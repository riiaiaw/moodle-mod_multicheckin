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
 * The page toolbar: an activity's options, as two groups of buttons.
 *
 * Every page used to stack its own column of single_button() forms. Each one is
 * a <form>, each form is a block, and the result was a vertical list at the
 * bottom of the activity page that ran off the screen - with every page free to
 * grow its own set and none of them agreeing on the order. The set now lives
 * here and is rendered once.
 *
 * The split is by AUDIENCE, not by page. "Things a student reaches for" and
 * "things a teacher reaches for" are the two questions a person actually has
 * when they look at the bottom of this page, and they survive a page being
 * opened by the other role: a student simply sees one group, because every
 * teacher entry was never added for them.
 *
 * Built from plain Bootstrap classes rather than a stylesheet. The toolbar has
 * to look like the rest of the theme, and shipping CSS to say "these are
 * buttons" would mean every theme caching and reloading a file to be told what
 * a button is.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class nav_toolbar {

    /** @var string What a student reaches for. */
    public const GROUP_STUDENT = 'student';

    /** @var string What a teacher reaches for. */
    public const GROUP_TEACHER = 'teacher';

    /** @var array<int, array{group: string, html: string}> Entries in the order they were added. */
    protected $items = [];

    /**
     * Add a link button.
     *
     * @param string $group One of the GROUP_ constants.
     * @param \moodle_url|string $url Where it goes.
     * @param string $label Button text, already localised and escaped by the caller.
     * @param array $options primary (bool), badge (string), title (string), newwindow (bool).
     * @return self
     */
    public function add(string $group, $url, string $label, array $options = []): self {
        $attributes = [
            // A primary button is the one action this page exists for. Exactly
            // one entry per group should carry it, or the emphasis says nothing.
            'class' => 'btn ' . (!empty($options['primary']) ? 'btn-primary' : 'btn-outline-secondary'),
        ];
        if (!empty($options['title'])) {
            $attributes['title'] = (string) $options['title'];
        }
        if (!empty($options['newwindow'])) {
            $attributes['target'] = '_blank';
            $attributes['rel'] = 'noopener';
        }

        $body = $label;
        if (!empty($options['badge'])) {
            $body .= ' ' . \html_writer::span((string) $options['badge'], 'badge bg-warning text-dark ms-1');
        }

        return $this->add_html($group, \html_writer::link($url, $body, $attributes));
    }

    /**
     * Add an entry that renders its own markup.
     *
     * For the handful of options that are not links - the QR window button is a
     * real <button> with a script behind it, because a popup has to be opened
     * by a click rather than followed to - and for anything that wants to draw
     * its own badge.
     *
     * @param string $group One of the GROUP_ constants.
     * @param string $html Button markup.
     * @return self
     */
    public function add_html(string $group, string $html): self {
        if ($html !== '') {
            $this->items[] = ['group' => $group, 'html' => $html];
        }

        return $this;
    }

    /**
     * Whether a group has anything in it.
     *
     * @param string $group One of the GROUP_ constants.
     * @return bool
     */
    public function has(string $group): bool {
        foreach ($this->items as $item) {
            if ($item['group'] === $group) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render the toolbar, or an empty string when there is nothing to show.
     *
     * An empty toolbar renders as nothing at all rather than as an empty card:
     * a student looking at an activity has no management options, and giving
     * them a titled empty box to explain their absence would be worse than the
     * space it occupies.
     *
     * @return string HTML.
     */
    public function render(): string {
        $groups = [
            self::GROUP_STUDENT => 'toolbar_students',
            self::GROUP_TEACHER => 'toolbar_teachers',
        ];

        $out = '';
        foreach ($groups as $group => $labelkey) {
            $cells = [];
            foreach ($this->items as $item) {
                if ($item['group'] === $group) {
                    $cells[] = $item['html'];
                }
            }
            if (!$cells) {
                continue;
            }

            $out .= \html_writer::div(get_string($labelkey, 'mod_multicheckin'),
                'text-uppercase text-muted small fw-bold mb-2 multicheckin-toolbar-title');
            $out .= \html_writer::div(implode('', $cells), 'd-flex flex-wrap gap-2 mb-3 multicheckin-toolbar-group');
        }

        if ($out === '') {
            return '';
        }

        return \html_writer::tag('div', $out, ['class' => 'card card-body mt-4 multicheckin-toolbar']);
    }
}
