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
 * Camera capture for the evidence photograph.
 *
 * This is the one page a student reaches on a phone, often through an in-app
 * browser, and the photograph is the only proof the check-in happened. The code
 * below was moved here verbatim from an inline <script> - an inline script
 * cannot be covered by a CSP hash or nonce, and the payload it carried includes
 * the watermark text (course, session and room names), which is teacher-written
 * content going straight into a page.
 *
 * The configuration arrives in a data-config attribute on #multicheckin-photo-config
 * rather than being interpolated into the page: maxedge, quality, the watermark
 * string and every message the flow can show.
 *
 * Three behaviours worth knowing before changing anything here:
 *
 *   1. The shutter starts disabled and only becomes usable once a stream is
 *      actually playing, because a button that captures nothing reads as broken.
 *   2. Constraints are offered only if the track reports them in getCapabilities();
 *      applyConstraints() with an unsupported constraint throws and would take
 *      the whole capture down.
 *   3. The stream is released on pagehide and beforeunload. A camera that stays
 *      on after the page is gone leaves its recording light on, which on a
 *      student's phone looks exactly like being recorded.
 *
 * @module     mod_multicheckin/photo_capture
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    /** @type {string} Element carrying the capture configuration. */
    var CONFIG_ID = 'multicheckin-photo-config';

    return {
        /**
         * Start the capture flow.
         *
         * @return {void}
         */
        init: function() {
            var holder = document.getElementById(CONFIG_ID);
            if (!holder) {
                return;
            }

            var cfg;
            try {
                cfg = JSON.parse(holder.getAttribute('data-config'));
            } catch (e) {
                // Without a configuration there is nothing to capture into; the
                // page still shows the fallback instructions.
                return;
            }
            var video = document.getElementById('multicheckin-photo-video');
            var canvas = document.getElementById('multicheckin-photo-canvas');
            var field = document.getElementById('multicheckin-photo-data');
            var form = document.getElementById('multicheckin-photo-form');
            var shoot = document.getElementById('multicheckin-photo-shoot');
            var sw = document.getElementById('multicheckin-photo-switch');
            var status = document.getElementById('multicheckin-photo-status');
            var fallback = document.getElementById('multicheckin-photo-fallback');
            var stage = document.getElementById('multicheckin-photo-stage');
            var stream = null;
            // The facing we are asking for. 'environment' is the rear camera: a student
            // photographing a classroom wants to show the room, not their own face.
            var mode = 'environment';
            // Every video input the browser admits to, in its own order. Only used when
            // facingMode turns out to be a request the browser ignores.
            var cams = [];
            var camindex = 0;
            var busy = false;

            function fail(message) {
                if (status) { status.textContent = message; }
                if (shoot) { shoot.disabled = true; }
                if (fallback) { fallback.style.display = 'block'; }
            }

            function ready() {
                if (status) { status.textContent = cfg.strings.ready; }
                if (shoot) { shoot.disabled = false; }
            }

            function stop() {
                if (stream) {
                    stream.getTracks().forEach(function(track) { track.stop(); });
                    stream = null;
                }
            }

            function say(message) {
                if (status) { status.textContent = message; }
            }

            function track() {
                return (stream && stream.getVideoTracks) ? stream.getVideoTracks()[0] : null;
            }

            // What the camera says it can do. Every constraint below is offered only if
            // it appears here first: applyConstraints with an unsupported constraint is
            // rejected, and an unhandled rejection at this point leaves a black
            // rectangle where the preview should be.
            function caps() {
                var t = track();
                if (!t || !t.getCapabilities) { return {}; }
                try { return t.getCapabilities() || {}; } catch (e) { return {}; }
            }

            // The frame size is not known until the stream reports it, and the shutter
            // must not go live before there is a frame to capture.
            function whenSized(fn) {
                if (!video) { return; }
                if (video.videoWidth) { fn(); return; }
                var handler = function() {
                    video.removeEventListener('loadedmetadata', handler);
                    fn();
                };
                video.addEventListener('loadedmetadata', handler);
            }

            function facingOf(s) {
                var track = (s && s.getVideoTracks) ? s.getVideoTracks()[0] : null;
                if (!track || !track.getSettings) { return ''; }
                try { return track.getSettings().facingMode || ''; } catch (e) { return ''; }
            }

            function deviceOf(s) {
                var track = (s && s.getVideoTracks) ? s.getVideoTracks()[0] : null;
                if (!track || !track.getSettings) { return ''; }
                try { return track.getSettings().deviceId || ''; } catch (e) { return ''; }
            }

            // Rear first, but "first" and not "only": `ideal` degrades to whatever camera
            // exists, while `exact` would make the page fail outright on a laptop with no
            // rear camera - the one machine a teacher is most likely to test on.
            //
            // The size is asked for in 4:3 on purpose. Left alone, a browser hands over
            // the smallest mode that satisfies the request - often 640x480 - and the
            // picture then has too few pixels to read a face or a screen. Asking for
            // 16:9 instead would be worse: a 4:3 sensor delivering 16:9 crops the sides
            // off, which is the opposite of the wide view this page is for.
            function byFacing(which) {
                return navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: {ideal: which},
                        width: {ideal: 1920},
                        height: {ideal: 1440}
                    },
                    audio: false
                });
            }

            function byDevice(index) {
                return navigator.mediaDevices.getUserMedia({
                    video: {
                        deviceId: {exact: cams[index].deviceId},
                        width: {ideal: 1920},
                        height: {ideal: 1440}
                    },
                    audio: false
                });
            }

            function refreshCams() {
                if (!navigator.mediaDevices.enumerateDevices) { cams = []; return; }
                navigator.mediaDevices.enumerateDevices().then(function(list) {
                    cams = list.filter(function(d) { return d.kind === 'videoinput'; });
                    var here = deviceOf(stream);
                    for (var i = 0; i < cams.length; i++) {
                        if (here && cams[i].deviceId === here) { camindex = i; }
                    }
                    if (sw) {
                        if (cams.length > 1) {
                            sw.style.display = '';
                            sw.disabled = false;
                        } else {
                            sw.style.display = 'none';
                            sw.disabled = true;
                        }
                    }
                }).catch(function() { cams = []; });
            }

            // Everything that depends on the camera having a frame to describe. Runs
            // again after a camera switch, because the front and the rear camera have
            // different limits.
            function afterStream() {
                refreshCams();
                openWide();
                askContinuous();
                fitPreview();
                ready();
            }

            function adopt(s) {
                stream = s;
                video.srcObject = s;
                video.play();
                whenSized(afterStream);
            }

            // ---- The lens ------------------------------------------------------

            // Ask for the wide end of whatever zoom range the camera reports.
            //
            // Not "stay where the camera already is", which is what 2.0.19 did. That
            // assumption only survives on a single camera - and a single camera has no
            // zoom range at all, so it never reaches this branch. A phone with several
            // rear cameras reports 0.5x/1x/2x/5x as one range and boots on a middle
            // stop, often 2x: the picture is then a corner of the room rather than the
            // room. Nothing on screen looks wrong, so the default stop is the last
            // thing anyone suspects.
            //
            // This is not a control: the student never sees it and cannot move it. It
            // is the page choosing the lens that fits the scene, once, when the camera
            // opens - the same thing the phone's own camera app does at 1x.
            //
            // A camera that refuses stays where it is. There is nothing to fall back
            // to now that the crop-and-scale zoom is gone, and a picture taken at the
            // wrong stop is still a picture of the student.
            function openWide() {
                var t = track();
                if (!t || !t.applyConstraints) { return; }
                var range = caps().zoom;
                if (!range || typeof range.min !== 'number'
                        || typeof range.max !== 'number' || range.max <= range.min) {
                    return;
                }
                t.applyConstraints({zoom: range.min}).catch(function() {});
            }

            // ---- Focus ---------------------------------------------------------

            // Continuous focus is asked for explicitly because not every rear camera
            // starts there: some phones come up focused at infinity, which is sharp for
            // a building and soft for a sign two metres away.
            function askContinuous() {
                var t = track();
                if (!t || !t.applyConstraints) { return; }
                var modes = caps().focusMode;
                if (!modes || modes.indexOf('continuous') === -1) { return; }
                t.applyConstraints({advanced: [{focusMode: 'continuous'}]}).catch(function() {});
            }

            // Tap to focus on one point: a single-shot on the spot that was tapped,
            // handing back to continuous two seconds later so the next picture is not
            // stuck on the last one. A browser that does not know about points of
            // interest simply keeps its continuous focus.
            function focusAt(clientX, clientY) {
                var t = track();
                if (!t || !t.applyConstraints || !stage) { return; }
                var c = caps();
                var modes = c.focusMode;
                if (!modes || modes.indexOf('single-shot') === -1 || !c.pointsOfInterest) { return; }
                var rect = stage.getBoundingClientRect();
                if (!rect.width || !rect.height) { return; }
                // Where the tap landed inside the window, 0 to 1.
                var wx = Math.min(1, Math.max(0, (clientX - rect.left) / rect.width));
                var wy = Math.min(1, Math.max(0, (clientY - rect.top) / rect.height));
                // Then where that is inside the FRAME. The window shows a 4:3 rectangle cut
                // out of the frame, so the same number is not the same place any more:
                // passing the tap through unchanged would focus above or below the thing
                // that was tapped, by exactly as much as the crop took off. Handing a 16:9
                // frame through untouched loses a sixth of the height, which is enough to
                // focus on the wall behind someone's head - and the status line still says
                // "focused", so nothing looks wrong.
                var crop = cropBox(video.videoWidth, video.videoHeight);
                if (!crop.w || !crop.h || !video.videoWidth || !video.videoHeight) { return; }
                var x = (crop.x + wx * crop.w) / video.videoWidth;
                var y = (crop.y + wy * crop.h) / video.videoHeight;
                t.applyConstraints({advanced: [{
                    focusMode: 'single-shot',
                    pointsOfInterest: [{x: x, y: y}]
                }]}).then(function() {
                    say(cfg.strings.focused);
                    window.setTimeout(function() {
                        // Only put the ready line back if nothing more important has
                        // been said since.
                        if (status && status.textContent === cfg.strings.focused) { ready(); }
                        askContinuous();
                    }, 2000);
                }).catch(function() { /* declined; continuous focus stands */ });
            }

            function switchTo(target) {
                if (busy) { return; }
                busy = true;
                var previous = mode;
                if (sw) { sw.disabled = true; }
                if (shoot) { shoot.disabled = true; }
                say(cfg.strings.switching);
                // Let the old camera go BEFORE asking for the new one: on a phone, the
                // camera that is still open is usually the reason the second one cannot
                // be acquired.
                stop();
                byFacing(target).then(function(s) {
                    // Ask whether it actually changed. A browser that ignores facingMode
                    // hands back the camera it already had, and without this check the
                    // button would look like it worked while the picture never moves.
                    var got = facingOf(s);
                    if (got && got !== target) {
                        s.getTracks().forEach(function(t) { t.stop(); });
                        throw new Error('facing ignored');
                    }
                    mode = target;
                    adopt(s);
                }).catch(function() {
                    // Second route: walk the device list. Desktops with a virtual camera
                    // or two webcams land here, because facingMode means nothing to them.
                    if (cams.length < 2) { throw new Error('no second camera'); }
                    var next = (camindex + 1) % cams.length;
                    return byDevice(next).then(function(s) {
                        camindex = next;
                        adopt(s);
                    });
                }).catch(function() {
                    // Neither route worked: put the camera we had back, so the page is
                    // never left on a black rectangle with a dead shutter.
                    return byFacing(previous).then(function(s) {
                        mode = previous;
                        adopt(s);
                        say(cfg.strings.switchfailed);
                    }).catch(function() {
                        fail(cfg.strings.denied);
                    });
                }).then(function() {
                    busy = false;
                    if (!stream) { return; }
                    if (shoot) { shoot.disabled = false; }
                    if (sw && cams.length > 1) { sw.disabled = false; }
                });
            }

            // ---- The frame -----------------------------------------------------

            // The 4:3 rectangle taken out of the frame. It is THE crop - there is one
            // arithmetic and both halves of the page read it: fitPreview() places the
            // picture so that exactly this rectangle is what the student sees, and draw()
            // copies exactly this rectangle into the file.
            //
            // Two copies of this arithmetic is the failure this page must never have:
            // change the ratio in one of them and the student frames one thing while the
            // file holds another, with nothing on either screen to say so. It happened
            // once, in the days of the zoom slider, and it was found by a person, not by a
            // test.
            //
            // Centred, because cover-cropping anything else - top, bottom, the side the
            // hand happens to be - would silently decide part of the composition for the
            // student.
            function cropBox(vw, vh) {
                var ratio = 4 / 3;
                if (vw / vh > ratio) {
                    // Wider than 4:3: the height is the whole frame and the sides are cut.
                    var w = vh * ratio;
                    return {x: (vw - w) / 2, y: 0, w: w, h: vh};
                }
                // Narrower than 4:3, which is every phone held upright: the width is the
                // whole frame and the top and bottom are cut.
                var h = vw / ratio;
                return {x: 0, y: (vh - h) / 2, w: vw, h: h};
            }

            // Show the crop rectangle and nothing else: enlarge the picture until the
            // rectangle fills the stage, then slide it so the rectangle sits at the
            // window. Percentages, so a rotation, a resize or a change of camera resolves
            // itself with no second listener and no arithmetic in the layout.
            function fitPreview() {
                if (!video || !stage) { return; }
                var vw = video.videoWidth;
                var vh = video.videoHeight;
                if (!vw || !vh) { return; }
                var box = cropBox(vw, vh);
                video.style.width = (100 * vw / box.w) + '%';
                video.style.height = (100 * vh / box.h) + '%';
                video.style.left = (-100 * box.x / box.w) + '%';
                video.style.top = (-100 * box.y / box.h) + '%';
            }

            function draw() {
                var vw = video.videoWidth;
                var vh = video.videoHeight;
                if (!vw || !vh) { return false; }

                // The rectangle the preview is showing, asked for now and not remembered
                // from when the camera opened. A constraint applied after the fact
                // (openWide, or continuous focus on a camera that changes mode for it) can
                // change the frame size under us, and a remembered rectangle would then
                // quietly send a piece of the frame the student is not looking at.
                var box = cropBox(vw, vh);

                // Scaled so the LONGEST edge of the CROP is at most cfg.maxedge. The crop
                // is 4:3 by construction, so this no longer has to reason about a tall
                // original - and the ceiling still never enlarges a frame past its own
                // pixels.
                var scale = Math.min(1, cfg.maxedge / Math.max(box.w, box.h));
                canvas.width = Math.round(box.w * scale);
                canvas.height = Math.round(box.h * scale);

                var ctx = canvas.getContext('2d');
                ctx.drawImage(video, box.x, box.y, box.w, box.h, 0, 0, canvas.width, canvas.height);

                // The watermark. Time, course and point, burned in by the browser from
                // text the SERVER generated - a phone with a wrong clock cannot stamp a
                // convenient hour onto the evidence.
                var line = cfg.watermark;
                var size = Math.max(12, Math.round(canvas.height * 0.035));
                ctx.font = 'bold ' + size + 'px sans-serif';
                ctx.textBaseline = 'bottom';
                var pad = Math.round(size * 0.5);
                var width = Math.min(canvas.width - 2 * pad, ctx.measureText(line).width);
                ctx.fillStyle = 'rgba(0, 0, 0, 0.55)';
                ctx.fillRect(pad, canvas.height - size - 3 * pad, width + 2 * pad, size + 2 * pad);
                ctx.fillStyle = '#ffffff';
                ctx.fillText(line, 2 * pad, canvas.height - 2 * pad, canvas.width - 4 * pad);

                return true;
            }

            // The capture has to arrive under the size the server accepts. At 2048 px
            // the ceiling is 768 KiB, which leaves room to keep the quality high: the
            // ladder stops at 0.60 rather than the 0.45 of 2.0.19, because a JPEG that
            // low turns the printed rows of a check-in sheet into blocky mush - and a
            // softer picture that cannot be read is not a smaller picture, it is a
            // useless one. Halving the canvas stays as the last resort.
            function encode() {
                var limit = Math.floor(cfg.maxbytes * 4 / 3);
                var tries = [cfg.quality, 0.68, 0.60];
                var data = '';
                for (var i = 0; i < tries.length; i++) {
                    data = canvas.toDataURL('image/jpeg', tries[i]);
                    if (data && data.length <= limit) { return data; }
                }
                // Still too big: half the pixels. It costs detail, but a slightly softer
                // picture beats a check-in that cannot be recorded.
                var w = Math.round(canvas.width / 2);
                var h = Math.round(canvas.height / 2);
                var small = document.createElement('canvas');
                small.width = w;
                small.height = h;
                small.getContext('2d').drawImage(canvas, 0, 0, w, h);
                return small.toDataURL('image/jpeg', cfg.quality);
            }

            function send() {
                var data;
                try {
                    data = encode();
                } catch (e) {
                    fail(cfg.strings.denied);
                    return;
                }
                if (!data || data.indexOf('data:image/jpeg') !== 0) {
                    fail(cfg.strings.denied);
                    return;
                }
                if (field) { field.value = data; }
                stop();
                if (form) { form.submit(); }
            }

            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                fail(cfg.strings.denied);
                return;
            }

            byFacing('environment').then(adopt).catch(function() {
                // A browser that does not know facingMode rejects the constraint, so ask
                // again the way it was asked before this version: no camera preference.
                return navigator.mediaDevices.getUserMedia({video: true, audio: false})
                    .then(adopt)
                    .catch(function() {
                        // Refused permission, no camera at all, or a site served over
                        // plain HTTP: all three arrive here, and all three end at the
                        // by-hand route.
                        fail(cfg.strings.denied);
                    });
            });

            // A camera can change its frame size after it opens: openWide() asks for a
            // different zoom stop and some cameras answer with a different mode, and so
            // does continuous focus on others. Without this the window would keep the
            // shape of a frame that no longer exists and show more or less than the file
            // will hold. On a rotation or a resize the percentages in fitPreview() resolve
            // again on their own, so no window listener is needed.
            if (video) {
                video.addEventListener('resize', fitPreview);
            }

            if (sw) {
                sw.addEventListener('click', function() {
                    if (sw.disabled || busy || !stream) { return; }
                    switchTo(mode === 'environment' ? 'user' : 'environment');
                });
            }

            if (stage) {
                stage.addEventListener('click', function(e) {
                    // A tap on the picture means "focus here", not "take the picture":
                    // the shutter is a separate button and it asks before it fires.
                    focusAt(e.clientX, e.clientY);
                });
            }

            if (shoot) {
                shoot.addEventListener('click', function() {
                    if (!window.confirm(cfg.strings.shootconfirm)) { return; }
                    shoot.disabled = true;
                    if (!draw()) {
                        shoot.disabled = false;
                        return;
                    }
                    send();
                });
            }

            // Leaving the page must let the camera go, or the recorder light stays on.
            window.addEventListener('pagehide', stop);
            window.addEventListener('beforeunload', stop);
        }
    };
});
