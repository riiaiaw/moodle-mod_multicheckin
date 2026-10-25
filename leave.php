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
 * Ask for leave from one session.
 *
 * The student-facing counterpart to checkin.php, and deliberately a separate
 * page rather than a fourth method on it: a check-in is an event against a
 * point, a leave is a declaration about the whole class, and the two want
 * different fields, different rules and different words. Scanning a QR code
 * arrives at checkin.php, so leaving this out of there is also what keeps the
 * scan route from offering a leave it cannot justify.
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

use mod_multicheckin\checkin_engine;
use mod_multicheckin\form\leave_form;
use mod_multicheckin\leave_manager;
use mod_multicheckin\session_manager;

$id = required_param('id', PARAM_INT);
$sessionparam = optional_param('session', 0, PARAM_INT);

$cm = get_coursemodule_from_id('multicheckin', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/multicheckin:checkin', $context);

$template = session_manager::get_template_for_cm($cm->id);
if (!$template) {
    throw new \moodle_exception('invalidcoursemodule');
}

// Resolved against the activity's own template, so a hand-edited session id
// cannot record a leave against another course's attendance.
$session = session_manager::resolve_session($template, $sessionparam);
if (!$session) {
    $session = session_manager::default_session($template);
}

$viewurl = new moodle_url('/mod/multicheckin/view.php', [
    'id' => $cm->id,
    'session' => $session->id,
]);

$PAGE->set_url('/mod/multicheckin/leave.php', ['id' => $cm->id, 'session' => $session->id]);
$PAGE->set_title(format_string($template->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// ---- Refusals ------------------------------------------------------------
// Stated before the form is built so the student is told why rather than being
// shown a form whose submission would be rejected.
$existing = leave_manager::record((int) $session->id, (int) $USER->id);

$haslog = $DB->record_exists('multicheckin_log', [
    'sessionid' => (int) $session->id,
    'userid' => (int) $USER->id,
]);

if (!leave_manager::is_open($session)) {
    redirect($viewurl, get_string('leave_closed', 'mod_multicheckin'), null,
        \core\output\notification::NOTIFY_ERROR);
}

// A leave outranks the logs, so a leave filed after a check-in would silently
// erase attendance the student really did have. Refusing is the honest answer;
// the student can ask the teacher, who can set the status from the sheet.
if ($haslog) {
    redirect($viewurl, get_string('leave_haslog', 'mod_multicheckin'), null,
        \core\output\notification::NOTIFY_ERROR);
}

$form = new leave_form($PAGE->url, [
    'cmid' => $cm->id,
    'session' => $session,
    'context' => $context,
    'leave' => $existing,
]);

if ($data = $form->get_data()) {
    leave_manager::save($session, (int) $USER->id, trim((string) $data->reason),
        leave_manager::ORIGIN_STUDENT, (int) $data->leavefile, (int) $USER->id);

    // The status is settled immediately, so the report, the badge and the
    // gradebook all agree with what the student was just told. Only this
    // student's row changed, so only this student is recomputed and pushed: with
    // the class-wide call, sixty students filing leave one after another
    // recomputed and re-pushed the whole class sixty times.
    checkin_engine::refresh_user($session, (int) $USER->id);
    multicheckin_update_grades($session, (int) $USER->id);

    redirect($viewurl, get_string('leave_submitted', 'mod_multicheckin'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('leave_request', 'mod_multicheckin'));

echo html_writer::div(get_string('leave_request_intro', 'mod_multicheckin'), 'text-muted mb-3');

// Stated on the page rather than only in the README: the app's web view cannot
// always hand a file back to the server, and a student who has already written
// the request should not lose it over an attachment.
echo $OUTPUT->notification(get_string('leave_appnote', 'mod_multicheckin'),
    \core\output\notification::NOTIFY_INFO, false);

if ($existing) {
    echo $OUTPUT->notification(get_string('leave_already', 'mod_multicheckin'),
        \core\output\notification::NOTIFY_WARNING, false);
}

// Which session this leave is for. The picker is only useful when a series
// exists; for a single-session activity it is one line of date and time.
echo html_writer::div(
    html_writer::tag('strong', get_string('leave_for', 'mod_multicheckin',
        format_string($template->name))) . ' — '
    . userdate($session->timestart) . ' – ' . userdate($session->timeend, get_string('strftimetime')),
    'mb-3');

$form->display();

$backurl = new moodle_url($viewurl);
echo html_writer::div($OUTPUT->single_button($backurl, get_string('leave_back', 'mod_multicheckin'), 'get'), 'mt-3');

echo $OUTPUT->footer();
