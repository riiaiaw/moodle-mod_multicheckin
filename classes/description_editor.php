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
 * The occurrence description: one field, two formats, and the files it can hold.
 *
 * A description is written in an editor, so it may carry images, and those are
 * files: component mod_multicheckin, area sessiondescription, itemid = the
 * occurrence id. Keeping them against the occurrence is what makes a backup
 * carry them, deleting the activity remove them, and pluginfile able to serve
 * them to anyone who may see the activity at all - which is the whole audience
 * for a description.
 *
 * Every description written before v1.11.0 was typed into a plain textarea, and
 * its row is marked FORMAT_PLAIN so it still renders as escaped text with its
 * newlines turned into line breaks. Anything written since is FORMAT_HTML and
 * goes through format_text(). One column, two readings, decided per row rather
 * than per version - which is why the choice is made in render() and nowhere
 * else.
 *
 * Since v2.0.0 the editor is not on the page that owns the occurrence. The
 * batch table used to grow a live editor per row up to a row-count threshold
 * and fall back to a textarea past it, which meant the field had two different
 * shapes to read back and a rule that had to be announced to the teacher. Both
 * pages now show a one-line preview and a button; the editor itself lives in
 * session_note.php, which since v2.0.3 is normally loaded inside a dialog on
 * the page rather than in a window of its own. So this class keeps the
 * rendering, the file area and the two formats, and the batch machinery has
 * gone.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class description_editor {

    /** @var string Component the description files belong to. */
    public const COMPONENT = 'mod_multicheckin';

    /** @var string File area holding images embedded in a description. */
    public const FILEAREA = 'sessiondescription';

    /** @var int Characters of a description the preview shows before cutting it short. */
    public const SUMMARY_LENGTH = 60;

    /** @var string Form field the editor posts its text, format and draft under. */
    public const FIELD = 'description_editor';

    /**
     * Editor options, shared by the window's form and nothing else.
     *
     * maxfiles is -1 (unlimited) rather than 0 because attachments are the point
     * of the field being an editor at all; noclean is on because the value is
     * stored exactly as typed and cleaned by format_text() on the way out, which
     * is the reading every other editor-backed field in Moodle uses. Cleaning on
     * the way in would also mangle the markup the editor itself produced.
     *
     * maxbytes is left at 0, which the element treats as "not supplied" and
     * therefore as the site's own limit - the same reading every core form
     * takes when it passes the course's maxbytes straight through.
     *
     * @param \context $context Module context the files belong to.
     * @return array
     */
    public static function editor_options(\context $context): array {
        return [
            'context' => $context,
            'maxfiles' => -1,
            'maxbytes' => 0,
            'subdirs' => 0,
            'trusttext' => false,
            'noclean' => true,
        ];
    }

    /**
     * The format an occurrence's description is stored in.
     *
     * Defaults to plain text. That is the safe reading of a missing column: a
     * value of unknown provenance is escaped and line-broken rather than handed
     * to the HTML filter, so the worst case is a description that shows its
     * markup instead of one that loses everything between two angle brackets.
     *
     * @param \stdClass $occurrence Occurrence record.
     * @return int FORMAT_* constant.
     */
    public static function format_of(\stdClass $occurrence): int {
        return (int) ($occurrence->descriptionformat ?? FORMAT_PLAIN);
    }

    /**
     * Whether a posted field came from an editor element rather than a textarea.
     *
     * Only session_note.php posts a note now, and it posts the editor's array
     * shape. The other shape is still recognised because the reader has to be
     * able to say what it was handed rather than assume: a request that posted
     * a bare string is a request that did not come from an editor, and reading
     * it as one would lose the text.
     *
     * @param mixed $row Posted value: an editor array, a string, or nothing.
     * @return bool
     */
    public static function is_rich_row($row): bool {
        return is_array($row) && array_key_exists('text', $row);
    }

    /**
     * The DOM id of the editor element for one occurrence.
     *
     * @param int $sessionid Occurrence id.
     * @return string
     */
    public static function element_id(int $sessionid): string {
        return 'multicheckin-description-' . $sessionid;
    }

    /**
     * Move a draft area into place and return the text to store.
     *
     * @param int $draftitemid Draft itemid the editor posted.
     * @param string $text Text the editor posted.
     * @param int $sessionid Occurrence id, which is the file itemid.
     * @param \context $context Module context.
     * @return string Text with every @@PLUGINFILE@@ rewritten to the stored file.
     */
    public static function save_draft(int $draftitemid, string $text, int $sessionid,
            \context $context): string {
        $options = self::editor_options($context);

        return file_save_draft_area_files($draftitemid, $context->id, self::COMPONENT, self::FILEAREA,
            $sessionid, $options, $text);
    }

    /**
     * How many characters of a description a reader actually sees.
     *
     * Counted with the tags taken off, so that wrapping a sentence in a
     * paragraph does not read as three characters longer than the same sentence
     * typed without one. For plain text strip_tags() is a no-op, which is what
     * keeps the limit meaning the same thing on both sides of the upgrade.
     *
     * @param string $text Stored text.
     * @param int $format FORMAT_* constant.
     * @return int
     */
    public static function visible_length(string $text, int $format): int {
        // (int) on both sides: core defines the FORMAT_ constants as STRINGS
        // ('0'..'4'), so `2 !== FORMAT_PLAIN` is true and a strict comparison
        // against an int silently takes the wrong branch.
        if ($format !== (int) FORMAT_PLAIN) {
            $text = trim(strip_tags($text));
        }

        return \core_text::strlen($text);
    }

    /**
     * A description as HTML, in whichever format its row is stored in.
     *
     * @param string $text Stored text.
     * @param int $format FORMAT_* constant.
     * @param int $sessionid Occurrence id, used to resolve @@PLUGINFILE@@.
     * @param \context $context Module context.
     * @return string HTML.
     */
    public static function render(string $text, int $format, int $sessionid, \context $context): string {
        // The FORMAT_ constants are strings in core, so the cast is load-bearing
        // rather than cosmetic: without it this branch is never taken and a
        // plain-text note is handed to the filter that it was deliberately kept
        // away from.
        if ($format === (int) FORMAT_PLAIN) {
            // The pre-v1.11.0 reading, kept byte for byte: the stored value is
            // raw, so this is the single escaping point, and the newlines a
            // teacher typed in the old textarea survive as line breaks.
            return nl2br(s($text));
        }

        // Every other format Moodle has - HTML, "Moodle auto-format", Markdown -
        // is a filter reading, and format_text() is what reads it. A crafted or
        // stale value cannot escape this branch: it is a format_text() call, not
        // an echo.
        $text = file_rewrite_pluginfile_urls($text, 'pluginfile.php', $context->id,
            self::COMPONENT, self::FILEAREA, $sessionid);

        return format_text($text, $format, [
            'context' => $context,
            'para' => false,
            'overflowdiv' => false,
        ]);
    }

    /**
     * A one-line preview of a description, for the pages that only link to it.
     *
     * The tags come off first - with the same format rule render() uses, so a
     * plain-text note that happens to contain an angle bracket keeps it - and
     * the result is collapsed onto one line and cut to SUMMARY_LENGTH. This is
     * not an escaping step: the caller escapes what comes back, which is why
     * nothing here goes through s() or format_text(). Escaping here as well
     * would show the teacher an entity instead of the character they typed.
     *
     * @param string $text Stored text.
     * @param int $format FORMAT_* constant.
     * @return string Plain single-line text, '' when there is nothing to show.
     */
    public static function summary(string $text, int $format): string {
        $plain = $format === (int) FORMAT_PLAIN ? $text : strip_tags($text);
        $plain = trim((string) preg_replace('/\s+/u', ' ', $plain));
        if ($plain === '') {
            return '';
        }

        if (\core_text::strlen($plain) <= self::SUMMARY_LENGTH) {
            return $plain;
        }

        return \core_text::substr($plain, 0, self::SUMMARY_LENGTH) . '…';
    }

    /**
     * The button that opens the note editor for one occurrence.
     *
     * It carries a data attribute rather than a handler, and keeps its href and
     * target, so it works either way: with scripting the click is intercepted
     * and the form opens in a dialog on the page the teacher is already on;
     * without scripting the same URL opens in a window of its own, which is how
     * this behaved before v2.0.3. A control that only existed once a script had
     * run would be a control that sometimes does not exist.
     *
     * The dialog holds an iframe rather than an editor moved into the modal. A
     * note is written in Moodle's own editor element, and that element is built
     * by the page that renders it: moving it into another container afterwards
     * leaves a dead textarea. Loading the same page inside a frame means the
     * editor is initialised exactly as it always was, by the page that owns it,
     * and the save is the same save.
     *
     * @param int $cmid Course module id.
     * @param int $sessionid Occurrence id.
     * @param string $return Where the form should offer to go back to.
     * @param bool $hasnote Whether there is already a note, which decides the label.
     * @return string HTML.
     */
    public static function open_button(int $cmid, int $sessionid, string $return, bool $hasnote): string {
        $url = new \moodle_url('/mod/multicheckin/session_note.php', [
            'id' => $cmid,
            'session' => $sessionid,
            'return' => $return,
        ]);

        return \html_writer::link($url,
            get_string($hasnote ? 'session_description_edit' : 'session_description_add', 'mod_multicheckin'),
            [
                'class' => 'btn btn-outline-secondary btn-sm multicheckin-noteopen',
                'target' => '_blank',
                'rel' => 'noopener',
                // Read as a selector by mod_multicheckin/notemodal. Its value is
                // the occurrence, which is what the module needs to find the
                // right cell to redraw once the form reports a save.
                'data-multicheckin-note' => (string) $sessionid,
            ]);
    }

    /**
     * Load the module that turns every note button on the page into a dialog.
     *
     * Called once per page rather than from open_button(): a page draws one
     * button per occurrence, and registering the same module thirty times would
     * be thirty identical registrations for one behaviour. A page that renders
     * no button never calls this and never loads the module.
     *
     * The strings the module needs to redraw a cell after a save are passed in
     * rather than baked into the JavaScript, which keeps the module free of
     * translation.
     *
     * @return void
     */
    public static function require_js(): void {
        global $PAGE;

        $PAGE->requires->js_call_amd('mod_multicheckin/notemodal', 'init', [[
            'title' => get_string('session_note', 'mod_multicheckin'),
            'none' => get_string('session_description_none', 'mod_multicheckin'),
            'add' => get_string('session_description_add', 'mod_multicheckin'),
            'edit' => get_string('session_description_edit', 'mod_multicheckin'),
        ]]);
    }

    /**
     * The script a saved form runs: report the save, and close the right way.
     *
     * Two readers, one page. Inside the dialog the page behind it is told what
     * was saved, so it redraws the preview and the button without a reload - a
     * reload there would discard whatever the teacher had already typed into
     * the form beside the note, which is the one field they were not editing.
     * In a window of its own there is nothing to redraw, and the close button
     * goes back to reloading the opener, which is how this worked before
     * v2.0.3.
     *
     * With scripting off neither branch runs and the close button is an
     * ordinary link to the page the form was opened from, so it still ends up
     * somewhere sensible.
     *
     * @param \stdClass $occurrence Occurrence record, carrying the stored note.
     * @param bool $embed Whether the page was opened inside the dialog.
     * @return string JavaScript.
     */
    public static function note_saved_js(\stdClass $occurrence, bool $embed): string {
        $stored = trim((string) ($occurrence->description ?? ''));

        // The hex flags are load-bearing rather than belt and braces: this
        // string is inlined into a <script> element, and a note containing the
        // closing tag - or an angle bracket, or an ampersand - would otherwise
        // end that element early and take the rest of the page with it.
        $payload = json_encode([
            'type' => 'multicheckin-note-saved',
            'sessionid' => (int) $occurrence->id,
            'summary' => self::summary($stored, self::format_of($occurrence)),
            'hasnote' => $stored !== '',
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $embedded = $embed ? 'true' : 'false';

        return <<<JS
(function() {
    var payload = {$payload};
    var embedded = {$embedded};
    // A frame, not merely a window: the page is its own parent when it is not
    // embedded, and messaging itself would be a no-op that looks like a bug.
    var frame = embedded && window.parent && window.parent !== window;

    if (frame) {
        window.parent.postMessage(payload, window.location.origin);
    }

    var link = document.getElementById('multicheckin-note-close');
    if (!link) {
        return;
    }
    link.addEventListener('click', function(e) {
        if (frame) {
            e.preventDefault();
            window.parent.postMessage({type: 'multicheckin-note-close'}, window.location.origin);
            return;
        }
        if (window.opener && !window.opener.closed) {
            e.preventDefault();
            window.opener.location.reload();
            window.close();
        }
    });
})();
JS;
    }

    /**
     * Make the back control close the dialog the form is filling.
     *
     * The control is a real link to the page the form was opened from, so a
     * browser without scripting still ends up somewhere sensible - and so a
     * click that this snippet does not handle opens the page rather than doing
     * nothing. Inside the dialog that same link is wrong: it navigates the
     * frame, and the dialog belongs to the page around it, so the teacher is
     * left with the whole series table loaded inside a box that was sized for
     * a three-field form. Nothing closes it, because only the owner can.
     *
     * @param bool $embed Whether the page is filling a dialog.
     * @return string JavaScript.
     */
    public static function note_close_js(bool $embed): string {
        $embedded = $embed ? 'true' : 'false';

        return <<<JS
(function() {
    var embedded = {$embedded};
    var frame = embedded && window.parent && window.parent !== window;
    var back = document.getElementById('multicheckin-note-back');
    if (!back) {
        return;
    }
    back.addEventListener('click', function(e) {
        if (frame) {
            e.preventDefault();
            window.parent.postMessage({type: 'multicheckin-note-close'}, window.location.origin);
            return;
        }
        if (window.opener && !window.opener.closed) {
            e.preventDefault();
            window.close();
        }
    });
})();
JS;
    }

    /**
     * The note column of a table row, and of the single-occurrence page.
     *
     * One function for both, because the two places must not drift: a teacher
     * who sees a preview in the table and something else on the page would have
     * to work out which of the two was lying. The preview is escaped here and
     * only here.
     *
     * @param int $cmid Course module id.
     * @param \stdClass $occurrence Occurrence record.
     * @param string $return Where the window should offer to go back to.
     * @return string HTML.
     */
    public static function note_cell(int $cmid, \stdClass $occurrence, string $return): string {
        $stored = trim((string) ($occurrence->description ?? ''));
        $preview = self::summary($stored, self::format_of($occurrence));

        $body = $preview === ''
            ? \html_writer::span(get_string('session_description_none', 'mod_multicheckin'), 'text-muted')
            : s($preview);

        return \html_writer::div(
            \html_writer::div($body, 'mb-1 multicheckin-note-summary') .
            self::open_button($cmid, (int) $occurrence->id, $return, $stored !== ''),
            'multicheckin-note-cell');
    }

    /**
     * The value an editor form element expects for one occurrence.
     *
     * Both halves of core's return matter. file_prepare_draft_area() fills in a
     * fresh draftitemid by reference when handed 0, and returns the text with
     * its @@PLUGINFILE@@ references rewritten to draft URLs. Handing the
     * original text to an editor instead would leave every stored image
     * pointing at @@PLUGINFILE@@, which nothing outside a stored file area
     * knows how to read - the teacher would see broken images and, on save,
     * would store the marker back as literal text.
     *
     * The format handed over is HTML rather than the row's own, and that is
     * deliberate: an editor element posts back the format it was given, so
     * handing a rich editor FORMAT_PLAIN would have it save rich text as plain
     * and show the teacher their own formatting as literal tags. The consequence
     * is stated rather than hidden - a plain-text row that is opened here becomes
     * HTML, which is exactly what opening it in a rich editor means. Nothing is
     * rewritten until the form is saved.
     *
     * @param \stdClass $occurrence Occurrence record, carrying description.
     * @param \context $context Module context.
     * @param int $draftitemid Draft itemid the request carried, 0 to make a new one.
     * @return array ['text' => string, 'format' => int, 'itemid' => int].
     */
    public static function editor_value(\stdClass $occurrence, \context $context,
            int $draftitemid = 0): array {
        $options = self::editor_options($context);
        if ($draftitemid === 0) {
            $draftitemid = file_get_submitted_draft_itemid(self::FIELD);
        }

        $text = file_prepare_draft_area($draftitemid, $context->id, self::COMPONENT, self::FILEAREA,
            (int) $occurrence->id, $options, (string) ($occurrence->description ?? ''));

        return [
            'text' => (string) $text,
            'format' => FORMAT_HTML,
            'itemid' => (int) $draftitemid,
        ];
    }

    /**
     * The format to store for a format a form posted.
     *
     * Only two readings exist on the way out - plain, and "through the text
     * filter" - so only two are kept on the way in. Plain is honoured because a
     * user whose preferred editor is the plain textarea can still ask for it;
     * anything this site does not offer, or that a hand-made request invented,
     * is stored as HTML, which is what this editor produces.
     *
     * @param int $format Posted format.
     * @return int FORMAT_* constant.
     */
    public static function accepted_format(int $format): int {
        // The same (int) caveat as everywhere else: FORMAT_PLAIN is the string
        // '2' in core, so this must compare numbers.
        if ($format === (int) FORMAT_PLAIN || array_key_exists($format, format_text_menu())) {
            return $format;
        }

        return (int) FORMAT_HTML;
    }

    /**
     * What a posted note field carries, reduced to the four things a save needs.
     *
     * The draft the editor posted is turned back into @@PLUGINFILE@@ WITHOUT
     * writing the file area: the save is validated and compared before anything
     * is stored, so that a form refused for some other reason cannot have
     * already replaced the images this note had.
     *
     * @param mixed $row Posted value: an editor array, a string, or nothing.
     * @return array ['text' => string, 'format' => int|null, 'itemid' => int, 'rich' => bool]
     */
    public static function row_value($row): array {
        if (!self::is_rich_row($row)) {
            return [
                'text' => (string) $row,
                // A bare string says nothing about the format, so the row keeps
                // whatever it already has.
                'format' => null,
                'itemid' => 0,
                'rich' => false,
            ];
        }

        $itemid = (int) ($row['itemid'] ?? 0);
        $text = (string) ($row['text'] ?? '');
        if ($itemid) {
            $text = file_rewrite_urls_to_pluginfile($text, $itemid);
        }

        return [
            'text' => $text,
            'format' => self::accepted_format((int) ($row['format'] ?? FORMAT_HTML)),
            'itemid' => $itemid,
            'rich' => true,
        ];
    }

    /**
     * Move a note's draft into place and return the text to store.
     *
     * The counterpart to row_value(). row_value() reads a posted note without
     * touching the file table, because validation happens before anything is
     * written; this is the write, called once the save is known to be going
     * ahead. Splitting them is what keeps a note refused for being too long
     * from having already replaced the images the stored note was showing.
     *
     * @param int $sessionid Occurrence id, which is the file itemid.
     * @param array $raw A row as row_value() returned it.
     * @return string The text to store.
     */
    public static function commit_row(int $sessionid, array $raw): string {
        $text = (string) ($raw['text'] ?? '');
        if (empty($raw['rich']) || empty($raw['itemid'])) {
            return $text;
        }

        $context = leave_manager::safe_context($sessionid);
        if ($context === null) {
            // Unreachable from the note window, which only reaches a row it has
            // just read back, and deliberately unspectacular if it ever is: the
            // alternative is throwing half way through a save. The
            // @@PLUGINFILE@@ marker stays, so the description still reads as one
            // that once had an image rather than silently losing a paragraph.
            return $text;
        }

        return self::save_draft((int) $raw['itemid'], $text, $sessionid, $context);
    }

    /**
     * Drop every file an occurrence's description carries.
     *
     * Called from the same places that drop an occurrence's leave rows, so that
     * removing an activity, removing one occurrence or clearing a series all
     * leave the file table as clean as the data tables. The context is looked
     * up from the row rather than passed in because every one of those callers
     * is in the middle of deleting it.
     *
     * @param int $sessionid Occurrence id.
     * @return void
     */
    public static function delete_files(int $sessionid): void {
        // Resolved defensively: this runs inside course-module deletion, where
        // the module row may already be gone, and a missing context must not
        // abort the whole cleanup.
        $context = leave_manager::safe_context($sessionid);
        if ($context === null) {
            return;
        }

        get_file_storage()->delete_area_files($context->id, self::COMPONENT, self::FILEAREA, $sessionid);
    }
}
