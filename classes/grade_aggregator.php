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
 * Combine the per-occurrence results of an activity into one gradebook score.
 *
 * Once an activity carries a series of attendance occurrences, one score has to
 * come out of many. The activity keeps a single grade item - Moodle's gradebook
 * is where sub-grades get combined, and emitting a dozen items per activity
 * would crowd every course gradebook and leave the weighting to the teacher.
 *
 * Two rules are offered:
 *   - all:  every occurrence counts equally, which is what "attendance" usually
 *           means.
 *   - best: the best N occurrences count, which is the standard classroom rule
 *           that lets a student miss a few classes without losing the mark.
 *   - sum:  the occurrence scores are totalled rather than averaged, so each
 *           class adds to a running total. A term of ten classes worth two
 *           points each reaches twenty, which suits a course that treats every
 *           class as a fixed contribution rather than as one slice of a term.
 *
 * Occurrences a student has no result row for are excluded rather than counted
 * as zero. That keeps a half-finished term from dragging everyone's grade down
 * before those classes have happened, and it matches the old behaviour where a
 * session with no result simply contributed nothing.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_aggregator {

    /** @var string Aggregate mode: average every occurrence. */
    public const MODE_ALL = 'all';
    /** @var string Aggregate mode: average the best N occurrences. */
    public const MODE_BEST = 'best';
    /** @var string Aggregate mode: total the occurrence scores. */
    public const MODE_SUM = 'sum';

    /**
     * Aggregate the grades for a set of users.
     *
     * Two optional arguments let a caller that has ALREADY read this data hand
     * it in. The series report needs both - it reads every occurrence (for its
     * columns) and every result row (for its cells) before it asks for grades,
     * so asking again inside here was a second copy of two reads the page had
     * just paid for. Omit them, and the method reads what it needs exactly as
     * before; nothing about the answer depends on where the rows came from.
     *
     * @param \stdClass $template The activity template record.
     * @param float $totalpoints Highest configured status value for the activity.
     * @param int $userid Restrict to one user, or 0 for everyone with a result.
     * @param array|null $sessions Occurrences already read, keyed by id.
     * @param array|null $rows Every result row for those occurrences, as
     *                         get_records_sql() returns it (id => record).
     * @return array userid => grade scaled to the activity maximum.
     */
    public static function user_grades(\stdClass $template, float $totalpoints, int $userid = 0,
            ?array $sessions = null, ?array $rows = null): array {
        global $DB;

        $sessions = $sessions ?? session_manager::list_sessions($template);
        if (!$sessions) {
            return [];
        }

        $max = (float) ($template->grade ?: 100);
        $sessionids = array_keys($sessions);

        if ($rows === null || $userid) {
            // A caller that narrowed the read to one user must not hand in a
            // whole-class row set, so the shortcut is declined rather than
            // trusted: the rows are read here when $userid is set.
            list($insql, $params) = $DB->get_in_or_equal($sessionids, SQL_PARAMS_NAMED, 'sess');
            if ($userid) {
                $params['userid'] = $userid;
                $userclause = ' AND userid = :userid';
            } else {
                $userclause = '';
            }

            $rows = $DB->get_records_sql(
                "SELECT id, sessionid, userid, points
                   FROM {multicheckin_result}
                  WHERE sessionid {$insql} {$userclause}",
                $params
            );
        }

        // Group the per-occurrence percentages by user. A percentage rather
        // than raw points so sessions with different point values combine
        // sensibly.
        $peruser = [];
        foreach ($rows as $row) {
            $normalised = $totalpoints > 0
                ? min($max, ((float) $row->points / $totalpoints) * $max)
                : 0.0;
            $peruser[(int) $row->userid][] = $normalised;
        }

        $mode = self::mode($template);
        $best = self::best_count($template);

        // "Sum" totals the occurrences rather than averaging them, so an
        // occurrence the student has no result row for has to score zero
        // rather than be skipped: skipping it would let a student who attended
        // one class out of ten sit at the full mark. That is the whole point of
        // the mode — every class is a fixed contribution, and a missed one
        // contributes nothing. The number of occurrences therefore replaces the
        // number of result rows as the divisor in combine().
        $occurrencecount = count($sessionids);

        $grades = [];
        foreach ($peruser as $uid => $values) {
            $grades[$uid] = self::combine($values, $mode, $best, $occurrencecount);
        }

        return $grades;
    }

    /**
     * Combine one user's occurrence grades by the configured rule.
     *
     * Pure, so the rounding and the "fewer occurrences than N" case can be
     * tested without a database.
     *
     * @param array $values Grades for each occurrence the user has a result for.
     * @param string $mode MODE_ALL, MODE_BEST or MODE_SUM.
     * @param int $best N for MODE_BEST.
     * @param int $occurrencecount Total occurrences in the series, used by
     *        MODE_SUM as the divisor so missed occurrences count as zero.
     * @return float
     */
    public static function combine(array $values, string $mode, int $best, int $occurrencecount = 0): float {
        $values = array_values(array_filter($values, 'is_numeric'));
        if (!$values) {
            return 0.0;
        }

        if ($mode === self::MODE_SUM) {
            // Total the earned percentages, then divide by the number of
            // occurrences there are, not the number the student has rows for.
            // Ten classes at 2 points with 18 earned becomes 18/20, i.e. 90% of
            // the activity maximum, and a student with two missing rows is
            // scaled against ten as well so the missed classes pull the mark
            // down as they should.
            $divisor = $occurrencecount > 0 ? $occurrencecount : count($values);
            return round(array_sum($values) / $divisor, 5);
        }

        if ($mode === self::MODE_BEST && $best > 0 && $best < count($values)) {
            // Highest first, take the best N. Sorting descending rather than
            // ascending keeps rsort's ordering obvious with the slice below.
            rsort($values, SORT_NUMERIC);
            $values = array_slice($values, 0, $best);
        }

        return round(array_sum($values) / count($values), 5);
    }

    /**
     * The aggregation mode configured for an activity.
     *
     * @param \stdClass $template Template record.
     * @return string
     */
    public static function mode(\stdClass $template): string {
        $mode = (string) ($template->gradeaggregate ?? self::MODE_ALL);
        if ($mode === self::MODE_BEST) {
            return self::MODE_BEST;
        }
        if ($mode === self::MODE_SUM) {
            return self::MODE_SUM;
        }
        return self::MODE_ALL;
    }

    /**
     * How many occurrences the "best N" rule keeps.
     *
     * @param \stdClass $template Template record.
     * @return int
     */
    public static function best_count(\stdClass $template): int {
        $best = (int) ($template->gradebestcount ?? 0);
        return $best > 0 ? $best : 1;
    }
}
