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

namespace mod_multicheckin\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task: recompute statuses for any session that has ended but is not yet
 * finalised. This is the safety net that guarantees "absent" is applied to students
 * who never checked in, mirroring how mod_attendance applies unmarked statuses.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finalise_sessions extends \core\task\scheduled_task {

    /**
     * Task name shown in admin.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_finalise_sessions', 'mod_multicheckin');
    }

    /**
     * Run the task.
     *
     * @return void
     */
    public function execute(): void {
        global $CFG, $DB;

        // Occurrences that have ended and that nothing has settled since.
        //
        // "Ended recently" on its own is the wrong question, and answering it
        // was expensive: every occurrence that had ended in the past week was
        // recomputed in full on every run, a whole class worth of reads and
        // writes each hour that produced exactly what the previous hour had
        // already stored. Nothing about it ever changed, so nothing ever noticed.
        //
        // What this task promises is narrower than that. When an occurrence
        // ends, its statuses must be computed at least once, so that students
        // who never checked in are written as absent instead of being left
        // without a row. Every path that can change a result after that point -
        // a check-in, a manual mark, a leave, an edited point - recomputes on
        // the spot (see checkin_engine::refresh_user()). The only gap left is
        // the occurrence nobody computed at all.
        //
        // So the question is "has this been settled since it ended", and
        // timefinalised is where this task records its own answer, after a run
        // has completed. Reading the same thing off the result rows was the
        // tempting version and it is wrong: a run that died after writing thirty
        // of sixty students leaves thirty rows stamped after timeend, and every
        // later run would read that as done and skip the thirty it never
        // reached. A safety net that fails exactly when something failed is not
        // a safety net.
        //
        // The seven days stay as an outer bound rather than as the rule: it
        // keeps the task from chasing ancient history if a site was offline, or
        // the task was disabled, for a month.
        //
        // With a series this deliberately picks up every occurrence AND the
        // template. The template's own row is what the activity form edits, but
        // when no occurrences exist it is also the single session, and its dates
        // are real; a template that carries occurrences simply has end dates
        // that pass unnoticed, which is harmless because its results are empty.
        // Skipping the template instead would silently drop the safety net for
        // every single-session activity on upgrade.
        $now = time();
        $since = $now - (7 * DAYSECS);
        $sessions = $DB->get_records_select('multicheckin',
            'timefinalised = 0 AND timeend < :now AND timeend > :since',
            ['now' => $now, 'since' => $since]);

        // Group the ended sessions by their activity so the gradebook is pushed
        // once per activity rather than once per occurrence. Fifteen pushes of
        // the same aggregate to the same grade item is fifteen writes that end
        // in the identical value.
        $templates = [];
        foreach ($sessions as $session) {
            $this->settle($session);

            $templateid = \mod_multicheckin\session_manager::is_occurrence($session)
                ? (int) $session->parentid
                : (int) $session->id;
            $templates[$templateid] = true;
        }

        // Cron does not include mod/multicheckin/lib.php - only a request that
        // goes through require_login() does - so nothing below may rely on a
        // bare lib.php function. The gradebook push therefore goes through the
        // class, which the autoloader always finds. Calling
        // multicheckin_update_grades() here instead is what made this task fail
        // on every run, with "Call to undefined function
        // mod_multicheckin\task\multicheckin_update_grades()", as soon as an
        // activity had a finished session.
        //
        // lib.php is still loaded below as a belt: this file may one day reach
        // for something else in it, and paying one require here is cheaper than
        // that surprise. The path goes through __DIR__ on purpose - on a Moodle
        // 5.x site the plugin lives under public/, so $CFG->dirroot . '/mod/...'
        // misses by a whole directory and the require fails silently.
        require_once(__DIR__ . '/../../lib.php');

        foreach (array_keys($templates) as $templateid) {
            $template = $DB->get_record('multicheckin', ['id' => $templateid]);
            if ($template) {
                // Push the aggregated statuses into the course gradebook.
                \mod_multicheckin\grade_helper::update_grades($template);
            }
        }

        // Capture permits whose ten minutes ran out and whose owner never came
        // back. Nothing on any page would ever revisit those rows, so without
        // this they would sit there for the life of the site. It rides here
        // rather than in a task of its own because this one already runs
        // hourly, and one more statement is cheaper than another registration
        // and another admin string to explain it.
        \mod_multicheckin\photo_manager::prune_passes();
    }

    /**
     * Settle one occurrence, then record that it has been settled.
     *
     * Split out so that the two halves can be seen together and tested together:
     * the mark is written only after compute_session() has returned, and a run
     * that throws in the middle leaves the mark at zero for the next run to pick
     * up. Writing the mark before the work, or inferring it from the result rows
     * afterwards, both produce a task that quietly stops doing its job - the
     * first because a crash is recorded as success, the second because a
     * half-finished run looks finished.
     *
     * @param \stdClass $session Session record.
     * @return void
     */
    public function settle(\stdClass $session): void {
        \mod_multicheckin\checkin_engine::compute_session((int) $session->id);
        $this->mark_finalised((int) $session->id);
    }

    /**
     * Record that an occurrence has been settled.
     *
     * @param int $sessionid Session id.
     * @return void
     */
    protected function mark_finalised(int $sessionid): void {
        global $DB;

        $DB->set_field('multicheckin', 'timefinalised', time(), ['id' => $sessionid]);
    }
}
