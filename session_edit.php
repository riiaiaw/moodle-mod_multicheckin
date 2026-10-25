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
 * Edit one attendance occurrence: its title, its note, and its two times.
 *
 * The companion to the batch editor on series.php. That page is for a term's
 * worth of small corrections; this one is for the occurrence that needs a
 * paragraph, and it is the only place the description gets room to be written.
 *
 * The interesting part is not the form but what saving it costs. An occurrence
 * start is the origin every check-in point is measured from, so moving it
 * re-judges attendance that has already been collected. The page therefore
 * refuses to move the times of an occurrence with check-ins until the teacher
 * ticks the box that says so, and afterwards reports how many judgements it
 * recomputed. Nothing else is ever touched: the logs stay exactly as they were.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Moodle 5.x moved plugins under public/, but keeps the authoritative
// config.php one level ABOVE it. A bare '../../config.php' resolves to
// public/config.php there, which is not the file that defines the site.
// Resolving against __DIR__ and probing both layouts finds the real one on
// either a 4.x tree (mod/<name>/) or a 5.x tree (public/mod/<name>/).
$mcroots = [__DIR__ . '/../../config.php', __DIR__ . '/../../../config.php'];
foreach ($mcroots as $mcroot) {
    if (is_readable($mcroot)) {
        require_once($mcroot);
        break;
    }
}
unset($mcroots, $mcroot);
require_once(__DIR__ . '/lib.php');

use mod_multicheckin\description_editor;
use mod_multicheckin\form\session_form;
use mod_multicheckin\occurrence_editor;
use mod_multicheckin\session_manager;

$id = required_param('id', PARAM_INT);
$sessionparam = optional_param('session', 0, PARAM_INT);
$returnparam = optional_param('return', '', PARAM_ALPHA);

$cm = get_coursemodule_from_id('multicheckin', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/multicheckin:manage', $context);

$template = session_manager::get_template_for_cm($cm->id);
if (!$template) {
    throw new \moodle_exception('invalidcoursemodule');
}

// Resolved against this activity's own template, so a hand-edited session id
// cannot retime another course's attendance.
$session = session_manager::resolve_session($template, $sessionparam);
if (!$session) {
    $session = session_manager::default_session($template);
}

// Where the teacher came from. The series page is the default because that is
// where the per-row Edit link lives; view.php is offered for a single-session
// activity, where there is no table to return to.
if ($returnparam === 'view') {
    $returnurl = new moodle_url('/mod/multicheckin/view.php', [
        'id' => $cm->id,
        'session' => $session->id,
    ]);
} else {
    $returnurl = new moodle_url('/mod/multicheckin/series.php', ['id' => $cm->id]);
}

$PAGE->set_url('/mod/multicheckin/session_edit.php', ['id' => $cm->id, 'session' => $session->id]);
$PAGE->set_title(format_string($template->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$checkins = occurrence_editor::log_count((int) $session->id);

$form = new session_form($PAGE->url, [
    'cmid' => $cm->id,
    'session' => $session,
    'checkins' => $checkins,
    'return' => $returnparam,
]);

$form->set_data([
    'id' => $cm->id,
    'session' => $session->id,
    'return' => $returnparam,
    'name' => (string) $session->name,
    'photorequired' => (int) $session->photorequired,
    'timestart' => (int) $session->timestart,
    'timeend' => (int) $session->timeend,
]);

if ($data = $form->get_data()) {
    // The note is not part of this form any more: it is edited in its own
    // window, which writes the column directly. Leaving the key out is what
    // keeps this save from overwriting it with an empty string.
    $values = occurrence_editor::values([
        'name' => $data->name,
        'photorequired' => $data->photorequired,
        'timestart' => $data->timestart,
        'timeend' => $data->timeend,
    ]);

    $changed = occurrence_editor::changes($session, $values);
    $errors = occurrence_editor::validate($values);

    // The gate. Saving an occurrence that has collected check-ins while its
    // times move is allowed, but not by accident: the teacher has to have been
    // shown the consequence and agreed to it. A note-only edit is never gated.
    if (!$errors && occurrence_editor::confirm_needed($changed, $checkins) && empty($data->recalcok)) {
        $errors['recalcok'] = 'session_recalc_required';
    }

    if ($errors) {
        // Re-rendered rather than redirected: the teacher keeps what they
        // typed.
        foreach ($errors as $field => $key) {
            $form->setElementError($field, get_string($key, 'mod_multicheckin'));
        }
    } else {
        $result = occurrence_editor::save($session, $values);

        if (!$result['changed']) {
            $message = get_string('session_unchanged', 'mod_multicheckin');
        } else {
            $message = get_string('session_saved', 'mod_multicheckin',
                format_string($result['occurrence']->name));
            if (occurrence_editor::needs_recalc($result['changed'])) {
                occurrence_editor::recalc($result['occurrence']);
                $message .= ' ' . get_string('session_recalcd', 'mod_multicheckin',
                    occurrence_editor::result_count((int) $session->id));
            }
        }

        redirect($returnurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// The note preview's button opens the editor in a dialog, and the module that
// drives it has to be declared before the page is printed.
description_editor::require_js();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('session_edit', 'mod_multicheckin'));

echo html_writer::div(get_string('session_edit_intro', 'mod_multicheckin'), 'text-muted mb-3');

if (session_manager::is_occurrence($session)) {
    echo html_writer::div(get_string('occurrence_n', 'mod_multicheckin', $session->seqno), 'mb-2');
} else {
    // The activity's own row. Editing it here still works, but the title is the
    // activity name, and a teacher should not discover that from the course page.
    echo $OUTPUT->notification(get_string('session_edit_template', 'mod_multicheckin'),
        \core\output\notification::NOTIFY_INFO, false);
}

if ($checkins > 0) {
    echo $OUTPUT->notification(get_string('session_recalc_notice', 'mod_multicheckin', $checkins),
        \core\output\notification::NOTIFY_WARNING, false);
}

// The note, previewed and linked rather than edited in place. The window it
// opens saves the column itself, so this page's form never carries it.
echo html_writer::div(description_editor::note_cell((int) $cm->id, $session, $returnparam), 'mb-3');

$form->display();

echo html_writer::div($OUTPUT->single_button($returnurl, get_string('back'), 'get'), 'mt-3');

echo $OUTPUT->footer();
