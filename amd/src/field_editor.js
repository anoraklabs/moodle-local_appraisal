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
 * Field and section editing for a template version: the add/edit modals, drag-to-reorder and keyboard
 * reordering on drafts. Fields can move between sections; sections can be reordered as whole blocks.
 *
 * @module     local_appraisal/field_editor
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import ModalForm from 'core_form/modalform';
import Notification from 'core/notification';

const SELECTORS = {
    root: '[data-region="field-editor"]',
    sectionList: '[data-region="section-list"]',
    section: '[data-region="section-item"]',
    list: '[data-region="field-list"]',
    item: '[data-region="field-item"]',
    number: '[data-region="field-number"]',
    emptySection: '[data-region="empty-section"]',
    handle: '[data-drag-type="move"]',
    sectionHandle: '[data-drag-type="move-section"]',
    fieldForm: '[data-action="add-field"], [data-action="edit-field"]',
    sectionForm: '[data-action="add-section"], [data-action="edit-section"]',
    overviewForm: '[data-action="edit-overview"]',
    moveButton: '[data-action="move-up"], [data-action="move-down"]',
    sectionMoveButton: '[data-action="move-section-up"], [data-action="move-section-down"]',
};

const CLASSES = {
    dragging: 'local-appraisal-dragging',
};

// Where the button that opened a modal form is remembered across the reload that follows a save.
const FOCUS_KEY = 'local_appraisal/field_editor/focus';

const FORMS = {
    field: 'local_appraisal\\form\\field_form',
    section: 'local_appraisal\\form\\section_form',
    overview: 'local_appraisal\\form\\overview_form',
};

/**
 * A selector that finds the same button again after the page reloads.
 *
 * @param {HTMLElement} button
 * @returns {string}
 */
const buttonSelector = (button) => ['action', 'id', 'sectionid']
    .filter((key) => button.dataset[key] !== undefined)
    .map((key) => `[data-${key}="${button.dataset[key]}"]`)
    .join('');

/**
 * Remember the button that opened a form, so focus can return to it once the saved page has reloaded.
 *
 * Browser storage can be unavailable (a private window, blocked site data); focus then just starts at the top.
 *
 * @param {HTMLElement} button
 */
const rememberFocus = (button) => {
    try {
        window.sessionStorage.setItem(FOCUS_KEY, buttonSelector(button));
    } catch (error) {
        // Nothing to do: losing the focus position is the only effect.
    }
};

/**
 * Put focus back on the button remembered before the last reload, if it is still on the page.
 *
 * @param {HTMLElement} root
 */
const restoreFocus = (root) => {
    let selector = null;
    try {
        selector = window.sessionStorage.getItem(FOCUS_KEY);
        window.sessionStorage.removeItem(FOCUS_KEY);
    } catch (error) {
        return;
    }
    if (selector) {
        root.querySelector(selector)?.focus();
    }
};

/**
 * Open a modal form for a field or section.
 *
 * @param {string} formClass
 * @param {number} versionid
 * @param {HTMLElement} button The button that was clicked; carries data-id, data-title and optionally data-sectionid.
 */
const openForm = (formClass, versionid, button) => {
    const form = new ModalForm({
        formClass,
        args: {
            versionid,
            id: parseInt(button.dataset.id, 10) || 0,
            sectionid: parseInt(button.dataset.sectionid, 10) || 0,
        },
        modalConfig: {title: button.dataset.title},
        returnFocus: button,
    });
    form.addEventListener(form.events.FORM_SUBMITTED, () => {
        rememberFocus(button);
        window.location.reload();
    });
    form.show();
};

/**
 * The field lists in page order: one per section, or a single list when there are no sections.
 *
 * @param {HTMLElement} root
 * @returns {HTMLElement[]}
 */
const getLists = (root) => [...root.querySelectorAll(SELECTORS.list)];

/**
 * A list's field rows in their current order.
 *
 * @param {HTMLElement} list
 * @returns {HTMLElement[]}
 */
const getItems = (list) => [...list.querySelectorAll(SELECTORS.item)];

/**
 * The section blocks in their current order.
 *
 * @param {HTMLElement} root
 * @returns {HTMLElement[]}
 */
const getSections = (root) => [...root.querySelectorAll(SELECTORS.section)];

/**
 * The current layout, in the shape the reorder web service expects.
 *
 * @param {HTMLElement} root
 * @returns {Object[]} Each {id, fieldids}.
 */
const getLayout = (root) => getLists(root).map((list) => ({
    id: parseInt(list.dataset.sectionid, 10) || 0,
    fieldids: getItems(list).map((item) => parseInt(item.dataset.id, 10)),
}));

