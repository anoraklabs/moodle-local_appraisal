// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Autosave for the appraisal page: every answer is saved as it is changed, with saving, saved and retry feedback,
 * and the progress lines are updated from the saved result without reloading.
 *
 * Saves for one field are sent one at a time, so two quick changes never overlap; a change made while a save is
 * running is sent as soon as it finishes. The strings are all fetched once when the page starts, so a lost
 * connection can still show an error and a Retry button.
 *
 * @module     local_appraisal/record_form
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {get_strings as getStrings} from 'core/str';

const SELECTORS = {
    root: '[data-region="record"]',
    field: '[data-region="field"]',
    input: '[data-region="input"]',
    control: '[data-region="control"]',
    status: '[data-region="save-status"]',
    saved: '[data-region="saved"]',
    section: '[data-region="section"]',
    sectionProgress: '[data-region="section-progress"]',
    recordProgress: '[data-region="record-progress"]',
    recordStatus: '[data-region="record-status"]',
    commentInput: '[data-region="comment-input"]',
    commentStatus: '[data-region="comment-status"]',
    clear: '[data-action="clear"]',
    footer: '[data-region="record-footer"]',
    footerAction: '[data-region="footer-action"]',
    outstanding: '[data-region="outstanding"]',
};

// Per field (and one for the comments): whether a save is running, and whether a newer change is waiting.
const queues = new Map();

// The number of the latest save sent. Saves for different fields can finish out of order, so only the latest one is
// allowed to rewrite the record status.
let latestSave = 0;

// The record status value (1 = Not started) from the latest save response; null until a save has finished.
let latestStatus = null;

// The status value of a record that has not been started, given to init() by the page.
let notStartedStatus = null;

// The page's strings, fetched once in init(). Until they arrive (or if they cannot be fetched) these English fallbacks
// are used, so a change made straight away, or while offline, still shows a status and Retry.
let strings = {
    saving: 'Saving…',
    saved: 'Saved',
    savefailed: 'Could not save.',
    retry: 'Retry',
    progress: '{complete} of {required}',
    sectionprogress: '{complete} of {required}',
    invalid: 'Invalid value.',
    outstandingfields: 'Required fields still to complete: {n}',
    outstandingcomment: 'Overall comments are required.',
    outstandingstart: 'Save at least one answer or comment first.',
};

// Resolves when the strings have been fetched (or the attempt has failed).
let stringsReady = Promise.resolve();

/**
 * Fetch the strings the script needs. The two progress strings keep their numbers as placeholders.
 *
 * @returns {Promise}
 */
const loadStrings = async() => {
    try {
        await fetchStrings();
    } catch (error) {
        // Keep the fallbacks.
    }
};

/**
 * Fetch the strings into the module's string table.
 *
 * @returns {Promise}
 */
const fetchStrings = async() => {
    const placeholders = {complete: '{complete}', required: '{required}'};
    const [
        saving, saved, savefailed, retry, progress, sectionprogress, invalid, outstandingfields, outstandingcomment,
        outstandingstart,
    ] = await getStrings([
        {key: 'saving', component: 'local_appraisal'},
        {key: 'saved', component: 'local_appraisal'},
        {key: 'savefailed', component: 'local_appraisal'},
        {key: 'retry', component: 'local_appraisal'},
        {key: 'progress', component: 'local_appraisal', param: placeholders},
        {key: 'sectionprogress', component: 'local_appraisal', param: placeholders},
        {key: 'error:invalidanswer', component: 'local_appraisal'},
        {key: 'outstandingfields', component: 'local_appraisal', param: '{n}'},
        {key: 'outstandingcomment', component: 'local_appraisal'},
        {key: 'outstandingstart', component: 'local_appraisal'},
    ]);
    strings = {
        saving, saved, savefailed, retry, progress, sectionprogress, invalid, outstandingfields, outstandingcomment,
        outstandingstart,
    };
};

/**
 * Put numbers into a progress string.
 *
 * @param {string} template A string with {complete} and {required} in it.
 * @param {number} complete
 * @param {number} required
 * @returns {string}
 */
const fillProgress = (template, complete, required) => template
    .replace('{complete}', complete).replace('{required}', required);

/**
 * The value to send for an input: a JSON list of keys for checkboxes, the chosen value for radios, or the control's text.
 *
 * @param {HTMLElement} input The [data-region="input"] element.
 * @returns {string|null} Null when the control holds something the browser could not turn into a value.
 */
const getValue = (input) => {
    const boxes = input.querySelectorAll('input[type="checkbox"]');
    if (boxes.length) {
        const keys = [...boxes].filter((box) => box.checked).map((box) => parseInt(box.value, 10));
        return JSON.stringify(keys.sort((a, b) => a - b));
    }
    const radios = input.querySelectorAll('input[type="radio"]');
    if (radios.length) {
        const chosen = [...radios].find((radio) => radio.checked);
        return chosen ? chosen.value : '';
    }
    const control = input.querySelector(SELECTORS.control);
    // A half-typed number or date reads as empty; saving that would delete the saved answer.
    if (control.validity && control.validity.badInput) {
        return null;
    }
    return control.value;
};

