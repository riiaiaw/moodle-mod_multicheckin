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

namespace mod_multicheckin\event;

/**
 * A check-in record was removed.
 *
 * Students never delete their own records, so every one of these is a teacher
 * action: clearing a point on the marking page, or removing a student's whole
 * session. Before this existed the row simply vanished, which left the log with
 * half its history - the records the plugin did not create - and no way to tell
 * afterwards which check-ins had been taken away and when.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class checkin_removed extends \core\event\base {
    /**
     * Init the event.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['objecttable'] = 'multicheckin_log';
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * Event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_checkin_removed', 'mod_multicheckin');
    }

    /**
     * Description.
     *
     * @return string
     */
    public function get_description(): string {
        return "The user with id '{$this->userid}' removed the check-in of the user " .
            "with id '{$this->relateduserid}' at point with id '{$this->other['pointid']}' " .
            "in the multicheckin session with id '{$this->other['sessionid']}'.";
    }

    /**
     * Restore mapping.
     *
     * @return array
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'multicheckin_log', 'restore' => 'multicheckin_log'];
    }
}
