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
}
