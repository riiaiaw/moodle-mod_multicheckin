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
 * Library of interface functions and constants for module multicheckin.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Feature support declaration.
 *
 * @param string $feature FEATURE_xx constant.
 * @return mixed True if supported, null otherwise.
 */
function multicheckin_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_GROUPINGS:
            return true;
        case FEATURE_GROUPMEMBERSONLY:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        default:
            return null;
    }
}

/**
 * Add a new instance of the module.
 *
 * @param stdClass $data Form data.
 * @param mod_multicheckin_mod_form|null $mform Form instance.
 * @return int New instance id.
 */
function multicheckin_add_instance($data, $mform = null) {
    global $DB;

    $data->timemodified = time();

    if (empty($data->lategrace)) {
        $data->lategrace = 300;
    }
    if (empty($data->earlygrace)) {
        $data->earlygrace = 300;
    }

    // The activity row is the configuration template. It always exists, even
    // when a series is generated below: cm->instance points at it, and the
    // occurrences reference it through parentid. Keeping it means an activity
    // has one stable place for its shared settings and its grade item, and that
    // deleting the series never leaves the activity without a record.
    $data->parentid = 0;
    $data->seriesid = 0;
    $data->seqno = 1;

    $id = $DB->insert_record('multicheckin', $data);

    // Create the check-in points: sign-in, the configured mid check-ins and
    // sign-out. A null plan keeps the legacy single mid check-in default for
    // any caller that does not come from the activity form.
    $plan = \mod_multicheckin\session_manager::mid_plan_from_data($data);
    \mod_multicheckin\session_manager::create_default_points($id, $plan);

    // Generate the occurrence series, if the form asked for one. Doing this on
    // add as well as on update means a teacher can set the whole term up in one
    // pass instead of having to save twice.
    $spec = \mod_multicheckin\session_manager::spec_from_data($data);
    if ($spec !== null) {
        \mod_multicheckin\session_manager::generate_series($id, $spec, $plan);
    }

    return $id;
}

/**
 * Update an existing instance.
 *
 * @param stdClass $data Form data.
 * @param mod_multicheckin_mod_form|null $mform Form instance.
 * @return bool True on success.
 */
