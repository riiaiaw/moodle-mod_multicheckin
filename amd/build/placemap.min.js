/**
 * Classroom map picker for the multicheckin place form.
 *
 * The coordinate fields on that form were a memory test: a teacher had to know
 * a building's latitude and longitude before they could save it, and a wrong
 * number entered once becomes the room every other teacher then picks. This
 * module puts a map above the fields: click to place, drag to fine-tune.
 *
 * Both the basemap registry and the Leaflet bundle live in the geolocation
 * availability plugin, and the server passes what is needed in `config`. When
 * that plugin is absent the module is never booted and the fields behave
 * exactly as before.
 *
 * The coordinate conversion mirrors classes/coord.php and the copies in the
 * availability pickers; validate.sh compares the CRS tables.
 *
 * @module     mod_multicheckin/placemap
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    var CRS = {
        amap: 'gcj02',
        amapkeyed: 'gcj02',
        amapsat: 'gcj02',
        carto: 'wgs84',
        osm: 'wgs84',
        tianditu: 'wgs84'
    };

    var GCJ_A = 6378245.0;
    var GCJ_EE = 0.00669342162296594323;
    var PI = Math.PI;

    function needsCorrection(basemap) {
        return (CRS[basemap] || 'wgs84') === 'gcj02';
    }

    function outOfChina(lat, lng) {
        if (lng < 72.004 || lng > 137.8347) {
            return true;
        }
        if (lat < 0.8293 || lat > 55.8271) {
            return true;
        }
        return false;
    }

    function transformLat(x, y) {
        var ret = -100.0 + 2.0 * x + 3.0 * y + 0.2 * y * y + 0.1 * x * y + 0.2 * Math.sqrt(Math.abs(x));
        ret += (20.0 * Math.sin(6.0 * x * PI) + 20.0 * Math.sin(2.0 * x * PI)) * 2.0 / 3.0;
        ret += (20.0 * Math.sin(y * PI) + 40.0 * Math.sin(y / 3.0 * PI)) * 2.0 / 3.0;
        ret += (160.0 * Math.sin(y / 12.0 * PI) + 320.0 * Math.sin(y * PI / 30.0)) * 2.0 / 3.0;
        return ret;
    }

    function transformLng(x, y) {
        var ret = 300.0 + x + 2.0 * y + 0.1 * x * x + 0.1 * x * y + 0.1 * Math.sqrt(Math.abs(x));
        ret += (20.0 * Math.sin(6.0 * x * PI) + 20.0 * Math.sin(2.0 * x * PI)) * 2.0 / 3.0;
        ret += (20.0 * Math.sin(x * PI) + 40.0 * Math.sin(x / 3.0 * PI)) * 2.0 / 3.0;
        ret += (150.0 * Math.sin(x / 12.0 * PI) + 300.0 * Math.sin(x / 30.0 * PI)) * 2.0 / 3.0;
        return ret;
    }

    function wgs84ToGcj02(lat, lng) {
        if (outOfChina(lat, lng)) {
            return {lat: lat, lng: lng};
        }
        var dlat = transformLat(lng - 105.0, lat - 35.0);
        var dlng = transformLng(lng - 105.0, lat - 35.0);
        var radlat = lat / 180.0 * PI;
        var magic = Math.sin(radlat);
        magic = 1 - GCJ_EE * magic * magic;
        var sqrtmagic = Math.sqrt(magic);
        dlat = (dlat * 180.0) / ((GCJ_A * (1 - GCJ_EE)) / (magic * sqrtmagic) * PI);
        dlng = (dlng * 180.0) / (GCJ_A / sqrtmagic * Math.cos(radlat) * PI);
        return {lat: lat + dlat, lng: lng + dlng};
    }

    function gcj02ToWgs84(lat, lng) {
        if (outOfChina(lat, lng)) {
            return {lat: lat, lng: lng};
        }
        var glat = lat;
        var glng = lng;
        for (var i = 0; i < 30; i++) {
            var fwd = wgs84ToGcj02(glat, glng);
            var dlat = fwd.lat - lat;
            var dlng = fwd.lng - lng;
            glat -= dlat;
            glng -= dlng;
            if (Math.abs(dlat) < 1e-11 && Math.abs(dlng) < 1e-11) {
                break;
            }
        }
        return {lat: glat, lng: glng};
    }

    function wgs84ToBasemap(lat, lng, basemap) {
        return needsCorrection(basemap) ? wgs84ToGcj02(lat, lng) : {lat: lat, lng: lng};
    }

    function basemapToWgs84(lat, lng, basemap) {
        return needsCorrection(basemap) ? gcj02ToWgs84(lat, lng) : {lat: lat, lng: lng};
    }

    // ---- Leaflet loading -------------------------------------------------

    var L = null;

    function loadCss(href) {
        if (document.querySelector('link[href="' + href + '"]')) {
            return;
        }
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        document.head.appendChild(link);
    }

    function ensureLeaflet(base, callback) {
        if (window.L) {
            callback(window.L);
            return;
        }
        var savedDefine = window.define;
        var savedAmd = savedDefine ? savedDefine.amd : null;
        if (savedDefine) {
            window.define = undefined;
        }
        var script = document.createElement('script');
        script.src = base + '/leaflet.js';
        script.onload = function() {
            if (savedDefine) {
                window.define = savedDefine;
                if (savedAmd) {
                    savedDefine.amd = savedAmd;
                }
            }
            callback(window.L);
        };
        script.onerror = function() {
            if (savedDefine) {
                window.define = savedDefine;
                if (savedAmd) {
                    savedDefine.amd = savedAmd;
                }
            }
            // No map: the coordinate inputs remain usable on their own.
        };
        document.head.appendChild(script);
    }

    // ---- Map wiring -------------------------------------------------------

    var state = {
        map: null,
        marker: null,
        circle: null,
        tile: null,
        basemapKey: null,
        lat: null,
        lng: null
    };

    /**
     * Is this URL a 512x512 static-map endpoint rather than a tile endpoint?
     *
     * @param {String} url Tile URL template.
     * @return {Boolean}
     */
    function isStaticMap(url) {
        return url.indexOf('/v3/staticmap') !== -1;
    }

    /**
     * Resolve tile URL options: subdomains and retina handling.
     *
     * @param {String} url Tile URL template.
     * @param {Number} maxzoom Maximum zoom.
     * @return {Object} URL and options.
     */
    function tileOpts(url, maxzoom) {
        var opts = {
            maxZoom: maxzoom || 19,
            detectRetina: false
        };
        if (url.indexOf('autonavi.com') !== -1) {
            opts.subdomains = ['1', '2', '3', '4'];
        } else if (url.indexOf('tianditu.gov.cn') !== -1) {
            opts.subdomains = ['0', '1', '2', '3', '4', '5', '6', '7'];
        } else {
            opts.subdomains = 'abc';
        }
        if (url.indexOf('{r}') !== -1) {
            if (window.devicePixelRatio && window.devicePixelRatio >= 2) {
                opts.detectRetina = true;
            } else {
                url = url.replace('{r}', '');
            }
        }
        return {url: url, opts: opts};
    }

    /**
     * Build the Leaflet layer for a basemap.
     *
     * @param {Object} source Basemap definition.
     * @return {Object} Leaflet layer.
     */
    function buildLayer(source) {
        var t = tileOpts(source.url, source.maxzoom);
        if (isStaticMap(t.url)) {
            return L.tileLayer('', {
                maxZoom: t.opts.maxZoom,
                attribution: source.attribution || '',
                tileSize: 512,
                zoomOffset: 0,
                getTileUrl: function(coords) {
                    var tpl = t.url.replace('{z}', coords.z).replace('{x}', coords.x).replace('{y}', coords.y);
                    var sep = tpl.indexOf('?') === -1 ? '?' : '&';
                    return tpl + sep + '_r=' + Date.now().toString(36) + coords.x + coords.y + coords.z;
                }
            });
        }
        return L.tileLayer(t.url, {
            maxZoom: t.opts.maxZoom,
            attribution: source.attribution || '',
            subdomains: t.opts.subdomains,
            detectRetina: t.opts.detectRetina
        });
    }

    var fieldNames = {
        lat: 'latitude',
        lng: 'longitude',
        radius: 'defaultradius'
    };

    function input(name) {
        return document.querySelector('input[name="' + name + '"]');
    }

    function readRadius() {
        var el = input(fieldNames.radius);
        var r = el ? parseInt(el.value, 10) : NaN;
        return (!isNaN(r) && r > 0) ? r : 200;
    }

    function fillForm(lat, lng) {
        var la = input(fieldNames.lat);
        var lo = input(fieldNames.lng);
        if (la) {
            la.value = lat.toFixed(6);
        }
        if (lo) {
            lo.value = lng.toFixed(6);
        }
    }

    function placeOverlays() {
        if (!state.map || state.lat === null) {
            return;
        }
        var display = wgs84ToBasemap(state.lat, state.lng, state.basemapKey);
        var pos = [display.lat, display.lng];
        if (state.marker) {
            state.marker.setLatLng(pos);
        } else {
            state.marker = L.marker(pos, {draggable: true}).addTo(state.map);
            state.marker.on('dragend', function() {
                var ll = state.marker.getLatLng();
                var wgs = basemapToWgs84(ll.lat, ll.lng, state.basemapKey);
                state.lat = wgs.lat;
                state.lng = wgs.lng;
                fillForm(wgs.lat, wgs.lng);
            });
        }
        var radius = readRadius();
        if (state.circle) {
            state.circle.setLatLng(pos);
            state.circle.setRadius(radius);
        } else {
            state.circle = L.circle(pos, {
                radius: radius,
                color: '#0f6cbf',
                weight: 1,
                fillOpacity: 0.12
            }).addTo(state.map);
        }
    }

    function setBasemap(key, cfg) {
        if (!state.map) {
            return;
        }
        var source = (cfg.basemaps && cfg.basemaps[key]) || null;
        if (!source) {
            for (var k in cfg.basemaps) {
                if (cfg.basemaps.hasOwnProperty(k)) {
                    key = k;
                    source = cfg.basemaps[k];
                    break;
                }
            }
            if (!source) {
                return;
            }
        }
        state.basemapKey = key;
        if (state.tile) {
            state.map.removeLayer(state.tile);
        }
        state.tile = buildLayer(source).addTo(state.map);
        placeOverlays();
    }

    function applyPoint(lat, lng) {
        state.lat = lat;
        state.lng = lng;
        fillForm(lat, lng);
        placeOverlays();
    }

    /**
     * Boot the picker.
     *
     * @param {Object} cfg Server configuration from place_manager::map_config().
     */
    function init(cfg) {
        cfg = cfg || {};
        var panel = document.getElementById(cfg.panelid || 'multicheckin-placemap');
        if (!panel || !cfg.leafletbase) {
            return;
        }
        fieldNames.lat = cfg.latfield || 'latitude';
        fieldNames.lng = cfg.lngfield || 'longitude';
        fieldNames.radius = cfg.radiusfield || 'defaultradius';

        loadCss(cfg.leafletbase + '/leaflet.css');
        ensureLeaflet(cfg.leafletbase, function(leaflet) {
            if (!leaflet) {
                return;
            }
            L = leaflet;
            var hasPoint = cfg.lat !== null && cfg.lat !== undefined && !isNaN(cfg.lat);
            var startLat = hasPoint ? cfg.lat : (cfg.defaultLat || 30.5928);
            var startLng = hasPoint ? cfg.lng : (cfg.defaultLng || 114.3055);
            var basemapKey = cfg.basemap || 'amap';

            var startDisplay = wgs84ToBasemap(startLat, startLng, basemapKey);
            state.map = L.map(panel).setView([startDisplay.lat, startDisplay.lng], hasPoint ? 16 : 12);
            setBasemap(basemapKey, cfg);

            state.map.on('click', function(e) {
                var wgs = basemapToWgs84(e.latlng.lat, e.latlng.lng, state.basemapKey);
                applyPoint(wgs.lat, wgs.lng);
            });

            var rinput = input(fieldNames.radius);
            if (rinput) {
                rinput.addEventListener('change', function() {
                    if (state.circle) {
                        state.circle.setRadius(readRadius());
                    }
                });
            }

            if (hasPoint) {
                applyPoint(cfg.lat, cfg.lng);
            }
        });
    }

    return {
        init: init
    };
});
