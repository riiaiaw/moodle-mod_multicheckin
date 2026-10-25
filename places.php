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
 * Manage the shared library of classroom locations.
 *
 * This is the bulk-editing counterpart to the dropdown in the activity form.
 * The form is the fast path — capture a coordinate, name it, move on — while
 * this page is where a teacher goes to correct a typo, fix a coordinate that
 * was entered wrong, or clear out rooms that are no longer used.
 *
 * The library is site-wide, so the page is gated on
 * mod/multicheckin:manageplaces at system level rather than on the ability to
 * manage one activity.
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
require_once($CFG->libdir . '/formslib.php');
// Load the class explicitly. Moodle's autoloader can resolve this namespace on
// a correctly registered site, but the management page is reached directly by
// URL and must not depend on the autoloader having been primed first.
require_once(__DIR__ . '/classes/place_manager.php');

use mod_multicheckin\place_manager;

$context = context_system::instance();
require_login();
require_capability('mod/multicheckin:manageplaces', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$placeid = optional_param('placeid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

// Where to send the teacher back to when they are done, so a trip here from
// an activity form does not lose the half-filled form they came from.
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
$backurl = $returnurl !== '' ? new moodle_url($returnurl)
    : new moodle_url('/mod/multicheckin/places.php');
$pageurl = new moodle_url('/mod/multicheckin/places.php');
if ($returnurl !== '') {
    $pageurl->param('returnurl', $returnurl);
}

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_title(get_string('place_managetitle', 'mod_multicheckin'));
$PAGE->set_heading(get_string('place_managetitle', 'mod_multicheckin'));
$PAGE->set_pagelayout('admin');

// ---- Deletion ------------------------------------------------------------
// Confirm-then-delete, because the library is shared and an accidental click
// would remove a room other teachers rely on. Nothing is lost from existing
// activities either way, but the room disappears from everyone's dropdown.
if ($action === 'delete' && $placeid > 0) {
    $place = place_manager::get($placeid);
    if ($place === null) {
        redirect($pageurl, get_string('placenotfound', 'mod_multicheckin'),
            null, \core\output\notification::NOTIFY_ERROR);
    }

    if ($confirm && confirm_sesskey()) {
        $usage = place_manager::usage_count($place);
        place_manager::delete($placeid);

        $message = get_string('placedeleted', 'mod_multicheckin', format_string($place->name));
        if ($usage > 0) {
            // Reassure rather than warn: the earlier activities are untouched.
            $message .= ' ' . get_string('placedeletedinuse', 'mod_multicheckin', $usage);
        }
        redirect($pageurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
    }

    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('placedeleteconfirm', 'mod_multicheckin', format_string($place->name)),
        new moodle_url($pageurl, [
            'action' => 'delete',
            'placeid' => $placeid,
            'confirm' => 1,
            'sesskey' => sesskey(),
        ]),
        $pageurl
    );
    echo $OUTPUT->footer();
    exit;
}

// ---- Add / edit ----------------------------------------------------------
/**
 * Form for creating or editing one saved classroom location.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_multicheckin_place_form extends moodleform {

    /**
     * Form definition.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;
        $place = $this->_customdata['place'] ?? null;

        $mform->addElement('hidden', 'placeid', $place->id ?? 0);
        $mform->setType('placeid', PARAM_INT);

        $mform->addElement('text', 'name', get_string('placename', 'mod_multicheckin'),
            ['size' => 48, 'maxlength' => place_manager::NAME_MAX]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addHelpButton('name', 'placename', 'mod_multicheckin');

        $mform->addElement('textarea', 'description',
            get_string('placedescription', 'mod_multicheckin'), ['rows' => 2, 'cols' => 48]);
        $mform->setType('description', PARAM_TEXT);
        $mform->addHelpButton('description', 'placedescription', 'mod_multicheckin');

        // Point picker. Without it these two fields are a memory test: the
        // teacher has to know the building's coordinates before they can save
        // it, which is how a wrong room gets into the shared library that
        // every other teacher then picks.
        $mform->addElement('html', html_writer::div(
            html_writer::div(get_string('place_mappick', 'mod_multicheckin'), 'small text-muted mb-1')
            . html_writer::div('', '', [
                'id' => 'multicheckin-placemap',
                'style' => 'height:320px;width:100%;border:1px solid #dee2e6;border-radius:4px;',
            ])));

        $mform->addElement('text', 'latitude', get_string('placelatitude', 'mod_multicheckin'),
            ['size' => 20]);
        $mform->setType('latitude', PARAM_RAW_TRIMMED);
        $mform->addRule('latitude', null, 'required', null, 'client');
        $mform->addHelpButton('latitude', 'placelatitude', 'mod_multicheckin');

        $mform->addElement('text', 'longitude', get_string('placelongitude', 'mod_multicheckin'),
            ['size' => 20]);
        $mform->setType('longitude', PARAM_RAW_TRIMMED);
        $mform->addRule('longitude', null, 'required', null, 'client');

        $mform->addElement('text', 'defaultradius',
            get_string('placedefaultradius', 'mod_multicheckin'), ['size' => 8]);
        $mform->setType('defaultradius', PARAM_INT);
        $mform->setDefault('defaultradius', place_manager::RADIUS_DEFAULT);
        $mform->addHelpButton('defaultradius', 'placedefaultradius', 'mod_multicheckin');

        $this->add_action_buttons(true, get_string('place_save', 'mod_multicheckin'));
    }

    /**
     * Validate.
     *
     * @param array $data Submitted data.
     * @param array $files Files.
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        try {
            place_manager::validate([
                'name' => $data['name'] ?? '',
                'description' => $data['description'] ?? '',
                'lat' => $data['latitude'] ?? '',
                'lng' => $data['longitude'] ?? '',
                'defaultradius' => $data['defaultradius'] ?? null,
            ]);
        } catch (\invalid_parameter_exception $e) {
            $message = place_manager::error_string($e->getMessage());
            // Attach the message to the field the code names, so it appears
            // next to the input rather than as a form-level banner.
            $code = $e->getMessage();
            if (strpos($code, 'placename') === 0) {
                $errors['name'] = $message;
            } else if (strpos($code, 'placeradius') === 0) {
                $errors['defaultradius'] = $message;
            } else {
                $errors['latitude'] = $message;
                $errors['longitude'] = $message;
            }
        }

        return $errors;
    }
}

$formurl = new moodle_url($pageurl, ['action' => $action === 'edit' ? 'edit' : 'add', 'placeid' => $placeid]);
$form = new mod_multicheckin_place_form($formurl, ['place' => $placeid > 0 ? place_manager::get($placeid) : null]);

if ($form->is_cancelled()) {
    redirect($pageurl);
}

// ---- Save ----------------------------------------------------------------
if ($data = $form->get_data()) {
    require_sesskey();

    $payload = [
        'name' => $data->name,
        'description' => $data->description,
        'lat' => $data->latitude,
        'lng' => $data->longitude,
        'defaultradius' => $data->defaultradius,
    ];

    // Note a same-named room, but do not block on it: two labs in different
    // buildings can legitimately share a name, and refusing would be wrong.
    // The message tells the teacher it happened so they can tell them apart
    // later, which is the real purpose of the check.
    $duplicate = place_manager::get_by_name($payload['name']);
    $dupenotice = '';
    if ($duplicate !== null && (int) $duplicate->id !== (int) ($data->placeid ?? 0)) {
        $dupenotice = ' ' . get_string('place_duplicatewarning', 'mod_multicheckin',
            format_string($duplicate->name));
    }

    try {
        if (!empty($data->placeid)) {
            place_manager::update((int) $data->placeid, $payload);
            $message = get_string('placeupdated', 'mod_multicheckin') . $dupenotice;
        } else {
            place_manager::create($payload);
            $message = get_string('placecreated', 'mod_multicheckin') . $dupenotice;
        }
        redirect($pageurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (\invalid_parameter_exception $e) {
        // validation() already covers this path; reaching here means the data
        // changed between validation and save, which is worth surfacing.
        redirect($formurl, place_manager::error_string($e->getMessage()),
            null, \core\output\notification::NOTIFY_ERROR);
    }
}

// ---- Page ----------------------------------------------------------------
echo $OUTPUT->header();

echo html_writer::div(get_string('place_managedesc', 'mod_multicheckin'),
    'alert alert-info');

if ($action === 'add' || $action === 'edit') {
    echo html_writer::tag('h3', $action === 'edit'
        ? get_string('place_edit', 'mod_multicheckin')
        : get_string('place_add', 'mod_multicheckin'));
    $form->display();

    // Map picker. The basemap data and the Leaflet bundle both live in the
    // geolocation availability plugin, so this page borrows them rather than
    // shipping a second copy of a 150 KB library: a site that runs this module
    // almost always runs that one, and pulling the sources from a single
    // registry keeps the two pickers drawing the same map.
    $mapcfg = \mod_multicheckin\place_manager::map_config($placeid > 0 ? $placeid : 0);
    if ($mapcfg !== null) {
        $PAGE->requires->js_call_amd('mod_multicheckin/placemap', 'init', [$mapcfg]);
    }

    echo html_writer::link($backurl, get_string('place_backtolist', 'mod_multicheckin'),
        ['class' => 'btn btn-link']);
    echo $OUTPUT->footer();
    exit;
}

// Toolbar.
$addurl = new moodle_url($pageurl, ['action' => 'add']);
echo html_writer::div(
    html_writer::link($addurl, get_string('place_add', 'mod_multicheckin'),
        ['class' => 'btn btn-primary mb-3']),
    ''
);

// Listing.
$places = place_manager::get_all();

if (!$places) {
    echo html_writer::div(get_string('placenone', 'mod_multicheckin'), 'text-muted');
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [
    get_string('placename', 'mod_multicheckin'),
    get_string('placelatitude', 'mod_multicheckin'),
    get_string('placelongitude', 'mod_multicheckin'),
    get_string('placedefaultradius', 'mod_multicheckin'),
    get_string('placeusage', 'mod_multicheckin'),
    get_string('placemodified', 'mod_multicheckin'),
    get_string('actions'),
];
$table->attributes['class'] = 'generaltable';

foreach ($places as $place) {
    $editurl = new moodle_url($pageurl, ['action' => 'edit', 'placeid' => $place->id]);
    $deleteurl = new moodle_url($pageurl, ['action' => 'delete', 'placeid' => $place->id]);

    $name = format_string($place->name);
    if (trim((string) $place->description) !== '') {
        $name .= html_writer::div(format_text($place->description, FORMAT_PLAIN),
            'small text-muted');
    }

    $usage = place_manager::usage_count($place);
    $usagetext = $usage > 0
        ? html_writer::span(get_string('placeusecount', 'mod_multicheckin', $usage),
            'badge bg-secondary')
        : html_writer::span(get_string('placeusezero', 'mod_multicheckin'), 'text-muted');

    $modified = $place->timemodified > 0
        ? userdate($place->timemodified, get_string('strftimedatetimeshort', 'langconfig'))
        : '—';

    $actions = html_writer::link($editurl, get_string('edit'), ['class' => 'me-2'])
        . html_writer::link($deleteurl, get_string('delete'));

    $table->data[] = [
        $name,
        format_float((float) $place->latitude, 6),
        format_float((float) $place->longitude, 6),
        place_manager::format_radius((int) $place->defaultradius),
        $usagetext,
        $modified,
        $actions,
    ];
}

echo html_writer::table($table);

echo html_writer::div(get_string('place_deletenote', 'mod_multicheckin'),
    'small text-muted mt-2');

echo $OUTPUT->footer();
