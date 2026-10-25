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
 * Main view page of a multicheckin instance.
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
use mod_multicheckin\geo_manager;
use mod_multicheckin\leave_manager;
use mod_multicheckin\nav_toolbar;
use mod_multicheckin\photo_manager;
use mod_multicheckin\point_name;
use mod_multicheckin\qr_manager;
use mod_multicheckin\session_manager;

$id = required_param('id', PARAM_INT);
// Optional: which occurrence to show. Absent means "whichever one is current",
// which is what a student following the activity link expects.
$sessionparam = optional_param('session', 0, PARAM_INT);
// Optional: which point to position on. The reminder email links here with the
// point it is about, so the page can mark that row and the browser can scroll to
// it. This only changes what is highlighted - never what may be checked in - and
// a value belonging to another occurrence simply matches no row below.
$pointparam = optional_param('point', 0, PARAM_INT);

$cm = get_coursemodule_from_id('multicheckin', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/multicheckin:view', $context);

// The activity row is the configuration template; the sessions shown are its
// occurrences, or the template itself when no series was generated. Resolving
// through these two calls means the rest of this page never has to know which
// of the two an activity is.
$template = session_manager::get_template_for_cm($cm->id);
if (!$template) {
    throw new \moodle_exception('invalidcoursemodule');
}

$sessions = session_manager::list_sessions($template);
$session = session_manager::resolve_session($template, $sessionparam);
if (!$session) {
    // A hand-edited session parameter pointing outside this activity: fall back
    // to the default rather than failing, so a stale bookmark still works.
    $session = session_manager::default_session($template);
}

$PAGE->set_url('/mod/multicheckin/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($template->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// All check-ins go through checkin.php so that password / QR / self rules are
// enforced in exactly one place. This page is read-only.

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($template->name));

if ($template->intro) {
    echo $OUTPUT->box(format_module_intro('multicheckin', $template, $cm->id), 'generalbox', 'intro');
}

// Occurrence picker. Rendered only when a series exists: for a single-session
// activity a dropdown of one is noise, and it would suggest there is something
// to switch between when there is not.
if (count($sessions) > 1) {
    echo \mod_multicheckin\occurrence_helper::render_picker(
        $cm->id, $sessions, (int) $session->id, 'view.php');
    echo \mod_multicheckin\occurrence_helper::render_current_note($session);
}

// What this occurrence is for, in the teacher's own words. Shown to students as
// well as teachers: it is the field that answers "what is happening in this
// class", which the activity intro cannot do for the twentieth session.
echo \mod_multicheckin\occurrence_helper::render_description($session, $context);

// Timing summary.
$table = new html_table();
$table->head = [
    get_string('timestart', 'mod_multicheckin'),
    get_string('timeend', 'mod_multicheckin'),
    get_string('lategrace', 'mod_multicheckin'),
    get_string('earlygrace', 'mod_multicheckin'),
];
$table->data = [[
    userdate($session->timestart),
    userdate($session->timeend),
    format_time($session->lategrace),
    format_time($session->earlygrace),
]];
echo html_writer::table($table);

// Points list.
$points = session_manager::get_points((int) $session->id);
$now = time();

// Teacher view: QR codes for open points sit at the top centre, ABOVE the
// check-in table, so a projected screen reads code first and roll below.
echo qr_manager::teacher_qr_panel((int) $cm->id, $session, $points);

// Which points has the current user already hit?
$myhits = [];
foreach ($DB->get_records('multicheckin_log', ['sessionid' => $session->id, 'userid' => $USER->id]) as $log) {
    $myhits[(int) $log->pointid] = true;
}

// The current user's leave for this session, if any. Read once here because
// both the leave button below and the footer status need it.
$myleave = leave_manager::record((int) $session->id, (int) $USER->id);

$ptable = new html_table();
$ptable->head = [
    '#',
    get_string('pointname', 'mod_multicheckin'),
    get_string('pointwindow', 'mod_multicheckin'),
    get_string('required', 'mod_multicheckin'),
    get_string('status', 'mod_multicheckin'),
    get_string('mycheckin', 'mod_multicheckin'),
    get_string('action', 'mod_multicheckin'),
];
$ptable->data = [];

// Geofence settings that apply to this user (managers are exempt, so they just
// see the plain self check-in button).
$isgeofenced = (geo_manager::effective_mode($session, 'self') !== geo_manager::MODE_NONE)
    && !has_capability('mod/multicheckin:manage', $context);
$needsgps = $isgeofenced && geo_manager::mode_needs_gps($session->geomode);

// A photo occurrence changes what the student has to do, so the button says so
// and the way out is offered beside it. Teachers are left out of both: they
// take the roll in person, and a photograph of them proves nothing about
// anybody, which is why checkin.php exempts them as well.
$photostudent = photo_manager::is_required($session)
    && !has_capability('mod/multicheckin:manage', $context);
$checkinlabel = $photostudent
    ? get_string('checkin_now_photo', 'mod_multicheckin')
    : get_string('checkin_now', 'mod_multicheckin');

$selfcheckinurls = [];

foreach ($points as $p) {
    [$open, $close] = session_manager::point_window($session, $p);
    $isopen = ($now >= $open && $now <= $close);
    $ihit = !empty($myhits[(int) $p->id]);

    $isqronly = qr_manager::only_scan($session, $p);

    $action = '';
    if ($isopen && !$ihit && has_capability('mod/multicheckin:checkin', $context)) {
        if ($isqronly) {
            // The code on the teacher's screen is the only way in, so no button
            // is drawn. Said in words rather than left as an empty cell: an
            // empty cell reads as "nothing to do here yet", and a student would
            // keep looking for the button. checkin.php refuses the same two
            // routes, so this is the explanation and not the rule.
            $action = get_string('qronly_only_scan', 'mod_multicheckin');
        } else if (!empty($session->allowselfcheckin)) {
            $url = new moodle_url('/mod/multicheckin/checkin.php', [
                'id' => $cm->id,
                'session' => $session->id,
                'point' => $p->id,
                'method' => 'self',
                'sesskey' => sesskey(),
            ]);
            if ($needsgps) {
                // Handled by the GPS-aware JS button below.
                $selfcheckinurls[(int) $p->id] = $url->out(false);
                $action = html_writer::tag('button', $checkinlabel,
                    [
                        'type' => 'button',
                        'class' => 'btn btn-primary btn-sm multicheckin-geocheckin',
                        'data-target' => (int) $p->id,
                    ]);
            } else {
                $action = html_writer::link($url, $checkinlabel,
                    ['class' => 'btn btn-primary btn-sm']);
            }
        } else if (!empty($p->qrcode)) {
            $action = get_string('scan_qr_hint', 'mod_multicheckin');
        }

        // The honest way out, on the row it applies to. A camera that does not
        // work is not a reason to lose the attendance, and a student should not
        // have to discover the by-hand route by failing first.
        if ($photostudent) {
            $action .= ' ' . html_writer::link(new moodle_url('/mod/multicheckin/photorequest.php', [
                'id' => $cm->id,
                'session' => $session->id,
                'point' => $p->id,
            ]), get_string('photo_request_short', 'mod_multicheckin'), ['class' => 'small ms-1']);
        }
    }

    $statusbadge = $isopen
        ? html_writer::span(get_string('open', 'mod_multicheckin'), 'badge bg-success')
        : html_writer::span(get_string('closed', 'mod_multicheckin'), 'badge bg-secondary');

    $mybadge = $ihit
        ? html_writer::span(get_string('checked_in', 'mod_multicheckin'), 'badge bg-success')
        : html_writer::span('—', 'text-muted');

    // The row the reminder email sent the student to. Marked, not filtered: the
    // rest of the roll stays visible, because "which point is this about" is
    // answered better by a highlight than by hiding the others. The id is what
    // the #fragment in the emailed link scrolls to.
    $rowattrs = [];
    if ($pointparam > 0 && (int) $p->id === $pointparam) {
        $rowattrs = [
            'class' => 'table-warning',
            'id' => 'multicheckin-point-' . (int) $p->id,
        ];
    }

    $ptable->data[] = new html_table_row([
        $p->sortorder,
        // The rule is worth repeating to the teacher, who set it and may have
        // forgotten: the badge names the point that will not take a button.
        format_string(point_name::display($p))
            . ($isqronly && has_capability('mod/multicheckin:manage', $context)
                ? ' ' . html_writer::span(get_string('qronly_badge', 'mod_multicheckin'),
                    'badge bg-info text-dark')
                : ''),
        userdate($open, get_string('strftimetime')) . ' – ' . userdate($close, get_string('strftimetime')),
        $p->required ? get_string('yes') : get_string('no'),
        $statusbadge,
        $mybadge,
        $action,
    ], $rowattrs);
}
echo html_writer::table($ptable);

// A photo occurrence is announced before the student presses anything, and the
// sentence is an instruction rather than a description as of 2.0.23: the
// photograph a WebView camera produces is cropped, and nothing the page can do
// afterwards repairs it. The student has to be told which browser to open the
// page in BEFORE they scan, because the mistake is not correctable from inside
// the app - WeChat offers no way out to a real browser from a page.
if ($photostudent) {
    echo $OUTPUT->notification(get_string('photo_required_notice', 'mod_multicheckin'),
        \core\output\notification::NOTIFY_INFO, false);
}

// The leave entry point. It sits AFTER the check-in table rather than in it:
// the table is one row per point, and a leave is one decision for the whole
// session, so a column there would have to be repeated on every row. A student
// who already holds a record for this session cannot turn it into a leave, so
// the button is not offered; teachers set that status from the marking sheet
// instead, which is why they do not see it either.
$leavesurl = new moodle_url('/mod/multicheckin/leave.php', [
    'id' => $cm->id,
    'session' => $session->id,
]);
$canrequestleave = has_capability('mod/multicheckin:checkin', $context)
    && !has_capability('mod/multicheckin:manage', $context)
    && !$myhits
    && leave_manager::is_open($session);

// A leave the student already holds is a STATUS, so it stays beside the table
// it belongs to. The button that asks for one is an option of the page, so it
// lives in the toolbar below with the other options.
if ($canrequestleave) {
    if ($myleave) {
        echo html_writer::div(
            html_writer::span(get_string('status_leave', 'mod_multicheckin'), 'badge bg-secondary'),
            'mt-2');
    }

    // Said here rather than on the leave page, which the student has already
    // found: the scan route is the one door a leave deliberately does not have.
    echo html_writer::div(get_string('leave_scan_hint', 'mod_multicheckin'),
        'text-muted small mt-1');
}

// Explain the fence to students so a rejection is not a mystery.
if ($isgeofenced) {
    echo $OUTPUT->notification(
        get_string('geofence_notice_' . ($needsgps ? 'gps' : 'ip'), 'mod_multicheckin'),
        \core\output\notification::NOTIFY_WARNING, false);
}

// GPS-aware self check-in: acquire a position, then forward it to checkin.php.
if ($needsgps && $selfcheckinurls) {
    $jsdata = json_encode($selfcheckinurls);
    $jsstr = json_encode([
        'locating' => get_string('geo_locating', 'mod_multicheckin'),
        'denied' => get_string('geo_denied', 'mod_multicheckin'),
        'unsupported' => get_string('geo_unsupported', 'mod_multicheckin'),
    ]);
    echo html_writer::tag('script', "
(function() {
    var urls = $jsdata;
    var L = $jsstr;
    document.querySelectorAll('.multicheckin-geocheckin').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var point = btn.getAttribute('data-target');
            var base = urls[point];
            if (!base) { return; }
            if (!navigator.geolocation) {
                alert(L.unsupported);
                window.location.href = base;
                return;
            }
            btn.disabled = true;
            var original = btn.textContent;
            btn.textContent = L.locating;
            navigator.geolocation.getCurrentPosition(function(pos) {
                var c = pos.coords;
                var sep = base.indexOf('?') === -1 ? '?' : '&';
                window.location.href = base + sep
                    + 'geolat=' + encodeURIComponent(c.latitude)
                    + '&geolng=' + encodeURIComponent(c.longitude)
                    + '&geoacc=' + encodeURIComponent(c.accuracy || '');
            }, function() {
                alert(L.denied);
                btn.disabled = false;
                btn.textContent = original;
            }, {enableHighAccuracy: true, timeout: 15000, maximumAge: 0});
        });
    });
})();
", ['type' => 'text/javascript']);
}

