/**
 * Classroom location picker for the activity form.
 *
 * Sits above the coordinate fields and does two things:
 *
 *   1. Selecting a saved room fills geolat / geolng / georadius, so a teacher
 *      never has to type or memorise coordinates.
 *   2. "Save current" stores whatever is currently in those fields into the
 *      shared library, so the next teacher can pick it instead of measuring it
 *      again.
 *
 * It deliberately never invents coordinates. If the fields are empty, saving is
 * refused rather than storing (0, 0) as if it were a place. Manual entry keeps
 * working exactly as before: this module only writes into the same fields the
 * teacher can already type into, so a site that never opens the dropdown loses
 * nothing.
 *
 * @module     mod_multicheckin/places
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/str', 'core/notification'], function($, Ajax, Str, Notification) {

    /** @type {Object|null} Cached library, keyed by nothing — one shared list. */
    var places = null;

    /** @type {Object} Language strings, resolved once. */
    var L = {};

    /** @type {Array} Keys fetched from the language pack. */
    var STRINGKEYS = [
        'place_choosedots',
        'place_usedot',
        'place_saving',
        'place_saved',
        'place_nocoords',
        'place_newname',
        'place_nameprompt',
        'place_dupewarn',
        'place_savebutton'
    ];

    /**
     * Set when this module is the one writing the coordinate fields.
     *
     * Selecting a room fills lat/lng and then fires change so moodleform
     * notices. Without this guard the change handler would treat that as the
     * teacher typing by hand, rebuild the list and reset the dropdown to the
     * placeholder — silently undoing the selection that caused it.
     *
     * @type {Boolean}
     */
    var writing = false;

    /**
     * Find the form fields, wherever they ended up in the rendered form.
     *
     * moodleform wraps each element in a container whose id encodes the field
     * name, and the suffix has changed between Moodle versions, so the inputs
     * are matched on their name attribute instead of on a container id. That
     * works for every element type used here.
     *
     * @return {Object} jQuery handles for the fields this module drives.
     */
    function fields() {
        return {
            lat: $('input[name="geolat"]'),
            lng: $('input[name="geolng"]'),
            radius: $('input[name="georadius"]'),
            mode: $('select[name="geomode"]'),
            // This plugin's own saved-room library. Server-rendered, then
            // refreshed by the module after a save so a new room appears
            // without a reload.
            saved: $('select[name="savedplace"]'),
            saveddata: $('input[name="savedplacedata"]'),
            // The shared library dropdown and its coordinate payload. Both are
            // absent when availability_gpsloc is not installed.
            shared: $('select[name="sharedplace"]'),
            shareddata: $('input[name="sharedplacedata"]')
        };
    }

    /**
     * Whether the geofence currently needs coordinates.
     *
     * The picker is hidden in "none" and "ip" modes because a stored room is
     * meaningless there, and showing it would invite a teacher to save a place
     * that has no effect on anything.
     *
     * @param {Object} f Field handles.
     * @return {Boolean}
     */
    function modeNeedsCoords(f) {
        var mode = f.mode.length ? f.mode.val() : '';
        return mode === 'gps' || mode === 'both';
    }

    /**
     * Show or hide the whole picker to match the selected geofence mode.
     *
     * moodleform already carries hideIf rules for every field this module
     * touches, and those run on the mode change by themselves. Doing it here as
     * well would fight them: hideIf would hide the field and this would show it
     * back, leaving the picker visible in a mode where a room means nothing.
     * The wrapper is left to the form; the module only reacts to a selection.
     *
     * @param {Object} f Field handles.
     */
    function syncVisibility(f) {
        // Intentionally a no-op kept for call-site clarity. The visibility of
        // the saved-room select, the shared select and the coordinate fields is
        // governed entirely by the form's hideIf rules.
        return f;
    }

    /**
     * Render the dropdown options from the cached library.
     *
     * @param {Object} f Field handles.
     * @param {Number} selectedid Option to preselect, 0 for none.
     */
    function renderOptions(f, selectedid) {
        var $select = f.saved;
        if (!$select.length) {
            return;
        }

        var current = f.lat.val() + '|' + f.lng.val();

        $select.empty();
        $select.append($('<option>').val('').text(L.place_choosedots));

        // Rebuild the coordinate payload alongside the options. A native select
        // cannot carry per-option attributes, so the values live in a hidden
        // JSON field keyed by option value; it has to be refreshed in step with
        // the list or a freshly saved room would be un-pickable until reload.
        var payload = {};

        $.each(places || [], function(i, p) {
            var key = p.lat + '|' + p.lng;
            var label = p.label;
            // Mark the room whose coordinates are already in the form, so a
            // teacher reopening an activity can see which one was used.
            if (selectedid && p.id === selectedid) {
                label = label + ' ' + L.place_usedot;
            } else if (!selectedid && key === current && f.lat.val() !== '') {
                label = label + ' ' + L.place_usedot;
            }
            $select.append($('<option>').val(p.id).text(label));
            payload[String(p.id)] = {
                lat: p.lat,
                lng: p.lng,
                radius: p.defaultradius
            };
        });

        f.saveddata.val(JSON.stringify(payload));

        if (selectedid) {
            $select.val('' + selectedid);
        }
    }

    /**
     * Show or hide the "save current coordinates" button.
     *
     * The button is rendered hidden and revealed only to someone who may write
     * to the library, so nobody is offered a control that would always be
     * refused. Both paths that fill the picker call this: the library handed
     * over with the page, and the service answer after a save.
     *
     * @param {Boolean} canedit Whether the user may write to the library.
     */
    function setCanEdit(canedit) {
        var $btn = $('[data-multicheckin-places="save"]');
        if (canedit) {
            $btn.show();
        } else {
            $btn.hide();
        }
    }

    /**
     * Load the library over the web service and render it.
     *
     * Reached after a save, or on a page that did not hand the library in with
     * its markup. The first paint does not come through here: the dropdown is
     * rendered server-side from the same rows, so opening the form costs no
     * request at all.
     *
     * @param {Object} f Field handles.
     * @return {Object} jQuery promise.
     */
    function load(f) {
        return Ajax.call([{
            methodname: 'mod_multicheckin_list_places',
            args: {}
        }])[0].then(function(result) {
            places = result.places || [];
            renderOptions(f, 0);
            setCanEdit(result.canedit);

            return places;
        }).catch(Notification.exception);
    }

    /**
     * Apply a saved room to the coordinate fields.
     *
     * @param {Object} f Field handles.
     */
    function applySelected(f) {
        var id = parseInt(f.saved.val(), 10);
        if (!id) {
            return;
        }

        var place = null;
        $.each(places || [], function(i, p) {
            if (p.id === id) {
                place = p;
                return false;
            }
            return true;
        });
        if (!place) {
            return;
        }

        writing = true;
        f.lat.val(place.lat);
        f.lng.val(place.lng);
        if (place.defaultradius > 0) {
            f.radius.val(place.defaultradius);
        }

        // Show the note, if the room carries one, in the picker's own hint area.
        var $note = $('[data-multicheckin-places="note"]');
        if (place.description) {
            $note.text(place.description).show();
        } else {
            $note.text('').hide();
        }

        // Tell moodleform something changed so its dirty-state tracking and any
        // dependent hideIf rules re-evaluate. writing is cleared in a timeout
        // because jQuery runs these handlers synchronously but moodleform may
        // queue its own work behind them.
        f.lat.trigger('change');
        f.radius.trigger('change');
        window.setTimeout(function() {
            writing = false;
        }, 0);
    }

    /**
     * Copy a room from the geolocation condition's shared library into the
     * coordinate fields.
     *
     * The coordinates arrive in a hidden JSON field rendered alongside the
     * select, keyed by option value. They used to ride on the option's data
     * attributes, which meant hand-building the <option> markup on the server;
     * Moodle escapes a 'static' element, so those tags surfaced as text. A
     * native select cannot carry per-option attributes, so the data travels
     * separately instead. A second web service call would be redundant: the
     * payload already crossed the wire with the form.
     *
     * @param {Object} f Field handles.
     * @param {Object} $select The shared-library dropdown.
     */
    function applyShared(f, $select) {
        var id = $select.val();
        if (!id) {
            return;
        }
        var payload = {};
        try {
            payload = JSON.parse(f.shareddata.val() || '{}');
        } catch (e) {
            return;
        }
        var room = payload[String(id)];
        if (!room) {
            return;
        }

        var lat = parseFloat(room.lat);
        var lng = parseFloat(room.lng);
        if (isNaN(lat) || isNaN(lng)) {
            return;
        }

        writing = true;
        f.lat.val(lat);
        f.lng.val(lng);
        var radius = parseInt(room.radius, 10);
        if (!isNaN(radius) && radius > 0) {
            f.radius.val(radius);
        }
        f.lat.trigger('change');
        f.radius.trigger('change');
        window.setTimeout(function() {
            writing = false;
        }, 0);
    }

    /**
     * Save the coordinates currently in the form as a new library entry.
     *
     * @param {Object} f Field handles.
     */
    function saveCurrent(f) {
        var lat = parseFloat(f.lat.val());
        var lng = parseFloat(f.lng.val());

        // Refuse to store a place with no coordinates. Storing (0, 0) would
        // later present as a real option in the dropdown and silently place a
        // geofence in the Atlantic.
        if (isNaN(lat) || isNaN(lng) || (lat === 0 && lng === 0)) {
            Notification.addNotification({
                message: L.place_nocoords,
                type: 'error'
            });
            return;
        }

        var name = window.prompt(L.place_nameprompt, '');
        if (name === null) {
            return;
        }
        name = (name || '').replace(/^\s+|\s+$/g, '');
        if (name === '') {
            Notification.addNotification({
                message: L.place_newname,
                type: 'error'
            });
            return;
        }

        // Warn about an existing room with the same name, but allow it: two
        // labs in different buildings can legitimately share a name. The check
        // is on name only, since an exact coordinate match would already be
        // visible in the dropdown.
        var clash = null;
        $.each(places || [], function(i, p) {
            if (p.name.toLowerCase() === name.toLowerCase()) {
                clash = p;
                return false;
            }
            return true;
        });
        if (clash && !window.confirm(L.place_dupewarn.replace('{$a}', clash.name))) {
            return;
        }

        var $btn = $('[data-multicheckin-places="save"]');
        $btn.prop('disabled', true).text(L.place_saving);

        Ajax.call([{
            methodname: 'mod_multicheckin_save_place',
            args: {
                id: 0,
                name: name,
                description: '',
                lat: lat,
                lng: lng,
                defaultradius: parseInt(f.radius.val(), 10) || 0
            }
        }])[0].then(function(result) {
            $btn.prop('disabled', false).text(L.place_savebutton);

            // Add to the cached list and reselect it, so the teacher sees the
            // room they just created is now the active choice.
            places = places || [];
            places.push(result.place);
            places.sort(function(a, b) {
                return a.name.localeCompare(b.name);
            });
            renderOptions(f, result.id);

            Notification.addNotification({
                message: L.place_saved,
                type: 'success'
            });

            return result;
        }).catch(function(err) {
            // Re-enable the button before reporting, otherwise a validation
            // failure leaves the form in a state the teacher cannot retry from.
            $btn.prop('disabled', false).text(L.place_savebutton);
            Notification.exception(err);
        });
    }

    return {
        /**
         * Wire up the picker.
         *
         * @param {Object} config Server-provided configuration.
         * @param {String} config.savebutton Label restored after a save.
         * @param {Array} [config.places] Library as the server read it, so the
         *                                first paint needs no request.
         * @param {Boolean} [config.canedit] Whether the user may write to it.
         */
        init: function(config) {
            var f = fields();

            // Nothing to drive on a page without the geofence fields.
            if (!f.lat.length || !f.lng.length) {
                return;
            }

            config = config || {};

            Str.get_strings($.map(STRINGKEYS.concat(['place_savebutton']), function(k) {
                return {key: k, component: 'mod_multicheckin'};
            })).then(function(strs) {
                $.each(STRINGKEYS, function(i, k) {
                    L[k] = strs[i];
                });
                L.place_savebutton = config.savebutton || strs[STRINGKEYS.length];

                syncVisibility(f);

                // Re-check visibility whenever the mode changes, since that is
                // what decides whether a coordinate is meaningful at all.
                f.mode.on('change', function() {
                    syncVisibility(f);
                });

                f.saved.on('change', function() {
                    applySelected(f);
                });

                // Rooms from the geolocation condition's library. Rendered by
                // the server, so no service call is involved and the module
                // stays a thin copy step.
                f.shared.on('change', function() {
                    applyShared(f, $(this));
                });

                $('[data-multicheckin-places="save"]').on('click', function(e) {
                    e.preventDefault();
                    saveCurrent(f);
                });

                // If the teacher edits the coordinates by hand, the dropdown
                // selection no longer describes them, so reset it to the
                // placeholder rather than showing a stale choice. Skipped when
                // we are the ones writing, otherwise picking a room would
                // immediately deselect itself.
                f.lat.add(f.lng).on('change', function() {
                    if (writing) {
                        return;
                    }
                    renderOptions(f, 0);
                });

                // The rows the server rendered the dropdown from, handed over
                // with the page. Using them means the form opens without a
                // request - and, which is why this branch exists, without the
                // permission error that the second read used to produce for a
                // teacher whose role carries the ability to edit the activity
                // but not to maintain the site-wide library.
                if (config.places) {
                    places = config.places;
                    renderOptions(f, 0);
                    setCanEdit(!!config.canedit);

                    return places;
                }

                return load(f);
            }).catch(Notification.exception);
        }
    };
});
