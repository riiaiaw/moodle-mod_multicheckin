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
 * What the note form does around a save, and what its controls do.
 *
 * This page is opened one of two ways: inside the dialog on the series page, or
 * in a window of its own when the dialog could not be used. The page itself is
 * the same; what differs is who has to be told and who has to be closed, and
 * only the server knows which of the two it is - so it says so in the data the
 * page renders, and this module reads it.
 *
 * The save report goes to the page behind the dialog rather than a reload: a
 * reload there would discard whatever the teacher had already typed into the
 * form beside the note, which is the one field they were not editing.
 *
 * The data lives in an element's attribute rather than in a script element.
 * That is issue #10: this used to be two `js_init_code()` calls with the
 * payload pasted into the script, and a note carrying a closing tag had to be
 * defused with hex flags on every field. An attribute is escaped by the HTML
 * writer instead, which is one place rather than one place per field, and this
 * file no longer contains any server data at all.
 *
 * With scripting off neither branch runs: both controls keep their href and
 * still go where they say they go.
 *
 * @module     mod_multicheckin/notepage
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    /**
     * The settings the page rendered for us, or null when there are none.
     *
     * @return {Object|null}
     */
    function settings() {
        var holder = document.getElementById('multicheckin-notepage-data');
        if (!holder || !holder.dataset || !holder.dataset.config) {
            return null;
        }
        try {
            return JSON.parse(holder.dataset.config);
        } catch (e) {
            // A page whose data cannot be read is a page that behaves as it did
            // before this module existed: the controls navigate. Nothing here is
            // worth breaking the rest of the page over.
            return null;
        }
    }

    /**
     * Whether this page is showing inside the dialog rather than on its own.
     *
     * A page is its own parent when it is not embedded, so messaging the parent
     * would be a no-op that looks like a bug. Both halves are required.
     *
     * @param {Object|null} config Page settings.
     * @return {Boolean}
     */
    function embedded(config) {
        return !!(config && config.embedded && window.parent && window.parent !== window);
    }

    /**
     * Turn a control into one that closes the thing the page is shown in.
     *
     * Inside the dialog only the owner can close it, so the click becomes a
     * message and the navigation is cancelled first. In a window of its own
     * there is nothing to message: closing it is enough, and the opener is
     * reloaded only when the page's content changed - a close after a save has
     * to show the new note, a plain back does not.
     *
     * @param {String} id Element id.
     * @param {Boolean} inFrame Whether this page is inside the dialog.
     * @param {Boolean} reloadOpener Whether the page behind should re-read itself.
     */
    function closeOnClick(id, inFrame, reloadOpener) {
        var control = document.getElementById(id);
        if (!control) {
            return;
        }

        control.addEventListener('click', function(e) {
            if (inFrame) {
                e.preventDefault();
                window.parent.postMessage({type: 'multicheckin-note-close'}, window.location.origin);
                return;
            }
            if (window.opener && !window.opener.closed) {
                e.preventDefault();
                if (reloadOpener) {
                    window.opener.location.reload();
                }
                window.close();
            }
        });
    }

    return {
        /**
         * Report the save, and make both controls close rather than navigate.
         */
        init: function() {
            var config = settings();
            var inFrame = embedded(config);

            if (inFrame && config.payload) {
                window.parent.postMessage(config.payload, window.location.origin);
            }

            // The close button only exists on the page a save lands on; the back
            // control only on the page a save has not happened yet. Binding both
            // unconditionally is cheaper than trusting that, and the ids are what
            // decide - a control that is not there is not bound.
            if (config && config.saved) {
                closeOnClick('multicheckin-note-close', inFrame, true);
            }
            closeOnClick('multicheckin-note-back', inFrame, false);
        }
    };
});
