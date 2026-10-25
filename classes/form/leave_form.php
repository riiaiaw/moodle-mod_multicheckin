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

use mod_multicheckin\leave_manager;

/**
 * Form to ask for leave from one session.
 *
 * Both inputs are optional: the declaration itself is the record, and a student
 * who has nothing to show should not be stopped from saying "I was not there".
 * The note is a real filepicker rather than a hand-rolled upload so the size
 * limit, the repository list and the accepted types all come from the site's
 * own file API.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class leave_form extends \moodleform {

    /**
     * Definition.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;
        $cmid = $this->_customdata['cmid'];
        $session = $this->_customdata['session'];
        $context = $this->_customdata['context'];
        $leave = $this->_customdata['leave'] ?? null;

        $mform->addElement('hidden', 'id', $cmid);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'session', $session->id);
        $mform->setType('session', PARAM_INT);

        $mform->addElement('textarea', 'reason', get_string('leave_reason', 'mod_multicheckin'),
            ['rows' => 3, 'cols' => 50]);
        $mform->setType('reason', PARAM_TEXT);
        $mform->addHelpButton('reason', 'leave_reason', 'mod_multicheckin');
        $mform->setDefault('reason', $leave ? (string) $leave->reason : '');

        // The picker works on a draft area, so an already-stored note is copied
        // into the draft first: without that the field would look empty on a
        // second visit and saving would wipe the file the student attached.
        $draftitemid = file_get_submitted_draft_itemid('leavefile');
        $options = leave_manager::file_options();
        file_prepare_draft_area($draftitemid, $context->id, leave_manager::COMPONENT,
            leave_manager::FILEAREA, $leave ? (int) $leave->id : null, $options);

        $mform->addElement('filepicker', 'leavefile',
            get_string('leave_attachment', 'mod_multicheckin'), null, $options);
        $mform->setType('leavefile', PARAM_INT);
        $mform->addHelpButton('leavefile', 'leave_attachment', 'mod_multicheckin');
        $mform->setDefault('leavefile', $draftitemid);

        $this->add_action_buttons(false, get_string('leave_submit', 'mod_multicheckin'));
    }

    /**
     * Validation.
     *
     * The accepted-type list on a filepicker is a UI hint: a request can carry
     * any draft item id, so the type is re-checked here against the file that
     * actually arrived in the draft area.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $draftid = (int) ($data['leavefile'] ?? 0);
        if ($draftid <= 0) {
            return $errors;
        }

        $context = $this->_customdata['context'];
        $fs = get_file_storage();
        $accepted = leave_manager::accepted_types();
        $draftfiles = $fs->get_area_files($context->id, 'user', 'draft', $draftid, 'id', false);

        foreach ($draftfiles as $file) {
            $extension = '.' . \core_text::strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
            if (!in_array($extension, $accepted, true)) {
                $errors['leavefile'] = get_string('leave_filetype', 'mod_multicheckin');
                break;
            }
        }

        return $errors;
    }
}
