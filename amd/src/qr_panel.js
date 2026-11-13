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
 * The rotating QR codes: keep them fresh, and open them in a named window.
 *
 * Two jobs that always appear together, which is why they are one module.
 *
 * Refresh: a code is valid for one rotation window, so the image is re-requested
 * a little faster than that. While the tab is hidden the interval stops - a code
 * on a projector stays up for a whole lesson and each tick is a full Moodle
 * bootstrap plus a freshly encoded PNG - and the first thing on the way back is
 * an immediate tick, because the token rotated while the frame was frozen.
 *
 * Open: a delegated click handler on `.multicheckin-qropen`. Delegated rather
 * than bound per button because the same markup is rendered on two pages, and
 * guarded so a second init does not install a second handler. The window is
 * named, so clicking twice reuses it instead of littering the desktop.
 *
 * @module     mod_multicheckin/qr_panel
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    /** @type {string} Images that carry a refresh interval. */
    var REFRESH_SELECTOR = '.multicheckin-qrimg';

    /** @type {string} Buttons that open a code in its own window. */
    var OPEN_SELECTOR = '.multicheckin-qropen';

    /** @type {boolean} Guard so a page that inits twice binds the click once. */
    var clickbound = false;

    /**
     * Re-request every code on its own interval.
     *
     * @return {void}
     */
    var startRefreshing = function() {
        Array.prototype.forEach.call(document.querySelectorAll(REFRESH_SELECTOR), function(img) {
            var ms = parseInt(img.getAttribute('data-refresh'), 10);
            // An image without the attribute is left alone. parseInt yields NaN
            // and setInterval treats that as zero, spinning as fast as the
            // browser allows.
            if (!ms || ms <= 0) {
                return;
            }

            var base = img.src.split('&_ts=')[0];
            var timer = null;

            var tick = function() {
                img.src = base + '&_ts=' + Date.now();
            };
            var start = function() {
                if (timer === null) {
                    timer = setInterval(tick, ms);
                }
            };
            var stop = function() {
                if (timer !== null) {
                    clearInterval(timer);
                    timer = null;
                }
            };

            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    stop();
                } else {
                    tick();
                    start();
                }
            });

            start();
        });
    };

    /**
     * Open a code in a named window instead of navigating away.
     *
     * @return {void}
     */
    var bindOpenButtons = function() {
        if (clickbound) {
            return;
        }
        clickbound = true;

        var open = function(url, name) {
            var win = window.open(url, name, 'width=760,height=900,resizable=yes,scrollbars=yes');
            if (win) {
                win.focus();
            }
        };

        document.addEventListener('click', function(e) {
            var btn = e.target.closest ? e.target.closest(OPEN_SELECTOR) : null;
            if (!btn) {
                return;
            }
            e.preventDefault();
            open(btn.getAttribute('data-qrurl'), btn.getAttribute('data-qrtarget'));
        });
    };

    return {
        /**
         * Wire up whatever the page rendered.
         *
         * @return {void}
         */
        init: function() {
            startRefreshing();
            bindOpenButtons();
        }
    };
});
