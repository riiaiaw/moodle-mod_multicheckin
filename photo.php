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

// ---- The pass, and the gates it does not exempt anyone from ----
// The owner is named explicitly rather than left to read_pass()'s default. This
// is the one page that turns a URL into a camera, and the permit now works from
// any browser the student is logged into, so "and it belongs to this user" is
// the check that keeps a forwarded link from checking in somebody who is not
// here. Spelling it out at the call site keeps that visible where it matters.
$capturepass = photo_manager::read_pass($passtoken, (int) $session->id, (int) $point->id,
    (int) $USER->id);
if (!$capturepass) {
    redirect($viewurl, get_string('photo_pass_expired', 'mod_multicheckin'), null,
        \core\output\notification::NOTIFY_ERROR);
}

if (!session_manager::point_is_open($session, $point)) {
    redirect($viewurl, get_string('point_closed', 'mod_multicheckin'), null,
        \core\output\notification::NOTIFY_ERROR);
}

$already = $DB->record_exists('multicheckin_log', [
    'sessionid' => (int) $session->id,
    'pointid' => (int) $point->id,
    'userid' => (int) $USER->id,
]);
if ($already) {
    photo_manager::clear_pass($passtoken);
    redirect($viewurl, get_string('already_checked_in', 'mod_multicheckin'), null,
        \core\output\notification::NOTIFY_INFO);
}

if (leave_manager::record((int) $session->id, (int) $USER->id)) {
    photo_manager::clear_pass($passtoken);
    redirect($viewurl, get_string('leave_already', 'mod_multicheckin'), null,
        \core\output\notification::NOTIFY_INFO);
}

// The fence is judged again, on the position the pass carried, with the method
// the pass was minted under. Nothing is taken on trust: a coordinate that was
// inside the fence when the pass was issued is judged again now, and a point
// that has since drifted outside is refused.
if (!has_capability('mod/multicheckin:manage', $context)) {
    $verdict = geo_manager::evaluate($session, $point, (int) $USER->id,
        (string) ($capturepass['method'] ?? 'self'), $capturepass['clientgeo'] ?? null);
    if (!$verdict['allowed']) {
        photo_manager::clear_pass($passtoken);
        redirect($viewurl, geo_manager::reason_string($verdict['reason']), null,
            \core\output\notification::NOTIFY_ERROR);
    }
}

// ---- The capture itself ----
$error = '';

if (data_submitted() && optional_param(photo_manager::CAPTURED_FLAG, '', PARAM_ALPHA) === 'yes') {
    require_sesskey();

    $payload = required_param(photo_manager::FIELD, PARAM_RAW);
    $verdict = photo_manager::validate_capture($payload);

    if (!$verdict['ok']) {
        $error = get_string('photoerr_' . $verdict['reason'], 'mod_multicheckin');
    } else {
        // The row first: its id is what the photograph is filed under.
        $logid = session_manager::record_checkin($session, $point, (int) $USER->id,
            (string) ($capturepass['method'] ?? 'self'));

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
                'objectid' => 0,
                'other' => ['pointid' => (int) $point->id, 'sessionid' => (int) $session->id],
            ]);
            $event->trigger();

            // One photograph changed one student's status. See checkin.php for
            // why this is not the whole class.
            checkin_engine::refresh_user($session, (int) $USER->id);
            multicheckin_update_grades($session, (int) $USER->id);

            redirect($viewurl, get_string('photo_checkin_recorded', 'mod_multicheckin'), null,
                \core\output\notification::NOTIFY_SUCCESS);
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

