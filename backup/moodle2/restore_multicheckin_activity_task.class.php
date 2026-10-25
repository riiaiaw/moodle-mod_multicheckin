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
 * Restore task for mod_multicheckin.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/restore_multicheckin_stepslib.php');

/**
 * Restore task that provides all the settings and steps to perform one
 * complete restore of the activity.
 */
class restore_multicheckin_activity_task extends restore_activity_task {

    /**
     * No particular settings for this activity.
     *
     * @return void
     */
    protected function define_my_settings() {
        // Nothing to ask: the repeat rule is not stored as a setting. It is
        // re-derived from the occurrence dates that were restored, which keeps
        // a restored series editable in the same way as the original.
    }

    /**
     * Defines a restore step to load the multicheckin.xml file.
     *
     * @return void
     */
    protected function define_my_steps() {
        $this->add_step(new restore_multicheckin_activity_structure_step(
            'multicheckin_structure', 'multicheckin.xml'));
    }

    /**
     * Define the contents in the activity that must be processed by the link decoder.
     *
     * @return array
     */
    public static function define_decode_contents() {
        return [new restore_decode_content('multicheckin', ['intro'], 'multicheckin')];
    }

    /**
     * Define the decoding rules for links belonging to the activity.
     *
     * @return array
     */
    public static function define_decode_rules() {
        return [
            new restore_decode_rule('MULTICHECKINVIEWBYID', '/mod/multicheckin/view.php', 'id'),
            new restore_decode_rule('MULTICHECKININDEX', '/mod/multicheckin/index.php', 'course'),
        ];
    }

    /**
     * Define the restore log rules that will be applied when restoring the
     * course logs, so module references in the logs are rewritten.
     *
     * @return array
     */
    public static function define_restore_log_rules() {
        return [
            new restore_log_rule('multicheckin', 'view', 'view.php?id={course_module}', '{multicheckin}'),
            new restore_log_rule('multicheckin', 'report', 'report.php?id={course_module}', '{multicheckin}'),
        ];
    }
}
