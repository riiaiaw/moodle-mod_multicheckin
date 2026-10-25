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
 * The queue of by-hand verification requests.
 *
 * Deliberately a page of its own rather than a column on the roster. A request
 * is raised when a camera did not work, which is not correlated with any column
 * of any table, and a teacher working through them wants the ones that are
 * still waiting at the top and nothing else in the way.
 *
 * The queue spans the WHOLE activity, not the occurrence currently selected:
 * "somebody could not photograph themselves in week 3" is a thing a teacher
 * needs to see in week 4, and a per-session view would hide it exactly then.
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

use mod_multicheckin\occurrence_helper;
use mod_multicheckin\photo_manager;
use mod_multicheckin\point_name;
use mod_multicheckin\session_manager;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$requestid = optional_param('request', 0, PARAM_INT);
$showall = optional_param('all', 0, PARAM_INT) > 0;

$cm = get_coursemodule_from_id('multicheckin', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/multicheckin:manage', $context);

$template = session_manager::get_template_for_cm($cm->id);
if (!$template) {
    throw new \moodle_exception('invalidcoursemodule');
}

$PAGE->set_url('/mod/multicheckin/photorequests.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($template->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

/**
 * One request row, but only when it belongs to this activity.
 *
 * The id in the URL is a number anybody can edit, so it is resolved through the
 * activity's own template before it is acted on. Without this a teacher could
 * decide a request raised in another course's attendance - and the approval
 * would record a check-in there.
 *
 * @param \stdClass $template Activity template row.
 * @param int $requestid Request row id.
 * @return \stdClass|null Request row, or null when it is not ours.
 */
function multicheckin_photoreq_owned(\stdClass $template, int $requestid): ?\stdClass {
    global $DB;

    $row = $DB->get_record('multicheckin_photoreq', ['id' => $requestid]);
    if (!$row) {
        return null;
    }

    $session = session_manager::resolve_session($template, (int) $row->sessionid);
    if (!$session || (int) $session->id !== (int) $row->sessionid) {
        return null;
    }

    return $row;
}

$listurl = new moodle_url('/mod/multicheckin/photorequests.php', ['id' => $cm->id]);

if ($action === 'approve' || $action === 'refuse') {
    require_sesskey();

    $row = multicheckin_photoreq_owned($template, $requestid);
    if (!$row) {
        redirect($listurl, get_string('photoreq_notfound', 'mod_multicheckin'), null,
            \core\output\notification::NOTIFY_ERROR);
    }

    if ($action === 'approve') {
        // The decision and the check-in are one action: see approve(). The
        // gradebook is pushed afterwards, because the new log row changes the
        // activity's aggregate grade.
        photo_manager::approve($requestid, (int) $USER->id);
        multicheckin_update_grades($template);
        $message = get_string('photoreq_approved_ok', 'mod_multicheckin');
    } else {
        photo_manager::decide($requestid, photo_manager::STATUS_REFUSED, (int) $USER->id);
        $message = get_string('photoreq_refused_ok', 'mod_multicheckin');
    }

    redirect(new moodle_url($listurl, $showall ? ['all' => 1] : []), $message, null,
        \core\output\notification::NOTIFY_SUCCESS);
}

$requests = photo_manager::for_template((int) $template->id, !$showall);

// Everything the table needs, in a handful of queries rather than one per row.
$sessions = [(int) $template->id => $template];
foreach (session_manager::get_occurrences((int) $template->id) as $occurrence) {
    $sessions[(int) $occurrence->id] = $occurrence;
}

$pointids = [];
$userids = [];
foreach ($requests as $request) {
    $pointids[(int) $request->pointid] = true;
    $userids[(int) $request->userid] = true;
    if ((int) $request->reviewedby) {
        $userids[(int) $request->reviewedby] = true;
    }
}

$points = $pointids
    ? $DB->get_records_list('multicheckin_point', 'id', array_keys($pointids), '', 'id,name')
    : [];
// Whole user rows: which columns make up a name is a site setting, so naming
// them here by hand is how a plugin ends up showing the wrong one.
$users = $userids ? $DB->get_records_list('user', 'id', array_keys($userids)) : [];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('photoreq_manage', 'mod_multicheckin'));

$pending = photo_manager::pending_count((int) $template->id);
echo html_writer::div(get_string('photoreq_manage_intro', 'mod_multicheckin'), 'text-muted mb-3');

$toggletext = $showall
    ? get_string('photoreq_showpending', 'mod_multicheckin')
    : get_string('photoreq_showall', 'mod_multicheckin');
$toggleurl = new moodle_url($listurl, $showall ? [] : ['all' => 1]);
echo html_writer::div(
    html_writer::link($toggleurl, $toggletext, ['class' => 'btn btn-outline-secondary btn-sm'])
    . ' ' . html_writer::span(get_string('photoreq_waiting', 'mod_multicheckin') . ': ' . $pending,
        'badge bg-warning text-dark align-middle ms-2'),
    'mb-3');

if (!$requests) {
    echo $OUTPUT->notification(get_string('photoreq_none', 'mod_multicheckin'),
        \core\output\notification::NOTIFY_INFO, false);
} else {
    $table = new html_table();
    $table->head = [
        get_string('status'),
        get_string('photoreq_session', 'mod_multicheckin'),
        get_string('pointname', 'mod_multicheckin'),
        get_string('photoreq_student', 'mod_multicheckin'),
        get_string('photoreq_reason', 'mod_multicheckin'),
        get_string('photoreq_submittedat', 'mod_multicheckin'),
        get_string('photoreq_reviewedby', 'mod_multicheckin'),
        get_string('action', 'mod_multicheckin'),
    ];
    $table->attributes['class'] = 'generaltable table-sm align-middle';

    foreach ($requests as $request) {
        $status = (int) $request->status;
        $session = $sessions[(int) $request->sessionid] ?? null;
        $point = $points[(int) $request->pointid] ?? null;
        $student = $users[(int) $request->userid] ?? null;

        $sessioncell = $session
            ? html_writer::link(new moodle_url('/mod/multicheckin/view.php', [
                'id' => $cm->id,
                'session' => $session->id,
            ]), occurrence_helper::label($session))
            : '—';

        $reviewcell = '—';
        if ((int) $request->reviewedby) {
            $reviewer = $users[(int) $request->reviewedby] ?? null;
            $reviewcell = ($reviewer ? fullname($reviewer) : '#' . (int) $request->reviewedby)
                . html_writer::div(userdate((int) $request->timereviewed), 'text-muted small');
        }

        // Every decision travels in a POST body. A link would be enough to
        // satisfy sesskey, but a GET that writes a check-in can be triggered by
        // anything that follows links, including a link prefetcher.
        $actions = '';
        if ($status === photo_manager::STATUS_PENDING) {
            foreach ([
                'approve' => [get_string('photoreq_approve', 'mod_multicheckin'), 'btn btn-primary btn-sm'],
                'refuse' => [get_string('photoreq_refuse', 'mod_multicheckin'), 'btn btn-outline-danger btn-sm'],
            ] as $what => $button) {
                $actions .= html_writer::tag('form',
                    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
                    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id])
                    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $what])
                    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'request', 'value' => (int) $request->id])
                    . ($showall ? html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'all', 'value' => 1]) : '')
                    . html_writer::tag('button', $button[0], ['type' => 'submit', 'class' => $button[1]]),
                    ['method' => 'post', 'action' => $listurl->out(false), 'class' => 'd-inline-block me-1']);
            }
        }

        $reason = trim((string) $request->reason);

        $table->data[] = [
            html_writer::span(photo_manager::status_label($status), photo_manager::status_class($status)),
            $sessioncell,
            $point ? format_string(point_name::display($point)) : '—',
            $student ? fullname($student) : '#' . (int) $request->userid,
            // Escaped, not formatted: the reason is a sentence the student
            // typed, and nothing in it is markup. nl2br after s() keeps the
            // line breaks they wrote without letting any tag through.
            $reason === '' ? html_writer::span('—', 'text-muted') : nl2br(s($reason)),
            userdate((int) $request->timecreated),
            $reviewcell,
            $actions,
        ];
    }

    echo html_writer::div(html_writer::table($table), 'table-responsive');
}

echo html_writer::div($OUTPUT->single_button($listurl, get_string('back'), 'get'), 'mt-3');

echo $OUTPUT->footer();
