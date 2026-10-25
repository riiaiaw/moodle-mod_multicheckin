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

namespace mod_multicheckin\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_multicheckin\place_manager;

/**
 * Lists the saved classroom locations.
 *
 * Requires mod/multicheckin:manageplaces, checked at system level - the same
 * capability, in the same context, that save_place and delete_place use. It
 * used to require nothing at all, which made the library readable by any
 * logged-in account that could call the function: every row carries a room name
 * and its exact WGS-84 centre, and those coordinates are what the geofence is
 * judged against. The picker this feeds is only ever drawn for someone who may
 * maintain the library, so the read is gated exactly like the write.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_places extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Return every saved place.
     *
     * @return array
     */
    public static function execute(): array {
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('mod/multicheckin:manageplaces', $context);

        return [
            'places' => place_manager::get_all_for_js(),
            'canedit' => place_manager::can_edit(),
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'places' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Place id.'),
                    'name' => new external_value(PARAM_TEXT, 'Display name.'),
                    'description' => new external_value(PARAM_TEXT, 'Optional note.'),
                    'lat' => new external_value(PARAM_FLOAT, 'WGS-84 latitude.'),
                    'lng' => new external_value(PARAM_FLOAT, 'WGS-84 longitude.'),
                    'defaultradius' => new external_value(PARAM_INT, 'Suggested radius in metres.'),
                    'label' => new external_value(PARAM_TEXT, 'Label for the dropdown.'),
                    'latdisplay' => new external_value(PARAM_TEXT, 'Formatted latitude.'),
                    'lngdisplay' => new external_value(PARAM_TEXT, 'Formatted longitude.'),
                    'radiusdisplay' => new external_value(PARAM_TEXT, 'Formatted radius.'),
                ])
            ),
            'canedit' => new external_value(PARAM_BOOL, 'Whether the caller may modify the library.'),
        ]);
    }
}
