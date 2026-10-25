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

/**
 * Handles QR tokens for check-in points.
 *
 * The QR image encodes a signed, time-bounded token rather than a plain URL, so
 * a student cannot photograph a code and share it with an absent classmate: the
 * token expires every few seconds.
 *
 * That holds for every occurrence, including those that require a photograph.
 * Version 2.0.22 froze the code for those, on the reasoning that framing a shot
 * takes longer than one rotation - and 2.0.23 withdrew it, because the shot is
 * taken on photo.php, which reaches the camera through a permit in the address
 * and never reads the code again. Freezing it therefore protected nothing, and
 * gave up the one property the code exists for: a photograph of the screen is
 * worthless a few seconds later.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qr_manager {

    /** @var int Default QR token rotation period in seconds. */
    public const ROTATION = 15;

    /** @var int Hard lower bound for the configurable rotation period. */
    public const ROTATION_MIN = 5;

    /** @var int Hard upper bound for the configurable rotation period. */
    public const ROTATION_MAX = 300;

    /** @var int Length of the signed token in hex characters (128 bits). */
    public const TOKEN_LENGTH = 32;

    /**
     * Resolve the rotation period that applies to a session.
     *
     * A per-session value (qrrotation) wins; it is clamped to the allowed
     * range. Sessions created before this setting existed fall back to the
     * default constant, so behaviour is unchanged for existing data.
     *
     * @param \stdClass|null $session Session record, or null for the default.
     * @return int Seconds.
     */
    public static function rotation_for(?\stdClass $session = null): int {
        $value = ($session !== null && isset($session->qrrotation))
            ? (int) $session->qrrotation
            : self::ROTATION;
        return max(self::ROTATION_MIN, min(self::ROTATION_MAX, $value));
    }

    /**
     * Whether a point accepts QR check-ins only.
     *
     * Two switches feed this decision: the occurrence-level flag closes every
     * point of an occurrence at once, the point-level flag closes one point.
     * The answer is the OR of the two - a point is locked when EITHER says so.
     * That direction is deliberate: ticking a box may only ever tighten the
     * rule. A teacher who switches the occurrence on expects the whole class to
     * be locked, and a point that could opt back out would make the occurrence
     * switch a lie.
     *
     * This method exists so the decision is written ONCE. There are five places
     * that care - the two check-in paths in checkin.php, the student row in
     * view.php, and the two QR panels - and the version 2.0.9 review found the
     * cost of writing the same filter in more than one place: the "skip students
     * on leave" test lived in audience(), and the chain rule built its candidate
     * list without ever passing through audience(), so a student who went on
     * leave after the first check-in kept being reminded. Any caller that needs
     * to know this asks here.
     *
     * !empty() rather than a cast: rows written before the column existed, and
     * older backups being restored, simply have no qronly property, and they
     * must read as 0 - the behaviour they were saved under.
     *
     * @param \stdClass $session Session record.
     * @param \stdClass $point Point record.
     * @return bool True when only a scanned code may record this check-in.
     */
    public static function only_scan(\stdClass $session, \stdClass $point): bool {
        return !empty($session->qronly) || !empty($point->qronly);
    }

    /**
     * Ensure a point has a signing key; create one if missing.
     *
     * @param \stdClass $point Point record.
     * @return string The key.
     */
    public static function ensure_key(\stdClass $point): string {
        global $DB;

        if (!empty($point->qrcodekey)) {
            return $point->qrcodekey;
        }

        $key = bin2hex(random_bytes(16));
        $DB->set_field('multicheckin_point', 'qrcodekey', $key, ['id' => $point->id]);
        $point->qrcodekey = $key;
        return $key;
    }

    /**
     * Build the payload that a QR code should encode right now.
     *
     * @param \stdClass $session Session record.
     * @param \stdClass $point Point record.
     * @param int|null $now Current time.
     * @return string A URL including the signed token.
     */
    public static function build_payload(\stdClass $session, \stdClass $point, ?int $now = null): string {
        $now = $now ?? time();
        $slot = self::current_slot($now, self::rotation_for($session));
        $token = self::sign((int) $point->id, $slot, self::ensure_key($point));

        // The method parameter is what routes the scan into the 'qr' branch of
        // checkin.php. Without it the page falls back to its 'self' default, so
        // the token was never checked and the geofence exemption for a freshly
        // scanned code never applied. Keep it in step with checkin.php.
        $url = new \moodle_url('/mod/multicheckin/checkin.php', [
            'id' => $session->coursemodule ?? 0,
            'session' => (int) $session->id,
            'point' => (int) $point->id,
            'slot' => $slot,
            'token' => $token,
            'method' => 'qr',
        ]);
        return $url->out(false);
    }

    /**
     * Whether the browser says this request is a passive load of a URL rather
     * than a visit to it.
     *
     * The payload above is a URL that writes a check-in, and it cannot carry a
     * session key: the code is drawn on the TEACHER's screen, so there is no
     * key in it that belongs to the student who scans it. The signed, rotating
     * token is the credential instead. That leaves one shape of abuse the token
     * cannot answer: the URL dropped into a page somebody else controls as
     * `<img src="...">`, which records a check-in for every student who merely
     * opens that page, without any of them scanning anything.
     *
     * Modern browsers label every request with what it is for, and that label
     * is set by the browser rather than by the page, so it cannot be forged
     * from the embedding side. A scan is a top-level navigation and arrives as
     * `document`; `<img>` arrives as `image`. The check therefore refuses the
     * destinations that only a passive load can have.
     *
     * Deliberately one-sided. An empty header means either an older browser or
     * a WebView that does not send them, and those are allowed through: this
     * must never be the reason a student standing in front of the code cannot
     * check in. A browser cannot label a navigation `image`, so the refusal
     * list cannot catch an honest scan - it can only catch an embed.
     *
     * @return bool True when the request is a passive subresource load.
     */
    public static function passive_embed(): bool {
        $destination = isset($_SERVER['HTTP_SEC_FETCH_DEST'])
            ? strtolower(trim((string) $_SERVER['HTTP_SEC_FETCH_DEST']))
            : '';
        if ($destination === '') {
            return false;
        }

        return in_array($destination, [
            'image', 'script', 'style', 'font', 'audio', 'video', 'track',
            'object', 'embed', 'manifest',
        ], true);
    }

    /**
     * Current rotation slot number.
     *
     * @param int $now Current timestamp.
     * @param int|null $rotation Rotation period; resolved from config if null.
     * @return int
     */
    public static function current_slot(int $now, ?int $rotation = null): int {
        $rotation = $rotation ?? self::ROTATION;
        $rotation = max(self::ROTATION_MIN, min(self::ROTATION_MAX, $rotation));
        return (int) floor($now / $rotation);
    }

    /**
     * Sign a (pointid, slot) pair.
     *
     * The digest is truncated to TOKEN_LENGTH hex characters. A full sha256
     * HMAC is 64 characters, and every extra character is another byte the QR
     * symbol has to carry: at 64 characters the payload needed a version 9
     * symbol (53x53 modules), which is markedly harder for a phone camera to
     * lock onto when the code is projected. 128 bits is far beyond what an
     * attacker could brute-force inside one 15-second rotation slot, so the
     * truncation costs nothing and buys a whole version of density. Build and
     * validate both call this method, so the two sides cannot drift.
     *
     * @param int $pointid Point id.
     * @param int $slot Slot number.
     * @param string $key Signing key.
     * @return string Hex HMAC, TOKEN_LENGTH characters long.
     */
    public static function sign(int $pointid, int $slot, string $key): string {
        return substr(hash_hmac('sha256', $pointid . ':' . $slot, $key), 0, self::TOKEN_LENGTH);
    }

    /**
     * Validate a submitted token.
     *
     * A code accepts the current slot and the one before it, so a student who
     * scans as the image redraws is not thrown out. There is no third case any
     * more: 2.0.23 removed the frozen code that used to accept one slot and
     * only one, so the tolerance either applies or the request is refused.
     *
     * @param \stdClass $point Point record.
     * @param int $slot Submitted slot.
     * @param string $token Submitted token.
     * @param int|null $now Current time.
     * @param \stdClass|null $session Session record (for the rotation period).
     * @return bool
     */
    public static function validate(\stdClass $point, int $slot, string $token,
            ?int $now = null, ?\stdClass $session = null): bool {
        $now = $now ?? time();
        $rotation = self::rotation_for($session);
        $current = self::current_slot($now, $rotation);
        if ($slot !== $current && $slot !== $current - 1) {
            return false;
        }
        $expected = self::sign((int) $point->id, $slot, self::ensure_key($point));
        return hash_equals($expected, $token);
    }

    /**
     * Seconds remaining until the current token rotates.
     *
     * @param int|null $now Current time.
     * @param \stdClass|null $session Session record (for the rotation period).
     * @return int
     */
    public static function seconds_to_rotation(?int $now = null, ?\stdClass $session = null): int {
        $now = $now ?? time();
        $rotation = self::rotation_for($session);
        return $rotation - ($now % $rotation);
    }

    /**
     * Render the teacher-facing QR panel for the currently open points.
     *
     * The panel is meant to be projected in class, so it is centred and sits
     * ABOVE the attendance tables rather than below them: the code is the
     * first thing students see when the teacher opens the page. It renders
     * nothing for students (capability gated inside) and nothing when no
     * QR-enabled point is open right now.
     *
     * @param int $cmid Course module id.
     * @param \stdClass $session Session record.
     * @param array $points Points of the session, ordered.
     * @return string HTML, empty when there is nothing to show.
     */
    public static function teacher_qr_panel(int $cmid, \stdClass $session, array $points): string {
        if (!has_capability('mod/multicheckin:manage', \context_module::instance($cmid))) {
            return '';
        }

        $now = time();
        $rotation = self::rotation_for($session);

        $blocks = '';
        foreach ($points as $p) {
            // A QR-only point is shown even when its own "show QR code" box is
            // clear. The two settings answer different questions - qrcode says
            // "offer the code", qronly says "the code is the only way in" - and
            // honouring the first one here would make a point that nobody can
            // sign into, with nothing on screen to explain why.
            if (empty($p->qrcode) && !self::only_scan($session, $p)) {
                continue;
            }
            [$open, $close] = \mod_multicheckin\session_manager::point_window($session, $p);
            if ($now < $open || $now > $close) {
                continue;
            }
            $blocks .= \html_writer::div(
                \html_writer::tag('h5', \format_string(point_name::display($p)))
                . self::qr_image($cmid, $session, $p)
                . \html_writer::div(get_string('qr_rotate_hint', 'mod_multicheckin', $rotation),
                    'text-muted small'),
                'd-inline-block m-3'
            );
        }

        if ($blocks === '') {
            return '';
        }

        $panel = \html_writer::div(
            \html_writer::tag('h4', get_string('qrcodes', 'mod_multicheckin'))
            . $blocks,
            'text-center multicheckin-qrpanel mb-3'
        );

        // Auto-refresh each QR image on its own schedule (token rotation).
        $panel .= self::refresh_script();

        return $panel;
    }

    /**
     * Render the button that opens the codes in a window of their own.
     *
     * The inline panel only appears while a point is open, which is right for
     * a page left on the projector but useless in the two minutes before class:
     * there is nothing on screen to open. This button reaches the same codes at
     * any time, in a separate window that can be dragged to a second screen or
     * left open while the teacher works elsewhere.
     *
     * @param int $cmid Course module id.
     * @param \stdClass $session Session record.
     * @return string HTML, empty for anyone who cannot manage this activity.
     */
    public static function qr_button(int $cmid, \stdClass $session): string {
        if (!has_capability('mod/multicheckin:manage', \context_module::instance($cmid))) {
            return '';
        }

        $url = new \moodle_url('/mod/multicheckin/qrwindow.php', [
            'id' => $cmid,
            'session' => (int) $session->id,
        ]);

        $button = \html_writer::tag('button', get_string('qrwindow_open', 'mod_multicheckin'), [
            'type' => 'button',
            'class' => 'btn btn-secondary multicheckin-qropen',
            'data-qrurl' => $url->out(false),
            'data-qrtarget' => self::window_name($session),
        ]);

        return \html_writer::div($button, 'd-inline-block')
            . self::window_script();
    }

    /**
     * Browser window name for one session's code window.
     *
     * Naming the window after the session is what makes the button behave:
     * clicking it twice focuses the window already open instead of stacking a
     * second copy, while two different sessions still open side by side.
     *
     * @param \stdClass $session Session record.
     * @return string
     */
    public static function window_name(\stdClass $session): string {
        return 'multicheckin_qr_' . (int) $session->id;
    }

    /**
     * Contents of the standalone code window.
     *
     * Everything the teacher needs on that screen and nothing else. Unlike the
     * inline panel this lists EVERY point of the session and ignores the open
     * window entirely: the point of opening it is to see the codes when the
     * teacher chooses to, so hiding one because the clock says so would defeat
     * the feature. A point whose window is shut is labelled as such, and the
     * page says plainly when nothing is open, so a code shown too early is
     * never mistaken for a working one.
     *
     * @param int $cmid Course module id.
     * @param \stdClass $session Session record.
     * @param array $points Points of the session, ordered.
     * @param int|null $now Current time, for tests.
     * @return string HTML.
     */
    public static function qr_window_panel(int $cmid, \stdClass $session, array $points,
            ?int $now = null): string {
        $now = $now ?? time();
        $rotation = self::rotation_for($session);

        $html = \html_writer::tag('h3', \format_string($session->name), ['class' => 'text-center']);
        $html .= \html_writer::div(
            get_string('timestart', 'mod_multicheckin') . ': ' . \userdate($session->timestart)
            . ' &nbsp;|&nbsp; '
            . get_string('timeend', 'mod_multicheckin') . ': ' . \userdate($session->timeend),
            'text-center text-muted small mb-2');

        // The occurrence's own note, so the window still says which class this
        // is once it is on a screen of its own.
        $description = trim((string) ($session->description ?? ''));
        if ($description !== '') {
            $html .= \html_writer::div(\nl2br(\s($description)),
                'text-center multicheckin-session-description mb-3');
        }

        if (!$points) {
            return $html . \html_writer::div(
                get_string('qrwindow_nopoints', 'mod_multicheckin'), 'alert alert-info');
        }

        $anyopen = false;
        $blocks = '';
        foreach ($points as $p) {
            [$open, $close] = \mod_multicheckin\session_manager::point_window($session, $p);
            $isopen = ($now >= $open && $now <= $close);
            if ($isopen) {
                $anyopen = true;
            }

            $box = \html_writer::tag('h5', \format_string(point_name::display($p)));
            $box .= self::qr_image($cmid, $session, $p);
            $box .= \html_writer::div(
                $isopen
                    ? \html_writer::span(get_string('open', 'mod_multicheckin'), 'badge bg-success')
                    : \html_writer::span(get_string('closed', 'mod_multicheckin'), 'badge bg-secondary'),
                'my-1');
            $box .= \html_writer::div(
                \userdate($open, get_string('strftimetime')) . ' – '
                . \userdate($close, get_string('strftimetime')),
                'text-muted small');
            // The flag governs whether the code is offered on the page; the
            // scan itself does not test it. Said out loud rather than left as
            // a silent difference between what the setting promises and what
            // the scanner does. A QR-only point gets the opposite note: its
            // code is on screen whether or not the box was ticked, because the
            // code is the only way students can sign in there.
            if (empty($p->qrcode)) {
                $box .= \html_writer::div(
                    self::only_scan($session, $p)
                        ? get_string('qronly_force_show', 'mod_multicheckin')
                        : get_string('qrwindow_point_notenabled', 'mod_multicheckin'),
                    'text-muted small');
            }

            $blocks .= \html_writer::div($box, 'd-inline-block m-3 align-top');
        }

        $html .= \html_writer::div($blocks, 'text-center multicheckin-qrpanel mb-3');

        $html .= \html_writer::div(get_string('qrwindow_hint', 'mod_multicheckin'),
            'text-muted small text-center');

        if (!$anyopen) {
            $html = \html_writer::div(
                get_string('qrwindow_closed_notice', 'mod_multicheckin', $rotation),
                'alert alert-warning') . $html;
        }

        return $html . self::refresh_script();
    }

    /**
     * The image for one point's code, with its refresh schedule.
     *
     * Both panels draw the same thing, so the schedule is written once, here.
     * Every code rotates again as of 2.0.23, so the attribute is unconditional
     * - and having one writer for it is what stops the inline panel and the
     * standalone window from disagreeing about how often to redraw.
     *
     * @param int $cmid Course module id.
     * @param \stdClass $session Session record.
     * @param \stdClass $point Point record.
     * @return string HTML.
     */
    private static function qr_image(int $cmid, \stdClass $session, \stdClass $point): string {
        $qrurl = new \moodle_url('/mod/multicheckin/qrcode.php', [
            'id' => $cmid,
            'session' => (int) $session->id,
            'point' => (int) $point->id,
        ]);

        $attributes = [
            'src' => $qrurl->out(false),
            'alt' => 'QR',
            'class' => 'border p-1 multicheckin-qrimg',
        ];
        // Refresh a little faster than the token rotates, so the code on
        // screen is never stale when a student scans it.
        $attributes['data-refresh'] = max(3000, (self::rotation_for($session) - 2) * 1000);

        return \html_writer::empty_tag('img', $attributes);
    }

    /**
     * Shared auto-refresh for every rotating QR image on a page.
     *
     * The inline panel and the standalone window draw the same
     * `.multicheckin-qrimg`, and the interval has to match the token rotation
     * in both. Kept in one place so the two cannot drift apart and leave one of
     * them serving a code that has already expired.
     *
     * An image without the attribute is left alone rather than refetched on an
     * interval parsed out of nothing: parseInt yields NaN, setInterval treats
     * that as zero and would spin as fast as the browser allows.
     *
     * @return string HTML script tag.
     */
    private static function refresh_script(): string {
        return \html_writer::tag('script', "
(function() {
    document.querySelectorAll('.multicheckin-qrimg').forEach(function(img) {
        var ms = parseInt(img.getAttribute('data-refresh'), 10);
        if (!ms || ms <= 0) { return; }
        var base = img.src.split('&_ts=')[0];
        var timer = null;
        var tick = function() {
            img.src = base + '&_ts=' + Date.now();
        };
        var start = function() {
            if (timer === null) {
                timer = setInterval(tick, ms);
            }
        };
        var stop = function() {
            if (timer !== null) {
                clearInterval(timer);
                timer = null;
            }
        };
        // A code on a projector stays up for a whole lesson, and this script
        // re-requests it every few seconds. Each request is a full Moodle
        // bootstrap and a freshly encoded PNG, so a class period costs a couple
        // of hundred of them. While the tab is in the background the projector
        // is showing something else and every one of those is wasted.
        //
        // The tick() on the way back is the part that matters. The token rotated
        // while the tab was hidden, so the frame still on screen has expired by
        // the time the teacher switches back; starting the interval and waiting
        // one more round would put a dead code in front of the class for the
        // first ten seconds.
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                stop();
            } else {
                tick();
                start();
            }
        });
        start();
    });
})();
", ['type' => 'text/javascript']);
    }

    /**
     * Shared click handler for the "open the codes" buttons.
     *
     * Delegated from the document rather than bound per button, and guarded so
     * the script may appear on a page twice (the button is rendered on both the
     * view and the report page) without installing two handlers. A named window
     * is reused, which is what stops repeated clicks from littering the desktop
     * with copies of the same code.
     *
     * @return string HTML script tag.
     */
    private static function window_script(): string {
        return \html_writer::tag('script', "
(function() {
    if (window.multicheckinQROpen) { return; }
    window.multicheckinQROpen = function(url, name) {
        var win = window.open(url, name, 'width=760,height=900,resizable=yes,scrollbars=yes');
        if (win) { win.focus(); }
    };
    document.addEventListener('click', function(e) {
        var btn = e.target.closest ? e.target.closest('.multicheckin-qropen') : null;
        if (!btn) { return; }
        e.preventDefault();
        window.multicheckinQROpen(btn.getAttribute('data-qrurl'), btn.getAttribute('data-qrtarget'));
    });
})();
", ['type' => 'text/javascript']);
    }
}
