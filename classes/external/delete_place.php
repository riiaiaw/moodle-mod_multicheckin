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
 * Deletes a saved classroom location.
 *
 * Deleting from the library never changes an existing attendance rule: each
 * session stored its own copy of the centre and radius when it was configured.
 * The response therefore reports the room's usage only so the caller can tell
 * the teacher which activities still point at the same spot — it does not block
 * the deletion, because there is nothing unsafe about it.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_place extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Place id to delete.'),
        ]);
    }

    /**
     * Delete the place.
     *
     * @param int $id Place id.
     * @return array
     */
    public static function execute(int $id): array {
        $params = self::validate_parameters(self::execute_parameters(), ['id' => $id]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('mod/multicheckin:manageplaces', $context);

        $place = place_manager::get($params['id']);
        if ($place === null) {
            throw new \dml_missing_record_exception(place_manager::TABLE);
        }

        // Recorded before the delete, so the caller can report it.
        $usage = place_manager::usage_count($place);

        $deleted = place_manager::delete($params['id']);

        return [
            'deleted' => $deleted,
            'usagecount' => $usage,
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'deleted' => new external_value(PARAM_BOOL, 'Whether the row was removed.'),
            'usagecount' => new external_value(PARAM_INT,
                'How many sessions still use the same coordinates.'),
        ]);
    }
}