/**
 * Remember where every section and field is, so a failed save can put them back.
 *
 * @param {HTMLElement} root
 * @returns {Object}
 */
const snapshot = (root) => ({
    sections: getSections(root),
    lists: getLists(root).map((list) => ({list, items: getItems(list)})),
});

/**
 * Put every section and field back where a snapshot found them.
 *
 * @param {HTMLElement} root
 * @param {Object} previous A snapshot().
 */
const restore = (root, previous) => {
    const container = root.querySelector(SELECTORS.sectionList);
    previous.sections.forEach((section) => container.append(section));
    previous.lists.forEach(({list, items}) => items.forEach((item) => list.append(item)));
};

/**
 * Enable or disable Publish to match the layout, as the server does when the page loads.
 *
 * Does nothing on a page without the draft's publish controls.
 *
 * @param {HTMLElement} root
 * @param {number} fieldcount Fields in the whole version.
 * @param {boolean} hasemptysection Whether any section has no fields.
 */
const refreshPublish = (root, fieldcount, hasemptysection) => {
    const container = root.querySelector('[data-region="publish-blocked"]');
    if (!container) {
        return;
    }
    let message = '';
    if (fieldcount === 0) {
        message = container.dataset.nofields;
    } else if (hasemptysection) {
        message = container.dataset.emptysection;
    }
    container.textContent = message;
    container.classList.toggle('d-none', message === '');
    root.querySelector('[data-region="publish-disabled"]').classList.toggle('d-none', message === '');
    root.querySelector('[data-region="publish-link"]').classList.toggle('d-none', message !== '');
};

/**
 * Rewrite the displayed numbers, the empty-section hints and the enabled state of the move buttons.
 *
 * @param {HTMLElement} root
 */
const refresh = (root) => {
    const items = getLists(root).flatMap(getItems);
    items.forEach((item, index) => {
        item.querySelector(SELECTORS.number).textContent = `${index + 1}`;
        item.querySelector('[data-action="move-up"]').disabled = index === 0;
        item.querySelector('[data-action="move-down"]').disabled = index === items.length - 1;
    });

    const sections = getSections(root);
    let hasEmptySection = false;
    sections.forEach((section, index) => {
        section.querySelector('[data-action="move-section-up"]').disabled = index === 0;
        section.querySelector('[data-action="move-section-down"]').disabled = index === sections.length - 1;
        const empty = getItems(section.querySelector(SELECTORS.list)).length === 0;
        section.querySelector(SELECTORS.emptySection).classList.toggle('d-none', !empty);
        hasEmptySection = hasEmptySection || empty;
    });

    refreshPublish(root, items.length, hasEmptySection);
};

/**
 * Send the current layout to the server, restoring the previous one if that fails.
 *
 * @param {number} versionid
 * @param {HTMLElement} root
 * @param {Object} previous A snapshot() from before the change.
 * @returns {Promise}
 */
const saveLayout = (versionid, root, previous) => Ajax.call([{
    methodname: 'local_appraisal_reorder_fields',
    args: {versionid, sections: getLayout(root)},
}])[0]
    .then(() => refresh(root))
    .catch((error) => {
        restore(root, previous);
        refresh(root);
        return Notification.exception(error);
    });

/**
 * Insert an element before or after another, depending on which half of it the pointer is over.
 *
 * @param {HTMLElement} moving
 * @param {HTMLElement} over
 * @param {number} clientY
 */
const placeByPointer = (moving, over, clientY) => {
    const rect = over.getBoundingClientRect();
    const before = clientY < rect.top + rect.height / 2;
    over.parentNode.insertBefore(moving, before ? over : over.nextElementSibling);
};

/**
 * Drag-to-reorder with the native Drag and Drop API. Only a handle starts a drag: a field's handle moves the
 * field (within or between sections), a section's handle moves the whole section.
 *
 * @param {number} versionid
 * @param {HTMLElement} root
 */