// ---- The options toolbar ----
//
// Two groups, one card. The selected occurrence travels with every link, so the
// manage and report pages open on the session the teacher was just looking at
// instead of resetting to the default one.
//
// Entries are added, not printed: a page contributes only what it can actually
// offer, the helper renders the rest, and a group nobody filled in draws
// nothing at all rather than an empty box.
$toolbar = new nav_toolbar();

// What the student can do for themselves. The leave button used to sit under
// the table with the check-in buttons; it is an option of the page like any
// other, so it belongs with them.
if ($canrequestleave) {
    $toolbar->add(nav_toolbar::GROUP_STUDENT, $leavesurl,
        get_string('leave_request', 'mod_multicheckin'),
        $myleave ? ['badge' => get_string('status_leave', 'mod_multicheckin')] : []);
}

if (has_capability('mod/multicheckin:manage', $context)) {
    $manageurl = new moodle_url('/mod/multicheckin/manage.php',
        ['id' => $cm->id, 'session' => $session->id]);
    $toolbar->add(nav_toolbar::GROUP_TEACHER, $manageurl,
        get_string('managepoints', 'mod_multicheckin'));

    // Retitling or retiming the occurrence shown. Offered here as well as on
    // the series page because this is the page a teacher reaches from the
    // course, and being sent to a table of thirty rows to fix one title is a
    // detour for no reason.
    $editurl = new moodle_url('/mod/multicheckin/session_edit.php',
        ['id' => $cm->id, 'session' => $session->id, 'return' => 'view']);
    $toolbar->add(nav_toolbar::GROUP_TEACHER, $editurl,
        get_string('session_edit_link', 'mod_multicheckin'));

    // The codes on demand. The inline panel above only paints while a point is
    // open, so before the class starts there is nothing on the page to project;
    // this opens the same codes in a window of their own, at any hour. It is a
    // real button rather than a link - a popup has to be opened by a click -
    // which is why it goes in as markup.
    $toolbar->add_html(nav_toolbar::GROUP_TEACHER, qr_manager::qr_button((int) $cm->id, $session));

    // Marking is the action this page exists for, so it is the one primary
    // button in the group.
    $markallurl = new moodle_url('/mod/multicheckin/markall.php',
        ['id' => $cm->id, 'session' => $session->id]);
    $toolbar->add(nav_toolbar::GROUP_TEACHER, $markallurl,
        get_string('markall_link', 'mod_multicheckin'), ['primary' => true]);

    // By-hand verification requests. Counted over the whole activity rather
    // than the occurrence shown: a request raised in week 3 is still waiting in
    // week 4, and a badge that counted only today's date would hide exactly the
    // ones that have been forgotten.
    $pendingphotos = photo_manager::pending_count((int) $template->id);
    $photorequrl = new moodle_url('/mod/multicheckin/photorequests.php', ['id' => $cm->id]);
    $toolbar->add(nav_toolbar::GROUP_TEACHER, $photorequrl,
        get_string('photoreq_manage', 'mod_multicheckin'),
        $pendingphotos > 0 ? ['badge' => (string) $pendingphotos] : []);

    // Leave requests. The count rides on the link itself: a teacher who has to
    // open the page to find out there is nothing waiting will stop opening it.
    $pendingleaves = leave_manager::pending_count((int) $session->id);
    $leavesmanageurl = new moodle_url('/mod/multicheckin/leaves.php',
        ['id' => $cm->id, 'session' => $session->id]);
    $toolbar->add(nav_toolbar::GROUP_TEACHER, $leavesmanageurl,
        get_string('leaves', 'mod_multicheckin'),
        $pendingleaves > 0 ? ['badge' => (string) $pendingleaves] : []);

    // Off unless the teacher asked for it: the audit page is a diagnostic and
    // has no place in the everyday flow of taking attendance.
    if (!empty($session->showgeoaudit)) {
        $auditurl = new moodle_url('/mod/multicheckin/geolog.php',
            ['id' => $cm->id, 'session' => $session->id]);
        $toolbar->add(nav_toolbar::GROUP_TEACHER, $auditurl,
            get_string('audit_link', 'mod_multicheckin'));
    }
}

