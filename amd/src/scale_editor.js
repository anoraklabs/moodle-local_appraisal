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
 * Grading scale editing: the add/edit level modal, drag-to-reorder and keyboard reordering of the levels.
 *
 * Reordering re-deals the scale's values in ascending order, so the page reloads after a save to show them.
 *
 * @module     local_appraisal/scale_editor
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import ModalForm from 'core_form/modalform';
import Notification from 'core/notification';

const SELECTORS = {
    root: '[data-region="scale-editor"]',
    list: '[data-region="level-list"]',
    item: '[data-region="level-item"]',
    handle: '[data-drag-type="move"]',
    levelForm: '[data-action="add-level"], [data-action="edit-level"]',
    moveButton: '[data-action="move-up"], [data-action="move-down"]',
};

const CLASSES = {
    dragging: 'local-appraisal-dragging',
};

// Where the control that was used is remembered across the reload that follows a save.
const FOCUS_KEY = 'local_appraisal/scale_editor/focus';

/**
 * Remember a selector for the control to focus once the saved page has reloaded.
 *
 * Browser storage can be unavailable (a private window, blocked site data); focus then just starts at the top.
 *
 * @param {string} selector
 */
const rememberFocus = (selector) => {
    try {
        window.sessionStorage.setItem(FOCUS_KEY, selector);
    } catch (error) {
        // Nothing to do: losing the focus position is the only effect.
    }
};

/**
 * Put focus back on the control remembered before the last reload, if it is still on the page.
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
 * The level rows in their current order.
 *
 * @param {HTMLElement} root
 * @returns {HTMLElement[]}
 */
const getItems = (root) => [...root.querySelectorAll(SELECTORS.item)];

/**
 * The current order of the level ids.
 *
 * @param {HTMLElement} root
 * @returns {number[]}
 */
const getOrder = (root) => getItems(root).map((item) => parseInt(item.dataset.id, 10));

/**
 * Open the add/edit level modal.
 *
 * @param {number} scaleid
 * @param {HTMLElement} button The button that was clicked; carries data-id and data-title.
 */
const openForm = (scaleid, button) => {
    const form = new ModalForm({
        formClass: 'local_appraisal\\form\\scale_level_form',
        args: {
            scaleid,
            id: parseInt(button.dataset.id, 10) || 0,
        },
        modalConfig: {title: button.dataset.title},
        returnFocus: button,
    });
    form.addEventListener(form.events.FORM_SUBMITTED, () => {
        rememberFocus(`[data-action="${button.dataset.action}"][data-id="${button.dataset.id}"]`);
        window.location.reload();
    });
    form.show();
};

/**
 * Put the rows back in a previous order after a failed save.
 *
 * @param {HTMLElement} root
 * @param {HTMLElement[]} items The rows in their earlier order.
 */
const restore = (root, items) => {
    const list = root.querySelector(SELECTORS.list);
    items.forEach((item) => list.append(item));
};

/**
 * Send the current order to the server. The values are re-dealt there, so reload to show them.
 *
 * @param {number} scaleid
 * @param {HTMLElement} root
 * @param {HTMLElement[]} previous The rows in their order before the change.
 * @param {string} focusSelector The control to focus after the reload.
 * @returns {Promise}
 */
const saveOrder = (scaleid, root, previous, focusSelector) => Ajax.call([{
    methodname: 'local_appraisal_reorder_levels',
    args: {scaleid, levelids: getOrder(root)},
}])[0]
    .then(() => {
        rememberFocus(focusSelector);
        window.location.reload();
        return null;
    })
    .catch((error) => {
        restore(root, previous);
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
 * Drag-to-reorder with the native Drag and Drop API. Only a handle starts a drag.
 *
 * @param {number} scaleid
 * @param {HTMLElement} root
 */
const initDragAndDrop = (scaleid, root) => {
    let dragged = null;
    let previous = [];
    let before = '';

    root.addEventListener('dragstart', (event) => {
        const handle = event.target.closest(SELECTORS.handle);
        if (!handle) {
            event.preventDefault();
            return;
        }
        dragged = handle.closest(SELECTORS.item);
        previous = getItems(root);
        before = JSON.stringify(getOrder(root));
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
        const over = event.target.closest(SELECTORS.item);
        if (over && over !== dragged) {
            placeByPointer(dragged, over, event.clientY);
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
        const item = dragged;
        dragged.classList.remove(CLASSES.dragging);
        dragged = null;
        if (JSON.stringify(getOrder(root)) !== before) {
            saveOrder(scaleid, root, previous, `${SELECTORS.item}[data-id="${item.dataset.id}"] ${SELECTORS.handle}`);
        }
    });
};

/**
 * Move a level one place up or down with the buttons.
 *
 * @param {number} scaleid
 * @param {HTMLElement} root
 * @param {HTMLElement} button The move button that was pressed.
 */
const moveByButton = (scaleid, root, button) => {
    const item = button.closest(SELECTORS.item);
    const up = button.dataset.action === 'move-up';
    const previous = getItems(root);
    if (up && item.previousElementSibling) {
        item.parentNode.insertBefore(item, item.previousElementSibling);
    } else if (!up && item.nextElementSibling) {
        item.parentNode.insertBefore(item.nextElementSibling, item);
    } else {
        return;
    }
    saveOrder(scaleid, root, previous, `${SELECTORS.item}[data-id="${item.dataset.id}"] [data-action="${button.dataset.action}"]`);
};

/**
 * Disable the move buttons that cannot move their row any further.
 *
 * @param {HTMLElement} root
 */
const refreshMoveButtons = (root) => {
    const items = getItems(root);
    items.forEach((item, index) => {
        const up = item.querySelector('[data-action="move-up"]');
        const down = item.querySelector('[data-action="move-down"]');
        if (up) {
            up.disabled = index === 0;
        }
        if (down) {
            down.disabled = index === items.length - 1;
        }
    });
};

/**
 * Initialise the editor.
 *
 * @param {number} scaleid
 */
export const init = (scaleid) => {
    const root = document.querySelector(SELECTORS.root);
    if (!root) {
        return;
    }

    root.addEventListener('click', (event) => {
        const formButton = event.target.closest(SELECTORS.levelForm);
        if (formButton) {
            event.preventDefault();
            openForm(scaleid, formButton);
            return;
        }
        const moveButton = event.target.closest(SELECTORS.moveButton);
        if (moveButton) {
            event.preventDefault();
            moveByButton(scaleid, root, moveButton);
        }
    });

    if (root.querySelector(SELECTORS.list) && root.dataset.locked !== '1') {
        refreshMoveButtons(root);
        initDragAndDrop(scaleid, root);
    }
    restoreFocus(root);
};
