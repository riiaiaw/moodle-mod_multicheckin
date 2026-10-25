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
 * Attendance report for a multicheckin instance.
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
use mod_multicheckin\mark_manager;
use mod_multicheckin\nav_toolbar;
use mod_multicheckin\occurrence_helper;
use mod_multicheckin\photo_manager;
use mod_multicheckin\point_name;
use mod_multicheckin\qr_manager;
use mod_multicheckin\report_bar;
use mod_multicheckin\session_manager;

$id = required_param('id', PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);
$sessionparam = optional_param('session', 0, PARAM_INT);
$all = optional_param('all', 0, PARAM_INT);

$cm = get_coursemodule_from_id('multicheckin', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/multicheckin:viewreports', $context);

$template = session_manager::get_template_for_cm($cm->id);
if (!$template) {
    throw new \moodle_exception('invalidcoursemodule');
}

$sessions = session_manager::list_sessions($template);
$multi = count($sessions) > 1;
$showall = $multi && $all;

$session = session_manager::resolve_session($template, $sessionparam);
if (!$session) {
    $session = session_manager::default_session($template);
}

$PAGE->set_url('/mod/multicheckin/report.php', ['id' => $cm->id, 'session' => $session->id]);
$PAGE->set_title(format_string($template->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// ---- Whole-series report ----------------------------------------------
//
// With a series, the per-occurrence sheet answers "who came to this class" but
// not "how is this student doing". The series view answers the second: one row
// per student, one column per occurrence, so a pattern of absences is visible
// at a glance instead of having to open twenty reports.
if ($showall) {
    \mod_multicheckin\report_helper::render_series_report($course, $cm, $context, $template, $sessions,
        $download === 'csv');
    exit;
}

$points = session_manager::get_points((int) $session->id);
// The sheet lists the activity's audience, which is the same roster the engine
// computes for: a teacher appears only if the audience was widened to include
// their role.
$users = \mod_multicheckin\audience_manager::participants($session, $context);

// Editing the records is offered only to people who may change them, and the
// whole column is left out otherwise rather than shown disabled.
$canmark = has_capability('mod/multicheckin:manage', $context);

$statuslabels = [
    checkin_engine::STATUS_PRESENT => get_string('status_present', 'mod_multicheckin'),
    checkin_engine::STATUS_LATE => get_string('status_late', 'mod_multicheckin'),
    checkin_engine::STATUS_EARLY => get_string('status_early', 'mod_multicheckin'),
    checkin_engine::STATUS_LATE_EARLY => get_string('status_late_early', 'mod_multicheckin'),
    checkin_engine::STATUS_ABSENT => get_string('status_absent', 'mod_multicheckin'),
    checkin_engine::STATUS_LEAVE => get_string('status_leave', 'mod_multicheckin'),
];

// Build the report rows.
//
// Everything the loop needs is read once for the class and indexed by user.
// Reading per student - which is what this did - cost a result row, that
// student's logs, and (for anyone the scheduled task had not reached yet) the
// same logs and leave a second time inside compute_user(). On a sixty-strong
// class that is roughly two hundred and forty statements to draw one table,
// against three here. The rows themselves are unchanged: compute_user_with()
// is the same judgement compute_user() reaches, so a student the task has not
// reached still gets the answer the task would have stored.
$allresults = $DB->get_records('multicheckin_result', ['sessionid' => $session->id]);
$allleaves = \mod_multicheckin\leave_manager::for_session((int) $session->id);
$alllogs = [];
foreach ($DB->get_records('multicheckin_log', ['sessionid' => $session->id]) as $log) {
    $alllogs[(int) $log->userid][(int) $log->id] = $log;
}

$rows = [];
foreach ($users as $user) {
    $userid = (int) $user->id;
    $result = $allresults[$userid] ?? null;
    $logs = $alllogs[$userid] ?? [];

    // Count check-ins per point for this user, and note which of them a teacher
    // entered. One read covers both; counting per cell would be one query per
    // student per point.
    $pointcounts = [];
    $manualpoints = [];
    foreach ($points as $p) {
        $pointcounts[$p->id] = 0;
    }
    foreach ($logs as $log) {
        $pid = (int) $log->pointid;
        if (!array_key_exists($pid, $pointcounts)) {
            continue;
        }
        $pointcounts[$pid]++;
        if (mark_manager::is_manual($log)) {
            $manualpoints[$pid] = true;
        }
    }

    if (!$result) {
        // No stored result row for this user yet. That is normal while a session
        // is still running, and it also happens for a session the scheduled
        // finalise task has not reached. Falling straight back to "absent" would
        // then report a student who checked in and out as never having turned up,
        // so the status is derived from their check-in logs here instead.
        // compute_user_with() only reads what it was handed, so the page stays a
        // pure view and the answer is the one the task would have stored.
        $computed = checkin_engine::compute_user_with($session, $points, $userid, $logs,
            $allleaves[$userid] ?? null);
        $result = (object) $computed;
    }

    $statuscode = $result->statuscode;
    $rows[] = (object) [
        'user' => fullname($user),
        'userid' => (int) $user->id,
        // The student number as this site stores it. It is read from the
        // username field - the login name - because that is the identifier
        // every account is guaranteed to have, and a school that issues student
        // numbers as usernames gets the number itself here.
        'username' => (string) $user->username,
        'pointcounts' => $pointcounts,
        'manualpoints' => $manualpoints,
        'statuscode' => $statuscode,
        'status' => $statuslabels[$statuscode] ?? $statuscode,
        'firstcheckin' => $result->firstcheckin ? userdate($result->firstcheckin, get_string('strftimetime')) : '-',
        'lastcheckin' => $result->lastcheckin ? userdate($result->lastcheckin, get_string('strftimetime')) : '-',
        'latemin' => (int) $result->latemin,
        'earlymin' => (int) $result->earlymin,
        'points' => (float) $result->points,
    ];
}

// What the roster adds up to.
//
// Counted from $rows, the array the table below is drawn from - not from a
// second query. Two reads of the same thing are two chances to disagree, and a
// summary that says five were absent while the table lists four is worse than
// no summary at all: the reader has no way to tell which half lied.
$summary = report_bar::count($rows);

// CSV export.
if ($download === 'csv') {
    $filename = clean_filename($session->name . '_attendance_report.csv');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    // BOM for Excel Chinese compatibility.
    fwrite($out, "\xEF\xBB\xBF");

    $header = [
        get_string('user'),
        get_string('studentid', 'mod_multicheckin'),
    ];
    foreach ($points as $p) {
        $header[] = format_string(point_name::display($p));
    }
    // Which points were entered by the teacher, spelled out rather than folded
    // into the counts: a spreadsheet column of numbers stays a column of
    // numbers, and the attribution the teacher can see on screen survives the
    // export. Without it a printed sheet says a check-in happened but not who
    // recorded it.
    $header[] = get_string('mark_manual', 'mod_multicheckin');
    $header = array_merge($header, [
        get_string('status', 'mod_multicheckin'),
        get_string('firstcheckin', 'mod_multicheckin'),
        get_string('lastcheckin', 'mod_multicheckin'),
        get_string('latemin', 'mod_multicheckin'),
        get_string('earlymin', 'mod_multicheckin'),
        get_string('points', 'mod_multicheckin'),
    ]);
    fputcsv($out, $header);

    foreach ($rows as $r) {
        $line = [$r->user, $r->username];
        foreach ($points as $p) {
            $line[] = $r->pointcounts[$p->id];
        }
        // Same order as the header above: the names of the points a teacher
        // entered, or an empty cell when every record came from the student.
        $manualnames = [];
        foreach ($points as $p) {
            if (!empty($r->manualpoints[$p->id])) {
                $manualnames[] = trim(strip_tags(format_string(point_name::display($p))));
            }
        }
        $line[] = implode(', ', $manualnames);
        $line = array_merge($line, [
            $r->status,
            $r->firstcheckin,
            $r->lastcheckin,
            $r->latemin,
            $r->earlymin,
            $r->points,
        ]);
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('viewreport', 'mod_multicheckin'));

// With a series, the teacher needs both windows on the same data: this class
// (below) and the whole term (a separate, wider table). The switch is a plain
// link rather than a form so either view can be bookmarked or put on the course
// page.
if ($multi) {
    $allurl = new moodle_url($PAGE->url, ['all' => 1]);
    echo html_writer::div(html_writer::link($allurl, get_string('reportall_link', 'mod_multicheckin')),
        'mb-2');
}

echo occurrence_helper::render_picker((int) $cm->id, $sessions, (int) $session->id, 'report.php');

// The occurrence's own note, so a teacher reading a column of statuses has the
// one line that says what the session was.
echo occurrence_helper::render_description($session, $context);

// One row, two halves: the action this page offers on the left, what the
// numbers add up to on the right.
//
// The export button used to be a single_button() - a <form> per button, and a
// form is a block, so it sat on a line of its own with a browser margin above
// and below it, next to nothing. The summary goes on the same line because it
// answers the question the teacher asks before reading a single row ("how many
// were missing today"), and putting it behind the table would mean scrolling
// past forty names to find it. On a narrow screen the two halves wrap instead
// of shrinking: a badge row squeezed to half a phone would be unreadable.
$csvurl = new moodle_url($PAGE->url, ['download' => 'csv']);
echo html_writer::div(
    report_bar::export_button($csvurl) . report_bar::summary($summary),
    'd-flex flex-wrap align-items-center gap-3 mb-3 multicheckin-report-bar');

// The QR panel sits at the top centre, above the roster table, so the teacher
// can project this page and have students scan the code first. It renders
// only for managers and only while a QR-enabled point is open.
echo qr_manager::teacher_qr_panel((int) $cm->id, $session, $points);

$table = new html_table();
$table->head = [
    get_string('user'),
    // The student number sits beside the name rather than at the far end of
    // the row: a sheet is read by matching a face to a number, and the two are
    // no use apart.
    get_string('studentid', 'mod_multicheckin'),
];
foreach ($points as $p) {
    $table->head[] = format_string(point_name::display($p));
}
$table->head = array_merge($table->head, [
    get_string('status', 'mod_multicheckin'),
    get_string('firstcheckin', 'mod_multicheckin'),
    get_string('lastcheckin', 'mod_multicheckin'),
    get_string('latemin', 'mod_multicheckin'),
    get_string('earlymin', 'mod_multicheckin'),
    get_string('points', 'mod_multicheckin'),
]);
if ($canmark) {
    $table->head[] = get_string('action', 'mod_multicheckin');
}

$table->data = [];

// The photographs for the whole page, in one sweep, so a point cell can carry
// the evidence for the count beside it. Collected before the loop rather than
// inside it: a file-area lookup per cell would make this page's cost grow with
// the class, and it is already the page a teacher leaves open during a lecture.
$photos = photo_manager::for_session_users($session,
    array_map(fn($r) => (int) $r->userid, $rows));

foreach ($rows as $r) {
    $line = [$r->user, s($r->username)];
    foreach ($points as $p) {
        $count = $r->pointcounts[$p->id];
        // A teacher-entered record is worth flagging: it is the one cell in the
        // row that did not come from the student's own device.
        if ($count && !empty($r->manualpoints[$p->id])) {
            $count .= ' ' . html_writer::span(
                get_string('mark_manual', 'mod_multicheckin'), 'badge bg-info text-dark');
        }
        $photo = $photos[(int) $r->userid][(int) $p->id] ?? null;
        if ($photo) {
            $count .= ' ' . photo_manager::link($photo,
                html_writer::tag('i', '', ['class' => 'fa fa-camera', 'aria-hidden' => 'true']));
        }
        $line[] = $count;
    }
    $line[] = html_writer::span($r->status, checkin_engine::status_class($r->statuscode));
    $line[] = $r->firstcheckin;
    $line[] = $r->lastcheckin;
    $line[] = $r->latemin;
    $line[] = $r->earlymin;
    $line[] = $r->points;
    if ($canmark) {
        $markurl = new moodle_url('/mod/multicheckin/mark.php', [
            'id' => $cm->id,
            'session' => $session->id,
            'userid' => $r->userid,
        ]);
        $line[] = html_writer::link($markurl, get_string('marks_edit', 'mod_multicheckin'));
    }
    $table->data[] = $line;
}

echo html_writer::table($table);

// ---- The options toolbar ----
//
// The same helper the activity page uses, so the bottom of every page in this
// plugin reads the same way and offers the same set in the same order. Taking
// the roll is the action this report exists to feed, so it is the primary
// button.
$toolbar = new nav_toolbar();

if ($canmark) {
    $markallurl = new moodle_url('/mod/multicheckin/markall.php', [
        'id' => $cm->id,
        'session' => $session->id,
    ]);
    $toolbar->add(nav_toolbar::GROUP_TEACHER, $markallurl,
        get_string('markall_link', 'mod_multicheckin'), ['primary' => true]);
}

$backurl = new moodle_url('/mod/multicheckin/view.php', ['id' => $cm->id, 'session' => $session->id]);
$toolbar->add(nav_toolbar::GROUP_TEACHER, $backurl, get_string('back'));

echo $toolbar->render();

echo $OUTPUT->footer();
