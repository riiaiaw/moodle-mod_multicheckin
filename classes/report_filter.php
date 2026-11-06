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
 * Sorting, status filtering and keyword search for the attendance report.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_multicheckin;

defined('MOODLE_INTERNAL') || die();

/**
 * The four rules behind the report's controls, as pure functions.
 *
 * They live in a class for one reason: report.php cannot be included by a test
 * (it wants config.php and a logged-in session), and "sorted in the wrong
 * order" is a defect that looks exactly like a correct page. Wrong order still
 * renders, still exports, still adds up. So the rules are callable here, and
 * the page is left with nothing to decide.
 *
 * No query is made here and no HTML is produced: the rows arrive already built
 * by the page, which is what keeps this testable and free.
 *
 * A note on what this file must never do, because the plugin has already been
 * through it. The first version of these controls sorted names with
 * \core_collator::compare($a, $b) - a method that does not exist. The class
 * offers whole-table sorting only (asort, ksort, asort_objects_by_property,
 * asort_objects_by_method, asort_array_of_arrays_by_key) and calling anything
 * else is a fatal error the moment the report page opens, not a wrong order.
 * The check in validate_v14.sh that forbids the name is the reason this comment
 * can stay a paragraph instead of becoming a regression.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_filter {

    /** Sort by the student's full name, in the language of the course. */
    public const SORT_NAME = 'name';

    /** Sort by the student number as a human reads it (S2 before S10). */
    public const SORT_STUDENTID = 'studentid';

    /** Ascending order. */
    public const DIR_ASC = 'asc';

    /** Descending order. */
    public const DIR_DESC = 'desc';

    /** No status filter. */
    public const STATUS_ALL = 'all';

    /**
     * Longest search string kept. A query is a name or a number; anything far
     * past that is a paste accident, and an unbounded string would be carried
     * in every link on the page.
     */
    public const MAX_QUERY = 100;

    /**
     * The sort keys this report understands, in the order they appear in the
     * table head.
     *
     * @return string[]
     */
    public static function sorts(): array {
        return [self::SORT_NAME, self::SORT_STUDENTID];
    }

    /**
     * Clean the request parameters into the four values the rest of the file uses.
     *
     * Every value is checked against a whitelist rather than merely sanitised:
     * an unknown sort key, an unknown status letter or a direction that is
     * neither asc nor desc all fall back to the default silently. Silently,
     * because these arrive from bookmarked links and hand-edited URLs - a page
     * that refused to load over a stale parameter would be worse than one that
     * showed the default.
     *
     * @param array $params Raw request values, any of sort, dir, status, q.
     * @return array{sort: string, dir: string, status: string, q: string}
     */
    public static function normalise(array $params): array {
        $sort = (string) ($params['sort'] ?? '');
        if (!in_array($sort, self::sorts(), true)) {
            $sort = '';
        }

        // A direction with no sort key is not a state the page can render, so
        // it is dropped here rather than carried into every link.
        $dir = (string) ($params['dir'] ?? '');
        if ($sort === '' || $dir !== self::DIR_DESC) {
            $dir = self::DIR_ASC;
        }

        $status = (string) ($params['status'] ?? '');
        $allowed = array_merge([self::STATUS_ALL], report_bar::codes());
        if (!in_array($status, $allowed, true)) {
            $status = self::STATUS_ALL;
        }

        $q = trim((string) ($params['q'] ?? ''));
        if (\core_text::strlen($q) > self::MAX_QUERY) {
            $q = \core_text::substr($q, 0, self::MAX_QUERY);
        }

        return ['sort' => $sort, 'dir' => $dir, 'status' => $status, 'q' => $q];
    }

    /**
     * Whether anything is being filtered or re-sorted.
     *
     * Used to decide whether the page offers a "clear" link: a link that clears
     * nothing is noise, and a page that always shows one teaches the reader to
     * ignore the row it sits in.
     *
     * @param array $filters As returned by normalise().
     * @return bool
     */
    public static function is_active(array $filters): bool {
        return $filters['sort'] !== '' || $filters['status'] !== self::STATUS_ALL
            || $filters['q'] !== '';
    }

    /**
     * Keep the rows the reader asked to see.
     *
     * Status and search are applied together: a teacher looking at "absent" and
     * typing a surname wants the absent students with that surname, not one or
     * the other.
     *
     * @param \stdClass[] $rows Rows as built by the report page.
     * @param array $filters As returned by normalise().
     * @return \stdClass[] Re-indexed list of the rows that survive.
     */
    public static function filter(array $rows, array $filters): array {
        $status = (string) ($filters['status'] ?? self::STATUS_ALL);
        $q = (string) ($filters['q'] ?? '');

        if ($status === self::STATUS_ALL && $q === '') {
            // Nothing to do - but still returned as a list, so the caller never
            // has to care which branch produced its rows.
            return array_values($rows);
        }

        $needle = $q === '' ? '' : \core_text::strtolower($q);
        $out = [];
        foreach ($rows as $row) {
            if ($status !== self::STATUS_ALL && (string) $row->statuscode !== $status) {
                continue;
            }
            if ($needle !== '' && !self::matches($row, $needle)) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Whether a row matches a search string, already lower-cased by the caller.
     *
     * The name and the student number are both searched because both are what a
     * teacher has in front of them: a printed sheet of numbers, or a face they
     * are trying to put a name to. Searching only one of the two means half the
     * time the box looks broken.
     *
     * str_contains() on a lowered copy is a substring test, not a prefix test -
     * a family name in the middle of a full name has to be findable.
     *
     * @param \stdClass $row Report row.
     * @param string $needle Lower-cased search string, never empty.
     * @return bool
     */
    protected static function matches(\stdClass $row, string $needle): bool {
        $name = \core_text::strtolower((string) ($row->user ?? ''));
        if (str_contains($name, $needle)) {
            return true;
        }
        $number = \core_text::strtolower((string) ($row->username ?? ''));
        return str_contains($number, $needle);
    }

    /**
     * Put the rows in the order the reader asked for.
     *
     * Names go through core_collator's whole-table method, which is the only
     * way to get the site's language order (pinyin on a Chinese site) instead
     * of byte order. Student numbers use strnatcasecmp: they are ASCII, and
     * natural order is what makes S2 come before S10 - the collator would sort
     * them as text and put S10 first.
     *
     * @param \stdClass[] $rows Rows to order.
     * @param array $filters As returned by normalise().
     * @return \stdClass[] Re-indexed list in the requested order.
     */
    public static function sort(array $rows, array $filters): array {
        $key = (string) ($filters['sort'] ?? '');
        if ($key === '') {
            return array_values($rows);
        }

        $rows = array_values($rows);
        if ($key === self::SORT_NAME) {
            // Takes the array by reference and keeps its keys; the values end up
            // in collation order. array_values() below restores a plain list.
            \core_collator::asort_objects_by_property($rows, 'user');
        } else if ($key === self::SORT_STUDENTID) {
            usort($rows, function ($a, $b) {
                return strnatcasecmp((string) ($a->username ?? ''), (string) ($b->username ?? ''));
            });
        }

        if ((string) ($filters['dir'] ?? self::DIR_ASC) === self::DIR_DESC) {
            $rows = array_reverse($rows);
        }

        return array_values($rows);
    }
}
