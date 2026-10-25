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
 * Standalone window showing every check-in code of one occurrence.
 *
 * Reached from the "check-in QR codes" button on the activity page and on the
 * report page. It exists because the inline panel only renders while a point is
 * open: a teacher who arrives early, or who wants the codes ready on a second
 * screen before the bell, otherwise has nothing to open.
 *
 * The window deliberately does NOT filter by the open window. It lists every
 * point of the occurrence and labels each one's state, and says plainly when
 * none of them is open, so a code read off this screen too early is never
 * mistaken for one that will be accepted.
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

use mod_multicheckin\qr_manager;
use mod_multicheckin\session_manager;

$cmid = required_param('id', PARAM_INT);
$sessionid = required_param('session', PARAM_INT);

$cm = get_coursemodule_from_id('multicheckin', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
// Codes are a teacher's working copy of the signing token: this window is the
// one place they are all laid out, and no student has any business seeing the
// set of them at once.
require_capability('mod/multicheckin:manage', $context);

// The occurrence must belong to this activity. Taken straight from the request,
// a manager of one course could open another course's codes - and qrcode.php
// would mint valid tokens for them. The record is therefore resolved against
// this activity's own template, exactly as qrcode.php and checkin.php do.
$template = session_manager::get_template_for_cm($cm->id);
$session = $template ? session_manager::resolve_session($template, $sessionid) : null;
if (!$session || (int) $session->id !== $sessionid) {
    throw new \moodle_exception('invalidrecord', 'error');
}

$points = session_manager::get_points((int) $session->id);

// The 'popup' layout is the one Moodle renders without site navigation. This
// window exists to be projected or dragged to a second screen, and every strip
// of chrome around the code is space the back row cannot read it from.
$PAGE->set_url('/mod/multicheckin/qrwindow.php', ['id' => $cm->id, 'session' => $session->id]);
$PAGE->set_pagelayout('popup');
$PAGE->set_title(get_string('qrwindow_title', 'mod_multicheckin', format_string($session->name)));
$PAGE->set_heading(format_string($session->name));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo qr_manager::qr_window_panel((int) $cm->id, $session, $points);
echo $OUTPUT->footer();