/**
 * The text for a failed save: the server's message for an exception it raised, otherwise a plain "Could not save"
 * rather than the browser's own wording for a lost connection.
 *
 * @param {Object} error
 * @returns {string}
 */
const errorText = (error) => error && error.errorcode ? error.message : strings.savefailed;

/**
 * Show a save state under an input.
 *
 * @param {HTMLElement} statusEl The element that holds the status text.
 * @param {string} state 'saving', 'saved', 'error' or '' to clear.
 * @param {string} text The text to show.
 * @param {Function|null} onRetry Called when Retry is pressed, for an error.
 */
const showStatus = (statusEl, state, text, onRetry = null) => {
    statusEl.textContent = '';
    statusEl.classList.remove('text-muted', 'text-success', 'text-danger');
    if (!state) {
        return;
    }
    const classes = {saving: 'text-muted', saved: 'text-success', error: 'text-danger'};
    statusEl.classList.add(classes[state]);
    statusEl.append(document.createTextNode(text));
    if (state === 'error' && onRetry) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-link btn-sm py-0';
        button.dataset.action = 'retry';
        button.textContent = strings.retry;
        button.addEventListener('click', onRetry);
        statusEl.append(' ', button);
    }
};

/**
 * Recount the required fields in each section and rewrite the section progress lines.
 *
 * @param {HTMLElement} root
 */
const refreshSections = (root) => {
    root.querySelectorAll(SELECTORS.section).forEach((section) => {
        const cards = [...section.querySelectorAll(SELECTORS.field)]
            .filter((card) => card.dataset.mine === '1' && card.dataset.required === '1');
        const target = section.querySelector(SELECTORS.sectionProgress);
        if (!target) {
            return;
        }
        const complete = cards.filter((card) => card.dataset.complete === '1').length;
        target.textContent = cards.length ? fillProgress(strings.sectionprogress, complete, cards.length) : '';
    });
};

/**
 * Rewrite the header progress from the fields on the page.
 *
 * It is counted from the fields rather than taken from the response, because saves for different fields can reach the
 * server in any order and a response may have been worked out before another save landed.
 *
 * @param {HTMLElement} root
 */
const refreshProgress = (root) => {
    const progress = root.querySelector(SELECTORS.recordProgress);
    if (!progress) {
        return;
    }
    const cards = [...root.querySelectorAll(SELECTORS.field)]
        .filter((card) => card.dataset.mine === '1' && card.dataset.required === '1');
    const complete = cards.filter((card) => card.dataset.complete === '1').length;
    progress.textContent = fillProgress(strings.progress, complete, cards.length);
};

/**
 * Enable or disable the Submit and Sign off buttons from the fields on the page, and say what is still outstanding.
 *
 * The page is rendered with the right state; this keeps it right as answers are saved. A button also carries a reason
 * the page cannot change (the record is not yet Submitted), which stays in the list.
 *
 * @param {HTMLElement} root
 */
const refreshFooter = (root) => {
    const actions = root.querySelectorAll(SELECTORS.footerAction);
    if (!actions.length) {
        return;
    }
    const cards = [...root.querySelectorAll(SELECTORS.field)]
        .filter((card) => card.dataset.mine === '1' && card.dataset.required === '1');
    const missing = cards.filter((card) => card.dataset.complete !== '1').length;
    const comment = root.querySelector(SELECTORS.commentInput);
    const outstanding = root.querySelector(SELECTORS.outstanding);
    actions.forEach((action) => {
        const reasons = [];
        if (action.dataset.blocked) {
            reasons.push(action.dataset.blocked);
        }
        // A version with no appraisee fields is signed off once anything has been saved.
        if (action.dataset.needsStart === '1' && (latestStatus === null || latestStatus === notStartedStatus)) {
            reasons.push(strings.outstandingstart);
        }
        if (missing) {
            reasons.push(strings.outstandingfields.replace('{n}', missing));
        }
        if (action.dataset.needsComment === '1' && comment && comment.value.trim() === '') {
            reasons.push(strings.outstandingcomment);
        }
        action.disabled = reasons.length > 0;
        if (outstanding) {
            outstanding.textContent = reasons.join(' ');
            outstanding.hidden = reasons.length === 0;
        }
    });
};

/**
 * Whether no save is running or waiting.
 *
 * @returns {boolean}
 */
const isIdle = () => [...queues.values()].every((queue) => !queue.saving && !queue.pending);

/**
 * Resolve once every save has finished, or at the deadline.
 *
 * @param {number} deadline A time in milliseconds, as Date.now() returns.
 * @returns {Promise}
 */
const waitUntilIdle = (deadline) => new Promise((resolve) => {
    const check = () => {
        if (isIdle() || Date.now() > deadline) {
            resolve();
            return;
        }
        setTimeout(check, 50);
    };
    check();
});

