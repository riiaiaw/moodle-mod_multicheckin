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
 * Backup structure for mod_multicheckin.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/backup_multicheckin_stepslib.php');

/**
 * Provides the steps to perform one complete backup of the multicheckin instance.
 *
 * The attendance occurrence series is backed up as part of the activity: an
 * occurrence is a row in the same table as the activity, so it travels with
 * the activity's own elements list and needs no separate handling. Restoring
 * without them would silently collapse a term's attendance to its template,
 * which is why the restore step remaps parentid rather than relying on the
 * numeric ids.
 */
class backup_multicheckin_activity_task extends backup_activity_task {

    /**
     * No specific settings for this activity.
     *
     * @return void
     */
    protected function define_my_settings() {
        // Nothing to configure: attendance figures are content, not a setting.
    }

    /**
     * Defines a backup step to store the instance data in the multicheckin.xml file.
     *
     * @return void
     */
    protected function define_my_steps() {
        $this->add_step(new backup_multicheckin_activity_structure_step(
            'multicheckin_structure', 'multicheckin.xml'));
    }

    /**
     * Encode URL references in the activity intro.
     *
     * @param string $content Encoded content.
     * @return string
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        // Link to the activity index.
        $search = "/(" . $base . "\/mod\/multicheckin\/index.php\?id\=)([0-9]+)/";
        $content = preg_replace($search, '$@MULTICHECKININDEX*$2@$', $content);

        // Link to one activity, optionally pinned to an occurrence. The
        // occurrence id is deliberately not encoded: ids are site-specific and
        // restoring them would point at whatever row now happens to hold that
        // number. The restored link opens the activity's default session.
        $search = "/(" . $base . "\/mod\/multicheckin\/view.php\?id\=)([0-9]+)/";
        $content = preg_replace($search, '$@MULTICHECKINVIEWBYID*$2@$', $content);

        return $content;
    }
}
