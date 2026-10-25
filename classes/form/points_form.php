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

namespace mod_multicheckin\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use mod_multicheckin\point_name;

/**
 * Form to edit the check-in points of a session.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class points_form extends \moodleform {

    /**
     * Definition.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;
        $points = $this->_customdata['points'];

        $mform->addElement('hidden', 'id', $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'action', 'savepoints');
        $mform->setType('action', PARAM_ALPHA);

        foreach ($points as $p) {
            $key = 'p' . $p->id;
            // The heading names the point, but the text box below it keeps the
            // stored name: the box is where the teacher edits the value, and
            // showing them a translated default there would write that
            // translation back into the row the moment they pressed save.
            $mform->addElement('header', $key, format_string(point_name::display($p)));

            $mform->addElement('hidden', $key . '_id', $p->id);
            $mform->setType($key . '_id', PARAM_INT);

            $mform->addElement('text', $key . '_name', get_string('pointname', 'mod_multicheckin'), ['size' => 30]);
            $mform->setType($key . '_name', PARAM_TEXT);
            $mform->setDefault($key . '_name', $p->name);

            $mform->addElement('text', $key . '_openoffset',
                get_string('openoffset', 'mod_multicheckin'), ['size' => 8]);
            $mform->setType($key . '_openoffset', PARAM_INT);
            $mform->setDefault($key . '_openoffset', $p->openoffset);
            $mform->addHelpButton($key . '_openoffset', 'openoffset', 'mod_multicheckin');

            $mform->addElement('text', $key . '_closeoffset',
                get_string('closeoffset', 'mod_multicheckin'), ['size' => 8]);
            $mform->setType($key . '_closeoffset', PARAM_INT);
            $mform->setDefault($key . '_closeoffset', $p->closeoffset);

            $mform->addElement('select', $key . '_role', get_string('pointrole', 'mod_multicheckin'), [
                'first' => get_string('role_first', 'mod_multicheckin'),
                'mid' => get_string('role_mid', 'mod_multicheckin'),
                'last' => get_string('role_last', 'mod_multicheckin'),
            ]);
            $mform->setDefault($key . '_role', $p->role);

            $mform->addElement('advcheckbox', $key . '_required', get_string('required', 'mod_multicheckin'));
            $mform->setDefault($key . '_required', $p->required);

            $mform->addElement('advcheckbox', $key . '_qrcode', get_string('pointqrcode', 'mod_multicheckin'));
            $mform->setDefault($key . '_qrcode', $p->qrcode);
            $mform->addHelpButton($key . '_qrcode', 'pointqrcode', 'mod_multicheckin');

            // Placed directly after the QR box because the two are read
            // together: qrcode says "offer the code", qronly says "the code is
            // the only way in". Tick this and the check-in button, the password
            // route and the occurrence-level self check-in switch all stop
            // mattering for this point.
            $mform->addElement('advcheckbox', $key . '_qronly', get_string('pointqronly', 'mod_multicheckin'));
            $mform->setDefault($key . '_qronly', $p->qronly ?? 0);
            $mform->addHelpButton($key . '_qronly', 'pointqronly', 'mod_multicheckin');

            $mform->addElement('advcheckbox', $key . '_notify', get_string('pointnotify', 'mod_multicheckin'));
            $mform->setDefault($key . '_notify', $p->notify);

            $mform->addElement('text', $key . '_password', get_string('pointpassword', 'mod_multicheckin'), ['size' => 30]);
            $mform->setType($key . '_password', PARAM_TEXT);
            $mform->setDefault($key . '_password', $p->password);
        }

        $this->add_action_buttons(false, get_string('savechanges'));
    }
}