/**
 * Apply what a save changed on the page: the progress lines always, the record status only from the latest response.
 *
 * @param {HTMLElement} root
 * @param {Object} result From the save web service.
 * @param {boolean} latest Whether this was the latest save sent.
 */
const applyResult = (root, result, latest) => {
    if (latest) {
        latestStatus = result.status;
        const status = root.querySelector(SELECTORS.recordStatus);
        if (status) {
            status.textContent = result.statusname;
        }
    }
    refreshProgress(root);
    refreshSections(root);
    refreshFooter(root);
};

/**
 * Save one field's current value, now or when the save already running for it has finished.
 *
 * @param {HTMLElement} root
 * @param {number} recordid
 * @param {number} role
 * @param {HTMLElement} input The [data-region="input"] element.
 */
const saveField = async(root, recordid, role, input) => {
    const fieldid = parseInt(input.dataset.fieldid, 10);
    const key = `field-${fieldid}`;
    const queue = queues.get(key) || {saving: false, pending: false};
    queues.set(key, queue);
    if (queue.saving) {
        queue.pending = true;
        return;
    }

    const card = input.closest(SELECTORS.field);
    const statusEl = input.querySelector(SELECTORS.status);
    const value = getValue(input);
    if (value === null) {
        showStatus(statusEl, 'error', strings.invalid);
        return;
    }
    queue.saving = true;
    await stringsReady;
    showStatus(statusEl, 'saving', strings.saving);
    try {
        const sequence = ++latestSave;
        const result = await Ajax.call([{
            methodname: 'local_appraisal_save_answer',
            args: {recordid, fieldid, role, value},
        }])[0];
        card.dataset.complete = result.complete ? '1' : '0';
        const saved = input.closest('[data-region="answer"]')?.querySelector(SELECTORS.saved);
        if (saved) {
            saved.textContent = result.saved;
        }
        applyResult(root, result, sequence === latestSave);
        showStatus(statusEl, 'saved', strings.saved);
    } catch (error) {
        showStatus(statusEl, 'error', errorText(error), () => saveField(root, recordid, role, input));
    } finally {
        queue.saving = false;
        if (queue.pending) {
            queue.pending = false;
            saveField(root, recordid, role, input);
        }
    }
};

/**
 * Save the overall comments.
 *
 * @param {HTMLElement} root
 * @param {number} recordid
 * @param {HTMLTextAreaElement} textarea
 */
const saveComment = async(root, recordid, textarea) => {
    const queue = queues.get('comment') || {saving: false, pending: false};
    queues.set('comment', queue);
    if (queue.saving) {
        queue.pending = true;
        return;
    }

    const statusEl = root.querySelector(SELECTORS.commentStatus);
    queue.saving = true;
    await stringsReady;
    showStatus(statusEl, 'saving', strings.saving);
    try {
        const sequence = ++latestSave;
        const result = await Ajax.call([{
            methodname: 'local_appraisal_save_comment',
            args: {recordid, comment: textarea.value},
        }])[0];
        applyResult(root, result, sequence === latestSave);
        showStatus(statusEl, 'saved', strings.saved);
    } catch (error) {
        showStatus(statusEl, 'error', errorText(error), () => saveComment(root, recordid, textarea));
    } finally {
        queue.saving = false;
        if (queue.pending) {
            queue.pending = false;
            saveComment(root, recordid, textarea);
        }
    }
};

/**
 * Initialise autosave on the page.
 *
 * @param {number} recordid
 * @param {number} role The viewer's side: 1 = appraiser, 2 = appraisee.
 * @param {number} notStarted The status value of a record that has not been started.
 */
export const init = (recordid, role, notStarted) => {
    notStartedStatus = notStarted;
    const root = document.querySelector(SELECTORS.root);
    if (!root) {
        return;
    }
    stringsReady = loadStrings();

    root.addEventListener('change', (event) => {
        const comment = event.target.closest(SELECTORS.commentInput);
        if (comment) {
            saveComment(root, recordid, comment);
            return;
        }
        const input = event.target.closest(SELECTORS.input);
        if (input) {
            saveField(root, recordid, role, input);
        }
    });

    root.addEventListener('input', (event) => {
        if (event.target.closest(SELECTORS.commentInput)) {
            refreshFooter(root);
        }
    });

    // A footer button leaves the page, so let a save that its click has just caused (a field losing focus) finish first.
    root.addEventListener('submit', async(event) => {
        const form = event.target.closest(`${SELECTORS.footer} form`);
        if (!form || isIdle()) {
            return;
        }
        event.preventDefault();
        await waitUntilIdle(Date.now() + 10000);
        form.submit();
    });

    root.addEventListener('click', (event) => {
        const clear = event.target.closest(SELECTORS.clear);
        if (!clear) {
            return;
        }
        event.preventDefault();
        const input = clear.closest(SELECTORS.input);
        input.querySelectorAll('input[type="radio"]').forEach((radio) => {
            radio.checked = false;
        });
        saveField(root, recordid, role, input);
    });
};
