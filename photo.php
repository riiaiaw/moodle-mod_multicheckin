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
 * The photo pass: take the picture, and only then record the check-in.
 *
 * This page is reached from checkin.php with a short-lived pass in the URL, and
 * it is the SECOND half of a photo check-in. The first half - the password, the
 * QR token, the geofence - has already run there, because framing a photograph
 * takes longer than a rotating code survives (a photo occurrence hands out a
 * code that stands still, for exactly this reason). Credentials are therefore
 * settled before the camera opens, and what is left for this page is the
 * picture itself.
 *
 * What it deliberately does NOT have is any way to choose a file. There is no
 * file input, no drag-and-drop area and no draft file area anywhere in the
 * request: the browser puts a JPEG into an ordinary form field and posts it. So
 * "you may not upload a picture instead of taking one" is not a rule this page
 * remembers to enforce - it is a shape the request does not have.
 *
 * Two orderings in here are load-bearing.
 *
 *   - The LOG ROW IS WRITTEN FIRST, because its id is the itemid the file area
 *     is keyed by. If the file cannot be stored the row is rolled back, so a
 *     capture that fails leaves no check-in behind it: for a photo occurrence,
 *     "checked in" has to mean "checked in and photographed".
 *
 *   - Every gate is RE-RUN AT RECORDING TIME rather than trusted from the pass.
 *     The pass says which occurrence and point it was minted for and the
 *     position that satisfied the fence; the window, the duplicate check and
 *     the leave check are all re-evaluated here, so a pass cannot be used to
 *     check in after the point has closed.
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
// phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalGlobalState -- config.php is above public/ on 5.x.
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
use mod_multicheckin\photo_manager;
use mod_multicheckin\point_name;
use mod_multicheckin\session_manager;

$cmid = required_param('id', PARAM_INT);
$sessionid = required_param('session', PARAM_INT);
$pointid = required_param('point', PARAM_INT);
$passtoken = required_param('pass', PARAM_ALPHANUM);

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
$byhandurl = new moodle_url('/mod/multicheckin/photorequest.php', [
    'id' => $cm->id,
    'session' => $session->id,
    'point' => $point->id,
]);

