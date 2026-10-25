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
 * The site-wide library of named classroom locations.
 *
 * Before this existed a teacher had to type the classroom coordinates into the
 * activity form by hand, for every activity. Two people typing the same lecture
 * hall from memory produce coordinates that differ by tens of metres, and a
 * student standing at the doorway then falls inside one teacher's circle and
 * outside another's. Recording each room once, under a name, removes the whole
 * class of problem: the shared list is the definition of where that room is.
 *
 * Scope is site-wide rather than per course. A classroom does not belong to a
 * course, and a per-course library would make every course re-enter the same
 * buildings.
 *
 * Coordinates in this table are ALWAYS WGS-84, which is the datum the browser
 * reports through the Geolocation API. The visitor is trusted to have supplied
 * WGS-84, because the form has no map to pick from; see {@see geo_manager} for
 * the comparison, and note that the geofence compares like with like.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class place_manager {

    /** @var string Table name. */
    public const TABLE = 'multicheckin_place';

    /** @var int Longest name accepted, matching the char(255) column. */
    public const NAME_MAX = 255;

    /** @var int Minimum radius accepted, in metres. */
    public const RADIUS_MIN = 10;

    /** @var int Maximum radius accepted, in metres. */
    public const RADIUS_MAX = 50000;

    /** @var int Radius used when a caller does not supply one. */
    public const RADIUS_DEFAULT = 100;

    /**
     * Every place, ordered by name.
     *
     * The library is expected to hold tens to low hundreds of rooms, so it is
     * read whole. The form needs the entire list to build its dropdown anyway,
     * and a teacher filtering by name is better served by doing that in the
     * browser than by a round trip per keystroke.
     *
     * @return array<int, \stdClass>
     */
    public static function get_all(): array {
        global $DB;
        $records = $DB->get_records(self::TABLE, null, 'name ASC, id ASC');
        return array_values($records);
    }

    /**
     * Every place, shaped for JavaScript.
     *
     * Numbers are cast rather than passed through as strings: a latitude that
     * arrives in the browser as "30.5928" would silently break the arithmetic
     * that fills the radius and coordinate fields.
     *
     * @return array<int, array>
     */
    public static function get_all_for_js(): array {
        $out = [];
        foreach (self::get_all() as $record) {
            $out[] = self::export($record);
        }
        return $out;
    }

    /**
     * One place, or null when it does not exist.
     *
     * @param int $id Place id.
     * @return \stdClass|null
     */
    public static function get(int $id): ?\stdClass {
        global $DB;
        if ($id <= 0) {
            return null;
        }
        $record = $DB->get_record(self::TABLE, ['id' => $id]);
        return $record ?: null;
    }

    /**
     * One place by name (case-insensitive, trimmed), or null.
     *
     * Used to warn about a duplicate before it is created, rather than to
     * enforce uniqueness: two labs genuinely can share a name in different
     * buildings, so blocking would be wrong.
     *
     * @param string $name Name to look for.
     * @return \stdClass|null
     */
    public static function get_by_name(string $name): ?\stdClass {
        global $DB;
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $records = $DB->get_records_select(self::TABLE,
            $DB->sql_like('name', ':name', false, false),
            ['name' => $name], 'id ASC', '*', 0, 1);
        return $records ? reset($records) : null;
    }

    /**
     * Shape a record for JavaScript and templates.
     *
     * @param \stdClass $record Database row.
     * @return array
     */
    public static function export(\stdClass $record): array {
        return [
            'id' => (int) $record->id,
            'name' => (string) $record->name,
            'description' => (string) ($record->description ?? ''),
            'lat' => (float) $record->latitude,
            'lng' => (float) $record->longitude,
            'defaultradius' => (int) $record->defaultradius,
            // Pre-joined label so the browser never has to assemble fields that
            // may be empty.
            'label' => self::format_label($record),
            // Formatted coordinates for the management table.
            'latdisplay' => format_float((float) $record->latitude, 6),
            'lngdisplay' => format_float((float) $record->longitude, 6),
            'radiusdisplay' => self::format_radius((int) $record->defaultradius),
        ];
    }

    /**
     * Human-readable label: "主楼 A301 (100 m)".
     *
     * @param \stdClass $record Database row.
     * @return string
     */
    public static function format_label(\stdClass $record): string {
        $radius = self::format_radius((int) $record->defaultradius);
        return format_string($record->name) . ' (' . $radius . ')';
    }

    /**
     * Human-readable radius ("100 m" or "1.5 km").
     *
     * Metres are used below a kilometre because that is the unit the rest of
     * the module uses for a geofence; a 100 m lecture-hall radius reads better
     * as "100 m" than as "0.1 km".
     *
     * @param int $metres Radius in metres.
     * @return string
     */
    public static function format_radius(int $metres): string {
        if ($metres >= 1000) {
            return get_string('radiuskm', 'mod_multicheckin', round($metres / 1000, 1));
        }
        return get_string('radiusm', 'mod_multicheckin', $metres);
    }

    /**
     * Create a place.
     *
     * @param array $data Keys: name, description, lat, lng, defaultradius.
     * @return int The new place id.
     * @throws \invalid_parameter_exception If the data does not validate.
     */
    public static function create(array $data): int {
        global $DB, $USER;

        $clean = self::validate($data);
        $now = time();

        return (int) $DB->insert_record(self::TABLE, (object) [
            'name' => $clean['name'],
            'description' => $clean['description'],
            'latitude' => $clean['lat'],
            'longitude' => $clean['lng'],
            'defaultradius' => $clean['defaultradius'],
            'usermodified' => (int) $USER->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Update a place.
     *
     * @param int $id Place id.
     * @param array $data Keys: name, description, lat, lng, defaultradius.
     * @return bool
     * @throws \invalid_parameter_exception If the data does not validate.
     * @throws \dml_missing_record_exception If the place does not exist.
     */
    public static function update(int $id, array $data): bool {
        global $DB, $USER;

        $existing = self::get($id);
        if ($existing === null) {
            throw new \dml_missing_record_exception(self::TABLE);
        }

        $clean = self::validate($data);

        $existing->name = $clean['name'];
        $existing->description = $clean['description'];
        $existing->latitude = $clean['lat'];
        $existing->longitude = $clean['lng'];
        $existing->defaultradius = $clean['defaultradius'];
        $existing->usermodified = (int) $USER->id;
        $existing->timemodified = time();

        return $DB->update_record(self::TABLE, $existing);
    }

    /**
     * Delete a place.
     *
     * Deleting from the library deliberately does NOT touch activities that
     * already used it. Each session stored its own copy of the centre and
     * radius at the time it was configured, so existing attendance rules keep
     * working exactly as before. Tidying the library must not silently change
     * a rule that has already been used to mark students absent.
     *
     * @param int $id Place id.
     * @return bool
     */
    public static function delete(int $id): bool {
        global $DB;
        if ($id <= 0) {
            return false;
        }
        return $DB->delete_records(self::TABLE, ['id' => $id]);
    }

    /**
     * How many activities reference a coordinate pair.
     *
     * Purely informational, shown on the management page so a teacher knows
     * whether a room is in use before deleting it. Nothing is blocked on the
     * result, because nothing needs to be: deleting never alters a session.
     *
     * The match is on coordinates rather than on a stored place id, because the
     * session form stores the resolved centre and radius, not a reference. Two
     * places with identical coordinates therefore both report the same usage,
     * which is honest — the activities genuinely do point at the same spot.
     *
     * Both sides are cast to float and compared on the SQL side with a small
     * tolerance. Comparing the raw strings would be fragile: the value read
     * back out of the place table is formatted to seven decimals by the DB
     * driver, while the session column may have been written from a value the
     * teacher typed with a different number of digits. The tolerance is about
     * a centimetre, which cannot conflate two genuinely different rooms.
     *
     * @param \stdClass $place Place record.
     * @return int Number of sessions with the same centre.
     */
    public static function usage_count(\stdClass $place): int {
        global $DB;

        $lat = (float) $place->latitude;
        $lng = (float) $place->longitude;

        // ~1e-7 degrees, i.e. about 1.1 cm at the equator.
        $tolerance = 0.0000001;

        $sql = "SELECT COUNT(id)
                  FROM {multicheckin}
                 WHERE geolat BETWEEN :lat1 AND :lat2
                   AND geolng BETWEEN :lng1 AND :lng2";

        return (int) $DB->count_records_sql($sql, [
            'lat1' => $lat - $tolerance,
            'lat2' => $lat + $tolerance,
            'lng1' => $lng - $tolerance,
            'lng2' => $lng + $tolerance,
        ]);
    }

    /**
     * Validate and normalise incoming place data.
     *
     * @param array $data Raw input.
     * @return array{name: string, description: string, lat: float, lng: float, defaultradius: int}
     * @throws \invalid_parameter_exception If anything is out of range or missing.
     */
    public static function validate(array $data): array {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \invalid_parameter_exception('placename_required');
        }
        if (\core_text::strlen($name) > self::NAME_MAX) {
            throw new \invalid_parameter_exception('placename_toolong');
        }

        $latraw = $data['lat'] ?? null;
        $lngraw = $data['lng'] ?? null;
        if ($latraw === null || $latraw === '' || $lngraw === null || $lngraw === ''
                || !is_numeric($latraw) || !is_numeric($lngraw)) {
            throw new \invalid_parameter_exception('placecoords_required');
        }

        $lat = (float) $latraw;
        $lng = (float) $lngraw;

        if ($lat < -90.0 || $lat > 90.0) {
            throw new \invalid_parameter_exception('placelat_range');
        }
        if ($lng < -180.0 || $lng > 180.0) {
            throw new \invalid_parameter_exception('placelng_range');
        }
        // Exactly (0, 0) sits in the Atlantic and is what an unfilled form
        // produces, not a classroom. Rejecting it catches the common mistake of
        // saving a place before the coordinates were entered.
        if ($lat === 0.0 && $lng === 0.0) {
            throw new \invalid_parameter_exception('placecoords_unset');
        }

        $radiusraw = $data['defaultradius'] ?? null;
        $radius = ($radiusraw === null || $radiusraw === '')
            ? self::RADIUS_DEFAULT
            : (int) $radiusraw;
        if ($radius < self::RADIUS_MIN || $radius > self::RADIUS_MAX) {
            throw new \invalid_parameter_exception('placeradius_range');
        }

        return [
            'name' => $name,
            'description' => trim((string) ($data['description'] ?? '')),
            'lat' => $lat,
            'lng' => $lng,
            'defaultradius' => $radius,
        ];
    }

    /**
     * Turn a validation failure code into a message a teacher can act on.
     *
     * The codes thrown by {@see validate()} are short identifiers rather than
     * language keys, so they can be logged without pulling in the language
     * system. This maps them, and falls back to a generic message for a code
     * that has no string yet — a missing translation should never surface as a
     * raw identifier in the interface.
     *
     * @param string $code Code thrown by {@see validate()}.
     * @return string
     */
    public static function error_string(string $code): string {
        $map = [
            'placename_required' => 'placename_required',
            'placename_toolong' => 'placename_toolong',
            'placecoords_required' => 'placecoords_required',
            'placelat_range' => 'placelat_range',
            'placelng_range' => 'placelng_range',
            'placecoords_unset' => 'placecoords_unset',
            'placeradius_range' => 'placeradius_range',
        ];
        $key = $map[$code] ?? '';
        if ($key !== '' && get_string_manager()->string_exists($key, 'mod_multicheckin')) {
            return get_string($key, 'mod_multicheckin');
        }
        return get_string('placeerror_generic', 'mod_multicheckin');
    }

    /**
     * Whether the current user may add, edit or delete places.
     *
     * The library is site-wide, so editing it changes coordinates that other
     * teachers' activities depend on. It is therefore capability-gated rather
     * than open to anyone who can edit one activity. The capability is granted
     * to managers and editing teachers by default, which is the agreed
     * "admins and teachers can both maintain it" rule, and a site that wants a
     * stricter process can remove it from the editing-teacher archetype without
     * touching any code.
     *
     * @param \context|null $context Context to check in, defaults to system.
     * @return bool
     */
    public static function can_edit(?\context $context = null): bool {
        if ($context === null) {
            $context = \context_system::instance();
        }
        return has_capability('mod/multicheckin:manageplaces', $context);
    }

    /**
     * Whether the current user may delete places specifically.
     *
     * Separated from {@see can_edit()} so a site can let teachers add rooms but
     * reserve deletion for administrators, which is a common arrangement once
     * the library has grown.
     *
     * @param \context|null $context Context to check in, defaults to system.
     * @return bool
     */
    public static function can_delete(?\context $context = null): bool {
        if ($context === null) {
            $context = \context_system::instance();
        }
        return has_capability('mod/multicheckin:manageplaces', $context);
    }

    /**
     * Configuration for the classroom map picker, or null when it cannot run.
     *
     * The basemap registry and the Leaflet bundle both belong to the
     * geolocation availability plugin, so a site that runs this module without
     * that one simply gets no map: the coordinate fields keep working exactly
     * as they always did. This is a deliberate trade against bundling a second
     * 150 KB copy of Leaflet and a second basemap list that could drift.
     *
     * @param int $placeid Place being edited, or 0 for a new one.
     * @return array|null Configuration for mod_multicheckin/placemap, or null.
     */
    public static function map_config(int $placeid = 0): ?array {
        global $CFG;

        if (!class_exists('\availability_gpsloc\basemaps')) {
            return null;
        }
        try {
            $usable = \availability_gpsloc\basemaps::usable();
        } catch (\Throwable $e) {
            return null;
        }
        if (!$usable) {
            return null;
        }

        $basemaps = [];
        foreach ($usable as $key => $map) {
            $basemaps[$key] = [
                'name' => $map['name'],
                'url' => $map['url'],
                'attribution' => $map['attribution'],
                'maxzoom' => (int) $map['maxzoom'],
            ];
        }

        $lat = null;
        $lng = null;
        $radius = null;
        if ($placeid > 0) {
            $place = self::get($placeid);
            if ($place !== null) {
                $lat = (float) $place->latitude;
                $lng = (float) $place->longitude;
                $radius = (int) $place->defaultradius;
            }
        }

        $defaultbasemap = get_config('availability_gpsloc', 'defaultbasemap');
        if (!isset($basemaps[$defaultbasemap])) {
            $defaultbasemap = array_key_first($basemaps);
        }

        return [
            'wwwroot' => $CFG->wwwroot,
            // Leaflet is served from the availability plugin's directory.
            'leafletbase' => $CFG->wwwroot . '/availability/condition/gpsloc/leaflet',
            'panelid' => 'multicheckin-placemap',
            'latfield' => 'latitude',
            'lngfield' => 'longitude',
            'radiusfield' => 'defaultradius',
            'basemaps' => $basemaps,
            'basemap' => $defaultbasemap,
            'lat' => $lat,
            'lng' => $lng,
            'radius' => $radius,
            'defaultLat' => (float) (get_config('availability_gpsloc', 'defaultlat') ?: 30.5928),
            'defaultLng' => (float) (get_config('availability_gpsloc', 'defaultlng') ?: 114.3055),
        ];
    }
}
