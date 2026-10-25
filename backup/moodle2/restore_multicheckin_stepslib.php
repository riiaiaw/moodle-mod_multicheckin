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
 * Restore structure step for mod_multicheckin.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Restore step for the multicheckin activity.
 *
 * The activity and its occurrences are rows of the same table, so the parent
 * activity row is restored first and the occurrences follow it in the same
 * element. Their parentid is not carried across as a number: it is remapped to
 * whatever id the activity was given in the target course, because the
 * template is what parentid points at and it is the one row that changes id.
 */
class restore_multicheckin_activity_structure_step extends restore_activity_structure_step {

    /**
     * Restore a user id if it is present, otherwise leave the field alone.
     *
     * @param int $oldid Source user id.
     * @return int New user id, or 0 when the source had none.
     */
    protected function apply_restore_user(int $oldid): int {
        return $oldid ? (int) $this->get_mappingid('user', $oldid, 0) : 0;
    }

    /**
     * Define the structure of the restore workflow.
     *
     * @return restore_path_element
     */
    protected function define_structure() {

        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('multicheckin', '/activity/multicheckin');

        // Points are configuration, not user data: a restored activity keeps
        // its check-in structure and passwords even when student data is
        // excluded, so the activity is immediately usable.
        $paths[] = new restore_path_element('multicheckin_point',
            '/activity/multicheckin/points/point');

        if ($userinfo) {
            $paths[] = new restore_path_element('multicheckin_log',
                '/activity/multicheckin/logs/log');
            $paths[] = new restore_path_element('multicheckin_geolog',
                '/activity/multicheckin/geologs/geolog');
            $paths[] = new restore_path_element('multicheckin_result',
                '/activity/multicheckin/results/result');
            $paths[] = new restore_path_element('multicheckin_notify',
                '/activity/multicheckin/notifies/notify');
            $paths[] = new restore_path_element('multicheckin_leave',
                '/activity/multicheckin/leaves/leave');
            $paths[] = new restore_path_element('multicheckin_photoreq',
                '/activity/multicheckin/photoreqs/photoreq');
        }

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Process a multicheckin row: the activity itself, or one of its occurrences.
     *
     * Both kinds are rows of the same table and arrive in the same element, so
     * telling them apart is what decides which fields may be rewritten.
     * parentid === 0 marks the activity; anything else is an occurrence.
     *
     * @param array|object $data The XML data.
     * @return void
     */
    protected function process_multicheckin($data) {
        global $DB;

        $data = (object) $data;
        $oldid = (int) $data->id;
        $isparent = empty($data->parentid);

        $data->course = $this->get_courseid();

        if ($isparent) {
            // This row IS the activity. It must not claim to be an occurrence
            // of anything, and it is the only row that gets the new instance
            // registered against the course module.
            $data->parentid = 0;
            $data->seriesid = 0;
        } else {
            // An occurrence. parentid has to name the activity's NEW id:
            // leaving the source id would detach the occurrence from the
            // restored activity and make the whole series invisible. The
            // activity was inserted first, so its mapping is available here.
            $data->parentid = $this->get_new_parentid('multicheckin');
        }

        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $data->timestart = $this->apply_date_offset($data->timestart);
        $data->timeend = $this->apply_date_offset($data->timeend);

        $newitemid = $DB->insert_record('multicheckin', $data);
        // The fourth argument records the old context id alongside the mapping,
        // and it is what makes the description images restorable at all:
        // add_related_files() matches files to rows by joining the file list
        // against this table on both the itemid AND that context, so a mapping
        // written without it is a mapping nothing can hang files off.
        $this->set_mapping('multicheckin', $oldid, $newitemid, true);

        if ($isparent) {
            $this->apply_activity_instance($newitemid);
        }
    }

    /**
     * Map a source sessionid onto the id its row was given in this course.
     *
     * A child row hangs off either the activity or one of its occurrences, and
     * only the row's own value says which. Resolving it through the mapping
     * built by process_multicheckin() keeps an occurrence's points with that
     * occurrence; using the parent activity id for every row would pile the
     * whole series onto the template. Rows whose session did not come across
     * return 0, and callers drop them rather than attaching them to the wrong
     * session.
     *
     * @param int $oldid Source session id.
     * @return int New session id, or 0 when it is not part of this activity.
     */
    protected function mapped_session(int $oldid): int {
        return (int) $this->get_mappingid('multicheckin', $oldid, 0);
    }

    /**
     * Process a check-in point restore.
     *
     * @param array|object $data The XML data.
     * @return void
     */
    protected function process_multicheckin_point($data) {
        global $DB;

        $data = (object) $data;
        $oldid = (int) $data->id;

        $data->sessionid = $this->mapped_session((int) $data->sessionid);
        if (!$data->sessionid) {
            return;
        }
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        // QR tokens are per-session secrets and are deliberately not reused.
        $data->qrcodekey = '';
        $data->qrcodetime = 0;

        // A backup taken before the QR-only flag existed carries no value for
        // it. Missing means 0 - the behaviour that backup was taken under - and
        // must not be confused with a saved 0, so the value is only invented
        // when the element is absent. A present value is the teacher's setting
        // and travels through untouched.
        if (!isset($data->qronly)) {
            $data->qronly = 0;
        }

        $newitemid = $DB->insert_record('multicheckin_point', $data);
        $this->set_mapping('multicheckin_point', $oldid, $newitemid);
    }

    /**
     * Process a check-in log entry restore.
     *
     * @param array|object $data The XML data.
     * @return void
     */
    protected function process_multicheckin_log($data) {
        global $DB;

        $data = (object) $data;
        $oldid = (int) $data->id;

        $data->sessionid = $this->mapped_session((int) $data->sessionid);
        $data->pointid = $this->get_mappingid('multicheckin_point', $data->pointid, 0);
        $data->userid = $this->apply_restore_user((int) $data->userid);
        if (!$data->sessionid || !$data->userid || !$data->pointid) {
            return;
        }
        $data->timecreated = $this->apply_date_offset($data->timecreated);

        $newitemid = $DB->insert_record('multicheckin_log', $data);
        // The fourth argument marks the mapping as carrying files. Without it
        // the photographs of this check-in would be silently dropped on
        // restore: the file area is keyed by the log row's id, and the row has
        // just been given a new one.
        $this->set_mapping('multicheckin_log', $oldid, $newitemid, true);
    }

    /**
     * Process a geo evaluation restore.
     *
     * @param array|object $data The XML data.
     * @return void
     */
    protected function process_multicheckin_geolog($data) {
        global $DB;

        $data = (object) $data;

        $data->sessionid = $this->mapped_session((int) $data->sessionid);
        $data->pointid = $this->get_mappingid('multicheckin_point', $data->pointid, 0);
        $data->userid = $this->apply_restore_user((int) $data->userid);
        if (!$data->sessionid || !$data->userid) {
            return;
        }
        $data->timecreated = $this->apply_date_offset($data->timecreated);

        $DB->insert_record('multicheckin_geo_log', $data);
    }

    /**
     * Process a computed result restore.
     *
     * @param array|object $data The XML data.
     * @return void
     */
    protected function process_multicheckin_result($data) {
        global $DB;

        $data = (object) $data;

        $data->sessionid = $this->mapped_session((int) $data->sessionid);
        $data->userid = $this->apply_restore_user((int) $data->userid);
        if (!$data->sessionid || !$data->userid) {
            return;
        }
        $data->firstcheckin = $this->apply_date_offset($data->firstcheckin);
        $data->lastcheckin = $this->apply_date_offset($data->lastcheckin);
        $data->timecomputed = $this->apply_date_offset($data->timecomputed);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $DB->insert_record('multicheckin_result', $data);
    }

    /**
     * Process a notification restore.
     *
     * @param array|object $data The XML data.
     * @return void
     */
    protected function process_multicheckin_notify($data) {
        global $DB;

        $data = (object) $data;

        $data->sessionid = $this->mapped_session((int) $data->sessionid);
        $data->pointid = $this->get_mappingid('multicheckin_point', $data->pointid, 0);
        $data->userid = $this->apply_restore_user((int) $data->userid);
        if (!$data->sessionid || !$data->userid) {
            return;
        }
        $data->timesent = $this->apply_date_offset($data->timesent);

        $DB->insert_record('multicheckin_notify', $data);
    }

    /**
     * Process a leave request restore.
     *
     * A leave row is keyed by session and user; both have to come across, so a
     * row whose session was not part of this activity is dropped rather than
     * left pointing at nothing.
     *
     * @param array|object $data The XML data.
     * @return void
     */
    protected function process_multicheckin_leave($data) {
        global $DB;

        $data = (object) $data;
        $oldid = (int) $data->id;

        $data->sessionid = $this->mapped_session((int) $data->sessionid);
        $data->userid = $this->apply_restore_user((int) $data->userid);
        $data->reviewedby = $this->apply_restore_user((int) $data->reviewedby);
        if (!$data->sessionid || !$data->userid) {
            return;
        }
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $newitemid = $DB->insert_record('multicheckin_leave', $data);
        // Files are held against the new row: mark the mapping as carrying
        // files so add_related_files() can relocate them in after_execute().
        $this->set_mapping('multicheckin_leave', $oldid, $newitemid, true);
    }

    /**
     * Process a by-hand verification request restore.
     *
     * Keyed by session, point and user, all three of which have to resolve
     * before the row means anything; a request whose point did not come across
     * is dropped rather than left pointing at nothing.
     *
     * @param array|object $data The XML data.
     * @return void
     */
    protected function process_multicheckin_photoreq($data) {
        global $DB;

        $data = (object) $data;

        $data->sessionid = $this->mapped_session((int) $data->sessionid);
        $data->pointid = $this->get_mappingid('multicheckin_point', $data->pointid, 0);
        $data->userid = $this->apply_restore_user((int) $data->userid);
        $data->reviewedby = $this->apply_restore_user((int) $data->reviewedby);
        if (!$data->sessionid || !$data->userid || !$data->pointid) {
            return;
        }
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $data->timereviewed = $this->apply_date_offset((int) $data->timereviewed);

        $DB->insert_record('multicheckin_photoreq', $data);
    }

    /**
     * After restore, resync the gradebook for the restored activity.
     *
     * @return void
     */
    protected function after_execute() {
        global $DB;

        // Add the intro files.
        $this->add_related_files('mod_multicheckin', 'intro', null);

        // The images a description embeds, one area per restored occurrence.
        $this->add_related_files('mod_multicheckin', 'sessiondescription', 'multicheckin');

        // Add the leave notes, one file per restored leave row.
        $this->add_related_files('mod_multicheckin', 'leaveattachment', 'multicheckin_leave');

        // Add the check-in photographs, one file per restored log row. The
        // itemname matches the mapping written by process_multicheckin_log():
        // send_files_to_pool() joins on it, so a name that does not match is a
        // restore that silently produces no files at all.
        $this->add_related_files('mod_multicheckin', 'checkinphoto', 'multicheckin_log');

        // The restored occurrence dates are offset, so the computed statuses
        // may no longer match. Recomputing is cheap and leaves the gradebook
        // agreeing with the rows that were just written.
        //
        // Through the class rather than the lib.php callback: a restore runs in
        // the restore controller's process, and nothing there promises that
        // mod/multicheckin/lib.php has been included.
        $instanceid = $this->get_task()->get_activityid();
        if ($instanceid) {
            $template = $DB->get_record('multicheckin', ['id' => $instanceid]);
            if ($template) {
                \mod_multicheckin\grade_helper::update_grades($template);
            }
        }
    }
}
