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
 * Decides who counts as a participant of an activity.
 *
 * The roster used to be "everyone who may check in", which in the default
 * capability set is students, teachers, editing teachers and managers at once.
 * That is right for the permission check on a single check-in, but wrong for a
 * roster: it puts the teacher's own name on the attendance sheet, in the
 * report, and in the gradebook, and the teacher then has to be marked absent
 * (or manually excused) every single lesson.
 *
 * The audience is the roster, and it is a per-activity setting because the
 * answer differs: a lecture has students only, a seminar may want tutors
 * listed, and a training course sometimes tracks everyone. Every roster in the
 * plugin comes from here so the sheet, the report and the grade computation
 * can never disagree about who was expected in the room.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audience_manager {

    /** @var string Default audience: students only. */
    public const DEFAULT_ROLES = 'student';

    /** @var int Stored value length. Matches the CHAR(255) column. */
    public const MAXLEN = 255;

    /**
     * Roles offered in the form, as shortname => localised name.
     *
     * Managers are deliberately absent. An administrator is not a class
     * participant, and listing them would put a site-wide account on every
     * attendance sheet in every course. The other non-student roles
     * (editingteacher, teacher, and any custom teaching role) stay available
     * because a co-teacher genuinely does sometimes take the roll.
     *
     * The list is built from the roles actually assigned in this course
     * context, so a site with its own role names sees its own names, and a
     * course without a given role is not offered a switch that would do
     * nothing.
     *
     * @param \context $context Module context.
     * @return array shortname => localised role name.
     */
    public static function get_role_options(\context $context): array {
        $options = [];

        // get_all_roles() yields the role records themselves. role_get_names()
        // would also work but its third parameter means "return id => localised
        // name", and passing true there hands back strings - which then blow up
        // inside role_get_name(), whose first parameter is typed stdClass.
        // Asking for the records directly makes the intent unambiguous.
        //
        // In a course context get_all_roles() also attaches coursealias, so a
        // role renamed for this course shows the name the teacher sees.
        $roles = get_all_roles($context);
        foreach ($roles as $role) {
            if ($role->shortname === 'manager') {
                continue;
            }
            $options[$role->shortname] = role_get_name($role, $context, ROLENAME_ORIGINAL);
        }

        // The student role is always offered, even if it has not been assigned
        // yet: a teacher building the activity before enrolment opens should
        // still be able to say "students" rather than find an empty box.
        if (!isset($options['student'])) {
            $options['student'] = get_string('audience_role_student', 'mod_multicheckin');
        }

        return $options;
    }

    /**
     * Split a stored audience value into role shortnames.
     *
     * The value is a comma-separated list, written that way so one column
     * carries any combination without a join table. An empty or missing value
     * means "not configured", which reads as the default (students only) - so
     * a row created before this option existed still gets the intended roster.
     *
     * @param mixed $raw Stored value (string), or a session record.
     * @return string[] Role shortnames, de-duplicated and non-empty.
     */
    public static function roles($raw): array {
        if (is_object($raw)) {
            $raw = $raw->audience ?? null;
        }

        $raw = trim((string) ($raw ?? ''));
        if ($raw === '') {
            return [self::DEFAULT_ROLES];
        }

        $out = [];
        foreach (explode(',', $raw) as $shortname) {
            $shortname = trim($shortname);
            if ($shortname !== '' && !in_array($shortname, $out, true)) {
                $out[] = $shortname;
            }
        }

        return $out ?: [self::DEFAULT_ROLES];
    }

    /**
     * Normalise a submitted choice into the stored string.
     *
     * Used by the form before saving. Anything that is not one of the offered
     * roles is dropped rather than trusted, and an empty selection falls back
     * to the default instead of producing "nobody is a participant" - an empty
     * roster would silently stop grades from being computed for the class.
     *
     * @param mixed $submitted Array of shortnames, or a comma-separated string.
     * @param array|null $allowed Allowed shortnames; null to accept any.
     * @return string Comma-separated, length-capped.
     */
    public static function normalise($submitted, ?array $allowed = null): string {
        if (is_string($submitted)) {
            $submitted = explode(',', $submitted);
        }
        if (!is_array($submitted)) {
            $submitted = [];
        }

        $out = [];
        foreach ($submitted as $shortname) {
            $shortname = trim((string) $shortname);
            if ($shortname === '' || in_array($shortname, $out, true)) {
                continue;
            }
            if ($allowed !== null && !in_array($shortname, $allowed, true)) {
                continue;
            }
            $out[] = $shortname;
        }

        if (!$out) {
            return self::DEFAULT_ROLES;
        }

        return \core_text::substr(implode(',', $out), 0, self::MAXLEN);
    }

    /**
     * The roster for one activity: enrolled users in the configured roles.
     *
     * @param \stdClass $session The activity/template record (needs course + id).
     * @param \context $context Module context.
     * @param int $groupid Optional group id to restrict to (0 = all groups).
     * @return array Users keyed by user id, as get_enrolled_users() returns them.
     */
    public static function participants(\stdClass $session, \context $context, int $groupid = 0): array {
        $roles = self::roles($session);

        $users = self::enrolled_in_roles($context, $roles, $groupid);

        // A teacher who ticks a role nobody has yet gets an empty sheet, and an
        // empty sheet looks like a broken activity rather than a setting. Fall
        // back to the students so the page still shows the class.
        if (!$users && !in_array(self::DEFAULT_ROLES, $roles, true)) {
            $users = self::enrolled_in_roles($context, [self::DEFAULT_ROLES], $groupid);
        }

        return $users;
    }

    /**
     * Enrolled users holding any of the given role shortnames.
     *
     * get_enrolled_users() filters by capability, not by role, so it cannot
     * answer "only the students". The two are therefore combined: the
     * enrolment SQL built by get_enrolled_sql() is used as the roster (it is
     * what keeps suspended users and enrolment methods accounted for the same
     * way every other list in Moodle does), and the role filter is applied as
     * a semi-join against the role assignments in THIS context.
     *
     * The role match is a WHERE ... IN (subquery) rather than a join so the
     * query returns one row per user. A join would return a row per matching
     * role, and a user holding both teacher and student would appear twice on
     * the sheet.
     *
     * @param \context $context Module context.
     * @param string[] $roles Role shortnames.
     * @param int $groupid Optional group id (0 = all groups).
     * @return array Users keyed by user id.
     */
    private static function enrolled_in_roles(\context $context, array $roles, int $groupid = 0): array {
        global $DB;

        if (!$roles) {
            return [];
        }

        // Resolve the shortnames to ids. A shortname that does not exist on
        // this site is dropped, so a stale stored value cannot make the query
        // return nothing at all.
        list($insql, $params) = $DB->get_in_or_equal($roles, SQL_PARAMS_NAMED, 'role');
        $roleids = $DB->get_fieldset_sql(
            "SELECT id
               FROM {role}
              WHERE shortname {$insql}",
            $params
        );
        if (!$roleids) {
            return [];
        }

        list($usersql, $userparams) = get_enrolled_sql($context, '', $groupid, true);
        if (trim($usersql) === '') {
            return [];
        }

        list($roleinsql, $roleparams) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'rid');

        // Role assignments are matched in this module's context and, for
        // teachers, in the course context above it. A co-teacher is normally
        // assigned on the course rather than on one activity, so checking only
        // the module context would miss exactly the case the option exists for.
        $ctxids = [$context->id];
        $coursecontext = $context->get_course_context(false);
        if ($coursecontext) {
            $ctxids[] = $coursecontext->id;
        }
        list($ctxinsql, $ctxparams) = $DB->get_in_or_equal($ctxids, SQL_PARAMS_NAMED, 'ctx');

        $sql = "SELECT u.*
                  FROM {user} u
                  JOIN ({$usersql}) eu ON eu.id = u.id
                 WHERE u.id IN (
                           SELECT ra.userid
                             FROM {role_assignments} ra
                            WHERE ra.roleid {$roleinsql}
                              AND ra.contextid {$ctxinsql}
                       )
              ORDER BY u.lastname, u.firstname";

        $params = array_merge($userparams, $roleparams, $ctxparams);

        return $DB->get_records_sql($sql, $params);
    }
}
