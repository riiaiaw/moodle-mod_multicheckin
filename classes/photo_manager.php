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

namespace mod_multicheckin;

defined('MOODLE_INTERNAL') || die();

// The context the photos are filed under is the same one the leave notes use,
// so this class borrows leave_manager's resolver rather than answering the
// question a second way. The cleanup paths below run from pages opened directly
// by URL and from lib.php early in the request, where the component's
// autoloader is not always primed yet; requiring it here keeps that resolver
// reachable (the same pattern session_manager uses for schedule_planner).
require_once(__DIR__ . '/leave_manager.php');

/**
 * On-the-spot photographs of a check-in, and the requests raised when one
 * cannot be taken.
 *
 * An occurrence may require every check-in to be proved with a photograph. The
 * photograph is captured by the browser's own camera and is the ONLY accepted
 * source: the capture page has no file input, so a picture taken earlier (or
 * borrowed from someone else) cannot be submitted in its place. That is the
 * whole point of the feature - a screenshot of a photo is worth nothing - so
 * nothing in this class offers an upload path.
 *
 * Three things carry the design:
 *
 *   - ONE PHOTOGRAPH PROVES ONE CHECK-IN. A sign-in and a sign-out are two
 *     separate claims about two separate moments, so the file hangs off the log
 *     row (itemid = multicheckin_log.id) rather than off the occurrence. A
 *     session with two points produces two photos per student.
 *
 *   - THE PHOTOGRAPH IS A HARD GATE. Nothing is written to multicheckin_log
 *     until a capture has been accepted, so "checked in" always means "checked
 *     in and photographed" for a photo occurrence. That is why the capture
 *     happens EARLIER than the recording (see the photo pass below) instead of
 *     being attached to a row that already exists.
 *
 *   - THERE IS A WAY OUT THAT IS NOT AN UPLOAD. A student whose camera is
 *     unavailable - permission refused, no camera, or the Moodle App, where
 *     getUserMedia does not work - can ask the teacher to verify them by hand.
 *     That request is the only alternative, and it is deliberately a request:
 *     the teacher decides, and the check-in it produces is recorded through the
 *     existing marking path, which already marks rows as manual and leaves the
 *     IP empty.
 *
 * THE PHOTO PASS. Credentials are checked before the capture, and a rotating QR
 * token is only valid for about fifteen seconds - far less than it takes to
 * frame and take a picture. So a successful credential check mints a short-lived
 * one-off pass, held server-side in the session, that authorises the capture
 * page and the recording that follows it. The pass records which occurrence and
 * point it was minted for and the position that satisfied the fence; every gate
 * is then re-run at recording time, so a pass cannot be used to check in after
 * the point has closed.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class photo_manager {

    /** @var string Component name, for the file API. */
    public const COMPONENT = 'mod_multicheckin';

    /** @var string File area holding the captured photographs. */
    public const FILEAREA = 'checkinphoto';

    /**
     * @var int Longest edge, in pixels, the browser is told to shrink to.
     *
     * 2048, raised from 1600 in 2.0.20 (and from 960 in 2.0.19). At 960 the
     * point name on a sign became a smudge, and the file was too small to read
     * either way. A 2048 px frame at quality 0.75 lands around 400-700 KB,
     * inside MAX_BYTES; anything that would not fit is re-encoded softer in the
     * browser before it is sent.
     *
     * Not full sensor resolution on purpose. A 4K frame has to be encoded at
     * quality 0.30 to fit a small ceiling, and JPEG block artefacts turn the
     * printed rows of a check-in sheet into mush: the bigger file is the less
     * readable one. Raising the ceiling and the edge together keeps the
     * quality that makes detail readable in the first place.
     */
    public const MAX_EDGE = 2048;

    /** @var float JPEG quality the browser encodes with. */
    public const JPEG_QUALITY = 0.75;

    /**
     * @var int Hard ceiling on the bytes a capture may carry.
     *
     * 768 KiB since 2.0.20 (512 KiB before it). It was raised together with
     * MAX_EDGE, not on its own: a 2048 px frame at quality 0.75 already needs
     * about 400-700 KB, and holding it at 512 KiB would have forced the
     * browser down to quality 0.55 for a picture that was asked to be sharper.
     * It is a ceiling on the POST, not on what the browser produces: the
     * browser steps the quality down until the frame fits, so nothing a
     * working camera sends arrives above this line. It exists to stop a
     * hand-composed request from handing the server a hundred megabytes to
     * decode - the base64 in the field is a third larger again, so a site
     * needs post_max_size and upload_max_filesize of at least 1.1 MiB for the
     * largest capture that can legitimately arrive.
     */
    public const MAX_BYTES = 786432;

    /** @var string The only type the capture path produces. */
    public const MIMETYPE = 'image/jpeg';

    /** @var string Filename every capture is stored under. */
    public const FILENAME = 'checkin.jpg';

    /** @var int The student is waiting for the teacher. */
    public const STATUS_PENDING = 0;

    /** @var int The teacher checked the student in by hand. */
    public const STATUS_APPROVED = 1;

    /** @var int The teacher refused the request. */
    public const STATUS_REFUSED = 2;

    /** @var int Seconds a capture pass stays valid. */
    public const PASS_TTL = 600;

    /** @var string Session key holding the permits of one ordinary browser. */
    public const PASS_SESSION_KEY = 'multicheckin_photopass';

    /** @var string Table holding permits that have to outlive one browser. */
    public const PASS_TABLE = 'multicheckin_pass';

    /** @var int Extra time a spent permit is kept before the sweep removes it. */
    public const PASS_PRUNE_GRACE = 86400;

    /** @var string The form field the capture page posts its blob under. */
    public const FIELD = 'photo';

    /** @var string Marker the capture page sets on its own submissions. */
    public const CAPTURED_FLAG = 'captured';

    /** @var string Prefix of the data URL the capture page posts back. */
    public const PAYLOAD_PREFIX = 'data:image/jpeg;base64,';

    /**
     * Whether this occurrence demands a photograph for every check-in.
     *
     * @param \stdClass $session Session record (template or occurrence).
     * @return bool
     */
    public static function is_required(\stdClass $session): bool {
        return !empty($session->photorequired);
    }

    /**
     * Whether the browser in front of us can be asked for a camera.
     *
     * The Moodle App renders plugin pages in an in-app browser where
     * getUserMedia is not available, so a student arriving that way would be
     * shown a camera that never starts. They are sent straight to the
     * by-hand route instead, with the reason spelled out.
     *
     * @return bool
     */
    public static function capture_available(): bool {
        return !\core_useragent::is_moodle_app();
    }

    /**
     * The in-app browser the student is reading this page in, if its camera
     * layer is not the device's own.
     *
     * All four of these are WebViews that bring a camera implementation with
     * them. The same phone and the same page give a normal picture in Safari or
     * Chrome and a cropped one inside them, and nothing the page sends changes
     * that - so the page says so instead of pretending the picture is right.
     *
     * Two boundaries decide whether this is useful or merely noisy:
     *
     * - Enterprise WeChat calls itself BOTH `MicroMessenger` and `wxwork`, so
     *   it has to be tested first, or the hint names the wrong app.
     * - Phone QQ sends `QQ/`, while the standalone QQ *Browser* sends
     *   `MQQBrowser` and no `QQ/` at all - and that browser takes perfectly
     *   normal photographs. Matching the engine name would warn a student whose
     *   browser is fine, which is the one outcome worse than saying nothing.
     *
     * @param string|null $useragent Agent to inspect; this request's by default.
     * @return string|null One of 'wework', 'wechat', 'dingtalk', 'qq'; null if unrestricted.
     */
    public static function restricted_host(?string $useragent = null): ?string {
        if ($useragent === null) {
            $useragent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        }
        $agent = strtolower((string) $useragent);
        if ($agent === '') {
            return null;
        }
        if (strpos($agent, 'wxwork') !== false) {
            return 'wework';
        }
        if (strpos($agent, 'micromessenger') !== false) {
            return 'wechat';
        }
        if (strpos($agent, 'dingtalk') !== false) {
            return 'dingtalk';
        }
        // `mqqbrowser` on its own is the QQ Browser, which is a normal browser.
        if (strpos($agent, 'qq/') !== false) {
            return 'qq';
        }
        return null;
    }

    /**
     * Whether a permit issued to this request has to be readable from a second
     * browser.
     *
     * Only WeChat. That is deliberately narrower than restricted_host(), and
     * the narrower line is the point: the table exists to rescue one specific
     * dead end, not to become the place permits are kept.
     *
     * WeChat's in-app browser has no menu item that reopens the page in the
     * real browser, so copying the address is the only way out of its cropped
     * camera - and that copy is worthless unless the permit survives the change
     * of browser. Enterprise WeChat, DingTalk and phone QQ all do offer "open
     * in browser", so the student leaves through the menu, the session is kept
     * along the way, and no row is written for them.
     *
     * One comparison is enough because restricted_host() answers 'wework' for
     * Enterprise WeChat before it looks at the MicroMessenger token that app
     * also sends; every other restricted host answers with its own name.
     *
     * @param string|null $useragent Agent to inspect; this request's by default.
     * @return bool True when the permit must be shared rather than private.
     */
    public static function pass_must_be_shared(?string $useragent = null): bool {
        return self::restricted_host($useragent) === 'wechat';
    }

    /**
     * The context the photographs live in.
     *
     * An occurrence is an extra row in the same table as its template and has no
     * course module of its own, so the file area hangs off the TEMPLATE's module
     * context - the same one every other page for this activity already uses.
     * The resolver is shared with the leave notes and the occurrence
     * descriptions: three file areas, one answer to "which context is this".
     *
     * @param \stdClass $session Session record (template or occurrence).
     * @return \context Module context.
     */
    public static function context_for_session(\stdClass $session): \context {
        return leave_manager::context_for_session($session);
    }

    /**
     * Module context for an occurrence, or null when it cannot be resolved.
     *
     * Used by the cleanup paths, which run while the row is being deleted and
     * so cannot look the context up from it.
     *
     * @param int $sessionid Occurrence id.
     * @return \context|null
     */
    public static function safe_context(int $sessionid): ?\context {
        return leave_manager::safe_context($sessionid);
    }

    /**
     * The line burned into the corner of a capture.
     *
     * Date and time come from the SERVER, not from the device: a phone with a
     * wrong clock would otherwise stamp a photograph with a convenient time, and
     * the point of the stamp is to make an old photograph hard to pass off as a
     * new one. The course and point names are there so a photograph lifted out
     * of the page still says what it was evidence for.
     *
     * @param \stdClass $session Session record.
     * @param \stdClass $point Point record.
     * @param \stdClass $course Course record.
     * @param int|null $now Timestamp to print, defaults to now.
     * @return string
     */
    public static function watermark_text(\stdClass $session, \stdClass $point, \stdClass $course,
            ?int $now = null): string {
        $now = $now ?? time();

        return implode('  ', [
            userdate($now, get_string('strftimedatetimeshort')),
            format_string($course->fullname),
            format_string(point_name::display($point)),
        ]);
    }

    /**
     * Check a posted capture before anything is written.
     *
     * The capture page has no file input and posts no multipart body at all: it
     * puts the JPEG into an ordinary form field as a data URL. So "there is no
     * way to upload a file instead of taking one" is not a rule this code
     * remembers to enforce - it is a shape the request simply does not have.
     * What is left to check is that the payload really is a JPEG the page could
     * have produced: a PNG, a PDF, a twelve-megapixel original or a stray string
     * all fail here.
     *
     * What this CANNOT do is prove the pixels came from a camera a moment ago. A
     * determined student can compose the same POST by hand, and no browser API
     * can tell a live capture from a file carefully shaped to look like one. The
     * feature stops deliberately at three things: no upload control anywhere in
     * the interface, a pass only this browser's own attempt can hold, and a
     * shape so narrow that nothing but a canvas capture gets through. The rest
     * is what the teacher's by-hand review is for.
     *
     * @param string $payload Data URL posted in the capture field.
     * @return array{ok: bool, reason: string, edge: int, binary: string} Verdict, reason, longest edge, decoded bytes.
     */
    public static function validate_capture(string $payload): array {
        $prefix = self::PAYLOAD_PREFIX;
        if (strpos($payload, $prefix) !== 0) {
            return ['ok' => false, 'reason' => 'photonotjpeg', 'edge' => 0, 'binary' => ''];
        }

        // Cheap ceiling before decoding: base64 is four characters per three
        // bytes, so anything longer than that cannot be within the size limit,
        // and decoding it first would just be work handed to us by an attacker.
        $encoded = strlen($payload) - strlen($prefix);
        if ($encoded <= 0 || $encoded > (int) ceil(self::MAX_BYTES * 4 / 3) + 4) {
            return ['ok' => false, 'reason' => 'phototoobig', 'edge' => 0, 'binary' => ''];
        }

        $binary = base64_decode(substr($payload, strlen($prefix)), true);
        if ($binary === false || $binary === '') {
            return ['ok' => false, 'reason' => 'photonotimage', 'edge' => 0, 'binary' => ''];
        }

        if (strlen($binary) > self::MAX_BYTES) {
            return ['ok' => false, 'reason' => 'phototoobig', 'edge' => 0, 'binary' => ''];
        }

        $info = @getimagesizefromstring($binary);
        if (!$info || empty($info[0]) || empty($info[1])) {
            return ['ok' => false, 'reason' => 'photonotimage', 'edge' => 0, 'binary' => ''];
        }

        if ((int) ($info[2] ?? 0) !== IMAGETYPE_JPEG || (string) ($info['mime'] ?? '') !== self::MIMETYPE) {
            return ['ok' => false, 'reason' => 'photonotjpeg', 'edge' => 0, 'binary' => ''];
        }

        $edge = max((int) $info[0], (int) $info[1]);
        if ($edge > self::MAX_EDGE) {
            return ['ok' => false, 'reason' => 'phototoowide', 'edge' => $edge, 'binary' => ''];
        }

        return ['ok' => true, 'reason' => '', 'edge' => $edge, 'binary' => $binary];
    }

    /**
     * Write a validated capture against a check-in log row.
     *
     * Straight into the real file area, with no draft area in between. That is
     * not merely shorter: a draft area is a place where a file the browser
     * already had can be parked, so routing the capture through one would put
     * back the very door this feature exists to close. The log row is written
     * first because its id IS the itemid; the caller rolls that row back if this
     * throws, so a photograph that cannot be stored never leaves a check-in
     * behind it.
     *
     * @param \stdClass $session Session record.
     * @param int $logid multicheckin_log.id.
     * @param string $binary The decoded JPEG.
     * @return \stored_file|null The stored photograph.
     */
    public static function store_for_log(\stdClass $session, int $logid, string $binary): ?\stored_file {
        $context = self::context_for_session($session);
        $fs = get_file_storage();

        // One photograph per check-in, always: a replayed request must replace
        // rather than accumulate, and in any case a log id is used only once.
        $fs->delete_area_files($context->id, self::COMPONENT, self::FILEAREA, $logid);

        $record = [
            'contextid' => $context->id,
            'component' => self::COMPONENT,
            'filearea' => self::FILEAREA,
            'itemid' => $logid,
            'filepath' => '/',
            'filename' => self::FILENAME,
        ];

        return $fs->create_file_from_string($record, $binary);
    }

    /**
     * The photograph attached to a check-in, if there is one.
     *
     * @param \stdClass $session Session record.
     * @param int $logid multicheckin_log.id.
     * @return \stored_file|null
     */
    public static function for_log(\stdClass $session, int $logid): ?\stored_file {
        try {
            $context = self::context_for_session($session);
        } catch (\moodle_exception $e) {
            return null;
        }

        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, self::COMPONENT, self::FILEAREA, $logid,
            'sortorder, id', false);

        return $files ? reset($files) : null;
    }

    /**
     * The pluginfile URL of a check-in's photograph.
     *
     * The filename is kept in the URL so a browser shows something meaningful in
     * its tab and a download keeps its extension; pluginfile ignores it when
     * locating the file, but it must match the stored name for the link to
     * survive a strict check.
     *
     * @param \stdClass $session Session record.
     * @param int $logid multicheckin_log.id.
     * @return \moodle_url|null
     */
    public static function photo_url(\stdClass $session, int $logid): ?\moodle_url {
        $file = self::for_log($session, $logid);
        if (!$file) {
            return null;
        }

        return self::url_for_file($file);
    }

    /**
     * The pluginfile URL of one stored photograph.
     *
     * Separated from photo_url() because the bulk readers below hand back
     * stored_file objects that were found in one sweep, and looking each of
     * them up again by log id would undo the saving.
     *
     * @param \stored_file $file The photograph.
     * @return \moodle_url
     */
    public static function url_for_file(\stored_file $file): \moodle_url {
        return \moodle_url::make_pluginfile_url($file->get_contextid(), self::COMPONENT, self::FILEAREA,
            $file->get_itemid(), $file->get_filepath(), $file->get_filename());
    }

    /**
     * A link that opens one photograph in a new tab.
     *
     * Every place that shows a photograph goes through here, so the target and
     * the rel attribute cannot drift apart between the roster, the report and
     * the marking sheet. rel=noopener is not decoration: without it the opened
     * page can reach back into this one.
     *
     * @param \stored_file $file The photograph.
     * @param string $label Link text, defaulting to a camera glyph.
     * @return string HTML.
     */
    public static function link(\stored_file $file, string $label = ''): string {
        if ($label === '') {
            $label = get_string('photo_view', 'mod_multicheckin');
        }

        return \html_writer::link(self::url_for_file($file), $label, [
            'target' => '_blank',
            'rel' => 'noopener',
            'class' => 'multicheckin-photo-link',
            'title' => get_string('photo_view', 'mod_multicheckin'),
        ]);
    }

    /**
     * Every photograph of one occurrence, keyed by user and then by point.
     *
     * The whole sheet in a constant number of queries. A marking table cannot
     * afford a file-area lookup per cell, and the file area belongs to this
     * activity alone, so all of it can be listed once and sorted out in memory
     * against the log rows already being read.
     *
     * @param \stdClass $session Session record.
     * @param int[] $userids Students to include.
     * @return array<int, array<int, \stored_file>> [userid][pointid] => photograph.
     */
    public static function for_session_users(\stdClass $session, array $userids): array {
        global $DB;

        $userids = array_values(array_unique(array_map('intval', $userids)));
        if (!$userids) {
            return [];
        }

        try {
            $context = self::context_for_session($session);
        } catch (\moodle_exception $e) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'photouser');
        $logs = $DB->get_records_select('multicheckin_log',
            "sessionid = :sessionid AND userid {$insql}",
            $inparams + ['sessionid' => (int) $session->id], '', 'id,userid,pointid');
        if (!$logs) {
            return [];
        }

        $bylog = [];
        foreach ($logs as $log) {
            $bylog[(int) $log->id] = $log;
        }

        $files = get_file_storage()->get_area_files($context->id, self::COMPONENT, self::FILEAREA,
            false, 'sortorder, id', false);

        $out = [];
        foreach ($files as $file) {
            $log = $bylog[(int) $file->get_itemid()] ?? null;
            if (!$log) {
                continue;
            }
            $out[(int) $log->userid][(int) $log->pointid] = $file;
        }

        return $out;
    }

    /**
     * Whether this user may look at a check-in's photograph.
     *
     * A face is personal data, so the rule is the same narrow one the leave
     * notes use: the student it belongs to, or a teacher who can already read
     * the activity's reports. "Enrolled in the course" is not enough, and
     * neither is "may view the activity".
     *
     * @param int $logid multicheckin_log.id.
     * @param int $userid User asking for the file.
     * @param \context $context Module context of the activity.
     * @return bool
     */
    public static function can_view(int $logid, int $userid, \context $context): bool {
        global $DB;

        $log = $DB->get_record('multicheckin_log', ['id' => $logid], 'id,userid');
        if (!$log) {
            return false;
        }

        if ((int) $log->userid === $userid) {
            return true;
        }

        return has_any_capability(
            ['mod/multicheckin:manage', 'mod/multicheckin:viewreports'], $context);
    }

    // -----------------------------------------------------------------------
    // Capture passes.
    // -----------------------------------------------------------------------

    /**
     * Mint a pass authorising one capture for one point.
     *
     * Two stores, one decision, and the decision is pass_must_be_shared().
     * The permit goes into the session in every browser except one: inside
     * WeChat it goes into a table, because only a table is reachable from the
     * browser the student is being sent to.
     *
     * That split is not an optimisation. A session entry can be read by the
     * browser that created it and by no other, which is fine while the same
     * browser also takes the picture. It stops being fine inside WeChat: the
     * camera there crops the frame, the page tells the student to copy the
     * address into a real browser, and that browser has a different session -
     * so it reported the permit as expired, immediately and every time, and
     * the advice could never have worked.
     *
     * Every other in-app browser keeps the session, because none of them needs
     * the table: their menus reopen the page in the real browser and carry the
     * cookie across. Ordinary check-ins therefore leave no row behind at all,
     * which is also why this table cannot slowly become a log of every camera
     * the site ever opened.
     *
     * The rest of the class then stops caring which store was used. read_pass()
     * looks in both, clear_pass() clears both, and no caller passes a flag
     * saying what kind of browser it is in - a caller that did would be a
     * second place for this decision, and the second place is the one that
     * gets forgotten.
     *
     * Two columns carry the security rather than merely the data:
     *
     *   - tokenhash, not the token. A copy of this table yields no working
     *     link, and the lookup is still one unique index hit.
     *   - userid. A permit that crosses browsers is a permit that can be
     *     forwarded, so it names its owner and read_pass() refuses everybody
     *     else. While the permit lived in a session this came free; a row has
     *     to say it out loud.
     *
     * The position that satisfied the fence rides along in both stores, so the
     * recording step can re-run the same verdict with the same numbers instead
     * of accepting a check-in on the strength of a check made minutes ago.
     *
     * @param \stdClass $session Session record.
     * @param \stdClass $point Point record.
     * @param string $method self|qr|password.
     * @param array|null $clientgeo Position that passed the fence, if any.
     * @param int|null $userid Owner; the current user when omitted.
     * @return string The token to hand back in the URL.
     */
    public static function issue_pass(\stdClass $session, \stdClass $point, string $method,
            ?array $clientgeo = null, ?int $userid = null): string {
        global $DB, $USER;

        $userid = $userid ?? (int) $USER->id;
        $token = random_string(32);
        $now = time();
        $held = [
            'userid' => $userid,
            'sessionid' => (int) $session->id,
            'pointid' => (int) $point->id,
            'method' => $method,
            'clientgeo' => $clientgeo,
            'expires' => $now + self::PASS_TTL,
        ];

        if (!self::pass_must_be_shared()) {
            self::write_session_pass($token, $held, $now);
            return $token;
        }

        // Permits this student left behind are dropped on the way in, as they
        // were when every permit lived in the session: a page opened all
        // morning must not leave a usable key in the table. Scoped to this
        // user on purpose - signing in and signing out are two points and two
        // permits, and minting one must not cancel the other.
        foreach ($DB->get_records(self::PASS_TABLE, ['userid' => $userid]) as $row) {
            if (!self::is_live((int) $row->expires, $now)) {
                $DB->delete_records(self::PASS_TABLE, ['id' => (int) $row->id]);
            }
        }

        $DB->insert_record(self::PASS_TABLE, (object) [
            'tokenhash' => self::hash_token($token),
            'userid' => $userid,
            'sessionid' => $held['sessionid'],
            'pointid' => $held['pointid'],
            'method' => $method,
            'clientgeo' => $clientgeo === null ? null : json_encode($clientgeo),
            'expires' => $held['expires'],
            'timecreated' => $now,
        ]);

        return $token;
    }

    /**
     * Read a pass back, or null when it is unknown, spent or belongs elsewhere.
     *
     * Both stores are consulted, because the browser reading the permit need
     * not be the browser that wrote it: one minted inside WeChat is read by
     * Safari seconds later. The session is tried first only because it is the
     * common case and costs no query - the answer does not depend on which
     * store supplied it.
     *
     * The owner check is not a hardening of the old behaviour, it replaces
     * what the session used to provide for free. A permit found in the table
     * is reachable from any browser the student is signed into, so without the
     * check a forwarded link would check in an absent classmate.
     *
     * An expired permit is deleted where it is found rather than left for the
     * sweep: this is the only moment anything knows it is dead, and it costs
     * one delete on a primary key - or one unset on an array the next issuance
     * would rewrite anyway.
     *
     * @param string $token Token from the URL.
     * @param int $sessionid Occurrence it must belong to.
     * @param int $pointid Point it must belong to.
     * @param int|null $userid Owner it must belong to; the current user when omitted.
     * @return array|null
     */
    public static function read_pass(string $token, int $sessionid, int $pointid,
            ?int $userid = null): ?array {
        global $DB, $USER;

        $userid = $userid ?? (int) $USER->id;
        $now = time();

        $passes = self::session_passes();
        if (isset($passes[$token]) && is_array($passes[$token])) {
            $held = $passes[$token];
            if (!self::is_live((int) ($held['expires'] ?? 0), $now)) {
                // Dropped, then allowed to fall through. A token cannot
                // legitimately be in both stores, but if one ever were, a dead
                // session entry must not hide the live row that could answer.
                unset($passes[$token]);
                self::store_session_passes($passes);
            } else {
                return self::pass_matches($held, $userid, $sessionid, $pointid) ? $held : null;
            }
        }

        $row = $DB->get_record(self::PASS_TABLE, ['tokenhash' => self::hash_token($token)]);
        if (!$row) {
            return null;
        }
        if (!self::is_live((int) $row->expires, $now)) {
            $DB->delete_records(self::PASS_TABLE, ['id' => (int) $row->id]);
            return null;
        }

        $held = [
            'userid' => (int) $row->userid,
            'sessionid' => (int) $row->sessionid,
            'pointid' => (int) $row->pointid,
            'method' => (string) $row->method,
            'clientgeo' => self::decode_geo($row->clientgeo ?? null),
            'expires' => (int) $row->expires,
        ];
        if (!self::pass_matches($held, $userid, $sessionid, $pointid)) {
            return null;
        }

        return $held;
    }

    /**
     * Drop one pass, once the check-in it authorised has been written.
     *
     * Both stores always, and the caller does not say which. photo.php runs
     * this from whichever browser took the picture, which may not be the one
     * that minted the permit, so "clear it wherever it is" is the only form
     * that is right on both paths. Leaving the session copy behind would also
     * make a spent permit usable again from the browser that started it.
     *
     * By token, not by row id: the caller holds the string from the URL and
     * nothing else. Exactly one row can match - tokenhash is unique.
     *
     * @param string $token Token to forget.
     * @return void
     */
    public static function clear_pass(string $token): void {
        global $DB;

        $passes = self::session_passes();
        if (isset($passes[$token])) {
            unset($passes[$token]);
            self::store_session_passes($passes);
        }

        $DB->delete_records(self::PASS_TABLE, ['tokenhash' => self::hash_token($token)]);
    }

    /**
     * Drop every outstanding pass of one user.
     *
     * Both stores, for the reason clear_pass() does: one student's permits can
     * be split across them, and a clear that misses one store leaves a link
     * working after the attempt it belongs to was abandoned.
     *
     * @param int|null $userid Whose permits to drop; the current user when omitted.
     * @return void
     */
    public static function clear_passes(?int $userid = null): void {
        global $DB, $USER;

        $userid = $userid ?? (int) $USER->id;

        $passes = self::session_passes();
        foreach ($passes as $token => $held) {
            if ((int) ($held['userid'] ?? 0) === $userid) {
                unset($passes[$token]);
            }
        }
        self::store_session_passes($passes);

        $DB->delete_records(self::PASS_TABLE, ['userid' => $userid]);
    }

    /**
     * Remove table permits that expired long enough ago that nobody will ask.
     *
     * Only the table. Session entries are cleaned where they are written and
     * where they are read, and the session itself is discarded by the browser
     * or by Moodle, so there is nothing here for a task to sweep.
     *
     * A permit is refused the moment it expires, so everything this removes is
     * already dead weight: what is left is the row of a student who started a
     * photo check-in and never came back, which nothing else would ever visit.
     *
     * The grace period is what keeps this from racing a request in flight: a
     * permit that expired one second ago may still be the subject of a page
     * loaded a minute ago, and deleting it under that page would turn a clear
     * "expired" into an equally clear "unknown".
     *
     * Read row by row rather than issuing "expires < :cut" as SQL. The table
     * holds a few hundred rows at most, and a hand-written comparison inside a
     * test double is exactly how a test starts passing against a statement the
     * real database would reject.
     *
     * @param int $grace Seconds past expiry to keep a row.
     * @return int How many rows were removed.
     */
    public static function prune_passes(int $grace = self::PASS_PRUNE_GRACE): int {
        global $DB;

        $now = time();
        $removed = 0;
        foreach ($DB->get_records(self::PASS_TABLE) as $row) {
            if ((int) $row->expires < $now - $grace) {
                $DB->delete_records(self::PASS_TABLE, ['id' => (int) $row->id]);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Keep a permit in this browser's session, dropping spent ones on the way.
     *
     * The sweep is here rather than in a task because the session is the one
     * store no cron can reach: it belongs to a cookie, not to a table. Permits
     * accumulate only for a student who keeps re-opening the camera, so
     * cleaning at the point of write is enough.
     *
     * @param string $token Token the URL will carry.
     * @param array $held Permit record.
     * @param int $now Current time.
     * @return void
     */
    private static function write_session_pass(string $token, array $held, int $now): void {
        $passes = self::session_passes();
        foreach ($passes as $existing => $record) {
            if (!is_array($record) || !self::is_live((int) ($record['expires'] ?? 0), $now)) {
                unset($passes[$existing]);
            }
        }
        $passes[$token] = $held;
        self::store_session_passes($passes);
    }

    /**
     * The permits this browser is holding, or an empty array.
     *
     * @return array
     */
    private static function session_passes(): array {
        global $SESSION;

        if (!is_object($SESSION) || !isset($SESSION->{self::PASS_SESSION_KEY})) {
            return [];
        }
        $passes = $SESSION->{self::PASS_SESSION_KEY};
        return is_array($passes) ? $passes : [];
    }

    /**
     * Write the permit list back to the session.
     *
     * Guarded rather than assumed: read_pass() and clear_pass() are called
     * from pages that may be reached without a session in edge cases (a
     * restored request, a CLI harness), and a missing session must degrade to
     * "nothing was stored" rather than a fatal.
     *
     * @param array $passes Permits to keep.
     * @return void
     */
    private static function store_session_passes(array $passes): void {
        global $SESSION;

        if (!is_object($SESSION)) {
            return;
        }
        $SESSION->{self::PASS_SESSION_KEY} = $passes;
    }

    /**
     * Whether a permit recorded for one owner, occurrence and point.
     *
     * One function for both stores, so the rules cannot drift apart between
     * the session copy and the table row.
     *
     * @param array $held Permit record.
     * @param int $userid Owner asking for it.
     * @param int $sessionid Occurrence it must belong to.
     * @param int $pointid Point it must belong to.
     * @return bool
     */
    private static function pass_matches(array $held, int $userid, int $sessionid, int $pointid): bool {
        return (int) ($held['userid'] ?? 0) === $userid
            && (int) ($held['sessionid'] ?? 0) === $sessionid
            && (int) ($held['pointid'] ?? 0) === $pointid;
    }

    /**
     * Whether a permit has not run out.
     *
     * One comparison for the three places that ask - the write that sweeps,
     * the read that drops, and the sweep itself - so an off-by-one cannot be
     * corrected in two of them and left standing in the third.
     *
     * @param int $expires Permit expiry.
     * @param int $now Current time.
     * @return bool
     */
    private static function is_live(int $expires, int $now): bool {
        return $expires >= $now;
    }

    /**
     * The stored form of a token.
     *
     * @param string $token Token from the URL.
     * @return string Hex sha256.
     */
    private static function hash_token(string $token): string {
        return hash('sha256', $token);
    }

    /**
     * Turn the stored position back into the array the fence expects.
     *
     * @param string|null $stored JSON, or null when no position was carried.
     * @return array|null
     */
    private static function decode_geo(?string $stored): ?array {
        if ($stored === null || $stored === '') {
            return null;
        }
        $decoded = json_decode($stored, true);
        return is_array($decoded) ? $decoded : null;
    }

    // -----------------------------------------------------------------------
    // By-hand verification requests.
    // -----------------------------------------------------------------------

    /**
     * One student's request for one point, or null when there is none.
     *
     * @param int $sessionid Occurrence id.
     * @param int $pointid Point id.
     * @param int $userid Student id.
     * @return \stdClass|null
     */
    public static function record_for(int $sessionid, int $pointid, int $userid): ?\stdClass {
        global $DB;

        $row = $DB->get_record('multicheckin_photoreq', [
            'sessionid' => $sessionid,
            'pointid' => $pointid,
            'userid' => $userid,
        ]);

        return $row ?: null;
    }

    /**
     * Raise (or re-raise) a request.
     *
     * A request the teacher already decided is NOT silently reset by a second
     * submit: the student sees the decision and, if it was refused, the record
     * is put back in the queue with the reason they gave. Re-opening it is the
     * only way a refused student can be looked at again, since they cannot
     * simply take another photograph - there is no camera to take one with,
     * which is why they are here.
     *
     * @param \stdClass $session Session record.
     * @param \stdClass $point Point record.
     * @param int $userid Student id.
     * @param string $reason Free-text reason (may be empty).
     * @return \stdClass The stored request row.
     */
    public static function request(\stdClass $session, \stdClass $point, int $userid,
            string $reason = ''): \stdClass {
        global $DB;

        $now = time();
        $existing = self::record_for((int) $session->id, (int) $point->id, $userid);

        if ($existing) {
            if ((int) $existing->status !== self::STATUS_PENDING) {
                $DB->update_record('multicheckin_photoreq', (object) [
                    'id' => (int) $existing->id,
                    'status' => self::STATUS_PENDING,
                    'reason' => $reason,
                    'reviewedby' => 0,
                    'timereviewed' => 0,
                    'timemodified' => $now,
                ]);
                $existing->status = self::STATUS_PENDING;
                $existing->reason = $reason;
            }

            return $existing;
        }

        $row = (object) [
            'sessionid' => (int) $session->id,
            'pointid' => (int) $point->id,
            'userid' => $userid,
            'reason' => $reason,
            'status' => self::STATUS_PENDING,
            'reviewedby' => 0,
            'timereviewed' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $row->id = $DB->insert_record('multicheckin_photoreq', $row);

        return $row;
    }

    /**
     * Record the teacher's decision.
     *
     * @param int $requestid Request row id.
     * @param int $status STATUS_APPROVED or STATUS_REFUSED.
     * @param int $byuserid Teacher id.
     * @return bool True when the row was found and updated.
     */
    public static function decide(int $requestid, int $status, int $byuserid): bool {
        global $DB;

        if (!in_array($status, [self::STATUS_APPROVED, self::STATUS_REFUSED], true)) {
            return false;
        }

        if (!$DB->record_exists('multicheckin_photoreq', ['id' => $requestid])) {
            return false;
        }

        $now = time();
        $DB->update_record('multicheckin_photoreq', (object) [
            'id' => $requestid,
            'status' => $status,
            'reviewedby' => $byuserid,
            'timereviewed' => $now,
            'timemodified' => $now,
        ]);

        return true;
    }

    /**
     * Approve a request AND record the check-in it stands for.
     *
     * The decision and the record are one action on purpose. A request marked
     * "passed" that left the student still absent would be a promise the page
     * did not keep: the teacher has just declared the student was there, and
     * the only thing left to do with that declaration is to write it down.
     *
     * The row goes in through the same recorder every other check-in uses, with
     * the method 'manual', so a check-in produced this way is indistinguishable
     * from one a teacher marked by hand on the roster - which is exactly what
     * it is. It is deliberately NOT written as a photograph check-in: no
     * capture exists, and pretending one does would make the report claim
     * evidence that was never collected.
     *
     * Recording is skipped when the student already holds a row for that point,
     * so a second click - or a teacher who marked them by hand first - cannot
     * produce a duplicate.
     *
     * @param int $requestid Request row id.
     * @param int $byuserid Teacher id.
     * @return bool True when the request was found and approved.
     */
    public static function approve(int $requestid, int $byuserid): bool {
        global $DB;

        $request = $DB->get_record('multicheckin_photoreq', ['id' => $requestid]);
        if (!$request) {
            return false;
        }

        if (!self::decide($requestid, self::STATUS_APPROVED, $byuserid)) {
            return false;
        }

        $session = $DB->get_record('multicheckin', ['id' => (int) $request->sessionid]);
        $point = $DB->get_record('multicheckin_point', ['id' => (int) $request->pointid]);
        if (!$session || !$point) {
            // The request outlived its point. The decision still stands; there
            // is simply nothing left to write the check-in against.
            return true;
        }

        $exists = $DB->record_exists('multicheckin_log', [
            'sessionid' => (int) $session->id,
            'pointid' => (int) $point->id,
            'userid' => (int) $request->userid,
        ]);
        if (!$exists) {
            session_manager::record_checkin($session, $point, (int) $request->userid, 'manual');

            // One approval writes one row for one student, so one status is what
            // has to be derived and one grade is what can have moved. Recomputing
            // the class here made the cost of a single click depend on how many
            // students the activity has, and it pushed no grades at all - the
            // gradebook kept showing the old figure until something else ran.
            checkin_engine::refresh_user($session, (int) $request->userid);
            grade_helper::update_grades($session, (int) $request->userid);
        }

        return true;
    }

    /**
     * Requests raised by one student in one occurrence, keyed by point id.
     *
     * @param int $sessionid Occurrence id.
     * @param int $userid Student id.
     * @return array<int, \stdClass>
     */
    public static function for_user(int $sessionid, int $userid): array {
        global $DB;

        $rows = $DB->get_records('multicheckin_photoreq', [
            'sessionid' => $sessionid,
            'userid' => $userid,
        ]);
        $keyed = [];
        foreach ($rows as $row) {
            $keyed[(int) $row->pointid] = $row;
        }

        return $keyed;
    }

    /**
     * How many requests of an activity are still waiting for a teacher.
     *
     * Counted across the whole activity rather than one occurrence, because the
     * review list is a queue for the teacher rather than a view of one date.
     *
     * @param int $templateid Activity template id.
     * @return int
     */
    public static function pending_count(int $templateid): int {
        global $DB;

        // A template can also be its own occurrence, so the reading has to accept
        // "this very row" and "a row that points at it". The two cannot share one
        // placeholder: core's fix_sql_params() counts the OCCURRENCES of each
        // name - ":templateid ... :templateid" counts as two - and compares that
        // total against the number of entries in $params. A repeated name is
        // therefore read as a missing argument and the page dies with
        // invalidqueryparam ("expected 3, got 2"), which is exactly what a
        // two-branch OR written with one name produces.
        $sql = "SELECT COUNT(r.id)
                  FROM {multicheckin_photoreq} r
                  JOIN {multicheckin} s ON s.id = r.sessionid
                 WHERE (s.id = :templateid OR s.parentid = :parentid)
                   AND r.status = :status";

        return (int) $DB->count_records_sql($sql, [
            'templateid' => $templateid,
            'parentid' => $templateid,
            'status' => self::STATUS_PENDING,
        ]);
    }

    /**
     * Every request of an activity, across all of its occurrences.
     *
     * Waiting requests come first, which is what makes the page usable as a
     * queue: the teacher works down the list and stops when the head is empty.
     *
     * @param int $templateid Activity template id.
     * @param bool $pendingonly Only the requests nobody has decided yet.
     * @return array Request rows, waiting first then newest first.
     */
    public static function for_template(int $templateid, bool $pendingonly = false): array {
        global $DB;

        // Two names for the same id, for the reason spelled out in pending_count():
        // a placeholder that appears twice is counted twice by core and the
        // remaining parameters stop adding up.
        $sql = "SELECT r.*
                  FROM {multicheckin_photoreq} r
                  JOIN {multicheckin} s ON s.id = r.sessionid
                 WHERE (s.id = :templateid OR s.parentid = :parentid)";
        if ($pendingonly) {
            $sql .= ' AND r.status = :status';
        }
        $sql .= ' ORDER BY r.status ASC, r.timecreated DESC';

        $params = ['templateid' => $templateid, 'parentid' => $templateid];
        if ($pendingonly) {
            $params['status'] = self::STATUS_PENDING;
        }

        return $DB->get_records_sql($sql, $params) ?: [];
    }

    /**
     * Drop the photographs and requests of one occurrence.
     *
     * Used when an occurrence is deleted, so no orphan rows or files are left
     * behind. The photographs are found through the log rows, because a log row
     * id is what the file area is keyed by; deleting the row first would leave
     * the files unreachable and undeletable, which is why the callers run this
     * BEFORE the delete. The context is resolved defensively: this also runs
     * inside course-module deletion, where the module row may already be gone.
     *
     * @param int $sessionid Occurrence id.
     * @return void
     */
    public static function delete_all_for_session(int $sessionid): void {
        global $DB;

        $logs = $DB->get_records('multicheckin_log', ['sessionid' => $sessionid], '', 'id');
        $context = self::safe_context($sessionid);
        if ($logs && $context) {
            $fs = get_file_storage();
            foreach ($logs as $log) {
                $fs->delete_area_files($context->id, self::COMPONENT, self::FILEAREA, (int) $log->id);
            }
        }

        $DB->delete_records('multicheckin_photoreq', ['sessionid' => $sessionid]);
    }

    /**
     * Drop one student's photographs and requests in one occurrence.
     *
     * The privacy API needs a targeted delete; the cleanup paths work per
     * occurrence, and a request to erase one person cannot wait for the whole
     * activity to go.
     *
     * @param int $sessionid Occurrence id.
     * @param int $userid User id.
     * @return void
     */
    public static function delete_for_user(int $sessionid, int $userid): void {
        global $DB;

        $logs = $DB->get_records('multicheckin_log', [
            'sessionid' => $sessionid,
            'userid' => $userid,
        ], '', 'id');
        $context = self::safe_context($sessionid);
        if ($logs && $context) {
            $fs = get_file_storage();
            foreach ($logs as $log) {
                $fs->delete_area_files($context->id, self::COMPONENT, self::FILEAREA, (int) $log->id);
            }
        }

        $DB->delete_records('multicheckin_photoreq', ['sessionid' => $sessionid, 'userid' => $userid]);
    }

    /**
     * Drop the photographs of individual check-in rows.
     *
     * Called wherever log rows are removed. The file area is keyed by the log
     * row's id, so a row that goes without this leaves a picture that nothing
     * can find and nothing can delete: the id it was filed under no longer
     * exists, and the only way back to it would be to wipe the whole area.
     *
     * @param \stdClass $session Session record the rows belong to.
     * @param int[] $logids Log row ids about to be deleted.
     * @return void
     */
    public static function delete_for_logs(\stdClass $session, array $logids): void {
        $logids = array_values(array_unique(array_map('intval', $logids)));
        if (!$logids) {
            return;
        }

        try {
            $context = self::context_for_session($session);
        } catch (\moodle_exception $e) {
            return;
        }

        $fs = get_file_storage();
        foreach ($logids as $logid) {
            $fs->delete_area_files($context->id, self::COMPONENT, self::FILEAREA, $logid);
        }
    }

    /**
     * Drop the photographs taken against one check-in point.
     *
     * Used when a point is removed - by the management page, or by a series
     * rebuild that trims a mid roll call away - because its log rows go with it.
     *
     * @param int $pointid Point id.
     * @return void
     */
    public static function delete_for_point(int $pointid): void {
        global $DB;

        $logs = $DB->get_records('multicheckin_log', ['pointid' => $pointid], '', 'id,sessionid');
        if (!$logs) {
            return;
        }

        $first = reset($logs);
        $context = self::safe_context((int) $first->sessionid);
        if (!$context) {
            return;
        }

        $fs = get_file_storage();
        foreach ($logs as $log) {
            $fs->delete_area_files($context->id, self::COMPONENT, self::FILEAREA, (int) $log->id);
        }
    }

    /**
     * Drop every photograph an activity owns.
     *
     * Used when the activity itself is deleted. Here the whole file area can go
     * in one call: the context belongs to this activity alone, so there is
     * nothing else to protect and no need to walk the log rows first.
     *
     * @param int $templateid Activity template id.
     * @return void
     */
    public static function delete_all_for_template(int $templateid): void {
        global $DB;

        $context = self::safe_context($templateid);
        if ($context) {
            get_file_storage()->delete_area_files($context->id, self::COMPONENT, self::FILEAREA);
        }

        $sql = "DELETE FROM {multicheckin_photoreq}
                 WHERE sessionid IN (
                       SELECT id FROM {multicheckin} WHERE id = :templateid OR parentid = :parentid)";
        $DB->execute($sql, ['templateid' => $templateid, 'parentid' => $templateid]);
    }

    /**
     * Human-readable label for a request status.
     *
     * @param int $status Status code.
     * @return string
     */
    public static function status_label(int $status): string {
        switch ($status) {
            case self::STATUS_APPROVED:
                return get_string('photoreq_approved', 'mod_multicheckin');
            case self::STATUS_REFUSED:
                return get_string('photoreq_refused', 'mod_multicheckin');
        }

        return get_string('photoreq_pending', 'mod_multicheckin');
    }

    /**
     * Bootstrap badge class for a request status.
     *
     * @param int $status Status code.
     * @return string
     */
    public static function status_class(int $status): string {
        switch ($status) {
            case self::STATUS_APPROVED:
                return 'badge bg-success';
            case self::STATUS_REFUSED:
                return 'badge bg-danger';
        }

        return 'badge bg-warning text-dark';
    }
}