function multicheckin_update_instance($data, $mform = null) {
    global $DB;

    $data->id = $data->instance;
    $data->timemodified = time();

    // Never let a form post turn the template into an occurrence. Only the
    // series code sets parentid, and it only ever does so on insert.
    $data->parentid = 0;

    $ok = $DB->update_record('multicheckin', $data);

    // Reconcile the mid check-in points with the configured count and times.
    // Existing points keep their id (and logs) whenever possible.
    $plan = \mod_multicheckin\session_manager::mid_plan_from_data($data);
    if ($plan !== null) {
        \mod_multicheckin\session_manager::regenerate_mid_points((int) $data->id, $plan);
    }

    // Extend or rebuild the occurrence series. This only ever ADDS occurrences
    // (an existing start time is left alone), so saving the form after check-ins
    // have been collected can never destroy them. Shrinking the series is a
    // separate, explicit action on the manage page.
    $spec = \mod_multicheckin\session_manager::spec_from_data($data);
    if ($spec !== null) {
        \mod_multicheckin\session_manager::generate_series((int) $data->id, $spec, $plan);
    }

    // The mid check-in requirement is a property of the class, not of one date,
    // so every occurrence has to follow it. Until v2.0.6 only the template row
    // was reconciled here, which is why raising the count left the dates
    // already on the timetable untouched. Runs after generate_series() so the
    // occurrences it just created are covered too - they were built from the
    // same plan, so they come back with nothing to add.
    if ($plan !== null) {
        $midstats = \mod_multicheckin\session_manager::sync_series_mid_points((int) $data->id, $plan);
        if ($midstats['added'] || $midstats['removed'] || $midstats['signed']) {
            // A saved settings page redirects, so this has to survive the
            // redirect: core\notification is the API that does.
            \core\notification::add(
                get_string('midpoints_synced', 'mod_multicheckin', (object) $midstats),
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
    }

    // Shared settings belong to every occurrence in the series. Timing does
    // not: each occurrence keeps the date it was generated with.
    $session = $DB->get_record('multicheckin', ['id' => $data->id]);
    if ($session) {
        \mod_multicheckin\session_manager::sync_occurrence_settings((int) $data->id, $session);
    }

    // Timing may have changed: refresh the computed statuses and push the
    // new grades straight into the course gradebook.
    //
    // The settling mark is cleared along with it. The times just moved, so "this
    // occurrence has already been settled" may no longer be true by the time the
    // new end time arrives: the scheduled task has to look at it again, or the
    // students who never check in are left with no absent row and no task that
    // will ever write one.
    $DB->set_field('multicheckin', 'timefinalised', 0, ['id' => (int) $data->id]);
    \mod_multicheckin\checkin_engine::compute_session((int) $data->id);
    if ($session) {
        multicheckin_update_grades($session);
    }

    return $ok;
}

/**
 * Delete an instance and all dependent data.
 *
 * @param int $id Instance id.
 * @return bool True on success.
 */
function multicheckin_delete_instance($id) {
    global $DB;

    if (!$session = $DB->get_record('multicheckin', ['id' => $id])) {
        return false;
    }

    // Deleting the activity removes the whole series, not just the template:
    // the occurrences are not reachable from anywhere else once the course
    // module is gone. delete_series() leaves the template row alone, so it is
    // called first and the template is removed below.
    \mod_multicheckin\session_manager::delete_series((int) $id);

    // Delete logs of all points.
    $DB->delete_records('multicheckin_log', ['sessionid' => $id]);
    $DB->delete_records('multicheckin_result', ['sessionid' => $id]);
    \mod_multicheckin\leave_manager::delete_all_for_session((int) $id);
    // The template's own note images. delete_series() above only walks the
    // occurrences, so the activity row - which is a session with a description
    // like any other - has to be cleaned here or its files outlive the course
    // module that owned them.
    \mod_multicheckin\description_editor::delete_files((int) $id);
    // The photographs, and every by-hand verification request of the series.
    // Run while the activity row and the course module are both still there:
    // resolving the context they are stored under goes through both.
    \mod_multicheckin\photo_manager::delete_all_for_template((int) $id);
    // Permits naming this activity itself, as opposed to its occurrences. The
    // series walk above clears the occurrences; the template row can carry
    // check-ins of its own and therefore its own permits.
    $DB->delete_records(\mod_multicheckin\photo_manager::PASS_TABLE, ['sessionid' => $id]);
    $DB->delete_records('multicheckin_point', ['sessionid' => $id]);
    $DB->delete_records('multicheckin', ['id' => $id]);

    // Remove any gradebook item for this instance.
    $cm = get_coursemodule_from_instance('multicheckin', $id, $session->course, false, IGNORE_MISSING);
    if ($cm) {
        grade_update('mod/multicheckin', $session->course, 'mod', 'multicheckin',
            $id, 0, null, null, ['deleted' => 1]);
    }

    return true;
}

/**
 * Return the grade for a user, aggregated over the activity's occurrences.
 *
 * The record passed in is the activity template (cm->instance), which is also
 * what the grade item is keyed on, so the gradebook sees one item per activity
 * however many occurrences it holds. That is the deliberate difference from a
 * per-occurrence item: Moodle already has a gradebook for combining sub-grades,
 * and creating a dozen items per activity would clutter every course gradebook
 * and leave the teacher to work out the weighting themselves.
 *
 * @param stdClass $session Instance record (the template).
 * @param int $userid User id (0 = all).
 * @return stdClass|array|null
 */
function multicheckin_get_user_grades($session, $userid = 0) {
    // A thin wrapper, on purpose. The logic lives in \mod_multicheckin\
    // grade_helper so that entry points which never load this file - a
    // scheduled task, a CLI script, an adhoc task - can still reach it. See the
    // class docblock for the failure this arrangement fixes.
    //
    // The dependency only ever runs this way round. A namespaced class must not
    // call this function: it would work on a page, where require_login() has
    // included lib.php, and fail in cron, where nothing has.
    return \mod_multicheckin\grade_helper::get_user_grades($session, (int) $userid);
}

/**
 * Update the gradebook entries for this activity.
 *
 * @param stdClass $session Instance record (the template).
 * @param int $userid Optional single user id.
 * @return bool
 */
function multicheckin_update_grades($session, $userid = 0) {
    // A thin wrapper - see multicheckin_get_user_grades() above and the
    // grade_helper class docblock. Moodle's own gradebook calls this name, so
    // the callback has to keep existing even though the work moved.
    return \mod_multicheckin\grade_helper::update_grades($session, (int) $userid);
}

/**
 * Serves the module intro files, and any note attached to a leave request.
 *
 * @param stdClass $course Course.
 * @param stdClass $cm Course module.
 * @param context $context Context.
 * @param string $filearea File area.
 * @param array $args Arguments.
 * @param bool $forcedownload Force download.
 * @param array $options Options.
 * @return bool
 */
function multicheckin_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $USER;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_login($course, true, $cm);

    // Four areas are served: the activity intro, the optional note a leave
    // request may carry, the images a description embeds, and the photographs
    // taken at check-in. Anything else is not ours to hand out.
    if ($filearea !== 'intro'
            && $filearea !== \mod_multicheckin\leave_manager::FILEAREA
            && $filearea !== \mod_multicheckin\description_editor::FILEAREA
            && $filearea !== \mod_multicheckin\photo_manager::FILEAREA) {
        return false;
    }

    if (!has_capability('mod/multicheckin:view', $context)) {
        return false;
    }

    $itemid = array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_multicheckin', $filearea, $itemid, $filepath, $filename);
    if (!$file) {
        return false;
    }

    // A leave note is personal evidence: it belongs to one student, and only
    // that student and the activity's teachers may read it. The capability
    // test above cannot express this on its own - every student holds it - so
    // the ownership check is what actually keeps the notes private.
    if ($filearea === \mod_multicheckin\leave_manager::FILEAREA
            && !\mod_multicheckin\leave_manager::can_view_attachment((int) $itemid, (int) $USER->id, $context)) {
        return false;
    }

    // A photograph of a student is the same kind of document, and the rule is
    // the same narrow one: the student it belongs to, or a teacher who can
    // already read this activity's reports. "Enrolled in the course" is not
    // enough, and neither is "may view the activity".
    if ($filearea === \mod_multicheckin\photo_manager::FILEAREA
            && !\mod_multicheckin\photo_manager::can_view((int) $itemid, (int) $USER->id, $context)) {
        return false;
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Add this module's options to the course reset form.
 *
 * Two independent choices: clearing the collected check-ins keeps the term's
 * schedule (the usual case between cohorts), while removing the occurrences
 * turns the activity back into a single session. Offering only one of them
 * would force a teacher to delete and recreate the activity to get the other.
 *
 * @param MoodleQuickForm $mform The reset form.
 * @return void
 */
function multicheckin_reset_course_form_definition($mform) {
    $mform->addElement('header', 'multicheckinheader',
        get_string('modulenameplural', 'mod_multicheckin'));

    $mform->addElement('advcheckbox', 'reset_multicheckin',
        get_string('resetcheckindata', 'mod_multicheckin'));

    $mform->addElement('advcheckbox', 'reset_multicheckin_series',
        get_string('resetseries', 'mod_multicheckin', ''));
}

/**
 * Defaults for the course reset form.
 *
 * @param stdClass $course Course.
 * @return array
 */
function multicheckin_reset_course_form_defaults($course) {
    return ['reset_multicheckin_series' => 0];
}

/**
 * Reset user data in the module (course reset).
 *
 * @param stdClass $data Reset data.
 * @return array Status array.
 */
function multicheckin_reset_userdata($data) {
    global $DB;

    $status = [];
    if (!empty($data->reset_multicheckin)) {
        // The work lives in session_manager so that it can be tested without a
        // page request, and so that the list of what "check-in data" covers is
        // written down once rather than assembled here out of whatever this
        // callback happened to remember. See reset_course_data() for why the
        // photographs have to go first and why they go per activity.
        \mod_multicheckin\session_manager::reset_course_data((int) $data->courseid);

        $status[] = [
            'component' => get_string('modulenameplural', 'mod_multicheckin'),
            'item' => get_string('resetcheckindata', 'mod_multicheckin'),
            'error' => false,
        ];
    }

    // Optionally drop the generated occurrences too, keeping the activity as a
    // single session. Separate from the check-in reset above because it changes
    // what the course looks like, not just the collected data.
    if (!empty($data->reset_multicheckin_series)) {
        $templates = $DB->get_records('multicheckin',
            ['course' => $data->courseid, 'parentid' => 0], '', 'id');
        $removed = 0;
        foreach ($templates as $template) {
            $removed += \mod_multicheckin\session_manager::delete_series((int) $template->id);
        }
        $status[] = [
            'component' => get_string('modulenameplural', 'mod_multicheckin'),
            'item' => get_string('resetseries', 'mod_multicheckin', $removed),
            'error' => false,
        ];
    }

    return $status;
}
