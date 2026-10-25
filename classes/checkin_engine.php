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
 * Core status computation engine.
 *
 * Given a session (occurrence) with N check-in points, computes for each enrolled
 * user whether they were present, late, left early or absent, based on the point
 * windows and the configured grace periods relative to the session start (T0).
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class checkin_engine {

    /** @var string Status code: present. */
    public const STATUS_PRESENT = 'P';
    /** @var string Status code: late. */
    public const STATUS_LATE = 'L';
    /** @var string Status code: left early. */
    public const STATUS_EARLY = 'EL';
    /** @var string Status code: late AND left early. */
    public const STATUS_LATE_EARLY = 'LT';
    /** @var string Status code: absent. */
    public const STATUS_ABSENT = 'A';
    /** @var string Status code: on leave (excused). */
    public const STATUS_LEAVE = 'LV';

    /** @var string Early-leave mode: judged by the last check-in point window. */
    public const EARLYMODE_WINDOW = 'window';
    /** @var string Early-leave mode: judged by the departure time vs session end. */
    public const EARLYMODE_THRESHOLD = 'threshold';

    /**
     * Compute and persist results for every user in the session.
     *
     * @param int $sessionid The multicheckin instance id.
     * @return int Number of users processed.
     */
    public static function compute_session(int $sessionid): int {
        global $DB;

        $session = $DB->get_record('multicheckin', ['id' => $sessionid], '*', MUST_EXIST);
        $context = \context_module::instance(self::get_cmid($session));
        // The roster comes from the audience setting, not from the capability:
        // see audience_manager. The engine must compute for exactly the people
        // the sheet and the report list, or a teacher inside the roster would
        // hold a result row while the sheet never showed them one.
        $users = audience_manager::participants($session, $context);

        $points = $DB->get_records('multicheckin_point', ['sessionid' => $sessionid], 'sortorder ASC');
        if (!$points) {
            return 0;
        }

        // The logs and the leaves are read once for the class, not once per
        // student. The judgement needs both for every row, and both are already
        // keyed by user, so sixty students cost two reads instead of a hundred
        // and twenty. Nothing about the answer changes: compute_user_with() is
        // the same code compute_user() reaches.
        $leaves = leave_manager::for_session($sessionid);
        $logs = [];
        $alllogs = $DB->get_records('multicheckin_log', ['sessionid' => $sessionid], '',
            'id, userid, pointid, timecreated');
        foreach ($alllogs as $log) {
            $logs[(int) $log->userid][(int) $log->id] = $log;
        }

        $count = 0;
        foreach ($users as $user) {
            $userid = (int) $user->id;
            $result = self::compute_user_with($session, $points, $userid,
                $logs[$userid] ?? [], $leaves[$userid] ?? null);
            self::save_result($sessionid, $userid, $result);
            $count++;
        }

        return $count;
    }

    /**
     * Recompute one user's result row, and only that user's.
     *
     * This is what a single check-in needs: one student's log changed, so one
     * student's status can have changed with it. compute_session() answers the
     * same question for the whole class, which is the wrong width here - a
     * sixty-student class checking in one after another would recompute the
     * class sixty times over, and every one of those runs writes rows that the
     * next run immediately overwrites with the identical value.
     *
     * The width of the work is the width of the change. Nothing else moves.
     *
     * @param \stdClass $session Session record.
     * @param int $userid The user whose log changed.
     * @return void
     */
    public static function refresh_user(\stdClass $session, int $userid): void {
        global $DB;

        $sessionid = (int) $session->id;
        $points = $DB->get_records('multicheckin_point', ['sessionid' => $sessionid], 'sortorder ASC');
        if (!$points) {
            return;
        }

        $result = self::compute_user($session, $points, $userid);
        self::save_result($sessionid, $userid, $result);
    }

    /**
     * Compute the status for a single user.
     *
     * Reads what it needs, then hands off: the judgement itself lives in
     * compute_user_with() so that a page holding a whole class worth of logs
     * and leaves can compute a row without asking for them again, while still
     * going through the same code. Two implementations of "what is this
     * student's status" would be one too many.
     *
     * @param \stdClass $session Session record.
     * @param array $points Points indexed by id.
     * @param int $userid User id.
     * @return array Result array with keys: statuscode, firstcheckin, lastcheckin, latemin, earlymin, points.
     */
    public static function compute_user(\stdClass $session, array $points, int $userid): array {
        global $DB;

        $leave = leave_manager::record((int) $session->id, $userid);

        $logs = $DB->get_records('multicheckin_log', [
            'sessionid' => (int) $session->id,
            'userid' => $userid,
        ], 'timecreated ASC');

        return self::compute_user_with($session, $points, $userid, $logs, $leave);
    }

    /**
     * Compute the status for one user from logs and leave already in hand.
     *
     * The judgement, with no reads of its own: everything it needs arrives as
     * an argument. Callers that have the class worth of rows in memory pass
     * them; callers with one student in mind use compute_user().
     *
     * @param \stdClass $session Session record.
     * @param array $points Points indexed by id.
     * @param int $userid User id.
     * @param array $logs This user's log rows, keyed by id.
     * @param \stdClass|null $leave This user's leave record, or null.
     * @return array Result array with keys: statuscode, firstcheckin, lastcheckin, latemin, earlymin, points.
     */
    public static function compute_user_with(\stdClass $session, array $points, int $userid,
            array $logs, ?\stdClass $leave = null): array {

        // A leave outranks everything below. It is a session-level declaration
        // rather than an event against a point, so there is nothing in the logs
        // to weigh it against: when one exists it decides the outcome, and the
        // teacher clears it to let the check-in records speak again. This is
        // checked BEFORE the "no logs -> absent" shortcut, which is the whole
        // point - a student on leave has no logs and must not read as absent.
        if ($leave) {
            return self::leave_result($session);
        }

        $t0 = (int) $session->timestart;
        $te = (int) $session->timeend;

        // Whether the session has an end-of-class check-in at all. When it does
        // not, the sign-out point is inert: a successful sign-in is the whole
        // record, so the sign-out point must not gate "absent" (via required)
        // nor drive the early-leave decision. The default is 1 so an activity
        // created before this option existed keeps its behaviour.
        $requiresignout = !isset($session->requiresignout) || !empty($session->requiresignout);

        // First and last point by sortorder.
        $ordered = array_values($points);
        usort($ordered, fn($a, $b) => $a->sortorder <=> $b->sortorder);
        $firstpoint = $ordered[0];
        $lastpoint = end($ordered);

        // Points that actually take part in the decision. With no sign-out
        // requirement the last point (the sign-out) is dropped, so "the last
        // point" below means the last point that matters.
        $activepoints = $ordered;
        $signoutpointid = 0;
        if (!$requiresignout && count($activepoints) > 1) {
            $signout = array_pop($activepoints);
            $signoutpointid = (int) $signout->id;
        }

        // The logs arrived as an argument - see the docblock. Nothing below
        // reads the database.

        // Logs against the (inert) sign-out point are ignored when a sign-out
        // is not required, so a stray checkout cannot masquerade as a sign-in.
        if ($signoutpointid) {
            $logs = array_filter($logs, fn($l) => (int) $l->pointid !== $signoutpointid);
        }

        if (!$logs) {
            return self::empty_result(self::STATUS_ABSENT);
        }

        $firstcheckin = null;
        $lastcheckin = null;
        foreach ($logs as $log) {
            if ($firstcheckin === null || $log->timecreated < $firstcheckin) {
                $firstcheckin = (int) $log->timecreated;
            }
            if ($lastcheckin === null || $log->timecreated > $lastcheckin) {
                $lastcheckin = (int) $log->timecreated;
            }
        }

        // How many required points did the user hit? Only points that still
        // take part in the decision count, so the inert sign-out point does not
        // make a signed-in student look absent.
        $requiredids = [];
        foreach ($activepoints as $p) {
            if (!empty($p->required)) {
                $requiredids[] = (int) $p->id;
            }
        }
        $hitrequired = [];
        foreach ($logs as $log) {
            if (in_array((int) $log->pointid, $requiredids, true)) {
                $hitrequired[(int) $log->pointid] = true;
            }
        }

        // If none of the required points were hit -> absent.
        if ($requiredids && count($hitrequired) === 0) {
            return self::empty_result(self::STATUS_ABSENT);
        }

        $latemin = 0;
        $earlymin = 0;
        $islate = false;
        $isearly = false;

        // ---- Late check: was the FIRST (sign-in) point hit after T0 + lategrace? ----
        $firstpointlogs = array_filter($logs, fn($l) => (int) $l->pointid === (int) $firstpoint->id);
        if ($firstpointlogs) {
            $arrival = min(array_map(fn($l) => (int) $l->timecreated, $firstpointlogs));
            $latethreshold = $t0 + (int) $session->lategrace;
            if ($arrival > $latethreshold) {
                $islate = true;
                $latemin = (int) floor(($arrival - $t0) / 60);
            }
        } else if (!empty($firstpoint->required)) {
            // Missed the required sign-in point entirely: count as late for the
            // full remaining duration so the report still shows a meaningful value.
            $islate = true;
            $latemin = (int) floor(max(0, $te - $t0) / 60);
        }

        // ---- Early-leave check ----
        // Two configurable modes:
        //   window    : the last (sign-out) point has NO record inside its own open window.
        //   threshold : the last recorded departure is earlier than timeend - earlygrace.
        //
        // Skipped entirely when the activity does not require a sign-out: with
        // no end-of-class check-in a successful sign-in is the whole record, so
        // the status stays present or late and no early-leave penalty applies.
        $earlymode = isset($session->earlymode) ? $session->earlymode : self::EARLYMODE_WINDOW;
        $lastpointlogs = array_filter($logs, fn($l) => (int) $l->pointid === (int) $lastpoint->id);

        if (!$requiresignout) {
            // No sign-out requirement: deliberately leave $isearly false, which
            // the initialiser above already did. Nothing to measure.
        } else if ($earlymode === self::EARLYMODE_WINDOW) {
            // Judge by whether the sign-out window was satisfied.
            $windowopen = $t0 + (int) $lastpoint->openoffset;   // no +0 clamp: keep author intent
            if (!$lastpointlogs) {
                if (!empty($lastpoint->required)) {
                    $isearly = true;
                    // Measure how long before the sign-out window opened they last were present.
                    $base = ($lastcheckin !== null) ? (int) $lastcheckin : $windowopen;
                    $earlymin = (int) floor(max(0, $windowopen - $base) / 60);
                }
            }
            // A record inside the window satisfies attendance; no early-leave penalty.
        } else {
            // Threshold mode: compare departure against timeend - earlygrace.
            $earlythreshold = $te - (int) $session->earlygrace;
            if ($lastpointlogs) {
                $departure = max(array_map(fn($l) => (int) $l->timecreated, $lastpointlogs));
                if ($departure < $earlythreshold) {
                    $isearly = true;
                    $earlymin = (int) floor(($te - $departure) / 60);
                }
            } else if (!empty($lastpoint->required)) {
                $isearly = true;
                $base = ($lastcheckin !== null) ? (int) $lastcheckin : $t0;
                $earlymin = (int) floor(max(0, $te - $base) / 60);
            }
        }

        // ---- Combine the two dimensions into a single status code ----
        // Priority: absent > late+early > early > late > present.
        if ($islate && $isearly) {
            $status = self::STATUS_LATE_EARLY;
        } else if ($isearly) {
            $status = self::STATUS_EARLY;
        } else if ($islate) {
            $status = self::STATUS_LATE;
        } else {
            $status = self::STATUS_PRESENT;
        }

        $awarded = self::points_for_status($status, $session);

        return [
            'statuscode' => $status,
            'firstcheckin' => $firstcheckin ?? 0,
            'lastcheckin' => $lastcheckin ?? 0,
            'latemin' => $latemin,
            'earlymin' => $earlymin,
            'points' => $awarded,
        ];
    }

    /**
     * Build an empty (absent) result.
     *
     * @param string $status Status code.
     * @return array
     */
    protected static function empty_result(string $status): array {
        return [
            'statuscode' => $status,
            'firstcheckin' => 0,
            'lastcheckin' => 0,
            'latemin' => 0,
            'earlymin' => 0,
            'points' => 0.0,
        ];
    }

    /**
     * Build the result for a student who is on leave.
     *
     * There are no check-in times to report - a leave is precisely the absence
     * of any - so the time and minute fields stay at zero and the report shows a
     * dash rather than inventing attendance. The points are the configured leave
     * value, which is the whole reason the status exists.
     *
     * @param \stdClass $session Session record.
     * @return array
     */
    protected static function leave_result(\stdClass $session): array {
        return [
            'statuscode' => self::STATUS_LEAVE,
            'firstcheckin' => 0,
            'lastcheckin' => 0,
            'latemin' => 0,
            'earlymin' => 0,
            'points' => self::points_for_status(self::STATUS_LEAVE, $session),
        ];
    }

    /**
     * Points awarded for a given status.
     *
     * The values come from the per-instance settings (status point values on
     * the activity form). Sessions created before those fields existed fall
     * back to the original hardcoded values, so behaviour is unchanged until
     * the teacher edits the activity.
     *
     * @param string $status Status code.
     * @param \stdClass $session Session record.
     * @return float
     */
    public static function points_for_status(string $status, \stdClass $session): float {
        switch ($status) {
            case self::STATUS_PRESENT:
                return isset($session->pointspresent) ? (float) $session->pointspresent : 2.0;
            case self::STATUS_LATE:
                return isset($session->pointslate) ? (float) $session->pointslate : 1.0;
            case self::STATUS_EARLY:
                return isset($session->pointsearly) ? (float) $session->pointsearly : 0.5;
            case self::STATUS_LATE_EARLY:
                return isset($session->pointslateearly) ? (float) $session->pointslateearly : 0.5;
            case self::STATUS_LEAVE:
                return isset($session->pointsleave) ? (float) $session->pointsleave : 0.5;
            case self::STATUS_ABSENT:
            default:
                return isset($session->pointsabsent) ? (float) $session->pointsabsent : 0.0;
        }
    }

    /**
     * Highest status value configured for a session.
     *
     * This is the natural full mark of the session: a student whose final
     * status carries the largest configured value earns the whole activity
     * grade. Kept public and pure (no database access) so the gradebook
     * normalisation and unit tests can both use it.
     *
     * @param \stdClass $session Session record.
     * @return float
     */
    public static function max_configured_points(\stdClass $session): float {
        return max(
            self::points_for_status(self::STATUS_PRESENT, $session),
            self::points_for_status(self::STATUS_LATE, $session),
            self::points_for_status(self::STATUS_EARLY, $session),
            self::points_for_status(self::STATUS_LATE_EARLY, $session),
            self::points_for_status(self::STATUS_ABSENT, $session),
            self::points_for_status(self::STATUS_LEAVE, $session)
        );
    }

    /**
     * Total possible points for a session (for gradebook normalisation).
     *
     * Since v1.4.0 this is the highest configured status value, not a multiple
     * of the check-in point count: the final status is awarded once per
     * session, so scaling by the number of points diluted the grade of every
     * student whenever the teacher added mid check-ins.
     *
     * @param int $sessionid Session id.
     * @return float
     */
    public static function total_possible_points(int $sessionid): float {
        global $DB;
        $session = $DB->get_record('multicheckin', ['id' => $sessionid],
            'pointspresent, pointslate, pointsearly, pointslateearly, pointsabsent, pointsleave');
        if (!$session) {
            return 0.0;
        }
        return self::max_configured_points($session);
    }

    /**
     * Persist a computed result.
     *
     * @param int $sessionid Session id.
     * @param int $userid User id.
     * @param array $result Result data.
     * @return void
     */
    protected static function save_result(int $sessionid, int $userid, array $result): void {
        global $DB;

        $existing = $DB->get_record('multicheckin_result', [
            'sessionid' => $sessionid,
            'userid' => $userid,
        ]);

        $record = (object) array_merge([
            'sessionid' => $sessionid,
            'userid' => $userid,
            'timecomputed' => time(),
            'timemodified' => time(),
        ], $result);

        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('multicheckin_result', $record);
        } else {
            $record->id = $DB->insert_record('multicheckin_result', $record);
        }
    }

    /**
     * Get the course module id for a session record.
     *
     * Only the template is referenced by course_modules.instance: an occurrence
     * is an extra row in the same table with parentid set and has no course
     * module at all. Looking one up with an occurrence id therefore finds
     * nothing, and MUST_EXIST turns that into an exception.
     *
     * That exception used to escape compute_session() and abort the whole
     * finalise task the moment it reached an occurrence, so a series never had
     * its statuses computed through the scheduled path and every student stayed
     * on "absent" no matter how many times they had checked in.
     *
     * @param \stdClass $session Session record.
     * @return int
     */
    protected static function get_cmid(\stdClass $session): int {
        $template = session_manager::get_template_for_session($session);
        $instanceid = $template ? (int) $template->id : (int) $session->id;
        $cm = get_coursemodule_from_instance('multicheckin', $instanceid, $session->course, false, MUST_EXIST);
        return (int) $cm->id;
    }

    /**
     * Human-readable label for a status code.
     *
     * @param string $code Status code.
     * @return string
     */
    public static function status_label(string $code): string {
        switch ($code) {
            case self::STATUS_PRESENT:
                return get_string('status_present', 'mod_multicheckin');
            case self::STATUS_LATE:
                return get_string('status_late', 'mod_multicheckin');
            case self::STATUS_EARLY:
                return get_string('status_early', 'mod_multicheckin');
            case self::STATUS_LATE_EARLY:
                return get_string('status_late_early', 'mod_multicheckin');
            case self::STATUS_LEAVE:
                return get_string('status_leave', 'mod_multicheckin');
            case self::STATUS_ABSENT:
            default:
                return get_string('status_absent', 'mod_multicheckin');
        }
    }

    /**
     * CSS class for a status code.
     *
     * Every token here has to actually exist somewhere, or the badge renders as
     * bare text. That is not a style preference: v2.0.12 exists because
     * "late and early" was returning Bootstrap's `bg-orange`, and Bootstrap 5
     * has no such colour — so the one status a teacher most wants to spot was
     * the one status with no background at all, on three screens at once
     * (the report, the student's own panel, and the whole-series matrix).
     *
     * The two orange-ish tokens are therefore the plugin's own class names, with
     * the rules in styles.css. A name that lives in the same repository as its
     * rule cannot drift away from it, and a theme that recolours `bg-warning`
     * cannot make late-and-early indistinguishable from late.
     *
     * @param string $code Status code.
     * @return string
     */
    public static function status_class(string $code): string {
        switch ($code) {
            case self::STATUS_PRESENT:
                return 'badge bg-success';
            case self::STATUS_LATE:
                return 'badge bg-warning text-dark';
            case self::STATUS_EARLY:
                return 'badge bg-info text-dark';
            case self::STATUS_LATE_EARLY:
                return 'badge multicheckin-status-lateearly';
            case self::STATUS_LEAVE:
                // Neutral, not a reward and not a punishment: leave is the one
                // status that is neither good nor bad news on its own.
                return 'badge bg-secondary';
            case self::STATUS_ABSENT:
            default:
                return 'badge bg-danger';
        }
    }
}
