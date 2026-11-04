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
 * The Add link modal on the Appraiser cohorts page.
 *
 * @module     local_appraisal/cohort_links
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalForm from 'core_form/modalform';

const SELECTORS = {
    addButton: '[data-action="add-link"]',
};

/**
 * Open the modal form for a new cohort link and reload the page once it is saved.
 *
 * @param {HTMLElement} button The button that was clicked; carries data-title.
 */
const openForm = (button) => {
    const form = new ModalForm({
        formClass: 'local_appraisal\\form\\cohort_link_form',
        modalConfig: {title: button.dataset.title},
        returnFocus: button,
    });
    form.addEventListener(form.events.FORM_SUBMITTED, () => {
        window.location.reload();
    });
    form.show();
};

/**
 * Initialise the page.
 */
export const init = () => {
    document.addEventListener('click', (event) => {
        const button = event.target.closest(SELECTORS.addButton);
        if (button) {
            event.preventDefault();
            openForm(button);
        }
    });
};
