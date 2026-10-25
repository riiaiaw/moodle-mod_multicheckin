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
 * Geofence enforcement for self-service check-in.
 *
 * Answers one question: may this user, from this network position, mark
 * themselves present for this point? Two independent signals are supported and
 * may be combined:
 *
 *   - IP  : the client address must fall inside one of the configured CIDR ranges
 *           (typically the campus / classroom Wi-Fi subnet).
 *   - GPS : the coordinates reported by the browser must lie within
 *           {@see georadius} metres of the classroom centre.
 *
 * Every evaluated attempt is written to {multicheckin_geo_log} so a teacher can
 * audit rejected (and accepted) check-ins after the fact.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class geo_manager {

    /** @var string No geofence: any authenticated student may self check-in. */
    public const MODE_NONE = 'none';

    /** @var string Enforce IP/CIDR only. */
    public const MODE_IP = 'ip';

    /** @var string Enforce GPS radius only. */
    public const MODE_GPS = 'gps';

    /** @var string Enforce both IP and GPS (stricter: both must pass). */
    public const MODE_BOTH = 'both';

    /**
     * Geofence modes keyed by mode code.
     *
     * @return array<string, string>
     */
    public static function get_modes(): array {
        return [
            self::MODE_NONE => get_string('geomode_none', 'mod_multicheckin'),
            self::MODE_IP => get_string('geomode_ip', 'mod_multicheckin'),
            self::MODE_GPS => get_string('geomode_gps', 'mod_multicheckin'),
            self::MODE_BOTH => get_string('geomode_both', 'mod_multicheckin'),
        ];
    }

    /**
     * Decide whether a check-in attempt satisfies the configured geofence.
     *
     * A mode of "none" always passes. In "both" mode every configured signal must
     * pass; in single-signal modes only that signal is evaluated.
     *
     * @param \stdClass $session Session record (with geofence columns).
     * @param \stdClass $point Point record.
     * @param int $userid User making the attempt.
     * @param string $method Check-in method: self|qr|password.
     * @param array|null $clientgeo Optional ['lat' => float, 'lng' => float, 'acc' => float].
     * @param bool $audit Whether to write a row into multicheckin_geo_log.
     * @return array{allowed: bool, reason: string}
     */
    public static function evaluate(\stdClass $session, \stdClass $point, int $userid,
            string $method = 'self', ?array $clientgeo = null, bool $audit = true): array {
        $mode = self::effective_mode($session, $method);

        if ($mode === self::MODE_NONE) {
            return ['allowed' => true, 'reason' => 'ok'];
        }

        $ip = getremoteaddr();
        $lat = isset($clientgeo['lat']) ? (float) $clientgeo['lat'] : null;
        $lng = isset($clientgeo['lng']) ? (float) $clientgeo['lng'] : null;
        $acc = isset($clientgeo['acc']) ? (float) $clientgeo['acc'] : null;
        $distance = null;

        $ipok = null;
        $gpsok = null;
        $reason = 'ok';

        // ---- IP / CIDR evaluation ----
        if ($mode === self::MODE_IP || $mode === self::MODE_BOTH) {
            $ranges = self::parse_ranges($session->geoipranges ?? '');
            if (!$ranges) {
                // Mode demands IP but nothing configured: do not lock students out.
                $ipok = true;
            } else {
                $ipok = self::ip_in_ranges($ip, $ranges);
                if (!$ipok && $reason === 'ok') {
                    $reason = 'ip_not_allowed';
                }
            }
        }

        // ---- GPS radius evaluation ----
        if ($mode === self::MODE_GPS || $mode === self::MODE_BOTH) {
            $clat = (float) ($session->geolat ?? 0);
            $clng = (float) ($session->geolng ?? 0);
            $radius = (int) ($session->georadius ?? 0);

            if ($clat == 0.0 && $clng == 0.0) {
                // No centre configured: cannot enforce, do not block.
                $gpsok = true;
            } else if ($lat === null || $lng === null) {
                $gpsok = false;
                if ($reason === 'ok') {
                    $reason = 'no_gps';
                }
            } else {
                $distance = self::haversine($clat, $clng, $lat, $lng);
                // Allow the reported accuracy as slack so a noisy fix near the
                // edge is not unfairly rejected.
                $slack = ($acc !== null && $acc > 0) ? min($acc, 50.0) : 0.0;
                $gpsok = ($distance <= $radius + $slack);
                if (!$gpsok && $reason === 'ok') {
                    $reason = 'out_of_range';
                }
            }
        }

        // ---- Combine: in "both" mode every evaluated signal must pass ----
        $allowed = true;
        if ($mode === self::MODE_BOTH) {
            $allowed = ($ipok !== false) && ($gpsok !== false);
        } else if ($mode === self::MODE_IP) {
            $allowed = ($ipok !== false);
        } else if ($mode === self::MODE_GPS) {
            $allowed = ($gpsok !== false);
        }

        if ($allowed) {
            $reason = 'ok';
        }

        if ($audit) {
            self::log_attempt($session, $point, $userid, $method, $mode,
                $allowed, $reason, $ip, $lat, $lng, $acc, $distance);
        }

        return ['allowed' => $allowed, 'reason' => $reason];
    }

    /**
     * Which geofence mode actually applies to this method.
     *
     * QR check-ins are governed by the same fence only when the teacher opted in
     * via geoenforceqr; otherwise scanning a live rotating code is treated as
     * sufficient proof of presence.
     *
     * @param \stdClass $session Session record.
     * @param string $method Check-in method.
     * @return string Mode code.
     */
    public static function effective_mode(\stdClass $session, string $method): string {
        $mode = $session->geomode ?? self::MODE_NONE;
        if (!array_key_exists($mode, self::get_modes())) {
            $mode = self::MODE_NONE;
        }
        if ($method === 'qr' && empty($session->geoenforceqr)) {
            return self::MODE_NONE;
        }
        return $mode;
    }

    /**
     * Whether the given mode needs the browser to report GPS coordinates.
     *
     * @param string $mode Mode code.
     * @return bool
     */
    public static function mode_needs_gps(string $mode): bool {
        return $mode === self::MODE_GPS || $mode === self::MODE_BOTH;
    }

    /**
     * Parse a newline-separated list of single IPs or CIDR ranges.
     *
     * IPv4 and IPv6 may be mixed in one list; each range is matched only
     * against addresses of its own family.
     *
     * @param string $raw Raw textarea value.
     * @return array List of [packed network, prefix bits] pairs.
     */
    public static function parse_ranges(string $raw): array {
        $out = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            $pair = self::parse_cidr($line);
            if ($pair !== null) {
                $out[] = $pair;
            }
        }
        return $out;
    }

    /**
     * Parse one address or CIDR token into a packed network plus prefix bits.
     *
     * Both families go through here. The network travels in the packed form
     * inet_pton() returns - four bytes for IPv4, sixteen for IPv6 - because an
     * integer cannot hold 128 bits, and because a packed string makes "these
     * two are not comparable" a length check instead of a numeric coincidence.
     *
     * An IPv4-mapped IPv6 token (::ffff:192.168.1.0/120) is folded back to the
     * IPv4 network it names, so a teacher who pastes one from a proxy log gets
     * the range they meant rather than a v6 range no IPv4 client can match.
     *
     * @param string $token Token, e.g. "10.0.0.5", "192.168.10.0/24", "2001:db8::/32".
     * @return array|null [packed network, prefix bits] or null if invalid.
     */
    public static function parse_cidr(string $token): ?array {
        $prefix = null;
        if (strpos($token, '/') !== false) {
            [$ip, $pfx] = explode('/', $token, 2);
            if (!ctype_digit($pfx)) {
                return null;
            }
            $prefix = (int) $pfx;
        } else {
            $ip = $token;
        }

        // Deliberately NOT packed_address(): that one folds mapped addresses,
        // which would throw away the prefix before it has been rebased. Here the
        // fold happens after, and only when the prefix still names the IPv4 part.
        $packed = trim($ip) === '' ? false : @inet_pton(trim($ip));
        if ($packed === false) {
            return null;
        }

        $mapped = self::mapped_as_ipv4($packed);
        if ($mapped !== null && ($prefix === null || $prefix >= 96)) {
            // ::ffff:a.b.c.d names an IPv4 address, and /96 or longer names it
            // plus some of its own bits: rebase onto the four bytes it means.
            $packed = $mapped;
            $prefix = $prefix === null ? 32 : $prefix - 96;
        }

        $maxbits = strlen($packed) * 8;
        if ($prefix === null) {
            $prefix = $maxbits;
        }
        if ($prefix < 0 || $prefix > $maxbits) {
            return null;
        }

        return [self::mask_packed($packed, $prefix), $prefix];
    }

    /**
     * The packed form of an address, or null when it is not one.
     *
     * Mapped addresses are folded to IPv4 here as well, so a client reported as
     * ::ffff:10.0.0.5 matches the 10.0.0.0/8 the teacher typed.
     *
     * @param string $ip Address.
     * @return string|null Packed bytes, or null when the value is not an address.
     */
    private static function packed_address(string $ip): ?string {
        if ($ip === '') {
            return null;
        }
        // inet_pton() warns on garbage; the warning is not the answer.
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        return self::mapped_as_ipv4($packed) ?? $packed;
    }

    /**
     * The IPv4 form of an IPv4-mapped IPv6 address, or null for anything else.
     *
     * A dual-stack socket can report an IPv4 client as ::ffff:a.b.c.d. Treating
     * that as "IPv6" would make every IPv4 range in the list miss a client that
     * is, in fact, on IPv4.
     *
     * @param string $packed Packed address.
     * @return string|null Four bytes, or null when it is not mapped.
     */
    private static function mapped_as_ipv4(string $packed): ?string {
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return substr($packed, 12, 4);
        }
        return null;
    }

    /**
     * Zero the host bits of a packed network address.
     *
     * @param string $packed Packed address.
     * @param int $prefix Prefix bits.
     * @return string Packed network.
     */
    private static function mask_packed(string $packed, int $prefix): string {
        $length = strlen($packed);
        $bytes = intdiv($prefix, 8);
        $bits = $prefix % 8;
        $out = substr($packed, 0, $bytes);
        if ($bits > 0 && $bytes < $length) {
            $mask = (0xFF << (8 - $bits)) & 0xFF;
            $out .= chr(ord($packed[$bytes]) & $mask);
            $bytes++;
        }
        return str_pad($out, $length, "\0");
    }

    /**
     * Whether a packed address sits inside one packed network.
     *
     * @param string $packed Packed address.
     * @param string $net Packed network (host bits already zeroed).
     * @param int $prefix Prefix bits.
     * @return bool
     */
    private static function packed_in_network(string $packed, string $net, int $prefix): bool {
        $bytes = intdiv($prefix, 8);
        $bits = $prefix % 8;
        if ($bytes > 0 && substr($packed, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $bits)) & 0xFF;
        return (ord($packed[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }

    /**
     * Check a client address against a list of parsed ranges.
     *
     * Both families are matched, and a range only ever matches an address of
     * its own family: a four-byte network and a sixteen-byte address are not
     * compared at all. That is what keeps an IPv4-only list behaving exactly as
     * it did before IPv6 support existed - an IPv6 client still finds nothing
     * that describes it, and the fence still fails closed for it.
     *
     * @param string $ip Client IP.
     * @param array $ranges Parsed ranges from parse_ranges().
     * @return bool
     */
    public static function ip_in_ranges(string $ip, array $ranges): bool {
        $packed = self::packed_address($ip);
        if ($packed === null) {
            // Not an address at all - an empty value, a header that is not one.
            // Fails closed, as before.
            return false;
        }
        foreach ($ranges as $range) {
            [$net, $prefix] = $range;
            if (strlen($net) !== strlen($packed)) {
                continue;
            }
            if (self::packed_in_network($packed, $net, (int) $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Great-circle distance between two WGS-84 points, in metres (haversine).
     *
     * @param float $lat1 Latitude 1.
     * @param float $lng1 Longitude 1.
     * @param float $lat2 Latitude 2.
     * @param float $lng2 Longitude 2.
     * @return float Metres.
     */
    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float {
        $earth = 6371000.0; // Mean Earth radius in metres.
        $dlat = deg2rad($lat2 - $lat1);
        $dlng = deg2rad($lng2 - $lng1);
        $a = sin($dlat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dlng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earth * $c;
    }

    /**
     * Persist one geofence decision to the audit table.
     *
     * @param \stdClass $session Session.
     * @param \stdClass $point Point.
     * @param int $userid User.
     * @param string $method Check-in method.
     * @param string $mode Evaluated mode.
     * @param bool $allowed Decision.
     * @param string $reason Reason code.
     * @param string $ip Client IP.
     * @param float|null $lat Latitude.
     * @param float|null $lng Longitude.
     * @param float|null $acc Accuracy.
     * @param float|null $distance Computed distance.
     * @return void
     */
    protected static function log_attempt(\stdClass $session, \stdClass $point, int $userid,
            string $method, string $mode, bool $allowed, string $reason, string $ip,
            ?float $lat, ?float $lng, ?float $acc, ?float $distance): void {
        global $DB;

        $row = (object) [
            'sessionid' => (int) $session->id,
            'pointid' => (int) $point->id,
            'userid' => $userid,
            'method' => $method,
            'geomode' => $mode,
            'allowed' => $allowed ? 1 : 0,
            'reason' => $reason,
            'ip' => $ip,
            'latitude' => $lat,
            'longitude' => $lng,
            'accuracy' => $acc,
            'distance' => $distance,
            'timecreated' => time(),
        ];
        $DB->insert_record('multicheckin_geo_log', $row);
    }

    /**
     * Map a reason code to a human-readable, localised message.
     *
     * @param string $reason Reason code from evaluate().
     * @return string
     */
    public static function reason_string(string $reason): string {
        $map = [
            'ok' => 'geofence_ok',
            'ip_not_allowed' => 'geofence_ip_denied',
            'out_of_range' => 'geofence_out_of_range',
            'no_gps' => 'geofence_no_gps',
        ];
        $key = $map[$reason] ?? 'geofence_denied';
        return get_string($key, 'mod_multicheckin');
    }
}
