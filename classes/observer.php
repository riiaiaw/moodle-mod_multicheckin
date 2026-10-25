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

// Observers run during course/module deletion, which is early enough that the
// component's autoloader cannot be relied on. photo_manager owns the check-in
// photos that have to go with the session being removed.
require_once(__DIR__ . '/photo_manager.php');

/**
 * Event observers for mod_multicheckin.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {

    /**
     * React to a course module being deleted.
     *
     * @param \core\event\course_module_deleted $event Event.
     * @return void
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        global $DB;

        $cm = $event->get_record_snapshot('course_modules', $event->objectid);
        if ($cm->modname !== 'multicheckin') {
            return;
        }

        $sessionid = (int) $cm->instance;

        // Remove the activity's occurrences first. When the activity carries a
        // series, the rows holding its check-ins are the occurrences, and the
        // template row itself holds none: deleting only $cm->instance would
        // leave the whole term's attendance behind as orphans.
        self::delete_session_records(session_manager::get_occurrences($sessionid));
        self::delete_session_records([(int) $sessionid => (object) ['id' => $sessionid]]);

        // Deliberately NOT touching multicheckin_place. That table is the
        // site-wide classroom library, shared by every course, and holds no
        // data belonging to this activity. Deleting one course module must not
        // remove rooms that other courses are still configured against.
        // It is cleaned up by the privacy provider and by the management page.
    }

    /**
     * Delete every child row of the given sessions.
     *
     * The tables are ordered so nothing is removed before the row that refers
     * to it, and the session rows themselves go last.
     *
     * One transaction per occurrence. Seven statements run here across eight
     * tables and every one of them is a hard DELETE, so a failure in the middle
     * used to leave the occurrence half dismantled - points without their log
     * rows, or log rows whose points are gone - and this runs inside course
     * module deletion, which is the one path where nobody is watching to retry
     * it. Wrapping them means the occurrence is either wholly gone or wholly
     * present, so a retry starts from a state it can finish.
     *
     * The stored files are deleted inside the same block on purpose: each
     * photograph is filed under the id of a log row that is about to
     * disappear, and resolving the module context they live under needs the
     * occurrence row to still exist. Deleting a file that is already gone is
     * harmless, so a rollback followed by a retry converges rather than fails.
     *
     * @param array $sessions Session records keyed by id.
     * @return void
     */
    public static function delete_session_records(array $sessions): void {
        global $DB;

        foreach ($sessions as $session) {
            $id = (int) $session->id;

            $DB->transaction(static function () use ($DB, $id) {
                // Before the log rows, because the photographs are filed under
                // their ids. Called while the session row still exists, since
                // resolving the module context goes through it.
                photo_manager::delete_all_for_session($id);
                $DB->delete_records('multicheckin_log', ['sessionid' => $id]);
                $DB->delete_records('multicheckin_result', ['sessionid' => $id]);
                $DB->delete_records('multicheckin_notify', ['sessionid' => $id]);
                $DB->delete_records('multicheckin_geo_log', ['sessionid' => $id]);
                leave_manager::delete_all_for_session($id);
                description_editor::delete_files($id);
                $DB->delete_records('multicheckin_point', ['sessionid' => $id]);
                $DB->delete_records('multicheckin', ['id' => $id]);
            });
        }
    }

    /**
     * React to a user enrolment being removed: prune their check-in data.
     *
     * @param \core\event\user_enrolment_deleted $event Event.
     * @return void
     */
    public static function user_enrolment_deleted(\core\event\user_enrolment_deleted $event): void {
        // Intentionally left as a hook: attendance history is normally retained
        // for audit purposes, so we do not delete the user's records here.
    }

    /**
     * After a course module instance is updated, recompute if needed.
     *
     * @param \core\event\course_module_updated $event Event.
     * @return void
     */
    public static function course_module_updated(\core\event\course_module_updated $event): void {
        global $DB;

        $cm = $event->get_record_snapshot('course_modules', $event->objectid);
        if ($cm->modname !== 'multicheckin') {
            return;
        }

        $session = $DB->get_record('multicheckin', ['id' => $cm->instance]);
        if (!$session) {
            return;
        }

        // Only recompute once the session has actually finished.
        if (time() >= (int) $session->timeend) {
            checkin_engine::compute_session((int) $session->id);
        }
    }
}
