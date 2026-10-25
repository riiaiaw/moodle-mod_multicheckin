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
 * Unified check-in entry point.
 *
 * Handles three methods:
 *   - self     : the student clicks a button on the activity view.
 *   - qr       : the student scans a rotating QR code.
 *   - password : the student types the point / session password.
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
use mod_multicheckin\qr_manager;
use mod_multicheckin\session_manager;

$cmid = required_param('id', PARAM_INT);
$sessionid = required_param('session', PARAM_INT);
$pointid = required_param('point', PARAM_INT);
$method = optional_param('method', 'self', PARAM_ALPHA);

// The QR branch below assigns these. Declared up front because the
// geolocation bridge has to rebuild the request URL and carry them with it.
$slot = null;
$token = null;

// Optional browser-reported position, only used when a GPS fence is active.
$geolat = optional_param('geolat', null, PARAM_RAW_TRIMMED);
$geolng = optional_param('geolng', null, PARAM_RAW_TRIMMED);
$geoacc = optional_param('geoacc', null, PARAM_RAW_TRIMMED);

// 'done' means the browser has already been asked for a position during this
// attempt, so a refusal lands on the verdict instead of looping.
$geostate = optional_param('geo', '', PARAM_ALPHA);

$cm = get_coursemodule_from_id('multicheckin', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/multicheckin:checkin', $context);

// The session must belong to this activity. Fetching it by id alone would let
// a hand-edited 'session' parameter check a student into another course's
// attendance, so it is resolved against the activity's own template: the
// result is either this activity's record or null.
$template = session_manager::get_template_for_cm($cm->id);
$session = $template ? session_manager::resolve_session($template, $sessionid) : null;
if (!$session || (int) $session->id !== $sessionid) {
    throw new \moodle_exception('invalidrecord', 'error');
}

$point = $DB->get_record('multicheckin_point', ['id' => $pointid, 'sessionid' => $sessionid], '*', MUST_EXIST);

// The session record needs the coursemodule for building URLs.
$session->coursemodule = $cm->id;

$viewurl = new moodle_url('/mod/multicheckin/view.php', ['id' => $cm->id]);

// ---- CSRF: this page writes, so it must not be reachable from a page
// somebody else built ----
//
// Everything below this line writes: a log row, a result row, a gradebook
// push and an event. Two of the three methods start from a link this plugin
// built on this activity's own page, and that link carries sesskey() (and so
// does the geolocation script that rewrites it before navigating). Requiring
// the key is what stops `<img src="checkin.php?...&method=self">` pasted into a
// forum post, a message or an email from recording a check-in for every student
// who opens the page: a passive load has no key to send, and cannot get one.
//
// The QR route is the genuine exception and is handled differently. The code is
// drawn on the TEACHER's screen, so the URL inside it cannot contain the
// student's key - the key does not exist until that student's own browser asks
// for the page. What the code carries instead is a signed token that rotates
// every few seconds, and that token is the credential. It is still refused when
// the browser reports the request as a passive subresource load - the shape the
// <img> attack takes - while a scan, which is a navigation, is unaffected.
if ($method === 'qr') {
    if (qr_manager::passive_embed()) {
        redirect($viewurl, get_string('qr_scan_in_browser', 'mod_multicheckin'), null,
            \core\output\notification::NOTIFY_ERROR);
    }
} else {
    require_sesskey();
}

// ---- Decide the effective method and validate credentials ----
if ($method === 'qr') {
    $slot = required_param('slot', PARAM_INT);
    $token = required_param('token', PARAM_ALPHANUM);
    if (!qr_manager::validate($point, $slot, $token, null, $session)) {
        redirect($viewurl, get_string('qr_invalid', 'mod_multicheckin'), null,
            \core\output\notification::NOTIFY_ERROR);
    }
} else if ($method === 'password') {
    // A QR-only point has no password route. Refused HERE, before the
    // submitted value is even read and long before it is compared, because the
    // point of the setting is that the route does not exist - not that it
    // returns the wrong answer. A student who is told the password by a
    // classmate must still be unable to sign in, otherwise "only the code on
    // the screen works" is not true and the teacher has no way to tell.
    if (qr_manager::only_scan($session, $point)) {
        redirect($viewurl, get_string('qronly_only_scan', 'mod_multicheckin'), null,
            \core\output\notification::NOTIFY_ERROR);
    }

    // Password may be per-point or a session-wide self check-in password.
    $submitted = required_param('password', PARAM_TEXT);
    $expected = '';
    if (!empty($point->password)) {
        $expected = $point->password;
    } else if (!empty($session->selfcheckinpassword)) {
        $expected = $session->selfcheckinpassword;
    }
    if ($expected === '' || !hash_equals($expected, $submitted)) {
        redirect($viewurl, get_string('password_invalid', 'mod_multicheckin'), null,
            \core\output\notification::NOTIFY_ERROR);
    }
} else {
    // Self check-in: only allowed if the teacher enabled it.
    $method = 'self';
    if (empty($session->allowselfcheckin)) {
        redirect($viewurl, get_string('selfcheckin_disabled', 'mod_multicheckin'), null,
            \core\output\notification::NOTIFY_ERROR);
    }
    // And a QR-only point closes this route as well, checked after the
    // occurrence-wide switch so the wider rule speaks first. view.php does not
    // draw the button for such a point, but hiding a button is not a rule: the
    // URL is guessable, so the refusal has to live on the page that writes.
    if (qr_manager::only_scan($session, $point)) {
        redirect($viewurl, get_string('qronly_only_scan', 'mod_multicheckin'), null,
            \core\output\notification::NOTIFY_ERROR);
    }
}

// ---- Geofence: is this user physically/network-wise allowed to mark present? ----
// Teachers and managers bypass the fence (they take the roll in person); the
// fence exists to stop students checking in from a dorm room.
//
// The position is declared out here because the photo pass has to carry it: the
// capture happens on another page, and the fence is judged again there on the
// same numbers rather than being remembered as a verdict.
$clientgeo = null;
if (!has_capability('mod/multicheckin:manage', $context)) {
    if ($geolat !== null && $geolng !== null && $geolat !== '' && $geolng !== '') {
        $clientgeo = [
            'lat' => (float) $geolat,
            'lng' => (float) $geolng,
            'acc' => ($geoacc !== null && $geoacc !== '') ? (float) $geoacc : null,
        ];
    }

    // A GPS fence cannot be judged without a position, and the QR route has
    // never asked for one: a student scans a code and arrives here with no
    // coordinates at all. Rejecting outright would lock out the whole class the
    // moment a teacher turns the fence on, so instead send the browser back for
    // one fix and return with it attached. 'geo=done' marks that the round trip
    // has already happened; a second pass - refused permission, no fix, no
    // geolocation API - falls through to the normal verdict, which then says
    // honestly that no position was available.
    //
    // Passwords are left alone: replaying the request would put the password in
    // the URL, and a typed password is a different kind of proof from a scan.
    $effective = geo_manager::effective_mode($session, $method);
    if ($clientgeo === null && $geostate !== 'done' && $method !== 'password'
            && geo_manager::mode_needs_gps($effective)) {
        $baseparams = [
            'id' => $cmid,
            'session' => $sessionid,
            'point' => $pointid,
            'method' => $method,
            'geo' => 'done',
            // The key travels with the round trip. Leaving it out would mean
            // the browser comes back to a page that refuses it, so every
            // fenced check-in would fail at the last step - the fence and the
            // guard are two halves of one path and have to be changed together.
            'sesskey' => sesskey(),
        ];
        if ($method === 'qr') {
            $baseparams['slot'] = $slot;
            $baseparams['token'] = $token;
        }
        $geobase = (new moodle_url('/mod/multicheckin/checkin.php', $baseparams))->out(false);

        $PAGE->set_url(new moodle_url('/mod/multicheckin/checkin.php', ['id' => $cmid]));
        $PAGE->set_title(format_string($template->name));
        $PAGE->set_heading(format_string($course->fullname));
        $PAGE->set_context($context);

        $cfg = json_encode([
            'base' => $geobase,
            'locating' => get_string('geo_locating', 'mod_multicheckin'),
        ]);

        echo $OUTPUT->header();
        echo html_writer::div(get_string('geo_locating', 'mod_multicheckin'),
            'alert alert-info multicheckin-geobridge');
        echo html_writer::tag('script', "
(function() {
    var cfg = $cfg;
    function finish(lat, lng, acc) {
        var url = cfg.base;
        if (lat !== null && lat !== undefined) {
            url += '&geolat=' + encodeURIComponent(lat)
                 + '&geolng=' + encodeURIComponent(lng)
                 + (acc ? '&geoacc=' + encodeURIComponent(acc) : '');
        }
        window.location.replace(url);
    }
    if (!navigator.geolocation) { finish(null); return; }
    navigator.geolocation.getCurrentPosition(function(pos) {
        var c = pos.coords;
        finish(c.latitude, c.longitude, c.accuracy);
    }, function() { finish(null); },
    {enableHighAccuracy: true, timeout: 15000, maximumAge: 0});
})();
", ['type' => 'text/javascript']);
        echo $OUTPUT->footer();
        exit;
    }

    $verdict = geo_manager::evaluate($session, $point, (int) $USER->id, $method, $clientgeo);
    if (!$verdict['allowed']) {
        redirect($viewurl, geo_manager::reason_string($verdict['reason']), null,
            \core\output\notification::NOTIFY_ERROR);
    }
}

// ---- Common gate: the point must be open and not already recorded ----
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
    redirect($viewurl, get_string('already_checked_in', 'mod_multicheckin'), null,
        \core\output\notification::NOTIFY_INFO);
}

// A student on leave is not expected in class, and a leave outranks the logs -
// so a check-in recorded here would change no status and would only make the
// report say two contradictory things. Refused with the reason rather than
// written silently. This is the scan path as well, which is deliberate: the
// code on screen says "come in", and a leave is the opposite statement.
if (leave_manager::record((int) $session->id, (int) $USER->id)) {
    redirect($viewurl, get_string('leave_already', 'mod_multicheckin'), null,
        \core\output\notification::NOTIFY_INFO);
}

// ---- The photo gate ----
//
// For a photo occurrence nothing is written yet. What happens instead is that
// the successful credential check above is exchanged for a short-lived pass,
// and the student is sent to the capture page. The check-in is written THERE,
// after a picture has been accepted, which is what makes "checked in" mean
// "checked in and photographed" rather than "checked in, hopefully".
//
// The exchange exists because the two halves cannot happen on one page: a
// rotating QR token is valid for about fifteen seconds, and framing and taking
// a photograph takes longer than that. So credentials are settled here, where
// the token is still fresh, and the picture is taken afterwards, where it can
// be. Every gate is re-run at the moment of recording, so the pass buys time
// and nothing else.
//
// Teachers are exempt, exactly as they are from the fence: they take the roll
// in person, and a photograph of the teacher is not evidence about anybody.
if (!has_capability('mod/multicheckin:manage', $context) && photo_manager::is_required($session)) {
    // The Moodle App renders this plugin in an in-app browser where
    // getUserMedia does not exist, so a student arriving that way would be
    // shown a camera that can never start. They are sent straight to the
    // by-hand route, with the reason spelled out.
    if (!photo_manager::capture_available()) {
        $fallback = new moodle_url('/mod/multicheckin/photorequest.php', [
            'id' => $cmid,
            'session' => $sessionid,
            'point' => $pointid,
        ]);
        redirect($fallback, get_string('photo_app_fallback', 'mod_multicheckin'), null,
            \core\output\notification::NOTIFY_INFO);
    }

    $passtoken = photo_manager::issue_pass($session, $point, $method, $clientgeo);
    $capture = new moodle_url('/mod/multicheckin/photo.php', [
        'id' => $cmid,
        'session' => $sessionid,
        'point' => $pointid,
        'pass' => $passtoken,
    ]);
    redirect($capture);
}

// ---- Record the check-in ----
session_manager::record_checkin($session, $point, (int) $USER->id, $method);

$event = \mod_multicheckin\event\checkin_recorded::create([
    'context' => $context,
    'objectid' => 0,
    'other' => ['pointid' => (int) $point->id, 'sessionid' => (int) $session->id],
]);
$event->trigger();

// Keep this student's computed status and gradebook entry in step with the new
// log, and only theirs. The check-in changed one row, so one row is the width of
// the work: refreshing the whole class here would have every student recompute
// all sixty of their classmates as the class files in one after another, and
// fifty-nine of those sixty runs write the value the next run writes again.
checkin_engine::refresh_user($session, (int) $USER->id);
multicheckin_update_grades($session, (int) $USER->id);

redirect($viewurl, get_string('checkin_recorded_ok', 'mod_multicheckin'), null,
    \core\output\notification::NOTIFY_SUCCESS);