// ---- The browser in front of the student ----------------------------------
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
// permit in its own session because it never has to travel.
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
            html_writer::tag('strong',
                get_string('photo_openinbrowser', 'mod_multicheckin', $hostname),
                ['class' => 'd-block mb-2'])
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
                'd-flex align-items-center'),
            'alert alert-warning');

        // Clipboard access is not universal inside these WebViews, so the button
        // has three ways out: the async clipboard, the old execCommand, and
        // selecting the text for a long press. The last one needs no permission at
        // all, which is why the address is on screen as a field and not baked into
        // the button. This script reads the DOM and writes one string; it shares
        // nothing with the capture code below and cannot change what that does.
        echo html_writer::tag('script', <<<'JS'
(function() {
    var button = document.getElementById('multicheckin-photo-copy');
    var source = document.getElementById('multicheckin-photo-copysource');
    var state = document.getElementById('multicheckin-photo-copystate');
    if (!button || !source) { return; }

    function say(text) {
        if (state) { state.textContent = text; }
    }

    function done() {
        say(button.getAttribute('data-copied') || '');
        window.setTimeout(function() { say(''); }, 3000);
    }

    function selectIt() {
        try {
            source.focus();
            source.select();
            return true;
        } catch (e) {
            return false;
        }
    }

    function fallback() {
        if (selectIt()) {
            try {
                if (document.execCommand('copy')) { done(); return; }
            } catch (e) { /* the selection is already on screen */ }
        }
        say(button.getAttribute('data-failed') || '');
    }

    button.addEventListener('click', function() {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(source.value).then(done, fallback);
            return;
        }
        fallback();
    });

    // Focusing selects the whole address, so a long press copies it even when
    // neither clipboard route is available.
    source.addEventListener('focus', function() { source.select(); });
})();
JS
        , ['type' => 'text/javascript']);
    } else {
        // Every remaining in-app browser has a menu item that reopens this page
        // in the real browser and carries this session with it, so the student is
        // told to use that instead of being handed a copy that would not survive
        // the trip.
        echo html_writer::div(
            html_writer::tag('strong',
                get_string('photo_openinbrowser_menu', 'mod_multicheckin', $hostname),
                ['class' => 'd-block']),
            'alert alert-warning');
    }
}

echo html_writer::div(get_string('photo_intro', 'mod_multicheckin', format_string(point_name::display($point))), 'mb-2');

// Said before the shutter, not after it. The line is also drawn into the
// picture, so a teacher reading the file later can tell when and where it was
// taken without a database row to hand.
echo html_writer::div(
    html_writer::tag('strong', get_string('photo_watermark', 'mod_multicheckin'))
    . ' ' . s(photo_manager::watermark_text($session, $point, $course)),
    'text-muted small mb-2');

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
        ]),
    'multicheckin-photo-frame',
    ['style' => 'width:100%;max-width:480px;']);

echo html_writer::tag('canvas', '', [
    'id' => 'multicheckin-photo-canvas',
    'class' => 'multicheckin-photo-canvas',
    'style' => 'display:none;',
]);

echo html_writer::div(get_string('photo_starting', 'mod_multicheckin'),
    'text-muted', ['id' => 'multicheckin-photo-status']);

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
    'd-flex flex-wrap align-items-center gap-2 mt-3');

// Shown only once the camera has actually failed, so the by-hand route is
// offered at the moment it is the only route left - and not as an alternative
// sitting beside the shutter for anyone to prefer.
echo html_writer::tag('div',
    html_writer::div(get_string('photo_camera_failed', 'mod_multicheckin'), 'mb-2')
    . html_writer::link($byhandurl, get_string('photo_request_byhand', 'mod_multicheckin'),
        ['class' => 'btn btn-secondary']),
    [
        'class' => 'mt-3 multicheckin-photo-fallback',
        'id' => 'multicheckin-photo-fallback',
        'style' => 'display:none;',
    ]);

echo html_writer::end_tag('form');

// The page works only with scripting on, and says so rather than leaving a dead
// shutter on screen. There is no upload fallback to offer instead: a picture
// the browser already had is not evidence of anything.
echo html_writer::tag('noscript', html_writer::div(
    html_writer::div(get_string('photo_noscript', 'mod_multicheckin'), 'mb-2')
    . html_writer::link($byhandurl, get_string('photo_request_byhand', 'mod_multicheckin'),
        ['class' => 'btn btn-secondary']),
    'alert alert-warning'));

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

