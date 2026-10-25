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
 * Whole-class marking sheet for one session.
 *
 * One row per student, one cell per check-in point: tick what happened, adjust
 * a time where it matters, save once. Per-student editing lives in mark.php;
 * this page exists because taking the roll is a class-sized job, and a teacher
 * doing it one page at a time would be fighting the interface.
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
use mod_multicheckin\nav_toolbar;
use mod_multicheckin\photo_manager;
use mod_multicheckin\point_name;
use mod_multicheckin\session_manager;

/**
 * How many cells (students x points) one page of the sheet may render.
 *
 * Every cell posts two fields - the tick box and the time - so a page is two
 * fields wide per cell. PHP silently keeps only the first max_input_vars fields
 * of a request, and a truncated sheet would look like a teacher who unticked
 * nothing: the tail of the class would simply not arrive. Staying well under the
 * limit is what makes a partial submission impossible rather than mysterious.
 */
define('MULTICHECKIN_MARKALL_CELLS', 200);

$id = required_param('id', PARAM_INT);
$sessionparam = optional_param('session', 0, PARAM_INT);
$page = optional_param('page', 0, PARAM_INT);
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

$sessions = session_manager::list_sessions($template);
$points = session_manager::get_points((int) $session->id);

// Same enrolment rule as the report, so the sheet lists exactly the people the
// report does - which is now the activity's audience, not everyone who may
// check in. Sorted in PHP: the sheet is read top to bottom, and a roster that
// reshuffles between visits is unusable for taking the roll.
$users = \mod_multicheckin\audience_manager::participants($session, $context);
usort($users, function($a, $b) {
    $left = \core_text::strtolower($a->lastname . ' ' . $a->firstname);
    $right = \core_text::strtolower($b->lastname . ' ' . $b->firstname);
    return $left <=> $right;
});

$pageurl = new moodle_url('/mod/multicheckin/markall.php', [
    'id' => $cm->id,
    'session' => $session->id,
]);
$reporturl = new moodle_url('/mod/multicheckin/report.php', [
    'id' => $cm->id,
    'session' => $session->id,
]);

