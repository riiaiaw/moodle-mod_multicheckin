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
 * Batch management of an activity's attendance occurrences.
 *
 * One activity spanning a term can hold twenty or more occurrences; editing or
 * deleting them one at a time is the thing this page exists to avoid. It lists
 * them all with their state and figures, and offers the two whole-series
 * actions: top up from the repeat rule, and clear.
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
use mod_multicheckin\description_editor;
use mod_multicheckin\occurrence_editor;
use mod_multicheckin\occurrence_helper;
use mod_multicheckin\session_manager;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$sessionparam = optional_param('session', 0, PARAM_INT);

$cm = get_coursemodule_from_id('multicheckin', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/multicheckin:manage', $context);

$template = session_manager::get_template_for_cm($cm->id);
if (!$template) {
    throw new \moodle_exception('invalidcoursemodule');
}

$PAGE->set_url('/mod/multicheckin/series.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($template->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$backurl = new moodle_url('/mod/multicheckin/view.php', ['id' => $cm->id]);

// ---- Whole-series actions ----------------------------------------------
//
// Both are POST-shaped in effect (they change a lot of rows at once) and both
// require a sesskey. The clear action is additionally guarded by a browser
// confirmation, because it discards collected attendance and there is no undo.

if ($action === 'regenerate') {
    require_sesskey();

    // Re-uses the rule saved on the activity itself, so the button has nothing
    // to ask: it simply brings the series up to what the rule implies today.
    $spec = session_manager::spec_from_data($template);
    $created = 0;
    if ($spec !== null) {
        $created = session_manager::generate_series((int) $template->id, $spec, null);
    }

    redirect($PAGE->url, get_string('series_regenerated', 'mod_multicheckin', $created), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'clear') {
    require_sesskey();

    $summary = session_manager::series_data_summary((int) $template->id);
    $removed = session_manager::delete_series((int) $template->id);

    redirect($PAGE->url, get_string('series_cleared', 'mod_multicheckin', $removed), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'deleteone') {
    require_sesskey();

    $target = session_manager::resolve_session($template, $sessionparam);
    if (!$target || $target->id == $template->id) {
        // resolve_session returns the template when nothing matches, and the
        // template must never be deleted through this page: it is the activity.
        redirect($PAGE->url, get_string('series_is_template', 'mod_multicheckin'), null,
            \core\output\notification::NOTIFY_ERROR);
    }

    $DB->delete_records('multicheckin_geo_log', ['sessionid' => $target->id]);
    $DB->delete_records('multicheckin_notify', ['sessionid' => $target->id]);
    // Photographs first: they are filed under the log row ids, so deleting the
    // rows first would leave pictures nothing could ever find again.
    \mod_multicheckin\photo_manager::delete_all_for_session((int) $target->id);
    $DB->delete_records('multicheckin_log', ['sessionid' => $target->id]);
    $DB->delete_records('multicheckin_result', ['sessionid' => $target->id]);
    \mod_multicheckin\leave_manager::delete_all_for_session((int) $target->id);
    \mod_multicheckin\description_editor::delete_files((int) $target->id);
    $DB->delete_records('multicheckin_point', ['sessionid' => $target->id]);
    $DB->delete_records('multicheckin', ['id' => $target->id]);

    session_manager::renumber_occurrences((int) $template->id);
    multicheckin_update_grades($template);

    redirect($PAGE->url, get_string('series_deleted_one', 'mod_multicheckin'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

// ---- Rows, figures, and the batch save ---------------------------------
//
// The table is also the edit form, so its data has to exist before anything is
// rendered: a save that is refused has to come back with every row the teacher
// typed, not with the table rebuilt from the database.

$sessions = session_manager::get_occurrences((int) $template->id);
$summary = session_manager::series_data_summary((int) $template->id);

// Per-occurrence figures for the table, in one query rather than one per row.
$stats = [];
if ($sessions) {
    list($insql, $params) = $DB->get_in_or_equal(array_keys($sessions), SQL_PARAMS_NAMED, 's');
    $counts = $DB->get_records_sql(
        "SELECT sessionid, COUNT(*) AS checkins
           FROM {multicheckin_log}
          WHERE sessionid {$insql}
       GROUP BY sessionid",
        $params
    );
    foreach ($counts as $row) {
        $stats[(int) $row->sessionid]['checkins'] = (int) $row->checkins;
    }

    $pointcounts = $DB->get_records_sql(
        "SELECT sessionid, COUNT(*) AS points
           FROM {multicheckin_point}
          WHERE sessionid {$insql}
       GROUP BY sessionid",
        $params
    );
    foreach ($pointcounts as $row) {
        $sid = (int) $row->sessionid;
        $stats[$sid] = ($stats[$sid] ?? []) + ['points' => (int) $row->points];
    }
}

// Values to redisplay and rows to mark up when a save was refused.
$posted = [];
$rowerrors = [];
$pending = [];

if ($action === 'savebatch' && data_submitted()) {
    require_sesskey();

    // Read as flat arrays keyed by occurrence id. The row count is taken from
    // the occurrences this activity actually has, so a row added to the POST by
    // hand is simply never looked at.
    //
    // The note is deliberately not among them. Since v2.0.0 the table shows a
    // preview and a button instead of an editor, and the note is written by the
    // window that button opens. Reading a field this form no longer posts would
    // hand save_batch an empty string for every row, and an empty string is a
    // note that was deleted.
    $names = optional_param_array('name', [], PARAM_TEXT);
    $dates = optional_param_array('date', [], PARAM_TEXT);
    $clocks = optional_param_array('clock', [], PARAM_TEXT);
    $enddates = optional_param_array('enddate', [], PARAM_TEXT);
    $endclocks = optional_param_array('endclock', [], PARAM_TEXT);
    // A checkbox posts nothing when it is unticked, so this array only holds
    // the rows the teacher switched ON. It is turned into an explicit 0 for
    // every other row below, and only for rows this activity actually has: a
    // missing key must mean "off", never "leave whatever was there", or simply
    // opening the page and saving would clear the column.
    $photorequired = optional_param_array('photorequired', [], PARAM_INT);
    $ack = optional_param('recalcack', 0, PARAM_INT) > 0;

    $rows = [];
    $checkins = [];
    foreach ($sessions as $sid => $ignored) {
        $sid = (int) $sid;
        $rows[$sid] = [
            'name' => (string) ($names[$sid] ?? ''),
            'date' => (string) ($dates[$sid] ?? ''),
            'clock' => (string) ($clocks[$sid] ?? ''),
            'enddate' => (string) ($enddates[$sid] ?? ''),
            'endclock' => (string) ($endclocks[$sid] ?? ''),
            'photorequired' => !empty($photorequired[$sid]) ? 1 : 0,
        ];
        $checkins[$sid] = (int) ($stats[$sid]['checkins'] ?? 0);
    }

    $result = occurrence_editor::save_batch($sessions, $rows, $checkins, $ack);

    if ($result['saved']) {
        redirect($PAGE->url, get_string('series_saved', 'mod_multicheckin', (object) [
            'sessions' => $result['sessions'],
            'judged' => $result['judged'],
        ]), null, \core\output\notification::NOTIFY_SUCCESS);
    }

    $posted = $rows;
    $rowerrors = $result['errors'];
    $pending = $result['pending'];
}

// ---- Page --------------------------------------------------------------

// The note column holds a preview and a button rather than an editor: one
// editor per row would make this page unusable at a term's length. The module
// that opens the one editor on demand is declared before the table is drawn.
description_editor::require_js();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('series_manage', 'mod_multicheckin'));

// Restore the class link to a full URL for the heading text.
$target = session_manager::resolve_session($template, $sessionparam);

if (!$sessions) {
    echo $OUTPUT->notification(get_string('series_none_yet', 'mod_multicheckin'),
        \core\output\notification::NOTIFY_INFO, false);
} else {
    echo html_writer::div(get_string('series_manage_desc', 'mod_multicheckin', [
        'total' => $summary['total'],
        'withdata' => $summary['withdata'],
    ]), 'mb-3');

    // What a refused save has to say, before the teacher scrolls back into the
    // table to find out which row it was about.
    if ($rowerrors) {
        echo $OUTPUT->notification(get_string('series_save_errors', 'mod_multicheckin'),
            \core\output\notification::NOTIFY_ERROR, false);
    }
    if ($pending) {
        echo $OUTPUT->notification(
            get_string('series_recalc_needed', 'mod_multicheckin', count($pending)),
            \core\output\notification::NOTIFY_WARNING, false);
    }

    // The table is the form. One submit covers the whole page: retitling a
    // term's occurrences one dialog at a time is the chore this replaces.
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $PAGE->url,
        'id' => 'multicheckin-batch',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'savebatch']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);

    echo occurrence_helper::render_table((int) $cm->id, $sessions, $stats,
        (int) ($target->id ?? 0), $posted, $rowerrors);

    // Shown only after a save has been refused for want of it. Rendering the
    // box from the start would let a teacher tick a confirmation about a
    // consequence nobody has described to them yet.
    if ($pending) {
        echo html_writer::div(
            html_writer::empty_tag('input', [
                'type' => 'checkbox',
                'name' => 'recalcack',
                'id' => 'multicheckin-recalcack',
                'value' => 1,
                'class' => 'form-check-input',
            ])
            . html_writer::tag('label', get_string('series_recalc_ack', 'mod_multicheckin'),
                ['for' => 'multicheckin-recalcack', 'class' => 'form-check-label']),
            'form-check mb-3');
    }

    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-primary',
        'value' => get_string('series_savebatch', 'mod_multicheckin'),
    ]);

    echo html_writer::end_tag('form');
    echo html_writer::div(get_string('series_savebatch_desc', 'mod_multicheckin'),
        'text-muted small mt-1');
}

// Whole-series actions.
$actions = [];

$regenurl = new moodle_url($PAGE->url, ['action' => 'regenerate', 'sesskey' => sesskey()]);
$actions[] = html_writer::link($regenurl, get_string('series_regenerate', 'mod_multicheckin'),
    ['class' => 'btn btn-secondary']);

if ($sessions) {
    $clearurl = new moodle_url($PAGE->url, ['action' => 'clear', 'sesskey' => sesskey()]);
    $actions[] = html_writer::link($clearurl, get_string('series_clear', 'mod_multicheckin'), [
        'class' => 'btn btn-outline-danger',
        'data-multicheckin-confirm' => get_string('series_clear_confirm', 'mod_multicheckin', $summary['total']),
    ]);
}
echo html_writer::div(implode(' ', $actions), 'mb-3');

echo html_writer::div(get_string('series_regenerate_desc', 'mod_multicheckin'), 'text-muted small mb-1');
if ($sessions) {
    echo html_writer::div(get_string('series_clear_desc', 'mod_multicheckin'), 'text-muted small mb-3');
}

// Confirmation for the destructive links, applied by the shared AMD module so
// it works from any page that renders one of these buttons.
global $PAGE;
$PAGE->requires->js_call_amd('mod_multicheckin/confirm', 'init');

echo $OUTPUT->single_button($backurl, get_string('back'), 'get');

echo $OUTPUT->footer();
