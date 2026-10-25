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
 * The name of a check-in point, in the reader's own language.
 *
 * A point's name lives in the database, and the points a session starts life
 * with - sign-in, the mid-class rounds, sign-out - are named by the plugin
 * itself. That name is written in whatever language the site happened to be
 * using at the time, so a point created on a Chinese site carries 签到 in its
 * row and keeps showing 签到 after the reader switches the interface to
 * English. Nothing was ever going to translate it: it is a piece of text that
 * was stored, not a language string.
 *
 * This class closes that gap at the last moment. A name the plugin wrote is
 * shown in the reader's language; a name the teacher typed is shown exactly as
 * typed. Both the old rows written by earlier versions and the rows written
 * today go through the same path, so no data migration is involved and a point
 * named in Chinese becomes Sign-in on an English page.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class point_name {

    /**
     * The stored names this plugin writes by itself, each mapped to the string
     * it was built from.
     *
     * The list is deliberately spelled out rather than read back out of the
     * language packs at run time: comparing a stored name in one language
     * against the packs of every other installed language is a lot of loading
     * for a page that only wants to print one word, and the plugin ships
     * exactly two packs (en and zh_cn), so these sixteen short strings cover
     * every default name that can be in the database. A guard assertion keeps
     * the pairs in step with the packs, so an edit to either side that forgets
     * the other is caught rather than quietly going stale.
     *
     * @var array<string, string[]>
     */
    private const DEFAULT_NAMES = [
        'point_signin' => ['sign-in', '签到'],
        'point_signout' => ['sign-out', '签退'],
        'point_mid' => ['mid-class check', '课中抽查'],
        'point_new' => ['check-in point', '签到点'],
    ];

    /**
     * The name to show for a point.
     *
     * @param \stdClass $point A row of {multicheckin_point}, or any object carrying
     *                         the name and role properties.
     * @return string The name, unescaped and not yet passed through format_string().
     */
    public static function display(\stdClass $point): string {
        return self::display_name((string) ($point->name ?? ''), (string) ($point->role ?? ''));
    }

    /**
     * The name to show, given the stored parts on their own.
     *
     * This is for the callers that read the name through a join and never hold
     * a full point row - the audit log and the privacy export - so the role has
     * to come along as well when the row carries no name.
     *
     * @param string $name The stored name, possibly blank.
     * @param string $role 'first', 'mid' or 'last'. Only consulted when the name is blank.
     * @return string The name, unescaped and not yet passed through format_string().
     */
    public static function display_name(string $name, string $role = ''): string {
        $raw = trim($name);

        // A blank name is a point that was never named: say what it is by role.
        if ($raw === '') {
            return self::by_role($role);
        }

        // The plugin numbers repeated mid rounds ("Mid-class check 2"). Keep the
        // number the stored name carries, translate only the words.
        $base = $raw;
        $number = '';
        if (preg_match('/^(.*?)\s+(\d+)$/u', $raw, $matches)) {
            $base = trim($matches[1]);
            $number = $matches[2];
        }

        $key = self::default_key($base);
        if ($key === null) {
            // A name the teacher typed. It is theirs; leave it exactly as it is.
            return $raw;
        }

        if ($key === 'point_new') {
            // This is the one default name that carries its number in {$a}.
            return $number === ''
                ? get_string('pointname', 'mod_multicheckin')
                : get_string($key, 'mod_multicheckin', (int) $number);
        }

        return get_string($key, 'mod_multicheckin') . ($number === '' ? '' : ' ' . (int) $number);
    }

    /**
     * Whether a stored name is one the plugin wrote rather than the teacher.
     *
     * A numbered variant counts as the plugin's ("Mid-class check 2"), so the
     * trailing number is stripped before the comparison.
     *
     * @param string $name The stored name.
     * @return bool
     */
    public static function is_default(string $name): bool {
        $raw = trim($name);
        if ($raw === '') {
            return false;
        }

        $base = $raw;
        if (preg_match('/^(.*?)\s+(\d+)$/u', $raw, $matches)) {
            $base = trim($matches[1]);
        }

        return self::default_key($base) !== null;
    }

    /**
     * The string a stored default name was built from, or null for a teacher's name.
     *
     * @param string $name A name with no trailing number.
     * @return string|null The language key, or null.
     */
    private static function default_key(string $name): ?string {
        $needle = \core_text::strtolower(trim($name));
        if ($needle === '') {
            return null;
        }

        foreach (self::DEFAULT_NAMES as $key => $words) {
            foreach ($words as $word) {
                if ($needle === \core_text::strtolower($word)) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * The default name for a point of a given role.
     *
     * @param string $role 'first', 'mid' or 'last'.
     * @return string
     */
    private static function by_role(string $role): string {
        if ($role === 'last') {
            return get_string('point_signout', 'mod_multicheckin');
        }
        if ($role === 'mid') {
            return get_string('point_mid', 'mod_multicheckin');
        }

        return get_string('point_signin', 'mod_multicheckin');
    }
}
