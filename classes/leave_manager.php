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
 * The "on leave" declaration for one student in one occurrence.
 *
 * Everything else in this plugin derives a status from check-in LOGS, and that
 * works because a check-in is an event against a point. A leave is not: it says
 * "I was not there for this whole class", which no point can express, and it
 * carries two things a log row has nowhere to keep - a reason and an optional
 * note file. So it lives in its own table and the engine consults it first.
 *
 * The precedence rule is deliberate and worth stating plainly:
 *
 *   - A leave WINS over the logs. It is a human declaration, and the teacher's
 *     marking screen offers exactly this as a status, so it has to be the thing
 *     that decides the outcome - otherwise ticking "leave" would appear to do
 *     nothing whenever the student happened to have a record.
 *
 *   - Clearing a leave is NON-DESTRUCTIVE. The logs are never touched, so
 *     removing a leave simply lets the check-in records speak again and the
 *     student's real times and minutes reappear. A teacher who set the status
 *     wrongly is one click away from the previous state.
 *
 * The review flag is the "teacher still has to look at this" marker: a leave a
 * STUDENT raised starts unreviewed and is what the pending badge counts; one a
 * TEACHER set starts already reviewed, because there is nothing left to check.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class leave_manager {

    /** @var string Component name, for the file API. */
    public const COMPONENT = 'mod_multicheckin';

    /** @var string File area holding the optional leave note. */
    public const FILEAREA = 'leaveattachment';

    /** @var string Raised by the student from the activity page. */
    public const ORIGIN_STUDENT = 'student';

    /** @var string Set by a teacher from the marking screens. */
    public const ORIGIN_TEACHER = 'teacher';

    /** @var int At most one note file per leave. */
    public const MAXFILES = 1;

    /**
     * Types the note may be. A photo of a paper slip, or a scanned PDF.
     *
     * @return string[] File extensions, as accepted_types() expects.
     */
    public static function accepted_types(): array {
        return ['.jpg', '.jpeg', '.png', '.gif', '.webp', '.pdf'];
    }

    /**
     * The context the note files live in.
     *
     * An occurrence is an extra row in the same table as its template and has no
     * course module of its own, so the file area hangs off the TEMPLATE's module
     * context - the same one every other page for this activity already uses.
     *
     * @param \stdClass $session Session record (template or occurrence).
     * @return \context Module context.
     */
    public static function context_for_session(\stdClass $session): \context {
        $template = session_manager::get_template_for_session($session);
        $instance = $template ? (int) $template->id : (int) $session->id;
        $cm = get_coursemodule_from_instance('multicheckin', $instance, 0, false, MUST_EXIST);

        return \context_module::instance($cm->id);
    }

    /**
     * File-picker options for the leave note.
     *
     * Everything this needs comes from core's file API (lib/filelib.php), which
     * every page already loads; the repository library that used to be pulled
     * in here is not part of a draft-area save or of the picker's option list.
     *
     * @return array
     */
    public static function file_options(): array {
        global $CFG;

        return [
            'subdirs' => 0,
            'maxbytes' => get_max_upload_file_size((int) ($CFG->maxbytes ?? 0)),
            'maxfiles' => self::MAXFILES,
            'accepted_types' => self::accepted_types(),
        ];
    }

    /**
     * Whether a leave may be requested for this session right now.
     *
     * A leave is a statement about the whole class rather than an event against
     * a check-in point, so it is deliberately NOT tied to any point's window: a
     * student who knows in advance that they will be away can say so, and one
     * who only decides during the lesson can still say so. The one hard edge is
     * the end of the session - after that the attendance is settled and only
     * the teacher's marking screen changes it.
     *
     * @param \stdClass $session Session record.
     * @param int|null $now Timestamp to test against, defaults to now.
     * @return bool
     */
    public static function is_open(\stdClass $session, ?int $now = null): bool {
        $now = $now ?? time();

        return $now <= (int) $session->timeend;
    }

    /**
     * One student's leave for one occurrence, or null when there is none.
     *
     * @param int $sessionid Occurrence id.
     * @param int $userid User id.
     * @return \stdClass|null
     */
    public static function record(int $sessionid, int $userid): ?\stdClass {
        global $DB;

        $row = $DB->get_record('multicheckin_leave', [
            'sessionid' => $sessionid,
            'userid' => $userid,
        ]);

        return $row ?: null;
    }

    /**
     * Every leave of a session, keyed by user id.
     *
     * Read once for the whole sheet rather than once per student, so rendering a
     * class list stays a constant number of queries.
     *
     * @param int $sessionid Occurrence id.
     * @return array<int, \stdClass>
     */
    public static function for_session(int $sessionid): array {
        global $DB;

        $rows = $DB->get_records('multicheckin_leave', ['sessionid' => $sessionid]);
        $keyed = [];
        foreach ($rows as $row) {
            $keyed[(int) $row->userid] = $row;
        }

        return $keyed;
    }

    /**
     * How many leaves of this session the teacher has not looked at yet.
     *
     * @param int $sessionid Occurrence id.
     * @return int
     */
    public static function pending_count(int $sessionid): int {
        global $DB;

        return $DB->count_records('multicheckin_leave', [
            'sessionid' => $sessionid,
            'reviewed' => 0,
        ]);
    }

    /**
     * Create or update a leave, storing the note file from a draft area.
     *
     * The file is written through the draft area so that the form, the maxfile
     * limit and the accepted-type list all come from one filepicker rather than
     * from a hand-rolled upload. Passing a null draft leaves whatever is stored
     * alone, which is what the teacher screens want: they set a status, they do
     * not replace the student's evidence.
     *
     * @param \stdClass $session Session record.
     * @param int $userid Student id.
     * @param string $reason Free-text reason (may be empty).
     * @param string $origin ORIGIN_STUDENT or ORIGIN_TEACHER.
     * @param int|null $draftitemid Draft area holding the note, or null to leave files alone.
     * @param int $byuserid Who is writing this.
     * @return \stdClass The stored leave row.
     */
    public static function save(\stdClass $session, int $userid, string $reason, string $origin,
            ?int $draftitemid = null, int $byuserid = 0): \stdClass {
        global $DB;

        $now = time();
        $existing = self::record((int) $session->id, $userid);

        if ($existing) {
            $row = $existing;
            $row->reason = $reason;
            $row->origin = $origin;
            $row->timemodified = $now;

            // A teacher touching a leave has, by definition, looked at it. On a
            // student write nothing changes here, so a re-submit cannot silently
            // clear a review the teacher already did.
            if ($origin === self::ORIGIN_TEACHER) {
                $row->reviewed = 1;
                $row->reviewedby = $byuserid;
            }

            $DB->update_record('multicheckin_leave', $row);
        } else {
            $row = (object) [
                'sessionid' => (int) $session->id,
                'userid' => $userid,
                'reason' => $reason,
                'origin' => $origin,
                'reviewed' => $origin === self::ORIGIN_TEACHER ? 1 : 0,
                'reviewedby' => $origin === self::ORIGIN_TEACHER ? $byuserid : 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $row->id = $DB->insert_record('multicheckin_leave', $row);
        }

        if ($draftitemid !== null) {
            $context = self::context_for_session($session);
            file_save_draft_area_files($draftitemid, $context->id, self::COMPONENT, self::FILEAREA,
                (int) $row->id, self::file_options());
        }

        return $row;
    }

    /**
     * Take a student off leave, restoring whatever the logs say.
     *
     * The log rows are deliberately left alone: this is the non-destructive half
     * of the precedence rule, and it is what makes the status a toggle a teacher
     * can undo.
     *
     * @param \stdClass $session Session record.
     * @param int $userid Student id.
     * @return bool True when a leave was actually removed.
     */
    public static function remove(\stdClass $session, int $userid): bool {
        global $DB;

        $existing = self::record((int) $session->id, $userid);
        if (!$existing) {
            return false;
        }

        $context = self::context_for_session($session);
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, self::COMPONENT, self::FILEAREA, (int) $existing->id);

        $DB->delete_records('multicheckin_leave', ['id' => (int) $existing->id]);

        return true;
    }

    /**
     * Flip the "teacher has looked at this" flag.
     *
     * @param \stdClass $session Session record.
     * @param int $userid Student id.
     * @param bool $reviewed New state.
     * @param int $byuserid Who is marking it.
     * @return bool True when a leave was found and updated.
     */
    public static function set_reviewed(\stdClass $session, int $userid, bool $reviewed, int $byuserid): bool {
        global $DB;

        $existing = self::record((int) $session->id, $userid);
        if (!$existing) {
            return false;
        }

        $DB->update_record('multicheckin_leave', (object) [
            'id' => (int) $existing->id,
            'reviewed' => $reviewed ? 1 : 0,
            'reviewedby' => $reviewed ? $byuserid : 0,
            'timemodified' => time(),
        ]);

        return true;
    }

    /**
     * The student's note file, if one was attached.
     *
     * @param \stdClass $session Session record.
     * @param int $leaveid Leave row id.
     * @return \stored_file|null
     */
    public static function attachment(\stdClass $session, int $leaveid): ?\stored_file {
        $context = self::context_for_session($session);
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, self::COMPONENT, self::FILEAREA, $leaveid,
            'sortorder, id', false);

        if (!$files) {
            return null;
        }

        return reset($files);
    }

    /**
     * Whether a leave carries a note file (used by the reports to show a paperclip).
     *
     * @param \stdClass $session Session record.
     * @param int $leaveid Leave row id.
     * @return bool
     */
    public static function has_attachment(\stdClass $session, int $leaveid): bool {
        return self::attachment($session, $leaveid) !== null;
    }

    /**
     * Drop every leave of a session, files included.
     *
     * Used when the activity (or one of its occurrences) is deleted, so no
     * orphan rows or files are left behind. The context is resolved defensively:
     * this runs inside course-module deletion, where the module row may already
     * be gone, and a missing context must not abort the whole cleanup.
     *
     * @param int $sessionid Occurrence id.
     * @return void
     */
    public static function delete_all_for_session(int $sessionid): void {
        global $DB;

        $rows = $DB->get_records('multicheckin_leave', ['sessionid' => $sessionid], '', 'id');
        if ($rows) {
            $context = self::safe_context($sessionid);
            if ($context) {
                $fs = get_file_storage();
                foreach ($rows as $row) {
                    $fs->delete_area_files($context->id, self::COMPONENT, self::FILEAREA, (int) $row->id);
                }
            }
        }

        $DB->delete_records('multicheckin_leave', ['sessionid' => $sessionid]);
    }

    /**
     * Drop one student's leave for one occurrence, note file included.
     *
     * The privacy API needs a targeted delete; remove() needs the session record
     * to hand, which a contextlist-driven cleanup does not have.
     *
     * @param int $sessionid Occurrence id.
     * @param int $userid User id.
     * @return void
     */
    public static function delete_for_user(int $sessionid, int $userid): void {
        global $DB;

        $row = self::record($sessionid, $userid);
        if (!$row) {
            return;
        }

        $context = self::safe_context($sessionid);
        if ($context) {
            $fs = get_file_storage();
            $fs->delete_area_files($context->id, self::COMPONENT, self::FILEAREA, (int) $row->id);
        }

        $DB->delete_records('multicheckin_leave', ['id' => (int) $row->id]);
    }

    /**
     * Whether this user may read a leave's note file.
     *
     * A leave note is one student's personal evidence, so the rule is narrow on
     * purpose: the student it belongs to, or a teacher who can already see the
     * whole activity's reports. Nothing else - not "any enrolled student", and
     * not "anyone who may view the activity" - may open it.
     *
     * @param int $leaveid Leave row id, which is the file's itemid.
     * @param int $userid User asking for the file.
     * @param \context $context Module context of the activity.
     * @return bool
     */
    public static function can_view_attachment(int $leaveid, int $userid, \context $context): bool {
        global $DB;

        $leave = $DB->get_record('multicheckin_leave', ['id' => $leaveid], 'id,userid');
        if (!$leave) {
            return false;
        }

        if ((int) $leave->userid === $userid) {
            return true;
        }

        return has_any_capability(
            ['mod/multicheckin:manage', 'mod/multicheckin:viewreports'], $context);
    }

    /**
     * Every leave of an activity, across all of its occurrences.
     *
     * The teacher's review list is a task list for the whole activity rather
     * than for whichever date happens to be selected, so it reads from the
     * template outwards: the template's own row and every occurrence that
     * points at it. Unreviewed leaves come first, which is what makes the list
     * usable as a queue.
     *
     * @param int $templateid Activity template id.
     * @param bool $pendingonly Only the leaves no teacher has looked at yet.
     * @return array Leave rows, pending first then newest first.
     */
    public static function for_template(int $templateid, bool $pendingonly = false): array {
        global $DB;

        // The template is one of its own occurrences, so both branches are
        // needed - but each needs its own placeholder. Core's fix_sql_params()
        // counts how many TIMES a name appears and compares that with the size
        // of $params, so writing :templateid twice makes a two-argument call
        // look like a three-argument query and it is rejected with
        // invalidqueryparam.
        $sql = "SELECT l.*
                  FROM {multicheckin_leave} l
                  JOIN {multicheckin} s ON s.id = l.sessionid
                 WHERE (s.id = :templateid OR s.parentid = :parentid)";
        if ($pendingonly) {
            $sql .= ' AND l.reviewed = 0';
        }
        $sql .= ' ORDER BY l.reviewed ASC, l.timecreated DESC';

        return $DB->get_records_sql($sql, ['templateid' => $templateid, 'parentid' => $templateid]) ?: [];
    }

    /**
     * Module context for an occurrence, or null when it cannot be resolved.
     *
     * Public because it is the general answer to "which module's file area does
     * this row's content live in", and the description files need it for the
     * same reason the leave notes do - from inside the cleanup paths that are
     * deleting the row and so cannot look the context up themselves.
     *
     * @param int $sessionid Occurrence id.
     * @return \context|null
     */
    public static function safe_context(int $sessionid): ?\context {
        global $DB;

        $session = $DB->get_record('multicheckin', ['id' => $sessionid]);
        if (!$session) {
            return null;
        }

        try {
            return self::context_for_session($session);
        } catch (\moodle_exception $e) {
            return null;
        }
    }
}
