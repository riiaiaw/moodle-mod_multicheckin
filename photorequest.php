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
 * Ask the teacher to verify a check-in by hand.
 *
 * The one route out of a photo occurrence that is not a photograph. It exists
 * because the camera genuinely is not always there: permission refused, no
 * camera on the device, a site served over plain HTTP, or the Moodle App, where
 * getUserMedia does not work at all. Without this page those students would
 * simply be unable to check in.
 *
 * It is deliberately a REQUEST and not a check-in. Nothing is recorded here;
 * the row is put in front of a teacher, who decides. That keeps the balance of
 * the feature where it belongs - a student cannot turn "my camera did not
 * work" into attendance on their own say-so - while still giving an honest
 * student a way through.
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

use mod_multicheckin\leave_manager;
use mod_multicheckin\photo_manager;
use mod_multicheckin\point_name;
use mod_multicheckin\session_manager;

$cmid = required_param('id', PARAM_INT);
$sessionid = required_param('session', PARAM_INT);
$pointid = required_param('point', PARAM_INT);

$cm = get_coursemodule_from_id('multicheckin', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/multicheckin:checkin', $context);

$template = session_manager::get_template_for_cm($cm->id);
$session = $template ? session_manager::resolve_session($template, $sessionid) : null;
if (!$session || (int) $session->id !== $sessionid) {
    throw new \moodle_exception('invalidrecord', 'error');
}

$point = $DB->get_record('multicheckin_point', ['id' => $pointid, 'sessionid' => $sessionid], '*', MUST_EXIST);
$session->coursemodule = $cm->id;

$viewurl = new moodle_url('/mod/multicheckin/view.php', ['id' => $cm->id]);
$PAGE->set_url('/mod/multicheckin/photorequest.php', [
    'id' => $cm->id,
    'session' => $session->id,
    'point' => $point->id,
]);
$PAGE->set_title(format_string($template->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// A request only means something where a photograph was demanded. Elsewhere it
// would be a second way to ask for something the student can simply do.
if (!photo_manager::is_required($session)) {
    redirect($viewurl, get_string('photoreq_notrequired', 'mod_multicheckin'), null,
        \core\output\notification::NOTIFY_INFO);
}

// The same gates a check-in itself has to clear, and for the same reason: a
// request is a claim about being in this class now.
if (!session_manager::point_is_open($session, $point)) {
    redirect($viewurl, get_string('point_closed', 'mod_multicheckin'), null,
        \core\output\notification::NOTIFY_ERROR);
}

if ($DB->record_exists('multicheckin_log', [
    'sessionid' => (int) $session->id,
    'pointid' => (int) $point->id,
    'userid' => (int) $USER->id,
])) {
    redirect($viewurl, get_string('already_checked_in', 'mod_multicheckin'), null,
        \core\output\notification::NOTIFY_INFO);
}

if (leave_manager::record((int) $session->id, (int) $USER->id)) {
    redirect($viewurl, get_string('leave_already', 'mod_multicheckin'), null,
        \core\output\notification::NOTIFY_INFO);
}

$existing = photo_manager::record_for((int) $session->id, (int) $point->id, (int) $USER->id);

if (data_submitted()) {
    require_sesskey();

    $reason = trim(optional_param('reason', '', PARAM_TEXT));
    if (\core_text::strlen($reason) > 500) {
        $reason = \core_text::substr($reason, 0, 500);
    }

    photo_manager::request($session, $point, (int) $USER->id, $reason);

    redirect($viewurl, get_string('photoreq_submitted', 'mod_multicheckin'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('photoreq_title', 'mod_multicheckin'));

echo html_writer::div(get_string('photoreq_intro', 'mod_multicheckin', format_string(point_name::display($point))), 'mb-3');

// Where the student already stands, said before the form rather than after it.
// A refusal can be re-opened - that is the only way back for a student whose
// camera is genuinely broken - but they should know a decision was made.
if ($existing) {
    $status = (int) $existing->status;
    echo $OUTPUT->notification(get_string('photoreq_current', 'mod_multicheckin', (object) [
        'status' => photo_manager::status_label($status),
        'time' => userdate((int) $existing->timecreated),
    ]), $status === photo_manager::STATUS_REFUSED
        ? \core\output\notification::NOTIFY_WARNING
        : \core\output\notification::NOTIFY_INFO, false);
}

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'session', 'value' => $session->id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'point', 'value' => $point->id]);

$textarea = html_writer::tag('textarea', '', [
    'name' => 'reason',
    'id' => 'multicheckin-photoreq-reason',
    'rows' => 3,
    'maxlength' => 500,
    'class' => 'form-control',
]);
echo html_writer::div(
    html_writer::tag('label', get_string('photoreq_reason', 'mod_multicheckin'),
        ['for' => 'multicheckin-photoreq-reason', 'class' => 'form-label'])
    . $textarea
    . html_writer::div(get_string('photoreq_reason_help', 'mod_multicheckin'), 'form-text'),
    'mb-3');

// Submitted through a real button rather than a link: this writes a row, and a
// GET that creates something can be triggered by a prefetcher.
echo html_writer::tag('button', get_string('photoreq_submit', 'mod_multicheckin'), [
    'type' => 'submit',
    'class' => 'btn btn-primary',
]);
echo html_writer::end_tag('form');

echo html_writer::div($OUTPUT->single_button($viewurl, get_string('back'), 'get'), 'mt-3');

echo $OUTPUT->footer();
