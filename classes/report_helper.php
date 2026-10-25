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
 * Whole-series attendance report.
 *
 * An activity with a series has two useful reports and they answer different
 * questions. The per-occurrence sheet answers "who came to this class". This
 * one answers "how is this student doing": one row per student, one column per
 * occurrence, plus the aggregated grade that goes to the gradebook.
 *
 * Printing twenty separate reports to find one student who has missed half the
 * term is exactly the work this replaces, so the matrix is the primary layout
 * rather than a summary stitched together by the reader.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_helper {

    /**
     * Render (or download) the series attendance matrix.
     *
     * @param \stdClass $course Course record.
     * @param \stdClass $cm Course module record.
     * @param \context_module $context Module context.
     * @param \stdClass $template Activity template record.
     * @param array $sessions Occurrences keyed by id, ordered by start time.
     * @param bool $csv True to stream a CSV download instead of rendering the page.
     * @return void
     */
    public static function render_series_report(\stdClass $course, \stdClass $cm, \context_module $context,
            \stdClass $template, array $sessions, bool $csv = false): void {
        global $DB, $OUTPUT, $PAGE;

        // One roster for the whole series: the audience is a property of the
        // activity, so every occurrence answers with the same people and the
        // matrix cannot gain or lose a row between its columns.
        $users = audience_manager::participants($template, $context);
        $matrix = self::build_matrix($template, $sessions, $users);

        if ($csv) {
            self::send_csv($template, $sessions, $matrix);
            return;
        }

        $statuslabels = [
            checkin_engine::STATUS_PRESENT => get_string('status_present', 'mod_multicheckin'),
            checkin_engine::STATUS_LATE => get_string('status_late', 'mod_multicheckin'),
            checkin_engine::STATUS_EARLY => get_string('status_early', 'mod_multicheckin'),
            checkin_engine::STATUS_LATE_EARLY => get_string('status_late_early', 'mod_multicheckin'),
            checkin_engine::STATUS_ABSENT => get_string('status_absent', 'mod_multicheckin'),
            checkin_engine::STATUS_LEAVE => get_string('status_leave', 'mod_multicheckin'),
        ];

        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('reportall', 'mod_multicheckin'));

        // The activity name matters here because the matrix is reached from a
        // specific occurrence and covers all of them.
        echo \html_writer::div(format_string($template->name)
            . ' — ' . get_string('occurrence_count', 'mod_multicheckin', count($sessions)),
            'text-muted mb-3');

        $csvurl = new \moodle_url('/mod/multicheckin/report.php',
            ['id' => $cm->id, 'all' => 1, 'download' => 'csv']);
        // The same button the per-occurrence sheet draws, from the same place:
        // two reports that export the same way should not export in two
        // different-looking ways.
        echo \html_writer::div(report_bar::export_button($csvurl),
            'd-flex flex-wrap align-items-center gap-3 mb-3 multicheckin-report-bar');

        $table = new \html_table();
        $table->attributes['class'] = 'generaltable';
        $table->head = [
            get_string('user'),
            get_string('studentid', 'mod_multicheckin'),
        ];
        $seq = 0;
        foreach ($sessions as $session) {
            $seq++;
            // The column head is a short ordinal plus the date, because a
            // twenty-column table of full dates is unreadable when projected.
            $table->head[] = \html_writer::tag('span',
                get_string('occurrence_short', 'mod_multicheckin', $seq),
                ['class' => 'fw-bold'])
                . \html_writer::empty_tag('br')
                . userdate($session->timestart, get_string('strftimedateshort', 'langconfig'));
        }
        $table->head[] = get_string('attendancecount', 'mod_multicheckin');
        $table->head[] = get_string('grade');

        $table->data = [];
        foreach ($matrix['rows'] as $row) {
            $line = [$row->user, s($row->username)];
            foreach ($sessions as $session) {
                $cell = $row->cells[(int) $session->id] ?? null;
                if ($cell === null) {
                    $line[] = \html_writer::span('–', 'text-muted');
                } else {
                    $line[] = \html_writer::span(
                        $statuslabels[$cell->statuscode] ?? $cell->statuscode,
                        checkin_engine::status_class($cell->statuscode));
                }
            }
            $line[] = $row->attended . ' / ' . count($sessions);
            $line[] = $row->grade === null ? '-' : format_float($row->grade, 2);
            $table->data[] = $line;
        }

        echo \html_writer::table($table);

        echo \html_writer::div(self::aggregate_note($template), 'text-muted small mb-3');

        $backurl = new \moodle_url('/mod/multicheckin/view.php', ['id' => $cm->id]);
        echo \html_writer::div(
            \html_writer::link($backurl, get_string('back'),
                ['class' => 'btn btn-outline-secondary btn-sm']),
            'd-flex flex-wrap align-items-center gap-2 mb-3');

        echo $OUTPUT->footer();
    }

    /**
     * Gather one row of per-occurrence results per enrolled user.
     *
     * All results for the series are fetched in a single query and grouped in
     * PHP. Fetching per user per occurrence would be users × occurrences
     * round trips, which on a class of 120 over 24 sessions is 2880 queries for
     * a page whose whole point is that it is faster than opening 24 reports.
     *
     * @param \stdClass $template Activity template record.
     * @param array $sessions Occurrences keyed by id.
     * @param array $users Enrolled users keyed by id.
     * @return array ['rows' => array of stdClass, 'sessionids' => int[]]
     */
    public static function build_matrix(\stdClass $template, array $sessions, array $users): array {
        global $DB;

        $sessionids = array_map('intval', array_keys($sessions));
        $results = [];
        $resultrows = [];
        if ($sessionids) {
            list($insql, $params) = $DB->get_in_or_equal($sessionids, SQL_PARAMS_NAMED, 'sess');
            $rows = $DB->get_records_sql(
                "SELECT id, sessionid, userid, statuscode, points
                   FROM {multicheckin_result}
                  WHERE sessionid {$insql}",
                $params
            );
            // Kept as read: the grade column below is aggregated from these
            // same rows. Asking the aggregator to read them again was a second
            // copy of the heaviest query on this page.
            $resultrows = $rows;
            foreach ($rows as $row) {
                $results[(int) $row->userid][(int) $row->sessionid] = $row;
            }
        }

        $grades = grade_aggregator::user_grades($template, self::totalpoints($template), 0,
            $sessions, $resultrows);

        $out = [];
        foreach ($users as $user) {
            $cells = $results[(int) $user->id] ?? [];
            $attended = 0;
            foreach ($cells as $cell) {
                // "Attended" counts the statuses that mean the student was
                // actually there. A leave is not one of them: the absence was
                // excused, but the count is about presence and saying otherwise
                // would overstate it. Written as a list rather than "not
                // absent" so a later status cannot be counted by accident.
                if (in_array($cell->statuscode, [
                    checkin_engine::STATUS_PRESENT,
                    checkin_engine::STATUS_LATE,
                    checkin_engine::STATUS_EARLY,
                    checkin_engine::STATUS_LATE_EARLY,
                ], true)) {
                    $attended++;
                }
            }
            $out[] = (object) [
                'user' => fullname($user),
                // Same reading as the per-occurrence sheet: the username field,
                // which is where this site keeps student numbers. Carried on
                // the row rather than looked up again by the two renderers, so
                // the matrix and its CSV cannot disagree about who is who.
                'username' => (string) $user->username,
                'cells' => $cells,
                'attended' => $attended,
                'grade' => array_key_exists((int) $user->id, $grades) ? (float) $grades[(int) $user->id] : null,
            ];
        }

        return ['rows' => $out, 'sessionids' => $sessionids];
    }

    /**
     * The highest status point value configured for the activity.
     *
     * This is the denominator the check-in engine already uses when it turns
     * raw points into a grade, so the report scales its figures the same way
     * the gradebook does rather than inventing a second scale.
     *
     * @param \stdClass $template Activity template record.
     * @return float
     */
    public static function totalpoints(\stdClass $template): float {
        $values = [
            (float) ($template->pointspresent ?? 0),
            (float) ($template->pointslate ?? 0),
            (float) ($template->pointsearly ?? 0),
            (float) ($template->pointslateearly ?? 0),
            (float) ($template->pointsleave ?? 0),
        ];
        $max = max($values);
        return $max > 0 ? $max : 1.0;
    }

    /**
     * One sentence describing how the shown grade was combined.
     *
     * A number in a "grade" column is only trustworthy if the reader knows the
     * rule that produced it, and the rule is a per-activity setting that may
     * differ from the last activity they looked at.
     *
     * @param \stdClass $template Activity template record.
     * @return string Plain text.
     */
    public static function aggregate_note(\stdClass $template): string {
        if (grade_aggregator::mode($template) === grade_aggregator::MODE_BEST) {
            return get_string('aggregate_note_best', 'mod_multicheckin',
                grade_aggregator::best_count($template));
        }
        return get_string('aggregate_note_all', 'mod_multicheckin');
    }

    /**
     * Stream the matrix as a CSV download.
     *
     * @param \stdClass $template Activity template record.
     * @param array $sessions Occurrences keyed by id.
     * @param array $matrix Result of build_matrix().
     * @return void Never returns; the response is terminated here.
     */
    private static function send_csv(\stdClass $template, array $sessions, array $matrix): void {
        $filename = clean_filename($template->name . '_all_occurrences.csv');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        // BOM so Excel reads the Chinese names as UTF-8 rather than mojibake.
        fwrite($out, "\xEF\xBB\xBF");

        $header = [
            get_string('user'),
            get_string('studentid', 'mod_multicheckin'),
        ];
        $seq = 0;
        foreach ($sessions as $session) {
            $seq++;
            $header[] = get_string('occurrence_short', 'mod_multicheckin', $seq)
                . ' ' . userdate($session->timestart, get_string('strftimedateshort', 'langconfig'));
        }
        $header[] = get_string('attendancecount', 'mod_multicheckin');
        $header[] = get_string('grade');
        fputcsv($out, $header);

        $statuslabels = [
            checkin_engine::STATUS_PRESENT => get_string('status_present', 'mod_multicheckin'),
            checkin_engine::STATUS_LATE => get_string('status_late', 'mod_multicheckin'),
            checkin_engine::STATUS_EARLY => get_string('status_early', 'mod_multicheckin'),
            checkin_engine::STATUS_LATE_EARLY => get_string('status_late_early', 'mod_multicheckin'),
            checkin_engine::STATUS_ABSENT => get_string('status_absent', 'mod_multicheckin'),
            checkin_engine::STATUS_LEAVE => get_string('status_leave', 'mod_multicheckin'),
        ];

        foreach ($matrix['rows'] as $row) {
            $line = [$row->user, $row->username];
            foreach ($sessions as $session) {
                $cell = $row->cells[(int) $session->id] ?? null;
                $line[] = $cell === null ? '' : ($statuslabels[$cell->statuscode] ?? $cell->statuscode);
            }
            $line[] = $row->attended . '/' . count($sessions);
            $line[] = $row->grade === null ? '' : format_float($row->grade, 2);
            fputcsv($out, $line);
        }

        fclose($out);
        exit;
    }
}
