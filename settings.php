<?php
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
 * Site administration settings for mod_multicheckin.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    // ---- Occurrence generation --------------------------------------------
    //
    // The cap exists because one form submission can otherwise create an
    // unbounded number of rows: a daily repeat with a typo'd end year is
    // thousands of sessions, each with its own check-in points. It defaults to
    // sixty - roughly a term of twice-weekly classes - and is a site setting
    // rather than a hard constant so a school running year-round daily
    // attendance can raise it without patching the code.
    $settings->add(new admin_setting_configtext(
        'mod_multicheckin/maxsessions',
        get_string('maxsessions', 'mod_multicheckin'),
        get_string('maxsessions_desc', 'mod_multicheckin',
            \mod_multicheckin\schedule_planner::HARD_MAX_SESSIONS),
        \mod_multicheckin\schedule_planner::DEFAULT_MAX_SESSIONS,
        PARAM_INT
    ));

    // ---- Report controls --------------------------------------------------
    //
    // Sorting, the status filter and the keyword search on the attendance
    // report.
    //
    // A switch for a feature that has no moving parts deserves a reason, and
    // the reason is history: an earlier version of these controls was removed
    // wholesale while an unrelated fault was being investigated - a fault that
    // made every page print a block of text, which turned out to be a
    // JavaScript module and had no traceable link to this code. Rebuilding the
    // controls server-side removes the whole category of cause, but "we could
    // not reproduce it" is not the same as "it cannot happen".
    //
    // So: with this off, the report is the page it was before the controls
    // existed - no links in the summary, no search box, the parameters read but
    // ignored. That makes it the first thing to try when something odd appears
    // after an upgrade, and the first thing to eliminate before concluding the
    // plugin is at fault.
    $settings->add(new admin_setting_configcheckbox(
        'mod_multicheckin/reportfilters',
        get_string('reportfilters', 'mod_multicheckin'),
        get_string('reportfilters_desc', 'mod_multicheckin'),
        1
    ));
}
