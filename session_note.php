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
 * Edit one occurrence's note, on a page of its own.
 *
 * The pages that own an occurrence - the series table and the single-occurrence
 * form - show a preview of the note and a button. This is what the button
 * opens: the same editor, on a page of its own. Since v2.0.3 that page is
 * normally loaded inside a dialog on the page the button was on, which is what
 * the embed flag marks and what the embedded layout is for; opened on its own -
 * by a browser without scripting, or by a teacher who held a modifier key - it
 * falls back to the popup layout, which is what makes it read as a small window.
 *
 * The point of the split is not tidiness. A note is the only field of an
 * occurrence that wants a paragraph and pictures, and an editor is the most
 * expensive thing on a page: thirty of them turn a term's overview into a page
 * that takes seconds to become usable. One page, loaded on demand, costs one
 * editor - and it costs it exactly when a teacher has asked to edit.
 *
 * The save reuses occurrence_editor, so the note is written by the same code
 * that writes the title and the times, including its length rule. What this
 * page decides is only which two columns to hand it.
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
use mod_multicheckin\form\session_note_form;
use mod_multicheckin\occurrence_editor;
use mod_multicheckin\session_manager;

$id = required_param('id', PARAM_INT);
$sessionparam = optional_param('session', 0, PARAM_INT);
$returnparam = optional_param('return', '', PARAM_ALPHA);
// Set when this page is filling a dialog on the page that opened it rather than
// a window of its own. It changes the layout and the way the page talks back to
// whoever opened it, and nothing else: the form, the validation and the save
// are the same in both cases.
$embed = optional_param('embed', 0, PARAM_INT);

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
// cannot reach another course's occurrence.
$session = session_manager::resolve_session($template, $sessionparam);
if (!$session) {
    $session = session_manager::default_session($template);
}

// Where the window sends the teacher back to. The same two answers the full
// edit page offers: the series table, or the activity page for a
// single-session activity that has no table to return to.
if ($returnparam === 'view') {
    $returnurl = new moodle_url('/mod/multicheckin/view.php', [
        'id' => $cm->id,
        'session' => $session->id,
    ]);
} else {
    $returnurl = new moodle_url('/mod/multicheckin/series.php', ['id' => $cm->id]);
}

$editingurl = new moodle_url('/mod/multicheckin/session_note.php', [
    'id' => $cm->id,
    'session' => $session->id,
    'return' => $returnparam,
]);
if ($embed) {
    // Carried through the save, so the confirmation this page redirects to is
    // still laid out for the frame it is in. Losing it would swap a small form
    // for a full page inside a dialog that was sized for the small one.
    $editingurl->param('embed', 1);
}

