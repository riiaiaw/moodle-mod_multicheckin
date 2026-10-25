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
 * Live preview of the attendance occurrence series on the activity form.
 *
 * Mirrors mod_multicheckin\schedule_planner. The rule is duplicated here in
 * JavaScript only so the teacher can see the dates without a round trip; the
 * authoritative dates are always the ones the server computes from the same
 * fields on save. Whenever the two could disagree the server wins, and the
 * preview says as much on screen.
 *
 * @module     mod_multicheckin/series
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery'], function($) {

    // Everything here works in whole seconds, matching the timestamps the form
    // submits and the PHP planner's own units. Stepping by DAY (milliseconds)
    // against a seconds-based cursor walked a thousand days per iteration and
    // produced a preview with one occurrence.
    var DAY = 86400;
    var REPEAT_NONE = 'none';
    var REPEAT_WEEKLY = 'weekly';
    var REPEAT_DAILY = 'daily';
    var REPEAT_CUSTOM = 'custom';

    var strings = {};

    /**
     * Read the date out of a Moodle date_selector.
     *
     * The selector renders its own hidden timestamp field, which is the value
     * the form actually submits, so reading it keeps the preview and the server
     * on the same footing - including the site's timezone setting.
     *
     * @param {Object} form The form element.
     * @param {String} name Field name.
     * @return {Number} Timestamp, or 0 when unset.
     */
    function dateValue(form, name) {
        var $field = $(form).find('[name="' + name + '"]');
        if (!$field.length) {
            return 0;
        }
        // date_selector submits a single hidden field carrying the timestamp.
        if ($field.length === 1 && $field.attr('type') === 'hidden') {
            return parseInt($field.val(), 10) || 0;
        }
        // Fallback: an assembled selector, read the visible parts.
        var y = parseInt($(form).find('[name="' + name + '[year]"]').val(), 10);
        var m = parseInt($(form).find('[name="' + name + '[month]"]').val(), 10);
        var d = parseInt($(form).find('[name="' + name + '[day]"]').val(), 10);
        if (!y || !m || !d) {
            return 0;
        }
        return Math.floor(new Date(y, m - 1, d, 0, 0, 0, 0).getTime() / 1000);
    }

    /**
     * Midnight local time of a timestamp.
     *
     * @param {Number} ts Timestamp.
     * @return {Number} Midnight timestamp.
     */
    function dayStart(ts) {
        var d = new Date(ts * 1000);
        d.setHours(0, 0, 0, 0);
        return Math.floor(d.getTime() / 1000);
    }

    /**
     * ISO-8601 weekday number, 1 = Monday.
     *
     * @param {Number} ts Midnight timestamp.
     * @return {Number} 1 to 7.
     */
    function isoWeekday(ts) {
        var day = new Date(ts * 1000).getDay();
        return day === 0 ? 7 : day;
    }

    /**
     * The selected weekdays.
     *
     * @param {Object} form The form element.
     * @return {Object} Set of day numbers.
     */
    function weekdays(form) {
        var picked = {};
        for (var day = 1; day <= 7; day++) {
            var $cb = $(form).find('[name="serieswd' + day + '"]');
            if ($cb.length && $cb.is(':checked')) {
                picked[day] = true;
            }
        }
        return picked;
    }

    /**
     * Parse the HH:MM start time, falling back to the session start field.
     *
     * @param {Object} form The form element.
     * @return {Object} {hour, minute}
     */
    function clock(form) {
        var raw = ($(form).find('[name="seriestimefrom"]').val() || '').trim();
        var m = raw.match(/^(\d{1,2})[:.](\d{2})$/);
        if (m) {
            return {hour: Math.min(23, parseInt(m[1], 10)), minute: Math.min(59, parseInt(m[2], 10))};
        }
        var start = dateValue(form, 'timestart');
        if (start > 0) {
            var d = new Date(start * 1000);
            return {hour: d.getHours(), minute: d.getMinutes()};
        }
        return {hour: 9, minute: 0};
    }

    /**
     * Sort and de-duplicate a list of timestamps.
     *
     * The server does exactly this before it truncates, so doing it here too
     * means the preview and the save agree on the count - and a weekday that is
     * also the start date does not appear twice on screen.
     *
     * @param {Number[]} stamps Timestamps.
     * @return {Number[]} Ascending, unique.
     */
    function dedupe(stamps) {
        stamps.sort(function(a, b) {
            return a - b;
        });
        var seen = {};
        var unique = [];
        stamps.forEach(function(ts) {
            if (!seen[ts]) {
                seen[ts] = true;
                unique.push(ts);
            }
        });
        return unique;
    }

    /**
     * Turn one hand-entered date and time into a timestamp.
     *
     * Mirrors schedule_planner::parse_custom_row(). The month/day are read back
     * off the constructed Date because JavaScript rolls an impossible date
     * forward (31 February becomes 3 March); comparing the parts is what makes
     * that a rejection rather than a silent move. Anything that does not parse
     * yields 0 and is dropped: the server validates and is the authority, and
     * the preview should not promise a date the save will refuse.
     *
     * @param {String} date YYYY-MM-DD.
     * @param {String} time HH:MM, blank means midnight.
     * @return {Number} Timestamp, or 0 when the row is not usable.
     */
    function customRow(date, time) {
        var dm = (date || '').trim().match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
        if (!dm) {
            return 0;
        }
        var y = parseInt(dm[1], 10);
        var mo = parseInt(dm[2], 10);
        var d = parseInt(dm[3], 10);
        var probe = new Date(y, mo - 1, d);
        if (probe.getFullYear() !== y || probe.getMonth() !== mo - 1 || probe.getDate() !== d) {
            return 0;
        }

        var tm = (((time || '').trim()) || '00:00').match(/^(\d{1,2})[:.](\d{2})$/);
        if (!tm) {
            return 0;
        }
        var hh = parseInt(tm[1], 10);
        var mm = parseInt(tm[2], 10);
        if (hh > 23 || mm > 59) {
            return 0;
        }

        return Math.floor(new Date(y, mo - 1, d, hh, mm, 0, 0).getTime() / 1000);
    }

    /**
     * The timestamps of an irregular series.
     *
     * The count field decides how many rows are read, exactly as the server
     * reads it - never how many row inputs the page happens to contain, because
     * the form renders the full block and leaves the unused rows on the page.
     *
     * @param {Object} form The form element.
     * @return {Number[]} Ascending timestamps, within the site's cap.
     */
    function planCustom(form) {
        var count = parseInt($(form).find('[name="seriescustomcount"]').val(), 10) || 0;
        if (count < 1) {
            return [];
        }
        if (strings.max && count > strings.max) {
            count = strings.max;
        }

        var starts = [];
        for (var k = 1; k <= count; k++) {
            var date = $(form).find('[name="seriescd' + k + '"]').val() || '';
            var time = $(form).find('[name="seriesct' + k + '"]').val() || '';
            var ts = customRow(date, time);
            if (ts) {
                starts.push(ts);
            }
        }

        var unique = dedupe(starts);
        return strings.max ? unique.slice(0, strings.max) : unique;
    }

    /**
     * Compute the occurrence start times, mirroring the PHP planner.
     *
     * @param {Object} form The form element.
     * @return {Number[]} Ascending timestamps.
     */
    function plan(form) {
        var repeat = $(form).find('[name="seriesrepeat"]').val() || REPEAT_NONE;

        // A single-session activity has exactly one start: the one already on
        // the timing fields. Returning it keeps the preview honest ("1
        // occurrence, on this date") instead of blank, and matches the PHP
        // planner. An unrecognised mode returns nothing rather than silently
        // behaving like one of the real modes.
        if (repeat === REPEAT_NONE) {
            var only = dateValue(form, 'timestart');
            return only > 0 ? [only] : [];
        }
        if (repeat === REPEAT_CUSTOM) {
            return planCustom(form);
        }
        if (repeat !== REPEAT_WEEKLY && repeat !== REPEAT_DAILY) {
            return [];
        }

        var from = dayStart(dateValue(form, 'seriesdatefrom'));
        var to = dayStart(dateValue(form, 'seriesdateto'));
        if (!from || !to || to < from) {
            return [];
        }

        var days = [];
        var cursor;

        if (repeat === REPEAT_WEEKLY) {
            var picked = weekdays(form);
            if (!Object.keys(picked).length) {
                return [];
            }
            for (cursor = from; cursor <= to; cursor += DAY) {
                if (picked[isoWeekday(cursor)]) {
                    days.push(cursor);
                }
            }
        } else {
            var interval = parseInt($(form).find('[name="seriesinterval"]').val(), 10) || 1;
            if (interval < 1) {
                interval = 1;
            }
            for (cursor = from; cursor <= to; cursor += interval * DAY) {
                days.push(cursor);
            }
        }

        // Sort and de-duplicate, exactly as the server does, so a weekday that
        // is also the start date does not appear twice in the preview.
        var unique = dedupe(days);

        var c = clock(form);
        var starts = [];
        for (var i = 0; i < unique.length; i++) {
            starts.push(unique[i] + (c.hour * 3600) + (c.minute * 60));
            if (strings.max && starts.length >= strings.max) {
                break;
            }
        }
        return starts;
    }

    /**
     * Format a timestamp as YYYY-MM-DD HH:MM without any library.
     *
     * @param {Number} ts Timestamp.
     * @return {String}
     */
    function stamp(ts) {
        function pad(n) {
            return (n < 10 ? '0' : '') + n;
        }
        var d = new Date(ts * 1000);
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
            + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    /**
     * Render the preview into its slot.
     *
     * @param {Object} form The form element.
     */
    function render(form) {
        var $slot = $(form).find('[data-multicheckin-series="preview"]');
        if (!$slot.length) {
            return;
        }

        var starts = plan(form);
        if (!starts.length) {
            $slot.html($('<span>').addClass('text-muted').text(strings.none));
            return;
        }

        var $list = $('<ul>').addClass('mb-1 ps-3');
        starts.slice(0, 8).forEach(function(ts) {
            $list.append($('<li>').text(stamp(ts)));
        });
        if (starts.length > 8) {
            $list.append($('<li>').addClass('text-muted')
                .text(strings.more.replace('{$a}', starts.length)));
        }

        var $summary = $('<div>').addClass('fw-bold')
            .text(strings.count.replace('{$a}', starts.length));

        $slot.empty().append($summary).append($list);

        if (strings.max && starts.length >= strings.max) {
            $slot.append($('<div>').addClass('text-warning')
                .text(strings.capped.replace('{$a}', strings.max)));
        }

        $slot.append($('<div>').addClass('text-muted small').text(strings.hint));
    }

    return {
        /**
         * Wire the preview to every field that feeds it.
         *
         * @param {Object} config Localised strings from the server.
         */
        init: function(config) {
            strings = config || {};

            var $form = $('form.mform').first();
            if (!$form.length || !$form.find('[name="seriesrepeat"]').length) {
                return;
            }

            // The form is replaced wholesale whenever Moodle toggles a
            // conditional section, so the handler is delegated from the form
            // and never needs rebinding.
            $form.on('change input', function(e) {
                var name = e.target && e.target.name ? e.target.name : '';
                if (name.indexOf('series') === 0 || name.indexOf('timestart') === 0) {
                    render($form[0]);
                }
            });

            // date_selector hides its real field behind a popup dialog, so the
            // change only reaches us once the dialog is confirmed. Re-check on
            // a click as a backstop.
            $form.on('click', '.moodle-date-selector, [data-fieldtype="date"] button', function() {
                window.setTimeout(function() {
                    render($form[0]);
                }, 50);
            });

            render($form[0]);
        }
    };
});
