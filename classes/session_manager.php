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

// Both classes live in this namespace and would normally be autoloaded, but
// session_manager is also reached from pages opened directly by URL and from
// lib.php early in the request, where the component's autoloader is not always
// primed yet. Requiring it here keeps the series methods usable everywhere.
//
// photo_manager is pulled in for the same reason: deleting a session (or one of
// its points) has to take that point's photos with it, and those delete paths
// run from the same early/URL-direct contexts that schedule_planner does.
require_once(__DIR__ . '/schedule_planner.php');
require_once(__DIR__ . '/photo_manager.php');

/**
 * Session (occurrence) and check-in point management helpers.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class session_manager {

    /** @var int Hard upper bound for the number of mid-session check-ins. */
    public const MID_MAX = 10;

    /** @var int Default mid check-in window length in seconds. */
    public const MID_WINDOW_DEFAULT = 600;

    /**
     * Extract the mid check-in plan from an activity form.
     *
     * Returns null when the form carries no mid check-in fields at all (any
     * caller that predates v1.4.0 keeps its legacy behaviour). The plan holds:
     *   - count:  how many mid check-in points the teacher wants,
     *   - opens:  open offsets in seconds from T0 (null = use the default),
     *   - window: how long each mid point stays open, in seconds.
     *
     * @param mixed $data Form data (object or array).
     * @return array|null
     */
    public static function mid_plan_from_data($data): ?array {
        $arr = (array) $data;
        if (!array_key_exists('midcount', $arr)) {
            return null;
        }

        $count = max(0, min(self::MID_MAX, (int) $arr['midcount']));

        $minutes = (int) ($arr['midwindow'] ?? (self::MID_WINDOW_DEFAULT / 60));
        $window = max(60, $minutes * 60);

        $opens = [];
        for ($k = 1; $k <= $count; $k++) {
            $opens[] = isset($arr["midopen{$k}"]) && $arr["midopen{$k}"] !== ''
                ? max(0, (int) $arr["midopen{$k}"]) * 60
                : null;
        }

        return ['count' => $count, 'opens' => $opens, 'window' => $window];
    }

    /**
     * Create the check-in points for a new session:
     * sign-in (first), the configured mid check-ins and sign-out (last).
     *
     * When $plan is null the legacy default applies: one mid check-in at the
     * middle of the session with a ten-minute window.
     *
     * @param int $sessionid Session id.
     * @param array|null $plan Plan from mid_plan_from_data(), or null.
     * @return void
     */
    public static function create_default_points(int $sessionid, ?array $plan = null): void {
        global $DB;

        $session = $DB->get_record('multicheckin', ['id' => $sessionid], '*', MUST_EXIST);
        $duration = max(600, (int) $session->timeend - (int) $session->timestart);

        // No mid check-ins unless the caller asks for them or the form supplies
        // a plan. A new activity therefore starts with the two points that
        // define a session - sign in and sign out - and a mid roll call is
        // something the teacher adds on purpose. This is also the fallback for
        // any caller that reaches here without a plan (a restore, an upgrade),
        // where inventing a roll call nobody configured would be wrong.
        $midcount = 0;
        $midwindow = self::MID_WINDOW_DEFAULT;
        $midopens = [];
        if ($plan !== null) {
            $midcount = max(0, (int) $plan['count']);
            $midwindow = max(60, (int) $plan['window']);
            $midopens = array_values((array) ($plan['opens'] ?? []));
        }

        $defaults = [
            [
                'name' => get_string('point_signin', 'mod_multicheckin'),
                'sortorder' => 1,
                'openoffset' => -600,
                'closeoffset' => (int) $session->lategrace + 900,
                'role' => 'first',
                'required' => 1,
            ],
        ];

        for ($i = 0; $i < $midcount; $i++) {
            // A missing (or blank) start time falls back to an even spread
            // between sign-in and sign-out, which matches the legacy default
            // for the single mid point.
            if (!isset($midopens[$i]) || $midopens[$i] === null) {
                $midopens[$i] = (int) floor($duration * ($i + 1) / ($midcount + 1) - 300);
            }
            $defaults[] = [
                'name' => $midcount > 1
                    ? get_string('point_mid', 'mod_multicheckin') . ' ' . ($i + 1)
                    : get_string('point_mid', 'mod_multicheckin'),
                'sortorder' => 2 + $i,
                'openoffset' => (int) $midopens[$i],
                'closeoffset' => (int) $midopens[$i] + $midwindow,
                'role' => 'mid',
                'required' => 0,
            ];
        }

        $defaults[] = [
            'name' => get_string('point_signout', 'mod_multicheckin'),
            'sortorder' => 2 + $midcount,
            'openoffset' => (int) ($duration - (int) $session->earlygrace - 900),
            'closeoffset' => (int) $duration + 600,
            'role' => 'last',
            'required' => 1,
        ];

        // A session with no sign-out requirement carries no sign-out point: the
        // last point would only offer a QR code that changes no grade. The
        // status is taken from the sign-in point alone. Default 1 keeps the
        // point for every activity that wants it.
        $requiresignout = !isset($session->requiresignout) || !empty($session->requiresignout);
        if (!$requiresignout) {
            array_pop($defaults);
        }

        foreach ($defaults as $d) {
            $record = (object) array_merge($d, [
                'sessionid' => $sessionid,
                'password' => '',
                'qrcode' => ($d['sortorder'] === 1 || $d['role'] === 'last') ? 1 : 0,
                // A new point always accepts the button. Locking one to the
                // code is a per-point decision a teacher makes afterwards; the
                // generator must not carry the template's occurrence-wide flag
                // down into the points, or turning the flag off later would
                // leave every point still locked with nothing on screen saying
                // where that came from.
                'qronly' => 0,
                'qrcodekey' => bin2hex(random_bytes(16)),
                'qrcodetime' => 0,
                'notify' => 1,
                'timemodified' => time(),
            ]);
            $DB->insert_record('multicheckin_point', $record);
        }
    }

    /**
     * Reconcile the mid check-in points of an existing session with a plan.
     *
     * The change is minimal: the first N existing mid points keep their id
     * (and therefore their logs) and only get their windows updated; missing
     * points are appended. This means changing a start time never destroys
     * check-in history.
     *
     * Surplus points are removed only when they are empty AND carry one of the
     * plugin's own names. A point someone has signed is evidence, and lowering
     * a number in the settings is not an instruction to delete evidence - it is
     * an instruction to stop offering that check-in. The same goes for a point
     * a teacher added by hand on the points page: the name no longer matches a
     * default, so the plan does not own it and must not remove it.
     *
     * @param int $sessionid Session id.
     * @param array $plan Plan from mid_plan_from_data().
     * @return array ['added' => int[] point ids appended, 'removed' => int, 'kept' => int]
     */
    public static function regenerate_mid_points(int $sessionid, array $plan): array {
        global $DB;

        $count = max(0, min(self::MID_MAX, (int) $plan['count']));
        $window = max(60, (int) $plan['window']);
        $opens = array_values((array) ($plan['opens'] ?? []));

        $session = $DB->get_record('multicheckin', ['id' => $sessionid], '*', MUST_EXIST);
        $duration = max(600, (int) $session->timeend - (int) $session->timestart);
        $mids = array_values($DB->get_records('multicheckin_point',
            ['sessionid' => $sessionid, 'role' => 'mid'], 'sortorder ASC'));

        $stats = ['added' => [], 'removed' => 0, 'kept' => 0];
        $touched = false;

        // Update the overlapping range in place.
        $keep = min(count($mids), $count);
        for ($i = 0; $i < $keep; $i++) {
            $open = isset($opens[$i]) && $opens[$i] !== null
                ? (int) $opens[$i]
                : (int) floor($duration * ($i + 1) / ($count + 1) - 300);
            // A settings page is saved for all sorts of reasons - a title, an
            // introduction - and most of them change nothing about the mid
            // check-ins. Writing the same two numbers back over twenty dates is
            // work nobody asked for, so a point that already matches its plan is
            // left alone. It also keeps "did anything change?" honest: the flag
            // stays down and nothing downstream has to renumber.
            $close = $open + $window;
            if ((int) $mids[$i]->openoffset === $open && (int) $mids[$i]->closeoffset === $close) {
                continue;
            }
            $upd = (object) [
                'id' => $mids[$i]->id,
                'openoffset' => $open,
                'closeoffset' => $close,
                'timemodified' => time(),
            ];
            $DB->update_record('multicheckin_point', $upd);
            $touched = true;
        }

        // Trim the surplus - but only what nobody has used and what the plan
        // itself created, whichever part of the range it sits in.
        for ($i = $keep; $i < count($mids); $i++) {
            $point = $mids[$i];
            if ($DB->record_exists('multicheckin_log', ['pointid' => $point->id])
                    || !point_name::is_default((string) $point->name)) {
                $stats['kept']++;
                continue;
            }
            photo_manager::delete_for_point((int) $point->id);
            $DB->delete_records('multicheckin_log', ['pointid' => $point->id]);
            $DB->delete_records('multicheckin_point', ['id' => $point->id]);
            $stats['removed']++;
            $touched = true;
        }

        // Append what is missing.
        for ($i = count($mids); $i < $count; $i++) {
            $open = isset($opens[$i]) && $opens[$i] !== null
                ? (int) $opens[$i]
                : (int) floor($duration * ($i + 1) / ($count + 1) - 300);
            $record = (object) [
                'sessionid' => $sessionid,
                'name' => get_string('point_mid', 'mod_multicheckin') . ' ' . ($i + 1),
                'sortorder' => 2 + $i,
                'openoffset' => $open,
                'closeoffset' => $open + $window,
                'role' => 'mid',
                'required' => 0,
                'password' => '',
                'qrcode' => 0,
                'qrcodekey' => bin2hex(random_bytes(16)),
                'qrcodetime' => 0,
                'notify' => 1,
                'timemodified' => time(),
            ];
            $stats['added'][] = (int) $DB->insert_record('multicheckin_point', $record);
            $touched = true;
        }

        if ($touched) {
            // Anything moved or changed size: renumber every point of the
            // session so the display order stays first, mids, last.
            self::renumber_points($sessionid);
        }

        return $stats;
    }

    /**
     * Apply a mid check-in plan to every occurrence of a series.
     *
     * Saving the activity form used to reconcile only the template row, so a
     * teacher who changed "mid check-ins" from 1 to 2 kept the old points in
     * every date already generated. This walks the occurrences and applies the
     * same plan to each.
     *
     * A point that appears on a session whose window has already closed gets a
     * signature for every participant. Nobody could have signed it: the point
     * did not exist while its window was open. Signing only closed windows is
     * what keeps the feature from turning a mid-class roll call into "everyone
     * present the moment the teacher adds it".
     *
     * @param int $templateid Template id.
     * @param array $plan Plan from mid_plan_from_data().
     * @return array ['sessions' => int, 'added' => int, 'removed' => int, 'kept' => int, 'signed' => int]
     */
    public static function sync_series_mid_points(int $templateid, array $plan): array {
        global $DB;

        $stats = ['sessions' => 0, 'added' => 0, 'removed' => 0, 'kept' => 0, 'signed' => 0];

        $occurrences = self::get_occurrences($templateid);
        if (!$occurrences) {
            return $stats;
        }

        $template = $DB->get_record('multicheckin', ['id' => $templateid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('multicheckin', (int) $templateid, (int) $template->course,
            false, IGNORE_MISSING);
        if (!$cm) {
            return $stats;
        }
        $context = \context_module::instance($cm->id);
        $now = time();

        foreach ($occurrences as $occurrence) {
            $result = self::regenerate_mid_points((int) $occurrence->id, $plan);
            $stats['sessions']++;
            $stats['removed'] += (int) $result['removed'];
            $stats['kept'] += (int) $result['kept'];
            $stats['added'] += count($result['added']);

            if (!$result['added']) {
                continue;
            }

            // Only the ones whose window is already over. A point still to be
            // opened is exactly the roll call the teacher wants to run.
            $added = [];
            foreach ($result['added'] as $pointid) {
                $point = $DB->get_record('multicheckin_point', ['id' => $pointid], '*', IGNORE_MISSING);
                if ($point) {
                    $added[] = $point;
                }
            }
            $fresh = self::closed_points($occurrence, $added, $now);
            if (!$fresh) {
                continue;
            }

            // The roster is the audience setting, the same list the sheet and
            // the report show. Signing a different set of people would make the
            // three disagree about who was there.
            $users = audience_manager::participants($occurrence, $context);
            if (!$users) {
                continue;
            }
            $stats['signed'] += mark_manager::fill_missing($occurrence, $fresh, array_keys($users));
        }

        return $stats;
    }

    /**
     * The points whose check-in window has already closed.
     *
     * Separated from the sync so the decision can be tested without a roster: it
     * is the whole difference between "this check-in was never possible" and
     * "this check-in is still to come", and getting it backwards would either
     * sign a live roll call or leave a dead one marked absent.
     *
     * @param \stdClass $session Session record.
     * @param \stdClass[] $points Point records.
     * @param int $now Timestamp to compare against; 0 means now.
     * @return \stdClass[] Points keyed by id.
     */
    public static function closed_points(\stdClass $session, array $points, int $now = 0): array {
        $now = $now > 0 ? $now : time();

        $out = [];
        foreach ($points as $point) {
            [, $close] = self::point_window($session, $point);
            if ($now > $close) {
                $out[(int) $point->id] = $point;
            }
        }

        return $out;
    }

    /**
     * Renumber the points of a session: sign-in first, then the mid points by
     * their open time, sign-out last.
     *
     * @param int $sessionid Session id.
     * @return void
     */
    public static function renumber_points(int $sessionid): void {
        global $DB;

        $rank = ['first' => 0, 'mid' => 1, 'last' => 2];
        $points = $DB->get_records('multicheckin_point', ['sessionid' => $sessionid]);
        usort($points, function ($a, $b) use ($rank) {
            $ra = $rank[$a->role] ?? 1;
            $rb = $rank[$b->role] ?? 1;
            return [$ra, (int) $a->openoffset] <=> [$rb, (int) $b->openoffset];
        });

        $order = 1;
        foreach ($points as $p) {
            if ((int) $p->sortorder !== $order) {
                $DB->set_field('multicheckin_point', 'sortorder', $order, ['id' => $p->id]);
            }
            $order++;
        }
    }

    /**
     * Get all points for a session, ordered.
     *
     * @param int $sessionid Session id.
     * @return array
     */
    public static function get_points(int $sessionid): array {
        global $DB;
        return $DB->get_records('multicheckin_point', ['sessionid' => $sessionid], 'sortorder ASC');
    }

    /**
     * Whether a point is currently open for self check-in.
     *
     * @param \stdClass $session Session record.
     * @param \stdClass $point Point record.
     * @param int|null $now Current time.
     * @return bool
     */
    public static function point_is_open(\stdClass $session, \stdClass $point, ?int $now = null): bool {
        $now = $now ?? time();
        $open = (int) $session->timestart + (int) $point->openoffset;
        $close = (int) $session->timestart + (int) $point->closeoffset;
        return ($now >= $open && $now <= $close);
    }

    /**
     * Open / close window for a point in absolute timestamps.
     *
     * @param \stdClass $session Session record.
     * @param \stdClass $point Point record.
     * @return array [open, close]
     */
    public static function point_window(\stdClass $session, \stdClass $point): array {
        return [
            (int) $session->timestart + (int) $point->openoffset,
            (int) $session->timestart + (int) $point->closeoffset,
        ];
    }

    /**
     * Record a check-in event (raw log).
     *
     * @param \stdClass $session Session record.
     * @param \stdClass $point Point record.
     * @param int $userid User id.
     * @param string $method One of manual|self|qr|password.
     * @return int New log id.
     */
    public static function record_checkin(\stdClass $session, \stdClass $point, int $userid, string $method = 'self'): int {
        global $DB;

        $log = (object) [
            'pointid' => (int) $point->id,
            'sessionid' => (int) $session->id,
            'userid' => $userid,
            'timecreated' => time(),
            'ip' => getremoteaddr(),
            'method' => $method,
        ];

        return (int) $DB->insert_record('multicheckin_log', $log);
    }

    /**
     * Delete all points of a session (used before re-creating from the form).
     *
     * @param int $sessionid Session id.
     * @return void
     */
    public static function delete_points(int $sessionid): void {
        global $DB;

        // One transaction: the photographs, the log rows and the points are
        // three statements that describe one state, and this runs immediately
        // before the points are rebuilt from the form. Stopping between them
        // would leave log rows pointing at points that no longer exist, which
        // is worse than either half on its own.
        $DB->transaction(static function () use ($DB, $sessionid) {
            photo_manager::delete_all_for_session($sessionid);
            $DB->delete_records('multicheckin_log', ['sessionid' => $sessionid]);
            $DB->delete_records('multicheckin_point', ['sessionid' => $sessionid]);
        });
    }

    /**
     * Erase every check-in this course's activities have collected.
     *
     * This is what "reset check-in data" in the course reset form means, and it
     * is the one place that has to remember all of it. It lives here rather than
     * in the reset callback because a page request is not needed to run it: the
     * callback forwards, the work is testable.
     *
     * Two rules decide the order and the shape.
     *
     * The photographs go first. A photograph lives in the file area keyed by the
     * id of the log row it belongs to, and every deletion path in this plugin
     * finds it by looking that row up. Delete the rows first and the files are
     * orphaned for good: the context still exists, the bytes are still on disk,
     * and no page, report or scheduled task can ever name them again.
     *
     * They are removed per activity, not per occurrence, because the context
     * belongs to the activity: one call covers the template and every occurrence
     * under it, where per-occurrence calls would delete the same area a dozen
     * times.
     *
     * The permits and the fence audit rows are here for the same reason as the
     * requests: all three are keyed by a session, all three outlive the check-in
     * they describe, and none of them is reachable from a page once the logs
     * they belonged to are gone.
     *
     * @param int $courseid Course id.
     * @return void
     */
    public static function reset_course_data(int $courseid): void {
        global $DB;

        $templates = $DB->get_records('multicheckin', [
            'course' => $courseid,
            'parentid' => 0,
        ], '', 'id');
        foreach ($templates as $template) {
            photo_manager::delete_all_for_template((int) $template->id);
        }

        $sessions = $DB->get_records('multicheckin', ['course' => $courseid], '', 'id');
        if (!$sessions) {
            return;
        }

        list($insql, $params) = $DB->get_in_or_equal(array_keys($sessions));
        foreach ([
            'multicheckin_photoreq',
            'multicheckin_pass',
            'multicheckin_geo_log',
            'multicheckin_log',
            'multicheckin_result',
        ] as $table) {
            $DB->delete_records_select($table, "sessionid {$insql}", $params);
        }
    }

    // ---- Occurrence series -------------------------------------------------
    //
    // An activity has one configuration template (the row cm->instance points
    // at, parentid = 0) and zero or more occurrences below it (parentid =
    // template id). When there are no occurrences the template is treated as
    // the single session, which is how every activity behaved before series
    // existed - so an untouched or upgraded activity keeps working.

    /**
     * Build a repeat specification from submitted form data.
     *
     * Returns null when the caller is not the activity form (no series fields
     * present at all), which keeps add_instance/update_instance safe for every
     * other entry point - backup restore, unit tests, the CLI. A form that
     * carries the fields but selects "do not repeat" yields an empty spec, and
     * generate_series() then produces nothing.
     *
     * The field names are read here and nowhere else, so the form, lib.php and
     * the tests all describe the series the same way.
     *
     * @param mixed $data Form data (object or array).
     * @return array|null
     */
    public static function spec_from_data($data): ?array {
        $arr = (array) $data;
        if (!array_key_exists('seriesrepeat', $arr)) {
            return null;
        }

        $weekdays = [];
        for ($day = 1; $day <= 7; $day++) {
            if (!empty($arr["serieswd{$day}"])) {
                $weekdays[] = $day;
            }
        }

        return [
            'repeat' => (string) ($arr['seriesrepeat'] ?? schedule_planner::REPEAT_NONE),
            'weekdays' => $weekdays,
            'interval' => (int) ($arr['seriesinterval'] ?? 1),
            'datefrom' => (int) ($arr['seriesdatefrom'] ?? 0),
            'dateto' => (int) ($arr['seriesdateto'] ?? 0),
            'timefrom' => (string) ($arr['seriestimefrom'] ?? ''),
            'custom' => self::custom_rows_from_data($arr),
            'timestart' => (int) ($arr['timestart'] ?? 0),
            'timeend' => (int) ($arr['timeend'] ?? 0),
        ];
    }

    /**
     * The explicit rows behind a 'custom' series, in the order they were typed.
     *
     * The number of rows comes from the form's own count field, never from how
     * many row fields happen to be present in the request. The form renders a
     * fixed block of rows so they can be revealed without a round trip, so
     * counting the fields would read the untouched ones too and quietly create
     * sessions the teacher never entered.
     *
     * Blank rows are dropped: leaving a middle row empty is a normal way to
     * skip one while keeping the rest, and it should not become a midnight
     * session on the epoch date.
     *
     * @param array $arr Form data as an array.
     * @return array Rows of ['date' => string, 'time' => string].
     */
    public static function custom_rows_from_data(array $arr): array {
        $count = (int) ($arr['seriescustomcount'] ?? 0);
        if ($count <= 0) {
            return [];
        }

        // The count is attacker-controlled in principle, so it is clamped to
        // the same ceiling the planner enforces. Without this a hand-edited
        // request could walk a million rows.
        $count = min($count, schedule_planner::max_sessions());

        $rows = [];
        for ($k = 1; $k <= $count; $k++) {
            $date = trim((string) ($arr["seriescd{$k}"] ?? ''));
            $time = trim((string) ($arr["seriesct{$k}"] ?? ''));
            if ($date === '' && $time === '') {
                continue;
            }
            $rows[] = ['date' => $date, 'time' => $time];
        }

        return $rows;
    }

    /**
     * The template record for an activity.
     *
     * @param int $cmid Course module id.
     * @return \stdClass|null
     */
    public static function get_template_for_cm(int $cmid): ?\stdClass {
        global $DB;

        $cm = get_coursemodule_from_id('multicheckin', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return null;
        }
        return $DB->get_record('multicheckin', ['id' => $cm->instance]) ?: null;
    }

    /**
     * All occurrences of a template, optionally filtered to a series.
     *
     * @param int $templateid Template id.
     * @param int|null $seriesid Restrict to one batch, or null for all.
     * @return array Occurrences keyed by id, ordered by start time.
     */
    public static function get_occurrences(int $templateid, ?int $seriesid = null): array {
        global $DB;

        if ($templateid <= 0) {
            return [];
        }

        $conditions = ['parentid' => $templateid];
        if ($seriesid !== null) {
            $conditions['seriesid'] = $seriesid;
        }

        return $DB->get_records('multicheckin', $conditions, 'timestart ASC, seqno ASC') ?: [];
    }

    /**
     * Every session a page should offer: the occurrences, or the template.
     *
     * This is the compatibility hinge. Pages that used to take cm->instance as
     * "the session" now call this: when a series exists they get its
     * occurrences, and when it does not they get the template back as a
     * one-element list. Callers therefore never need a special case for the
     * single-session activity, and an upgraded site renders identically.
     *
     * @param \stdClass $template Template record.
     * @return array Sessions keyed by id, ordered by start time.
     */
    public static function list_sessions(\stdClass $template): array {
        $occurrences = self::get_occurrences((int) $template->id);
        if ($occurrences) {
            return $occurrences;
        }
        return [(int) $template->id => $template];
    }

    /**
     * Whether a record is a generated occurrence rather than a template.
     *
     * @param \stdClass $record A multicheckin row.
     * @return bool
     */
    public static function is_occurrence(\stdClass $record): bool {
        return (int) ($record->parentid ?? 0) > 0;
    }

    /**
     * The template row behind any session row.
     *
     * An occurrence is an extra row in the same table whose parentid points at
     * the template; it has no course module of its own, because
     * course_modules.instance always references the template. Anything that
     * needs the module - its context, its enrolled users, its grade item - must
     * therefore be handed the template, not the occurrence id.
     *
     * @param \stdClass $session Session record, template or occurrence.
     * @return \stdClass|null The template, or null when the parent row is gone.
     */
    public static function get_template_for_session(\stdClass $session): ?\stdClass {
        global $DB;

        if (!self::is_occurrence($session)) {
            return $session;
        }
        return $DB->get_record('multicheckin', ['id' => (int) $session->parentid]) ?: null;
    }

    /**
     * The session record a check-in or report should act on.
     *
     * Resolves a requested session id against the activity, refusing anything
     * that is neither the activity's own template nor one of its occurrences.
     * Without this guard a hand-edited 'session' parameter could read or write
     * another course's attendance.
     *
     * @param \stdClass $template Template record.
     * @param int $requestedid Requested session id (0 = pick automatically).
     * @return \stdClass|null
     */
    public static function resolve_session(\stdClass $template, int $requestedid = 0): ?\stdClass {
        global $DB;

        if ($requestedid > 0 && $requestedid !== (int) $template->id) {
            $record = $DB->get_record('multicheckin', ['id' => $requestedid]);
            if (!$record || (int) $record->parentid !== (int) $template->id) {
                return null;
            }
            return $record;
        }

        if ($requestedid === (int) $template->id) {
            return $template;
        }

        return self::default_session($template);
    }

    /**
     * The session to show when no particular one is requested.
     *
     * Prefers an occurrence that is currently running, then the next upcoming
     * one, then the most recent past one; falls back to the template when the
     * activity has no series. This is what makes the activity link open on the
     * session a student actually needs rather than always the first or last.
     *
     * @param \stdClass $template Template record.
     * @param int|null $now Current time.
     * @return \stdClass
     */
    public static function default_session(\stdClass $template, ?int $now = null): \stdClass {
        $sessions = self::list_sessions($template);
        if (count($sessions) === 1) {
            return reset($sessions);
        }

        $now = $now ?? time();

        // Currently open (with a small tail so the sign-out window counts).
        foreach ($sessions as $session) {
            if ($now >= (int) $session->timestart - 3600 && $now <= (int) $session->timeend + 3600) {
                return $session;
            }
        }

        // The next one still to come.
        foreach ($sessions as $session) {
            if ((int) $session->timestart > $now) {
                return $session;
            }
        }

        // Otherwise the last one that has finished.
        return end($sessions);
    }

    /**
     * Create the occurrences for a template from a repeat specification.
     *
     * Re-running this is safe: an occurrence whose start time already exists
     * for the template is left alone, so a teacher can save the form twice, or
     * extend the end date, without ending up with duplicate sessions or losing
     * the check-ins already collected.
     *
     * Only the occurrence structure is touched. The template row is never
     * modified and no check-in log is ever deleted here - that is what
     * delete_series() is for, and it is a separate, explicit action.
     *
     * @param int $templateid Template id.
     * @param array $spec Repeat specification for schedule_planner::plan().
     * @param array|null $plan Mid check-in plan for the new occurrences.
     * @return int Number of occurrences created.
     */
    public static function generate_series(int $templateid, array $spec, ?array $plan = null): int {
        global $DB;

        $template = $DB->get_record('multicheckin', ['id' => $templateid], '*', MUST_EXIST);
        $starts = schedule_planner::plan($spec);
        if (!$starts) {
            return 0;
        }

        $records = schedule_planner::build_sessions($template, $starts);

        // Existing start times, so re-saving the form is idempotent.
        $existing = [];
        foreach (self::get_occurrences($templateid) as $occurrence) {
            $existing[(int) $occurrence->timestart] = true;
        }

        $created = 0;
        foreach ($records as $record) {
            if (isset($existing[(int) $record->timestart])) {
                continue;
            }

            $record->seqno = self::next_seqno($templateid);
            $newid = (int) $DB->insert_record('multicheckin', $record);

            // Each occurrence gets its own points; they share the template's
            // configuration but hold per-point state (QR keys, windows, logs)
            // that must not be shared between sessions.
            self::create_default_points($newid, $plan);

            $existing[(int) $record->timestart] = true;
            $created++;
        }

        if ($created > 0) {
            self::renumber_occurrences($templateid);
        }

        return $created;
    }

    /**
     * The next ordinal to assign within a template's series.
     *
     * @param int $templateid Template id.
     * @return int
     */
    private static function next_seqno(int $templateid): int {
        global $DB;
        $max = $DB->get_field_sql(
            'SELECT MAX(seqno) FROM {multicheckin} WHERE parentid = ?',
            [$templateid]
        );
        return ((int) $max) + 1;
    }

    /**
     * Renumber a template's occurrences so seqno matches chronological order.
     *
     * @param int $templateid Template id.
     * @return void
     */
    public static function renumber_occurrences(int $templateid): void {
        global $DB;

        $order = 1;
        foreach (self::get_occurrences($templateid) as $occurrence) {
            if ((int) $occurrence->seqno !== $order) {
                $DB->set_field('multicheckin', 'seqno', $order, ['id' => $occurrence->id]);
            }
            $order++;
        }
    }

    /**
     * Remove every occurrence of a template, with their points and logs.
     *
     * Deliberately never touches the template row, and never touches a record
     * belonging to another activity. Callers must confirm with the teacher
     * first: this discards collected attendance data for the whole series.
     *
     * @param int $templateid Template id.
     * @param bool $keeplogs When true, keep the check-in logs of occurrences
     *                       that have any, and remove only the empty ones.
     * @return int Number of occurrences removed.
     */
    public static function delete_series(int $templateid, bool $keeplogs = false): int {
        global $DB;

        $occurrences = self::get_occurrences($templateid);
        if (!$occurrences) {
            return 0;
        }

        $removed = 0;
        foreach ($occurrences as $occurrence) {
            $id = (int) $occurrence->id;

            if ($keeplogs && self::session_has_data($id)) {
                continue;
            }

            // One transaction per occurrence, for the same reason the observer
            // uses one: nine statements across eight tables, all of them hard
            // DELETEs, and a stop in the middle leaves an occurrence that is
            // partly there. A batch rebuild deletes dozens of these in a row,
            // so the half-state is not hypothetical - it is what a timeout on
            // occurrence 17 would leave behind for the next run to trip over.
            //
            // The files go inside the block because they are filed under the log
            // ids that are disappearing, and the module context they live under
            // is resolved through the occurrence row that is still present at
            // this point. Deleting an already-deleted file is harmless, so a
            // rollback followed by a retry converges.
            $DB->transaction(static function () use ($DB, $id) {
                $DB->delete_records('multicheckin_geo_log', ['sessionid' => $id]);
                $DB->delete_records('multicheckin_notify', ['sessionid' => $id]);
                // Before the log rows: the photographs are filed under their
                // ids, and this must still run while the session row exists,
                // because resolving the module context goes through it.
                photo_manager::delete_all_for_session($id);
                $DB->delete_records('multicheckin_log', ['sessionid' => $id]);
                $DB->delete_records('multicheckin_result', ['sessionid' => $id]);
                leave_manager::delete_all_for_session($id);
                // The images the note embeds go with the row. Called before the
                // multicheckin row itself is removed, because resolving the
                // module context needs that row to still be there.
                description_editor::delete_files($id);
                // A permit names a point of this occurrence. It lapses ten
                // minutes after it is minted, but the sweep runs hourly and a
                // copied link outlives the point it leads to, so the rows go
                // with the occurrence rather than waiting for the next sweep.
                $DB->delete_records(photo_manager::PASS_TABLE, ['sessionid' => $id]);
                $DB->delete_records('multicheckin_point', ['sessionid' => $id]);
                $DB->delete_records('multicheckin', ['id' => $id]);
            });

            $removed++;
        }

        return $removed;
    }

    /**
     * Whether a session holds any check-in data worth protecting.
     *
     * @param int $sessionid Session id.
     * @return bool
     */
    public static function session_has_data(int $sessionid): bool {
        global $DB;
        return $DB->record_exists('multicheckin_log', ['sessionid' => $sessionid]);
    }

    /**
     * How many occurrences carry check-in data.
     *
     * Used to warn before a destructive rebuild: "24 occurrences, 5 of which
     * already hold check-ins" is the sentence a teacher needs to read before
     * confirming.
     *
     * @param int $templateid Template id.
     * @return array ['total' => int, 'withdata' => int]
     */
    public static function series_data_summary(int $templateid): array {
        global $DB;

        $total = $DB->count_records('multicheckin', ['parentid' => $templateid]);
        if ($total === 0) {
            return ['total' => 0, 'withdata' => 0];
        }

        $withdata = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT sessionid)
               FROM {multicheckin_log}
              WHERE sessionid IN (SELECT id FROM {multicheckin} WHERE parentid = ?)",
            [$templateid]
        );

        return ['total' => (int) $total, 'withdata' => (int) $withdata];
    }

    /**
     * Copy a template's shared configuration onto its occurrences.
     *
     * When a teacher edits the activity form, the changes belong to every
     * occurrence: the grace periods, the geofence, the point values and the
     * grade maximum are properties of the class, not of one date. Timing is
     * explicitly NOT copied - each occurrence keeps the dates it was generated
     * with, which is the whole point of having a series.
     *
     * @param int $templateid Template id.
     * @param \stdClass $template The saved template record.
     * @return int Number of occurrences updated.
     */
    public static function sync_occurrence_settings(int $templateid, \stdClass $template): int {
        global $DB;

        $occurrences = self::get_occurrences($templateid);
        if (!$occurrences) {
            return 0;
        }

        // Fields pushed from the activity onto every occurrence: configuration,
        // not identity.
        //
        // 'name' and 'description' are deliberately NOT here. An occurrence
        // title is not a copy of the activity title - since v1.10.0 a teacher
        // can retitle one occurrence ("Week 3: lab session") and a description
        // is written per occurrence by definition. Syncing either would mean
        // that saving the activity form silently overwrote work done on the
        // series page, which is exactly the kind of data loss that is invisible
        // until someone goes looking for a note that is no longer there.
        static $shared = [
            'intro', 'introformat', 'lategrace', 'earlygrace', 'absentreason',
            'grade', 'pointspresent', 'pointslate', 'pointsearly', 'pointslateearly',
            'pointsabsent', 'pointsleave', 'earlymode',
            'requiresignout',
            'allowselfcheckin', 'selfcheckinpassword', 'qronly', 'notifyenabled', 'notifyleadtime',
            'notifyrule',
            'qrrotation', 'geomode', 'geoipranges', 'geolat', 'geolng', 'georadius',
            'geoenforceqr', 'showgeoaudit',
            'audience',
        ];
        $updated = 0;
        foreach ($occurrences as $occurrence) {
            $record = (object) ['id' => (int) $occurrence->id, 'timemodified' => time()];
            $changed = false;
            foreach ($shared as $field) {
                if (property_exists($template, $field)
                        && (string) ($occurrence->$field ?? '') !== (string) ($template->$field ?? '')) {
                    $record->$field = $template->$field;
                    $changed = true;
                }
            }
            if ($changed) {
                $DB->update_record('multicheckin', $record);
                $updated++;
            }
        }

        return $updated;
    }
}
