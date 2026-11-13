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
 * Event fired when somebody opens the activity.
 *
 * lib.php advertises FEATURE_COMPLETION_TRACKS_VIEWS, so a course can be set to
 * complete an activity once it has been viewed. That only works if the view is
 * both logged and reported to completion - the feature flag alone does nothing,
 * which is why "view to complete" silently never completed before this class
 * existed.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_module_viewed extends \core\event\course_module_viewed {
    /**
     * Init the event.
     *
     * The object table is the activity table and its name is also the folder the
     * activity lives in: core builds the event URL as /mod/<objecttable>/view.php,
     * so it has to be 'multicheckin' rather than 'mod_multicheckin'.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['objecttable'] = 'multicheckin';
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * Restore mapping.
     *
     * @return array
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'multicheckin', 'restore' => 'multicheckin'];
    }
}
