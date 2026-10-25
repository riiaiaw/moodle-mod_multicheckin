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
 * Plan a series of attendance occurrences from a repeat rule.
 *
 * This class is deliberately pure: it takes an array specification and returns
 * timestamps, with no database access, no global state and no formatting. Both
 * the activity form's live preview and the save path call plan(), so the dates
 * a teacher sees before submitting are produced by exactly the same code that
 * creates the sessions. Two implementations of the same rule would eventually
 * disagree and the preview would start lying.
 *
 * The spec array:
 *   repeat     string  none | weekly | daily | custom
 *   weekdays   int[]   ISO-8601 day numbers (1 = Monday ... 7 = Sunday), for 'weekly'
 *   interval   int     days between occurrences (1 = every day), for 'daily'
 *   datefrom   int     start date timestamp (only the calendar day is used)
 *   dateto     int     end date timestamp (only the calendar day is used)
 *   timefrom   string  'HH:MM' start clock time, for 'weekly' and 'daily'
 *   custom     array   explicit rows, for 'custom'. Each row is either
 *                      ['date' => 'YYYY-MM-DD', 'time' => 'HH:MM'] or the
 *                      single string 'YYYY-MM-DD HH:MM'. The clock time is the
 *                      site's own, not UTC.
 *   duration   int     session length in seconds, used to derive the end time
 *   timestart  int     fallback start timestamp, used by 'none'
 *   timeend    int     fallback end timestamp, used by 'none'
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schedule_planner {

    /** @var string Repeat mode: create no extra occurrences. */
    public const REPEAT_NONE = 'none';
    /** @var string Repeat mode: one occurrence per selected weekday each week. */
    public const REPEAT_WEEKLY = 'weekly';
    /** @var string Repeat mode: every N days. */
    public const REPEAT_DAILY = 'daily';
    /** @var string Repeat mode: an explicit list of dates and times. */
    public const REPEAT_CUSTOM = 'custom';

    /**
     * How many 'custom' rows the form shows before the teacher raises it.
     *
     * A teaching term is usually a handful of irregular dates with a stretch of
     * ordinary weeks between them, so five is enough to start on without making
     * the form look like a spreadsheet. The count field raises it, up to
     * max_sessions().
     */
    public const CUSTOM_DEFAULT_ROWS = 5;

    /**
     * Fallback ceiling for the number of occurrences in one series.
     *
     * A term has roughly 16 to 20 teaching weeks, so 60 leaves room for a
     * twice-weekly class plus resits while still stopping a mistyped end date
     * (a year, a decade) from creating tens of thousands of rows in one request.
     * Sites can change this with the 'maxsessions' plugin setting.
     */
    public const DEFAULT_MAX_SESSIONS = 60;

    /** @var int Absolute ceiling, whatever the site setting says. */
    public const HARD_MAX_SESSIONS = 500;

    /** @var int One day in seconds. */
    private const DAYSECS = 86400;

    /**
     * The effective cap on occurrences for this site.
     *
     * Reads the site-wide 'maxsessions' setting and clamps it into a sane
     * range, so a copy-pasted or hand-edited config value cannot disable the
     * guard entirely.
     *
     * @return int
     */
    public static function max_sessions(): int {
        $configured = (int) get_config('mod_multicheckin', 'maxsessions');
        if ($configured <= 0) {
            return self::DEFAULT_MAX_SESSIONS;
        }
        return max(1, min(self::HARD_MAX_SESSIONS, $configured));
    }

    /**
     * Compute the start timestamps of every occurrence in a series.
     *
     * Timestamps come back ascending, de-duplicated, and truncated to the
     * configured maximum. A malformed or empty spec yields an empty array
     * rather than an exception: the form validates the fields, and a planner
     * that throws would take the form down with it.
     *
     * @param array $spec Specification, see the class docblock.
     * @return int[] Start timestamps, ascending.
     */
    public static function plan(array $spec): array {
        $repeat = (string) ($spec['repeat'] ?? self::REPEAT_NONE);

        if ($repeat === self::REPEAT_NONE) {
            return self::plan_none($spec);
        }

        if ($repeat === self::REPEAT_CUSTOM) {
            return self::plan_custom($spec);
        }

        $max = self::max_sessions();

        if ($repeat === self::REPEAT_DAILY) {
            $dates = self::daily_dates($spec);
        } else if ($repeat === self::REPEAT_WEEKLY) {
            $dates = self::weekly_dates($spec);
        } else {
            return [];
        }

        // Sort and de-duplicate on the calendar day, then stamp the clock time
        // on. Weekly mode routinely reaches the same day twice for a weekday
        // that is also the start date, and a duplicated session would show up
        // as two identical rows the teacher then has to delete by hand.
        sort($dates, SORT_NUMERIC);
        $dates = array_values(array_unique($dates));

        [$hour, $minute] = self::clock($spec);

        $starts = [];
        foreach ($dates as $day) {
            $starts[] = $day + ($hour * 3600) + ($minute * 60);
            if (count($starts) >= $max) {
                break;
            }
        }

        return $starts;
    }

    /**
     * Human-readable preview of the plan, for the form and for tests.
     *
     * @param array $spec Specification.
     * @param int $limit How many entries to return.
     * @return array ['total' => int, 'capped' => bool, 'max' => int, 'items' => array]
     */
    public static function preview(array $spec, int $limit = 5): array {
        $starts = self::plan($spec);
        $max = ($spec['repeat'] ?? self::REPEAT_NONE) === self::REPEAT_NONE
            ? 0
            : self::max_sessions();

        $items = [];
        foreach ($starts as $ts) {
            $items[] = userdate($ts, get_string('strftimedatetime', 'langconfig'));
            if (count($items) >= $limit) {
                break;
            }
        }

        return [
            'total' => count($starts),
            'capped' => $max > 0 && count($starts) >= $max,
            'max' => $max,
            'items' => $items,
        ];
    }

    /**
     * Build the whole series of session records for one template.
     *
     * Returns plain objects ready for insert_record(): the shared settings are
     * copied from the template and only the occurrence-specific fields are
     * overridden. Pure, so the save path can be tested without a database.
     *
     * @param \stdClass $template The activity's template record.
     * @param int[] $starts Start timestamps from plan().
     * @return \stdClass[] Records, without ids.
     */
    public static function build_sessions(\stdClass $template, array $starts): array {
        $duration = max(0, (int) $template->timeend - (int) $template->timestart);
        $duration = $duration > 0 ? $duration : 3600;

        $records = [];
        $seq = 1;
        foreach ($starts as $start) {
            $record = clone $template;

            // A session is identified by its own id, which the database
            // assigns; carrying the template's id over would make the insert
            // collide with the template row or update it outright.
            unset($record->id);

            $record->parentid = (int) $template->id;
            $record->seriesid = (int) $template->id;
            $record->seqno = $seq++;
            $record->timestart = (int) $start;
            $record->timeend = (int) $start + $duration;
            $record->timemodified = time();

            $records[] = $record;
        }

        return $records;
    }

    /**
     * Weekday labels for the form's checkboxes.
     *
     * ISO-8601 numbering (Monday = 1) keeps the values aligned with PHP's own
     * 'N' format and with the calendar, so nothing has to translate between
     * conventions on the way in or out.
     *
     * @return array day number => label
     */
    public static function weekday_options(): array {
        $options = [];
        for ($day = 1; $day <= 7; $day++) {
            $options[$day] = get_string('weekday_' . $day, 'mod_multicheckin');
        }
        return $options;
    }

    /**
     * Expand a 'none' spec: the template's own window is the only occurrence.
     *
     * @param array $spec Specification.
     * @return int[]
     */
    private static function plan_none(array $spec): array {
        $start = (int) ($spec['timestart'] ?? 0);
        return $start > 0 ? [$start] : [];
    }

    /**
     * Expand a 'custom' spec: each row is one occurrence, taken as given.
     *
     * This is the escape hatch the other two modes cannot provide. A weekly rule
     * describes one clock time for the whole term, and a real timetable is not
     * like that: a lab moves, a public holiday shifts a week, one session starts
     * at 14:00 because the room was booked. Rather than growing ever more
     * elaborate rules, the teacher lists the dates.
     *
     * Rows that do not parse are dropped rather than throwing: validation is the
     * form's job, and a planner that throws would take the form down with it.
     * The site's occurrence cap still applies - it is a guard against a mistyped
     * import, and it protects this mode exactly as it protects the others.
     *
     * @param array $spec Specification.
     * @return int[] Start timestamps, ascending and de-duplicated.
     */
    private static function plan_custom(array $spec): array {
        $rows = $spec['custom'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $starts = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $date = (string) ($row['date'] ?? '');
                $time = (string) ($row['time'] ?? '');
            } else {
                // A single "YYYY-MM-DD HH:MM" string is accepted too, so a
                // caller (or a test) can describe a row in one piece.
                $parts = preg_split('/[\sT]+/', trim((string) $row), 2);
                $date = $parts[0] ?? '';
                $time = $parts[1] ?? '';
            }

            $timestamp = self::parse_custom_row($date, $time);
            if ($timestamp !== null) {
                $starts[] = $timestamp;
            }
        }

        if (!$starts) {
            return [];
        }

        sort($starts, SORT_NUMERIC);
        $starts = array_values(array_unique($starts));

        return array_slice($starts, 0, self::max_sessions());
    }

    /**
     * Turn one hand-entered date and clock time into a timestamp.
     *
     * Returns null - never throws - for anything that is not a real date or a
     * real 24-hour time, so both the form's validation and the planner can use
     * the same function and agree on what counts as valid. The time is read in
     * the site's timezone, which is the clock the person typing it is looking
     * at: a session entered as 08:00 has to start at 08:00 wherever the server
     * happens to be hosted.
     *
     * @param string $date Date as YYYY-MM-DD.
     * @param string $time Time as HH:MM (a blank time means midnight).
     * @return int|null Timestamp, or null when the row is not valid.
     */
    public static function parse_custom_row(string $date, string $time): ?int {
        $date = trim($date);
        $time = trim($time);

        if (!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $date, $m)) {
            return null;
        }
        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        if ($time === '') {
            $time = '00:00';
        }
        // The same HH:MM tolerance the repeat rule accepts, so a teacher who
        // types 8.30 in one place is not refused in another.
        if (!preg_match('/^(\d{1,2})[:.](\d{2})$/', $time, $t)) {
            return null;
        }
        $hour = (int) $t[1];
        $minute = (int) $t[2];
        if ($hour > 23 || $minute > 59) {
            return null;
        }

        $when = sprintf('%04d-%02d-%02d %02d:%02d:00', $year, $month, $day, $hour, $minute);
        $date = new \DateTime($when, \core_date::get_server_timezone_object());

        return $date->getTimestamp();
    }

    /**
     * Calendar days for a 'daily' spec.
     *
     * The start day is always included, and the end day is included whenever
     * the stride lands on it - the comparison is by calendar day, so a session
     * whose clock time falls late on the end date is not silently dropped.
     *
     * @param array $spec Specification.
     * @return int[] Midday timestamps, one per occurrence.
     */
    private static function daily_dates(array $spec): array {
        $from = self::day_start((int) ($spec['datefrom'] ?? 0));
        $to = self::day_start((int) ($spec['dateto'] ?? 0));
        $interval = (int) ($spec['interval'] ?? 1);
        if ($interval < 1) {
            $interval = 1;
        }

        if ($from <= 0 || $to <= 0 || $to < $from) {
            return [];
        }

        $dates = [];
        for ($day = $from; $day <= $to; $day += $interval * self::DAYSECS) {
            $dates[] = $day;
        }

        return $dates;
    }

    /**
     * Calendar days for a 'weekly' spec.
     *
     * Each selected weekday contributes one occurrence per week between the
     * start and end dates. The occurrence on the start day itself is kept even
     * when its clock time has already passed: a teacher setting a term up on
     * the morning of the first class, or filling in the form just after it
     * began, means that class to be part of the series.
     *
     * @param array $spec Specification.
     * @return int[] Midday timestamps, ascending.
     */
    private static function weekly_dates(array $spec): array {
        $from = self::day_start((int) ($spec['datefrom'] ?? 0));
        $to = self::day_start((int) ($spec['dateto'] ?? 0));

        if ($from <= 0 || $to <= 0 || $to < $from) {
            return [];
        }

        $weekdays = [];
        foreach ((array) ($spec['weekdays'] ?? []) as $day) {
            $day = (int) $day;
            if ($day >= 1 && $day <= 7) {
                $weekdays[$day] = true;
            }
        }
        if (!$weekdays) {
            return [];
        }

        $dates = [];

        // Walk forward from the start date's own weekday. Starting the scan a
        // few days earlier guarantees the first calendar day of the series is
        // considered, whatever weekday it falls on.
        $cursor = $from;
        while ($cursor <= $to) {
            if (isset($weekdays[self::iso_weekday($cursor)])) {
                $dates[] = $cursor;
            }
            $cursor += self::DAYSECS;
        }

        return $dates;
    }

    /**
     * Midnight of the calendar day containing a timestamp.
     *
     * Uses the timezone the site is configured with, so "the first of the
     * month" means that date to the people entering it. Public because the form
     * seeds the series' start date from the activity's own start time, and that
     * arithmetic has to be done in exactly one place.
     *
     * @param int $timestamp Timestamp.
     * @return int Midnight timestamp, or 0 when the input is empty.
     */
    public static function day_start(int $timestamp): int {
        if ($timestamp <= 0) {
            return 0;
        }
        $date = new \DateTime('@' . $timestamp);
        $date->setTimezone(\core_date::get_server_timezone_object());
        $date->setTime(0, 0, 0);
        return $date->getTimestamp();
    }

    /**
     * The clock time of a timestamp as 'HH:MM', in the site's timezone.
     *
     * The counterpart to day_start(): together they let the form answer "when
     * does the first session start" in the two pieces its own fields want,
     * without a second copy of the timezone handling.
     *
     * @param int $timestamp Timestamp.
     * @return string 'HH:MM', or '' when the input is empty.
     */
    public static function clock_string(int $timestamp): string {
        if ($timestamp <= 0) {
            return '';
        }
        $date = new \DateTime('@' . $timestamp);
        $date->setTimezone(\core_date::get_server_timezone_object());
        return $date->format('H:i');
    }

    /**
     * ISO-8601 weekday number of a midnight timestamp.
     *
     * @param int $timestamp Midnight timestamp.
     * @return int 1 = Monday ... 7 = Sunday.
     */
    private static function iso_weekday(int $timestamp): int {
        $date = new \DateTime('@' . $timestamp);
        $date->setTimezone(\core_date::get_server_timezone_object());
        return (int) $date->format('N');
    }

    /**
     * Read the clock time out of the spec.
     *
     * @param array $spec Specification.
     * @return array [hour, minute]
     */
    private static function clock(array $spec): array {
        $raw = trim((string) ($spec['timefrom'] ?? ''));

        if (preg_match('/^(\d{1,2})[:.](\d{2})$/', $raw, $m)) {
            $hour = (int) $m[1];
            $minute = (int) $m[2];
        } else {
            // Fall back to the time of day carried by the start date field,
            // which is what the teacher sees on screen.
            $start = (int) ($spec['timestart'] ?? 0);
            if ($start > 0) {
                $date = new \DateTime('@' . $start);
                $date->setTimezone(\core_date::get_server_timezone_object());
                $hour = (int) $date->format('G');
                $minute = (int) $date->format('i');
            } else {
                $hour = 9;
                $minute = 0;
            }
        }

        return [max(0, min(23, $hour)), max(0, min(59, $minute))];
    }
}
