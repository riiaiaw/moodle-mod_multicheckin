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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
// The form reaches into the classroom library for its picker and the
// management link. Load the class rather than relying on the autoloader, which
// is not guaranteed to have the component registered on every code path that
// instantiates this form.
require_once(__DIR__ . '/classes/place_manager.php');

/**
 * Module instance settings form.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_multicheckin_mod_form extends moodleform_mod {

    /**
     * Definition.
     *
     * @return void
     */
    protected function definition(): void {
        global $CFG;

        $mform = $this->_form;

        // General.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        // Timing.
        $mform->addElement('header', 'timing', get_string('timing', 'mod_multicheckin'));

        $mform->addElement('date_time_selector', 'timestart',
            get_string('timestart', 'mod_multicheckin'), ['optional' => false]);
        $mform->addHelpButton('timestart', 'timestart', 'mod_multicheckin');

        $mform->addElement('date_time_selector', 'timeend',
            get_string('timeend', 'mod_multicheckin'), ['optional' => false]);

        $mform->addElement('text', 'lategrace', get_string('lategrace', 'mod_multicheckin'), ['size' => 8]);
        $mform->setType('lategrace', PARAM_INT);
        $mform->setDefault('lategrace', 300);
        $mform->addHelpButton('lategrace', 'lategrace', 'mod_multicheckin');

        // Whether the session has an end-of-class check-in at all. Off means a
        // successful sign-in is the entire record: the status is present or
        // late and the early-leave machinery below is skipped, so the mode and
        // grace fields are hidden rather than left to look meaningful.
        $mform->addElement('advcheckbox', 'requiresignout',
            get_string('requiresignout', 'mod_multicheckin'), null, null, [0, 1]);
        $mform->setDefault('requiresignout', 1);
        $mform->addHelpButton('requiresignout', 'requiresignout', 'mod_multicheckin');

        $mform->addElement('text', 'earlygrace', get_string('earlygrace', 'mod_multicheckin'), ['size' => 8]);
        $mform->setType('earlygrace', PARAM_INT);
        $mform->setDefault('earlygrace', 300);
        $mform->addHelpButton('earlygrace', 'earlygrace', 'mod_multicheckin');
        $mform->hideIf('earlygrace', 'requiresignout', 'notchecked');

        $mform->addElement('select', 'earlymode', get_string('earlymode', 'mod_multicheckin'), [
            \mod_multicheckin\checkin_engine::EARLYMODE_WINDOW => get_string('earlymode_window', 'mod_multicheckin'),
            \mod_multicheckin\checkin_engine::EARLYMODE_THRESHOLD => get_string('earlymode_threshold', 'mod_multicheckin'),
        ]);
        $mform->setDefault('earlymode', \mod_multicheckin\checkin_engine::EARLYMODE_WINDOW);
        $mform->addHelpButton('earlymode', 'earlymode', 'mod_multicheckin');
        $mform->hideIf('earlymode', 'requiresignout', 'notchecked');

        // ---- Mid-session check-ins ----
        // The teacher decides how many mid check-ins there are and when each
        // one opens; the manage page still allows per-point fine tuning.
        $midmax = \mod_multicheckin\session_manager::MID_MAX;
        $countoptions = [];
        for ($k = 0; $k <= $midmax; $k++) {
            $countoptions[$k] = $k === 0 ? get_string('none') : (string) $k;
        }

        $mform->addElement('header', 'midcheckins', get_string('midsection', 'mod_multicheckin'));
        $mform->addHelpButton('midcheckins', 'midsection', 'mod_multicheckin');

        $mform->addElement('select', 'midcount',
            get_string('midcount', 'mod_multicheckin'), $countoptions);
        $mform->setDefault('midcount', 0);
        $mform->addHelpButton('midcount', 'midcount', 'mod_multicheckin');

        // A default of zero is the honest one, but a teacher who came here
        // expecting a pre-filled roll call could read an empty section as
        // "this is not supported". One line of text says how to add one.
        //
        // Deliberately not hidden when the count is raised: hideIf() keys off
        // an element's name, and a 'static' element has none, so driving it
        // from midcount is not something the form API guarantees. The wording
        // reads correctly at any count, which needs no JavaScript at all.
        $mform->addElement('static', 'midcounthint', '',
            html_writer::div(get_string('midcount_zero_hint', 'mod_multicheckin'),
                'text-muted small'));

        for ($k = 1; $k <= $midmax; $k++) {
            $mform->addElement('text', "midopen{$k}",
                get_string('midopenlabel', 'mod_multicheckin', $k), ['size' => 6]);
            $mform->setType("midopen{$k}", PARAM_INT);
            if ($k === 1) {
                $mform->addHelpButton("midopen{$k}", 'midopen', 'mod_multicheckin');
            }
            // Only the rows the count actually selects are shown.
            $mform->hideIf("midopen{$k}", 'midcount', 'neq', (string) $k);
        }

        $mform->addElement('text', 'midwindow',
            get_string('midwindow', 'mod_multicheckin'), ['size' => 6]);
        $mform->setType('midwindow', PARAM_INT);
        $mform->setDefault('midwindow', 10);
        $mform->addHelpButton('midwindow', 'midwindow', 'mod_multicheckin');

        // ---- Occurrence series ----
        // One activity carrying a term's attendance. The activity row keeps the
        // shared settings; each generated occurrence gets its own date. The
        // rule lives here and nowhere else - the preview below and the save
        // path both call schedule_planner, so what the teacher sees before
        // submitting is what will actually be created.
        $mform->addElement('header', 'series', get_string('seriessection', 'mod_multicheckin'));
        $mform->addHelpButton('series', 'seriessection', 'mod_multicheckin');

        $mform->addElement('select', 'seriesrepeat', get_string('series_repeat', 'mod_multicheckin'), [
            \mod_multicheckin\schedule_planner::REPEAT_NONE => get_string('series_repeat_none', 'mod_multicheckin'),
            \mod_multicheckin\schedule_planner::REPEAT_WEEKLY => get_string('series_repeat_weekly', 'mod_multicheckin'),
            \mod_multicheckin\schedule_planner::REPEAT_DAILY => get_string('series_repeat_daily', 'mod_multicheckin'),
            \mod_multicheckin\schedule_planner::REPEAT_CUSTOM => get_string('series_repeat_custom', 'mod_multicheckin'),
        ]);
        $mform->setDefault('seriesrepeat', \mod_multicheckin\schedule_planner::REPEAT_NONE);
        $mform->addHelpButton('seriesrepeat', 'series_repeat', 'mod_multicheckin');

        // Weekdays as a checkbox row rather than a multi-select: the teacher
        // picks "Tuesday and Thursday" far more often than any single day, and
        // a multi-select hides that the other days even exist.
        $weekdaygroup = [];
        foreach (\mod_multicheckin\schedule_planner::weekday_options() as $day => $label) {
            $weekdaygroup[] = $mform->createElement('advcheckbox', "serieswd{$day}", '', $label, null, [0, $day]);
        }
        $mform->addGroup($weekdaygroup, 'seriesweekdays',
            get_string('series_weekdays', 'mod_multicheckin'), ' ', false);
        $mform->addHelpButton('seriesweekdays', 'series_weekdays', 'mod_multicheckin');
        $mform->hideIf('seriesweekdays', 'seriesrepeat', 'neq',
            \mod_multicheckin\schedule_planner::REPEAT_WEEKLY);

        $mform->addElement('text', 'seriesinterval',
            get_string('series_interval', 'mod_multicheckin'), ['size' => 6]);
        $mform->setType('seriesinterval', PARAM_INT);
        $mform->setDefault('seriesinterval', 7);
        $mform->addHelpButton('seriesinterval', 'series_interval', 'mod_multicheckin');
        $mform->hideIf('seriesinterval', 'seriesrepeat', 'neq',
            \mod_multicheckin\schedule_planner::REPEAT_DAILY);

        // Dates, not a count: teachers know when term ends, and a date range is
        // self-correcting when a public holiday shifts the schedule.
        //
        // The range and the clock time belong to the two rule-driven modes. In
        // 'custom' the teacher types each date and time on its own row, so the
        // shared fields would be a second, contradictory source of truth; the
        // 'in' condition hides them for both 'none' and 'custom' in one rule.
        // ('in' is supported by the form dependency manager: the PHP side joins
        // the array with '|' and form.js matches the select value against it.)
        $sharedmodes = [
            \mod_multicheckin\schedule_planner::REPEAT_NONE,
            \mod_multicheckin\schedule_planner::REPEAT_CUSTOM,
        ];

        $mform->addElement('date_selector', 'seriesdatefrom',
            get_string('series_datefrom', 'mod_multicheckin'), ['optional' => false]);
        $mform->addHelpButton('seriesdatefrom', 'series_datefrom', 'mod_multicheckin');
        $mform->hideIf('seriesdatefrom', 'seriesrepeat', 'in', $sharedmodes);

        $mform->addElement('date_selector', 'seriesdateto',
            get_string('series_dateto', 'mod_multicheckin'), ['optional' => false]);
        $mform->addHelpButton('seriesdateto', 'series_dateto', 'mod_multicheckin');
        $mform->hideIf('seriesdateto', 'seriesrepeat', 'in', $sharedmodes);

        $mform->addElement('text', 'seriestimefrom',
            get_string('series_timefrom', 'mod_multicheckin'), ['size' => 8]);
        $mform->setType('seriestimefrom', PARAM_TEXT);
        $mform->addHelpButton('seriestimefrom', 'series_timefrom', 'mod_multicheckin');
        $mform->hideIf('seriestimefrom', 'seriesrepeat', 'in', $sharedmodes);

        // ---- Irregular series ('custom') ----
        //
        // The escape hatch the two rule-driven modes cannot provide: a real
        // timetable is not one clock time for the whole term. Rather than
        // growing ever more elaborate rules, the teacher lists the dates. Each
        // row is a plain YYYY-MM-DD and HH:MM pair, written in the site's own
        // clock so the person typing sees what the planner will store.
        //
        // A count field drives how many rows are shown rather than add/remove
        // buttons: it is a single control for both adding and removing, it
        // needs no round trip, and - because it is also the ceiling - it is the
        // one place where the site's occurrence limit is visible to the
        // teacher. Rows beyond the chosen count are hidden by the form's own
        // dependency manager, and the reader on the save path trusts the count
        // and nothing else.
        $maxsessions = \mod_multicheckin\schedule_planner::max_sessions();

        // A select is keyed by its option values, so the list is built with the
        // number on both sides - range() alone would hand the form a 0-based
        // key and quietly offer "0 to 59".
        $countoptions = [];
        for ($i = 1; $i <= $maxsessions; $i++) {
            $countoptions[$i] = $i;
        }

        $mform->addElement('select', 'seriescustomcount',
            get_string('series_customcount', 'mod_multicheckin'), $countoptions);
        $mform->setType('seriescustomcount', PARAM_INT);
        $mform->setDefault('seriescustomcount', \mod_multicheckin\schedule_planner::CUSTOM_DEFAULT_ROWS);
        $mform->addHelpButton('seriescustomcount', 'series_customcount', 'mod_multicheckin');
        $mform->hideIf('seriescustomcount', 'seriesrepeat', 'neq',
            \mod_multicheckin\schedule_planner::REPEAT_CUSTOM);

        // One group per row, so the label, the two inputs and their help all
        // hide together. The placeholder carries the format - it is the only
        // place the expected shape of the value is stated, and a hint that
        // disappears on the first keystroke is exactly right for it.
        $dateplaceholder = get_string('series_customdate_hint', 'mod_multicheckin');
        $timeplaceholder = get_string('series_customtime_hint', 'mod_multicheckin');
        for ($k = 1; $k <= $maxsessions; $k++) {
            $row = [];
            $row[] = $mform->createElement('text', "seriescd{$k}", '',
                ['size' => 12, 'placeholder' => $dateplaceholder]);
            $row[] = $mform->createElement('text', "seriesct{$k}", '',
                ['size' => 8, 'placeholder' => $timeplaceholder]);
            $mform->addGroup($row, "seriesrow{$k}",
                get_string('series_customrow', 'mod_multicheckin', $k), ' ', false);
            $mform->setType("seriescd{$k}", PARAM_TEXT);
            $mform->setType("seriesct{$k}", PARAM_TEXT);

            // A row is worth showing when the mode is 'custom' AND the count
            // reaches it. The dependency manager takes several rules per
            // element and hides when any one of them applies, so the two
            // conditions are two hideIf() calls rather than a single special
            // case: 'mode is not custom', and 'this row is past the count'.
            $mform->hideIf("seriesrow{$k}", 'seriesrepeat', 'neq',
                \mod_multicheckin\schedule_planner::REPEAT_CUSTOM);
            if ($k > 1) {
                $mform->hideIf("seriesrow{$k}", 'seriescustomcount', 'in',
                    implode('|', range(1, $k - 1)));
            }
        }

        // Live preview. A static element is correct here: it holds generated
        // text with no form control of its own, and the script below rewrites
        // its contents as the fields change. (Static elements escape their
        // value, which is why the classroom picker needed real selects - but
        // escaped text is exactly what this slot wants.)
        $mform->addElement('static', 'seriespreview', '',
            html_writer::div(get_string('series_preview_none', 'mod_multicheckin'),
                'text-muted small', ['data-multicheckin-series' => 'preview']));

        // Grade aggregation across occurrences.
        $mform->addElement('header', 'gradeaggregation', get_string('gradesection', 'mod_multicheckin'));
        $mform->addHelpButton('gradeaggregation', 'gradesection', 'mod_multicheckin');

        $mform->addElement('select', 'gradeaggregate', get_string('gradeaggregate', 'mod_multicheckin'), [
            'all' => get_string('gradeaggregate_all', 'mod_multicheckin'),
            'sum' => get_string('gradeaggregate_sum', 'mod_multicheckin'),
            'best' => get_string('gradeaggregate_best', 'mod_multicheckin'),
        ]);
        $mform->setDefault('gradeaggregate', 'all');
        $mform->addHelpButton('gradeaggregate', 'gradeaggregate', 'mod_multicheckin');

        $mform->addElement('text', 'gradebestcount',
            get_string('gradebestcount', 'mod_multicheckin'), ['size' => 6]);
        $mform->setType('gradebestcount', PARAM_INT);
        $mform->setDefault('gradebestcount', 10);
        $mform->addHelpButton('gradebestcount', 'gradebestcount', 'mod_multicheckin');
        $mform->hideIf('gradebestcount', 'gradeaggregate', 'neq', 'best');

        // Self check-in.
        $mform->addElement('header', 'selfcheckin', get_string('selfcheckinsection', 'mod_multicheckin'));

        $mform->addElement('advcheckbox', 'allowselfcheckin',
            get_string('allowselfcheckin', 'mod_multicheckin'), null, null, [0, 1]);
        $mform->setDefault('allowselfcheckin', 1);
        $mform->addHelpButton('allowselfcheckin', 'allowselfcheckin', 'mod_multicheckin');

        // The stricter sibling of the switch above. "Allow self check-in" off
        // only hides the button - the password route in checkin.php has never
        // looked at it. This one closes both, for every point, and cannot be
        // loosened from the point editor: ticking a box may only tighten.
        $mform->addElement('advcheckbox', 'qronly',
            get_string('qronly', 'mod_multicheckin'), null, null, [0, 1]);
        $mform->setDefault('qronly', 0);
        $mform->addHelpButton('qronly', 'qronly', 'mod_multicheckin');

        $mform->addElement('text', 'selfcheckinpassword',
            get_string('selfcheckinpassword', 'mod_multicheckin'), ['size' => 30]);
        $mform->setType('selfcheckinpassword', PARAM_TEXT);
        $mform->addHelpButton('selfcheckinpassword', 'selfcheckinpassword', 'mod_multicheckin');

        // Who the attendance roster is for.
        //
        // Placed in the self check-in section rather than with the grading
        // options: the question it answers is "whose attendance is this", which
        // the teacher decides before anything about points. Multi-select
        // because a seminar may genuinely want the tutor listed alongside the
        // students, and a single choice would have to invent a "students and
        // teachers" label that does not fit the next course.
        //
        // Managers are excluded from the list: an administrator is not a class
        // participant, and including them would put a site-wide account on every
        // sheet in every course.
        $audienceoptions = \mod_multicheckin\audience_manager::get_role_options(self::form_context($this));
        $mform->addElement('select', 'audience',
            get_string('audience', 'mod_multicheckin'), $audienceoptions,
            ['multiple' => 'multiple', 'size' => min(6, max(3, count($audienceoptions)))]);
        $mform->getElement('audience')->setMultiple(true);
        $mform->setDefault('audience', [\mod_multicheckin\audience_manager::DEFAULT_ROLES]);
        $mform->addHelpButton('audience', 'audience', 'mod_multicheckin');
        $mform->setType('audience', PARAM_TEXT);

        // ---- Geofence (anti-proxy) ----
        $mform->addElement('header', 'geofence', get_string('geosection', 'mod_multicheckin'));

        $mform->addElement('select', 'geomode', get_string('geomode', 'mod_multicheckin'),
            \mod_multicheckin\geo_manager::get_modes());
        $mform->setDefault('geomode', \mod_multicheckin\geo_manager::MODE_NONE);
        $mform->addHelpButton('geomode', 'geomode', 'mod_multicheckin');

        $mform->addElement('textarea', 'geoipranges', get_string('geoipranges', 'mod_multicheckin'),
            ['rows' => 4, 'cols' => 40]);
        $mform->setType('geoipranges', PARAM_TEXT);
        $mform->addHelpButton('geoipranges', 'geoipranges', 'mod_multicheckin');
        $mform->hideIf('geoipranges', 'geomode', 'in', ['none', 'gps']);

        // Saved classroom picker. Placed above the coordinate fields so the
        // common case (pick a room) reads first and the manual entry below it
        // stays available as the fallback.
        //
        // This is a native 'select' element, filled from the site library on
        // render. It used to be a hand-built <select> inside a 'static'
        // element, but Moodle renders fstatic through a Mustache {{html}} slot
        // that HTML-escapes its value, so the control arrived as visible text.
        // A native select renders its own options and cannot hit that.
        //
        // The group is hidden in "none"/"ip" modes, matching the coordinate
        // fields: a saved room means nothing when the geofence is not using
        // coordinates at all.
        $savedplaces = $this->saved_places();
        $placeoptions = ['' => get_string('placeselectnone', 'mod_multicheckin')];
        $placepayload = [];
        foreach ($savedplaces as $place) {
            $radius = (int) ($place->defaultradius
                ?: \mod_multicheckin\place_manager::RADIUS_DEFAULT);
            $placeoptions[(string) $place->id] = \mod_multicheckin\place_manager::format_label($place);
            $placepayload[(string) $place->id] = [
                'lat' => format_float((float) $place->latitude, 7),
                'lng' => format_float((float) $place->longitude, 7),
                'radius' => $radius,
            ];
        }

        $mform->addElement('select', 'savedplace',
            get_string('placesection', 'mod_multicheckin'), $placeoptions);
        $mform->setType('savedplace', PARAM_INT);
        $mform->addHelpButton('savedplace', 'placesection', 'mod_multicheckin');
        $mform->hideIf('savedplace', 'geomode', 'in', ['none', 'ip']);

        // The coordinates travel in this hidden field rather than as per-option
        // attributes, which a native select cannot carry.
        $mform->addElement('hidden', 'savedplacedata', json_encode($placepayload));
        $mform->setType('savedplacedata', PARAM_RAW);
        $mform->hideIf('savedplacedata', 'geomode', 'in', ['none', 'ip']);

        // The manage link and the "save current coordinates" button are plain
        // text/controls with no options, so a static element is correct for
        // them: nothing in it needs to stay unescaped.
        $mform->addElement('static', 'placestools', '', $this->places_tools_html());
        $mform->hideIf('placestools', 'geomode', 'in', ['none', 'ip']);

        // Pull a room straight out of the geolocation condition's shared
        // library (availability_gpsloc), so the two plugins do not each
        // maintain a list of the same buildings. Offered only when that
        // plugin is installed and has at least one usable room.
        //
        // This is a real moodleform 'select' element, not a hand-built one
        // wrapped in a 'static' element. A static element looks tempting for
        // the per-option data attributes, but Moodle renders the fstatic
        // template with the value escaped, so the markup arrived as visible
        // text instead of a control. The native select renders the options
        // itself; the coordinates travel in a separate hidden JSON field that
        // the AMD module reads, which is where they were going anyway.
        $sharedplaces = $this->shared_places();
        if ($sharedplaces) {
            $options = ['' => get_string('sharedplacenone', 'mod_multicheckin')];
            $payload = [];
            foreach ($sharedplaces as $place) {
                $radius = (int) ($place->defaultradius
                    ?: \availability_gpsloc\place_manager::RADIUS_DEFAULT);
                $label = format_string($place->name)
                    . ' (' . \availability_gpsloc\place_manager::format_radius($radius) . ')';
                $options[(string) $place->id] = $label;
                $payload[(string) $place->id] = [
                    'lat' => format_float((float) $place->latitude, 7),
                    'lng' => format_float((float) $place->longitude, 7),
                    'radius' => $radius,
                ];
            }

            $mform->addElement('select', 'sharedplace',
                get_string('sharedplaceselect', 'mod_multicheckin'), $options);
            $mform->setType('sharedplace', PARAM_INT);
            $mform->addHelpButton('sharedplace', 'sharedplaceselect', 'mod_multicheckin');
            $mform->hideIf('sharedplace', 'geomode', 'in', ['none', 'ip']);

            $mform->addElement('hidden', 'sharedplacedata', json_encode($payload));
            $mform->setType('sharedplacedata', PARAM_RAW);
            $mform->hideIf('sharedplacedata', 'geomode', 'in', ['none', 'ip']);

            $mform->addElement('static', 'sharedplacehint', '',
                html_writer::div(get_string('sharedplacehint', 'mod_multicheckin'),
                    'small text-muted'));
            $mform->hideIf('sharedplacehint', 'geomode', 'in', ['none', 'ip']);
        } else if (!class_exists('\availability_gpsloc\place_manager')) {
            // The library is unavailable because the geolocation plugin is not
            // installed, so say so once rather than leaving the teacher to
            // wonder why the dropdown other sites show is missing here. When
            // the plugin is present but its library is simply empty the notice
            // is wrong, hence the class_exists test rather than a bare else.
            $mform->addElement('static', 'sharedplacemissing', '',
                html_writer::div(get_string('sharedplaceunavailable', 'mod_multicheckin'),
                    'small text-muted'));
            $mform->hideIf('sharedplacemissing', 'geomode', 'in', ['none', 'ip']);
        }

        // The width on these fields is load-bearing, not cosmetic. The default
        // theme renders form labels as block-level text with the control
        // underneath, and the natural width of the label column is narrow;
        // a long label such as "教室纬度" was clipped to "里纬度" by the
        // overflow rule. Setting an explicit width gives the label room.
        $mform->addElement('text', 'geolat', get_string('geolat', 'mod_multicheckin'),
            ['size' => 16, 'style' => 'width:18rem']);
        $mform->setType('geolat', PARAM_RAW_TRIMMED);
        $mform->setDefault('geolat', 0);
        $mform->addHelpButton('geolat', 'geolat', 'mod_multicheckin');
        $mform->hideIf('geolat', 'geomode', 'in', ['none', 'ip']);

        $mform->addElement('text', 'geolng', get_string('geolng', 'mod_multicheckin'),
            ['size' => 16, 'style' => 'width:18rem']);
        $mform->setType('geolng', PARAM_RAW_TRIMMED);
        $mform->setDefault('geolng', 0);
        $mform->addHelpButton('geolng', 'geolng', 'mod_multicheckin');
        $mform->hideIf('geolng', 'geomode', 'in', ['none', 'ip']);

        $mform->addElement('text', 'georadius', get_string('georadius', 'mod_multicheckin'),
            ['size' => 8, 'style' => 'width:18rem']);
        $mform->setType('georadius', PARAM_INT);
        $mform->setDefault('georadius', 100);
        $mform->addHelpButton('georadius', 'georadius', 'mod_multicheckin');
        $mform->hideIf('georadius', 'geomode', 'in', ['none', 'ip']);

        // Ticked by default: once a fence is configured, a scanned code is the
        // weakest of the three proofs (it only shows the scanner saw a screen)
        // and should not quietly bypass the fence. Unticking it restores the
        // old behaviour for a room where projecting the code is itself the
        // proof. Existing activities keep whatever they already store.
        $mform->addElement('advcheckbox', 'geoenforceqr',
            get_string('geoenforceqr', 'mod_multicheckin'), null, null, [0, 1]);
        $mform->setDefault('geoenforceqr', 1);
        $mform->addHelpButton('geoenforceqr', 'geoenforceqr', 'mod_multicheckin');
        $mform->hideIf('geoenforceqr', 'geomode', 'eq', 'none');

        // The audit page is a diagnostic, not part of taking attendance: it
        // answers "why did the fence let everyone through?". Hiding the link by
        // default keeps the activity page to the things a teacher uses every
        // lesson. The page itself stays reachable by direct URL for anyone who
        // may manage the activity, so switching this on is a convenience, not a
        // permission gate.
        $mform->addElement('advcheckbox', 'showgeoaudit',
            get_string('showgeoaudit', 'mod_multicheckin'), null, null, [0, 1]);
        $mform->setDefault('showgeoaudit', 0);
        $mform->addHelpButton('showgeoaudit', 'showgeoaudit', 'mod_multicheckin');
        $mform->hideIf('showgeoaudit', 'geomode', 'eq', 'none');

        // ---- QR token rotation ----
        $mform->addElement('header', 'qroptions', get_string('qrsection', 'mod_multicheckin'));

        $mform->addElement('text', 'qrrotation', get_string('qrrotation', 'mod_multicheckin'), ['size' => 8]);
        $mform->setType('qrrotation', PARAM_INT);
        $mform->setDefault('qrrotation', \mod_multicheckin\qr_manager::ROTATION);
        $mform->addHelpButton('qrrotation', 'qrrotation', 'mod_multicheckin');
        $mform->addRule('qrrotation', null, 'numeric', null, 'client');

        // Reminders.
        $mform->addElement('header', 'notify', get_string('notifysection', 'mod_multicheckin'));

        $mform->addElement('advcheckbox', 'notifyenabled',
            get_string('notifyenabled', 'mod_multicheckin'), null, null, [0, 1]);
        // Off by default. A check-in activity that starts mailing the whole
        // class the moment it is created is a surprise, and the surprise lands
        // on students rather than on the teacher who could undo it. The teacher
        // who wants reminders ticks this box; nobody else has to notice.
        $mform->setDefault('notifyenabled', 0);
        $mform->addHelpButton('notifyenabled', 'notifyenabled', 'mod_multicheckin');

        $mform->addElement('text', 'notifyleadtime',
            get_string('notifyleadtime', 'mod_multicheckin'), ['size' => 8]);
        $mform->setType('notifyleadtime', PARAM_INT);
        $mform->setDefault('notifyleadtime', 300);
        $mform->addHelpButton('notifyleadtime', 'notifyleadtime', 'mod_multicheckin');
        $mform->disabledIf('notifyleadtime', 'notifyenabled', 'notchecked');

        // Which points remind, and who they remind. Three rules, because the
        // three questions a teacher actually asks are different: "nudge them
        // along the chain", "remind them at every point", and "one reminder at
        // the start of the class is enough".
        $mform->addElement('select', 'notifyrule', get_string('notifyrule', 'mod_multicheckin'), [
            \mod_multicheckin\notify_manager::RULE_CHAIN => get_string('notifyrule_chain', 'mod_multicheckin'),
            \mod_multicheckin\notify_manager::RULE_ALL => get_string('notifyrule_all', 'mod_multicheckin'),
            \mod_multicheckin\notify_manager::RULE_FIRST => get_string('notifyrule_first', 'mod_multicheckin'),
        ]);
        $mform->setDefault('notifyrule', \mod_multicheckin\notify_manager::RULE_CHAIN);
        $mform->addHelpButton('notifyrule', 'notifyrule', 'mod_multicheckin');
        $mform->disabledIf('notifyrule', 'notifyenabled', 'notchecked');

        // Grade.
        $this->standard_grading_coursemodule_elements();

        // ---- Status point values ----
        // Raw points per final status; the gradebook grade is the earned value
        // divided by the largest configured one, times the max grade above.
        $statusfields = [
            'pointspresent' => 2,
            'pointslate' => 1,
            'pointsearly' => 0.5,
            'pointslateearly' => 0.5,
            'pointsabsent' => 0,
            // An approved leave sits between late and present-but-early: the
            // absence is excused, but the session was still missed, so it is
            // deliberately worth less than attending and more than nothing.
            'pointsleave' => 0.5,
        ];
        $mform->addElement('header', 'statuspoints', get_string('statuspointssection', 'mod_multicheckin'));
        $mform->addHelpButton('statuspoints', 'statuspointssection', 'mod_multicheckin');
        foreach ($statusfields as $field => $default) {
            $mform->addElement('text', $field, get_string($field, 'mod_multicheckin'), ['size' => 6]);
            $mform->setType($field, PARAM_FLOAT);
            $mform->setDefault($field, $default);
        }
        $mform->addHelpButton('pointspresent', 'pointspresent', 'mod_multicheckin');

        // Common module settings.
        $this->standard_coursemodule_elements();

        $this->add_action_buttons();

        // Drive the saved-classroom picker. Registered here rather than in a
        // global hook so it only loads on this form.
        //
        // The library travels with the page. The form has already read it - the
        // dropdown is rendered server-side from those same rows - so having the
        // module fetch it again over the web service would be the form asking
        // for something it was just given. That second read is also what used to
        // greet a teacher whose role does not carry the library capability with
        // a permission error the moment the form opened, before they had done
        // anything at all. The service remains the way a room is written, and
        // the module still calls it to refresh the list after a save, where the
        // caller has demonstrably got the capability - the button that leads
        // there is only shown to them.
        global $PAGE;
        $PAGE->requires->js_call_amd('mod_multicheckin/places', 'init', [[
            'savebutton' => get_string('place_savebutton', 'mod_multicheckin'),
            'places' => \mod_multicheckin\place_manager::get_all_for_js(),
            'canedit' => \mod_multicheckin\place_manager::can_edit(),
        ]]);

        // Live preview of the occurrence series. The strings the script needs
        // are passed in rather than baked into the JavaScript, so the module
        // stays translation-free.
        $PAGE->requires->js_call_amd('mod_multicheckin/series', 'init', [[
            'none' => get_string('series_preview_none', 'mod_multicheckin'),
            'hint' => get_string('series_preview_hint', 'mod_multicheckin'),
            'count' => get_string('series_preview_count', 'mod_multicheckin'),
            'more' => get_string('series_preview_more', 'mod_multicheckin'),
            'capped' => get_string('series_preview_capped', 'mod_multicheckin'),
            // Two-digit ISO weekday numbers, so the script and the planner
            // agree on what "Tuesday" means without either translating.
            'max' => \mod_multicheckin\schedule_planner::max_sessions(),
        ]]);
    }

    /**
     * Saved rooms from this plugin's own site library.
     *
     * Read straight off the table so the select can be rendered server-side.
     * The library is small by design (a campus), so the cost is negligible and
     * it keeps the picker working with JavaScript disabled.
     *
     * @return array Records with id/name/latitude/longitude/defaultradius.
     */
    protected function saved_places(): array {
        if (!class_exists('\\mod_multicheckin\\place_manager')) {
            return [];
        }
        try {
            return \mod_multicheckin\place_manager::get_all() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The save-current-coordinates button and the library management link.
     *
     * These carry no dropdown, so a static element renders them correctly: the
     * Mustache escaping that broke the picker does not matter when the content
     * is a button and an anchor rather than raw control markup.
     *
     * The save button is emitted hidden and revealed by the script once it
     * knows whether the user holds the capability — showing it first and hiding
     * it afterwards would flash a control that some users cannot use.
     *
     * @return string HTML.
     */
    protected function places_tools_html(): string {
        $save = html_writer::tag('button', get_string('place_savebutton', 'mod_multicheckin'), [
            'type' => 'button',
            'class' => 'btn btn-outline-secondary ms-2',
            'data-multicheckin-places' => 'save',
            'style' => 'display: none;',
        ]);

        $note = html_writer::div('', 'small text-muted mt-1', [
            'data-multicheckin-places' => 'note',
            'style' => 'display: none;',
        ]);

        $hint = html_writer::div(
            get_string('place_pickerhint', 'mod_multicheckin'),
            'small text-muted mt-1');

        // The library is site-wide, so its management page is one click away
        // from wherever a teacher is already thinking about classrooms. It
        // used to be reachable only from the activity's view page, which meant
        // setting up a geofence and fixing a wrong coordinate happened in two
        // different places with no path between them.
        $caneditplaces = class_exists('\\mod_multicheckin\\place_manager')
            && \mod_multicheckin\place_manager::can_edit();
        if ($caneditplaces) {
            $manageurl = new moodle_url('/mod/multicheckin/places.php',
                ['returnurl' => qualified_me()]);
            $hint .= html_writer::div(
                html_writer::link($manageurl,
                    get_string('place_manage', 'mod_multicheckin'),
                    ['class' => 'small']),
                'mt-1');
        }

        return html_writer::div(
            $save,
            'd-flex align-items-center flex-wrap mb-1',
            ['data-multicheckin-places' => 'picker']
        ) . $note . $hint;
    }

    /**
     * Rooms from the geolocation availability condition's shared library.
     *
     * The two plugins target the same real-world object — a classroom — so
     * maintaining two independent lists means the same room can exist twice
     * with different coordinates, which is exactly the inconsistency the
     * libraries were built to remove. When availability_gpsloc is present this
     * dropdown offers its rooms; manual entry stays available either way.
     *
     * Returns the raw records so the caller can build a native select and a
     * JSON payload together. An earlier version rendered hand-written <option>
     * markup into a 'static' form element; Moodle escapes the fstatic value,
     * so the tags showed up as text on the page.
     *
     * @return array Records with id/name/latitude/longitude/defaultradius.
     */
    protected function shared_places(): array {
        if (!class_exists('\availability_gpsloc\place_manager')) {
            return [];
        }
        try {
            $places = \availability_gpsloc\place_manager::get_all();
        } catch (\Throwable $e) {
            // A broken or mid-upgrade availability plugin must not take this
            // form down with it; fall back to manual entry.
            return [];
        }
        return $places ?: [];
    }

    /**
     * The context the role list should be read from.
     *
     * definition() runs on the "add" path too, where there is no course module
     * yet - so $this->get_context() would throw there. The course context is
     * the one that always exists, and it is also the right source: roles are
     * normally assigned on the course, and the role NAME a teacher sees should
     * be the course's name for it.
     *
     * @param \moodleform_mod $form The form being defined.
     * @return \context
     */
    protected static function form_context($form): \context {
        if (!empty($form->get_course()->id)) {
            return \context_course::instance((int) $form->get_course()->id);
        }
        return \context_system::instance();
    }

    /**
     * Collapse the audience selection back into the stored string.
     *
     * The element is a multi-select, so the submitted value is an array; the
     * column is a single CHAR. Normalising here means every caller of this form
     * - the activity page, the CLI, the restore path - hands lib.php a value it
     * can insert unchanged, and none of them has to remember the column shape.
     *
     * An empty selection becomes the default rather than an empty string: a
     * roster of nobody would silently stop grades from being computed, which is
     * a far worse outcome than quietly meaning "students".
     *
     * @return \stdClass Form data, with audience a normalised string.
     */
    public function get_data() {
        $data = parent::get_data();
        if ($data === null) {
            return null;
        }

        $allowed = array_keys(\mod_multicheckin\audience_manager::get_role_options(self::form_context($this)));
        $data->audience = \mod_multicheckin\audience_manager::normalise($data->audience ?? [], $allowed);

        return $data;
    }

    /**
     * Prepare the audience value for the form.
     *
     * The stored value is one comma-separated string, but a multi-select
     * element expects an array of the selected keys. Converting here keeps the
     * database shape (one column, no join table) and the form shape (an array
     * of selections) each natural, with the translation in one place.
     *
     * @param array $defaultvalues Form defaults, modified in place.
     * @return void
     */
    public function data_preprocessing(&$defaultvalues): void {
        if (!empty($defaultvalues['id'])) {
            $defaultvalues['instance'] = $defaultvalues['id'];
        }

        // Multi-select: expand the stored string into the selected keys.
        if (array_key_exists('audience', $defaultvalues)) {
            $defaultvalues['audience'] = \mod_multicheckin\audience_manager::roles($defaultvalues['audience']);
        }

        // Sensible defaults for a new instance: a 2 hour class starting now.
        if (empty($defaultvalues['timestart'])) {
            $defaultvalues['timestart'] = time();
        }
        if (empty($defaultvalues['timeend'])) {
            $defaultvalues['timeend'] = time() + (2 * HOURSECS);
        }
        if (!isset($defaultvalues['lategrace'])) {
            $defaultvalues['lategrace'] = 300;
        }
        if (!isset($defaultvalues['earlygrace'])) {
            $defaultvalues['earlygrace'] = 300;
        }
        if (!isset($defaultvalues['geomode'])) {
            $defaultvalues['geomode'] = \mod_multicheckin\geo_manager::MODE_NONE;
        }
        if (!isset($defaultvalues['georadius'])) {
            $defaultvalues['georadius'] = 100;
        }
        if (!isset($defaultvalues['qrrotation'])) {
            $defaultvalues['qrrotation'] = \mod_multicheckin\qr_manager::ROTATION;
        }

        // The series' start date and start time follow the activity's own start
        // - "the first session" - rather than a fixed 08:00 and whatever day
        // the form happened to open. A series is grown out of the session the
        // teacher is already looking at, so defaulting to that session's date
        // and clock keeps the two in step; the old 08:00 made every generated
        // occurrence start at an hour nobody had chosen, and an end date that
        // defaulted to today could land before a future start date.
        //
        // Set here rather than with setDefault() in definition() because the
        // start time is only known at this point.
        $firststart = (int) ($defaultvalues['timestart'] ?? 0);
        if (!isset($defaultvalues['seriesdatefrom'])) {
            $defaultvalues['seriesdatefrom'] = \mod_multicheckin\schedule_planner::day_start($firststart);
        }
        if (!isset($defaultvalues['seriestimefrom'])) {
            $defaultvalues['seriestimefrom'] = \mod_multicheckin\schedule_planner::clock_string($firststart);
        }

        // Mid check-ins: prefill from the existing points when editing, so the
        // form shows exactly what is configured even if it was tuned on the
        // manage page afterwards.
        global $DB;
        if (!empty($defaultvalues['id'])) {
            $mids = array_values($DB->get_records('multicheckin_point',
                ['sessionid' => $defaultvalues['id'], 'role' => 'mid'], 'sortorder ASC'));
            $defaultvalues['midcount'] = count($mids);
            foreach ($mids as $i => $p) {
                $defaultvalues['midopen' . ($i + 1)] = (int) round($p->openoffset / 60);
            }
            if ($mids) {
                $defaultvalues['midwindow'] = max(1,
                    (int) round(((int) $mids[0]->closeoffset - (int) $mids[0]->openoffset) / 60));
            }
        } else {
            // A new instance: sign in and sign out only. A mid roll call is a
            // deliberate choice - most sessions do not need one - so the form
            // starts at zero and the hint below says how to add one. The
            // window keeps its default so the control is not blank if the
            // count is raised without touching it.
            $defaultvalues['midcount'] = 0;
            $defaultvalues['midwindow'] = \mod_multicheckin\session_manager::MID_WINDOW_DEFAULT / 60;
        }
    }

    /**
     * Validation.
     *
     * @param array $data Data.
     * @param array $files Files.
     * @return array Errors.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if (!empty($data['timestart']) && !empty($data['timeend'])) {
            if ((int) $data['timeend'] <= (int) $data['timestart']) {
                $errors['timeend'] = get_string('timeend_before_start', 'mod_multicheckin');
            }
        }

        // QR rotation must stay inside the supported range.
        if (isset($data['qrrotation']) && $data['qrrotation'] !== '') {
            $rot = (int) $data['qrrotation'];
            if ($rot < \mod_multicheckin\qr_manager::ROTATION_MIN
                    || $rot > \mod_multicheckin\qr_manager::ROTATION_MAX) {
                $errors['qrrotation'] = get_string('qrrotation_range', 'mod_multicheckin',
                    (object) [
                        'min' => \mod_multicheckin\qr_manager::ROTATION_MIN,
                        'max' => \mod_multicheckin\qr_manager::ROTATION_MAX,
                    ]);
            }
        }

        // Validate every IP range line when an IP fence is enabled.
        $mode = $data['geomode'] ?? \mod_multicheckin\geo_manager::MODE_NONE;
        if ($mode === \mod_multicheckin\geo_manager::MODE_IP
                || $mode === \mod_multicheckin\geo_manager::MODE_BOTH) {
            $raw = (string) ($data['geoipranges'] ?? '');
            foreach (preg_split('/\R/', $raw) as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, '#') === 0) {
                    continue;
                }
                if (\mod_multicheckin\geo_manager::parse_cidr($line) === null) {
                    $errors['geoipranges'] = get_string('geoipranges_invalid', 'mod_multicheckin', $line);
                    break;
                }
            }
        }

        // GPS fence needs a sane centre and radius.
        if ($mode === \mod_multicheckin\geo_manager::MODE_GPS
                || $mode === \mod_multicheckin\geo_manager::MODE_BOTH) {
            $lat = (float) ($data['geolat'] ?? 0);
            $lng = (float) ($data['geolng'] ?? 0);
            if (($lat == 0.0 && $lng == 0.0) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                $errors['geolat'] = get_string('geocoords_invalid', 'mod_multicheckin');
            }
            $radius = (int) ($data['georadius'] ?? 0);
            if ($radius < 10 || $radius > 5000) {
                $errors['georadius'] = get_string('georadius_range', 'mod_multicheckin');
            }
        }

        // Mid check-in plan sanity.
        if (isset($data['midcount'])) {
            $midcount = (int) $data['midcount'];
            if ($midcount < 0 || $midcount > \mod_multicheckin\session_manager::MID_MAX) {
                $errors['midcount'] = get_string('midcount_invalid', 'mod_multicheckin');
            } else {
                for ($k = 1; $k <= $midcount; $k++) {
                    $key = "midopen{$k}";
                    $val = (int) ($data[$key] ?? 0);
                    if ($val < 0 || $val > 2880) {
                        $errors[$key] = get_string('midopen_invalid', 'mod_multicheckin');
                    }
                }
                $midwindowmin = (int) ($data['midwindow'] ?? 0);
                if ($midwindowmin < 1 || $midwindowmin > 480) {
                    $errors['midwindow'] = get_string('midwindow_invalid', 'mod_multicheckin');
                }
            }
        }

        // Status point values must be sane non-negative numbers.
        foreach (['pointspresent', 'pointslate', 'pointsearly', 'pointslateearly',
                'pointsabsent', 'pointsleave'] as $sfield) {
            if (isset($data[$sfield]) && $data[$sfield] !== '') {
                $sval = (float) $data[$sfield];
                if ($sval < 0 || $sval > 1000) {
                    $errors[$sfield] = get_string('statuspoints_invalid', 'mod_multicheckin');
                }
            }
        }

        // Occurrence series. Only validated when a repeat rule is actually
        // selected: a single-session activity must not be blocked by fields it
        // never filled in.
        $repeat = (string) ($data['seriesrepeat'] ?? \mod_multicheckin\schedule_planner::REPEAT_NONE);

        if ($repeat === \mod_multicheckin\schedule_planner::REPEAT_CUSTOM) {
            // Each row is checked with the same parser the planner uses, so the
            // form can never accept a row the planner would silently drop, and
            // can never reject one it would have kept.
            $max = \mod_multicheckin\schedule_planner::max_sessions();
            $count = (int) ($data['seriescustomcount'] ?? 0);
            if ($count < 1 || $count > $max) {
                $errors['seriescustomcount'] = get_string('series_customcount_invalid',
                    'mod_multicheckin', $max);
            }
            $count = max(0, min($count, $max));

            $filled = 0;
            for ($k = 1; $k <= $count; $k++) {
                $rowdate = trim((string) ($data["seriescd{$k}"] ?? ''));
                $rowtime = trim((string) ($data["seriesct{$k}"] ?? ''));

                // A blank row is how the teacher skips one while keeping the
                // rest; it is dropped on save rather than being an error.
                if ($rowdate === '' && $rowtime === '') {
                    continue;
                }

                // The error is keyed by the row's group, because that is the
                // element the form can draw an error against - a group's
                // sub-fields are not elements in their own right.
                if ($rowdate === ''
                        || \mod_multicheckin\schedule_planner::parse_custom_row($rowdate, $rowtime) === null) {
                    $errors["seriesrow{$k}"] = get_string('series_customrow_invalid',
                        'mod_multicheckin', $k);
                    continue;
                }

                $filled++;
            }

            if ($filled === 0 && !isset($errors['seriescustomcount'])) {
                $errors['seriescustomcount'] = get_string('series_customrows_required', 'mod_multicheckin');
            }
        } else if ($repeat !== \mod_multicheckin\schedule_planner::REPEAT_NONE) {
            $from = (int) ($data['seriesdatefrom'] ?? 0);
            $to = (int) ($data['seriesdateto'] ?? 0);
            if ($from > 0 && $to > 0 && $to < $from) {
                $errors['seriesdateto'] = get_string('series_daterange_invalid', 'mod_multicheckin');
            } else if ($from <= 0 || $to <= 0) {
                $errors['seriesdatefrom'] = get_string('series_daterange_invalid', 'mod_multicheckin');
            }

            $rawtime = trim((string) ($data['seriestimefrom'] ?? ''));
            if (!preg_match('/^\d{1,2}[:.]\d{2}$/', $rawtime)) {
                $errors['seriestimefrom'] = get_string('series_timefrom_invalid', 'mod_multicheckin');
            }

            if ($repeat === \mod_multicheckin\schedule_planner::REPEAT_WEEKLY) {
                $picked = false;
                for ($day = 1; $day <= 7; $day++) {
                    if (!empty($data["serieswd{$day}"])) {
                        $picked = true;
                        break;
                    }
                }
                if (!$picked) {
                    $errors['seriesweekdays'] = get_string('series_weekdays_required', 'mod_multicheckin');
                }
            } else if ($repeat === \mod_multicheckin\schedule_planner::REPEAT_DAILY) {
                $interval = (int) ($data['seriesinterval'] ?? 0);
                if ($interval < 1 || $interval > 365) {
                    $errors['seriesinterval'] = get_string('series_interval_invalid', 'mod_multicheckin');
                }
            }
        }

        // Grade aggregation across occurrences. The ceiling follows the
        // configurable generation limit rather than a literal, so raising
        // maxsessions in site admin does not leave a form that refuses a value
        // the planner would happily accept.
        $aggregate = (string) ($data['gradeaggregate'] ?? 'all');
        if ($aggregate === 'best') {
            $best = (int) ($data['gradebestcount'] ?? 0);
            $ceiling = \mod_multicheckin\schedule_planner::HARD_MAX_SESSIONS;
            if ($best < 1 || $best > $ceiling) {
                $errors['gradebestcount'] = get_string('gradebestcount_invalid', 'mod_multicheckin', $ceiling);
            }
        }

        return $errors;
    }

    /**
     * Turn submitted form data into a repeat specification.
     *
     * Static and public so the save path and the test suite both build the
     * spec the same way - a second implementation here would drift from the
     * form's validation and produce a series the preview never showed.
     *
     * @param mixed $data Form data (object or array).
     * @return array Specification for schedule_planner::plan().
     */
    public static function repeat_spec($data): array {
        $arr = (array) $data;

        $weekdays = [];
        for ($day = 1; $day <= 7; $day++) {
            if (!empty($arr["serieswd{$day}"])) {
                $weekdays[] = $day;
            }
        }

        return [
            'repeat' => (string) ($arr['seriesrepeat'] ?? \mod_multicheckin\schedule_planner::REPEAT_NONE),
            'weekdays' => $weekdays,
            'interval' => (int) ($arr['seriesinterval'] ?? 1),
            'datefrom' => (int) ($arr['seriesdatefrom'] ?? 0),
            'dateto' => (int) ($arr['seriesdateto'] ?? 0),
            'timefrom' => (string) ($arr['seriestimefrom'] ?? ''),
            'timestart' => (int) ($arr['timestart'] ?? 0),
            'timeend' => (int) ($arr['timeend'] ?? 0),
        ];
    }
}
