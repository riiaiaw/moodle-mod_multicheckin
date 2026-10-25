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
 * Edit one student's check-in records for one session.
 *
 * The teacher-facing counterpart to checkin.php. Reached from the report, where
 * the teacher is already looking at the student whose record is wrong; the
 * whole-class sheet lives in markall.php.
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

use mod_multicheckin\form\mark_form;
use mod_multicheckin\leave_manager;
use mod_multicheckin\mark_manager;
use mod_multicheckin\session_manager;

$id = required_param('id', PARAM_INT);
$sessionparam = optional_param('session', 0, PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$cm = get_coursemodule_from_id('multicheckin', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/multicheckin:manage', $context);

$template = session_manager::get_template_for_cm($cm->id);
if (!$template) {
    throw new \moodle_exception('invalidcoursemodule');
}

$session = session_manager::resolve_session($template, $sessionparam);
if (!$session) {
    redirect(new moodle_url('/mod/multicheckin/view.php', ['id' => $cm->id]),
        get_string('series_bad_session', 'mod_multicheckin'), null,
        \core\output\notification::NOTIFY_ERROR);
}

// The student must belong to this activity. Without the check, a hand-edited
// userid would let a teacher of this course write records against any account
// on the site.
$user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
if (!is_enrolled($context, $user, 'mod/multicheckin:checkin')) {
    throw new \moodle_exception('invaliduser');
}

$sessions = session_manager::list_sessions($template);
$points = session_manager::get_points((int) $session->id);

$pageurl = new moodle_url('/mod/multicheckin/mark.php', [
    'id' => $cm->id,
    'session' => $session->id,
    'userid' => $user->id,
]);
$reporturl = new moodle_url('/mod/multicheckin/report.php', [
    'id' => $cm->id,
    'session' => $session->id,
]);

$PAGE->set_url($pageurl);
$PAGE->set_title(format_string($template->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// ---- Remove every record this student has in this session ----------------
// Coarser than unticking the boxes, and separate because it is the action a
// teacher takes when a student signed in for somebody else: the whole session
// has to go, not one point.
if ($action === 'clear' && confirm_sesskey()) {
    // The photographs go first: they are filed under the log row id, so once
    // the rows are gone there is nothing left to find them by.
    \mod_multicheckin\photo_manager::delete_for_user((int) $session->id, (int) $user->id);
    $DB->delete_records('multicheckin_log', [
        'sessionid' => (int) $session->id,
        'userid' => (int) $user->id,
    ]);
    mark_manager::resync($session, (int) $user->id);
    redirect($reporturl, get_string('marks_cleared', 'mod_multicheckin', fullname($user)), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

$states = mark_manager::point_states($session, $points, (int) $user->id);
$leave = leave_manager::record((int) $session->id, (int) $user->id);

$form = new mark_form($pageurl, [
    'cmid' => $cm->id,
    'session' => $session,
    'points' => $points,
    'states' => $states,
    'user' => $user,
    'leave' => $leave,
]);

if ($data = $form->get_data()) {
    $submitted = [];
    foreach ($points as $point) {
        $key = 'p' . $point->id;
        $submitted[(int) $point->id] = [
            'checked' => !empty($data->{$key . '_on'}),
            'clock' => (string) ($data->{$key . '_time'} ?? ''),
        ];
    }

    $totals = mark_manager::apply_marks($session, $points, (int) $user->id, $submitted);

    // The leave override is applied after the marks and never as a substitute
    // for them: the teacher's ticks are still stored, so unticking the leave
    // brings the same record straight back.
    $wantsleave = !empty($data->leave);
    $leavechange = '';
    if ($wantsleave && !$leave) {
        leave_manager::save($session, (int) $user->id, '',
            leave_manager::ORIGIN_TEACHER, null, (int) $USER->id);
        $leavechange = get_string('leave_granted', 'mod_multicheckin', 1);
    } else if (!$wantsleave && $leave) {
        leave_manager::remove($session, (int) $user->id);
        $leavechange = get_string('leave_cleared', 'mod_multicheckin', 1);
    }

    mark_manager::resync($session, (int) $user->id);

    // The form applies the same rule as the writer, so this is a backstop
    // rather than the expected path. Reported rather than swallowed: a point the
    // teacher believes was saved must not fail silently.
    $message = mark_manager::summarise($totals);
    if ($leavechange !== '') {
        $message .= ' ' . $leavechange;
    }
    $type = \core\output\notification::NOTIFY_SUCCESS;
    if ($totals['errors']) {
        $message .= ' ' . get_string('marks_some_rejected', 'mod_multicheckin',
            count($totals['errors']));
        $type = \core\output\notification::NOTIFY_WARNING;
    }

    redirect($reporturl, $message, null, $type);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('marks_edit_heading', 'mod_multicheckin', fullname($user)));

if (count($sessions) > 1) {
    echo \mod_multicheckin\occurrence_helper::render_picker(
        $cm->id, $sessions, (int) $session->id, 'mark.php', ['userid' => (int) $user->id]);
}

if (!$points) {
    echo $OUTPUT->notification(get_string('nopoints', 'mod_multicheckin'),
        \core\output\notification::NOTIFY_WARNING, false);
} else {
    echo html_writer::div(get_string('marks_intro', 'mod_multicheckin'), 'text-muted mb-3');
    $form->display();

    $clearurl = new moodle_url($pageurl, ['action' => 'clear', 'sesskey' => sesskey()]);
    echo html_writer::div(
        html_writer::link($clearurl, get_string('marks_clear', 'mod_multicheckin'),
            ['class' => 'btn btn-outline-danger btn-sm']),
        'mt-3');
}

$backurl = new moodle_url('/mod/multicheckin/report.php',
    ['id' => $cm->id, 'session' => $session->id]);
echo html_writer::div($OUTPUT->single_button($backurl, get_string('back'), 'get'), 'mt-3');

echo $OUTPUT->footer();
