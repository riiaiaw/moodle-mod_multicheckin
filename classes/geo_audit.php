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
 * Reading side of the geofence audit trail.
 *
 * geo_manager writes a row for every check-in attempt it is asked about, and
 * until now nothing read them back. That left the one question a teacher
 * actually has - "why did the fence do nothing?" - answerable only by reading
 * the source. This class turns those rows into the answer, and it is strictly
 * read-only: no decision here changes how a check-in is judged.
 *
 * The interesting part is not the verdict but the cases where there was no
 * verdict to give. A row can say "allowed" for three very different reasons:
 * the position really was inside the circle, the code exempted the attempt, or
 * there was no circle to compare against. Those are told apart in {@see explain}
 * and shown next to the row, because on its own "allowed" hides exactly the
 * situation the teacher is looking for.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class geo_audit {

    /** @var int Rows per page of the audit table. */
    public const PAGE_SIZE = 50;

    /** @var int Distance readings used for the summary statistics. */
    public const STATS_CAP = 2000;

    /**
     * A snapshot of the fence as configured right now.
     *
     * Shown above the table so the log can be read against the settings it was
     * produced under: a verdict from last week means nothing next to today's
     * radius, and the mode is what decides whether the log is even relevant.
     *
     * @param \stdClass $session Session record.
     * @return array
     */
    public static function config(\stdClass $session): array {
        $mode = (string) ($session->geomode ?? geo_manager::MODE_NONE);
        $lat = (float) ($session->geolat ?? 0);
        $lng = (float) ($session->geolng ?? 0);

        return [
            'mode' => $mode,
            'mode_label' => geo_manager::get_modes()[$mode] ?? $mode,
            'centre_set' => !($lat === 0.0 && $lng === 0.0),
            'lat' => $lat,
            'lng' => $lng,
            'radius' => (int) ($session->georadius ?? 0),
            'ranges' => geo_manager::parse_ranges((string) ($session->geoipranges ?? '')),
            'enforceqr' => !empty($session->geoenforceqr),
            'needsgps' => geo_manager::mode_needs_gps($mode),
            'needsip' => in_array($mode, [geo_manager::MODE_IP, geo_manager::MODE_BOTH], true),
        ];
    }

    /**
     * Why this row's verdict may not mean what it looks like.
     *
     * Returns a language key, or null when the row is a genuine evaluation of a
     * configured fence. Anything returned here is an observation about the
     * stored data and the configuration - never a change to either.
     *
     * @param \stdClass $row Audit row (geo log joined with its user and point).
     * @param array $config Result of config().
     * @param \stdClass $session Session record.
     * @return string|null Language key.
     */
    public static function explain(\stdClass $row, array $config, \stdClass $session): ?string {
        $modewas = (string) ($row->geomode ?? geo_manager::MODE_NONE);

        // The fence was configured, yet this attempt was evaluated as "none".
        // For a scan that is the QR exemption; for anything else it means the
        // activity's own mode was "none" at the time.
        if ($modewas === geo_manager::MODE_NONE && $config['mode'] !== geo_manager::MODE_NONE) {
            return ((string) $row->method === 'qr') ? 'audit_exempt_qr' : 'audit_exempt_mode';
        }

        // GPS was asked for but no centre was ever entered, so there was nothing
        // to measure against and the attempt was let through.
        if ($config['needsgps'] && !$config['centre_set'] && (string) $row->method !== 'qr') {
            return 'audit_centre_missing';
        }

        // IP was asked for but no range was configured - the same shape of
        // silent pass on the network side.
        if ($config['needsip'] && !$config['ranges'] && (string) $row->method !== 'qr') {
            return 'audit_ranges_missing';
        }

        return null;
    }

    /**
     * Fetch one page of audit rows for a session.
     *
     * @param int $sessionid Session id.
     * @param array $options 'result' => all|allowed|rejected, 'userid' => int,
     *                       'page' => int.
     * @return array{rows: array, total: int, page: int, pages: int}
     */
    public static function fetch(int $sessionid, array $options = []): array {
        global $DB;

        [$where, $params] = self::conditions($sessionid, $options);

        $total = (int) $DB->count_records_sql(
            "SELECT COUNT(g.id) FROM {multicheckin_geo_log} g WHERE $where", $params);

        $page = max(0, (int) ($options['page'] ?? 0));
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pages - 1);

        $rows = $DB->get_records_sql(
            "SELECT g.*, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                    u.middlename, u.alternatename, u.email, u.picture, u.imagealt,
                    mp.name AS pointname, mp.role AS pointrole
               FROM {multicheckin_geo_log} g
               JOIN {user} u ON u.id = g.userid
               JOIN {multicheckin_point} mp ON mp.id = g.pointid
              WHERE $where
           ORDER BY g.timecreated DESC, g.id DESC",
            $params, $page * self::PAGE_SIZE, self::PAGE_SIZE);

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * Counts per reason code for a session.
     *
     * @param int $sessionid Session id.
     * @param array $options As for fetch().
     * @return array{total: int, allowed: int, rejected: int, reasons: array}
     */
    public static function summary(int $sessionid, array $options = []): array {
        global $DB;

        [$where, $params] = self::conditions($sessionid, $options);

        $rows = $DB->get_records_sql(
            "SELECT g.reason AS reason, g.allowed AS allowed, COUNT(g.id) AS num
               FROM {multicheckin_geo_log} g
              WHERE $where
           GROUP BY g.reason, g.allowed", $params);

        $out = ['total' => 0, 'allowed' => 0, 'rejected' => 0, 'reasons' => []];
        foreach ($rows as $row) {
            $num = (int) $row->num;
            $out['total'] += $num;
            $out[$row->allowed ? 'allowed' : 'rejected'] += $num;
            $reason = (string) ($row->reason ?? '');
            if ($reason === '') {
                $reason = 'unknown';
            }
            $out['reasons'][$reason] = ($out['reasons'][$reason] ?? 0) + $num;
        }

        return $out;
    }

    /**
     * Distance statistics over the recent GPS judgements of a session.
     *
     * The point of the summary is to make a systematic offset visible. A fence
     * whose centre is on the wrong datum rejects students standing in the room
     * and accepts ones down the road, and the giveaway is that every distance is
     * wrong by roughly the same amount in the same direction. One number per row
     * hides that; the spread shows it.
     *
     * @param int $sessionid Session id.
     * @param int $cap How many recent readings to use.
     * @return array{count: int, min: float, median: float, mean: float, max: float}
     */
    public static function distance_stats(int $sessionid, int $cap = self::STATS_CAP): array {
        global $DB;

        $values = $DB->get_fieldset_sql(
            "SELECT g.distance
               FROM {multicheckin_geo_log} g
              WHERE g.sessionid = :sessionid AND g.distance IS NOT NULL
           ORDER BY g.timecreated DESC",
            ['sessionid' => $sessionid], 0, $cap);

        $values = array_map('floatval', $values);
        if (!$values) {
            return ['count' => 0, 'min' => 0.0, 'median' => 0.0, 'mean' => 0.0, 'max' => 0.0];
        }

        sort($values);
        $count = count($values);
        $middle = (int) floor($count / 2);
        $median = $count % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;

        return [
            'count' => $count,
            'min' => $values[0],
            'median' => $median,
            'mean' => array_sum($values) / $count,
            'max' => $values[$count - 1],
        ];
    }

    /**
     * Build the WHERE clause and parameters shared by the read methods.
     *
     * @param int $sessionid Session id.
     * @param array $options Filter options.
     * @return array [string $where, array $params]
     */
    protected static function conditions(int $sessionid, array $options): array {
        $where = 'g.sessionid = :sessionid';
        $params = ['sessionid' => $sessionid];

        $result = (string) ($options['result'] ?? 'all');
        if ($result === 'allowed') {
            $where .= ' AND g.allowed = 1';
        } else if ($result === 'rejected') {
            $where .= ' AND g.allowed = 0';
        }

        if (!empty($options['userid'])) {
            $where .= ' AND g.userid = :userid';
            $params['userid'] = (int) $options['userid'];
        }

        return [$where, $params];
    }
}
