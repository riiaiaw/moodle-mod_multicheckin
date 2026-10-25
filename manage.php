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
 * Manage check-in points for a session.
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
require_once($CFG->libdir . '/formslib.php');

use mod_multicheckin\checkin_engine;
use mod_multicheckin\geo_manager;
use mod_multicheckin\point_name;
use mod_multicheckin\session_manager;

$id = required_param('id', PARAM_INT);
$sessionparam = optional_param('session', 0, PARAM_INT);

$cm = get_coursemodule_from_id('multicheckin', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/multicheckin:manage', $context);

// The activity row is the template; the page acts on one of its occurrences (or
// on the template itself when no series exists). resolve_session() rejects an
// id that belongs to another activity, so the 'session' parameter cannot be
// used to reach outside this course module.
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

$PAGE->set_url('/mod/multicheckin/manage.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($template->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Every action below must return to the page showing the same occurrence, so
// the redirect target carries the session through.
$pageurl = new moodle_url('/mod/multicheckin/manage.php',
    ['id' => $cm->id, 'session' => $session->id]);
$PAGE->set_url($pageurl);

$action = optional_param('action', '', PARAM_ALPHA);

// Handle point creation.
if ($action === 'addpoint' && confirm_sesskey()) {
    $count = $DB->count_records('multicheckin_point', ['sessionid' => $session->id]);
    $record = (object) [
        'sessionid' => (int) $session->id,
        'name' => get_string('point_new', 'mod_multicheckin', $count + 1),
        'sortorder' => $count + 1,
        'openoffset' => 0,
        'closeoffset' => 600,
        'role' => 'mid',
        'required' => 0,
        'password' => '',
        'qrcode' => 0,
        'qronly' => 0,
        'timemodified' => time(),
    ];
    $DB->insert_record('multicheckin_point', $record);
    redirect($PAGE->url, get_string('point_added', 'mod_multicheckin'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Handle point deletion.
if ($action === 'delpoint') {
    $pointid = required_param('pointid', PARAM_INT);
    require_sesskey();
    \mod_multicheckin\photo_manager::delete_for_point((int) $pointid);
    $DB->delete_records('multicheckin_log', ['pointid' => $pointid]);
    $DB->delete_records('multicheckin_point', ['id' => $pointid, 'sessionid' => $session->id]);
    redirect($PAGE->url, get_string('point_deleted', 'mod_multicheckin'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Handle recompute.
if ($action === 'recompute') {
    require_sesskey();
    require_capability('mod/multicheckin:recompute', $context);
    $count = checkin_engine::compute_session((int) $session->id);
    multicheckin_update_grades($session);
    redirect($PAGE->url, get_string('recomputed', 'mod_multicheckin', $count), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Handle reminder send (manual trigger for testing).
if ($action === 'notifytest') {
    require_sesskey();
    $count = \mod_multicheckin\notify_manager::send_due_reminders();
    redirect($PAGE->url, get_string('reminders_sent', 'mod_multicheckin', $count), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managepoints', 'mod_multicheckin'));

// Which occurrence is being edited, and a way to switch. Without this the page
// silently edits whichever session happens to be default, which is exactly the
// mistake a multi-occurrence activity invites.
if (count($sessions) > 1) {
    echo \mod_multicheckin\occurrence_helper::render_picker(
        $cm->id, $sessions, (int) $session->id, 'manage.php');
    $seriesurl = new moodle_url('/mod/multicheckin/series.php', ['id' => $cm->id]);
    echo html_writer::div(
        html_writer::link($seriesurl, get_string('occurrence_manageall', 'mod_multicheckin')),
        'text-muted small mb-3');
}

// What this occurrence is for. Kept above the point table, because the note is
// usually the thing that explains what the points mean ("lab: check in at the
// bench, check out at the door").
echo \mod_multicheckin\occurrence_helper::render_description($session, $context);

// Simple inline edit form for existing points.
$points = session_manager::get_points((int) $session->id);

$editform = new \mod_multicheckin\form\points_form(null, [
    'points' => $points,
    'cmid' => $cm->id,
]);

if ($data = $editform->get_data()) {
    foreach ($points as $p) {
        $key = 'p' . $p->id;
        $upd = (object) [
            'id' => $p->id,
            'name' => $data->{$key . '_name'},
            'openoffset' => (int) $data->{$key . '_openoffset'},
            'closeoffset' => (int) $data->{$key . '_closeoffset'},
            'role' => $data->{$key . '_role'},
            'required' => empty($data->{$key . '_required'}) ? 0 : 1,
            'qrcode' => empty($data->{$key . '_qrcode'}) ? 0 : 1,
            'qronly' => empty($data->{$key . '_qronly'}) ? 0 : 1,
            'notify' => empty($data->{$key . '_notify'}) ? 0 : 1,
            'password' => $data->{$key . '_password'},
            'timemodified' => time(),
        ];
        // Ensure a signing key exists whenever QR is switched on - and also
        // when the point is QR-only, because that point is served a code
        // whether or not its own "show QR code" box is ticked. Minting it here
        // keeps the first render from having to write the row mid-page.
        if (($upd->qrcode || $upd->qronly) && empty($p->qrcodekey)) {
            $upd->qrcodekey = bin2hex(random_bytes(16));
        }
        $DB->update_record('multicheckin_point', $upd);
    }
    // Recompute using the new windows so the report reflects the change immediately.
    checkin_engine::compute_session((int) $session->id);
    multicheckin_update_grades($session);
    redirect($PAGE->url, get_string('pointsupdated', 'mod_multicheckin'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$editform->display();

// Quick actions.
$actions = [];
$addurl = new moodle_url($PAGE->url, ['action' => 'addpoint', 'sesskey' => sesskey()]);
$actions[] = html_writer::link($addurl, get_string('addpoint', 'mod_multicheckin'), ['class' => 'btn btn-secondary']);
$markallurl = new moodle_url('/mod/multicheckin/markall.php',
    ['id' => $cm->id, 'session' => $session->id]);
$actions[] = html_writer::link($markallurl, get_string('markall_link', 'mod_multicheckin'), ['class' => 'btn btn-secondary']);
// The audit link and the slice of log below it are shown only when the teacher
// switched the audit on for this activity; the page itself is still reachable
// by direct URL. See the geofence section of the plugin README.
if (!empty($session->showgeoaudit)) {
    $geologurl = new moodle_url('/mod/multicheckin/geolog.php',
        ['id' => $cm->id, 'session' => $session->id]);
    $actions[] = html_writer::link($geologurl, get_string('audit_link', 'mod_multicheckin'), ['class' => 'btn btn-secondary']);
}
$recurl = new moodle_url($PAGE->url, ['action' => 'recompute', 'sesskey' => sesskey()]);
$actions[] = html_writer::link($recurl, get_string('recompute', 'mod_multicheckin'), ['class' => 'btn btn-secondary']);
$notifyurl = new moodle_url($PAGE->url, ['action' => 'notifytest', 'sesskey' => sesskey()]);
$actions[] = html_writer::link($notifyurl, get_string('sendremindersnow', 'mod_multicheckin'), ['class' => 'btn btn-secondary']);
echo html_writer::div(implode(' ', $actions), 'mb-3');

// Delete links per point.
$dtable = new html_table();
$dtable->head = ['#', get_string('pointname', 'mod_multicheckin'), get_string('action', 'mod_multicheckin')];
foreach ($points as $p) {
    $delurl = new moodle_url($PAGE->url, [
        'action' => 'delpoint',
        'pointid' => $p->id,
        'sesskey' => sesskey(),
    ]);
    $dtable->data[] = [
        $p->sortorder,
        format_string(point_name::display($p)),
        html_writer::link($delurl, get_string('delete'), ['class' => 'text-danger']),
    ];
}
echo html_writer::table($dtable);

// ---- Geofence audit log ----
// A short recent slice, shown when a fence is configured so the page stays
// short otherwise. The full view - with the configuration restated, the reasons
// counted, and the rows where the fence was bypassed or had nothing to compare
// against spelled out - lives on its own page.
if (!empty($session->showgeoaudit) && !empty($session->geomode) && $session->geomode !== 'none') {
    $geologs = $DB->get_records_sql(
        "SELECT g.*, u.firstname, u.lastname, mp.name AS pointname, mp.role AS pointrole
           FROM {multicheckin_geo_log} g
           JOIN {user} u ON u.id = g.userid
           JOIN {multicheckin_point} mp ON mp.id = g.pointid
          WHERE g.sessionid = :sessionid
       ORDER BY g.timecreated DESC",
        ['sessionid' => $session->id], 0, 100
    );

    if ($geologs) {
        echo $OUTPUT->heading(get_string('privacy_geolog', 'mod_multicheckin'), 4);
        $gtable = new html_table();
        $gtable->head = [
            get_string('user', 'mod_multicheckin'),
            get_string('pointname', 'mod_multicheckin'),
            get_string('geomode', 'mod_multicheckin'),
            get_string('status', 'mod_multicheckin'),
            get_string('ip', 'mod_multicheckin'),
            get_string('georadius', 'mod_multicheckin'),
        ];
        $gtable->data = [];
        foreach ($geologs as $g) {
            $verdict = $g->allowed
                ? html_writer::span(get_string('geofence_ok', 'mod_multicheckin'), 'badge bg-success')
                : html_writer::span(geo_manager::reason_string($g->reason ?? 'geofence_denied'), 'badge bg-danger');

            $distance = ($g->distance !== null && $g->distance !== '')
                ? round((float) $g->distance, 1) . ' m'
                : '—';

            $gtable->data[] = [
                fullname((object) ['firstname' => $g->firstname, 'lastname' => $g->lastname]),
                format_string(point_name::display_name((string) $g->pointname, (string) $g->pointrole)),
                $g->geomode,
                $verdict,
                s($g->ip ?? ''),
                $distance,
            ];
        }
        echo html_writer::table($gtable);
        echo html_writer::div(get_string('geolog_note', 'mod_multicheckin'), 'text-muted small');
    }
}

$backurl = new moodle_url('/mod/multicheckin/view.php',
    ['id' => $cm->id, 'session' => $session->id]);
echo $OUTPUT->single_button($backurl, get_string('back'), 'get');

echo $OUTPUT->footer();
