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
 * Index of all multicheckin instances in a course.
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

$id = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);

require_course_login($course);

$PAGE->set_url('/mod/multicheckin/index.php', ['id' => $id]);
$PAGE->set_title(get_string('modulenameplural', 'mod_multicheckin'));
$PAGE->set_heading(format_string($course->fullname));

$instances = get_all_instances_in_course('multicheckin', $course);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_multicheckin'));

if (!$instances) {
    notice(get_string('thereareno', 'moodle', get_string('modulenameplural', 'mod_multicheckin')),
        new moodle_url('/course/view.php', ['id' => $id]));
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [get_string('name'), get_string('timestart', 'mod_multicheckin')];
foreach ($instances as $instance) {
    $link = new moodle_url('/mod/multicheckin/view.php', ['id' => $instance->coursemodule]);
    $table->data[] = [
        html_writer::link($link, format_string($instance->name)),
        userdate($instance->timestart),
    ];
}
echo html_writer::table($table);

echo $OUTPUT->footer();