$PAGE->set_url(new moodle_url($pageurl, ['page' => $page]));
$PAGE->set_title(format_string($template->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// ---- Work out the page slice ---------------------------------------------
$pointcount = max(1, count($points));
$cellcount = count($users) * $pointcount;
$perpage = max(1, (int) floor(MULTICHECKIN_MARKALL_CELLS / $pointcount));
$pages = $cellcount > MULTICHECKIN_MARKALL_CELLS
    ? (int) ceil(count($users) / $perpage)
    : 1;

$page = max(0, min($page, $pages - 1));
$pageusers = $pages > 1 ? array_slice($users, $page * $perpage, $perpage) : $users;

// ---- Handle a save -------------------------------------------------------
if ($action === 'save' && confirm_sesskey()) {
    $ticks = optional_param_array('m', [], PARAM_INT);
    $times = optional_param_array('t', [], PARAM_RAW_TRIMMED);
    $leaveboxes = optional_param_array('lv', [], PARAM_INT);
    // The marker records that the leave box was actually ON this page. Without
    // it, "unticked" and "belongs to another page of the sheet" are the same
    // absent key, and a paged save would clear leaves it never showed.
    $leavemarks = optional_param_array('lvx', [], PARAM_INT);

    $totals = ['added' => 0, 'updated' => 0, 'removed' => 0, 'unchanged' => 0, 'errors' => []];
    $expected = 0;
    $leavegranted = 0;
    $leavecleared = 0;

    foreach ($pageusers as $user) {
        $submitted = [];
        foreach ($points as $point) {
            $key = $point->id . '_' . $user->id;
            $expected++;
            // The time field doubles as the record that this cell was on the
            // page. Reading the tick box alone cannot tell "unticked" from
            // "never rendered", and confusing the two would delete records.
            if (!array_key_exists($key, $times)) {
                continue;
            }
            $submitted[(int) $point->id] = [
                'checked' => !empty($ticks[$key]),
                'clock' => (string) $times[$key],
            ];
        }

        // Leave is session-wide, so it is handled whether or not any point was
        // posted: the two are independent answers to different questions.
        if (array_key_exists((int) $user->id, $leavemarks)) {
            $wantsleave = !empty($leaveboxes[(int) $user->id]);
            $hadleave = leave_manager::record((int) $session->id, (int) $user->id) !== null;

            if ($wantsleave && !$hadleave) {
                leave_manager::save($session, (int) $user->id, '',
                    leave_manager::ORIGIN_TEACHER, null, (int) $USER->id);
                $leavegranted++;
            } else if (!$wantsleave && $hadleave) {
                leave_manager::remove($session, (int) $user->id);
                $leavecleared++;
            }
        }

        if (!$submitted) {
            continue;
        }

        $result = mark_manager::apply_marks($session, $points, (int) $user->id, $submitted);
        foreach (['added', 'updated', 'removed', 'unchanged'] as $key) {
            $totals[$key] += $result[$key];
        }
        $totals['errors'] += $result['errors'];
    }

    mark_manager::resync($session);

    $message = mark_manager::summarise($totals);
    if ($leavegranted) {
        $message .= ' ' . get_string('leave_granted', 'mod_multicheckin', $leavegranted);
    }
    if ($leavecleared) {
        $message .= ' ' . get_string('leave_cleared', 'mod_multicheckin', $leavecleared);
    }
    $type = \core\output\notification::NOTIFY_SUCCESS;
    if (count($times) < $expected) {
        // PHP dropped part of the request. Say so plainly: the teacher has to
        // know that what they see as saved may not have been received.
        $message .= ' ' . get_string('marks_truncated', 'mod_multicheckin',
            (object) ['got' => count($times), 'want' => $expected]);
        $type = \core\output\notification::NOTIFY_WARNING;
    } else if ($totals['errors']) {
        $message .= ' ' . get_string('marks_some_rejected', 'mod_multicheckin',
            count($totals['errors']));
        $type = \core\output\notification::NOTIFY_WARNING;
    }

    redirect($PAGE->url, $message, null, $type);
}

// ---- Fill in what is missing --------------------------------------------
// The "the code never went up and everyone was here" case. Additive only: the
// times of students who did check in are left exactly as they are.
if ($action === 'fillmissing' && confirm_sesskey()) {
    $userids = array_map(fn($user) => (int) $user->id, $pageusers);
    $created = mark_manager::fill_missing($session, $points, $userids);
    mark_manager::resync($session);

    redirect($PAGE->url, get_string('marks_filled', 'mod_multicheckin', $created), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('marks_all_heading', 'mod_multicheckin'));

if (count($sessions) > 1) {
    echo \mod_multicheckin\occurrence_helper::render_picker(
        $cm->id, $sessions, (int) $session->id, 'markall.php');
}

if (!$points) {
    echo $OUTPUT->notification(get_string('nopoints', 'mod_multicheckin'),
        \core\output\notification::NOTIFY_WARNING, false);
    echo html_writer::div($OUTPUT->single_button($reporturl, get_string('back'), 'get'), 'mt-3');
    echo $OUTPUT->footer();
    exit;
}

if (!$users) {
    echo $OUTPUT->notification(get_string('marks_nostudents', 'mod_multicheckin'),
        \core\output\notification::NOTIFY_INFO, false);
    echo html_writer::div($OUTPUT->single_button($reporturl, get_string('back'), 'get'), 'mt-3');
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::div(get_string('marks_all_intro', 'mod_multicheckin'), 'text-muted mb-3');

$formurl = new moodle_url($PAGE->url);
$form = html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $formurl->out(false),
    'id' => 'multicheckin-markall',
]);
$form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
$form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);

$table = new html_table();
$table->attributes['class'] = 'generaltable multicheckin-markall';
$table->head = [get_string('user', 'mod_multicheckin')];
foreach ($points as $point) {
    [$open, $close] = session_manager::point_window($session, $point);
    $table->head[] = html_writer::div(format_string(point_name::display($point)))
        . html_writer::div(
            userdate($open, get_string('strftimetime')) . ' – ' . userdate($close, get_string('strftimetime')),
            'text-muted small');
}
$table->head[] = get_string('leave', 'mod_multicheckin');
$table->head[] = get_string('action', 'mod_multicheckin');

// One query for the whole page rather than one per cell.
$grouped = mark_manager::session_logs($session, array_map(fn($user) => (int) $user->id, $pageusers));
$leaves = leave_manager::for_session((int) $session->id);

// The photographs, also in one sweep. Read whether or not this occurrence
// still demands one: a session whose switch has since been turned off still
// holds the pictures that were taken while it was on, and a teacher reviewing
// a class needs to see them either way.
$photos = photo_manager::for_session_users($session, array_map(fn($user) => (int) $user->id, $pageusers));

foreach ($pageusers as $user) {
    $logs = $grouped[(int) $user->id] ?? [];
    $row = [$OUTPUT->user_picture($user, ['size' => 24, 'link' => false]) . fullname($user)];

    foreach ($points as $point) {
        $rowshere = $logs[(int) $point->id] ?? [];
        $state = mark_manager::state_from_logs($rowshere);
        $key = $point->id . '_' . $user->id;

        $cell = html_writer::checkbox('m[' . $key . ']', 1, $state['checked'], '',
            ['class' => 'form-check-input me-1']);
        $cell .= html_writer::empty_tag('input', [
            'type' => 'time',
            'name' => 't[' . $key . ']',
            'value' => $state['checked'] ? $state['clock'] : mark_manager::default_clock($session, $point),
            'class' => 'form-control form-control-sm d-inline-block multicheckin-markall-time',
            'style' => 'width: 6.5rem;',
        ]);
        if (mark_manager::state_is_manual($state)) {
            $cell .= ' ' . html_writer::span(get_string('mark_manual', 'mod_multicheckin'),
                'badge bg-info text-dark');
        }

        // The evidence behind the tick, one click away. Shown from the file
        // area rather than from the state, so a photograph taken for a row the
        // teacher has since cleared is still reachable - it is a record of what
        // happened, and clearing a tick does not unhappen it.
        $photo = $photos[(int) $user->id][(int) $point->id] ?? null;
        if ($photo) {
            $cell .= ' ' . photo_manager::link($photo,
                html_writer::tag('i', '', ['class' => 'fa fa-camera', 'aria-hidden' => 'true']));
        }

        $row[] = $cell;
    }

    // A leave is one answer for the whole session, so it gets one column of
    // its own rather than a tick per point. The hidden marker travels with the
    // box so the save handler can tell "cleared" from "not on this page".
    $leave = $leaves[(int) $user->id] ?? null;
    $leavecell = html_writer::empty_tag('input', [
        'type' => 'hidden',
        'name' => 'lvx[' . $user->id . ']',
        'value' => 1,
    ]);
    $leavecell .= html_writer::checkbox('lv[' . $user->id . ']', 1, $leave !== null, '',
        ['class' => 'form-check-input me-1']);
    if ($leave && empty($leave->reviewed)) {
        $leavecell .= html_writer::span(get_string('leave_pending', 'mod_multicheckin'),
            'badge bg-warning text-dark');
    }
    $row[] = $leavecell;

    $editurl = new moodle_url('/mod/multicheckin/mark.php', [
        'id' => $cm->id,
        'session' => $session->id,
        'userid' => $user->id,
    ]);
    $row[] = html_writer::link($editurl, get_string('marks_edit', 'mod_multicheckin'));

    $table->data[] = $row;
}

$form .= html_writer::table($table);
$form .= html_writer::empty_tag('input', [
    'type' => 'submit',
    'class' => 'btn btn-primary',
    'value' => get_string('marks_saveall', 'mod_multicheckin'),
]);
$form .= html_writer::end_tag('form');

echo $form;

// Paging is normally invisible: a class that fits on one screen is one page.
if ($pages > 1) {
    $links = [];
    for ($i = 0; $i < $pages; $i++) {
        $url = new moodle_url($pageurl, ['page' => $i]);
        $label = ($i + 1);
        $links[] = ($i === $page)
            ? html_writer::span($label, 'badge bg-primary')
            : html_writer::link($url, $label);
    }
    echo html_writer::div(
        get_string('marks_pages', 'mod_multicheckin', (object) [
            'pages' => $pages,
            'perpage' => $perpage,
            'total' => count($users),
        ]) . ' ' . implode(' ', $links),
        'mt-2 text-muted small');
}

// ---- The options toolbar ----
//
// The same helper the report and the activity page use. Filling the blanks is
// offered first because it is the action this page is usually opened for once
// the class has finished, and reading the result is what a teacher does next.
$toolbar = new nav_toolbar();

$fillurl = new moodle_url($PAGE->url, ['action' => 'fillmissing', 'sesskey' => sesskey()]);
$toolbar->add_html(nav_toolbar::GROUP_TEACHER, html_writer::link($fillurl,
    get_string('marks_fillmissing', 'mod_multicheckin'), [
        'class' => 'btn btn-outline-secondary',
        'onclick' => 'return confirm(' . json_encode(
            get_string('marks_fillmissing_confirm', 'mod_multicheckin', count($pageusers))) . ');',
    ]));

$toolbar->add(nav_toolbar::GROUP_TEACHER, $reporturl, get_string('viewreport', 'mod_multicheckin'));

echo $toolbar->render();

echo html_writer::div(get_string('marks_fillmissing_note', 'mod_multicheckin'), 'text-muted small mt-2');

echo $OUTPUT->footer();
