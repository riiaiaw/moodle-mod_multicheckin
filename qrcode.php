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

/**
 * Outputs a QR code PNG for a check-in point.
 *
 * This page renders the signed token as a QR image through the bundled encoder,
 * so the plugin carries no third-party dependency. Which token that is - one
 * that rotates every few seconds, or one that stands still for a photo
 * occurrence - is decided by qr_manager::build_payload(), not here.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Moodle 5.x moved plugins under public/, but keeps the authoritative
// config.php one level ABOVE it. A bare '../../config.php' resolves to
// public/config.php there, which is not the file that defines the site.
// Resolving against __DIR__ and probing both layouts finds the real one on
// either a 4.x tree (mod/<name>/) or a 5.x tree (public/mod/<name>/).
$mcroots = [__DIR__ . '/../../config.php', __DIR__ . '/../../../config.php'];
foreach ($mcroots as $mcroot) {
    if (is_readable($mcroot)) {
        require_once($mcroot);
        break;
    }
}
unset($mcroots, $mcroot);
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/lib/qrcode_encoder.php');

use mod_multicheckin\qr_manager;
use mod_multicheckin\session_manager;

$cmid = required_param('id', PARAM_INT);
$sessionid = required_param('session', PARAM_INT);
$pointid = required_param('point', PARAM_INT);

$cm = get_coursemodule_from_id('multicheckin', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/multicheckin:manage', $context);

// The QR payload embeds a rotating token for one point of one session. Were
// the session taken straight from the request, a manager of this course could
// mint a valid code for another course's attendance, so the record is resolved
// against this activity first.
$template = session_manager::get_template_for_cm($cm->id);
$session = $template ? session_manager::resolve_session($template, $sessionid) : null;
if (!$session || (int) $session->id !== $sessionid) {
    throw new \moodle_exception('invalidrecord', 'error');
}

$point = $DB->get_record('multicheckin_point', ['id' => $pointid, 'sessionid' => $sessionid], '*', MUST_EXIST);

$session->coursemodule = $cm->id;
$payload = qr_manager::build_payload($session, $point);

// Render via the bundled encoder (no external dependency).
//
// Scale 8 with a 4-module quiet zone: the quiet zone is what the spec requires
// (ISO/IEC 18004) and what stops phone scanners from failing to lock onto the
// symbol, and the larger scale keeps the code readable when the panel is
// projected and scanned from a few metres away. A rotating image is redrawn on
// every token, so a bigger PNG costs nothing in freshness.
$png = \mod_multicheckin\qrcode_encoder::png($payload, 8, 4);

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
echo $png;
exit;
