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
 * Sends "remember to check in" reminders for check-in points.
 *
 * Two questions decide every reminder, and they are kept apart on purpose:
 *
 * - WHICH points remind. An occurrence chooses one of three rules (see the
 *   RULE_* constants): the first point always reminds and the rest follow a
 *   chain of successful check-ins, every point reminds, or only the first point
 *   of the occurrence reminds.
 * - WHO is reminded. Either the class list of the occurrence (minus anyone who
 *   has already checked in at that point and minus anyone on leave), or - for
 *   the chain rule's later points - only the students who got through the point
 *   immediately before.
 *
 * Those two questions meet in exactly ONE place, remind(): every candidate of
 * every rule passes the same "already signed / on leave / already told" gate
 * there. A filter spread across the candidate builders would be a filter per
 * branch, and one branch would eventually go without.
 *
 * A point becomes due a little before it opens (notifyleadtime), so the body
 * has to say whether the point is open yet: "is open until 10:20" is a lie five
 * minutes before it opens.
 *
 * The task runs every minute for as long as a window is open, so the cost of
 * one run is part of the design: the per-occurrence lookups happen once per
 * occurrence, the "already reminded" set is read once per point, and nothing is
 * looked up inside the loop over recipients.
 *
 * Notifications are delivered through three channels in one call (popup /
 * email / mobile push) via Moodle's message system; if mobile push is not
 * configured on the site, Moodle silently degrades to the other two.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notify_manager {

    /** @var string Message provider component name. */
    public const PROVIDER = 'nextcheckin_reminder';

    /**
     * Rule A: the first point of an occurrence always reminds; a later point
     * reminds only those who checked in at the point immediately before it.
     */
    public const RULE_CHAIN = 'chain';

    /** Rule B: every point of the occurrence reminds. */
    public const RULE_ALL = 'all';

    /** Rule C: only the first point of the occurrence reminds. */
    public const RULE_FIRST = 'first';

    /** @var array The values a stored rule may hold. */
    public const RULES = [self::RULE_CHAIN, self::RULE_ALL, self::RULE_FIRST];

    /**
     * The point has not opened yet, but its lead time has been reached.
     *
     * The body must say so: telling a student a window is open while the button
     * in front of them is still disabled reads as a broken activity.
     */
    public const STATE_SOON = 'soon';

    /** The point is open right now. */
    public const STATE_OPEN = 'open';

    /**
     * Longest lead time an occurrence may ask for, in seconds (one day).
     *
     * Two things depend on there being a bound: the reminder query widens the
     * session window by exactly this much, and a typo of six digits instead of
     * two must not turn every future occurrence into a mail bomb.
     */
    public const MAX_LEADTIME = 86400;

    /**
     * The occurrence columns the reminder loop reads, and no others.
     *
     * The occurrence table also carries the note, the introduction and the
     * scoring rules. Loading them once per occurrence per minute moves bytes
     * for nothing, and this task runs every minute.
     */
    public const SESSION_FIELDS = 'id, course, name, timestart, timeend, notifyleadtime, notifyrule, qronly';

    /**
     * The point columns the reminder loop reads, and no others.
     *
     * Deliberately excludes the point password and the QR signing key: a cron
     * task has no use for either, and a secret that is never read cannot leak
     * through a debug dump. qronly IS read: the reminder body has to know
     * whether the page its link opens will offer a button at all.
     */
    public const POINT_FIELDS = 'id, name, role, sortorder, openoffset, closeoffset, notify, qronly';

    /**
     * Remind whoever is due, for every occurrence in progress.
     *
     * @param int|null $now Current time.
     * @return int Number of messages sent.
     */
    public static function send_due_reminders(?int $now = null): int {
        global $DB;

        $now = $now ?? time();
        $sent = 0;

        // A reminder may legitimately go out BEFORE its point opens - that is
        // what the lead time asks for - so an occurrence that has not started
        // yet is in scope as well. The query is deliberately generous and
        // widened by the largest lead time allowed; due_state() then judges
        // each point exactly. Narrowing it here instead would silently drop the
        // sign-in reminder of every class, whose first point opens at T0.
        $sessions = $DB->get_records_select('multicheckin',
            'notifyenabled = 1 AND timestart <= :nowplus AND timeend >= :now',
            ['nowplus' => $now + self::MAX_LEADTIME, 'now' => $now], '', self::SESSION_FIELDS);

        foreach ($sessions as $session) {
            $points = array_values($DB->get_records('multicheckin_point',
                ['sessionid' => $session->id], 'sortorder ASC', self::POINT_FIELDS));
            if (!$points) {
                continue;
            }

            $rule = self::rule($session);

            // Everything that belongs to the occurrence rather than to one of
            // its points is resolved here, once: the course module, the course
            // name, and who asked for leave. Doing it per point would repeat
            // three lookups for every point of every occurrence, every minute.
            $shared = self::shared_context($session);

            // The class list costs queries of its own (enrolments, roles), so it
            // is built once per occurrence and only when a point actually needs
            // it. A chain-only occurrence never needs it at all for its later
            // points.
            $audience = null;

            foreach ($points as $index => $point) {
                // A point the teacher switched off stays switched off, whatever
                // the rule says.
                if (empty($point->notify)) {
                    continue;
                }
                $state = self::due_state($session, $point, $now);
                if ($state === null) {
                    continue;
                }
                // "Only the first point reminds" is the whole of that rule.
                if ($rule === self::RULE_FIRST && $index > 0) {
                    continue;
                }

                $needsroster = $rule !== self::RULE_CHAIN || $index === 0;
                if ($needsroster && $audience === null) {
                    $audience = self::audience($session, $shared);
                }

                $candidates = self::candidates($session, $points, $index, $rule,
                    $needsroster ? $audience : []);
                $sent += self::remind($session, $point, $candidates, $state, $now, $shared);
            }
        }

        return $sent;
    }

    /**
     * Which rule an occurrence follows.
     *
     * Anything unrecognised - a row written before this column existed, a
     * restore that dropped it, a value edited in the database - falls back to
     * the chain. That is the rule the plugin applied before the choice existed,
     * so an unknown value keeps an old activity behaving as its teacher last
     * saw it rather than turning it into a broadcast.
     *
     * @param \stdClass $session Session record.
     * @return string One of RULE_CHAIN / RULE_ALL / RULE_FIRST.
     */
    public static function rule(\stdClass $session): string {
        $rule = (string) ($session->notifyrule ?? '');

        return in_array($rule, self::RULES, true) ? $rule : self::RULE_CHAIN;
    }

    /**
     * How far before a point opens the reminder may go out.
     *
     * @param \stdClass $session Session record.
     * @return int Seconds, clamped to [0, MAX_LEADTIME].
     */
    public static function lead_time(\stdClass $session): int {
        $lead = (int) ($session->notifyleadtime ?? 0);

        if ($lead < 0) {
            return 0;
        }

        return min($lead, self::MAX_LEADTIME);
    }

    /**
     * Whether a point is due for its reminder right now, and in which state.
     *
     * @param \stdClass $session Session record.
     * @param \stdClass $point Point record.
     * @param int $now Current time.
     * @return string|null STATE_SOON, STATE_OPEN, or null when nothing is due.
     */
    public static function due_state(\stdClass $session, \stdClass $point, int $now): ?string {
        $open = (int) $session->timestart + (int) $point->openoffset;
        $close = (int) $session->timestart + (int) $point->closeoffset;

        // A point that is over must never produce a reminder, however wide the
        // lead time is: the recipient would be told to sign something closed.
        if ($now > $close) {
            return null;
        }
        if ($now >= $open) {
            return self::STATE_OPEN;
        }
        if ($now >= $open - self::lead_time($session)) {
            return self::STATE_SOON;
        }

        return null;
    }

    /**
     * Who should hear about this point, before already-signed users drop out.
     *
     * Leave is deliberately NOT applied here. The chain rule builds its
     * candidates from the previous point's check-ins and never touches the
     * class list, so a leave filter living in this method would cover the class
     * list and leave the chain uncovered - and the chain is the DEFAULT rule.
     * The gate is in remind(), where every candidate of every rule passes.
     *
     * @param \stdClass $session Session record.
     * @param array $points The occurrence's points, ordered.
     * @param int $index Position of the point being reminded about.
     * @param string $rule The occurrence's rule.
     * @param array $audience Class list (userid => true).
     * @return array userid => true.
     */
    public static function candidates(\stdClass $session, array $points, int $index,
            string $rule, array $audience): array {

        if ($rule === self::RULE_CHAIN && $index > 0) {
            // The chain: only students who got through the previous point are
            // told about this one. Someone who missed it is not nudged again,
            // which is exactly what makes this rule quieter than the others.
            return self::signed_users($session, (int) $points[$index - 1]->id);
        }

        return $audience;
    }

    /**
     * The class list of an occurrence, as the roll sheet and the reports see it.
     *
     * This is the roster only: leave is not subtracted here, because remind() is
     * the single gate every rule passes through (see candidates()).
     *
     * @param \stdClass $session Session record.
     * @param \stdClass|null $shared Occurrence context already resolved by the caller.
     * @return array userid => true. Empty when the class cannot be resolved.
     */
    public static function audience(\stdClass $session, ?\stdClass $shared = null): array {
        $shared = $shared ?? self::shared_context($session);
        if (!$shared->cm) {
            return [];
        }
        try {
            $context = \context_module::instance((int) $shared->cm->id, IGNORE_MISSING);
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach (audience_manager::participants($session, $context) as $user) {
            $userid = (int) (is_object($user) ? ($user->id ?? 0) : $user);
            if ($userid > 0) {
                $out[$userid] = true;
            }
        }

        return $out;
    }

    /**
     * The lookups that belong to an occurrence rather than to one of its points.
     *
     * Resolved once per occurrence by send_due_reminders() and handed down, so
     * that a four-point occurrence asks these questions once rather than four
     * times: the module, the course name, and who has been granted leave.
     *
     * @param \stdClass $session Session record.
     * @return \stdClass With cm, coursename and leaves.
     */
    protected static function shared_context(\stdClass $session): \stdClass {
        return (object) [
            'cm' => get_coursemodule_from_instance('multicheckin', (int) $session->id,
                $session->course, false, IGNORE_MISSING),
            'coursename' => self::course_name($session),
            'leaves' => leave_manager::for_session((int) $session->id),
        ];
    }

    /**
     * Students who have a check-in record at one point of one occurrence.
     *
     * A record IS the definition of a successful check-in in this plugin: a
     * photo waiting for approval has not been written yet (approval writes it),
     * so "the previous check-in succeeded" can be read straight off the log.
     *
     * @param \stdClass $session Session record.
     * @param int $pointid Point id.
     * @return array userid => true.
     */
    protected static function signed_users(\stdClass $session, int $pointid): array {
        global $DB;

        // Only the one column, through the (pointid, userid) index: this runs
        // once per point of every occurrence in progress.
        $rows = $DB->get_records('multicheckin_log',
            ['sessionid' => (int) $session->id, 'pointid' => $pointid], '', 'userid');

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->userid] = true;
        }

        return $out;
    }

    /**
     * Students already reminded about one point of one occurrence.
     *
     * Read as a set, in one query. The alternative - asking record_exists() for
     * each student in turn - costs one round trip per student per point per
     * minute and answers the same question every time: sixty students over a
     * half-hour window is eighteen hundred lookups that a single read replaces.
     *
     * @param \stdClass $session Session record.
     * @param int $pointid Point id.
     * @return array userid => true.
     */
    protected static function notified_users(\stdClass $session, int $pointid): array {
        global $DB;

        // One column, through the (sessionid, pointid, userid) unique index.
        $rows = $DB->get_records('multicheckin_notify',
            ['sessionid' => (int) $session->id, 'pointid' => $pointid], '', 'userid');

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->userid] = true;
        }

        return $out;
    }

    /**
     * Send the reminder for one point to everyone in the candidate set who still
     * needs it.
     *
     * This is the single gate on the reminder path. Three questions are asked of
     * every candidate of every rule, in one place, so that no rule added later
     * can quietly skip one of them:
     *
     * - has this student already signed THIS point? then there is nothing to say;
     * - has this student been granted leave for this occurrence? then they asked
     *   not to be expected, and a reminder they cannot act on is noise;
     * - has this student already been reminded about THIS point? then this run
     *   is the previous run's question again.
     *
     * @param \stdClass $session Session record.
     * @param \stdClass $point The point being reminded about.
     * @param array $candidates userid => true.
     * @param string $state STATE_SOON or STATE_OPEN.
     * @param int $now Current time.
     * @param \stdClass|null $shared Occurrence context already resolved by the caller.
     * @return int Number sent.
     */
    protected static function remind(\stdClass $session, \stdClass $point, array $candidates,
            string $state, int $now, ?\stdClass $shared = null): int {
        global $DB;

        if (!$candidates) {
            return 0;
        }

        $shared = $shared ?? self::shared_context($session);

        // Both sets are read for this point, not for this student.
        $signed = self::signed_users($session, (int) $point->id);
        $notified = self::notified_users($session, (int) $point->id);

        // The recipients' timezones, read the same way as the two sets above:
        // once for the point, column-limited, instead of one user lookup inside
        // the loop below. A broadcast is a whole class, and a class is one query.
        $timezones = self::recipient_timezones(array_keys($candidates));

        // Everything that is the same for every recipient of this point, worked
        // out before the loop rather than inside it: a broadcast reaches a whole
        // class, and the module, the course name and both display names are
        // properties of the point, not of the reader.
        $batch = self::batch_context($shared, $session, $point);

        $sent = 0;
        foreach (array_keys($candidates) as $userid) {
            $userid = (int) $userid;
            $skip = $userid <= 0 || isset($signed[$userid]) || isset($notified[$userid])
                || isset($shared->leaves[$userid]);
            if ($skip) {
                continue;
            }

            if (self::send_message($session, $point, $userid, $state, $batch,
                    $timezones[$userid] ?? '')) {
                $DB->insert_record('multicheckin_notify', (object) [
                    'sessionid' => (int) $session->id,
                    'pointid' => (int) $point->id,
                    'userid' => $userid,
                    'timesent' => $now,
                ]);
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * The per-point constants every recipient of a reminder shares.
     *
     * @param \stdClass $shared Occurrence context (cm, coursename, leaves).
     * @param \stdClass $session Session record.
     * @param \stdClass $point Point record.
     * @return \stdClass With cm, coursename, activityname and pointname.
     */
    protected static function batch_context(\stdClass $shared, \stdClass $session,
            \stdClass $point): \stdClass {
        return (object) [
            'cm' => $shared->cm,
            'coursename' => $shared->coursename,
            'activityname' => format_string($session->name),
            'pointname' => format_string(point_name::display($point)),
        ];
    }

    /**
     * Send the message to one user.
     *
     * The recipient may read this on a phone, in an inbox, or as a popup, so the
     * reminder always names BOTH the course and the activity (never a bare
     * "check in" with no clue where), and the body carries a link that lands on
     * the occurrence and the point being reminded about: a clickable one for
     * HTML-aware clients, plus the bare URL on its own line for anything that
     * strips markup.
     *
     * The body never claims the recipient has already checked in. Under the
     * broadcast rules most of them have not, and a reminder that opens with a
     * false statement is one the reader stops trusting.
     *
     * Times are rendered in the RECIPIENT's timezone. This runs under cron,
     * where the "current user" is the site's own, so the default timezone would
     * put a student's window on the server's clock - invisibly right on a
     * single-timezone campus and wrong for everyone else.
     *
     * @param \stdClass $session Session.
     * @param \stdClass $point Point.
     * @param int $userid User id.
     * @param string $state STATE_SOON or STATE_OPEN.
     * @param \stdClass $batch The per-point constants, resolved by the caller.
     * @param string $rawtimezone The recipient's timezone field, as stored.
     * @return bool True if dispatched.
     */
    protected static function send_message(\stdClass $session, \stdClass $point, int $userid,
            string $state, \stdClass $batch, string $rawtimezone = ''): bool {
        $activityname = $batch->activityname;
        $pointname = $batch->pointname;
        $cm = $batch->cm;
        $coursename = $batch->coursename;

        $opentime = (int) $session->timestart + (int) $point->openoffset;
        $closetime = (int) $session->timestart + (int) $point->closeoffset;
        $timezone = self::user_timezone($rawtimezone);

        // The link back to the activity, already positioned on THIS occurrence
        // and THIS point.
        //
        // A bare "view.php?id=..." is not enough once an activity has a series:
        // the page then opens whichever occurrence it considers current, which
        // is not necessarily the class this reminder is about. The session id
        // pins that down, and the point id lets the page mark the row the
        // student is being reminded about instead of leaving them to find it in
        // a list. The fragment is for phones: it scrolls straight to the row.
        //
        // When the cm cannot be resolved we fall back to a body variant that
        // simply omits the whole link paragraph, rather than printing a
        // dangling "copy this address:" with nothing after it.
        $url = '';
        if ($cm) {
            $viewurl = new \moodle_url('/mod/multicheckin/view.php', [
                'id' => (int) $cm->id,
                'session' => (int) $session->id,
                'point' => (int) $point->id,
            ]);
            $viewurl->set_anchor('multicheckin-point-' . (int) $point->id);
            $url = $viewurl->out(false);
        }

        $bodydata = (object) [
            'course' => $coursename,
            'session' => $activityname,
            'point' => $pointname,
            'opens' => userdate($opentime, get_string('strftimetime'), $timezone),
            'time' => userdate($closetime, get_string('strftimetime'), $timezone),
            'link' => '',
            'url' => $url,
            'linksection' => '',
        ];
        $soon = ($state === self::STATE_SOON);
        if ($url !== '') {
            $bodydata->link = \html_writer::link($url,
                get_string('msg_reminder_linktext', 'mod_multicheckin'));
            // A QR-only point has no button on the page this link opens, so the
            // paragraph says what to do instead of promising something the page
            // does not offer - the same failure as a link that points at a page
            // rather than at the row. The address itself is dropped here rather
            // than kept as a secondary route: there is nothing at the other end
            // of it that would record the check-in.
            $bodydata->linksection = qr_manager::only_scan($session, $point)
                ? \html_writer::tag('p', get_string('qronly_email_hint', 'mod_multicheckin'))
                : get_string('msg_reminder_linksection', 'mod_multicheckin', $bodydata);
            $bodykey = $soon ? 'msg_reminder_body_soon' : 'msg_reminder_body';
        } else {
            $bodykey = $soon ? 'msg_reminder_body_soon_nolink' : 'msg_reminder_body_nolink';
        }

        $message = new \core\message\message();
        $message->component = 'mod_multicheckin';
        $message->name = self::PROVIDER;
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $userid;
        $message->subject = get_string('msg_reminder_subject', 'mod_multicheckin', (object) [
            'course' => $coursename,
            'session' => $activityname,
        ]);
        $message->fullmessage = get_string($bodykey, 'mod_multicheckin', $bodydata);
        $message->fullmessageformat = FORMAT_HTML;
        $message->fullmessagehtml = $message->fullmessage;
        // Push notifications are truncated by the OS, so this stays short: course
        // plus activity, no URL. The link travels in contexturl below.
        $message->smallmessage = get_string('msg_reminder_small', 'mod_multicheckin', (object) [
            'course' => $coursename,
            'session' => $activityname,
        ]);
        $message->notification = 1;

        if ($cm) {
            $message->contexturl = $url;
            $message->contexturlname = $activityname;
        }

        try {
            message_send($message);
            return true;
        } catch (\Throwable $e) {
            debugging('mod_multicheckin reminder failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * The timezone fields of a whole set of recipients, in one read.
     *
     * Read for the point rather than for the student, like signed_users() and
     * notified_users(): a class is one query, not sixty lookups. Only the two
     * columns the body needs travel.
     *
     * @param array $userids Recipient ids.
     * @return array userid => timezone field as stored ('' when the row is gone).
     */
    protected static function recipient_timezones(array $userids): array {
        global $DB;

        $wanted = [];
        foreach ($userids as $userid) {
            if ((int) $userid > 0) {
                $wanted[] = (int) $userid;
            }
        }
        if (!$wanted) {
            return [];
        }

        $out = [];
        foreach ($DB->get_records_list('user', 'id', $wanted, '', 'id, timezone') as $row) {
            $out[(int) $row->id] = (string) ($row->timezone ?? '');
        }

        return $out;
    }

    /**
     * The timezone to hand to userdate() for one recipient.
     *
     * The field holds a NAME (`Asia/Shanghai`, which is what the user form
     * offers), the string '99' meaning "whatever the site is set to", an empty
     * value for a user who never chose, or a legacy numeric offset ('8',
     * '-3.5'). Casting the name to a number turns it into 0.0 - UTC - and the
     * reminder then tells a student in Shanghai to check in at a time eight
     * hours away from the one the point opens, without an error anywhere.
     *
     * So nothing is cast. '99' and the empty value become the INTEGER 99 that
     * userdate() compares against (the string '99' does not match its `=== 99`
     * check), and everything else is passed through as written:
     * core_date::normalise_timezone() understands names and the legacy offsets
     * alike, and it is the one place that should decide what they mean.
     *
     * @param string $raw The stored timezone field.
     * @return int|string 99 for the site default, otherwise the field unchanged.
     */
    protected static function user_timezone(string $raw): int|string {
        $tz = trim($raw);
        if ($tz === '' || $tz === '99') {
            return 99;
        }

        return $tz;
    }

    /**
     * Resolve the display name of the course an activity belongs to.
     *
     * Prefers the course full name and falls back to its short name, then to an
     * "unknown course" placeholder rather than throwing: a missing or empty
     * course record must never stop a reminder from going out.
     *
     * @param \stdClass $session Session record.
     * @return string Formatted course name.
     */
    protected static function course_name(\stdClass $session): string {
        global $DB;

        $courseid = (int) ($session->course ?? 0);
        if ($courseid > 0) {
            $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname', IGNORE_MISSING);
            if ($course) {
                if (trim((string) $course->fullname) !== '') {
                    return format_string($course->fullname);
                }
                if (trim((string) $course->shortname) !== '') {
                    return format_string($course->shortname);
                }
            }
        }

        return get_string('msg_reminder_unknowncourse', 'mod_multicheckin');
    }
}
