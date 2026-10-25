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

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * The form for one occurrence: title, note, photo demand, start and end.
 *
 * Only the fields an occurrence owns. Everything that belongs to the
 * activity - the audience, the geofence, the points, the grade weights - is
 * configured on the activity form and reached through sync_occurrence_settings();
 * offering it here as well would create two places to change one setting, and
 * the second one would be the one that loses. The photo demand is here for the
 * opposite reason: it varies per date, and it changes the shape of a check-in
 * for that date alone.
 *
 * The times use date_time_selector so the site's timezone rules are applied by
 * Moodle rather than by this plugin, which is why this form does not have to
 * parse a date at all.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class session_form extends \moodleform {

    /**
     * Build the form.
     */
    public function definition() {
        $mform = $this->_form;
        $session = $this->_customdata['session'];
        $cmid = (int) $this->_customdata['cmid'];
        $checkins = (int) ($this->_customdata['checkins'] ?? 0);

        $mform->addElement('hidden', 'id', $cmid);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'session', (int) $session->id);
        $mform->setType('session', PARAM_INT);

        $mform->addElement('hidden', 'return', (string) ($this->_customdata['return'] ?? ''));
        $mform->setType('return', PARAM_ALPHA);

        $mform->addElement('text', 'name', get_string('session_title', 'mod_multicheckin'),
            ['size' => 64, 'maxlength' => 255]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addHelpButton('name', 'session_title', 'mod_multicheckin');

        // The note is NOT here. Since v2.0.0 it is edited on a page of its own
        // (session_note.php), which this page links to, because an editor is the
        // most expensive thing on a page and this form sits beside a table of a
        // term's occurrences. What the page shows instead is a preview and the
        // button that opens it - in a dialog since v2.0.3.

        $mform->addElement('date_time_selector', 'timestart', get_string('session_start', 'mod_multicheckin'));
        $mform->addElement('date_time_selector', 'timeend', get_string('session_end', 'mod_multicheckin'));

        // The photo demand. A per-occurrence setting rather than a per-activity
        // one because it is not the same question every week - a lab session
        // may be photographed and the lecture beside it not - and it is
        // deliberately absent from the activity form, whose save pushes its
        // values onto every occurrence of the term.
        $mform->addElement('selectyesno', 'photorequired',
            get_string('session_photorequired', 'mod_multicheckin'));
        $mform->setType('photorequired', PARAM_INT);
        $mform->setDefault('photorequired', 0);
        $mform->addHelpButton('photorequired', 'session_photorequired', 'mod_multicheckin');

        // The recompute confirmation. Rendered only when this occurrence has
        // collected check-ins - a session nobody has checked into yet can be
        // moved freely, and a warning that fires when there is nothing to warn
        // about is a warning teachers learn to dismiss without reading.
        if ($checkins > 0) {
            $mform->addElement('advcheckbox', 'recalcok', get_string('session_recalc', 'mod_multicheckin'),
                get_string('session_recalc_label', 'mod_multicheckin', $checkins));
            $mform->setType('recalcok', PARAM_INT);
            $mform->addHelpButton('recalcok', 'session_recalc', 'mod_multicheckin');
        }

        $this->add_action_buttons(true, get_string('savechanges'));
    }
}
