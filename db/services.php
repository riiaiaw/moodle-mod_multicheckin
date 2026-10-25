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
 * Web service definitions for the classroom location library.
 *
 * These exist so the activity form can save a room without a page reload.
 *
 * All three require mod/multicheckin:manageplaces. The list call used to carry
 * none, on the reading that a campus room list is public information - but what
 * it actually returns per row is a name, an exact WGS-84 coordinate and the
 * radius the fence will be judged against, for every room on the site, to any
 * logged-in account that can send a web-service request. The picker it feeds
 * only ever appears on the forms of teachers who may maintain the library, so
 * reading it is gated exactly like writing it.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_multicheckin_list_places' => [
        'classname' => 'mod_multicheckin\external\list_places',
        'methodname' => 'execute',
        'description' => 'List the saved classroom locations.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/multicheckin:manageplaces',
    ],
    'mod_multicheckin_save_place' => [
        'classname' => 'mod_multicheckin\external\save_place',
        'methodname' => 'execute',
        'description' => 'Create or update a saved classroom location.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/multicheckin:manageplaces',
    ],
    'mod_multicheckin_delete_place' => [
        'classname' => 'mod_multicheckin\external\delete_place',
        'methodname' => 'execute',
        'description' => 'Delete a saved classroom location.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/multicheckin:manageplaces',
    ],
];
