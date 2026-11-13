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
 * Ask the browser for a position, then continue the check-in with it.
 *
 * checkin.php needs coordinates to judge a GPS fence, and only the browser has
 * any. This module is that one step: read the URL to continue to, wait for a
 * fix, and replace the location with the same URL plus the coordinates.
 *
 * A fix that never arrives is not an error here. The page is reloaded without
 * coordinates and the server says what it thinks of that - which is the only
 * place that can decide, because only the server knows whether the activity
 * needs a position at all.
 *
 * @module     mod_multicheckin/geo_bridge
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    /** @type {string} Element carrying the URL to continue to. */
    var CONFIG_ID = 'multicheckin-geo-bridge-config';

    return {
        /**
         * Start the round trip.
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
                // Without a URL to continue to there is nothing to do; the
                // server will re-render the page on the next request anyway.
                return;
            }

            var finish = function(lat, lng, acc) {
                var url = config.base;
                if (lat !== null && lat !== undefined) {
                    url += '&geolat=' + encodeURIComponent(lat)
                        + '&geolng=' + encodeURIComponent(lng)
                        + (acc ? '&geoacc=' + encodeURIComponent(acc) : '');
                }
                window.location.replace(url);
            };

            if (!navigator.geolocation) {
                finish(null);
                return;
            }

            navigator.geolocation.getCurrentPosition(function(pos) {
                var c = pos.coords;
                finish(c.latitude, c.longitude, c.accuracy);
            }, function() {
                finish(null);
            }, {enableHighAccuracy: true, timeout: 15000, maximumAge: 0});
        }
    };
});
