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
 * Self check-in behind a GPS fence.
 *
 * The server needs a position before it can judge the fence, and only the
 * browser has one. This module turns a plain link into that round trip: it asks
 * for a fix, then reloads the same link with the coordinates attached.
 *
 * Three decisions worth keeping:
 *
 *   1. The link stays a link. It has a real href, so with scripting off - or if
 *      this module never loads - the student still reaches checkin.php, which
 *      answers honestly that it has no position. A <button> with only a data
 *      attribute did nothing at all and read as broken.
 *   2. Failure is written into a live region, not thrown into an alert(). An
 *      alert blocks the page, cannot be styled or translated by the theme, and
 *      is announced once and then gone.
 *   3. The coordinates are the browser's own. Nothing here makes them true -
 *      see the note on geolocation in the plugin documentation. This module
 *      exists so an honest student does not have to type numbers.
 *
 * @module     mod_multicheckin/geo_checkin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    /** @type {string} Where the server parks the per-point URLs and strings. */
    var CONFIG_ID = 'multicheckin-geo-config';

    /** @type {string} The region that announces what is happening. */
    var STATUS_ID = 'multicheckin-geo-status';

    return {
        /**
         * Wire up every fenced check-in link on the page.
         *
         * @return {void}
         */
        init: function() {
            var holder = document.getElementById(CONFIG_ID);
            if (!holder) {
                return;
            }

            var config;
            try {
                config = JSON.parse(holder.getAttribute('data-config'));
            } catch (e) {
                // A page rendered by an older build, or a broken attribute: the
                // links still work, they just do not carry a position.
                return;
            }

            var urls = config.urls || {};
            var strings = config.strings || {};
            var status = document.getElementById(STATUS_ID);

            var say = function(text) {
                if (!status) {
                    return;
                }
                status.textContent = text;
                status.classList.remove('d-none');
            };

            var links = document.querySelectorAll('.multicheckin-geocheckin');
            Array.prototype.forEach.call(links, function(link) {
                link.addEventListener('click', function(e) {
                    var point = link.getAttribute('data-target');
                    var base = urls[point];
                    if (!base) {
                        // No URL for this point: let the link do what it says.
                        return;
                    }

                    e.preventDefault();

                    if (!navigator.geolocation) {
                        say(strings.unsupported || '');
                        window.location.href = base;
                        return;
                    }

                    var original = link.textContent;
                    link.textContent = strings.locating || original;
                    link.setAttribute('aria-disabled', 'true');

                    navigator.geolocation.getCurrentPosition(function(pos) {
                        var c = pos.coords;
                        var sep = base.indexOf('?') === -1 ? '?' : '&';
                        window.location.href = base + sep
                            + 'geolat=' + encodeURIComponent(c.latitude)
                            + '&geolng=' + encodeURIComponent(c.longitude)
                            + '&geoacc=' + encodeURIComponent(c.accuracy || '');
                    }, function() {
                        say(strings.denied || '');
                        link.textContent = original;
                        link.removeAttribute('aria-disabled');
                    }, {enableHighAccuracy: true, timeout: 15000, maximumAge: 0});
                });
            });
        }
    };
});