if (has_capability('mod/multicheckin:viewreports', $context)) {
    $reporturl = new moodle_url('/mod/multicheckin/report.php',
        ['id' => $cm->id, 'session' => $session->id]);
    $toolbar->add(nav_toolbar::GROUP_TEACHER, $reporturl, get_string('viewreport', 'mod_multicheckin'));

    if (count($sessions) > 1) {
        $seriesurl = new moodle_url('/mod/multicheckin/series.php', ['id' => $cm->id]);
        $toolbar->add(nav_toolbar::GROUP_TEACHER, $seriesurl,
            get_string('occurrence_manageall', 'mod_multicheckin'));
    }
}

// The classroom library is site-wide, so this link is offered on its own
// capability rather than being tied to managing this particular activity.
if (has_capability('mod/multicheckin:manageplaces', context_system::instance())) {
    $placesurl = new moodle_url('/mod/multicheckin/places.php');
    $toolbar->add(nav_toolbar::GROUP_TEACHER, $placesurl, get_string('place_manage', 'mod_multicheckin'));
}

echo $toolbar->render();

// Show my own latest status.
$myresult = $DB->get_record('multicheckin_result', [
    'sessionid' => $session->id,
    'userid' => $USER->id,
]);
if ($myresult) {
    echo $OUTPUT->heading(get_string('mystatus', 'mod_multicheckin'), 4);
    $label = checkin_engine::status_label($myresult->statuscode);
    $class = checkin_engine::status_class($myresult->statuscode);
    echo html_writer::span($label, $class);
}

echo $OUTPUT->footer();
