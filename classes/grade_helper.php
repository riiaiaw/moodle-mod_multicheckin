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

/**
 * Aggregate one activity's occurrence results and push them into the gradebook.
 *
 * This logic used to sit in lib.php as the two module callbacks
 * multicheckin_get_user_grades() / multicheckin_update_grades(). Those callbacks
 * still exist and still behave identically - they now delegate here - but the
 * scheduled task calls THIS class directly.
 *
 * The reason is load order, not tidiness. Moodle only includes
 * mod/multicheckin/lib.php on a request that passes through require_login();
 * cron, CLI and adhoc tasks never do. So a namespaced file that calls the global
 * callback by its bare name dies with
 * "Call to undefined function mod_multicheckin\task\multicheckin_update_grades()"
 * the moment the line runs. That is exactly what task\finalise_sessions did on
 * every run once an activity had ended, and it stayed invisible because an empty
 * set of finished sessions never reaches that line.
 *
 * Anything a task (or any other non-web entry point) needs must therefore live
 * in a class, which the autoloader can always find, and must not fall back to a
 * lib.php function. The global functions in lib.php are allowed to call in here;
 * the direction must never be reversed.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_helper {
    /**
     * Return the grade for a user, aggregated over the activity's occurrences.
     *
     * The record passed in is the activity template (cm->instance), which is
     * also what the grade item is keyed on, so the gradebook sees one item per
     * activity however many occurrences it holds.
     *
     * @param \stdClass $session Instance record (the template).
     * @param int $userid User id (0 = all).
     * @return array|null userid => raw grade, or null when there is nothing to push.
     */
    public static function get_user_grades(\stdClass $session, int $userid = 0) {
        // Grade items are keyed on the activity template, so an occurrence has
        // to be resolved back to it before anything grade-shaped happens here.
        // Moodle core already passes the template; our own pages can hold an
        // occurrence.
        $session = session_manager::get_template_for_session($session) ?: $session;

        if (empty($session->grade)) {
            return null;
        }

        $totalpoints = checkin_engine::total_possible_points((int) $session->id);
        if ($totalpoints <= 0) {
            return $userid ? null : [];
        }

        $aggregated = grade_aggregator::user_grades($session, $totalpoints, $userid);

        if ($userid) {
            if (!isset($aggregated[$userid])) {
                return null;
            }
            // Keyed, and the same value type the whole-class branch below
            // returns. update_grades() walks this answer with foreach and reads
            // each value as a number, so a bare object here - which is what this
            // did - made that walk iterate the object's properties and hand
            // grade_update() an item whose userid was the string 'rawgrade'.
            //
            // It never showed up because nothing called this with a userid until
            // a single check-in started needing to push only its own student.
            // The key is the whole point: it is what lets one student be pushed
            // without the other fifty-nine being rewritten with the values they
            // already have.
            return [$userid => (float) $aggregated[$userid]];
        }
        return $aggregated;
    }

    /**
     * Update the gradebook entries for this activity.
     *
     * @param \stdClass $session Instance record (the template).
     * @param int $userid Optional single user id.
     * @return bool True on success.
     */
    public static function update_grades(\stdClass $session, int $userid = 0): bool {
        self::require_gradelib();

        // The grade item belongs to the activity, not to one of its
        // occurrences. Pushing with an occurrence id would key grade_update() to
        // an instance that no course module points at, leaving a phantom item
        // in the gradebook, so an occurrence is resolved back to its template
        // first.
        $session = session_manager::get_template_for_session($session) ?: $session;

        $grades = self::get_user_grades($session, $userid);
        if ($grades === null) {
            return true;
        }

        $max = (float) ($session->grade ?: 100);

        // The aggregator already normalises to the gradebook maximum: it
        // divides each occurrence's earned points by the highest configured
        // status value, scales that to $max, then combines the occurrences by
        // the configured rule. The clamp guards odd configurations where a
        // non-present status carries a larger value than the present one.
        $items = [];
        foreach ($grades as $u => $raw) {
            $item = new \stdClass();
            $item->userid = $u;
            $item->rawgrade = min($max, round((float) $raw, 5));
            $items[$u] = $item;
        }

        // Positional arguments, and every position matters. Core's signature is
        // grade_update($source, $courseid, $itemtype, $itemmodule, $iteminstance,
        // $itemnumber, $grades, $itemdetails, $isbulkupdate) - the grades array
        // is the SEVENTH. Handing it over one place late puts it in
        // $itemdetails, where only ten item keys survive and userid is not one
        // of them, so no grade is written and the call still reports success.
        // That is how this shipped for several versions with the gradebook
        // permanently empty while every page looked fine.
        grade_update(
            'mod/multicheckin',
            $session->course,
            'mod',
            'multicheckin',
            $session->id,
            0,
            $items,
            self::item_details($session)
        );

        return true;
    }

    /**
     * The grade item definition this activity pushes.
     *
     * One definition, two callers: update_grades() above and the
     * multicheckin_grade_item_update() callback in lib.php. Moodle's
     * grade_update_mod_grades() looks for that callback beside
     * multicheckin_update_grades() and, finding only one of the pair, debugs
     * "You have declared one of ... but not both" and pushes nothing - and that
     * is the path both the gradebook's recalculate and the course reset take.
     *
     * @param \stdClass $session Instance record (the template).
     * @return array Item details in the shape grade_update() expects.
     */
    public static function item_details(\stdClass $session): array {
        self::require_gradelib();

        $name = format_string($session->name ?? '');
        $max = (float) ($session->grade ?: 0);
        if ($max <= 0) {
            // An activity that is not graded has no grade item. gradelib treats
            // GRADE_TYPE_NONE as "nothing to build" and returns before creating
            // one, which is what a zero-maximum item would otherwise become: a
            // blank row in every course gradebook.
            return ['itemname' => $name, 'gradetype' => GRADE_TYPE_NONE];
        }

        return [
            'itemname' => $name,
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => $max,
            'grademin' => 0,
        ];
    }

    /**
     * Create or refresh the grade item, optionally pushing grades with it.
     *
     * This is the body of lib.php's multicheckin_grade_item_update() callback.
     * It belongs here rather than there for the same reason update_grades()
     * does: grade_update_mod_grades() runs from cron and from the gradebook,
     * where lib.php was never included.
     *
     * @param \stdClass $session Instance record (the template).
     * @param array|string|null $grades Grades to push, or the string 'reset'.
     * @return int One of the GRADE_UPDATE_* status codes.
     */
    public static function grade_item_update(\stdClass $session, $grades = null): int {
        self::require_gradelib();

        $session = session_manager::get_template_for_session($session) ?: $session;

        $details = self::item_details($session);
        if ($grades === 'reset') {
            // A course reset deletes the grades and keeps the item, which core
            // expresses as $itemdetails['reset'] - not as a cleared $grades.
            $details['reset'] = true;
            $grades = null;
        }

        return grade_update(
            'mod/multicheckin',
            $session->course,
            'mod',
            'multicheckin',
            $session->id,
            0,
            $grades,
            $details
        );
    }

    /**
     * Make sure gradelib.php is loaded.
     *
     * A page request has usually pulled it in already; a scheduled task has not,
     * and the test harness supplies its own grade_update() instead, so only
     * load it when it is genuinely missing.
     *
     * @return void
     */
    private static function require_gradelib(): void {
        global $CFG;

        if (!function_exists('grade_update')) {
            require_once($CFG->libdir . '/gradelib.php');
        }
    }
}
