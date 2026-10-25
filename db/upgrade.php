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
 * Upgrade steps for mod_multicheckin.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the mod_multicheckin plugin.
 *
 * All steps are idempotent: they check for existence before adding, so the
 * script is safe to run repeatedly.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_multicheckin_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // v1.1.0 — early-leave modes, self check-in, QR support and reminders.
    if ($oldversion < 2026042001) {

        // --- New fields on the main session table. ---
        $table = new xmldb_table('multicheckin');

        $fields = [
            new xmldb_field('earlymode', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'window', 'syncattendance'),
            new xmldb_field('allowselfcheckin', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'earlymode'),
            new xmldb_field('selfcheckinpassword', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'allowselfcheckin'),
            new xmldb_field('notifyenabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'selfcheckinpassword'),
            new xmldb_field('notifyleadtime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '300', 'notifyenabled'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // --- New fields on the check-in point table. ---
        $ptable = new xmldb_table('multicheckin_point');

        $pfields = [
            new xmldb_field('qrcodekey', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'qrcode'),
            new xmldb_field('qrcodetime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'qrcodekey'),
            new xmldb_field('notify', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'qrcodetime'),
        ];
        foreach ($pfields as $field) {
            if (!$dbman->field_exists($ptable, $field)) {
                $dbman->add_field($ptable, $field);
            }
        }

        // --- New reminder tracking table. ---
        $ntable = new xmldb_table('multicheckin_notify');
        if (!$dbman->table_exists($ntable)) {
            $ntable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $ntable->add_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $ntable->add_field('pointid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $ntable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $ntable->add_field('timesent', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $ntable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $ntable->add_index('sessionid-pointid-userid', XMLDB_INDEX_UNIQUE, ['sessionid', 'pointid', 'userid']);
            $ntable->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $dbman->create_table($ntable);
        }

        upgrade_mod_savepoint(true, 2026042001, 'multicheckin');
    }

    // v1.2.0 — geofence (anti-proxy) + configurable QR token rotation.
    if ($oldversion < 2026042002) {

        // --- Geofence + QR rotation fields on the main session table. ---
        $table = new xmldb_table('multicheckin');

        $fields = [
            new xmldb_field('qrrotation', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '15', 'notifyleadtime'),
            new xmldb_field('geomode', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'none', 'qrrotation'),
            new xmldb_field('geoipranges', XMLDB_TYPE_TEXT, null, null, null, null, null, 'geomode'),
            new xmldb_field('geolat', XMLDB_TYPE_NUMBER, '10, 7', null, XMLDB_NOTNULL, null, '0', 'geoipranges'),
            new xmldb_field('geolng', XMLDB_TYPE_NUMBER, '10, 7', null, XMLDB_NOTNULL, null, '0', 'geolat'),
            new xmldb_field('georadius', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '100', 'geolng'),
            new xmldb_field('geoenforceqr', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'georadius'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // --- Geofence audit trail table. ---
        $gtable = new xmldb_table('multicheckin_geo_log');
        if (!$dbman->table_exists($gtable)) {
            $gtable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $gtable->add_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $gtable->add_field('pointid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $gtable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $gtable->add_field('method', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'self');
            $gtable->add_field('geomode', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'none');
            $gtable->add_field('allowed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $gtable->add_field('reason', XMLDB_TYPE_CHAR, '100', null, null, null, null);
            $gtable->add_field('ip', XMLDB_TYPE_CHAR, '45', null, null, null, null);
            $gtable->add_field('latitude', XMLDB_TYPE_NUMBER, '10, 7', null, null, null, null);
            $gtable->add_field('longitude', XMLDB_TYPE_NUMBER, '10, 7', null, null, null, null);
            $gtable->add_field('accuracy', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null);
            $gtable->add_field('distance', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null);
            $gtable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $gtable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $gtable->add_index('sessionid-userid', XMLDB_INDEX_NOTUNIQUE, ['sessionid', 'userid']);
            $gtable->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
            $dbman->create_table($gtable);
        }

        upgrade_mod_savepoint(true, 2026042002, 'multicheckin');
    }

    if ($oldversion < 2026042003) {
        // Site-wide library of named classroom locations (v1.3.0).
        //
        // Teachers were previously retyping the same classroom coordinates into
        // every new activity, and two people typing the same room from memory
        // disagree by tens of metres. One shared list fixes that.
        $ptable = new xmldb_table('multicheckin_place');

        if (!$dbman->table_exists($ptable)) {
            $ptable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $ptable->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $ptable->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $ptable->add_field('latitude', XMLDB_TYPE_NUMBER, '10, 7', null, XMLDB_NOTNULL, null, '0');
            $ptable->add_field('longitude', XMLDB_TYPE_NUMBER, '10, 7', null, XMLDB_NOTNULL, null, '0');
            $ptable->add_field('defaultradius', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '100');
            $ptable->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $ptable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $ptable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $ptable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $ptable->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
            $ptable->add_index('name', XMLDB_INDEX_NOTUNIQUE, ['name']);

            $dbman->create_table($ptable);
        }

        upgrade_mod_savepoint(true, 2026042003, 'multicheckin');
    }

    // v1.4.0 — teacher-configurable point values per final status.
    //
    // The values were previously hardcoded in checkin_engine (2 / 1 / 0.5 /
    // 0.5 / 0). Storing them per instance lets a teacher decide how much a
    // late arrival or an early departure costs without touching code, and the
    // gradebook normalisation divides by the highest configured value, so
    // "present" still maps to the full activity grade.
    if ($oldversion < 2026042004) {

        $table = new xmldb_table('multicheckin');

        $fields = [
            new xmldb_field('pointspresent', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, '2', 'grade'),
            new xmldb_field('pointslate', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, '1', 'pointspresent'),
            new xmldb_field('pointsearly', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, '0.5', 'pointslate'),
            new xmldb_field('pointslateearly', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, '0.5', 'pointsearly'),
            new xmldb_field('pointsabsent', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, '0', 'pointslateearly'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_mod_savepoint(true, 2026042004, 'multicheckin');
    }

    // v1.6.6 — classroom map picker and the shared-library bridge.
    //
    // No schema change: the coordinates have always been stored as WGS-84 in
    // multicheckin_place, and the picker only writes into the same two fields
    // a teacher could always type into. The version exists so an upgraded site
    // reloads the AMD build (a new placemap module) and so the new language
    // strings are installed.
    if ($oldversion < 2026042005) {
        upgrade_mod_savepoint(true, 2026042005, 'multicheckin');
    }

    // v1.6.7 — normalise the component name used by the savepoints.
    //
    // Every savepoint must pass the full component name, "mod_multicheckin":
    // Moodle reads the leading segment as the plugin type, and a savepoint
    // recorded under a different name is not recognised as belonging to this
    // plugin. The 2026042001 step had drifted to the bare "multicheckin"
    // while the later ones used the full name; all six now agree.
    //
    // Nothing in the database needs changing. This step exists so a site
    // upgrading from any earlier version records a fresh savepoint under the
    // one component name that every other mechanism (get_string, the file
    // API, message providers, the availability bridge) already uses.
    if ($oldversion < 2026042006) {
        upgrade_mod_savepoint(true, 2026042006, 'multicheckin');
    }

    // v1.6.8 — component restored to the full name.
    //
    // v1.6.7 briefly declared the bare "multicheckin", which Moodle cannot
    // map to a plugin type. The component is back to "mod_multicheckin"; this
    // step only bumps the version so a site that installed the broken v1.6.7
    // is offered the corrected one instead of being told it is up to date.
    if ($oldversion < 2026042007) {
        upgrade_mod_savepoint(true, 2026042007, 'multicheckin');
    }

    // v1.6.9 — Moodle 5.x public/ directory support.
    //
    // From 5.1 the web-accessible tree moved into public/, so this plugin now
    // lives at public/mod/multicheckin/ and the authoritative config.php sits
    // one level ABOVE public/. Every page used a bare '../../config.php',
    // which on a 5.x tree resolves to public/config.php - not the file that
    // defines the site. The pages now probe both layouts with __DIR__ and use
    // whichever config.php is readable, so one package works on 4.x and 5.x.
    //
    // No schema change; the version bump is what makes Moodle offer this build
    // to a site already running an earlier one.
    if ($oldversion < 2026042008) {
        upgrade_mod_savepoint(true, 2026042008, 'multicheckin');
    }

    // v1.6.10 — the classroom library class is now loaded explicitly.
    //
    // places.php and mod_form.php call into \mod_multicheckin\place_manager but
    // only required lib.php, which does not pull the class in. The management
    // page is opened straight by URL, where nothing has primed the autoloader
    // for this component yet, and it died with "class place_manager not found"
    // before it could render. Both files now require
    // classes/place_manager.php up front, so the class is present regardless of
    // how the page was reached.
    //
    // No schema change; the bump makes Moodle offer the corrected build.
    if ($oldversion < 2026042009) {
        upgrade_mod_savepoint(true, 2026042009, 'multicheckin');
    }

    // v1.6.11 — every savepoint above now passes the BARE module name.
    //
    // upgrade_mod_savepoint() is a thin wrapper:
    //     upgrade_plugin_savepoint($result, $version, 'mod', $modname)
    // so the component it looks up is built as 'mod' . '_' . $modname. The
    // earlier steps here passed the full frankenstyle name 'mod_multicheckin',
    // which produced the doubled component 'mod_mod_multicheckin'. Moodle could
    // not find a directory for that component and aborted the whole upgrade
    // with pluginnotexist. The correct third argument is exactly
    // 'multicheckin'; the helper adds the prefix itself.
    //
    // Note this is the opposite of $plugin->component in version.php, which
    // must stay the full 'mod_multicheckin' so the plugin type is readable.
    //
    // No schema change; the bump makes Moodle offer the corrected build.
    if ($oldversion < 2026042010) {
        upgrade_mod_savepoint(true, 2026042010, 'multicheckin');
    }

    // v1.6.12 — the plugin no longer hardcodes its own folder in require paths.
    //
    // Every page loaded lib.php and the library class through
    //     $CFG->dirroot . '/mod/multicheckin/...'
    // On a 4.x site that happens to be right. On 5.2 the plugin lives under
    // public/mod/multicheckin/, $CFG->dirroot is the site root, and the path
    // points at a directory that does not exist: require_once fails to find
    // the file, the class is never defined, and the page reports
    // "class place_manager not found". The requires now resolve through
    // __DIR__, which is correct under either layout. One unqualified
    // place_manager:: call in the form (no use statement) was corrected too.
    //
    // No schema change; the bump makes Moodle offer the corrected build.
    if ($oldversion < 2026042011) {
        upgrade_mod_savepoint(true, 2026042011, 'multicheckin');
    }

    // v1.6.13 — the classroom dropdowns are native form elements.
    //
    // Both pickers (this plugin's own library and the shared one from
    // availability_gpsloc) were built as hand-written <select> markup dropped
    // into a 'static' form element. Moodle renders that element through a
    // Mustache {{html}} slot, which HTML-escapes its value, so the page showed
    // an empty box and the option tags as literal text underneath. They are now
    // real 'select' elements, which render their own options, with the
    // coordinates carried in a companion hidden JSON field keyed by option
    // value - a native select cannot hold per-option data attributes.
    //
    // No schema change; the bump makes Moodle offer the corrected build.
    if ($oldversion < 2026042012) {
        upgrade_mod_savepoint(true, 2026042012, 'multicheckin');
    }

    // v1.7.0 — one activity can now hold a whole series of occurrences.
    //
    // Until now an activity WAS one attendance event: cm->instance pointed at
    // the single multicheckin row and that row was the session. Setting up a
    // term meant creating a dozen activities by hand. The multicheckin table
    // gains three self-referencing columns so the same row can act either as
    // the activity's configuration template or as one occurrence in a series:
    //
    //   parentid  0 = template (what cm->instance always pointed at, and still
    //             does); > 0 = the template this occurrence belongs to
    //   seriesid  the batch an occurrence was generated in, so a whole series
    //             can be listed or deleted without walking the parent chain
    //   seqno     the occurrence's ordinal, shown as "Occurrence N"
    //
    // Existing data needs no migration. Every current row has parentid = 0
    // after the defaults are applied, which is exactly the "template is the
    // only occurrence" case, so an upgraded site behaves identically until the
    // teacher generates a series. Deliberately no new table: an occurrence
    // carries the same timing, grace and points fields as the template, and
    // splitting them would add a join plus a field-sync problem to every read.
    if ($oldversion < 2026042013) {
        $table = new xmldb_table('multicheckin');

        $fields = [
            new xmldb_field('parentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'geoenforceqr'),
            new xmldb_field('seriesid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'parentid'),
            new xmldb_field('seqno', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1', 'seriesid'),
            // How the occurrences combine into the one gradebook score; see
            // grade_aggregator for why the activity keeps a single item.
            new xmldb_field('gradeaggregate', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'all', 'seqno'),
            new xmldb_field('gradebestcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '10', 'gradeaggregate'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Listing an activity's occurrences is a filter on parentid, so it is
        // worth its own index; the field is low-cardinality on a quiet site but
        // every view / manage / report page hits it.
        $index = new xmldb_index('parentid', XMLDB_INDEX_NOTUNIQUE, ['parentid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_mod_savepoint(true, 2026042013, 'multicheckin');
    }

    // v1.7.4 - the sign-out check-in becomes optional.
    //
    // Not every course takes attendance at the end of class. On those sites a
    // student who signs in is done, and marking them as having left early
    // because there is no checkout is simply wrong. requiresignout turns the
    // sign-out point inert: it stops gating "absent" and stops driving the
    // early-leave decision, leaving a status of present or late.
    //
    // Default 1 so every existing activity keeps exactly the behaviour it has
    // today; only a teacher who turns it off sees a change.
    if ($oldversion < 2026042017) {
        $table = new xmldb_table('multicheckin');
        $field = new xmldb_field('requiresignout', XMLDB_TYPE_INTEGER, '1', null,
            XMLDB_NOTNULL, null, '1', 'gradebestcount');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026042017, 'multicheckin');
    }

    // v1.7.8 - two changes that go together.
    //
    // 1. QR check-ins join the fence by default. A rotating code proves the
    //    scanner saw a screen, not that the screen was in the classroom, and on
    //    most sites the code is projected for a room full of people who are
    //    already there. The column default moves to 1 so an activity created
    //    from here on is fenced unless the teacher opts out. Existing rows keep
    //    whatever they hold, which is 0 from v1.7.0 onwards, so nothing a
    //    running course relies on changes underneath it.
    //
    // 2. The audit page becomes opt-in. It is a debugging aid for the case
    //    described in the plugin README - "the fence looks like it does
    //    nothing" - so it should not clutter every activity view. The link is
    //    driven by showgeoaudit, defaulting to 0, and the page itself is
    //    unchanged and still reachable by anyone who may manage the activity.
    if ($oldversion < 2026042021) {
        $table = new xmldb_table('multicheckin');

        $field = new xmldb_field('geoenforceqr', XMLDB_TYPE_INTEGER, '1', null,
            XMLDB_NOTNULL, null, '1', 'georadius');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_default($table, $field);
        }

        $auditfield = new xmldb_field('showgeoaudit', XMLDB_TYPE_INTEGER, '1', null,
            XMLDB_NOTNULL, null, '0', 'geoenforceqr');
        if (!$dbman->field_exists($table, $auditfield)) {
            $dbman->add_field($table, $auditfield);
        }

        upgrade_mod_savepoint(true, 2026042021, 'multicheckin');
    }

    // v1.7.9 - the roster becomes a setting.
    //
    // Until now every roster in the plugin was "enrolled, may check in", and the
    // shipped capability grants that to teachers and managers as well as
    // students. The consequence was that a teacher's own name sat on the
    // attendance sheet, in the report and in the gradebook, and had to be
    // excused by hand every lesson.
    //
    // The new column names the roles that count as participants, and it
    // defaults to 'student'. Unlike the v1.7.8 default move, this one is
    // deliberately applied to EXISTING rows too: leaving them unset would keep
    // every running course showing the teacher on the sheet, which is the exact
    // behaviour the option exists to remove. The column default and the
    // backfill therefore agree, and both say 'student'.
    //
    // The backfill only touches rows that are still empty. A site that has
    // somehow already set a value - or a future version that stores something
    // else - is left alone, so re-running this step cannot flatten a choice.
    if ($oldversion < 2026042022) {
        $table = new xmldb_table('multicheckin');

        $audiencefield = new xmldb_field('audience', XMLDB_TYPE_CHAR, '255', null,
            XMLDB_NOTNULL, null, 'student', 'showgeoaudit');
        if (!$dbman->field_exists($table, $audiencefield)) {
            $dbman->add_field($table, $audiencefield);
        }

        // Backfill: every activity created before this option existed becomes
        // "students only", which is what the option means by default.
        $DB->set_field_select('multicheckin', 'audience', 'student',
            "audience IS NULL OR audience = ?", ['']);

        upgrade_mod_savepoint(true, 2026042022, 'multicheckin');
    }

    // v1.9.0 adds the leave status.
    //
    // Two pieces. A new per-status point value, so "on leave" scores whatever
    // the teacher decides (0.5 by default) exactly like every other status
    // already does; and a table holding the leave itself. The leave cannot live
    // in multicheckin_log: that table records a check-in event against a point,
    // whereas a leave is a session-level declaration that no point can express,
    // and it also needs to carry a reason and a review flag.
    //
    // Existing rows need nothing done to them: the new column takes its default
    // on read (points_for_status() falls back to 0.5 when the field is absent),
    // and no existing row can have a leave, so the table starts empty.
    if ($oldversion < 2026042025) {
        $table = new xmldb_table('multicheckin');

        $leavefield = new xmldb_field('pointsleave', XMLDB_TYPE_NUMBER, '10, 5', null,
            XMLDB_NOTNULL, null, '0.5', 'pointsabsent');
        if (!$dbman->field_exists($table, $leavefield)) {
            $dbman->add_field($table, $leavefield);
        }

        $leavetable = new xmldb_table('multicheckin_leave');

        $leavetable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $leavetable->add_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $leavetable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $leavetable->add_field('reason', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $leavetable->add_field('origin', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'student');
        $leavetable->add_field('reviewed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $leavetable->add_field('reviewedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $leavetable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $leavetable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $leavetable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $leavetable->add_key('sessionid', XMLDB_KEY_FOREIGN, ['sessionid'], 'multicheckin', ['id']);

        $leavetable->add_index('sessionid-userid', XMLDB_INDEX_UNIQUE, ['sessionid', 'userid']);
        $leavetable->add_index('sessionid-reviewed', XMLDB_INDEX_NOTUNIQUE, ['sessionid', 'reviewed']);

        if (!$dbman->table_exists($leavetable)) {
            $dbman->create_table($leavetable);
        }

        upgrade_mod_savepoint(true, 2026042025, 'multicheckin');
    }

    // v1.10.0 - a description of the occurrence itself, and the freedom to
    // retitle one.
    //
    // The activity already had an intro, but that belongs to the ACTIVITY: it
    // is rendered at the top of view.php and, more to the point, it is one of
    // the fields sync_occurrence_settings() copies from the template into every
    // occurrence. Storing a per-occurrence note there would mean the next sync
    // silently overwrote it. So the occurrence gets its own column, next to the
    // intro it deliberately does not share.
    //
    // Plain text on purpose: the teacher asked for a text box, and a format
    // column that is always FORMAT_PLAIN would be a column that lies. Nothing
    // is backfilled - a description starts absent, and an absent description
    // renders as nothing at all.
    if ($oldversion < 2026042026) {
        $table = new xmldb_table('multicheckin');

        $descriptionfield = new xmldb_field('description', XMLDB_TYPE_TEXT, null, null,
            null, null, null, 'introformat');
        if (!$dbman->field_exists($table, $descriptionfield)) {
            $dbman->add_field($table, $descriptionfield);
        }

        upgrade_mod_savepoint(true, 2026042026, 'multicheckin');
    }

    // v1.11.0 - the occurrence description is edited in the site's rich text
    // editor (TinyMCE), and embedded images are stored as files.
    //
    // A description therefore needs the format column every Moodle text field
    // has. That column is not decoration: the two formats read the same stored
    // string in different ways. FORMAT_PLAIN escapes the value and turns its
    // newlines into <br>, which is how every description written so far is
    // displayed; FORMAT_HTML hands the value to the text filters, where a
    // newline is insignificant whitespace - two paragraphs would collapse into
    // one line - and a literal '<' would be read as the start of a tag.
    //
    // So this is the one column that MUST be backfilled rather than left at its
    // own default. The default (FORMAT_HTML) is right for rows written from now
    // on and wrong for every row that already exists, because every one of them
    // was typed into a plain textarea. Marking them FORMAT_PLAIN changes no
    // stored text at all and keeps them rendering exactly as their author wrote
    // them; the moment a teacher reopens one in the editor it is saved as HTML
    // like any other.
    //
    // The WHERE clause is what makes the statement safe to reason about: it
    // only ever moves a row that is still sitting on the column default, so it
    // can neither touch a rich-text row nor be undone by running twice.
    if ($oldversion < 2026042028) {
        $table = new xmldb_table('multicheckin');

        $formatfield = new xmldb_field('descriptionformat', XMLDB_TYPE_INTEGER, '4', null,
            XMLDB_NOTNULL, null, '1', 'description');
        if (!$dbman->field_exists($table, $formatfield)) {
            $dbman->add_field($table, $formatfield);
        }

        // Deliberately outside the field_exists() test above: a site that
        // already has the column (a re-run, or a column added by hand) still
        // needs its pre-rich-text rows marked plain.
        $DB->set_field_select('multicheckin', 'descriptionformat', FORMAT_PLAIN,
            'descriptionformat = ?', [FORMAT_HTML]);

        upgrade_mod_savepoint(true, 2026042028, 'multicheckin');
    }

    // v2.0.0 - a check-in may have to be proved with a photograph taken on the
    // spot, and a student who cannot take one can ask the teacher to verify it
    // by hand.
    //
    // The switch lives on the occurrence, beside the other per-occurrence
    // settings, and defaults to 0. That default is the whole migration story:
    // nothing that already runs changes until a teacher ticks the box on a
    // session, so no row is touched and no column is backfilled. (Contrast
    // v1.11.0, where descriptionformat DID have to be rewritten for existing
    // rows, because the new column's default disagreed with what the old rows
    // meant. Here the default and the old meaning are the same thing: off.)
    //
    // The requests table is the counterpart of multicheckin_leave, with one
    // deliberate difference: it is keyed per POINT, not per occurrence. A
    // photograph proves one check-in, and a sign-in and a sign-out are two
    // separate proofs, so a student who cannot photograph twice raises two
    // requests.
    if ($oldversion < 2026101400) {
        $table = new xmldb_table('multicheckin');

        $photofield = new xmldb_field('photorequired', XMLDB_TYPE_INTEGER, '1', null,
            XMLDB_NOTNULL, null, '0', 'requiresignout');
        if (!$dbman->field_exists($table, $photofield)) {
            $dbman->add_field($table, $photofield);
        }

        // The write-through to mod_attendance is gone as of 2.0.0: the two
        // occurrence columns that configured it and the per-result "already
        // pushed" flag are dropped along with it. Each step is guarded, because
        // 1.11.1 shipped this same removal - a site that took that release and
        // then this one must come up cleanly instead of failing on a field that
        // is already gone.
        //
        // The index goes first. It is built on attendanceid, and dropping a
        // column out from under a live index fails on most database engines.
        $oldindex = new xmldb_index('attendanceid', XMLDB_INDEX_NOTUNIQUE, ['attendanceid']);
        if ($dbman->index_exists($table, $oldindex)) {
            $dbman->drop_index($table, $oldindex);
        }

        foreach (['attendanceid', 'syncattendance'] as $column) {
            $gonefield = new xmldb_field($column);
            if ($dbman->field_exists($table, $gonefield)) {
                $dbman->drop_field($table, $gonefield);
            }
        }

        $resulttable = new xmldb_table('multicheckin_result');
        $syncedfield = new xmldb_field('synced');
        if ($dbman->field_exists($resulttable, $syncedfield)) {
            $dbman->drop_field($resulttable, $syncedfield);
        }

        $reqtable = new xmldb_table('multicheckin_photoreq');

        $reqtable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $reqtable->add_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $reqtable->add_field('pointid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $reqtable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $reqtable->add_field('reason', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $reqtable->add_field('status', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $reqtable->add_field('reviewedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $reqtable->add_field('timereviewed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $reqtable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $reqtable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $reqtable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $reqtable->add_key('sessionid', XMLDB_KEY_FOREIGN, ['sessionid'], 'multicheckin', ['id']);

        $reqtable->add_index('sessionid-pointid-userid', XMLDB_INDEX_UNIQUE, ['sessionid', 'pointid', 'userid']);
        $reqtable->add_index('sessionid-status', XMLDB_INDEX_NOTUNIQUE, ['sessionid', 'status']);

        if (!$dbman->table_exists($reqtable)) {
            $dbman->create_table($reqtable);
        }

        upgrade_mod_savepoint(true, 2026101400, 'multicheckin');
    }

    // 2.0.2 - the two scheduled tasks stop scanning the whole table.
    //
    // send_reminders runs every minute and looks for occurrences that are in
    // progress with reminders on: "notifyenabled = 1 AND timestart <= :now AND
    // timeend >= :now". finalise_sessions runs hourly and looks for occurrences
    // that ended within the last week: "timeend < :now AND timeend > :since".
    // Neither predicate had an index behind it, so both were a sequential read
    // of every occurrence on the site, and the first of them ran 1440 times a
    // day. The table is small enough that this was never the slowest thing on
    // the site, but it is a full scan that repeats on a clock, and the fix is
    // two index definitions.
    //
    // Two, not one, because the two predicates do not agree on their leading
    // column. The reminder query has an equality on notifyenabled, so it can
    // pin that column first and then walk timestart; the finalise query ranges
    // on timeend alone and cannot use a composite that starts with a flag.
    //
    // Both steps are guarded, so a site that already carries either index comes
    // up cleanly instead of failing on a duplicate name.
    if ($oldversion < 2026101402) {
        $table = new xmldb_table('multicheckin');

        $reminderindex = new xmldb_index('notifyenabled-timestart-timeend',
            XMLDB_INDEX_NOTUNIQUE, ['notifyenabled', 'timestart', 'timeend']);
        if (!$dbman->index_exists($table, $reminderindex)) {
            $dbman->add_index($table, $reminderindex);
        }

        $finaliseindex = new xmldb_index('timeend', XMLDB_INDEX_NOTUNIQUE, ['timeend']);
        if (!$dbman->index_exists($table, $finaliseindex)) {
            $dbman->add_index($table, $finaliseindex);
        }

        upgrade_mod_savepoint(true, 2026101402, 'multicheckin');
    }

    // Which points of an occurrence send a reminder.
    //
    // Until now the answer was fixed in code: the first point sent nothing, and
    // every later point mailed the students who had checked in at the point
    // before it. That is one of three rules a teacher may now choose, so the
    // choice needs a column. The default is the rule that was in force, except
    // for the first point - which starts reminding, because "the first check-in
    // sends a reminder" is the point of the chain rule as it is written.
    //
    // Guarded like every other step: a site that already carries the column
    // (re-run upgrade, restored database) comes up cleanly instead of failing.
    if ($oldversion < 2026101408) {
        $table = new xmldb_table('multicheckin');

        $field = new xmldb_field('notifyrule', XMLDB_TYPE_CHAR, '10', null,
            XMLDB_NOTNULL, null, 'chain', 'notifyleadtime');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026101408, 'multicheckin');
    }

    // Two switches for "this point accepts QR check-ins only".
    //
    // The teacher asked for check-in points that only accept the code shown on
    // the classroom screen, so the check-in button stops working for students.
    // The occurrence-level column closes every point of an occurrence at once;
    // the point-level column closes one point. Both default to 0, so every
    // activity that already exists keeps running exactly as it did: this step
    // adds two integers and changes no behaviour until a teacher ticks a box.
    //
    // Note what "only" has to mean for it to be true. Hiding the button is not
    // enough - the password path in checkin.php ignores allowselfcheckin today,
    // so a student who is told the password could still sign in. Both paths are
    // closed by qr_manager::only_scan(), which is the single place that decides.
    //
    // Guarded like every other step: a site that already carries the column
    // (re-run upgrade, restored database) comes up cleanly instead of failing.
    if ($oldversion < 2026101411) {
        $table = new xmldb_table('multicheckin');

        $field = new xmldb_field('qronly', XMLDB_TYPE_INTEGER, '1', null,
            XMLDB_NOTNULL, null, '0', 'allowselfcheckin');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('multicheckin_point');

        $field = new xmldb_field('qronly', XMLDB_TYPE_INTEGER, '1', null,
            XMLDB_NOTNULL, null, '0', 'qrcode');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026101411, 'multicheckin');
    }

    // 2.0.23 - the capture permit moves out of the session and into a table.
    //
    // The permit used to be an entry in $SESSION->multicheckin_photopass, and
    // the token in the URL was merely that array's key. That made the "copy
    // this link into a real browser" hint on photo.php impossible to honour:
    // the student copies the link out of WeChat and opens it in Safari, which
    // is a different MoodleSession, so the array is empty there and the page
    // reports the permit as expired - immediately, every time, no matter how
    // fast they are. Nothing about the QR rotation had anything to do with it.
    //
    // A row in a table is the smallest thing that both browsers can read, so
    // that is what this adds. Two details are load-bearing rather than merely
    // stored: tokenhash keeps sha256 of the token instead of the token itself,
    // and userid records WHO the permit is for - which is what makes a link
    // that now works in any browser still useless to the classmate it gets
    // forwarded to.
    //
    // No foreign key on sessionid. Four code paths delete an occurrence
    // (observer, session_manager, lib.php, series.php) and a constraint would
    // turn any one of them missing a child delete into a database error. The
    // rows live ten minutes and cleanup is by expiry, so a constraint would buy
    // nothing and could only break deletion.
    if ($oldversion < 2026101423) {
        $passtable = new xmldb_table('multicheckin_pass');

        $passtable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $passtable->add_field('tokenhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $passtable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $passtable->add_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $passtable->add_field('pointid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $passtable->add_field('method', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'self');
        $passtable->add_field('clientgeo', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $passtable->add_field('expires', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $passtable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $passtable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        $passtable->add_index('tokenhash', XMLDB_INDEX_UNIQUE, ['tokenhash']);
        $passtable->add_index('userid-expires', XMLDB_INDEX_NOTUNIQUE, ['userid', 'expires']);
        $passtable->add_index('sessionid', XMLDB_INDEX_NOTUNIQUE, ['sessionid']);
        $passtable->add_index('expires', XMLDB_INDEX_NOTUNIQUE, ['expires']);

        if (!$dbman->table_exists($passtable)) {
            $dbman->create_table($passtable);
        }

        upgrade_mod_savepoint(true, 2026101423, 'multicheckin');
    }

    // 2.0.24 - reminders stop being on by default.
    //
    // Until now a newly created activity mailed every student about every
    // check-in point it had, before anyone had told it anything about the
    // class. Changing the column default to 0 makes silence the state a teacher
    // never has to think about, and the box on the form the deliberate act.
    //
    // Existing rows are deliberately NOT touched. The box was on by default
    // until now, so every stored 1 is either a choice someone made or a value
    // nobody has looked at; an upgrade that flipped them would silently stop
    // reminders for the classes that depend on them. That is worse than one
    // unwanted message: a reminder that disappears leaves no trace at all,
    // while a reminder that arrives can be turned off in two clicks.
    //
    // Only the default for rows created from now on changes. There is no UPDATE
    // in this block, and there must not be one.
    if ($oldversion < 2026101424) {
        $table = new xmldb_table('multicheckin');
        $field = new xmldb_field('notifyenabled', XMLDB_TYPE_INTEGER, '1', null,
            XMLDB_NOTNULL, null, '0', 'selfcheckinpassword');

        // notifyenabled leads the notifyenabled-timestart-timeend index that
        // 2.0.2 put there, and database_manager::check_field_dependencies()
        // refuses ANY field change on a column that takes part in an index. It
        // refuses before it looks at what the change is, so a change that only
        // touches the default - something no index could care about - throws
        // ddldependencyerror just the same. The index comes off first and goes
        // back afterwards; there is no other legal route.
        //
        // 2.1.1 shipped without the drop and threw on the first site that tried
        // to upgrade, at this line. The guard of the day asked whether the call
        // was present. It was present. Being present is not being legal.
        $reminderindex = new xmldb_index('notifyenabled-timestart-timeend',
            XMLDB_INDEX_NOTUNIQUE, ['notifyenabled', 'timestart', 'timeend']);
        if ($dbman->index_exists($table, $reminderindex)) {
            $dbman->drop_index($table, $reminderindex);
        }

        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_default($table, $field);
        }

        // The guard is on the index, not on whether this run was the one that
        // removed it: a run that died between the drop and this line leaves a
        // site with no reminder index, and the next upgrade is the only thing
        // that will ever notice.
        if (!$dbman->index_exists($table, $reminderindex)) {
            $dbman->add_index($table, $reminderindex);
        }

        // Where the per-occurrence settling mark is kept.
        //
        // The finalise task used to recompute every occurrence that had ended in
        // the past week, every hour, forever. The obvious fix - skip an
        // occurrence whose result rows are all newer than its end time - is a
        // trap: a run that dies after writing thirty of sixty students leaves
        // thirty rows stamped after timeend, and every later run reads that as
        // "already done" and skips the thirty it never reached. A safety net
        // that stops working precisely when something went wrong is the one
        // kind you must not build.
        //
        // A mark the task sets only after a run completes has neither problem:
        // it cannot be inferred from data it also writes, and a failed run
        // leaves it at zero so the next run tries again. Existing rows start at
        // zero, so the first run after upgrading settles everything still inside
        // the window once - which is what it was doing anyway.
        $finalised = new xmldb_field('timefinalised', XMLDB_TYPE_INTEGER, '10', null,
            XMLDB_NOTNULL, null, '0', 'timemodified');
        if (!$dbman->field_exists($table, $finalised)) {
            $dbman->add_field($table, $finalised);
        }

        upgrade_mod_savepoint(true, 2026101424, 'multicheckin');
    }

    return true;
}
