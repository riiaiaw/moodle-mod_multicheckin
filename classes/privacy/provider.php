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

namespace mod_multicheckin\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_multicheckin\leave_manager;
use mod_multicheckin\photo_manager;
use mod_multicheckin\point_name;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy provider for mod_multicheckin.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    /**
     * Describe the data stored.
     *
     * @param collection $collection Collection.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('multicheckin_log', [
            'userid' => 'privacy:metadata:multicheckin_log:userid',
            'timecreated' => 'privacy:metadata:multicheckin_log:timecreated',
            'ip' => 'privacy:metadata:multicheckin_log:ip',
        ], 'privacy:metadata:multicheckin_log');

        $collection->add_database_table('multicheckin_result', [
            'statuscode' => 'privacy:metadata:multicheckin_result:statuscode',
        ], 'privacy:metadata:multicheckin_result');

        $collection->add_database_table('multicheckin_notify', [
            'userid' => 'privacy:metadata:multicheckin_notify:userid',
            'timesent' => 'privacy:metadata:multicheckin_notify:timesent',
        ], 'privacy:metadata:multicheckin_notify');

        // Geofence audit trail: records the client IP and, when a GPS fence is
        // active, the coordinates reported by the browser.
        $collection->add_database_table('multicheckin_geo_log', [
            'userid' => 'privacy:metadata:multicheckin_geo_log:userid',
            'ip' => 'privacy:metadata:multicheckin_geo_log:ip',
            'latitude' => 'privacy:metadata:multicheckin_geo_log:latitude',
            'longitude' => 'privacy:metadata:multicheckin_geo_log:longitude',
            'timecreated' => 'privacy:metadata:multicheckin_geo_log:timecreated',
        ], 'privacy:metadata:multicheckin_geo_log');

        // Shared classroom library. The coordinates describe a building, not a
        // person, so they are not personal data; the only personal datum held
        // is who last touched the record, which is already public to the site
        // as an editing action.
        $collection->add_database_table('multicheckin_place', [
            'usermodified' => 'privacy:metadata:multicheckin_place:usermodified',
            'timecreated' => 'privacy:metadata:multicheckin_place:timecreated',
            'timemodified' => 'privacy:metadata:multicheckin_place:timemodified',
        ], 'privacy:metadata:multicheckin_place');

        // A leave request: the student's own declaration, the reason they gave
        // and the teacher who checked it.
        $collection->add_database_table('multicheckin_leave', [
            'userid' => 'privacy:metadata:multicheckin_leave:userid',
            'reason' => 'privacy:metadata:multicheckin_leave:reason',
            'origin' => 'privacy:metadata:multicheckin_leave:origin',
            'reviewedby' => 'privacy:metadata:multicheckin_leave:reviewedby',
            'timecreated' => 'privacy:metadata:multicheckin_leave:timecreated',
        ], 'privacy:metadata:multicheckin_leave');

        // The optional leave note is stored through the file API, so the file
        // subsystem is declared too - otherwise the export would silently omit
        // the very document the student attached.
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:core_files');

        // A by-hand verification request: the student's own declaration that
        // their camera did not work, and the teacher who decided it.
        $collection->add_database_table('multicheckin_photoreq', [
            'userid' => 'privacy:metadata:multicheckin_photoreq:userid',
            'reason' => 'privacy:metadata:multicheckin_photoreq:reason',
            'status' => 'privacy:metadata:multicheckin_photoreq:status',
            'reviewedby' => 'privacy:metadata:multicheckin_photoreq:reviewedby',
            'timecreated' => 'privacy:metadata:multicheckin_photoreq:timecreated',
        ], 'privacy:metadata:multicheckin_photoreq');

        // The capture permit. Ten minutes of "this student may open the camera
        // for this point", which became a table row in 2.0.23 so that a link
        // copied out of an in-app browser still works where the student opens
        // it. It names the student, and it carries the position that satisfied
        // the fence, so it is personal data and has to be declared - being
        // short-lived does not make a row anonymous.
        $collection->add_database_table('multicheckin_pass', [
            'userid' => 'privacy:metadata:multicheckin_pass:userid',
            'clientgeo' => 'privacy:metadata:multicheckin_pass:clientgeo',
            'timecreated' => 'privacy:metadata:multicheckin_pass:timecreated',
        ], 'privacy:metadata:multicheckin_pass');

        // The check-in photograph rides in the same core_files declaration
        // above. It is not a second entry: Moodle expects one link per
        // subsystem, and a duplicated one is a declaration that contradicts
        // itself rather than one that says more.

        return $collection;
    }

    /**
     * Get contexts containing user data.
     *
     * @param int $userid User id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {multicheckin} mc ON mc.id = cm.instance
                  JOIN {multicheckin_log} ml ON ml.sessionid = mc.id
                 WHERE ml.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'multicheckin',
            'userid' => $userid,
        ]);

        // Leaves, which live on the occurrence rows rather than on the template
        // the course module points at. The join therefore walks the template and
        // its occurrences, so a student who only ever raised a leave is still
        // found - the log query above would miss them entirely.
        $leavesql = "SELECT DISTINCT ctx.id
                       FROM {context} ctx
                       JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                       JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                       JOIN {multicheckin} tmpl ON tmpl.id = cm.instance
                       JOIN {multicheckin} occ ON occ.id = tmpl.id OR occ.parentid = tmpl.id
                       JOIN {multicheckin_leave} mcl ON mcl.sessionid = occ.id
                      WHERE mcl.userid = :userid";
        $contextlist->add_from_sql($leavesql, [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'multicheckin',
            'userid' => $userid,
        ]);

        // By-hand verification requests, which hang off occurrences in the same
        // way. A student whose camera failed has a row here and no row in
        // multicheckin_log at all - the whole point of the request being that
        // no check-in was written - so neither query above would find them.
        $requestsql = "SELECT DISTINCT ctx.id
                         FROM {context} ctx
                         JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                         JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                         JOIN {multicheckin} tmpl ON tmpl.id = cm.instance
                         JOIN {multicheckin} occ ON occ.id = tmpl.id OR occ.parentid = tmpl.id
                         JOIN {multicheckin_photoreq} mpr ON mpr.sessionid = occ.id
                        WHERE mpr.userid = :userid";
        $contextlist->add_from_sql($requestsql, [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'multicheckin',
            'userid' => $userid,
        ]);

        // Capture permits, for the same reason: a student can hold one without
        // any row in multicheckin_log (the camera page was opened and left), and
        // saying "this user has data here" only for the tables that survive the
        // attempt would answer a narrower question than the one being asked.
        $passesql = "SELECT DISTINCT ctx.id
                       FROM {context} ctx
                       JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                       JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                       JOIN {multicheckin} tmpl ON tmpl.id = cm.instance
                       JOIN {multicheckin} occ ON occ.id = tmpl.id OR occ.parentid = tmpl.id
                       JOIN {multicheckin_pass} mpa ON mpa.sessionid = occ.id
                      WHERE mpa.userid = :userid";
        $contextlist->add_from_sql($passesql, [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'multicheckin',
            'userid' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Get users in a context.
     *
     * @param userlist $userlist Userlist.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $sql = "SELECT ml.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {multicheckin} mc ON mc.id = cm.instance
                  JOIN {multicheckin_log} ml ON ml.sessionid = mc.id
                 WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $sql, ['modname' => 'multicheckin', 'cmid' => $context->instanceid]);

        $leavesql = "SELECT DISTINCT mcl.userid
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                       JOIN {multicheckin} tmpl ON tmpl.id = cm.instance
                       JOIN {multicheckin} occ ON occ.id = tmpl.id OR occ.parentid = tmpl.id
                       JOIN {multicheckin_leave} mcl ON mcl.sessionid = occ.id
                      WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $leavesql, ['modname' => 'multicheckin', 'cmid' => $context->instanceid]);

        $requestsql = "SELECT DISTINCT mpr.userid
                         FROM {course_modules} cm
                         JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                         JOIN {multicheckin} tmpl ON tmpl.id = cm.instance
                         JOIN {multicheckin} occ ON occ.id = tmpl.id OR occ.parentid = tmpl.id
                         JOIN {multicheckin_photoreq} mpr ON mpr.sessionid = occ.id
                        WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $requestsql, ['modname' => 'multicheckin', 'cmid' => $context->instanceid]);

        $passesql = "SELECT DISTINCT mpa.userid
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                       JOIN {multicheckin} tmpl ON tmpl.id = cm.instance
                       JOIN {multicheckin} occ ON occ.id = tmpl.id OR occ.parentid = tmpl.id
                       JOIN {multicheckin_pass} mpa ON mpa.sessionid = occ.id
                      WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $passesql, ['modname' => 'multicheckin', 'cmid' => $context->instanceid]);
    }

    /**
     * Export user data.
     *
     * @param approved_contextlist $contextlist Contextlist.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_MODULE) {
                continue;
            }
            $cm = get_coursemodule_from_id('multicheckin', $context->instanceid);
            if (!$cm || $cm->modname !== 'multicheckin') {
                continue;
            }
            // Every session of the activity, not just the template. An
            // occurrence is a row of the same table, and a term's check-ins
            // hang off the occurrences: a query pinned to the template would
            // export an empty file for any activity that has a series.
            [$sesssql, $sessparams] = $DB->get_in_or_equal(self::session_ids((int) $cm->instance),
                SQL_PARAMS_NAMED, 'expsess');

            $logs = $DB->get_records_sql(
                "SELECT ml.id, ml.timecreated, ml.ip, ml.method, mp.name AS pointname, mp.role AS pointrole
                   FROM {multicheckin_log} ml
                   JOIN {multicheckin_point} mp ON mp.id = ml.pointid
                  WHERE ml.sessionid {$sesssql} AND ml.userid = :userid",
                $sessparams + ['userid' => $user->id]
            );
            if ($logs) {
                $data = array_map(fn($l) => [
                    'point' => point_name::display_name((string) $l->pointname, (string) $l->pointrole),
                    'time' => \core_privacy\local\request\transform::datetime($l->timecreated),
                    'ip' => $l->ip,
                    'method' => $l->method,
                ], array_values($logs));
                writer::with_context($context)->export_data([get_string('pluginname', 'mod_multicheckin')], (object) [
                    'checkins' => $data,
                ]);
            }

            // Geofence decisions that mention this user (IP + coordinates).
            $geologs = $DB->get_records_sql(
                "SELECT g.id, g.timecreated, g.method, g.geomode, g.allowed, g.reason,
                        g.ip, g.latitude, g.longitude, g.distance, mp.name AS pointname, mp.role AS pointrole
                   FROM {multicheckin_geo_log} g
                   JOIN {multicheckin_point} mp ON mp.id = g.pointid
                  WHERE g.sessionid {$sesssql} AND g.userid = :userid",
                $sessparams + ['userid' => $user->id]
            );
            if ($geologs) {
                $geodata = array_map(fn($g) => [
                    'point' => point_name::display_name((string) $g->pointname, (string) $g->pointrole),
                    'time' => \core_privacy\local\request\transform::datetime($g->timecreated),
                    'method' => $g->method,
                    'mode' => $g->geomode,
                    'allowed' => (bool) $g->allowed,
                    'reason' => $g->reason,
                    'ip' => $g->ip,
                    'latitude' => $g->latitude,
                    'longitude' => $g->longitude,
                    'distance' => $g->distance,
                ], array_values($geologs));
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'mod_multicheckin'), get_string('privacy_geolog', 'mod_multicheckin')],
                    (object) ['geofence' => $geodata]
                );
            }

            // Leave requests. The note file is exported through the file API so
            // the student actually receives the document they handed in.
            [$insql, $inparams] = $DB->get_in_or_equal(self::session_ids((int) $cm->instance),
                SQL_PARAMS_NAMED, 'leavesess');
            $leaves = $DB->get_records_select('multicheckin_leave',
                "sessionid $insql AND userid = :userid",
                $inparams + ['userid' => (int) $user->id]);
            if ($leaves) {
                $leavedata = [];
                foreach ($leaves as $leave) {
                    $leavedata[] = [
                        'reason' => (string) $leave->reason,
                        'raisedby' => (string) $leave->origin,
                        'reviewed' => (bool) $leave->reviewed,
                        'timecreated' => \core_privacy\local\request\transform::datetime((int) $leave->timecreated),
                    ];
                    writer::with_context($context)->export_area_files(
                        [get_string('pluginname', 'mod_multicheckin'), get_string('leaves', 'mod_multicheckin')],
                        leave_manager::COMPONENT,
                        leave_manager::FILEAREA,
                        (int) $leave->id
                    );
                }
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'mod_multicheckin'), get_string('leaves', 'mod_multicheckin')],
                    (object) ['leaves' => $leavedata]
                );
            }

            // By-hand verification requests: what the student said when the
            // camera did not work, and what was decided.
            $requests = $DB->get_records_sql(
                "SELECT r.id, r.reason, r.status, r.timecreated, r.timereviewed, mp.name AS pointname, mp.role AS pointrole
                   FROM {multicheckin_photoreq} r
                   LEFT JOIN {multicheckin_point} mp ON mp.id = r.pointid
                  WHERE r.sessionid {$sesssql} AND r.userid = :userid",
                $sessparams + ['userid' => $user->id]
            );
            if ($requests) {
                $requestdata = array_map(fn($r) => [
                    'point' => point_name::display_name((string) $r->pointname, (string) $r->pointrole),
                    'reason' => (string) $r->reason,
                    'status' => photo_manager::status_label((int) $r->status),
                    'timecreated' => \core_privacy\local\request\transform::datetime((int) $r->timecreated),
                    'timereviewed' => (int) $r->timereviewed
                        ? \core_privacy\local\request\transform::datetime((int) $r->timereviewed)
                        : null,
                ], array_values($requests));
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'mod_multicheckin'), get_string('photoreq_title', 'mod_multicheckin')],
                    (object) ['requests' => $requestdata]
                );
            }

            // The check-in photographs themselves, one file area per log row.
            // Exported through the file API rather than as URLs, so the student
            // receives the actual picture rather than a link that has to still
            // be valid when they open it.
            if ($logs) {
                foreach ($logs as $log) {
                    writer::with_context($context)->export_area_files(
                        [get_string('pluginname', 'mod_multicheckin'), get_string('photo_files', 'mod_multicheckin')],
                        photo_manager::COMPONENT,
                        photo_manager::FILEAREA,
                        (int) $log->id
                    );
                }
            }
        }
    }

    /**
     * Every multicheckin row that belongs to one course module.
     *
     * The course module references the template, but the leaves hang off the
     * occurrences, so cleanup has to reach both. Returns the template id plus
     * every occurrence that names it as its parent.
     *
     * @param int $templateid Template (multicheckin) id.
     * @return int[]
     */
    protected static function session_ids(int $templateid): array {
        global $DB;

        $ids = [$templateid];
        $rows = $DB->get_records('multicheckin', ['parentid' => $templateid], '', 'id');
        foreach ($rows as $row) {
            $ids[] = (int) $row->id;
        }

        return $ids;
    }

    /**
     * Delete all data in a context.
     *
     * @param \context $context Context.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('multicheckin', $context->instanceid);
        if (!$cm || $cm->modname !== 'multicheckin') {
            return;
        }
        $DB->delete_records('multicheckin_log', ['sessionid' => $cm->instance]);
        $DB->delete_records('multicheckin_result', ['sessionid' => $cm->instance]);
        $DB->delete_records('multicheckin_notify', ['sessionid' => $cm->instance]);
        $DB->delete_records('multicheckin_geo_log', ['sessionid' => $cm->instance]);
        // The check-in photographs, the by-hand requests, and any picture taken
        // for a session of the series. Run before the rows go: the file area is
        // reached through the module context, which is resolved from them.
        photo_manager::delete_all_for_template((int) $cm->instance);
        foreach (self::session_ids((int) $cm->instance) as $sessionid) {
            leave_manager::delete_all_for_session($sessionid);
            // Capture permits hang off occurrences too, and nothing else would
            // ever remove them here: they are not backed up and not exported,
            // and the scheduled sweep only deletes by expiry.
            $DB->delete_records('multicheckin_pass', ['sessionid' => $sessionid]);
        }
    }

    /**
     * Delete data for a user in given contexts.
     *
     * @param approved_contextlist $contextlist Contextlist.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('multicheckin', $context->instanceid);
            if (!$cm || $cm->modname !== 'multicheckin') {
                continue;
            }
            $DB->delete_records('multicheckin_log', ['sessionid' => $cm->instance, 'userid' => $user->id]);
            $DB->delete_records('multicheckin_result', ['sessionid' => $cm->instance, 'userid' => $user->id]);
            $DB->delete_records('multicheckin_notify', ['sessionid' => $cm->instance, 'userid' => $user->id]);
            $DB->delete_records('multicheckin_geo_log', ['sessionid' => $cm->instance, 'userid' => $user->id]);
            foreach (self::session_ids((int) $cm->instance) as $sessionid) {
                photo_manager::delete_for_user($sessionid, (int) $user->id);
                leave_manager::delete_for_user($sessionid, (int) $user->id);
                $DB->delete_records('multicheckin_pass',
                    ['sessionid' => $sessionid, 'userid' => (int) $user->id]);
            }
        }
    }

    /**
     * Delete data for multiple users.
     *
     * @param approved_userlist $userlist Userlist.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('multicheckin', $context->instanceid);
        if (!$cm || $cm->modname !== 'multicheckin') {
            return;
        }
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params = array_merge(['sessionid' => $cm->instance], $inparams);
        $DB->delete_records_select('multicheckin_log', "sessionid = :sessionid AND userid {$insql}", $params);
        $DB->delete_records_select('multicheckin_result', "sessionid = :sessionid AND userid {$insql}", $params);
        $DB->delete_records_select('multicheckin_notify', "sessionid = :sessionid AND userid {$insql}", $params);
        $DB->delete_records_select('multicheckin_geo_log', "sessionid = :sessionid AND userid {$insql}", $params);
        foreach (self::session_ids((int) $cm->instance) as $sessionid) {
            foreach ($userids as $uid) {
                photo_manager::delete_for_user($sessionid, (int) $uid);
                leave_manager::delete_for_user($sessionid, (int) $uid);
                $DB->delete_records('multicheckin_pass',
                    ['sessionid' => $sessionid, 'userid' => (int) $uid]);
            }
        }
    }
}
