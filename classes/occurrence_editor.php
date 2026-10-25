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
 * Editing one occurrence: its title, its note, and its two times.
 *
 * Two things make this worth a class of its own rather than four assignments
 * scattered across the pages that offer the form.
 *
 * The first is that an occurrence's times are not decoration. Every check-in
 * point is stored as an offset from the occurrence's start, so moving
 * `timestart` moves every point window with it, and a check-in that was
 * on time when it was collected can become late - or an absence become a
 * present - without a single log row changing. Whoever edits the times has to
 * know that, so the decision "does this need a recompute?" is made here once
 * and not re-derived per page.
 *
 * The second is that batch editing has to know what a teacher actually changed.
 * A form that posts twenty untouched rows must not recompute twenty sessions;
 * comparing old against new is the only way to tell a no-op from an edit.
 *
 * Wall-clock conversion deliberately goes through the SERVER timezone, which is
 * the same one schedule_planner::parse_custom_row() uses when the series is
 * generated from the repeat rule. A date typed in the series form and the same
 * date typed on this editor therefore land on the same instant; using the
 * user's own timezone here would make the two disagree for anyone logged in
 * on a different clock.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class occurrence_editor {

    /**
     * @var string[] The only columns on {multicheckin} this class will write.
     *
     * photorequired is here because it is a property of ONE class meeting: a
     * teacher may photograph the lab session and not the lecture, so it is
     * edited per occurrence like the title and the times. It is deliberately
     * NOT in sync_occurrence_settings()'s shared list - putting it there would
     * mean that saving the activity form silently switched the demand on or off
     * for every date of the term.
     */
    public const EDITABLE = ['name', 'description', 'descriptionformat', 'photorequired', 'timestart', 'timeend'];

    /** @var string[] The subset of EDITABLE that moves the check-in windows. */
    public const TIMEFIELDS = ['timestart', 'timeend'];

    /** @var int Longest description accepted, in characters. */
    public const DESCRIPTION_MAX = 2000;

    /** @var int Earliest year an occurrence may be moved to. */
    public const MIN_YEAR = 2000;

    /** @var int Latest year an occurrence may be moved to. */
    public const MAX_YEAR = 2100;

    /**
     * Turn a typed date and time into a timestamp.
     *
     * Delegates to the planner so that the series form, the series preview and
     * this editor all read "2026-10-14" the same way, including the tolerance
     * for a dot instead of a colon in the clock.
     *
     * @param string $date YYYY-MM-DD.
     * @param string $time HH:MM, blank meaning midnight.
     * @return int Timestamp, or 0 when the pair is not usable.
     */
    public static function parse_wallclock(string $date, string $time): int {
        return (int) (schedule_planner::parse_custom_row($date, $time) ?? 0);
    }

    /**
     * The date and time a timestamp stands for, in the server timezone.
     *
     * The exact inverse of parse_wallclock(), and it must stay that way: the
     * batch editor renders these two strings into its inputs and reads them
     * back on save, so a round trip that is not lossless would move every
     * occurrence a user merely scrolled past.
     *
     * @param int $timestamp Timestamp.
     * @return array ['date' => 'Y-m-d', 'time' => 'H:i'].
     */
    public static function format_wallclock(int $timestamp): array {
        $when = new \DateTime('@' . $timestamp);
        $when->setTimezone(\core_date::get_server_timezone_object());

        return [
            'date' => $when->format('Y-m-d'),
            'time' => $when->format('H:i'),
        ];
    }

    /**
     * Strip the characters that have no business in a description.
     *
     * Not an escaping step: the value is stored raw and escaped on output, the
     * way every other free-text field in this plugin is. This removes only the
     * control characters (keeping tab and the two line breaks) that would
     * otherwise reach a browser, a CSV export or a notification unchanged.
     *
     * @param string $raw Raw value.
     * @return string Trimmed value.
     */
    public static function clean(string $raw): string {
        $raw = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $raw);

        return trim($raw);
    }

    /**
     * Normalise a raw form submission into the values this class understands.
     *
     * @param array $raw Raw values, typically a form or POST array.
     * @return array Values restricted to EDITABLE.
     */
    public static function values(array $raw): array {
        $values = [];

        if (array_key_exists('name', $raw)) {
            $values['name'] = self::clean((string) $raw['name']);
        }
        if (array_key_exists('description', $raw)) {
            $values['description'] = self::clean((string) $raw['description']);
        }
        // Written whenever it is supplied, and never inferred. A row that is
        // edited through the editor element carries its format with it; a row
        // edited in the batch table's plain textarea says nothing about the
        // format and must keep the one it already has, which is what leaving
        // the key out achieves.
        if (array_key_exists('descriptionformat', $raw)) {
            $values['descriptionformat'] = (int) $raw['descriptionformat'];
        }
        // A checkbox, so anything that is not an explicit yes is a no. Read as
        // a flag rather than as a number: the batch table posts a missing key
        // for every row a teacher left unticked, and a missing key has to mean
        // "off", not "leave whatever was there".
        if (array_key_exists('photorequired', $raw)) {
            $values['photorequired'] = empty($raw['photorequired']) ? 0 : 1;
        }
        foreach (self::TIMEFIELDS as $field) {
            if (array_key_exists($field, $raw)) {
                $values[$field] = (int) $raw[$field];
            }
        }

        return $values;
    }

    /**
     * Which editable columns a set of values would actually change.
     *
     * Compared as strings, the same way sync_occurrence_settings() decides
     * whether it has anything to write, so "unchanged" means the same thing on
     * both paths.
     *
     * @param \stdClass $occurrence Occurrence record.
     * @param array $values Normalised values.
     * @return string[] Field names that differ.
     */
    public static function changes(\stdClass $occurrence, array $values): array {
        $changed = [];
        foreach (self::EDITABLE as $field) {
            if (!array_key_exists($field, $values)) {
                continue;
            }
            if ((string) ($occurrence->$field ?? '') !== (string) $values[$field]) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * Whether a change list moves the occurrence in time.
     *
     * Answers the only question the caller has to ask before recomputing: a
     * renamed occurrence with a new note leaves every judgement standing.
     *
     * @param string[] $changed Field names from changes().
     * @return bool
     */
    public static function needs_recalc(array $changed): bool {
        return (bool) array_intersect(self::TIMEFIELDS, $changed);
    }

    /**
     * Whether the teacher has to confirm the consequences before saving.
     *
     * Only when the times move AND there is collected attendance to re-judge.
     * Moving the times of an occurrence nobody has checked into yet is free,
     * and asking about it would train the teacher to click through the warning.
     *
     * @param string[] $changed Field names from changes().
     * @param int $logcount Check-in log rows recorded for the occurrence.
     * @return bool
     */
    public static function confirm_needed(array $changed, int $logcount): bool {
        return self::needs_recalc($changed) && $logcount > 0;
    }

    /**
     * Validate a set of normalised values.
     *
     * Returns language keys rather than messages so the caller decides how to
     * present them; the keys resolve against this plugin's language pack.
     *
     * @param array $values Normalised values.
     * @return array Field name => language key.
     */
    public static function validate(array $values): array {
        $errors = [];

        // Trimmed here as well as in values(), so that a caller which builds
        // the array by hand - or a future one that forgets to normalise - still
        // cannot save an occurrence whose title is a run of spaces.
        if (array_key_exists('name', $values) && trim((string) $values['name']) === '') {
            $errors['name'] = 'session_title_required';
        }

        // Measured in visible characters, not in markup. The limit has always
        // meant "how much can a teacher write", and a description wrapped in a
        // paragraph is not three characters longer than the same sentence typed
        // without one. A caller that supplies no format is judged as plain text,
        // which counts the stored string exactly as the pre-editor code did.
        if (array_key_exists('description', $values)) {
            $format = (int) ($values['descriptionformat'] ?? FORMAT_PLAIN);
            if (description_editor::visible_length((string) $values['description'], $format)
                    > self::DESCRIPTION_MAX) {
                $errors['description'] = 'session_description_toolong';
            }
        }

        // Checked whenever the caller supplied a time at all. "Supplied but
        // unparsable" and "supplied as zero" both arrive here as 0 and are both
        // refused: a batch row whose date box was cleared must be an error, not
        // a session silently moved to 1970. A caller editing only the title and
        // the note omits the two fields and is not judged on them.
        if (array_key_exists('timestart', $values) || array_key_exists('timeend', $values)) {
            $start = (int) ($values['timestart'] ?? 0);
            $end = (int) ($values['timeend'] ?? 0);
            if ($start <= 0 || $end <= 0) {
                $errors['timestart'] = 'session_time_invalid';
            } else if ($end <= $start) {
                $errors['timeend'] = 'session_time_order';
            } else if (!self::year_in_range($start) || !self::year_in_range($end)) {
                $errors['timestart'] = 'session_time_range';
            }
        }

        return $errors;
    }

    /**
     * Whether a timestamp falls inside the years the editor accepts.
     *
     * A mistyped year is the one time error that is both easy to make and hard
     * to notice: 2026 typed as 20206 leaves the session unreachable by any
     * picker, and 2016 quietly rewrites which term the occurrence belongs to.
     *
     * @param int $timestamp Timestamp.
     * @return bool
     */
    private static function year_in_range(int $timestamp): bool {
        $wall = self::format_wallclock($timestamp);
        $year = (int) substr($wall['date'], 0, 4);

        return $year >= self::MIN_YEAR && $year <= self::MAX_YEAR;
    }

    /**
     * Check-in log rows recorded against an occurrence.
     *
     * @param int $sessionid Occurrence id.
     * @return int
     */
    public static function log_count(int $sessionid): int {
        global $DB;

        return $DB->count_records('multicheckin_log', ['sessionid' => $sessionid]);
    }

    /**
     * Final judgements computed for an occurrence.
     *
     * @param int $sessionid Occurrence id.
     * @return int
     */
    public static function result_count(int $sessionid): int {
        global $DB;

        return $DB->count_records('multicheckin_result', ['sessionid' => $sessionid]);
    }

    /**
     * Write the changed columns, and nothing else.
     *
     * The occurrence passed in is updated in place so the caller can hand the
     * same record to recalc() without re-reading it.
     *
     * @param \stdClass $occurrence Occurrence record.
     * @param array $values Normalised values.
     * @return array ['changed' => string[], 'occurrence' => \stdClass].
     */
    public static function save(\stdClass $occurrence, array $values): array {
        global $DB;

        $changed = self::changes($occurrence, $values);
        if (!$changed) {
            return ['changed' => [], 'occurrence' => $occurrence];
        }

        $record = (object) ['id' => (int) $occurrence->id, 'timemodified' => time()];
        foreach ($changed as $field) {
            $record->$field = $values[$field];
            $occurrence->$field = $values[$field];
        }
        $DB->update_record('multicheckin', $record);

        return ['changed' => $changed, 'occurrence' => $occurrence];
    }

    /**
     * Re-judge an occurrence and push the result to the gradebook.
     *
     * Called only after the times moved: the check-in logs are never touched,
     * which is what makes the operation reversible. Moving the times back
     * restores the previous judgements exactly.
     *
     * @param \stdClass $occurrence Occurrence record, already saved.
     * @return void
     */
    public static function recalc(\stdClass $occurrence): void {
        global $DB;

        // The times moved, so a stamp saying "this occurrence was settled after
        // it ended" is a statement about a different end time. Clearing it puts
        // the occurrence back in the scheduled task's queue, which is what
        // guarantees that the students who never check in get their absent row
        // when the new end time passes. The recompute below still happens now;
        // the mark only decides whether the task looks again later.
        $DB->set_field('multicheckin', 'timefinalised', 0, ['id' => (int) $occurrence->id]);

        checkin_engine::compute_session((int) $occurrence->id);
    }

    /**
     * Apply a whole page of edits, or explain why none of it was applied.
     *
     * All or nothing on purpose. A term's worth of rows is one form, and the
     * teacher who mistyped one date expects the page back with that row marked,
     * not eleven sessions saved and the twelfth quietly left behind.
     *
     * The recompute gate is the same decision the single-occurrence page makes,
     * through the same confirm_needed(): if the times of an occurrence that has
     * collected check-ins would move, nothing is written until the teacher has
     * said so. That confirmation is submitted with the form rather than shown
     * in a dialog, so it holds with scripting switched off and can be asserted
     * on the server.
     *
     * A note is posted only by a caller that drew a note field, and since
     * v2.0.0 the batch table draws none: it links to the note window instead,
     * which writes the column on its own. A row that arrives without a note
     * leaves the column exactly as it is - building the key from a missing
     * field would turn every batch save into a batch wipe. When a row does
     * carry one it arrives as an editor array (text, format, itemid) and is
     * read twice on purpose: once here, without touching the file table, so
     * that the comparison and the validation see the same value the row would
     * be saved with; and once in the write loop below, where the draft behind a
     * rich row is finally moved into place. Doing the move in the first pass
     * would attach a picture for a page that is refused a moment later.
     *
     * @param array $occurrences id => occurrence record, restricted to this activity.
     * @param array $rows id => raw strings: name, date, clock, enddate, endclock,
     *                    and description when the caller drew a note field.
     * @param array $checkins id => number of check-in log rows recorded.
     * @param bool $ack Whether the teacher ticked the recompute confirmation.
     * @return array {
     *     saved: bool, errors: array, pending: array, changed: array,
     *     sessions: int, judged: int
     * }
     */
    public static function save_batch(array $occurrences, array $rows, array $checkins, bool $ack): array {
        $result = [
            'saved' => false,
            'errors' => [],
            'pending' => [],
            'changed' => [],
            'sessions' => 0,
            'judged' => 0,
        ];

        $prepared = [];
        foreach ($rows as $id => $row) {
            $id = (int) $id;
            // Rows are addressed by id, and an id that is not one of this
            // activity's occurrences is ignored rather than honoured: the form
            // is a list of ids a browser can edit, not a claim about the
            // database.
            if (!isset($occurrences[$id])) {
                continue;
            }

            $posted = [
                'name' => (string) ($row['name'] ?? ''),
                'timestart' => self::parse_wallclock((string) ($row['date'] ?? ''), (string) ($row['clock'] ?? '')),
                'timeend' => self::parse_wallclock((string) ($row['enddate'] ?? ''), (string) ($row['endclock'] ?? '')),
            ];

            // Same rule as the note below: only when the caller actually drew
            // the switch. A caller that does not offer it must not have its
            // rows read as "photographs are no longer wanted here".
            if (array_key_exists('photorequired', $row)) {
                $posted['photorequired'] = $row['photorequired'];
            }

            // Only when the row carried one. See the note on the parameter
            // above: a caller that no longer draws a note field must not have
            // its rows read as notes that were cleared.
            $note = null;
            if (array_key_exists('description', $row)) {
                $note = description_editor::row_value($row['description']);
                $posted['description'] = $note['text'];
                if ($note['format'] !== null) {
                    $posted['descriptionformat'] = $note['format'];
                }
            }

            $values = self::values($posted);

            $errors = self::validate($values);
            if ($errors) {
                $result['errors'][$id] = $errors;
                continue;
            }

            $changed = self::changes($occurrences[$id], $values);
            if ($changed) {
                $result['changed'][$id] = $changed;
                if (self::confirm_needed($changed, (int) ($checkins[$id] ?? 0))) {
                    $result['pending'][$id] = $changed;
                }
            }

            // Kept even when nothing changed: a row whose text is untouched can
            // still have had a file dragged into it, and the draft is the only
            // thing that says so. The write loop below decides what is worth
            // storing, so an unchanged row costs a file-area compare and no
            // column write.
            $prepared[$id] = ['occurrence' => $occurrences[$id], 'values' => $values, 'note' => $note];
        }

        if ($result['errors'] || ($result['pending'] && !$ack)) {
            return $result;
        }

        foreach ($prepared as $id => $item) {
            $values = $item['values'];
            if ($item['note'] !== null && $item['note']['rich']) {
                $values['description'] = description_editor::commit_row($id, $item['note']);
            }

            $saved = self::save($item['occurrence'], $values);
            if (!$saved['changed']) {
                unset($result['changed'][$id]);
                continue;
            }

            $result['sessions']++;
            if (self::needs_recalc($saved['changed'])) {
                self::recalc($saved['occurrence']);
                $result['judged'] += self::result_count($id);
            }
        }

        $result['saved'] = true;

        return $result;
    }
}
