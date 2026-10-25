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
 * Teacher's list of leave requests for an activity.
 *
 * A leave takes effect the moment it is filed, so this page is not an approval
 * queue - the status is already recorded and already scored. What it is for is
 * the teacher's side of "I should look at that": the reason, the note the
 * student attached, and a way to say it has been seen. Clearing a leave is here
 * too, because that is a decision the marking sheet expresses only as one
 * checkbox among many.
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
use mod_multicheckin\mark_manager;
use mod_multicheckin\session_manager;

$id = required_param('id', PARAM_INT);
$sessionparam = optional_param('session', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$userid = optional_param('userid', 0, PARAM_INT);
$actsessionid = optional_param('action_session', 0, PARAM_INT);
$pendingonly = optional_param('pending', 0, PARAM_BOOL);

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
    $session = session_manager::default_session($template);
}

$pageurl = new moodle_url('/mod/multicheckin/leaves.php', [
    'id' => $cm->id,
    'session' => $session->id,
]);
$markallurl = new moodle_url('/mod/multicheckin/markall.php', [
    'id' => $cm->id,
    'session' => $session->id,
]);
$viewurl = new moodle_url('/mod/multicheckin/view.php', [
    'id' => $cm->id,
    'session' => $session->id,
]);

$PAGE->set_url($pageurl);
$PAGE->set_title(format_string($template->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// ---- Actions -------------------------------------------------------------
// Cleared here, never granted: granting belongs on the marking sheet, beside
// the records it overrides. The student and the session both travel in the
// link, so a student with leaves in two sessions cannot have the wrong one
// changed, and the session is re-resolved against this activity rather than
// trusted as a bare id.
if ($action !== '' && $userid > 0 && $actsessionid > 0 && confirm_sesskey()) {
    $targetsession = session_manager::resolve_session($template, $actsessionid);
    if ($targetsession && (int) $targetsession->id === $actsessionid) {
        if ($action === 'review') {
            if (leave_manager::set_reviewed($targetsession, $userid, true, (int) $USER->id)) {
                redirect($pageurl, get_string('leave_reviewed_ok', 'mod_multicheckin'), null,
                    \core\output\notification::NOTIFY_SUCCESS);
            }
        } else if ($action === 'clear') {
            if (leave_manager::remove($targetsession, $userid)) {
                // The status falls back to whatever the logs say, so the
                // gradebook has to be told; the logs were never touched.
                mark_manager::resync($targetsession, $userid);
                redirect($pageurl, get_string('leave_cleared', 'mod_multicheckin', 1), null,
                    \core\output\notification::NOTIFY_SUCCESS);
            }
        }
    }
}

// Mark everything pending as seen in one go - the state a teacher reaches after
// reading the list from top to bottom.
if ($action === 'reviewall' && confirm_sesskey()) {
    $changed = 0;
    foreach (leave_manager::for_template((int) $template->id, true) as $row) {
        $targetsession = $DB->get_record('multicheckin', ['id' => (int) $row->sessionid]);
        if ($targetsession
                && leave_manager::set_reviewed($targetsession, (int) $row->userid, true, (int) $USER->id)) {
            $changed++;
        }
    }
    redirect($pageurl, get_string('leave_reviewed_ok', 'mod_multicheckin'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

// ---- List ----------------------------------------------------------------
$leaves = leave_manager::for_template((int) $template->id, (bool) $pendingonly);
$pendingtotal = count(leave_manager::for_template((int) $template->id, true));

$userids = array_map(fn($row) => (int) $row->userid, $leaves);
$sessionids = array_map(fn($row) => (int) $row->sessionid, $leaves);
$users = $userids ? $DB->get_records_list('user', 'id', $userids) : [];
$sessionrows = $sessionids ? $DB->get_records_list('multicheckin', 'id', $sessionids) : [];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('leaves_heading', 'mod_multicheckin', format_string($template->name)));

if (!$leaves) {
    echo $OUTPUT->notification(get_string('leave_none', 'mod_multicheckin'),
        \core\output\notification::NOTIFY_INFO, false);
    echo html_writer::div($OUTPUT->single_button($viewurl, get_string('back'), 'get'), 'mt-3');
    echo $OUTPUT->footer();
    exit;
}

// The filter is a link rather than a control: two states, one click, and the
// URL says which one you are looking at.
$filterurl = new moodle_url($pageurl, ['pending' => $pendingonly ? 0 : 1]);
$filtertext = $pendingonly
    ? get_string('leaves_showall', 'mod_multicheckin')
    : get_string('leave_pendingcount', 'mod_multicheckin', $pendingtotal);

$table = new html_table();
$table->attributes['class'] = 'generaltable multicheckin-leaves';
$table->head = [
    get_string('user', 'mod_multicheckin'),
    get_string('leave_col_session', 'mod_multicheckin'),
    get_string('leave_col_reason', 'mod_multicheckin'),
    get_string('leave_attachment', 'mod_multicheckin'),
    get_string('leave_origin', 'mod_multicheckin'),
    get_string('leave_col_time', 'mod_multicheckin'),
    get_string('status', 'mod_multicheckin'),
    get_string('action', 'mod_multicheckin'),
];
$table->data = [];

foreach ($leaves as $leave) {
    $student = $users[(int) $leave->userid] ?? null;
    $studentcell = $student
        ? $OUTPUT->user_picture($student, ['size' => 24, 'link' => false]) . fullname($student)
        : get_string('unknownuser');

    $where = $sessionrows[(int) $leave->sessionid] ?? null;
    $sessioncell = $where
        ? userdate($where->timestart, get_string('strftimedaydate'))
        : '—';

    $reason = trim((string) $leave->reason);
    $reasoncell = $reason === '' ? html_writer::span('—', 'text-muted') : s($reason);

    if ($where && leave_manager::has_attachment($where, (int) $leave->id)) {
        $file = leave_manager::attachment($where, (int) $leave->id);
        $fileurl = moodle_url::make_pluginfile_url($context->id, leave_manager::COMPONENT,
            leave_manager::FILEAREA, (int) $leave->id, $file->get_filepath(), $file->get_filename());
        $filecell = html_writer::link($fileurl, get_string('leave_download', 'mod_multicheckin'),
            ['class' => 'small']);
    } else {
        $filecell = html_writer::span(get_string('leave_attachment_none', 'mod_multicheckin'), 'text-muted');
    }

    $origincell = $leave->origin === leave_manager::ORIGIN_TEACHER
        ? get_string('leave_origin_teacher', 'mod_multicheckin')
        : get_string('leave_origin_student', 'mod_multicheckin');

    $statecell = empty($leave->reviewed)
        ? html_writer::span(get_string('leave_pending', 'mod_multicheckin'), 'badge bg-warning text-dark')
        : html_writer::span(get_string('leave_reviewed', 'mod_multicheckin'), 'badge bg-success');

    $actions = [];
    if ($where) {
        $editurl = new moodle_url('/mod/multicheckin/mark.php', [
            'id' => $cm->id,
            'session' => (int) $leave->sessionid,
            'userid' => (int) $leave->userid,
        ]);
        $actions[] = html_writer::link($editurl, get_string('marks_edit', 'mod_multicheckin'),
            ['class' => 'small']);
    }
    if (empty($leave->reviewed)) {
        $reviewurl = new moodle_url($pageurl, [
            'action' => 'review',
            'userid' => (int) $leave->userid,
            'action_session' => (int) $leave->sessionid,
            'sesskey' => sesskey(),
        ]);
        $actions[] = html_writer::link($reviewurl, get_string('leave_markreviewed', 'mod_multicheckin'),
            ['class' => 'small']);
    }
    $clearurl = new moodle_url($pageurl, [
        'action' => 'clear',
        'userid' => (int) $leave->userid,
        'action_session' => (int) $leave->sessionid,
        'sesskey' => sesskey(),
    ]);
    $actions[] = html_writer::link($clearurl, get_string('leave_clear_link', 'mod_multicheckin'), [
        'class' => 'small text-danger',
        'onclick' => 'return confirm(' . json_encode(
            get_string('leave_clear_confirm', 'mod_multicheckin')) . ');',
    ]);

    $table->data[] = [
        $studentcell,
        $sessioncell,
        $reasoncell,
        $filecell,
        $origincell,
        userdate($leave->timecreated),
        $statecell,
        implode(' · ', $actions),
    ];
}

echo html_writer::div(html_writer::link($filterurl, $filtertext, ['class' => 'small']), 'mb-2');
echo html_writer::table($table);

$foot = [];
if ($pendingtotal > 0) {
    $reviewallurl = new moodle_url($pageurl, ['action' => 'reviewall', 'sesskey' => sesskey()]);
    $foot[] = html_writer::link($reviewallurl, get_string('leave_reviewall', 'mod_multicheckin'),
        ['class' => 'btn btn-outline-secondary btn-sm']);
}
$foot[] = html_writer::link($markallurl, get_string('markall_link', 'mod_multicheckin'),
    ['class' => 'btn btn-outline-secondary btn-sm']);
$foot[] = html_writer::link($viewurl, get_string('back'), ['class' => 'btn btn-link btn-sm']);
echo html_writer::div(implode(' ', $foot), 'mt-3');

echo html_writer::div(get_string('leaves_note', 'mod_multicheckin'), 'text-muted small mt-2');

echo $OUTPUT->footer();