$PAGE->set_url($editingurl);
// A small focused form rather than a full page with blocks and a navbar. Inside
// the dialog that means the embedded layout, which is the one Moodle renders
// for a frame; on its own it means the popup layout, which is what makes it
// read as a small window even when the browser gave the teacher a tab.
$PAGE->set_pagelayout($embed ? 'embedded' : 'popup');
$PAGE->set_title(format_string($template->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$form = new session_note_form($editingurl, [
    'cmid' => $cm->id,
    'session' => $session,
    'return' => $returnparam,
    // The form drops its cancel button in a dialog: see its definition().
    'embed' => (bool) $embed,
]);

$form->set_data([
    'id' => $cm->id,
    'session' => $session->id,
    'return' => $returnparam,
    description_editor::FIELD => description_editor::editor_value($session, $context),
]);

// Cancel is a real destination, not a no-op: without this the button submits,
// the form redraws and the teacher is left wondering whether anything happened.
if ($form->is_cancelled()) {
    redirect($returnurl);
}

if ($data = $form->get_data()) {
    // Read the note without touching the file table first. The draft behind it
    // is only moved once the save is known to be going ahead, so a note refused
    // for being too long cannot have already replaced the images the stored
    // note was showing.
    $note = description_editor::row_value((array) $data->{description_editor::FIELD});

    $values = occurrence_editor::values([
        'description' => $note['text'],
        // The element is an editor and always posts its format, so the fallback
        // only covers a request that posted no note field at all.
        'descriptionformat' => (int) ($note['format'] ?? FORMAT_HTML),
    ]);

    $errors = occurrence_editor::validate($values);

    if ($errors) {
        // Re-rendered rather than redirected: the teacher keeps what they
        // typed. The error is reported against the element they can see, not
        // against the column it is stored in.
        foreach ($errors as $field => $key) {
            $element = $field === 'description' ? description_editor::FIELD : $field;
            $form->setElementError($element, get_string($key, 'mod_multicheckin'));
        }
    } else {
        // Now that nothing can refuse it, the draft moves into the file area.
        $values['description'] = description_editor::commit_row((int) $session->id, $note);

        occurrence_editor::save($session, $values);

        redirect(new moodle_url($editingurl, ['saved' => 1]));
    }
}

$saved = (bool) optional_param('saved', 0, PARAM_INT);
if ($saved) {
    // Re-read: the save above wrote the row this page was built from, and what
    // the confirmation shows has to be what was stored rather than what was
    // submitted.
    $session = $DB->get_record('multicheckin', ['id' => (int) $session->id], '*', MUST_EXIST);

    // Registered before the header is printed, which is the only point at which
    // a page can still add script. What it does depends on how this page was
    // opened, and both readings degrade: with scripting off the close button is
    // an ordinary link to the page the form was opened from, so it still ends
    // up somewhere sensible.
    $PAGE->requires->js_init_code(description_editor::note_saved_js($session, (bool) $embed));
}

// Registered whether or not the note was saved, and before the header - the
// back control exists on both readings of this page, and on neither of them
// may a click inside the dialog navigate the frame instead of closing it.
$PAGE->requires->js_init_code(description_editor::note_close_js((bool) $embed));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('session_note', 'mod_multicheckin'));

if (session_manager::is_occurrence($session)) {
    echo html_writer::div(get_string('occurrence_n', 'mod_multicheckin', $session->seqno)
        . ' · ' . format_string($session->name), 'mb-2');
} else {
    // The activity's own row. Editing it here still works, but its title is the
    // activity name, and a teacher should not discover that from a popup.
    echo $OUTPUT->notification(get_string('session_edit_template', 'mod_multicheckin'),
        \core\output\notification::NOTIFY_INFO, false);
}

echo html_writer::div(get_string('session_note_intro', 'mod_multicheckin'), 'text-muted mb-3');

$form->display();

if ($saved) {
    echo $OUTPUT->notification(get_string('session_note_saved', 'mod_multicheckin'),
        \core\output\notification::NOTIFY_SUCCESS, false);

    $stored = trim((string) ($session->description ?? ''));
    if ($stored !== '') {
        echo html_writer::div(description_editor::render($stored,
            description_editor::format_of($session), (int) $session->id, $context),
            'multicheckin-session-description mb-3');
    }

    echo html_writer::div(
        html_writer::link($editingurl, get_string('session_note_continue', 'mod_multicheckin'),
            ['class' => 'btn btn-secondary']) . ' ' .
        html_writer::link($returnurl, get_string('session_note_close', 'mod_multicheckin'),
            ['class' => 'btn btn-primary', 'id' => 'multicheckin-note-close']),
        'mt-2');
} else {
    // A link rather than a form posting to that address: inside the dialog the
    // module turns the click into a close message (see
    // description_editor::note_close_js), and with scripting off the link still
    // goes where it says it goes.
    echo html_writer::div(html_writer::link($returnurl, get_string('back'), [
        'class' => 'btn btn-secondary',
        'id' => 'multicheckin-note-back',
    ]), 'mt-3');
}

echo $OUTPUT->footer();
