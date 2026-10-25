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
 * Multi Check-in module version information.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2026102404;        // The current module version (yyyyMMddXX).
$plugin->requires  = 2026042000;        // Requires Moodle 5.2 (2026042000).
$plugin->supported = [502, 502];        // Available for Moodle 5.2.x only.
// The component is the FULL name, type prefix included: 'mod_multicheckin'.
// Moodle splits it on underscores and reads the first segment as the plugin
// type, so this value is what tells it the folder at mod/multicheckin/ holds
// an activity module. Dropping the prefix ('multicheckin') makes the type
// unreadable and the plugin cannot be installed at all; doubling it
// ('mod_mod_multicheckin') makes the plugin address a component that does not
// exist. Neither is what this site wants.
//
// Note the contrast with upgrade_mod_savepoint() in db/upgrade.php, which
// takes the BARE module name ('multicheckin'): that helper prepends 'mod_'
// itself, so passing 'mod_multicheckin' there would rebuild the doubled form.
$plugin->component = 'mod_multicheckin'; // Full name of the plugin.
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = 'v2.1.4 (Moodle 5.2)';
// No $plugin->dependencies. This plugin used to declare mod_attendance, but
// that list is a HARD install-time gate in Moodle - a site without the standard
// Attendance module could not install this one at all - and the only thing it
// bought was the optional write-through of computed statuses into
// attendance_log. That write-through is gone as of 2.0.0, so the declaration
// went with it and this module now installs and runs on its own report alone.
