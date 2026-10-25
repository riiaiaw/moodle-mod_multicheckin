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
 * The form for one occurrence's note, shown in a window of its own.
 *
 * One field. The note is the only part of an occurrence that wants a page to
 * itself: a title and two times fit in a table row, and a paragraph with
 * pictures in it does not. So the note gets its own small form, its own URL and
 * its own save, and the pages that own the occurrence link to it rather than
 * rendering an editor inline - which is also what stops a page of thirty
 * occurrences from being a page of thirty live editors.
 *
 * The editor is built by Moodle's own 'editor' element, so TinyMCE is
 * initialised the way every other editor on the site is: this form has no
 * JavaScript of its own, does not move an element between containers and does
 * not assume a modal exists. TinyMCE declares that it supports FORMAT_HTML and
 * nothing else, so the element renders a hidden format field instead of a
 * format dropdown - a rich editor, with nothing to configure.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class session_note_form extends \moodleform {

    /**
     * Build the form.
     */
    public function definition() {
        $mform = $this->_form;
        $session = $this->_customdata['session'];
        $cmid = (int) $this->_customdata['cmid'];
        $field = \mod_multicheckin\description_editor::FIELD;

        // The three values that say which occurrence is being edited and where
        // the window should offer to go afterwards.
        $mform->addElement('hidden', 'id', $cmid);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'session', (int) $session->id);
        $mform->setType('session', PARAM_INT);

        $mform->addElement('hidden', 'return', (string) ($this->_customdata['return'] ?? ''));
        $mform->setType('return', PARAM_ALPHA);

        // The element is named description_editor because that is the name its
        // value array (text/format/itemid) travels under; what it writes is the
        // single 'description' column. The id attribute is not optional - the
        // element's template reads it, and without it the editor renders with a
        // PHP notice and a name no script can address.
        $mform->addElement('editor', $field,
            get_string('session_description', 'mod_multicheckin'),
            [
                'id' => \mod_multicheckin\description_editor::element_id((int) $session->id),
                'rows' => 14,
                'cols' => 60,
            ],
            \mod_multicheckin\description_editor::editor_options(\context_module::instance($cmid)));
        $mform->setType($field, PARAM_RAW);
        $mform->addHelpButton($field, 'session_description', 'mod_multicheckin');

        // No cancel button inside a dialog. Its only possible job there is
        // "close without saving", which the back control and the dialog's own
        // close box both do - and a button that submits a form only to have the
        // page redraw itself reads as broken. In the pop-up layout there is no
        // close box to lean on, so the button stays and the page redirects.
        $embed = !empty($this->_customdata['embed']);
        $this->add_action_buttons(!$embed, get_string('savechanges'));
    }
}
