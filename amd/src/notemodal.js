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
 * The occurrence note, edited in a dialog on the page the teacher is on.
 *
 * A note is the one field of an occurrence that wants a paragraph and pictures,
 * and an editor is the most expensive thing a page can carry: thirty of them
 * turn a term's overview into a page that takes seconds to become usable. So
 * the pages that own an occurrence show a one-line preview and a button, and
 * the editor lives on a page of its own - which is what this module opens.
 *
 * It opens that page in a frame inside a dialog rather than moving the form's
 * markup into the dialog. Moodle's editor element is initialised by the page
 * that renders it, and an element moved into another container afterwards is a
 * dead textarea: the frame keeps the form on a page of its own, which is the
 * only arrangement in which the editor is built the way every other editor on
 * the site is.
 *
 * The buttons keep their href and target throughout, so a click that this
 * module does not intercept - because it never loaded, or because the teacher
 * held a modifier key to ask for a window - still opens the form.
 *
 * @module     mod_multicheckin/notemodal
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/modal', 'core/notification'], function($, Modal, Notification) {

    /** @type {Object} Language strings, supplied by the server. */
    var L = {};

    /** @type {Object|null} The dialog currently open, if any. */
    var dialog = null;

    /** @type {jQuery|null} The cell whose button opened it. */
    var cell = null;

    /**
     * Whether a message came from this site.
     *
     * Only messages from this origin are acted on. The form is same-origin by
     * construction, so anything else is a page that embedded this one and is
     * not something the dialog should be driven by.
     *
     * @param {MessageEvent} event Message event.
     * @return {Boolean}
     */
    function ours(event) {
        return event.origin === window.location.origin;
    }

    /**
     * Redraw one cell from what the form reported.
     *
     * The preview and the button's label are the only things a saved note
     * changes on this page, and both are redrawn rather than reloaded: a reload
     * would discard whatever the teacher had already typed into the form beside
     * them, which is exactly the field they were not editing.
     *
     * @param {Object} data Payload posted by the form.
     */
    function applySaved(data) {
        if (!cell || !cell.length) {
            return;
        }

        var $summary = cell.find('.multicheckin-note-summary');
        $summary.empty();
        if (data.summary) {
            $summary.text(data.summary);
        } else {
            $summary.append($('<span>').addClass('text-muted').text(L.none));
        }

        // The label says what the button will do next, so it follows the note
        // rather than the moment the page was drawn.
        cell.find('[data-multicheckin-note]').text(data.hasnote ? L.edit : L.add);
    }

    /**
     * Open the note form in a dialog.
     *
     * @param {String} url Address of the note form, already carrying the embed flag.
     * @param {jQuery} $cell Cell the button belongs to, so the answer can be applied to it.
     */
    function show(url, $cell) {
        cell = $cell;

        var $frame = $('<iframe>')
            .addClass('multicheckin-noteframe')
            .attr('src', url)
            .attr('title', L.title);

        Modal.create({
            title: L.title,
            large: true,
            removeOnClose: true
        }).then(function(modal) {
            dialog = modal;
            // Appended rather than passed as the body option: the body is set as
            // markup, and a frame built as one would have to have its address
            // escaped by hand to survive the trip through the HTML parser.
            modal.getBody().empty().append($frame);
            modal.show();

            return modal;
        }).catch(Notification.exception);
    }

    return {
        /**
         * Wire up every note button on the page.
         *
         * Delegated from the document because the occurrence table is rebuilt
         * on every render, so binding to the buttons themselves would have to
         * be redone each time - and a page may hold thirty of them.
         *
         * @param {Object} config Server-provided strings.
         */
        init: function(config) {
            L = config || {};

            $(document).on('click', '[data-multicheckin-note]', function(e) {
                // A modified click is a request for the old behaviour - a window
                // or a tab of the teacher's own - so it is left alone.
                if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
                    return true;
                }

                e.preventDefault();

                // The DOM property, not the attribute: it is already absolute
                // and its ampersands are already decoded.
                var url = this.href;
                url += (url.indexOf('?') === -1 ? '?' : '&') + 'embed=1';
                show(url, $(this).closest('.multicheckin-note-cell'));

                return false;
            });

            // The form reports back rather than reloading this page, because
            // this page may be holding edits of its own that a reload would
            // throw away.
            window.addEventListener('message', function(event) {
                if (!ours(event)) {
                    return;
                }

                var data = event.data || {};
                if (data.type === 'multicheckin-note-saved') {
                    applySaved(data);
                    return;
                }

                if (data.type === 'multicheckin-note-close' && dialog) {
                    dialog.destroy();
                    dialog = null;
                    cell = null;
                }
            });
        }
    };
});
