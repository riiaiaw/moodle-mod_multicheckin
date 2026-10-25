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
use core_external\external_single_structure;
use core_external\external_value;
use mod_multicheckin\place_manager;

/**
 * Creates or updates a saved classroom location.
 *
 * A single method covers both because the form that calls it is the same in
 * both cases: the only difference is whether an id was supplied. Splitting them
 * would duplicate the validation and the permission check for no benefit.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_place extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Place id to update; 0 to create.', VALUE_DEFAULT, 0),
            'name' => new external_value(PARAM_TEXT, 'Display name, e.g. 主楼 A301.'),
            'description' => new external_value(PARAM_TEXT, 'Optional note.', VALUE_DEFAULT, ''),
            'lat' => new external_value(PARAM_FLOAT, 'WGS-84 latitude.'),
            'lng' => new external_value(PARAM_FLOAT, 'WGS-84 longitude.'),
            'defaultradius' => new external_value(PARAM_INT, 'Suggested radius in metres.', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Save the place.
     *
     * @param int $id Place id, 0 to create.
     * @param string $name Display name.
     * @param string $description Optional note.
     * @param float $lat Latitude.
     * @param float $lng Longitude.
     * @param int $defaultradius Radius in metres.
     * @return array
     */
    public static function execute(int $id = 0, string $name = '', string $description = '',
            float $lat = 0.0, float $lng = 0.0, int $defaultradius = 0): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'lat' => $lat,
            'lng' => $lng,
            'defaultradius' => $defaultradius,
        ]);

        // The library is site-wide, so the capability is checked at system
        // level. validate_context() guarantees we have a valid session before
        // has_capability() is consulted.
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('mod/multicheckin:manageplaces', $context);

        $data = [
            'name' => $params['name'],
            'description' => $params['description'],
            'lat' => $params['lat'],
            'lng' => $params['lng'],
            // Zero means "caller did not care"; let the manager apply its
            // default rather than storing a meaningless zero.
            'defaultradius' => $params['defaultradius'] > 0 ? $params['defaultradius'] : null,
        ];

        try {
            if ($params['id'] > 0) {
                place_manager::update($params['id'], $data);
                $placeid = $params['id'];
                $created = false;
            } else {
                $placeid = place_manager::create($data);
                $created = true;
            }
        } catch (\invalid_parameter_exception $e) {
            // Turn the internal code into a message the teacher can act on,
            // rather than leaking "placelat_range" into the interface.
            throw new \invalid_parameter_exception(place_manager::error_string($e->getMessage()));
        }

        $record = place_manager::get($placeid);

        return [
            'id' => $placeid,
            'created' => $created,
            'place' => $record === null ? [] : place_manager::export($record),
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'The saved place id.'),
            'created' => new external_value(PARAM_BOOL, 'True when a new place was created.'),
            'place' => new external_single_structure([
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
            ], 'The saved record.'),
        ]);
    }
}