const initDragAndDrop = (versionid, root) => {
    let dragged = null;
    let isSection = false;
    let previous = null;
    let before = '';

    root.addEventListener('dragstart', (event) => {
        const handle = event.target.closest(`${SELECTORS.handle}, ${SELECTORS.sectionHandle}`);
        if (!handle) {
            event.preventDefault();
            return;
        }
        isSection = handle.matches(SELECTORS.sectionHandle);
        dragged = handle.closest(isSection ? SELECTORS.section : SELECTORS.item);
        previous = snapshot(root);
        before = JSON.stringify(getLayout(root));
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', dragged.dataset.id);
        event.dataTransfer.setDragImage(dragged, 0, 0);
        dragged.classList.add(CLASSES.dragging);
    });

    root.addEventListener('dragover', (event) => {
        if (!dragged) {
            return;
        }
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        if (isSection) {
            const over = event.target.closest(SELECTORS.section);
            if (over && over !== dragged) {
                placeByPointer(dragged, over, event.clientY);
            }
            return;
        }

        const over = event.target.closest(SELECTORS.item);
        if (over && over !== dragged) {
            placeByPointer(dragged, over, event.clientY);
            return;
        }
        // Over an empty section: drop the field into its list.
        const section = event.target.closest(SELECTORS.section);
        const list = section ? section.querySelector(SELECTORS.list) : null;
        if (list && getItems(list).length === 0) {
            list.append(dragged);
            refresh(root);
        }
    });

    root.addEventListener('drop', (event) => {
        if (dragged) {
            event.preventDefault();
        }
    });

    root.addEventListener('dragend', () => {
        if (!dragged) {
            return;
        }
        dragged.classList.remove(CLASSES.dragging);
        dragged = null;
        if (JSON.stringify(getLayout(root)) !== before) {
            saveLayout(versionid, root, previous);
        } else {
            refresh(root);
        }
    });
};

/**
 * Move a field one place up or down. At the edge of a section it moves into the neighbouring section.
 *
 * @param {HTMLElement} root
 * @param {HTMLElement} item
 * @param {boolean} up
 * @returns {boolean} Whether anything moved.
 */
const moveField = (root, item, up) => {
    const list = item.parentNode;
    const lists = getLists(root);
    const index = lists.indexOf(list);
    if (up && item.previousElementSibling) {
        list.insertBefore(item, item.previousElementSibling);
    } else if (!up && item.nextElementSibling) {
        list.insertBefore(item.nextElementSibling, item);
    } else if (up && index > 0) {
        lists[index - 1].append(item);
    } else if (!up && index < lists.length - 1) {
        lists[index + 1].prepend(item);
    } else {
        return false;
    }
    return true;
};

/**
 * Move a section one place up or down.
 *
 * @param {HTMLElement} section
 * @param {boolean} up
 * @returns {boolean} Whether anything moved.
 */
const moveSection = (section, up) => {
    const container = section.parentNode;
    if (up && section.previousElementSibling) {
        container.insertBefore(section, section.previousElementSibling);
    } else if (!up && section.nextElementSibling) {
        container.insertBefore(section.nextElementSibling, section);
    } else {
        return false;
    }
    return true;
};

/**
 * Handle a move up/down button for a field or a section, then save and keep focus sensible.
 *
 * @param {number} versionid
 * @param {HTMLElement} root
 * @param {HTMLElement} button The move button that was pressed.
 */
const moveByButton = (versionid, root, button) => {
    const action = button.dataset.action;
    const isSection = action.startsWith('move-section');
    const up = action.endsWith('-up');
    const target = button.closest(isSection ? SELECTORS.section : SELECTORS.item);
    const previous = snapshot(root);

    const moved = isSection ? moveSection(target, up) : moveField(root, target, up);
    if (!moved) {
        return;
    }
    saveLayout(versionid, root, previous).then(() => {
        // Keep focus in the row: on the same button, or its partner if this one just became disabled.
        const selector = isSection ? SELECTORS.sectionMoveButton : SELECTORS.moveButton;
        const focus = button.disabled ? target.querySelector(`:is(${selector}):not([disabled])`) : button;
        focus?.focus();
        return null;
    }).catch(Notification.exception);
};

/**
 * Initialise the editor.
 *
 * @param {number} versionid
 */
export const init = (versionid) => {
    const root = document.querySelector(SELECTORS.root);
    if (!root) {
        return;
    }

    root.addEventListener('click', (event) => {
        const fieldButton = event.target.closest(SELECTORS.fieldForm);
        if (fieldButton) {
            event.preventDefault();
            openForm(FORMS.field, versionid, fieldButton);
            return;
        }
        const sectionButton = event.target.closest(SELECTORS.sectionForm);
        if (sectionButton) {
            event.preventDefault();
            openForm(FORMS.section, versionid, sectionButton);
            return;
        }
        const overviewButton = event.target.closest(SELECTORS.overviewForm);
        if (overviewButton) {
            event.preventDefault();
            openForm(FORMS.overview, versionid, overviewButton);
            return;
        }
        const moveButton = event.target.closest(`${SELECTORS.moveButton}, ${SELECTORS.sectionMoveButton}`);
        if (moveButton) {
            event.preventDefault();
            moveByButton(versionid, root, moveButton);
        }
    });

    if (getLists(root).length) {
        refresh(root);
        initDragAndDrop(versionid, root);
    }
    restoreFocus(root);
};
