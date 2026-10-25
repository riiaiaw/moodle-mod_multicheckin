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
 * Backup steps for mod_multicheckin.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Structure step that backs up one multicheckin activity.
 *
 * The order of the elements matters: an occurrence is a row of the same
 * activity table, so it sits in the 'multicheckin' element beside the template
 * rather than in a nested one. Everything that names a session - points, logs,
 * geo evaluations, results and notifications - is included flat and is
 * re-linked on restore by the parentid and sessionid fields it carries.
 */
class backup_multicheckin_activity_structure_step extends backup_activity_structure_step {

    /**
     * Define the structure of the multicheckin.xml file.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {

        // To know if we are including userinfo.
        $userinfo = $this->get_setting_value('userinfo');

        // ---- Elements -----------------------------------------------------

        $multicheckin = new backup_nested_element('multicheckin', ['id'], [
            'name', 'intro', 'introformat', 'description', 'descriptionformat', 'timestart', 'timeend',
            'lategrace', 'earlygrace', 'absentreason', 'grade',
            'pointspresent', 'pointslate', 'pointsearly', 'pointslateearly', 'pointsabsent',
            'pointsleave',
            'earlymode',
            'photorequired',
            'allowselfcheckin', 'qronly', 'selfcheckinpassword',
            'notifyenabled', 'notifyleadtime', 'notifyrule', 'qrrotation',
            'geomode', 'geoipranges', 'geolat', 'geolng', 'georadius', 'geoenforceqr',
            'showgeoaudit', 'audience',
            // The self-referencing series columns. parentid is remapped on
            // restore so occurrences stay attached to their activity.
            'parentid', 'seriesid', 'seqno', 'gradeaggregate', 'gradebestcount',
            'timemodified',
        ]);

        $points = new backup_nested_element('points');
        $point = new backup_nested_element('point', ['id'], [
            'sessionid', 'name', 'sortorder', 'openoffset', 'closeoffset', 'role',
            'required', 'password', 'qrcode', 'qronly', 'qrcodekey', 'qrcodetime',
            'notify', 'timemodified',
        ]);

        $logs = new backup_nested_element('logs');
        $log = new backup_nested_element('log', ['id'], [
            'pointid', 'sessionid', 'userid', 'timecreated', 'ip', 'method',
        ]);

        $geologs = new backup_nested_element('geologs');
        $geolog = new backup_nested_element('geolog', ['id'], [
            'sessionid', 'pointid', 'userid', 'method', 'geomode', 'allowed', 'reason',
            'ip', 'latitude', 'longitude', 'accuracy', 'distance', 'timecreated',
        ]);

        $results = new backup_nested_element('results');
        $result = new backup_nested_element('result', ['id'], [
            'sessionid', 'userid', 'statuscode', 'firstcheckin', 'lastcheckin',
            'latemin', 'earlymin', 'points', 'timecomputed', 'timemodified',
        ]);

        $notifies = new backup_nested_element('notifies');
        $notify = new backup_nested_element('notify', ['id'], [
            'sessionid', 'pointid', 'userid', 'timesent',
        ]);

        $leaves = new backup_nested_element('leaves');
        $leave = new backup_nested_element('leave', ['id'], [
            'sessionid', 'userid', 'reason', 'origin', 'reviewed', 'reviewedby',
            'timecreated', 'timemodified',
        ]);

        $photoreqs = new backup_nested_element('photoreqs');
        $photoreq = new backup_nested_element('photoreq', ['id'], [
            'sessionid', 'pointid', 'userid', 'reason', 'status', 'reviewedby',
            'timereviewed', 'timecreated', 'timemodified',
        ]);

        // ---- Tree ---------------------------------------------------------

        $multicheckin->add_child($points);
        $points->add_child($point);

        $multicheckin->add_child($logs);
        $logs->add_child($log);

        $multicheckin->add_child($geologs);
        $geologs->add_child($geolog);

        $multicheckin->add_child($results);
        $results->add_child($result);

        $multicheckin->add_child($notifies);
        $notifies->add_child($notify);

        $multicheckin->add_child($leaves);
        $leaves->add_child($leave);

        $multicheckin->add_child($photoreqs);
        $photoreqs->add_child($photoreq);

        // ---- Sources ------------------------------------------------------

        // The activity AND its occurrences. An occurrence is a row of this
        // same table whose parentid points at the activity, so a plain
        // 'id = VAR_ACTIVITYID' source would back up the template alone and
        // quietly drop the whole series - every date, and with it every result
        // that belongs to a date. Matching id OR parentid keeps the template
        // and its occurrences together in one element.
        $multicheckin->set_source_sql(
            'SELECT * FROM {multicheckin} WHERE id = ? OR parentid = ?',
            [backup::VAR_ACTIVITYID, backup::VAR_ACTIVITYID]);

        // One query per child table covering every session of the activity.
        // The same id-OR-parentid sub-select is reused so a child row is
        // picked up whether it hangs off the template or off an occurrence.
        $sessionids = 'SELECT id FROM {multicheckin} WHERE id = ? OR parentid = ?';

        $point->set_source_sql(
            "SELECT * FROM {multicheckin_point} WHERE sessionid IN ($sessionids)",
            [backup::VAR_ACTIVITYID, backup::VAR_ACTIVITYID]);

        $log->set_source_sql(
            "SELECT * FROM {multicheckin_log} WHERE sessionid IN ($sessionids)",
            [backup::VAR_ACTIVITYID, backup::VAR_ACTIVITYID]);

        $geolog->set_source_sql(
            "SELECT * FROM {multicheckin_geo_log} WHERE sessionid IN ($sessionids)",
            [backup::VAR_ACTIVITYID, backup::VAR_ACTIVITYID]);

        $result->set_source_sql(
            "SELECT * FROM {multicheckin_result} WHERE sessionid IN ($sessionids)",
            [backup::VAR_ACTIVITYID, backup::VAR_ACTIVITYID]);

        $notify->set_source_sql(
            "SELECT * FROM {multicheckin_notify} WHERE sessionid IN ($sessionids)",
            [backup::VAR_ACTIVITYID, backup::VAR_ACTIVITYID]);

        $leave->set_source_sql(
            "SELECT * FROM {multicheckin_leave} WHERE sessionid IN ($sessionids)",
            [backup::VAR_ACTIVITYID, backup::VAR_ACTIVITYID]);

        $photoreq->set_source_sql(
            "SELECT * FROM {multicheckin_photoreq} WHERE sessionid IN ($sessionids)",
            [backup::VAR_ACTIVITYID, backup::VAR_ACTIVITYID]);

        // ---- Annotations --------------------------------------------------

        $multicheckin->annotate_ids('user', 'userid');

        $log->annotate_ids('user', 'userid');
        $geolog->annotate_ids('user', 'userid');
        $result->annotate_ids('user', 'userid');
        $notify->annotate_ids('user', 'userid');
        $leave->annotate_ids('user', 'userid');
        $leave->annotate_ids('user', 'reviewedby');
        $photoreq->annotate_ids('user', 'userid');
        $photoreq->annotate_ids('user', 'reviewedby');

        $log->annotate_files('mod_multicheckin', 'intro', null);

        // The check-in photographs. The itemid is the LOG row's own id, because
        // one photograph proves one check-in rather than one occurrence, so
        // unlike the intro above this annotation needs the log ids to survive
        // the trip - which is why the restore side asks for add_related_files()
        // against the log element rather than with a null itemid.
        $log->annotate_files('mod_multicheckin', 'checkinphoto', 'id');

        // The images a description embeds. The itemid is the occurrence's own
        // id, so unlike the intro above this annotation needs the id mapping to
        // survive the trip - which is why the restore side asks for
        // add_related_files() against the 'multicheckin' element rather than
        // with a null itemid.
        $multicheckin->annotate_files('mod_multicheckin', 'sessiondescription', 'id');

        // The leave note is a user file held against the leave row, so the
        // itemid is that row's own id rather than null.
        $leave->annotate_files('mod_multicheckin', 'leaveattachment', 'id');

        // User data is only taken when the setting says so.
        if (!$userinfo) {
            $logs->set_children(0);
            $geologs->set_children(0);
            $results->set_children(0);
            $notifies->set_children(0);
            $leaves->set_children(0);
            $photoreqs->set_children(0);
        }

        return $this->prepare_activity_structure($multicheckin);
    }
}
