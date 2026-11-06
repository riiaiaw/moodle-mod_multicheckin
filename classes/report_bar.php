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
 * The row above a report table: the export button, and what the numbers add up to.
 *
 * Two things live here rather than in the page scripts, for one reason each.
 *
 * The counting has to be testable. "How many were absent" is a number a teacher
 * reads and acts on, and a miscount is invisible: a summary that quietly drops
 * the excused students still adds up to something plausible. A page script
 * cannot be included by a test - it wants config.php and a logged-in session -
 * so the counting lives here as a pure function over rows the page already has.
 *
 * The markup lives here because two report pages draw the same button, and the
 * version of it that existed before was a single_button(): a <form> per button,
 * each form a block, so the export action sat on its own line with the browser's
 * default margins and no visual relation to anything around it. Writing the one
 * button once means the per-occurrence sheet and the whole-series matrix cannot
 * drift apart.
 *
 * No query is made here. The rows arrive already built, which is what keeps the
 * summary's cost at zero: it counts an array the page was going to produce
 * anyway.
 *
 * Everything here wears Bootstrap classes, or the one class the plugin's own
 * stylesheet defines. That is not tidiness: this plugin has already shipped a
 * badge asking for bg-orange, a name Bootstrap 5 does not define, and a name
 * with nothing behind it renders as nothing at all. A layout hook invented here
 * would be one more of those.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_bar {

    /**
     * The six statuses, in the order a teacher reads them: who was here, who
     * was here but late, and then the reasons they were not here.
     *
     * Declared here rather than derived from the rows: a status that nobody
     * happens to have this morning must still read as "0", because "0 absent"
     * and "absent is not a thing this report knows about" look identical when
     * the line is missing.
     *
     * @return string[]
     */
    public static function codes(): array {
        return [
            checkin_engine::STATUS_PRESENT,
            checkin_engine::STATUS_LATE,
            checkin_engine::STATUS_EARLY,
            checkin_engine::STATUS_LATE_EARLY,
            checkin_engine::STATUS_LEAVE,
            checkin_engine::STATUS_ABSENT,
        ];
    }

    /**
     * Count the roster by status.
     *
     * The total is the roster, not the sum of the six buckets: a row carrying a
     * code this report does not know about is still a person who was expected,
     * and pretending otherwise would make the two halves of the sentence
     * disagree ("42 expected, 41 accounted for" with nothing to explain the
     * gap). Such a row is counted in the total and in no bucket.
     *
     * @param \stdClass[] $rows Rows as built by the report, each with statuscode.
     * @return array{total: int, counts: array<string, int>}
     */
    public static function count(array $rows): array {
        $counts = array_fill_keys(self::codes(), 0);
        foreach ($rows as $row) {
            $code = isset($row->statuscode) ? (string) $row->statuscode : '';
            if (array_key_exists($code, $counts)) {
                $counts[$code]++;
            }
        }

        return ['total' => count($rows), 'counts' => $counts];
    }

    /**
     * Render the summary: the roster size, then one badge per status.
     *
     * The badges wear checkin_engine::status_class(), the same call the status
     * column of the table uses. That is deliberate: a teacher compares the two
     * by colour, and a summary that invented its own palette would invite them
     * to compare a colour that means one thing here and another there.
     *
     * The counts are always the WHOLE roster, never the filtered subset. The
     * badges are also the filter's controls, so a badge that showed "0" because
     * the reader had already filtered by another status would be a control that
     * vanished when it was used - there would be no way back to the others. The
     * width of the current view is printed separately as "showing X of Y", so
     * both sentences on the line are true at once.
     *
     * @param array{total: int, counts: array<string, int>} $summary As returned by count().
     * @param \moodle_url|null $baseurl Current page URL; when given, each badge becomes a link.
     * @param string $selected Status code currently filtered on, or '' for none.
     * @param int $shown How many rows the table is about to draw, or -1 when unfiltered.
     * @return string HTML.
     */
    public static function summary(array $summary, ?\moodle_url $baseurl = null,
            string $selected = '', int $shown = -1): string {
        $labels = [
            checkin_engine::STATUS_PRESENT => get_string('status_present', 'mod_multicheckin'),
            checkin_engine::STATUS_LATE => get_string('status_late', 'mod_multicheckin'),
            checkin_engine::STATUS_EARLY => get_string('status_early', 'mod_multicheckin'),
            checkin_engine::STATUS_LATE_EARLY => get_string('status_late_early', 'mod_multicheckin'),
            checkin_engine::STATUS_LEAVE => get_string('status_leave', 'mod_multicheckin'),
            checkin_engine::STATUS_ABSENT => get_string('status_absent', 'mod_multicheckin'),
        ];

        $out = '';

        // Only when the table is narrower than the roster: a line that always
        // reads "showing 42 of 42" is a line the reader learns to skip.
        if ($shown >= 0 && $shown !== (int) $summary['total']) {
            $out .= \html_writer::span(
                get_string('report_showing', 'mod_multicheckin',
                    (object) ['shown' => $shown, 'total' => $summary['total']]),
                'text-muted small me-2');
        }

        $out .= \html_writer::span(
            get_string('report_expectedcount', 'mod_multicheckin', $summary['total']),
            'fw-bold me-2');

        foreach (self::codes() as $code) {
            $count = (int) ($summary['counts'][$code] ?? 0);
            $class = checkin_engine::status_class($code);
            if ($selected === $code) {
                // A ring, not a new colour: the badge's colour is the status,
                // and a filter that repaints it makes the reader re-learn it.
                $class .= ' multicheckin-badge-selected';
            }
            $badge = \html_writer::span(($labels[$code] ?? $code) . ' ' . $count, $class);
            $out .= $baseurl === null ? $badge : \html_writer::link(
                new \moodle_url($baseurl, ['status' => $selected === $code ? 'all' : $code]),
                $badge);
        }

        // ms-sm-auto pushes the summary to the far end of the row on a screen
        // wide enough for one row, and stops doing so on a phone, where the
        // summary has wrapped onto its own line and belongs at its left edge
        // rather than floating to the right of nothing.
        return \html_writer::div($out, 'd-flex flex-wrap align-items-center gap-2 ms-sm-auto');
    }

    /**
     * Render the export button.
     *
     * A link, not a form: downloading a CSV is a GET the browser can follow on
     * its own, and single_button() wrapped that link in a <form> whose only
     * effect was to break the row in two. The primary colour marks it as the one
     * action this page offers; the icon is what makes it recognisable at a
     * glance on a projector.
     *
     * @param \moodle_url $url Export URL.
     * @return string HTML.
     */
    public static function export_button(\moodle_url $url): string {
        $icon = \html_writer::tag('i', '', ['class' => 'fa fa-download me-1', 'aria-hidden' => 'true']);

        return \html_writer::link($url, $icon . get_string('exportcsv', 'mod_multicheckin'), [
            'class' => 'btn btn-primary btn-sm',
            'role' => 'button',
        ]);
    }

    /**
     * Render the search box.
     *
     * A plain GET form, with nothing listening to it. Every condition the page
     * is currently using travels as a hidden field, so pressing Enter in the box
     * narrows the current view instead of resetting it - a search that quietly
     * drops the status filter is the same defect as a sort link that does.
     *
     * @param \moodle_url $baseurl The page's own URL, carrying id and session.
     * @param array $filters Current filters, as returned by report_filter::normalise().
     * @return string HTML.
     */
    public static function search_form(\moodle_url $baseurl, array $filters): string {
        $query = (string) ($filters['q'] ?? '');

        $out = \html_writer::start_tag('form', [
            'method' => 'get',
            'action' => $baseurl->out_omit_querystring(),
            'class' => 'd-flex flex-wrap align-items-center gap-2 multicheckin-report-search',
            'role' => 'search',
        ]);

        // Everything already in the URL, except the four controls themselves -
        // those are re-emitted below in their current state, so the form always
        // carries one copy of each.
        $own = ['sort', 'dir', 'status', 'q', 'download', 'all'];
        foreach ($baseurl->params() as $name => $value) {
            if (in_array($name, $own, true)) {
                continue;
            }
            $out .= \html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => $name,
                'value' => $value,
            ]);
        }
        if (($filters['sort'] ?? '') !== '') {
            $out .= \html_writer::empty_tag('input',
                ['type' => 'hidden', 'name' => 'sort', 'value' => $filters['sort']]);
            $out .= \html_writer::empty_tag('input',
                ['type' => 'hidden', 'name' => 'dir', 'value' => $filters['dir']]);
        }
        if (($filters['status'] ?? 'all') !== 'all') {
            $out .= \html_writer::empty_tag('input',
                ['type' => 'hidden', 'name' => 'status', 'value' => $filters['status']]);
        }

        $out .= \html_writer::empty_tag('input', [
            'type' => 'search',
            'name' => 'q',
            'value' => $query,
            'class' => 'form-control form-control-sm multicheckin-report-q',
            'placeholder' => get_string('report_search_placeholder', 'mod_multicheckin'),
            'aria-label' => get_string('report_search_placeholder', 'mod_multicheckin'),
        ]);
        $out .= \html_writer::tag('button', get_string('report_search', 'mod_multicheckin'), [
            'type' => 'submit',
            'class' => 'btn btn-outline-secondary btn-sm',
        ]);

        if ($query !== '') {
            // Only when there is something to clear: a permanent link that
            // clears nothing teaches the reader to ignore this row.
            $out .= \html_writer::link($baseurl, get_string('report_clear', 'mod_multicheckin'),
                ['class' => 'btn btn-link btn-sm']);
        }

        return $out . \html_writer::end_tag('form');
    }
}
