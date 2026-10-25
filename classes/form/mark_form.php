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

use mod_multicheckin\mark_manager;
use mod_multicheckin\point_name;
use mod_multicheckin\session_manager;

/**
 * Form to edit one student's check-in records for one session.
 *
 * One row per check-in point: a tick box and a clock time. The time field is
 * validated against that point's own window, using the same rule the writer
 * applies, so a value the form accepts is always a value that gets stored.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mark_form extends \moodleform {

    /**
     * Definition.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;
        $cmid = $this->_customdata['cmid'];
        $session = $this->_customdata['session'];
        $points = $this->_customdata['points'];
        $states = $this->_customdata['states'];

        $mform->addElement('hidden', 'id', $cmid);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'session', $session->id);
        $mform->setType('session', PARAM_INT);
        $mform->addElement('hidden', 'userid', $this->_customdata['user']->id);
        $mform->setType('userid', PARAM_INT);
        $mform->addElement('hidden', 'action', 'save');
        $mform->setType('action', PARAM_ALPHA);

        foreach ($points as $point) {
            $key = 'p' . $point->id;
            $state = $states[(int) $point->id] ?? null;
            if ($state === null) {
                continue;
            }

            $mform->addElement('header', $key, format_string(point_name::display($point)));

            if (mark_manager::state_is_manual($state)) {
                // This form is where a correction is made, so it is also where
                // the teacher has to see which rows did not come from the
                // student's own device. The sheet and the report already say
                // so; leaving it off here is what makes an old correction look
                // like the student's own record.
                $mform->addElement('static', $key . '_manual', '',
                    \html_writer::span(get_string('mark_manual', 'mod_multicheckin'),
                        'badge bg-info text-dark'));
            }

            $mform->addElement('advcheckbox', $key . '_on',
                get_string('mark_checked', 'mod_multicheckin'));
            $mform->setDefault($key . '_on', $state['checked'] ? 1 : 0);

            $mform->addElement('text', $key . '_time',
                get_string('mark_time', 'mod_multicheckin'),
                ['size' => 6, 'placeholder' => 'HH:MM']);
            $mform->setType($key . '_time', PARAM_RAW_TRIMMED);
            $mform->setDefault($key . '_time',
                $state['checked'] ? $state['clock'] : mark_manager::default_clock($session, $point));

            $mform->addElement('static', $key . '_window', '',
                $this->window_text($session, $point));
        }

        // A leave is one decision for the whole session, so it sits on its own
        // rather than beside a point: ticking it overrides every row above
        // without deleting any of them.
        $mform->addElement('header', 'leaveheader', get_string('leave', 'mod_multicheckin'));
        $mform->addElement('advcheckbox', 'leave',
            get_string('leave_grant', 'mod_multicheckin'));
        $mform->setDefault('leave', !empty($this->_customdata['leave']) ? 1 : 0);
        $mform->addHelpButton('leave', 'leave_grant', 'mod_multicheckin');

        $this->add_action_buttons(false, get_string('savechanges'));
    }

    /**
     * The "open 08:00 - 08:10" hint shown under each point.
     *
     * @param \stdClass $session Session record.
     * @param \stdClass $point Point record.
     * @return string
     */
    protected function window_text(\stdClass $session, \stdClass $point): string {
        [$open, $close] = session_manager::point_window($session, $point);

        return \html_writer::div(
            get_string('mark_window', 'mod_multicheckin', (object) [
                'open' => userdate($open, get_string('strftimetime')),
                'close' => userdate($close, get_string('strftimetime')),
            ]),
            'text-muted small');
    }

    /**
     * Validation.
     *
     * Only checked points are validated: an unchecked point is a request to
     * remove the record, and the clock beside it is irrelevant.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $session = $this->_customdata['session'];
        $states = $this->_customdata['states'];

        foreach ($this->_customdata['points'] as $point) {
            $key = 'p' . $point->id;
            if (empty($data[$key . '_on'])) {
                continue;
            }

            $state = $states[(int) $point->id] ?? null;
            if ($state === null) {
                continue;
            }

            [$open, $close] = session_manager::point_window($session, $point);
            $error = mark_manager::check_clock(
                trim((string) ($data[$key . '_time'] ?? '')),
                $open,
                $close,
                $state['checked'] ? $state['time'] : null
            );

            if ($error !== null) {
                $errors[$key . '_time'] = get_string($error, 'mod_multicheckin', (object) [
                    'open' => userdate($open, get_string('strftimetime')),
                    'close' => userdate($close, get_string('strftimetime')),
                ]);
            }
        }

        return $errors;
    }
}
