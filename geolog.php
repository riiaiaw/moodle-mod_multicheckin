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
 * Geofence audit: every check-in attempt the fence was asked about.
 *
 * Read-only by design. The question this page answers is "why did the fence do
 * nothing?", and the answer is almost always visible in the log rather than in
 * the fence: the attempt was exempted, or there was no circle to compare
 * against. Both are stated next to the row, and the current configuration is
 * restated at the top so the log is read against the settings that produced it.
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

use mod_multicheckin\geo_audit;
use mod_multicheckin\geo_manager;
use mod_multicheckin\point_name;
use mod_multicheckin\session_manager;

$id = required_param('id', PARAM_INT);
$sessionparam = optional_param('session', 0, PARAM_INT);
$page = optional_param('page', 0, PARAM_INT);
$resultfilter = optional_param('result', 'all', PARAM_ALPHA);
$userid = optional_param('userid', 0, PARAM_INT);

$cm = get_coursemodule_from_id('multicheckin', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
// The log holds where each student was, so it is restricted to people who may
// already see their attendance - not to everyone who can view the activity.
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

$pageurl = new moodle_url('/mod/multicheckin/geolog.php', [
    'id' => $cm->id,
    'session' => $session->id,
]);
$PAGE->set_url($pageurl);
$PAGE->set_title(format_string($template->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$config = geo_audit::config($session);
$options = ['result' => $resultfilter, 'userid' => $userid, 'page' => $page];

$fetched = geo_audit::fetch((int) $session->id, $options);
$summary = geo_audit::summary((int) $session->id, ['result' => 'all', 'userid' => $userid]);
$distances = geo_audit::distance_stats((int) $session->id);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('audit_heading', 'mod_multicheckin'));

if (count($sessions) > 1) {
    echo \mod_multicheckin\occurrence_helper::render_picker(
        $cm->id, $sessions, (int) $session->id, 'geolog.php');
}

// ---- The fence as it is configured now -----------------------------------
$ctable = new html_table();
$ctable->head = [
    get_string('geomode', 'mod_multicheckin'),
    get_string('audit_enforceqr', 'mod_multicheckin'),
    get_string('audit_centre', 'mod_multicheckin'),
    get_string('georadius', 'mod_multicheckin'),
    get_string('audit_ranges', 'mod_multicheckin'),
];
$ctable->data = [[
    $config['mode_label'],
    $config['mode'] === geo_manager::MODE_NONE
        ? '—'
        : get_string($config['enforceqr'] ? 'yes' : 'no'),
    $config['centre_set']
        ? format_float($config['lat'], 6) . ', ' . format_float($config['lng'], 6)
        : get_string('audit_centre_unset', 'mod_multicheckin'),
    $config['radius'] . ' m',
    $config['ranges']
        ? count($config['ranges'])
        : get_string('audit_ranges_unset', 'mod_multicheckin'),
]];
echo html_writer::table($ctable);

// The four ways a configured fence ends up judging nothing. Stated up front,
// because each of them produces a log full of "allowed" that looks like a
// working fence.
$notices = [];
if ($config['mode'] === geo_manager::MODE_NONE) {
    $notices[] = get_string('audit_notice_none', 'mod_multicheckin');
}
if ($config['needsgps'] && !$config['centre_set']) {
    $notices[] = get_string('audit_notice_centre', 'mod_multicheckin');
}
if ($config['needsip'] && !$config['ranges']) {
    $notices[] = get_string('audit_notice_ranges', 'mod_multicheckin');
}
if ($config['mode'] !== geo_manager::MODE_NONE && !$config['enforceqr']) {
    $notices[] = get_string('audit_notice_qr', 'mod_multicheckin');
}
foreach ($notices as $notice) {
    echo $OUTPUT->notification($notice, \core\output\notification::NOTIFY_WARNING, false);
}

// ---- Summary -------------------------------------------------------------
echo $OUTPUT->heading(get_string('audit_stats', 'mod_multicheckin'), 4);

$stable = new html_table();
$stable->head = [
    get_string('audit_total', 'mod_multicheckin'),
    get_string('audit_allowed', 'mod_multicheckin'),
    get_string('audit_rejected', 'mod_multicheckin'),
];
$stable->data = [[$summary['total'], $summary['allowed'], $summary['rejected']]];
echo html_writer::table($stable);

if ($summary['reasons']) {
    $rtable = new html_table();
    $rtable->head = [
        get_string('audit_col_reason', 'mod_multicheckin'),
        get_string('audit_col_verdict', 'mod_multicheckin'),
    ];
    foreach ($summary['reasons'] as $reason => $count) {
        $rtable->data[] = [
            $reason === 'unknown'
                ? get_string('audit_reason_unknown', 'mod_multicheckin')
                : geo_manager::reason_string((string) $reason),
            $count,
        ];
    }
    echo html_writer::table($rtable);
}

if ($distances['count']) {
    echo html_writer::div(
        get_string('audit_distance_summary', 'mod_multicheckin', (object) [
            'count' => $distances['count'],
            'min' => format_float($distances['min'], 1),
            'median' => format_float($distances['median'], 1),
            'mean' => format_float($distances['mean'], 1),
            'max' => format_float($distances['max'], 1),
        ]),
        'mb-3');
}

// ---- Filters -------------------------------------------------------------
$filters = [
    'all' => get_string('audit_filter_all', 'mod_multicheckin'),
    'allowed' => get_string('audit_filter_allowed', 'mod_multicheckin'),
    'rejected' => get_string('audit_filter_rejected', 'mod_multicheckin'),
];
$filterhtml = [];
foreach ($filters as $key => $label) {
    $url = new moodle_url($pageurl, ['result' => $key, 'userid' => $userid]);
    $filterhtml[] = $key === $resultfilter
        ? html_writer::span($label, 'badge bg-primary')
        : html_writer::link($url, $label);
}
echo html_writer::div(
    get_string('audit_filter', 'mod_multicheckin') . ' ' . implode(' ', $filterhtml),
    'mb-3');

if (!$fetched['rows']) {
    echo $OUTPUT->notification(get_string('audit_empty', 'mod_multicheckin'),
        \core\output\notification::NOTIFY_INFO, false);
} else {
    $mtable = new html_table();
    $mtable->head = [
        get_string('audit_col_time', 'mod_multicheckin'),
        get_string('user', 'mod_multicheckin'),
        get_string('pointname', 'mod_multicheckin'),
        get_string('audit_col_method', 'mod_multicheckin'),
        get_string('geomode', 'mod_multicheckin'),
        get_string('audit_col_verdict', 'mod_multicheckin'),
        get_string('audit_col_reason', 'mod_multicheckin'),
        get_string('ip', 'mod_multicheckin'),
        get_string('audit_col_lat', 'mod_multicheckin'),
        get_string('audit_col_lng', 'mod_multicheckin'),
        get_string('audit_col_acc', 'mod_multicheckin'),
        get_string('audit_col_note', 'mod_multicheckin'),
    ];
    $mtable->data = [];

    $methods = [
        'self' => get_string('audit_method_self', 'mod_multicheckin'),
        'qr' => get_string('audit_method_qr', 'mod_multicheckin'),
        'password' => get_string('audit_method_password', 'mod_multicheckin'),
    ];

    foreach ($fetched['rows'] as $row) {
        $verdict = $row->allowed
            ? html_writer::span(get_string('geofence_ok', 'mod_multicheckin'), 'badge bg-success')
            : html_writer::span(
                geo_manager::reason_string((string) ($row->reason ?? 'geofence_denied')),
                'badge bg-danger');

        $note = geo_audit::explain($row, $config, $session);

        $mtable->data[] = [
            userdate($row->timecreated),
            fullname($row),
            format_string(point_name::display_name((string) $row->pointname, (string) $row->pointrole)),
            $methods[(string) $row->method] ?? (string) $row->method,
            (string) $row->geomode,
            $verdict,
            (string) ($row->reason ?? ''),
            s((string) ($row->ip ?? '')),
            $row->latitude === null ? '—' : format_float((float) $row->latitude, 6),
            $row->longitude === null ? '—' : format_float((float) $row->longitude, 6),
            $row->accuracy === null ? '—' : format_float((float) $row->accuracy, 1),
            $note === null
                ? html_writer::span('—', 'text-muted')
                : html_writer::span(get_string($note, 'mod_multicheckin'), 'badge bg-warning text-dark'),
        ];
    }

    echo html_writer::table($mtable);

    if ($fetched['pages'] > 1) {
        $pagelinks = [];
        for ($i = 0; $i < $fetched['pages']; $i++) {
            $url = new moodle_url($pageurl, [
                'result' => $resultfilter,
                'userid' => $userid,
                'page' => $i,
            ]);
            $label = ($i + 1);
            $pagelinks[] = ($i === $fetched['page'])
                ? html_writer::span($label, 'badge bg-primary')
                : html_writer::link($url, $label);
        }
        echo html_writer::div(
            get_string('audit_pages', 'mod_multicheckin', (object) [
                'pages' => $fetched['pages'],
                'total' => $fetched['total'],
            ]) . ' ' . implode(' ', $pagelinks),
            'mt-2 text-muted small');
    }
}

echo html_writer::div(get_string('audit_note', 'mod_multicheckin'), 'text-muted small mt-3');

$backurl = new moodle_url('/mod/multicheckin/view.php',
    ['id' => $cm->id, 'session' => $session->id]);
echo html_writer::div($OUTPUT->single_button($backurl, get_string('back'), 'get'), 'mt-3');

echo $OUTPUT->footer();
