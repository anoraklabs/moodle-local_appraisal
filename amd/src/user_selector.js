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
 * Autocomplete data source for the Find a user search.
 *
 * @module     local_appraisal/user_selector
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

/**
 * Escape text for use as HTML.
 *
 * @param {string} text
 * @returns {string}
 */
const escape = (text) => {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
};

/**
 * Turn search results into autocomplete options.
 *
 * @param {string} selector The autocomplete element selector.
 * @param {Array} results From the web service.
 * @returns {Array} Options with value and label.
 */
export const processResults = (selector, results) => results.map((user) => ({
    value: user.id,
    label: user.extra ? `${escape(user.fullname)} <small class="text-muted">${escape(user.extra)}</small>` : escape(user.fullname),
}));

/**
 * Fetch users matching the query.
 *
 * @param {string} selector The autocomplete element selector.
 * @param {string} query
 * @param {Function} callback Receives the results.
 * @param {Function} failure Receives an error.
 */
export const transport = (selector, query, callback, failure) => {
    // The autocomplete transport API is callback-based, so the callback has to be called from the promise.
    /* eslint-disable promise/no-callback-in-promise */
    Ajax.call([{
        methodname: 'local_appraisal_search_users',
        args: {query},
    }])[0].then(callback).catch(failure);
    /* eslint-enable promise/no-callback-in-promise */
};
