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
 * The list of this activity's instances in a course was viewed.
 *
 * Core's course_module_instance_list_viewed is abstract and already supplies
 * everything this needs: crud = 'r', edulevel = LEVEL_OTHER, the URL, the
 * description and the CONTEXT_COURSE validation. Subclassing it is what tells
 * the log that mod/multicheckin/index.php was opened, which is otherwise the
 * one page of this plugin nothing records a view of.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_module_instance_list_viewed extends \core\event\course_module_instance_list_viewed {
}
