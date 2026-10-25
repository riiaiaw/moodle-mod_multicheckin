// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Confirm-before-following for destructive links.
 *
 * Deleting every occurrence of a series discards collected attendance and there
 * is no undo, so the action must not be one stray click away. The message is
 * carried on the link itself rather than hard-coded here, which keeps this
 * module free of translation strings and lets each link phrase its own warning
 * ("delete all 24 occurrences" reads differently from "delete this one").
 *
 * @module     mod_multicheckin/confirm
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery'], function($) {
    return {
        /**
         * Intercept clicks on any element carrying a confirmation message.
         */
        init: function() {
            // Delegated from the document: the occurrence table is rebuilt on
            // every render, so binding to the elements themselves would have to
            // be redone each time.
            $(document).on('click', '[data-multicheckin-confirm]', function(e) {
                var message = $(this).attr('data-multicheckin-confirm');
                if (message && !window.confirm(message)) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    return false;
                }
                return true;
            });
        }
    };
});
