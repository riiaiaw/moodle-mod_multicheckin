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

defined('MOODLE_INTERNAL') || die();

/**
 * Event fired when a user checks in at a point.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class checkin_recorded extends \core\event\base {

    /**
     * Init the event.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['objecttable'] = 'multicheckin_log';
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * Event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_checkin_recorded', 'mod_multicheckin');
    }

    /**
     * Description.
     *
     * @return string
     */
    public function get_description(): string {
        return "The user with id '{$this->userid}' checked in at point " .
            "with id '{$this->other['pointid']}' in the multicheckin session " .
            "with id '{$this->other['sessionid']}'.";
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
