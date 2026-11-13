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
 * Copy the check-in address to the clipboard, for the hand-off to a real browser.
 *
 * Kept apart from the capture code on purpose: this module reads the DOM and
 * writes one string, and cannot change what the camera does. It has three routes
 * out, in order of preference, because the page is often opened inside an
 * in-app browser where none of the obvious ones work:
 *
 *   1. the async clipboard API,
 *   2. document.execCommand('copy') on the selected field,
 *   3. nothing at all - but focusing the field selects the whole address, so a
 *      long press can still copy it.
 *
 * The last one is why the address is on screen in a field rather than baked into
 * the button's payload.
 *
 * @module     mod_multicheckin/photo_copy
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    return {
        /**
         * Wire up the copy button, if the page rendered one.
         *
         * @return {void}
         */
        init: function() {
            var button = document.getElementById('multicheckin-photo-copy');
            var source = document.getElementById('multicheckin-photo-copysource');
            var state = document.getElementById('multicheckin-photo-copystate');
            if (!button || !source) {
                return;
            }

            var say = function(text) {
                if (state) {
                    state.textContent = text;
                }
            };

            var done = function() {
                say(button.getAttribute('data-copied') || '');
                window.setTimeout(function() {
                    say('');
                }, 3000);
            };

            var selectIt = function() {
                try {
                    source.focus();
                    source.select();
                    return true;
                } catch (e) {
                    return false;
                }
            };

            var fallback = function() {
                if (selectIt()) {
                    try {
                        if (document.execCommand('copy')) {
                            done();
                            return;
                        }
                    } catch (e) {
                        // The selection is already on screen; a long press is
                        // the remaining route and needs no permission at all.
                    }
                }
                say(button.getAttribute('data-failed') || '');
            };

            button.addEventListener('click', function() {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(source.value).then(done, fallback);
                    return;
                }
                fallback();
            });

            // Focusing selects the whole address, so a long press copies it even
            // when neither clipboard route is available.
            source.addEventListener('focus', function() {
                source.select();
            });
        }
    };
});
