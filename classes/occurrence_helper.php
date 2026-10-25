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
 * Shared rendering for attendance occurrences.
 *
 * The view, manage and report pages all have to answer the same three
 * questions - which occurrence, what is its state, and let me switch - so the
 * markup lives here once rather than being written three times and drifting.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class occurrence_helper {

    /** @var string State: the occurrence is running now. */
    public const STATE_CURRENT = 'current';
    /** @var string State: the occurrence has finished. */
    public const STATE_PAST = 'past';
    /** @var string State: the occurrence is still to come. */
    public const STATE_FUTURE = 'future';

    /**
     * Where an occurrence sits in time.
     *
     * The window is widened by an hour either side so a session that has just
     * ended still reads as current; a student checking their status five
     * minutes after class should not be told the session is in the past.
     *
     * @param \stdClass $session Occurrence record.
     * @param int|null $now Current time.
     * @return string One of the STATE_ constants.
     */
    public static function state(\stdClass $session, ?int $now = null): string {
        $now = $now ?? time();
        if ($now < (int) $session->timestart - 3600) {
            return self::STATE_FUTURE;
        }
        if ($now > (int) $session->timeend + 3600) {
            return self::STATE_PAST;
        }
        return self::STATE_CURRENT;
    }

    /**
     * Localised label for a state.
     *
     * @param string $state One of the STATE_ constants.
     * @return string
     */
    public static function state_label(string $state): string {
        switch ($state) {
            case self::STATE_CURRENT:
                return get_string('occurrence_current', 'mod_multicheckin');
            case self::STATE_FUTURE:
                return get_string('occurrence_future', 'mod_multicheckin');
            default:
                return get_string('occurrence_past', 'mod_multicheckin');
        }
    }

    /**
     * Bootstrap badge class for a state.
     *
     * @param string $state One of the STATE_ constants.
     * @return string
     */
    public static function state_class(string $state): string {
        switch ($state) {
            case self::STATE_CURRENT:
                return 'badge bg-success';
            case self::STATE_FUTURE:
                return 'badge bg-secondary';
            default:
                return 'badge bg-light text-dark border';
        }
    }

    /**
     * Label for one occurrence in a list: date, time, and which one it is.
     *
     * @param \stdClass $session Occurrence record.
     * @return string Plain text.
     */
    public static function label(\stdClass $session): string {
        $when = userdate($session->timestart, get_string('strftimedateshort', 'langconfig'))
            . ' ' . userdate($session->timestart, get_string('strftimetime', 'langconfig'))
            . ' – ' . userdate($session->timeend, get_string('strftimetime', 'langconfig'));

        // An occurrence carries its ordinal; the template has none, and showing
        // "Occurrence 1" for a single-session activity would be misleading.
        if (session_manager::is_occurrence($session)) {
            return $when . '  (' . get_string('occurrence_n', 'mod_multicheckin', $session->seqno) . ')';
        }
        return $when;
    }

    /**
     * Render the occurrence dropdown.
     *
     * A GET form with an onchange submit, so the picker works without the AMD
     * layer and a chosen occurrence is a real URL that can be linked and
     * bookmarked - which matters because teachers paste these links into the
     * course page.
     *
     * @param int $cmid Course module id.
     * @param array $sessions Occurrences keyed by id.
     * @param int $selectedid Currently selected occurrence.
     * @param string $target Page to submit to, relative to the plugin.
     * @param array $extra Extra hidden fields to carry through the switch.
     * @return string HTML.
     */
    public static function render_picker(int $cmid, array $sessions, int $selectedid, string $target,
            array $extra = []): string {
        if (count($sessions) < 2) {
            return '';
        }

        $options = [];
        foreach ($sessions as $session) {
            $options[(string) $session->id] = self::label($session);
        }

        $select = \html_writer::select($options, 'session', (string) $selectedid, false, [
            // `.form-select` is the Bootstrap 5 name; `.custom-select` was removed
            // in 5.0 and left the occurrence switcher as a browser-default control.
            'class' => 'form-select',
            'onchange' => 'this.form.submit()',
        ]);

        $form = \html_writer::start_tag('form', [
            'method' => 'get',
            'action' => new \moodle_url('/mod/multicheckin/' . $target),
            'class' => 'd-flex align-items-center gap-2 mb-3',
        ]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cmid]);
        // Pages that narrow the view to one student (or one page of the class
        // sheet) would otherwise lose the context the moment the occurrence is
        // switched, dropping the teacher back to the whole roster.
        foreach ($extra as $name => $value) {
            $form .= \html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => $name,
                'value' => $value,
            ]);
        }
        $form .= \html_writer::tag('label', get_string('occurrence_select', 'mod_multicheckin'),
            ['for' => 'multicheckin-occurrence', 'class' => 'mb-0 text-nowrap']);
        $form .= $select;
        $form .= \html_writer::end_tag('form');

        return $form;
    }

    /**
     * A one-line note about where the shown occurrence sits in time.
     *
     * @param \stdClass $session Occurrence record.
     * @return string HTML.
     */
    public static function render_current_note(\stdClass $session): string {
        $state = self::state($session);
        $badge = \html_writer::span(self::state_label($state), self::state_class($state));

        return \html_writer::div(
            \html_writer::span(get_string('occurrencetime', 'mod_multicheckin') . '：')
            . userdate($session->timestart) . ' – ' . userdate($session->timeend)
            . ' &nbsp; ' . $badge,
            'mb-3');
    }

    /**
     * The occurrence's own note, if it has one.
     *
     * The note is stored raw and has two readings, chosen per row: a row
     * written before the field became a rich editor is FORMAT_PLAIN and is
     * escaped here, a row written since is markup and goes through
     * format_text(). Both readings live in description_editor::render(), which
     * is the single place that decides, so this cannot drift from the two
     * other pages that show the same field.
     *
     * The context is not decoration: a rich note may embed an image, and
     * resolving that image's @@PLUGINFILE@@ is a context-and-filearea lookup.
     *
     * @param \stdClass $session Occurrence record.
     * @param \context $context Module context.
     * @return string HTML, empty when there is no description.
     */
    public static function render_description(\stdClass $session, \context $context): string {
        $description = trim((string) ($session->description ?? ''));
        if ($description === '') {
            return '';
        }

        return \html_writer::div(
            \html_writer::tag('strong', get_string('session_description', 'mod_multicheckin')),
            'text-muted small mb-1')
            . \html_writer::div(
                description_editor::render($description,
                    description_editor::format_of($session), (int) $session->id, $context),
                'multicheckin-session-description mb-3');
    }

    /**
     * One text input for the batch form.
     *
     * @param string $name Field name, already carrying the row's id.
     * @param string $value Current value.
     * @param array $extra Extra attributes.
     * @param string $error Language key of the error to show, or ''.
     * @return string HTML.
     */
    private static function input(string $name, string $value, array $extra = [], string $error = ''): string {
        $attributes = array_merge([
            'type' => 'text',
            'name' => $name,
            'value' => $value,
            'class' => 'form-control form-control-sm' . ($error !== '' ? ' is-invalid' : ''),
        ], $extra);

        $html = \html_writer::empty_tag('input', $attributes);
        if ($error !== '') {
            $html .= \html_writer::div(get_string($error, 'mod_multicheckin'), 'invalid-feedback d-block');
        }

        return $html;
    }

    /**
     * Date and time inputs for one end of an occurrence.
     *
     * Two plain boxes rather than a date picker: the same pair the irregular
     * series form already asks for, parsed by the same function, so a date the
     * teacher types in one place means the same instant in the other. A picker
     * per row would also add five controls per cell to a table that may hold
     * thirty rows.
     *
     * @param int $id Occurrence id.
     * @param string $prefix 'date'/'clock' or 'enddate'/'endclock'.
     * @param string $date Value for the date box.
     * @param string $clock Value for the time box.
     * @param string $error Language key of the error to show, or ''.
     * @return string HTML.
     */
    private static function time_inputs(int $id, string $prefix, string $date, string $clock,
            string $error = ''): string {
        $dateprefix = $prefix === 'date' ? 'date' : 'enddate';
        $clockprefix = $prefix === 'date' ? 'clock' : 'endclock';

        return \html_writer::div(
            self::input($dateprefix . '[' . $id . ']', $date, [
                'size' => 10,
                'placeholder' => 'YYYY-MM-DD',
                'class' => 'form-control form-control-sm multicheckin-when' . ($error !== '' ? ' is-invalid' : ''),
            ], $error)
            . self::input($clockprefix . '[' . $id . ']', $clock, [
                'size' => 6,
                'placeholder' => 'HH:MM',
                'class' => 'form-control form-control-sm multicheckin-when' . ($error !== '' ? ' is-invalid' : ''),
            ]),
            'd-flex gap-1');
    }

    /**
     * The full occurrence table used on the manage page.
     *
     * The inputs in it belong to a form the caller opens around this table: a
     * table row is not a form element, and wrapping each row in its own form
     * would mean one submit per occurrence, which is the thing the batch editor
     * exists to avoid.
     *
     * The note column does not hold an editor. It shows a one-line preview and
     * the button that opens the note editor (session_note.php), which is what
     * keeps a term's overview from being a page of live editors: one editor,
     * opened when a teacher asks for it, costs one editor.
     *
     * The photo column is a plain tick box. It is the one field in this table
     * that changes the SHAPE of a check-in rather than its label, so it is
     * edited here and on the single-occurrence page, and nowhere else.
     *
     * @param int $cmid Course module id.
     * @param array $sessions Occurrences keyed by id.
     * @param array $stats Extra per-session figures: id => ['points' => int, 'checkins' => int].
     * @param int $selectedid Currently selected occurrence.
     * @param array $posted Submitted values to redisplay, id => row array. Used
     *                      when a save was refused so the teacher keeps their typing.
     * @param array $errors Field errors to mark up, id => [field => language key].
     * @return string HTML.
     */
    public static function render_table(int $cmid, array $sessions, array $stats, int $selectedid,
            array $posted = [], array $errors = []): string {
        $table = new \html_table();
        $table->head = [
            get_string('seqno', 'mod_multicheckin'),
            get_string('session_title', 'mod_multicheckin'),
            get_string('session_start', 'mod_multicheckin'),
            get_string('session_end', 'mod_multicheckin'),
            get_string('occurrencestatus', 'mod_multicheckin'),
            get_string('session_description', 'mod_multicheckin'),
            get_string('session_photorequired', 'mod_multicheckin'),
            get_string('occurrencepoints', 'mod_multicheckin'),
            get_string('occurrencecheckins', 'mod_multicheckin'),
            get_string('action', 'mod_multicheckin'),
        ];
        $table->attributes['class'] = 'generaltable table-sm align-middle';

        $now = time();
        foreach ($sessions as $session) {
            $id = (int) $session->id;
            $state = self::state($session, $now);
            $iscurrent = $id === $selectedid;

            $row = $posted[$id] ?? [];
            $rowerrors = $errors[$id] ?? [];

            $startwall = occurrence_editor::format_wallclock((int) $session->timestart);
            $endwall = occurrence_editor::format_wallclock((int) $session->timeend);

            $titleval = (string) ($row['name'] ?? $session->name);
            $startdate = (string) ($row['date'] ?? $startwall['date']);
            $startclock = (string) ($row['clock'] ?? $startwall['time']);
            $enddate = (string) ($row['enddate'] ?? $endwall['date']);
            $endclock = (string) ($row['endclock'] ?? $endwall['time']);

            $titlecell = self::input('name[' . $id . ']', $titleval, [
                'size' => 24,
                'maxlength' => 255,
            ], (string) ($rowerrors['name'] ?? ''));

            $startcell = self::time_inputs($id, 'date', $startdate, $startclock,
                (string) ($rowerrors['timestart'] ?? ''));

            $endcell = self::time_inputs($id, 'enddate', $enddate, $endclock,
                (string) ($rowerrors['timeend'] ?? ''));

            $desccell = description_editor::note_cell($cmid, $session, 'series');

            // The photo demand, as a tick box rather than a two-option select:
            // the column is off for almost every row, and a row that is merely
            // scrolled past must not read as a change. A submitted row only
            // carries the key when the box was ticked - which is why the caller
            // turns a missing key into an explicit 0 for every row it drew.
            $photoon = array_key_exists('photorequired', $row)
                ? !empty($row['photorequired'])
                : !empty($session->photorequired);
            $photoattrs = [
                'type' => 'checkbox',
                'name' => 'photorequired[' . $id . ']',
                'id' => 'multicheckin-photorequired-' . $id,
                'value' => 1,
                'class' => 'form-check-input',
            ];
            if ($photoon) {
                $photoattrs['checked'] = 'checked';
            }
            $photocell = \html_writer::tag('label',
                \html_writer::empty_tag('input', $photoattrs)
                . \html_writer::span(get_string('session_photorequired', 'mod_multicheckin'), 'sr-only'),
                ['for' => 'multicheckin-photorequired-' . $id, 'class' => 'form-check-label']);

            $viewurl = new \moodle_url('/mod/multicheckin/view.php',
                ['id' => $cmid, 'session' => $id]);
            $editurl = new \moodle_url('/mod/multicheckin/session_edit.php',
                ['id' => $cmid, 'session' => $id, 'return' => 'series']);

            // The occurrence being managed is marked, so the table doubles as
            // the answer to "which one am I editing?".
            $seqcell = $iscurrent ? \html_writer::tag('strong', $session->seqno) : $session->seqno;
            $namespan = \html_writer::span(userdate($session->timestart,
                get_string('strftimedateshort', 'langconfig')));
            $titlecell .= \html_writer::div($namespan, 'text-muted small mt-1');

            $actions = \html_writer::link($editurl, get_string('edit'),
                ['class' => 'me-2 fw-bold']);
            $actions .= \html_writer::link($viewurl, get_string('view'), ['class' => 'me-2']);
            if ($id !== $selectedid) {
                $manageurl = new \moodle_url('/mod/multicheckin/manage.php',
                    ['id' => $cmid, 'session' => $id]);
                $actions .= \html_writer::link($manageurl,
                    get_string('managepoints', 'mod_multicheckin'), ['class' => 'me-2']);
            }

            $deleurl = new \moodle_url('/mod/multicheckin/series.php', [
                'id' => $cmid,
                'action' => 'deleteone',
                'session' => $id,
                'sesskey' => sesskey(),
            ]);
            $actions .= \html_writer::link($deleurl, get_string('delete'),
                ['class' => 'text-danger', 'data-multicheckin-confirm' =>
                    get_string('series_deleteone_confirm', 'mod_multicheckin')]);

            $table->data[] = [
                $seqcell,
                $titlecell,
                $startcell,
                $endcell,
                \html_writer::span(self::state_label($state), self::state_class($state)),
                $desccell,
                $photocell,
                $stats[$id]['points'] ?? 0,
                $stats[$id]['checkins'] ?? 0,
                $actions,
            ];
        }

        return \html_writer::div(\html_writer::table($table), 'table-responsive');
    }
}
