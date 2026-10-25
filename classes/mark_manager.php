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

// Removing a check-in log has to take its photo with it, so mark_manager needs
// photo_manager. Deletes run from pages opened directly by URL, where the
// component's autoloader is not always primed; requiring it here (the same
// pattern session_manager uses for schedule_planner) keeps it available.
require_once(__DIR__ . '/photo_manager.php');

/**
 * Teacher-side editing of check-in records.
 *
 * Students create their own records through checkin.php; this class is the other
 * half of that, the path a teacher uses when the record is wrong or missing. Two
 * situations cover almost all of it: a student's phone died (or they had no
 * signal) so nothing was recorded, and a student signed in for a friend so the
 * record has to come back out. The first is an insert, the second a delete, and
 * a teacher correcting a clock time is an update - all three live here.
 *
 * Three rules shape the implementation.
 *
 *   - The check-in LOG is the only thing written. Statuses, late/early minutes
 *     and points are all derived from it by {@see checkin_engine::compute_user()},
 *     so marking a log and recomputing is enough; nothing else has to be kept in
 *     step by hand and no path exists that could leave the two disagreeing.
 *
 *   - A time the teacher enters must fall inside that point's own window. The
 *     window is the definition of when a check-in was possible at all, and a
 *     record outside it would be contradicted by the configuration it was
 *     matched against. A value that has not been touched is exempt: records
 *     already in the table are shown back as they are, and re-saving a form must
 *     not turn a legitimate stored time into an error.
 *
 *   - Rows written here are marked as manual. That is the whole audit trail for
 *     this feature by design: seeing "teacher-entered" next to a record is what
 *     matters, and no extra column or history table was wanted. It follows that
 *     editing an existing record replaces its origin marker - the time is now
 *     the teacher's assertion, not the student's.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mark_manager {

    /** @var string Method stored on a record a teacher created or corrected. */
    public const METHOD_MANUAL = 'manual';

    /**
     * Is a submitted clock string well formed?
     *
     * Only 24-hour "HH:MM" is accepted. The form renders exactly that, and
     * accepting loose formats here would mean two different parsers deciding
     * what a time is.
     *
     * @param string $clock Submitted value.
     * @return bool
     */
    public static function is_clock(string $clock): bool {
        return (bool) preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $clock);
    }

    /**
     * Render a timestamp as the "HH:MM" the form shows.
     *
     * Built from usergetdate() rather than a format string so the output cannot
     * drift with the date-formatting API: the value has to round-trip back
     * through resolve_time() unchanged, and a locale that changed the separator
     * would break that.
     *
     * @param int $time Timestamp.
     * @return string
     */
    public static function clock_from(int $time): string {
        $date = usergetdate($time);
        return sprintf('%02d:%02d', $date['hours'], $date['minutes']);
    }

    /**
     * Turn a clock choice into an absolute timestamp on the point's window day.
     *
     * The teacher picks a time of day, not a date: a check-in belongs to the
     * session being edited, so the date comes from the point's window and only
     * the clock is taken from the input. Assembling it with make_timestamp() in
     * the user's timezone is what keeps the stored value matching what the
     * teacher saw on screen.
     *
     * A window that crosses midnight (a 23:30 sign-out closing at 00:30) can
     * legitimately need the following day, so the result is rolled forward once
     * when that puts it inside the window.
     *
     * @param string $clock "HH:MM".
     * @param int $open Window start.
     * @param int $close Window end.
     * @return int|null Timestamp, or null when the string is malformed.
     */
    public static function resolve_time(string $clock, int $open, int $close): ?int {
        if (!self::is_clock($clock)) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', $clock));
        $date = usergetdate($open);

        $time = make_timestamp((int) $date['year'], (int) $date['mon'], (int) $date['mday'],
            $hour, $minute, 0, 99);

        if ($time < $open && $time + DAYSECS <= $close) {
            $time += DAYSECS;
        }

        return $time;
    }

    /**
     * Collapse the raw log rows of one point into the state the form shows.
     *
     * checkin.php refuses a second check-in for the same point, so in practice
     * there is at most one row and "the" time is unambiguous. Should more than
     * one exist - restored data, a manual insert from outside Moodle - the
     * earliest is treated as the record and the rest as duplicates to be merged,
     * which is also what compute_user() concludes, since it takes firstcheckin
     * as the minimum over the rows.
     *
     * @param array $logs Log rows for one user and point, ascending by time.
     * @return array{checked: bool, time: int, clock: string, method: string, count: int}
     */
    public static function state_from_logs(array $logs): array {
        if (!$logs) {
            return ['checked' => false, 'time' => 0, 'clock' => '', 'method' => '', 'count' => 0];
        }

        $first = reset($logs);

        return [
            'checked' => true,
            'time' => (int) $first->timecreated,
            'clock' => self::clock_from((int) $first->timecreated),
            'method' => (string) ($first->method ?? ''),
            'count' => count($logs),
        ];
    }

    /**
     * Every log row of a session, grouped by user and point.
     *
     * Read once and grouped in PHP so the class-name table (one row per student)
     * costs a single query rather than one per cell.
     *
     * @param \stdClass $session Session record.
     * @param int[] $userids Restrict to these users, or empty for everyone.
     * @return array<int, array<int, array>> [userid][pointid] => log rows.
     */
    public static function session_logs(\stdClass $session, array $userids = []): array {
        global $DB;

        $params = ['sessionid' => (int) $session->id];
        $where = 'sessionid = :sessionid';
        if ($userids) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_map('intval', $userids), SQL_PARAMS_NAMED, 'uid');
            $where .= " AND userid $insql";
            $params += $inparams;
        }

        $rows = $DB->get_records_select('multicheckin_log', $where, $params, 'timecreated ASC, id ASC');

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row->userid][(int) $row->pointid][] = $row;
        }

        return $grouped;
    }

    /**
     * The mark state of every point for one user.
     *
     * @param \stdClass $session Session record.
     * @param array $points Point records keyed by id.
     * @param int $userid User id.
     * @return array<int, array> Point id => state, as from state_from_logs().
     */
    public static function point_states(\stdClass $session, array $points, int $userid): array {
        $grouped = self::session_logs($session, [$userid]);
        $logs = $grouped[$userid] ?? [];

        $states = [];
        foreach ($points as $point) {
            $states[(int) $point->id] = self::state_from_logs($logs[(int) $point->id] ?? []);
        }

        return $states;
    }

    /**
     * The default clock for a point: the start of its own window.
     *
     * Signing in "on time" is the common correction, and the window start is the
     * instant that means it. It is also always inside the window, so the value
     * the form opens with can never itself be rejected.
     *
     * @param \stdClass $session Session record.
     * @param \stdClass $point Point record.
     * @return string "HH:MM".
     */
    public static function default_clock(\stdClass $session, \stdClass $point): string {
        [$open] = session_manager::point_window($session, $point);
        return self::clock_from($open);
    }

    /**
     * Check a submitted clock against a point's window.
     *
     * One rule set, used by both the form (to show inline errors) and
     * apply_marks() (to decide what is written). Splitting the rule across the
     * two would let the form accept a time the writer then refuses, and the
     * refusal would surface as silence rather than as an error.
     *
     * A stored time that sits a second or two outside the window - written at
     * the boundary, before a grace period was shortened - is shown back
     * unchanged. Re-submitting it is not an edit, so it must not be reported as
     * an error the teacher has to fix.
     *
     * @param string $clock "HH:MM", or '' to keep whatever is stored.
     * @param int $open Window start.
     * @param int $close Window end.
     * @param int|null $current Stored time for this point, if any.
     * @return string|null Error key, or null when acceptable.
     */
    public static function check_clock(string $clock, int $open, int $close, ?int $current): ?string {
        if ($clock === '') {
            return null;
        }

        $resolved = self::resolve_time($clock, $open, $close);
        if ($resolved === null) {
            return 'mark_time_invalid';
        }
        if ($current !== null && self::clock_from($current) === $clock) {
            return null;
        }
        if ($resolved < $open || $resolved > $close) {
            return 'mark_time_outside';
        }

        return null;
    }

    /**
     * Apply the submitted marks for one user.
     *
     * @param \stdClass $session Session record.
     * @param array $points Point records keyed by id.
     * @param int $userid The student whose record is being edited.
     * @param array $submitted Point id => ['checked' => bool, 'clock' => string].
     * @return array{added: int, updated: int, removed: int, unchanged: int, errors: array}
     *         Errors are keyed by point id. Nothing is written for a point that
     *         failed validation; the rest of the form still applies.
     */
    public static function apply_marks(\stdClass $session, array $points, int $userid,
            array $submitted): array {
        global $DB;

        $totals = ['added' => 0, 'updated' => 0, 'removed' => 0, 'unchanged' => 0, 'errors' => []];

        $grouped = self::session_logs($session, [$userid]);
        $logs = $grouped[$userid] ?? [];

        foreach ($points as $point) {
            $pointid = (int) $point->id;
            if (!array_key_exists($pointid, $submitted)) {
                // The form did not carry this point at all - a stale page after
                // the points were reconfigured. Leaving the record alone is the
                // only safe reading; acting on it would delete data the teacher
                // never saw.
                continue;
            }

            $entry = $submitted[$pointid];
            $checked = !empty($entry['checked']);
            $clock = trim((string) ($entry['clock'] ?? ''));
            $existing = $logs[$pointid] ?? [];

            if (!$checked) {
                if ($existing) {
                    // The evidence goes with the record. A photograph is filed
                    // under the log row's id, so leaving the row's photo behind
                    // would file it under an id nothing can ever look up again.
                    photo_manager::delete_for_logs($session, array_map(fn($l) => (int) $l->id, $existing));
                    $DB->delete_records('multicheckin_log', [
                        'sessionid' => (int) $session->id,
                        'pointid' => $pointid,
                        'userid' => $userid,
                    ]);
                    $totals['removed']++;
                } else {
                    $totals['unchanged']++;
                }
                continue;
            }

            [$open, $close] = session_manager::point_window($session, $point);

            $current = $existing ? (int) reset($existing)->timecreated : null;

            // Whether the teacher actually changed this point. The clock input
            // carries HH:MM and nothing finer, so this comparison has to happen
            // at minute precision. Comparing timestamps would treat every real
            // check-in - which has seconds - as edited the moment the page is
            // saved, and re-stamp the student's own record as a teacher entry.
            // "Saved the page" is not "edited the record".
            if ($clock === '') {
                // No clock submitted: the stored time stands, or the window
                // start is used for a new record.
                $target = $current ?? $open;
                $same = $existing !== [];
            } else if ($current !== null && self::clock_from($current) === $clock) {
                // Same minute as stored: there is nothing to write. Accepted
                // without the window check as well - a point whose window has
                // since moved must stay savable exactly as it stands, or the
                // teacher cannot correct any other row without this one being
                // refused.
                $target = $current;
                $same = true;
            } else {
                $error = self::check_clock($clock, $open, $close, $current);
                if ($error !== null) {
                    $totals['errors'][$pointid] = $error;
                    continue;
                }
                $target = self::resolve_time($clock, $open, $close);
                $same = false;
            }

            if (!$existing) {
                self::insert_log($session, $pointid, $userid, $target);
                $totals['added']++;
                continue;
            }

            if ($same) {
                if (count($existing) > 1) {
                    // Duplicate rows for one point are housekeeping, not an
                    // edit: the earliest row is kept exactly as it is - same
                    // time, same method - and only the extras are removed.
                    self::drop_duplicates($session, $existing,
                        (int) reset($existing)->id);
                    $totals['updated']++;
                } else {
                    $totals['unchanged']++;
                }
                continue;
            }

            // A real edit: keep the earliest row as the record, re-stamp it as
            // the teacher's, and drop any duplicates.
            $keep = (int) reset($existing)->id;
            $DB->update_record('multicheckin_log', (object) [
                'id' => $keep,
                'timecreated' => $target,
                'method' => self::METHOD_MANUAL,
            ]);
            self::drop_duplicates($session, $existing, $keep);
            $totals['updated']++;
        }

        return $totals;
    }

    /**
     * Give every user without a record one at the start of the point's window.
     *
     * This is the "the QR code never went up and the whole class was present"
     * case, and it is deliberately additive: an existing record is never touched,
     * so a student who was genuinely late keeps the time they signed in at and
     * only the gaps are filled. Running it twice changes nothing the second time.
     *
     * @param \stdClass $session Session record.
     * @param array $points Point records keyed by id.
     * @param int[] $userids Users to consider.
     * @return int Number of records created.
     */
    public static function fill_missing(\stdClass $session, array $points, array $userids): int {
        $created = 0;

        $grouped = self::session_logs($session, $userids);

        foreach ($userids as $userid) {
            $userid = (int) $userid;
            $logs = $grouped[$userid] ?? [];
            foreach ($points as $point) {
                if (!empty($logs[(int) $point->id])) {
                    continue;
                }
                [$open] = session_manager::point_window($session, $point);
                self::insert_log($session, (int) $point->id, $userid, $open);
                $created++;
            }
        }

        return $created;
    }

    /**
     * Write one teacher-entered check-in record.
     *
     * ip is left empty on purpose. The column records where a CHECK-IN came
     * from, which is evidence in the student's own case; filling it with the
     * teacher's address would attach a false claim to the row.
     *
     * @param \stdClass $session Session record.
     * @param int $pointid Point id.
     * @param int $userid Student id.
     * @param int $time Timestamp to record.
     * @return int New log id.
     */
    protected static function insert_log(\stdClass $session, int $pointid, int $userid, int $time): int {
        global $DB;

        return (int) $DB->insert_record('multicheckin_log', (object) [
            'pointid' => $pointid,
            'sessionid' => (int) $session->id,
            'userid' => $userid,
            'timecreated' => $time,
            'ip' => null,
            'method' => self::METHOD_MANUAL,
        ]);
    }

    /**
     * Remove the extra log rows of one point, keeping the chosen one.
     *
     * Duplicates only arise from data that got in another way - restored
     * backups, a manual insert from outside Moodle - and merging them is
     * housekeeping, so nothing about the surviving row is rewritten here. The
     * photographs go first: they are filed under the log row id, so once the
     * row is gone there is nothing left to find them by.
     *
     * @param \stdClass $session Session record.
     * @param array $logs Log rows for one user and point.
     * @param int $keep Id of the row to keep.
     * @return void
     */
    protected static function drop_duplicates(\stdClass $session, array $logs, int $keep): void {
        global $DB;

        foreach ($logs as $log) {
            if ((int) $log->id === $keep) {
                continue;
            }
            photo_manager::delete_for_logs($session, [(int) $log->id]);
            $DB->delete_records('multicheckin_log', ['id' => (int) $log->id]);
        }
    }

    /**
     * Whether a stored log row was entered or corrected by a teacher.
     *
     * @param \stdClass $log Log row.
     * @return bool
     */
    public static function is_manual(\stdClass $log): bool {
        return (string) ($log->method ?? '') === self::METHOD_MANUAL;
    }

    /**
     * Whether a state (as from state_from_logs()) came from a teacher entry.
     *
     * @param array $state State array.
     * @return bool
     */
    public static function state_is_manual(array $state): bool {
        return !empty($state['checked']) && (string) ($state['method'] ?? '') === self::METHOD_MANUAL;
    }

    /**
     * Recompute what a save changed, and push the outcome where it has to land.
     *
     * The width of this work has to equal the width of the change. Two of the
     * three callers (mark.php's single-student sheet, leaves.php's "clear this
     * one leave") change ONE student's logs, and the third (markall.php) marks
     * the whole class. Recomputing sixty rows because one teacher ticked one
     * box writes fifty-nine rows whose values the next save writes again, and
     * the gradebook gets sixty push calls for one changed grade - the same
     * defect 2.1.0 removed from the photo approval and the leave page.
     *
     * The order still matters: the statuses are derived from the logs first and
     * the grade is pushed from them, so the push reads what the recompute just
     * wrote.
     *
     * @param \stdClass $session Session record (template or occurrence).
     * @param int $userid 0 to recompute the whole class, or one student's row.
     * @return void
     */
    public static function resync(\stdClass $session, int $userid = 0): void {
        $template = session_manager::get_template_for_session($session);

        if ($userid > 0) {
            checkin_engine::refresh_user($session, $userid);
        } else {
            checkin_engine::compute_session((int) $session->id);
        }
        // Through the class, never through the lib.php callback: this method is
        // only reached from pages today, where require_login() has loaded
        // lib.php, but the same call from an external function or a task would
        // die on the missing function.
        grade_helper::update_grades($template ?? $session, $userid);
    }

    /**
     * A one-line summary of what a save changed, for the redirect message.
     *
     * @param array $totals Totals as returned by apply_marks().
     * @return string
     */
    public static function summarise(array $totals): string {
        return get_string('marks_saved', 'mod_multicheckin', (object) [
            'added' => (int) $totals['added'],
            'updated' => (int) $totals['updated'],
            'removed' => (int) $totals['removed'],
        ]);
    }
}