echo html_writer::tag('script', <<<JS
(function() {
    var cfg = $jsconfig;
    var video = document.getElementById('multicheckin-photo-video');
    var canvas = document.getElementById('multicheckin-photo-canvas');
    var field = document.getElementById('multicheckin-photo-data');
    var form = document.getElementById('multicheckin-photo-form');
    var shoot = document.getElementById('multicheckin-photo-shoot');
    var sw = document.getElementById('multicheckin-photo-switch');
    var status = document.getElementById('multicheckin-photo-status');
    var fallback = document.getElementById('multicheckin-photo-fallback');
    var stage = document.getElementById('multicheckin-photo-stage');
    var stream = null;
    // The facing we are asking for. 'environment' is the rear camera: a student
    // photographing a classroom wants to show the room, not their own face.
    var mode = 'environment';
    // Every video input the browser admits to, in its own order. Only used when
    // facingMode turns out to be a request the browser ignores.
    var cams = [];
    var camindex = 0;
    var busy = false;

    function fail(message) {
        if (status) { status.textContent = message; }
        if (shoot) { shoot.disabled = true; }
        if (fallback) { fallback.style.display = 'block'; }
    }

    function ready() {
        if (status) { status.textContent = cfg.strings.ready; }
        if (shoot) { shoot.disabled = false; }
    }

    function stop() {
        if (stream) {
            stream.getTracks().forEach(function(track) { track.stop(); });
            stream = null;
        }
    }

    function say(message) {
        if (status) { status.textContent = message; }
    }

    function track() {
        return (stream && stream.getVideoTracks) ? stream.getVideoTracks()[0] : null;
    }

    // What the camera says it can do. Every constraint below is offered only if
    // it appears here first: applyConstraints with an unsupported constraint is
    // rejected, and an unhandled rejection at this point leaves a black
    // rectangle where the preview should be.
    function caps() {
        var t = track();
        if (!t || !t.getCapabilities) { return {}; }
        try { return t.getCapabilities() || {}; } catch (e) { return {}; }
    }

    // The frame size is not known until the stream reports it, and the shutter
    // must not go live before there is a frame to capture.
    function whenSized(fn) {
        if (!video) { return; }
        if (video.videoWidth) { fn(); return; }
        var handler = function() {
            video.removeEventListener('loadedmetadata', handler);
            fn();
        };
        video.addEventListener('loadedmetadata', handler);
    }

    function facingOf(s) {
        var track = (s && s.getVideoTracks) ? s.getVideoTracks()[0] : null;
        if (!track || !track.getSettings) { return ''; }
        try { return track.getSettings().facingMode || ''; } catch (e) { return ''; }
    }

    function deviceOf(s) {
        var track = (s && s.getVideoTracks) ? s.getVideoTracks()[0] : null;
        if (!track || !track.getSettings) { return ''; }
        try { return track.getSettings().deviceId || ''; } catch (e) { return ''; }
    }

    // Rear first, but "first" and not "only": `ideal` degrades to whatever camera
    // exists, while `exact` would make the page fail outright on a laptop with no
    // rear camera - the one machine a teacher is most likely to test on.
    //
    // The size is asked for in 4:3 on purpose. Left alone, a browser hands over
    // the smallest mode that satisfies the request - often 640x480 - and the
    // picture then has too few pixels to read a face or a screen. Asking for
    // 16:9 instead would be worse: a 4:3 sensor delivering 16:9 crops the sides
    // off, which is the opposite of the wide view this page is for.
    function byFacing(which) {
        return navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: {ideal: which},
                width: {ideal: 1920},
                height: {ideal: 1440}
            },
            audio: false
        });
    }

    function byDevice(index) {
        return navigator.mediaDevices.getUserMedia({
            video: {
                deviceId: {exact: cams[index].deviceId},
                width: {ideal: 1920},
                height: {ideal: 1440}
            },
            audio: false
        });
    }

    function refreshCams() {
        if (!navigator.mediaDevices.enumerateDevices) { cams = []; return; }
        navigator.mediaDevices.enumerateDevices().then(function(list) {
            cams = list.filter(function(d) { return d.kind === 'videoinput'; });
            var here = deviceOf(stream);
            for (var i = 0; i < cams.length; i++) {
                if (here && cams[i].deviceId === here) { camindex = i; }
            }
            if (sw) {
                if (cams.length > 1) {
                    sw.style.display = '';
                    sw.disabled = false;
                } else {
                    sw.style.display = 'none';
                    sw.disabled = true;
                }
            }
        }).catch(function() { cams = []; });
    }

    // Everything that depends on the camera having a frame to describe. Runs
    // again after a camera switch, because the front and the rear camera have
    // different limits.
    function afterStream() {
        refreshCams();
        openWide();
        askContinuous();
        fitPreview();
        ready();
    }

    function adopt(s) {
        stream = s;
        video.srcObject = s;
        video.play();
        whenSized(afterStream);
    }

    // ---- The lens ------------------------------------------------------

    // Ask for the wide end of whatever zoom range the camera reports.
    //
    // Not "stay where the camera already is", which is what 2.0.19 did. That
    // assumption only survives on a single camera - and a single camera has no
    // zoom range at all, so it never reaches this branch. A phone with several
    // rear cameras reports 0.5x/1x/2x/5x as one range and boots on a middle
    // stop, often 2x: the picture is then a corner of the room rather than the
    // room. Nothing on screen looks wrong, so the default stop is the last
    // thing anyone suspects.
    //
    // This is not a control: the student never sees it and cannot move it. It
    // is the page choosing the lens that fits the scene, once, when the camera
    // opens - the same thing the phone's own camera app does at 1x.
    //
    // A camera that refuses stays where it is. There is nothing to fall back
    // to now that the crop-and-scale zoom is gone, and a picture taken at the
    // wrong stop is still a picture of the student.
    function openWide() {
        var t = track();
        if (!t || !t.applyConstraints) { return; }
        var range = caps().zoom;
        if (!range || typeof range.min !== 'number'
                || typeof range.max !== 'number' || range.max <= range.min) {
            return;
        }
        t.applyConstraints({zoom: range.min}).catch(function() {});
    }

    // ---- Focus ---------------------------------------------------------

    // Continuous focus is asked for explicitly because not every rear camera
    // starts there: some phones come up focused at infinity, which is sharp for
    // a building and soft for a sign two metres away.
    function askContinuous() {
        var t = track();
        if (!t || !t.applyConstraints) { return; }
        var modes = caps().focusMode;
        if (!modes || modes.indexOf('continuous') === -1) { return; }
        t.applyConstraints({advanced: [{focusMode: 'continuous'}]}).catch(function() {});
    }

    // Tap to focus on one point: a single-shot on the spot that was tapped,
    // handing back to continuous two seconds later so the next picture is not
    // stuck on the last one. A browser that does not know about points of
    // interest simply keeps its continuous focus.
    function focusAt(clientX, clientY) {
        var t = track();
        if (!t || !t.applyConstraints || !stage) { return; }
        var c = caps();
        var modes = c.focusMode;
        if (!modes || modes.indexOf('single-shot') === -1 || !c.pointsOfInterest) { return; }
        var rect = stage.getBoundingClientRect();
        if (!rect.width || !rect.height) { return; }
        // Where the tap landed inside the window, 0 to 1.
        var wx = Math.min(1, Math.max(0, (clientX - rect.left) / rect.width));
        var wy = Math.min(1, Math.max(0, (clientY - rect.top) / rect.height));
        // Then where that is inside the FRAME. The window shows a 4:3 rectangle cut
        // out of the frame, so the same number is not the same place any more:
        // passing the tap through unchanged would focus above or below the thing
        // that was tapped, by exactly as much as the crop took off. Handing a 16:9
        // frame through untouched loses a sixth of the height, which is enough to
        // focus on the wall behind someone's head - and the status line still says
        // "focused", so nothing looks wrong.
        var crop = cropBox(video.videoWidth, video.videoHeight);
        if (!crop.w || !crop.h || !video.videoWidth || !video.videoHeight) { return; }
        var x = (crop.x + wx * crop.w) / video.videoWidth;
        var y = (crop.y + wy * crop.h) / video.videoHeight;
        t.applyConstraints({advanced: [{
            focusMode: 'single-shot',
            pointsOfInterest: [{x: x, y: y}]
        }]}).then(function() {
            say(cfg.strings.focused);
            window.setTimeout(function() {
                // Only put the ready line back if nothing more important has
                // been said since.
                if (status && status.textContent === cfg.strings.focused) { ready(); }
                askContinuous();
            }, 2000);
        }).catch(function() { /* declined; continuous focus stands */ });
    }

    function switchTo(target) {
        if (busy) { return; }
        busy = true;
        var previous = mode;
        if (sw) { sw.disabled = true; }
        if (shoot) { shoot.disabled = true; }
        say(cfg.strings.switching);
        // Let the old camera go BEFORE asking for the new one: on a phone, the
        // camera that is still open is usually the reason the second one cannot
        // be acquired.
        stop();
        byFacing(target).then(function(s) {
            // Ask whether it actually changed. A browser that ignores facingMode
            // hands back the camera it already had, and without this check the
            // button would look like it worked while the picture never moves.
            var got = facingOf(s);
            if (got && got !== target) {
                s.getTracks().forEach(function(t) { t.stop(); });
                throw new Error('facing ignored');
            }
            mode = target;
            adopt(s);
        }).catch(function() {
            // Second route: walk the device list. Desktops with a virtual camera
            // or two webcams land here, because facingMode means nothing to them.
            if (cams.length < 2) { throw new Error('no second camera'); }
            var next = (camindex + 1) % cams.length;
            return byDevice(next).then(function(s) {
                camindex = next;
                adopt(s);
            });
        }).catch(function() {
            // Neither route worked: put the camera we had back, so the page is
            // never left on a black rectangle with a dead shutter.
            return byFacing(previous).then(function(s) {
                mode = previous;
                adopt(s);
                say(cfg.strings.switchfailed);
            }).catch(function() {
                fail(cfg.strings.denied);
            });
        }).then(function() {
            busy = false;
            if (!stream) { return; }
            if (shoot) { shoot.disabled = false; }
            if (sw && cams.length > 1) { sw.disabled = false; }
        });
    }

    // ---- The frame -----------------------------------------------------

    // The 4:3 rectangle taken out of the frame. It is THE crop - there is one
    // arithmetic and both halves of the page read it: fitPreview() places the
    // picture so that exactly this rectangle is what the student sees, and draw()
    // copies exactly this rectangle into the file.
    //
    // Two copies of this arithmetic is the failure this page must never have:
    // change the ratio in one of them and the student frames one thing while the
    // file holds another, with nothing on either screen to say so. It happened
    // once, in the days of the zoom slider, and it was found by a person, not by a
    // test.
    //
    // Centred, because cover-cropping anything else - top, bottom, the side the
    // hand happens to be - would silently decide part of the composition for the
    // student.
    function cropBox(vw, vh) {
        var ratio = 4 / 3;
        if (vw / vh > ratio) {
            // Wider than 4:3: the height is the whole frame and the sides are cut.
            var w = vh * ratio;
            return {x: (vw - w) / 2, y: 0, w: w, h: vh};
        }
        // Narrower than 4:3, which is every phone held upright: the width is the
        // whole frame and the top and bottom are cut.
        var h = vw / ratio;
        return {x: 0, y: (vh - h) / 2, w: vw, h: h};
    }

    // Show the crop rectangle and nothing else: enlarge the picture until the
    // rectangle fills the stage, then slide it so the rectangle sits at the
    // window. Percentages, so a rotation, a resize or a change of camera resolves
    // itself with no second listener and no arithmetic in the layout.
    function fitPreview() {
        if (!video || !stage) { return; }
        var vw = video.videoWidth;
        var vh = video.videoHeight;
        if (!vw || !vh) { return; }
        var box = cropBox(vw, vh);
        video.style.width = (100 * vw / box.w) + '%';
        video.style.height = (100 * vh / box.h) + '%';
        video.style.left = (-100 * box.x / box.w) + '%';
        video.style.top = (-100 * box.y / box.h) + '%';
    }

    function draw() {
        var vw = video.videoWidth;
        var vh = video.videoHeight;
        if (!vw || !vh) { return false; }

        // The rectangle the preview is showing, asked for now and not remembered
        // from when the camera opened. A constraint applied after the fact
        // (openWide, or continuous focus on a camera that changes mode for it) can
        // change the frame size under us, and a remembered rectangle would then
        // quietly send a piece of the frame the student is not looking at.
        var box = cropBox(vw, vh);

        // Scaled so the LONGEST edge of the CROP is at most cfg.maxedge. The crop
        // is 4:3 by construction, so this no longer has to reason about a tall
        // original - and the ceiling still never enlarges a frame past its own
        // pixels.
        var scale = Math.min(1, cfg.maxedge / Math.max(box.w, box.h));
        canvas.width = Math.round(box.w * scale);
        canvas.height = Math.round(box.h * scale);

        var ctx = canvas.getContext('2d');
        ctx.drawImage(video, box.x, box.y, box.w, box.h, 0, 0, canvas.width, canvas.height);

        // The watermark. Time, course and point, burned in by the browser from
        // text the SERVER generated - a phone with a wrong clock cannot stamp a
        // convenient hour onto the evidence.
        var line = cfg.watermark;
        var size = Math.max(12, Math.round(canvas.height * 0.035));
        ctx.font = 'bold ' + size + 'px sans-serif';
        ctx.textBaseline = 'bottom';
        var pad = Math.round(size * 0.5);
        var width = Math.min(canvas.width - 2 * pad, ctx.measureText(line).width);
        ctx.fillStyle = 'rgba(0, 0, 0, 0.55)';
        ctx.fillRect(pad, canvas.height - size - 3 * pad, width + 2 * pad, size + 2 * pad);
        ctx.fillStyle = '#ffffff';
        ctx.fillText(line, 2 * pad, canvas.height - 2 * pad, canvas.width - 4 * pad);

        return true;
    }

    // The capture has to arrive under the size the server accepts. At 2048 px
    // the ceiling is 768 KiB, which leaves room to keep the quality high: the
    // ladder stops at 0.60 rather than the 0.45 of 2.0.19, because a JPEG that
    // low turns the printed rows of a check-in sheet into blocky mush - and a
    // softer picture that cannot be read is not a smaller picture, it is a
    // useless one. Halving the canvas stays as the last resort.
    function encode() {
        var limit = Math.floor(cfg.maxbytes * 4 / 3);
        var tries = [cfg.quality, 0.68, 0.60];
        var data = '';
        for (var i = 0; i < tries.length; i++) {
            data = canvas.toDataURL('image/jpeg', tries[i]);
            if (data && data.length <= limit) { return data; }
        }
        // Still too big: half the pixels. It costs detail, but a slightly softer
        // picture beats a check-in that cannot be recorded.
        var w = Math.round(canvas.width / 2);
        var h = Math.round(canvas.height / 2);
        var small = document.createElement('canvas');
        small.width = w;
        small.height = h;
        small.getContext('2d').drawImage(canvas, 0, 0, w, h);
        return small.toDataURL('image/jpeg', cfg.quality);
    }

    function send() {
        var data;
        try {
            data = encode();
        } catch (e) {
            fail(cfg.strings.denied);
            return;
        }
        if (!data || data.indexOf('data:image/jpeg') !== 0) {
            fail(cfg.strings.denied);
            return;
        }
        if (field) { field.value = data; }
        stop();
        if (form) { form.submit(); }
    }

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        fail(cfg.strings.denied);
        return;
    }

    byFacing('environment').then(adopt).catch(function() {
        // A browser that does not know facingMode rejects the constraint, so ask
        // again the way it was asked before this version: no camera preference.
        return navigator.mediaDevices.getUserMedia({video: true, audio: false})
            .then(adopt)
            .catch(function() {
                // Refused permission, no camera at all, or a site served over
                // plain HTTP: all three arrive here, and all three end at the
                // by-hand route.
                fail(cfg.strings.denied);
            });
    });

    // A camera can change its frame size after it opens: openWide() asks for a
    // different zoom stop and some cameras answer with a different mode, and so
    // does continuous focus on others. Without this the window would keep the
    // shape of a frame that no longer exists and show more or less than the file
    // will hold. On a rotation or a resize the percentages in fitPreview() resolve
    // again on their own, so no window listener is needed.
    if (video) {
        video.addEventListener('resize', fitPreview);
    }

    if (sw) {
        sw.addEventListener('click', function() {
            if (sw.disabled || busy || !stream) { return; }
            switchTo(mode === 'environment' ? 'user' : 'environment');
        });
    }

    if (stage) {
        stage.addEventListener('click', function(e) {
            // A tap on the picture means "focus here", not "take the picture":
            // the shutter is a separate button and it asks before it fires.
            focusAt(e.clientX, e.clientY);
        });
    }

    if (shoot) {
        shoot.addEventListener('click', function() {
            if (!window.confirm(cfg.strings.shootconfirm)) { return; }
            shoot.disabled = true;
            if (!draw()) {
                shoot.disabled = false;
                return;
            }
            send();
        });
    }

    // Leaving the page must let the camera go, or the recorder light stays on.
    window.addEventListener('pagehide', stop);
    window.addEventListener('beforeunload', stop);
})();
JS
, ['type' => 'text/javascript']);

echo $OUTPUT->footer();