// The pass, and the gates it does not exempt anyone from.
// The owner is named explicitly rather than left to read_pass()'s default. This
// is the one page that turns a URL into a camera, and the permit now works from
// any browser the student is logged into, so "and it belongs to this user" is
// the check that keeps a forwarded link from checking in somebody who is not
// Here. Spelling it out at the call site keeps that visible where it matters.
$capturepass = photo_manager::read_pass(
    $passtoken,
    (int) $session->id,
    (int) $point->id,
    (int) $USER->id
);
if (!$capturepass) {
    redirect(
        $viewurl,
        get_string('photo_pass_expired', 'mod_multicheckin'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

if (!session_manager::point_is_open($session, $point)) {
    redirect(
        $viewurl,
        get_string('point_closed', 'mod_multicheckin'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$already = $DB->record_exists('multicheckin_log', [
    'sessionid' => (int) $session->id,
    'pointid' => (int) $point->id,
    'userid' => (int) $USER->id,
]);
if ($already) {
    photo_manager::clear_pass($passtoken);
    redirect(
        $viewurl,
        get_string('already_checked_in', 'mod_multicheckin'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

if (leave_manager::record((int) $session->id, (int) $USER->id)) {
    photo_manager::clear_pass($passtoken);
    redirect(
        $viewurl,
        get_string('leave_already', 'mod_multicheckin'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

// The fence is judged again, on the position the pass carried, with the method
// the pass was minted under. Nothing is taken on trust: a coordinate that was
// inside the fence when the pass was issued is judged again now, and a point
// that has since drifted outside is refused.
if (!has_capability('mod/multicheckin:manage', $context)) {
    $verdict = geo_manager::evaluate(
        $session,
        $point,
        (int) $USER->id,
        (string) ($capturepass['method'] ?? 'self'),
        $capturepass['clientgeo'] ?? null
    );
    if (!$verdict['allowed']) {
        photo_manager::clear_pass($passtoken);
        redirect(
            $viewurl,
            geo_manager::reason_string($verdict['reason']),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

// The capture itself.
$error = '';

if (data_submitted() && optional_param(photo_manager::CAPTURED_FLAG, '', PARAM_ALPHA) === 'yes') {
    require_sesskey();

    $payload = required_param(photo_manager::FIELD, PARAM_RAW);
    $verdict = photo_manager::validate_capture($payload);

    if (!$verdict['ok']) {
        $error = get_string('photoerr_' . $verdict['reason'], 'mod_multicheckin');
    } else {
        // The row first: its id is what the photograph is filed under.
        $logid = session_manager::record_checkin(
            $session,
            $point,
            (int) $USER->id,
            (string) ($capturepass['method'] ?? 'self')
        );

        $stored = null;
        try {
            $stored = photo_manager::store_for_log($session, $logid, $verdict['binary']);
        } catch (\Throwable $e) {
            $stored = null;
        }

        if (!$stored) {
            // Rolled back, not left half-written. A photograph that cannot be
            // stored must not leave a check-in claiming to have one.
            $DB->delete_records('multicheckin_log', ['id' => $logid]);
            $error = get_string('photo_store_failed', 'mod_multicheckin');
        } else {
            photo_manager::clear_pass($passtoken);

            $event = \mod_multicheckin\event\checkin_recorded::create([
                'context' => $context,
                // The log row the photograph was filed under. See checkin.php.
                'objectid' => (int) $logid,
                'other' => ['pointid' => (int) $point->id, 'sessionid' => (int) $session->id],
            ]);
            $event->trigger();

            // One photograph changed one student's status. See checkin.php for
            // why this is not the whole class.
            checkin_engine::refresh_user($session, (int) $USER->id);
            multicheckin_update_grades($session, (int) $USER->id);

            redirect(
                $viewurl,
                get_string('photo_checkin_recorded', 'mod_multicheckin'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
    }
}

$PAGE->set_url('/mod/multicheckin/photo.php', [
    'id' => $cm->id,
    'session' => $session->id,
    'point' => $point->id,
]);
$PAGE->set_title(format_string($template->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('photo_capture', 'mod_multicheckin'));

if ($error !== '') {
    echo $OUTPUT->notification($error, \core\output\notification::NOTIFY_ERROR, false);
}

// The browser in front of the student.
// A photograph taken inside WeChat, enterprise WeChat, phone QQ or DingTalk is
// not the photograph the same phone produces in Safari or Chrome: those
// WebViews bring a camera layer of their own, and a page cannot reach it.
// Saying so is all a page may do - there is no way to leave an in-app browser
// from script (intent:// is blocked, and iOS offers no scheme at all).
//
// It is a warning, NOT a gate. The teacher asked for a hint and a copy button
// and explicitly not for a block, so nothing below returns or exits: a student
// who ignores this line can still take the picture and still submit it.
//
// The address offered here is worth something only in WeChat. Until 2.0.23 the
// permit it carried lived in the session of the browser that started the
// attempt, so opening it anywhere else reported the permit as expired -
// immediately, and every single time, however fast the student was. A permit
// minted inside WeChat is a row in a table as of 2.0.23, which is what lets
// this copy cross browsers; every other browser, in-app or not, keeps its
// Permit in its own session because it never has to travel.
$formurl = new moodle_url('/mod/multicheckin/photo.php', [
    'id' => $cm->id,
    'session' => $session->id,
    'point' => $point->id,
    'pass' => $passtoken,
]);

$restrictedhost = photo_manager::restricted_host();
if ($restrictedhost !== null) {
    $hostname = get_string('photo_host_' . $restrictedhost, 'mod_multicheckin');

    // The copy box is offered only where a copy can work. A permit minted in
    // WeChat is a row in a table, so the browser the address is pasted into can
    // read it. For the other in-app browsers the permit stays in this one's
    // session, so a copied address would be refused the moment it left - and a
    // button handing out a dead link is worse than no button at all.
    if (photo_manager::pass_must_be_shared()) {
        echo html_writer::div(
            html_writer::tag(
                'strong',
                get_string('photo_openinbrowser', 'mod_multicheckin', $hostname),
                ['class' => 'd-block mb-2']
            )
            . html_writer::empty_tag('input', [
                'type' => 'text',
                'class' => 'form-control form-control-sm mb-2',
                'id' => 'multicheckin-photo-copysource',
                'value' => $formurl->out(false),
                'readonly' => 'readonly',
            ])
            . html_writer::div(
                html_writer::tag('button', get_string('photo_copylink', 'mod_multicheckin'), [
                    'type' => 'button',
                    'class' => 'btn btn-sm btn-outline-dark',
                    'id' => 'multicheckin-photo-copy',
                    'data-copied' => get_string('photo_copied', 'mod_multicheckin'),
                    'data-failed' => get_string('photo_copyfailed', 'mod_multicheckin'),
                ])
                . html_writer::span('', 'ms-2 small', ['id' => 'multicheckin-photo-copystate']),
                'd-flex align-items-center'
            ),
            'alert alert-warning'
        );

        // Clipboard access is not universal inside these WebViews, so the button
        // has three ways out: the async clipboard, the old execCommand, and
        // selecting the text for a long press. The last one needs no permission at
        // all, which is why the address is on screen as a field and not baked into
        // the button. This script reads the DOM and writes one string; it shares
        // nothing with the capture code below and cannot change what that does.
        // The behaviour lives in amd/src/photo_copy.js. An inline script
        // cannot be covered by a CSP hash or nonce, and this page is opened
        // from a QR code inside whatever browser the student happens to have.
        $PAGE->requires->js_call_amd('mod_multicheckin/photo_copy', 'init');
    } else {
        // Every remaining in-app browser has a menu item that reopens this page
        // in the real browser and carries this session with it, so the student is
        // told to use that instead of being handed a copy that would not survive
        // the trip.
        echo html_writer::div(
            html_writer::tag(
                'strong',
                get_string('photo_openinbrowser_menu', 'mod_multicheckin', $hostname),
                ['class' => 'd-block']
            ),
            'alert alert-warning'
        );
    }
}

echo html_writer::div(get_string('photo_intro', 'mod_multicheckin', format_string(point_name::display($point))), 'mb-2');

// Said before the shutter, not after it. The line is also drawn into the
// picture, so a teacher reading the file later can tell when and where it was
// taken without a database row to hand.
echo html_writer::div(
    html_writer::tag('strong', get_string('photo_watermark', 'mod_multicheckin'))
    . ' ' . s(photo_manager::watermark_text($session, $point, $course)),
    'text-muted small mb-2'
);

echo html_writer::div(get_string('photo_noretake', 'mod_multicheckin'), 'alert alert-info');

// The same URL the hint above puts in the copy field: one address, so the
// posted form and the copied link cannot drift apart.
echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $formurl,
    'id' => 'multicheckin-photo-form',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'session', 'value' => $session->id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'point', 'value' => $point->id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'pass', 'value' => $passtoken]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => photo_manager::CAPTURED_FLAG,
    'value' => 'yes',
]);
// The one field the capture travels in. It is a plain text field on purpose:
// a file input would be a way to submit a picture that was never taken.
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => photo_manager::FIELD,
    'id' => 'multicheckin-photo-data',
    'value' => '',
]);

// The stage is the landscape window the preview is shown through: it rounds the
// corners, is what a tap is measured against when the student focuses on a spot,
// and is a fixed 4:3 whatever shape the camera hands over. A phone held upright
// hands over an upright stream, and a box shaped by the stream is then a tall
// strip that pushes the shutter and the buttons below the fold - the picture is
// the whole page, and the thing being photographed is a thin band in the middle
// of it.
//
// The height is written as `padding-top: 75%` rather than `aspect-ratio` because
// the percentage form has been understood by every browser for twenty years while
// `aspect-ratio` is a 2021 feature. 75% is the height of a 4:3 box relative to
// its width; the same ratio appears once more in cropBox() below, and the two
// have to stay the same number - the box shape and the rectangle cut out of the
// frame are the same decision.
//
// Which is why the box sits inside one more div. A percentage padding resolves
// against the CONTAINING BLOCK's width, not the element's own, so capping the
// stage itself with max-width:480px would give a 480-wide box a height measured
// from the full-width parent - 480 by 900 on a desktop. The cap therefore goes on
// the wrapper, where the stage's 100% and its padding are measured from the same
// 480.
//
// Styled in place rather than from a stylesheet - a handful of rules on one page
// are not worth a file every theme then has to cache and reload.
echo html_writer::div(
    html_writer::div(
        html_writer::tag('video', '', [
            'id' => 'multicheckin-photo-video',
            'autoplay' => 'autoplay',
            'muted' => 'muted',
            'playsinline' => 'playsinline',
            'class' => 'multicheckin-photo-video',
            // Absolute, so the padding box above is filled exactly. The 100%/100%
            // and object-fit are what the page shows before the frame size is
            // known - fitPreview() then pins the picture to the crop rectangle
            // itself, and the two agree because both are the same 4:3, centred.
            'style' => 'display:block;position:absolute;top:0;left:0;width:100%;height:100%;'
                . 'object-fit:cover;',
        ]),
        'multicheckin-photo-stage',
        [
            'id' => 'multicheckin-photo-stage',
            'style' => 'position:relative;overflow:hidden;width:100%;padding-top:75%;'
                . 'background:#000;border-radius:.5rem;line-height:0;',
        ]
    ),
    'multicheckin-photo-frame',
    ['style' => 'width:100%;max-width:480px;']
);

echo html_writer::tag('canvas', '', [
    'id' => 'multicheckin-photo-canvas',
    'class' => 'multicheckin-photo-canvas',
    'style' => 'display:none;',
]);

// This line is the whole of the feedback on this page: "starting", "ready",
// "the camera is blocked", "that photo was too large". The shutter starts
// disabled, so without a live region a screen reader user is told nothing about
// when it becomes usable, or why it never did - and this page is the only way
// out of the check-in on a device without a camera.
echo html_writer::div(
    get_string('photo_starting', 'mod_multicheckin'),
    'text-muted',
    [
        'id' => 'multicheckin-photo-status',
        'role' => 'status',
        'aria-live' => 'polite',
    ]
);

// The shutter and the camera switch share one line, and the line wraps rather
// than squeezing them on a narrow phone. The switch stays hidden until the page
// has counted a second camera: on a laptop with one webcam a button that cannot
// do anything reads as broken, not as absent.
echo html_writer::div(
    html_writer::tag('button', get_string('photo_shoot', 'mod_multicheckin'), [
        'type' => 'button',
        'id' => 'multicheckin-photo-shoot',
        'class' => 'btn btn-primary',
        'disabled' => 'disabled',
    ]) . ' ' .
    html_writer::tag('button', get_string('photo_switchcam', 'mod_multicheckin'), [
        'type' => 'button',
        'id' => 'multicheckin-photo-switch',
        'class' => 'btn btn-outline-secondary',
        'disabled' => 'disabled',
        'style' => 'display:none;',
    ]),
    'd-flex flex-wrap align-items-center gap-2 mt-3'
);

// Shown only once the camera has actually failed, so the by-hand route is
// offered at the moment it is the only route left - and not as an alternative
// sitting beside the shutter for anyone to prefer.
echo html_writer::tag(
    'div',
    html_writer::div(get_string('photo_camera_failed', 'mod_multicheckin'), 'mb-2')
    . html_writer::link(
        $byhandurl,
        get_string('photo_request_byhand', 'mod_multicheckin'),
        ['class' => 'btn btn-secondary']
    ),
    [
        'class' => 'mt-3 multicheckin-photo-fallback',
        'id' => 'multicheckin-photo-fallback',
        'style' => 'display:none;',
    ]
);

echo html_writer::end_tag('form');

// The page works only with scripting on, and says so rather than leaving a dead
// shutter on screen. There is no upload fallback to offer instead: a picture
// the browser already had is not evidence of anything.
echo html_writer::tag('noscript', html_writer::div(
    html_writer::div(get_string('photo_noscript', 'mod_multicheckin'), 'mb-2')
    . html_writer::link(
        $byhandurl,
        get_string('photo_request_byhand', 'mod_multicheckin'),
        ['class' => 'btn btn-secondary']
    ),
    'alert alert-warning'
));

$jsconfig = json_encode([
    'maxedge' => photo_manager::MAX_EDGE,
    'maxbytes' => photo_manager::MAX_BYTES,
    'quality' => photo_manager::JPEG_QUALITY,
    'watermark' => photo_manager::watermark_text($session, $point, $course),
    'strings' => [
        'starting' => get_string('photo_starting', 'mod_multicheckin'),
        'ready' => get_string('photo_ready', 'mod_multicheckin'),
        'shoot' => get_string('photo_shoot', 'mod_multicheckin'),
        'shootconfirm' => get_string('photo_shoot_confirm', 'mod_multicheckin'),
        'switchcam' => get_string('photo_switchcam', 'mod_multicheckin'),
        'switching' => get_string('photo_switching', 'mod_multicheckin'),
        'switchfailed' => get_string('photo_switchfailed', 'mod_multicheckin'),
        'focused' => get_string('photo_focused', 'mod_multicheckin'),
        'denied' => get_string('photo_denied', 'mod_multicheckin'),
        'toobig' => get_string('photoerr_toobig', 'mod_multicheckin'),
    ],
]);

// The payload rides in an attribute and the behaviour lives in
// amd/src/photo_capture.js. An inline script cannot be covered by a CSP hash
// or nonce, and this payload carries the watermark - course, session and room
// names, which are teacher-written text going straight into a page.
echo html_writer::div('', 'd-none', [
    'id' => 'multicheckin-photo-config',
    'data-config' => $jsconfig,
]);
$PAGE->requires->js_call_amd('mod_multicheckin/photo_capture', 'init');

echo $OUTPUT->footer();
