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
 * English language strings for mod_multicheckin.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['photoreq_pending'] = 'Waiting for the teacher to check it by hand';
$string['photoreq_approved'] = 'Checked in by the teacher by hand';
$string['photoreq_refused'] = 'Refused';
$string['pluginname'] = 'Multi check-in attendance';
$string['pluginnameplural'] = 'Multi check-in attendance';
$string['modulename'] = 'Multi check-in attendance';
$string['modulenameplural'] = 'Multi check-in attendance';
$string['modulename_help'] = 'Create an attendance occurrence that requires several check-ins within a single class (sign-in, mid-class spot check, sign-out). The module automatically judges each student as present, late, left-early or absent based on the check-in times.';
$string['multicheckin:addinstance'] = 'Add a new multi check-in attendance';
$string['multicheckin:view'] = 'View multi check-in attendance';
$string['multicheckin:checkin'] = 'Perform a check-in';
$string['multicheckin:manage'] = 'Manage check-in points and sessions';
$string['multicheckin:viewreports'] = 'View attendance reports';
$string['multicheckin:recompute'] = 'Recompute attendance statuses';
$string['privacy:metadata'] = 'The Multi check-in attendance plugin stores check-in records (user, time and IP) for attendance purposes.';

// Section / field labels.
$string['timing'] = 'Timing and status rules';
$string['timestart'] = 'Session start time (T0)';
$string['timestart_help'] = 'The base time from which all check-in windows and the late / early-leave rules are measured.';
$string['timeend'] = 'Session end time';
$string['lategrace'] = 'Late grace period';
$string['lategrace_help'] = 'If the first (sign-in) check-in happens later than the session start plus this period, the student is marked as Late.';
$string['requiresignout'] = 'Require a sign-out check-in';
$string['requiresignout_help'] = 'Leave this on when the session has an end-of-class check-in: a student who never checks out is marked as having left early. Turn it off for courses that only take attendance at the start, where a successful sign-in is the whole record — the status is then simply present or late, and no early-leave penalty is applied.';
$string['earlygrace'] = 'Early-leave grace period';
$string['earlygrace_help'] = 'If the last (sign-out) check-in happens earlier than the session end minus this period, the student is marked as Left early.';
$string['timeend_before_start'] = 'The session end time must be after the session start time.';
$string['earlymode'] = 'Early-leave judgement';
$string['earlymode_help'] = 'Choose how "left early" is decided: by the sign-out check-in window, or by comparing the departure time against the session end.';
$string['earlymode_window'] = 'By check-in window (recommended)';
$string['earlymode_threshold'] = 'By departure time vs session end';
$string['selfcheckinsection'] = 'Self check-in';
$string['allowselfcheckin'] = 'Allow students to check in themselves';
$string['allowselfcheckin_help'] = 'When enabled, students can check in from the activity page while a point is open (optionally protected by a password).';
$string['selfcheckinpassword'] = 'Self check-in password (optional)';
$string['selfcheckinpassword_help'] = 'If set, students must type this password to check in themselves. Leave blank for no password. A per-point password overrides this.';
$string['qronly'] = 'QR check-in only (no button, no password)';
$string['qronly_help'] = 'Closes both the check-in button and the password for every point of this occurrence, so students can only check in by scanning the code on your screen. Stricter than the switch above, which only hides the button. Individual points can be locked as well on the points page. This cannot stop a student from passing a photograph of the code to somebody else: the code identifies the point, not the person. A shorter rotation period makes that harder.';
$string['audience'] = 'Attendance roster';
$string['audience_help'] = 'Which roles count as participants of this activity. The attendance sheet, the report and the grade calculation all use this list, so a teacher or tutor is only listed (and only marked absent) when their role is selected here. Administrators are never included. Defaults to students only.';
$string['audience_role_student'] = 'Students (default)';
$string['audience_empty'] = 'No enrolled user holds the selected roles, so the roster falls back to students.';
$string['notifysection'] = 'Check-in reminders';
$string['notifyenabled'] = 'Remind students of upcoming check-ins';
$string['notifyenabled_help'] = 'Off by default, and turning it on is a deliberate choice: a check-in that speaks for itself should not start mailing the class by itself. When it is on and a check-in point comes due, the students it applies to receive a reminder (site message, email and mobile push). Which points remind, and who they remind, is set by the rule below.';
$string['notifyleadtime'] = 'Reminder lead time (seconds)';
$string['notifyleadtime_help'] = 'How long before a point opens the reminder may go out. The reminder says whether the point is open yet, so a lead time does not send students to a button that still refuses them. 0 = remind only once the point is open.';
$string['notifyrule'] = 'Which check-ins send a reminder';
$string['notifyrule_help'] = 'The first point of an occurrence is the sign-in, so it usually reminds everyone. Whether the later points do, and to whom, is what this setting decides. Students who have already checked in at a point are never reminded about it twice, students on leave are skipped, and a point whose own reminder is switched off stays quiet.';
$string['notifyrule_chain'] = 'First point reminds everyone; each later point reminds only those who checked in at the point before it';
$string['notifyrule_all'] = 'Every point reminds everyone who has not checked in yet';
$string['notifyrule_first'] = 'Only the first point of each occurrence reminds';

// Geofence (anti-proxy).
$string['geosection'] = 'Anti-proxy geofence';
$string['geomode'] = 'Geofence mode';
$string['geomode_help'] = 'Restrict self check-in to students who are physically on site. IP checks the campus network; GPS checks that the browser is inside a radius around the classroom. "Both" requires both to pass. QR check-ins bypass the fence unless you opt in below.';
$string['geomode_none'] = 'No geofence';
$string['geomode_ip'] = 'IP / campus network only';
$string['geomode_gps'] = 'GPS radius only';
$string['geomode_both'] = 'Both IP and GPS';
$string['geoipranges'] = 'Allowed IP ranges';
$string['geoipranges_help'] = 'One address or CIDR range per line. IPv4 and IPv6 both work: 192.168.10.0/24, 10.0.0.5, 2001:db8::/32. Lines starting with # are ignored. An address only matches a range of its own family, so a list of IPv4 ranges blocks every IPv6 client - add the IPv6 range your network hands out as well. Leaving this empty while the mode asks for IP lets everyone through, which is deliberate: an empty list must not lock the whole class out.';
$string['geoipranges_invalid'] = 'This is not a valid IP address or CIDR range: {$a}';
$string['geolat'] = 'Classroom latitude';
$string['geolat_help'] = 'Latitude of the classroom centre, in decimal degrees (for example 30.4635000).';
$string['geolng'] = 'Classroom longitude';
$string['geolng_help'] = 'Longitude of the classroom centre, in decimal degrees (for example 114.1234000).'
    . ' Enter the coordinates as WGS-84, the datum a browser or phone reports.';
$string['georadius'] = 'Allowed radius (metres)';
$string['georadius_help'] = 'A check-in passes when the reported position is within this distance of the centre. Between 10 and 5000 metres; 100 m suits a lecture hall.';
$string['georadius_range'] = 'The radius must be between 10 and 5000 metres.';
$string['geocoords_invalid'] = 'Enter a valid classroom latitude and longitude.';
$string['geoenforceqr'] = 'Also apply the geofence to QR check-ins';
$string['geoenforceqr_help'] = 'On by default: a student scanning the code must also satisfy the geofence. Untick it where projecting the code is itself the proof of presence, to skip the position check for scans.';
$string['showgeoaudit'] = 'Show the geofence audit link';
$string['showgeoaudit_help'] = 'The audit page explains why a fence appears to do nothing. The link is hidden by default so everyday pages stay short; the page itself stays reachable by URL for anyone who may manage the activity, so this only surfaces the link.';
$string['geofence_notice_ip'] = 'Self check-in is restricted to the campus network. Connect to the campus Wi-Fi to check in.';
$string['geofence_notice_gps'] = 'Self check-in requires your location. Your browser will ask for permission and your position is verified against the classroom.';
$string['geofence_ok'] = 'Location verified.';
$string['geofence_denied'] = 'Your location could not be verified, so the check-in was rejected. Please ask your teacher to mark you in.';
$string['geofence_ip_denied'] = 'You are not on the campus network, so self check-in is not allowed. Connect to the campus Wi-Fi and try again.';
$string['geofence_out_of_range'] = 'You appear to be outside the classroom, so self check-in is not allowed.';
$string['geofence_no_gps'] = 'Your location could not be read. Allow location access in your browser and try again.';
$string['geo_locating'] = 'Checking your location…';
$string['geo_denied'] = 'Location permission was denied. Please allow it to check in.';
$string['geo_unsupported'] = 'Your browser cannot report a location; continuing without it.';
$string['privacy_geolog'] = 'Geofence checks';
$string['geolog_note'] = 'Showing the 100 most recent geofence decisions for this session.';
$string['ip'] = 'IP address';

// QR rotation.
$string['qrsection'] = 'QR code options';
$string['qrrotation'] = 'QR refresh interval (seconds)';
$string['qrrotation_help'] = 'How often the signed QR token changes. Shorter is more secure (a screenshot expires sooner) but the image refreshes more often. Allowed range 5 to 300 seconds; 15 seconds is a good balance.';
$string['qrrotation_range'] = 'The refresh interval must be between {$a->min} and {$a->max} seconds.';


// Points.
$string['pointname'] = 'Check-in point';
$string['point_new'] = 'Check-in point {$a}';
$string['point_signin'] = 'Sign-in';
$string['point_mid'] = 'Mid-class check';
$string['point_signout'] = 'Sign-out';
$string['pointwindow'] = 'Open window';
$string['pointrole'] = 'Role';
$string['role_first'] = 'First (sign-in)';
$string['role_mid'] = 'Middle (spot check)';
$string['role_last'] = 'Last (sign-out)';
$string['required'] = 'Required';
$string['pointpassword'] = 'Password (optional)';
$string['pointqrcode'] = 'Show a rotating QR code';
$string['pointqrcode_help'] = 'Display a QR code that refreshes every 15 seconds. Students scan it to check in without a password, and a shared screenshot expires quickly.';
$string['pointqronly'] = 'QR check-in only';
$string['pointqronly_help'] = 'This point accepts the rotating QR code only: the check-in button and the password stop working here. The code is on the teacher\'s page even when "Show a rotating QR code" is not ticked, because the code is the only way in.';
$string['pointnotify'] = 'Send a reminder when this point opens';
$string['openoffset'] = 'Opens (offset from start)';
$string['openoffset_help'] = 'Seconds relative to the session start time (T0). A negative value means the window opens before the session starts.';
$string['closeoffset'] = 'Closes (offset from start)';

// Statuses.
$string['status'] = 'Status';
$string['status_present'] = 'Present';
$string['status_late'] = 'Late';
$string['status_early'] = 'Left early';
$string['status_late_early'] = 'Late and left early';
$string['status_absent'] = 'Absent';
$string['status_leave'] = 'On leave';
$string['open'] = 'Open';
$string['closed'] = 'Closed';
$string['mystatus'] = 'My status';

// Leave requests.
$string['leave'] = 'Leave';
$string['leave_request'] = 'Request leave';
$string['leave_request_intro'] = 'Ask to be excused from this session. Submitting records the leave straight away, and your teacher can review it afterwards.';
$string['leave_reason'] = 'Reason (optional)';
$string['leave_reason_help'] = 'A short note for your teacher. You may leave this blank.';
$string['leave_attachment'] = 'Supporting document (optional)';
$string['leave_attachment_help'] = 'One image (JPG, PNG, GIF or WEBP) or PDF file, such as a photo of a signed note. Only you and your teachers can see it.';
$string['leave_submit'] = 'Submit leave request';
$string['leave_submitted'] = 'Your leave for this session has been recorded.';
$string['leave_already'] = 'A leave is already recorded for this session.';
$string['leave_haslog'] = 'You have already checked in at this session, so it cannot be turned into a leave.';
$string['leave_closed'] = 'This session is no longer open for leave requests.';
$string['leave_notallowed'] = 'You cannot request leave for this session.';
$string['leave_filetype'] = 'Please upload an image or a PDF file.';
$string['leave_appnote'] = 'Attaching a document is more reliable in a web browser. You may submit the leave here and add the document later from a browser.';
$string['leave_scan_hint'] = 'Scanning a code only checks in; a leave is submitted from the activity page.';
$string['leave_back'] = 'Back to the activity';
$string['leave_for'] = 'Leave for {$a}';
$string['leave_none'] = 'No leave requests for this activity yet.';
$string['leaves'] = 'Leave requests';
$string['leaves_heading'] = 'Leave requests: {$a}';
$string['leave_grant'] = 'On leave';
$string['leave_grant_help'] = 'Records a leave of absence for this student for the whole session. Their check-in records are kept: clearing the leave restores whatever they show.';
$string['leave_granted'] = 'Leave recorded for {$a} user(s).';
$string['leave_cleared'] = 'Leave cleared for {$a} user(s).';
$string['leave_pending'] = 'Awaiting review';
$string['leave_reviewed'] = 'Reviewed';
$string['leave_markreviewed'] = 'Mark as reviewed';
$string['leave_reviewed_ok'] = 'The leave request has been marked as reviewed.';
$string['leave_origin'] = 'Source';
$string['leave_origin_student'] = 'Student request';
$string['leave_origin_teacher'] = 'Set by teacher';
$string['leave_attachment_none'] = 'No document';
$string['leave_download'] = 'Download the leave note';
$string['leave_col_session'] = 'Session';
$string['leave_col_reason'] = 'Reason';
$string['leave_col_time'] = 'Submitted';
$string['leave_pendingcount'] = '{$a} leave request(s) awaiting review';
$string['leaves_showall'] = 'Show every leave request';
$string['leave_clear_link'] = 'Clear';
$string['leave_clear_confirm'] = 'Clear this leave? The check-in records are kept and the status goes back to what they show.';
$string['leave_reviewall'] = 'Mark all as reviewed';
$string['leaves_note'] = 'A leave takes effect as soon as it is filed; this list is for looking at it afterwards.';

// Actions and messages.
$string['action'] = 'Action';
$string['checkin_now'] = 'Check in';
$string['checkin_recorded_ok'] = 'Your check-in has been recorded.';
$string['point_closed'] = 'This check-in point is not open right now.';
$string['already_checked_in'] = 'You have already checked in at this point.';
$string['password_invalid'] = 'The password is not correct.';
$string['selfcheckin_disabled'] = 'Self check-in is not enabled for this activity.';
$string['qr_invalid'] = 'This QR code has expired or is not valid. Please scan the current code on screen.';
$string['qr_rotate_hint'] = 'Refreshes automatically every {$a} seconds.';
$string['qr_scan_in_browser'] = 'This check-in link has to be opened in the browser, not loaded inside another page. Scan the code on screen with your phone camera, or tap its link, and try again.';
$string['qrcodes'] = 'Check-in QR codes';
$string['scan_qr_hint'] = 'Scan the QR code on screen';
$string['qrwindow_open'] = 'Check-in QR codes';
$string['qrwindow_title'] = 'Check-in QR codes — {$a}';
$string['qrwindow_closed_notice'] = 'This session is not in a check-in window right now: a student scanning at this moment would be refused. The codes refresh every {$a} seconds, so project them once the window is open.';
$string['qrwindow_point_notenabled'] = 'This point does not have the "QR code" method ticked (scanning still counts).';
$string['qronly_only_scan'] = 'This point only accepts a scan of the QR code on the teacher\'s screen.';
$string['qronly_badge'] = 'QR only';
$string['qronly_force_show'] = 'QR check-in only: this code is shown even though the "QR code" method is not ticked.';
$string['qronly_email_hint'] = 'This point accepts a scan of the QR code on the teacher\'s screen only - there is no button to press.';
$string['qrwindow_nopoints'] = 'This session has no check-in points yet, so there is no code to show.';
$string['qrwindow_hint'] = 'Resize this window or drag it to a second screen. Clicking the button again reuses this window.';
$string['mycheckin'] = 'My check-in';
$string['checked_in'] = 'Checked in';
$string['managepoints'] = 'Manage check-in points';
$string['viewreport'] = 'Attendance report';
$string['addpoint'] = 'Add check-in point';
$string['point_added'] = 'Check-in point added.';
$string['point_deleted'] = 'Check-in point deleted.';
$string['pointsupdated'] = 'Check-in points updated.';
$string['recompute'] = 'Recompute statuses';
$string['recomputed'] = 'Statuses recomputed for {$a} user(s).';
$string['sendremindersnow'] = 'Send reminders now';
$string['reminders_sent'] = 'Sent {$a} reminder(s).';
$string['exportcsv'] = 'Export CSV';
$string['resetcheckindata'] = 'Delete all check-in data';
$string['resetseries'] = 'Delete the generated attendance occurrences ({$a})';

// Report columns.
$string['user'] = 'User';
$string['studentid'] = 'Student ID';
$string['firstcheckin'] = 'First check-in';
$string['lastcheckin'] = 'Last check-in';
$string['latemin'] = 'Late (min)';
$string['earlymin'] = 'Left early (min)';
$string['points'] = 'Points';

// Sync remarks.
$string['remark_late'] = 'Late by {$a} min';
$string['remark_early'] = 'Left early by {$a} min';

// Events and tasks.
$string['event_checkin_recorded'] = 'Check-in recorded';
$string['task_finalise_sessions'] = 'Finalise finished multi check-in sessions';
$string['task_send_reminders'] = 'Send multi check-in reminders';

// Reminder messages.
$string['messageprovider:nextcheckin_reminder'] = 'Reminder to check in again';
$string['msg_reminder_subject'] = '[{$a->course}] {$a->session} - remember to check in';
$string['msg_reminder_small'] = 'Remember to check in: {$a->course} / {$a->session}';
$string['msg_reminder_linktext'] = 'Check in now';
$string['msg_reminder_unknowncourse'] = 'Unknown course';
$string['msg_reminder_body'] = '<p>Course "{$a->course}", activity "{$a->session}": a check-in point is due.</p><p>The check-in point "{$a->point}" is now open until {$a->time}. Please remember to check in.</p>{$a->linksection}';
$string['msg_reminder_body_soon'] = '<p>Course "{$a->course}", activity "{$a->session}": a check-in point is due.</p><p>The check-in point "{$a->point}" opens at {$a->opens} and closes at {$a->time}. Please be ready to check in.</p>{$a->linksection}';
$string['msg_reminder_linksection'] = '<p>Open the check-in page (the link below is already positioned on the point &quot;{$a->point}&quot;): {$a->link}<br />If the link does not work, copy this address into your browser:<br />{$a->url}</p>';
$string['msg_reminder_body_nolink'] = '<p>Course "{$a->course}", activity "{$a->session}": a check-in point is due.</p><p>The check-in point "{$a->point}" is now open until {$a->time}. Please remember to check in.</p>';
$string['msg_reminder_body_soon_nolink'] = '<p>Course "{$a->course}", activity "{$a->session}": a check-in point is due.</p><p>The check-in point "{$a->point}" opens at {$a->opens} and closes at {$a->time}. Please be ready to check in.</p>';

// Privacy.
$string['privacy:metadata:multicheckin_log'] = 'Information about a user check-in.';
$string['privacy:metadata:multicheckin_log:userid'] = 'The user who checked in.';
$string['privacy:metadata:multicheckin_log:timecreated'] = 'When the check-in was recorded.';
$string['privacy:metadata:multicheckin_log:ip'] = 'The IP address the check-in came from.';
$string['privacy:metadata:multicheckin_result'] = 'The computed attendance status of a user.';
$string['privacy:metadata:multicheckin_result:statuscode'] = 'The computed status (present, late, left early or absent).';
$string['privacy:metadata:multicheckin_notify'] = 'A record that a check-in reminder was sent to a user.';
$string['privacy:metadata:multicheckin_notify:userid'] = 'The user the reminder was sent to.';
$string['privacy:metadata:multicheckin_notify:timesent'] = 'When the reminder was sent.';
$string['privacy:metadata:multicheckin_geo_log'] = 'An audit record of each self check-in geofence decision.';
$string['privacy:metadata:multicheckin_geo_log:userid'] = 'The user who attempted to check in.';
$string['privacy:metadata:multicheckin_geo_log:ip'] = 'The IP address the attempt came from.';
$string['privacy:metadata:multicheckin_geo_log:latitude'] = 'The latitude reported by the browser, when a GPS fence is active.';
$string['privacy:metadata:multicheckin_geo_log:longitude'] = 'The longitude reported by the browser, when a GPS fence is active.';
$string['privacy:metadata:multicheckin_geo_log:timecreated'] = 'When the geofence check was made.';
$string['privacy:metadata:core_files'] = 'Two kinds of file live with this activity: an optional supporting document attached to a leave request, and the photograph taken at a check-in when the occurrence demands one.';
$string['privacy:metadata:multicheckin_leave'] = 'A declaration that a user was on leave for one session, with an optional reason and supporting document.';
$string['privacy:metadata:multicheckin_leave:userid'] = 'The user the leave belongs to.';
$string['privacy:metadata:multicheckin_leave:reason'] = 'The reason given for the leave.';
$string['privacy:metadata:multicheckin_leave:origin'] = 'Whether the leave was requested by the student or set by a teacher.';
$string['privacy:metadata:multicheckin_leave:reviewed'] = 'Whether a teacher has reviewed the leave.';
$string['privacy:metadata:multicheckin_leave:reviewedby'] = 'The teacher who reviewed the leave.';
$string['privacy:metadata:multicheckin_leave:timecreated'] = 'When the leave was recorded.';
$string['privacy:metadata:multicheckin_leave:timemodified'] = 'When the leave was last changed.';

// ---- Shared classroom location library ----
//
// A site-wide list of named rooms, so a teacher picks a classroom instead of
// retyping its coordinates into every activity.
$string['placesection'] = 'Saved classroom';
$string['placeselectnone'] = 'Select a saved classroom...';
$string['sharedplaceselect'] = 'Classroom from the geolocation plugin';
$string['sharedplaceselect_help'] = 'Rooms from the shared classroom library maintained by the Geolocation availability condition plugin. Choosing one copies its coordinates and radius into the fields below; nothing is stored against the activity until you save. This list appears only when that plugin is installed, so no site is forced to run it.';
$string['sharedplacenone'] = 'Select a classroom...';
$string['sharedplacehint'] = 'Selecting a room fills in the latitude, longitude and radius below. The values are WGS-84, the same datum the browser reports.';
$string['sharedplaceunavailable'] = 'The shared classroom library is not available on this site. Enter the latitude, longitude and radius below by hand instead.';
$string['placesection_help'] = 'Pick a classroom from the shared library to fill in the coordinates and radius below. Manage the library to add or correct rooms. Coordinates are always WGS-84, the same datum the browser reports, so a room recorded once compares correctly for every student.';
$string['place_pickerhint'] = 'Choosing a saved classroom fills in the coordinates and radius below. You can still type them by hand.';
$string['place_choosedots'] = 'Choose a saved classroom…';
$string['place_usedot'] = '(in use)';
$string['place_savebutton'] = 'Save current as a classroom';
$string['place_saving'] = 'Saving…';
$string['place_saved'] = 'The classroom was saved to the shared library.';
$string['place_nocoords'] = 'Enter the coordinates first, then save them as a classroom.';
$string['place_nameprompt'] = 'Name this classroom (for example Main Building A301):';
$string['place_newname'] = 'The classroom needs a name.';
$string['place_dupewarn'] = 'A classroom called "{$a}" already exists. Save this one as well?';
$string['place_duplicatewarning'] = 'Note: a classroom with the same name already exists.';

// Management page.
$string['place_manage'] = 'Manage saved classrooms';
$string['place_managetitle'] = 'Saved classrooms';
$string['place_managedesc'] = 'These rooms are shared across every course on this site. Choosing one in an activity copies its coordinates into that activity, so correcting a wrong entry here does not change activities that already use it — edit those individually.';
$string['place_add'] = 'Add a classroom';
$string['place_edit'] = 'Edit classroom';
$string['place_backtolist'] = 'Back to the list';
$string['place_save'] = 'Save classroom';
$string['placecreated'] = 'The classroom was added.';
$string['placeupdated'] = 'The classroom was updated.';
$string['placenone'] = 'No classrooms have been saved yet.';
$string['placenotfound'] = 'That classroom no longer exists.';
$string['placedeleteconfirm'] = 'Delete the saved classroom "{$a}"?';
$string['placedeleted'] = 'The classroom "{$a}" was deleted.';
$string['placedeletedinuse'] = '{$a} activity(ies) still use the same coordinates; they are unaffected and keep working.';
$string['place_deletenote'] = 'Deleting a room only removes it from this list. Activities that already use its coordinates keep working exactly as before.';
$string['placeusage'] = 'Used by';
$string['placeusecount'] = '{$a} activity(ies)';
$string['placeusezero'] = 'not used';
$string['placemodified'] = 'Last changed';

// Field labels.
$string['placename'] = 'Classroom name';
$string['placename_help'] = 'How this room appears in the dropdown, for example Main Building A301 or Lecture Theatre 3. Two rooms may share a name if they are in different buildings.';
$string['placedescription'] = 'Note';
$string['placedescription_help'] = 'Optional. Shown as a hint when the room is selected, for example "use the north entrance".';
$string['placelatitude'] = 'Latitude';
$string['placelatitude_help'] = 'WGS-84 latitude in decimal degrees, for example 30.592800. This is the datum a browser reports, not the shifted GCJ-02 used by Chinese commercial maps — do not copy a value from AMap or Baidu directly.';
$string['place_mappick'] = 'Click the map to fill in the coordinates, or drag the marker to fine-tune. Stored values are WGS-84.';
$string['placelongitude'] = 'Longitude';
$string['placedefaultradius'] = 'Default radius (m)';
$string['placedefaultradius_help'] = 'The radius suggested for this room when it is selected, in metres. 100 m suits a lecture hall.';

// Validation.
$string['placename_required'] = 'Enter a name for the classroom.';
$string['placename_toolong'] = 'The name is too long; use 255 characters or fewer.';
$string['placecoords_required'] = 'Enter both the latitude and the longitude.';
$string['placecoords_unset'] = 'The coordinates cannot both be zero. Enter the actual position of the classroom.';
$string['placelat_range'] = 'The latitude must be between -90 and 90.';
$string['placelng_range'] = 'The longitude must be between -180 and 180.';
$string['placeradius_range'] = 'The radius must be between 10 and 50000 metres.';
$string['placeerror_generic'] = 'The classroom could not be saved. Check the values and try again.';

// Radius formatting, shared with the condition description.
$string['radiusm'] = '{$a} m';
$string['radiuskm'] = '{$a} km';

// Capability.
$string['multicheckin:manageplaces'] = 'Manage the shared list of saved classrooms';

// Privacy: the shared classroom library.
$string['privacy:metadata:multicheckin_place'] = 'The shared list of saved classrooms. The coordinates describe a building rather than a person; the only personal datum is who last edited a record.';
$string['privacy:metadata:multicheckin_place:usermodified'] = 'The user who last added or edited this classroom.';
$string['privacy:metadata:multicheckin_place:timecreated'] = 'When the classroom was first saved.';
$string['privacy:metadata:multicheckin_place:timemodified'] = 'When the classroom was last edited.';

// ---- Mid-session check-ins (v1.4.0) ----
$string['midsection'] = 'Mid-session check-ins';
$string['midsection_help'] = 'Roll call rounds during the session, between sign-in and sign-out. Set how many there are and when each one opens; fine tuning (exact seconds, per-point password, QR, reminders) stays on the manage points page. Changing the count keeps the existing points and their records where possible: surplus points at the end are removed with their logs, missing ones are appended.';
$string['midcount'] = 'Number of mid-session check-ins';
$string['midcount_help'] = 'How many roll call rounds take place between sign-in and sign-out. This defaults to 0, so a new activity has just its sign-in and sign-out; raise it to add a mid roll call.';
$string['midcount_zero_hint'] = 'No mid roll call. Keep 0 for a plain sign-in and sign-out, or set 1 or more to add a roll call between them.';
$string['midopenlabel'] = 'Mid check-in {$a}: opens (minutes after the session starts)';
$string['midopen'] = 'Opening time';
$string['midopen_help'] = 'Minutes after the session start when this mid check-in window opens. For example 45 means the window opens 45 minutes into the class.';
$string['midwindow'] = 'Window length (minutes)';
$string['midwindow_help'] = 'Every mid check-in stays open for this many minutes from its opening time. The manage page can still adjust each point separately afterwards.';
$string['midcount_invalid'] = 'Choose between 0 and 10 mid-session check-ins.';
$string['midopen_invalid'] = 'The opening time must be between 0 and 2880 minutes.';
$string['midwindow_invalid'] = 'The window length must be between 1 and 480 minutes.';
$string['midpoints_synced'] = 'Mid check-ins were updated on {$a->sessions} session(s): {$a->added} added, {$a->removed} removed, {$a->signed} signed. Points that already carried a check-in were kept.';

// ---- Status point values (v1.4.0) ----
$string['statuspointssection'] = 'Grade points per status';
$string['statuspointssection_help'] = 'How many raw points each final status earns for this session. The course grade is the earned points divided by the largest configured value, times the maximum grade above — so with the defaults, "present" scores the full activity grade, "late" half of it, and so on. Grades are pushed to the gradebook after every check-in, after recomputing, and when the session is finalised.';
$string['pointspresent'] = 'Present (points)';
$string['pointspresent_help'] = 'Points for the final status "present". This is normally the largest value and therefore the full mark of the session.';
$string['pointslate'] = 'Late (points)';
$string['pointsearly'] = 'Left early (points)';
$string['pointslateearly'] = 'Late and left early (points)';
$string['pointsabsent'] = 'Absent (points)';
$string['pointsleave'] = 'On leave (points)';
$string['statuspoints_invalid'] = 'Point values must be between 0 and 1000.';

// ---- Occurrence series (v1.7.0) ----
//
// One activity carries a term's worth of attendance: the activity itself keeps
// the shared settings (grace periods, geofence, point values, mid check-in
// structure) and holds any number of occurrences, each with its own date and
// time.
$string['seriessection'] = 'Attendance occurrences';
$string['seriessection_help'] = 'Generate all the sessions for a teaching period at once. The activity keeps the shared settings (timing rules, geofence, QR, point values, mid check-in structure) while each occurrence has its own date and check-in points. With no occurrences the activity is itself the single session, exactly as before.';
$string['series_repeat'] = 'Repeat';
$string['series_repeat_help'] = 'Choose "none" for a single session, "weekly" to pick weekdays, "daily" to repeat every N days, or "irregular dates" to list each date and time yourself.';
$string['series_repeat_none'] = 'Do not repeat (single session)';
$string['series_repeat_weekly'] = 'Repeat weekly';
$string['series_repeat_daily'] = 'Repeat daily';
$string['series_repeat_custom'] = 'Irregular dates (list them)';
$string['series_weekdays'] = 'Weekdays';
$string['series_weekdays_help'] = 'Run a session on each of these weekdays. You can pick several; a day is only generated once.';
$string['weekday_1'] = 'Monday';
$string['weekday_2'] = 'Tuesday';
$string['weekday_3'] = 'Wednesday';
$string['weekday_4'] = 'Thursday';
$string['weekday_5'] = 'Friday';
$string['weekday_6'] = 'Saturday';
$string['weekday_7'] = 'Sunday';
$string['series_interval'] = 'Interval in days';
$string['series_interval_help'] = 'How many days between sessions. 1 is every day, 2 is every other day, 7 is weekly.';
$string['series_datefrom'] = 'Start date';
$string['series_datefrom_help'] = 'First date of the series. When it falls on one of the selected weekdays it is generated too.';
$string['series_dateto'] = 'End date';
$string['series_dateto_help'] = 'Last date of the series. The comparison is by calendar day, so a session on the end date is always included whatever its clock time.';
$string['series_timefrom'] = 'Start time of each session';
$string['series_timefrom_help'] = 'Clock time each occurrence begins, as HH:MM. The length comes from the difference between the start and end time above.';
$string['series_preview'] = 'Occurrences to be created';
$string['series_preview_none'] = 'No repeat rule configured yet.';
$string['series_preview_hint'] = 'These dates are created when you save. An occurrence with the same start time already present is skipped rather than duplicated.';
$string['series_preview_more'] = '… and {$a} in total';
$string['series_preview_capped'] = 'The limit of {$a} occurrences for one generation has been reached. Shorten the date range, or ask an administrator to raise the limit.';
$string['series_preview_count'] = '{$a} occurrences';
$string['series_daterange_invalid'] = 'The end date must be later than the start date.';
$string['series_timefrom_invalid'] = 'Enter the start time as HH:MM, for example 08:30.';
$string['series_weekdays_required'] = 'Select at least one weekday for a weekly repeat.';
$string['series_interval_invalid'] = 'The interval must be between 1 and 365 days.';
$string['series_customcount'] = 'Number of sessions';
$string['series_customcount_help'] = 'How many sessions this activity has. Setting it reveals that many date and time rows below; each row is one session. The most you can set is the site\'s limit on occurrences for one generation.';
$string['series_customcount_invalid'] = 'The number of sessions must be between 1 and {$a}.';
$string['series_customrow'] = 'Session {$a}';
$string['series_customdate_hint'] = 'YYYY-MM-DD';
$string['series_customtime_hint'] = 'HH:MM';
$string['series_customrow_invalid'] = 'Session {$a}: enter a real date as YYYY-MM-DD and a time as HH:MM.';
$string['series_customrows_required'] = 'Enter at least one date and time, or choose a different repeat rule.';

// Occurrence lists and batch management.
$string['occurrences'] = 'Attendance occurrences';
$string['occurrence_n'] = 'Occurrence {$a}';
$string['occurrence_select'] = 'Select an occurrence';
$string['occurrence_none'] = 'No occurrences have been generated for this activity; it is running as a single session.';
$string['occurrence_count'] = '{$a} occurrences';
$string['occurrence_current'] = 'In progress';
$string['occurrence_past'] = 'Finished';
$string['occurrence_future'] = 'Upcoming';
$string['occurrence_manageall'] = 'Manage occurrences';
$string['series_manage'] = 'Manage attendance occurrences';
$string['series_manage_desc'] = 'This activity has {$a->total} occurrences, {$a->withdata} of which already hold check-ins.';
$string['series_regenerate'] = 'Rebuild from the current rule';
$string['series_regenerate_desc'] = 'Add any occurrences the repeat rule implies but that are missing, and renumber the series. Existing occurrences, points and check-ins are all kept.';
$string['series_regenerated'] = 'Added {$a} occurrences.';
$string['series_clear'] = 'Delete all occurrences';
$string['series_clear_desc'] = 'Remove every occurrence generated for this activity, with their check-in points. <strong>Check-ins already collected are deleted too, and cannot be recovered.</strong>';
$string['series_clear_confirm'] = 'Delete all {$a} occurrences and their check-in records? This cannot be undone.';
$string['series_cleared'] = 'Deleted {$a} occurrences.';
$string['series_deleteone'] = 'Delete this occurrence';
$string['series_deleteone_confirm'] = 'Delete this occurrence and its check-in records?';
$string['series_deleted_one'] = 'The occurrence was deleted.';
$string['series_none_yet'] = 'This activity has no generated occurrences yet.';
$string['series_is_template'] = 'That record is the activity configuration template and cannot be deleted as an occurrence.';
$string['series_bad_session'] = 'That occurrence does not belong to this activity, or no longer exists.';

// ---- Editing an occurrence (v1.10.0) ----
// A teacher can retitle one occurrence and write what it is for. The times are
// editable too, which is why the recompute wording below exists: an occurrence
// start is the origin every check-in point is measured from.
$string['session_title'] = 'Title';
$string['session_title_help'] = 'What this occurrence is called, for example "Week 3: map projections". It starts as the activity name, which is the same for every occurrence, so giving one its own title is what makes a series of thirty readable.';
$string['session_title_required'] = 'The title cannot be empty.';
$string['session_description'] = 'Description';
$string['session_description_help'] = 'What happens in this occurrence, in your own words. Students see it on the activity page; teachers see it wherever the occurrence is shown. The button beside the preview opens it in a dialog on the same page; formatting and images are allowed, and a note written before that was possible still displays as the plain text it was. This is not the activity introduction, which belongs to the activity as a whole.';
$string['session_description_add'] = 'Write a note';
$string['session_description_edit'] = 'Edit the note';
$string['session_description_none'] = 'No note yet.';
$string['session_description_toolong'] = 'The description is too long. Keep it to 2000 characters or fewer.';
$string['session_start'] = 'Starts';
$string['session_end'] = 'Ends';
$string['session_time_invalid'] = 'That date and time could not be read. Use YYYY-MM-DD for the date and HH:MM for the time.';
$string['session_time_order'] = 'The end has to come after the start.';
$string['session_time_range'] = 'The year must be between 2000 and 2100.';

// The single-occurrence editor.
$string['session_edit'] = 'Edit this occurrence';
$string['session_edit_link'] = 'Edit this occurrence';
$string['session_edit_intro'] = 'Change the title or the times of this occurrence. The note is edited in a dialog, opened from the button above. Every check-in point is placed relative to the start time, so moving it moves the point windows with it.';
$string['session_edit_template'] = 'This activity has a single session, so the record being edited is the activity itself: its title is the activity name shown on the course page.';
$string['session_saved'] = 'Saved "{$a}".';
$string['session_unchanged'] = 'Nothing was changed.';

// Moving the times of an occurrence that has already collected check-ins.
$string['session_note'] = 'Edit the note of this occurrence';
$string['session_note_intro'] = 'The note is written in a dialog, so a page listing a term of occurrences does not have to load an editor for every row. Formatting and images are allowed; students see the note on the activity page.';
$string['session_note_saved'] = 'The note was saved.';
$string['session_note_continue'] = 'Keep editing';
$string['session_note_close'] = 'Close and go back';
$string['session_recalc'] = 'Recompute check-ins';
$string['session_recalc_help'] = 'Each check-in point is stored as an offset from the start time, so moving the start moves every point window. Attendance already collected is judged again against the new windows, and a check-in that was on time can become late. The check-in records themselves are never deleted, and moving the times back restores the previous result.';
$string['session_recalc_label'] = 'I understand: this occurrence has {$a} check-in records, and saving will judge them again against the new times.';
$string['session_recalc_notice'] = 'This occurrence has {$a} check-in records. Changing the times will judge them again against the new times; the records themselves are kept.';
$string['session_recalc_required'] = 'Tick the confirmation first: changing the times will judge the check-in records already collected against the new times.';
$string['session_recalcd'] = '{$a} results were recomputed.';

// Whole-table editing on the series page.
$string['series_savebatch'] = 'Save all changes';
$string['series_savebatch_desc'] = 'Titles, descriptions and times can be edited directly in the table above, and one save covers the whole page. Dates are YYYY-MM-DD and times are HH:MM.';
$string['series_saved'] = 'Saved {$a->sessions} occurrence(s) and recomputed {$a->judged} result(s).';
$string['series_save_errors'] = 'Nothing was saved: at least one row could not be read. The rows that need attention are marked below, and everything you typed has been kept.';
$string['series_recalc_needed'] = 'Nothing was saved yet: {$a} occurrence(s) have check-ins collected and their times have been changed. Confirm below and save again to apply.';
$string['series_recalc_ack'] = 'I understand: the occurrences with collected check-ins will be judged again against their new times.';

// Occurrence table.
$string['seqno'] = 'No.';
$string['occurrencetime'] = 'Time';
$string['occurrencestatus'] = 'Status';
$string['occurrencepoints'] = 'Check-in points';
$string['occurrencecheckins'] = 'Check-ins';

// Grade aggregation.
$string['gradesection'] = 'Grade aggregation';
$string['gradesection_help'] = 'How the course gradebook score for this activity is derived when there is more than one attendance occurrence.';
$string['gradeaggregate'] = 'Aggregate grades by';
$string['gradeaggregate_help'] = '"Average of all occurrences" treats every class as equally important but ignores classes a student has no result for; "sum of all occurrences" counts those missing classes as zero, so every class is a fixed contribution to the total; "average of the best N" lets a student miss a few classes without losing the whole mark, which is the usual rule for classroom attendance.';
$string['gradeaggregate_all'] = 'Average of all occurrences';
$string['gradeaggregate_best'] = 'Average of the best N occurrences';
$string['gradeaggregate_sum'] = 'Sum of all occurrences';
$string['gradebestcount'] = 'Number of best occurrences';
$string['gradebestcount_help'] = 'Only used when aggregating by the best N. A value larger than the number of occurrences present is treated as the number present.';
$string['gradebestcount_invalid'] = 'The number of best occurrences must be between 1 and {$a}.';

// Whole-series report.
$string['reportall'] = 'Attendance across all occurrences';
$string['report_expectedcount'] = 'Expected {$a}';
$string['reportall_link'] = 'Report for all occurrences';
$string['reportone_link'] = 'Report for one occurrence';
$string['occurrence_short'] = '{$a}';
$string['attendancecount'] = 'Attended';
$string['aggregate_note_all'] = 'The grade column is the average of every occurrence the student has a result for.';
$string['aggregate_note_best'] = 'The grade column is the average of the student\'s best {$a} occurrences.';

// ---- Site administration ----
$string['maxsessions'] = 'Maximum occurrences per generation';
$string['maxsessions_desc'] = 'The most attendance occurrences a single save or rebuild can create for one activity. This bounds the work one form submission can trigger: a daily repeat with a mistyped end year would otherwise create thousands of sessions with their check-in points. The default of 60 suits a term of twice-weekly classes. Values above {$a} are reduced to {$a}.';

// ---- Teacher-side marking (v1.7.7) ----
// Students check in through checkin.php; this is the channel a teacher uses to
// add, correct or withdraw a record.
$string['marks_edit'] = 'Edit';
$string['marks_edit_link'] = 'Edit check-in records';
$string['marks_edit_heading'] = 'Check-in records for {$a}';
$string['marks_intro'] = 'Tick a point to record a check-in; unticking deletes that point\'s record. Times use the 24-hour clock and must fall inside the point\'s own window. Records saved here are marked as teacher entries.';
$string['mark_checked'] = 'Checked in';
$string['mark_time'] = 'Check-in time (HH:MM)';
$string['mark_window'] = 'This point is open {$a->open} - {$a->close}';
$string['mark_time_invalid'] = 'Use a 24-hour time of the form HH:MM, for example 08:05.';
$string['mark_time_outside'] = 'The time must fall inside this point\'s window ({$a->open} - {$a->close}).';
$string['mark_manual'] = 'Teacher entry';
$string['marks_clear'] = 'Delete all of this student\'s check-ins in this session';
$string['marks_cleared'] = 'Deleted every check-in {$a} had in this session.';
$string['marks_saved'] = 'Saved: {$a->added} added, {$a->updated} updated, {$a->removed} removed.';
$string['marks_some_rejected'] = '{$a} point(s) were not saved because the time was not valid.';
$string['marks_all_heading'] = 'Whole-class marking';
$string['marks_all_intro'] = 'One row per student, one cell per check-in point: tick to record a check-in, untick to delete it, and edit a time directly in its cell. One save covers the whole page.';
$string['marks_saveall'] = 'Save this page';
$string['marks_filled'] = 'Added {$a} missing record(s), timed at the start of each point\'s window.';
$string['marks_fillmissing'] = 'Record everyone on this page who has no check-in';
$string['marks_fillmissing_confirm'] = 'This adds a record for every point the {$a} students on this page have not checked in at yet, timed at the start of the point\'s window. Existing records are left alone. Continue?';
$string['marks_fillmissing_note'] = 'Filling in only closes gaps: a time a student recorded themselves is never overwritten.';
$string['marks_nostudents'] = 'This activity has no students who can check in yet.';
$string['marks_pages'] = '{$a->total} students in {$a->pages} pages of {$a->perpage}.';
$string['marks_truncated'] = 'Warning: this submission carried {$a->got} of {$a->want} cells, so part of the page did not arrive. Please retry, or use a smaller page.';
$string['markall_link'] = 'Whole-class marking';
$string['nopoints'] = 'This session has no check-in points yet. Add them on the session management page first.';

// ---- Geofence audit (v1.7.7) ----
// Read-only page: surfaces the decisions geo_manager already stores, to answer
// "why did the fence do nothing?".
$string['audit_heading'] = 'Geofence audit';
$string['audit_link'] = 'Geofence audit';
$string['audit_config'] = 'Current fence configuration';
$string['audit_enforceqr'] = 'Fence applies to QR scans';
$string['audit_centre'] = 'Classroom centre';
$string['audit_centre_unset'] = 'Not set';
$string['audit_ranges'] = 'Allowed IP ranges';
$string['audit_ranges_unset'] = 'Not configured';
$string['audit_notice_none'] = 'This activity uses the "no geofence" mode, so no check-in is checked against a location.';
$string['audit_notice_centre'] = 'The fence mode includes GPS but the classroom centre is still 0,0: there is no centre to compare against, so every attempt passes.';
$string['audit_notice_ranges'] = 'The fence mode includes an IP check but no allowed range is configured, so every attempt passes.';
$string['audit_notice_qr'] = '"Also apply the geofence to QR check-ins" is unticked for this activity, so scanning does not go through the fence (rows are marked "exempted as a QR scan").';
$string['audit_stats'] = 'Summary';
$string['audit_total'] = 'Attempts';
$string['audit_allowed'] = 'Passed';
$string['audit_rejected'] = 'Rejected';
$string['audit_filter'] = 'Filter:';
$string['audit_filter_all'] = 'All';
$string['audit_filter_allowed'] = 'Passed only';
$string['audit_filter_rejected'] = 'Rejected only';
$string['audit_col_time'] = 'Time';
$string['audit_col_method'] = 'Method';
$string['audit_col_verdict'] = 'Verdict';
$string['audit_col_reason'] = 'Reason';
$string['audit_col_lat'] = 'Reported latitude';
$string['audit_col_lng'] = 'Reported longitude';
$string['audit_col_acc'] = 'Accuracy (m)';
$string['audit_col_note'] = 'Note';
$string['audit_method_self'] = 'Self check-in';
$string['audit_method_qr'] = 'QR scan';
$string['audit_method_password'] = 'Password';
$string['audit_exempt_qr'] = 'Exempted as a QR scan';
$string['audit_exempt_mode'] = 'No fence at the time';
$string['audit_centre_missing'] = 'No centre set, passed silently';
$string['audit_ranges_missing'] = 'No range set, passed silently';
$string['audit_reason_unknown'] = 'No reason recorded';
$string['audit_empty'] = 'No geofence evaluations match. Either nobody has attempted a self check-in, or every attempt in this session bypassed the fence.';
$string['audit_distance_summary'] = 'Distance summary (last {$a->count} GPS evaluations): min {$a->min} m / median {$a->median} m / mean {$a->mean} m / max {$a->max} m. A median that is clearly too large, always in the same direction, usually means the classroom coordinates and the browser are on different datums.';
$string['audit_pages'] = '{$a->total} rows in {$a->pages} pages.';
$string['audit_note'] = 'This page is read-only and changes no verdict. Every self check-in (button, scan or password) leaves a row here; a teacher entry never passes through the fence, so it is not listed.';
$string['photo_capture'] = 'Photo check-in';
$string['photo_host_wechat'] = 'WeChat';
$string['photo_host_wework'] = 'WeCom';
$string['photo_host_qq'] = 'QQ';
$string['photo_host_dingtalk'] = 'DingTalk';
$string['photo_openinbrowser'] = 'Do not check in from {$a}: its camera crops the picture. Copy the link below into your phone browser and take it there.';
$string['photo_openinbrowser_menu'] = 'Do not check in from {$a}: its camera crops the picture. Open the menu at the top right, choose to open this page in your browser, and take the picture there.';
$string['photo_copylink'] = 'Copy the link';
$string['photo_copied'] = 'Copied';
$string['photo_copyfailed'] = 'Could not copy. Press and hold the link above to select it.';
$string['photo_intro'] = 'Take a live photograph now to record your check-in for "{$a}". The picture is taken here in the browser; this page has nowhere to upload one from.';
$string['photo_watermark'] = 'The photograph is stamped with:';
$string['photo_noretake'] = 'The picture is submitted the moment you take it, and this session does not allow a second one. It cannot be undone.';
$string['photo_shoot'] = 'Take the picture and submit';
$string['photo_shoot_confirm'] = 'The picture is submitted immediately and cannot be retaken. Continue?';
$string['photo_starting'] = 'Starting the camera...';
$string['photo_switchcam'] = 'Switch camera';
$string['photo_switchfailed'] = 'The camera could not be switched, so the previous one is back. Take the picture with it, or ask your teacher to verify you by hand.';
$string['photo_switching'] = 'Switching the camera...';
$string['photo_focused'] = 'Focused on the spot you tapped.';
$string['photo_ready'] = 'Camera ready. Press the button to take the picture.';
$string['photo_denied'] = 'The camera could not be started: permission was refused, the device has none, or the site is not served over HTTPS. Ask your teacher to verify you by hand instead.';
$string['photo_camera_failed'] = 'A picture could not be taken.';
$string['photo_noscript'] = 'Taking a photograph needs scripts enabled in your browser. This page has no upload fallback, so ask your teacher to verify you by hand.';
$string['photo_request_byhand'] = 'I cannot take one - ask my teacher to verify by hand';
$string['photo_request_short'] = 'Camera not working?';
$string['photo_required_notice'] = 'This session requires a photograph for every check-in. Open this page in your phone browser to check in, not in WeChat or a similar app: their built-in browser crops the picture. If the camera does not work, use the link beside the button to ask your teacher to verify you by hand.';
$string['photo_pass_expired'] = 'The photo pass has expired, or it belongs to a different point. Start the check-in again.';
$string['photo_checkin_recorded'] = 'Check-in recorded, with the photograph.';
$string['photo_view'] = 'View the photograph';
$string['photo_files'] = 'Check-in photographs';
$string['photo_store_failed'] = 'The photograph could not be saved, so no check-in was recorded. Please try again.';
$string['photo_app_fallback'] = 'The camera cannot be used inside the Moodle app. Ask your teacher to verify you by hand.';
$string['photoerr_notjpeg'] = 'That was not a JPEG. Please take the photograph again from this page.';
$string['photoerr_toobig'] = 'The picture is too large. Please take the photograph again.';
$string['photoerr_notimage'] = 'That was not an image. Please take the photograph again.';
$string['photoerr_toowide'] = 'The picture is larger than the accepted size. Please take the photograph again.';
$string['checkin_now_photo'] = 'Photo check-in';
$string['session_photorequired'] = 'Require a photograph';
$string['session_photorequired_help'] = 'When this is on, every check-in of this occurrence has to be proved with a photograph taken in the browser at that moment. There is no upload route, so a student whose camera does not work asks you to verify them by hand instead, and that request appears in the by-hand verification list. Marking the roll by hand is never asked for a photograph. The setting belongs to this occurrence alone: saving the activity form does not change it.';
$string['photoreq_title'] = 'Verify me by hand';
$string['photoreq_intro'] = 'Your camera could not be used for "{$a}". Say what happened, and your teacher will decide whether to record the check-in by hand. Nothing is recorded until they do.';
$string['photoreq_current'] = 'Your request: {$a->status}, raised {$a->time}.';
$string['photoreq_reason'] = 'What happened (optional)';
$string['photoreq_reason_help'] = 'For example: the phone has no camera / permission was refused / the browser does not support it.';
$string['photoreq_submit'] = 'Send the request';
$string['photoreq_submitted'] = 'Request sent. Your teacher will see it in the by-hand verification list.';
$string['photoreq_notrequired'] = 'This occurrence does not require a photograph, so there is nothing to verify by hand.';
$string['photoreq_manage'] = 'By-hand verification';
$string['photoreq_manage_intro'] = 'Requests students raised when their camera did not work. Approving one records the check-in as a teacher entry.';
$string['photoreq_none'] = 'Nothing is waiting.';
$string['photoreq_showall'] = 'Show every request';
$string['photoreq_showpending'] = 'Show only the ones waiting';
$string['photoreq_waiting'] = 'Waiting';
$string['photoreq_session'] = 'Occurrence';
$string['photoreq_student'] = 'Student';
$string['photoreq_submittedat'] = 'Raised';
$string['photoreq_reviewedby'] = 'Decided by';
$string['photoreq_approve'] = 'Approve and record';
$string['photoreq_refuse'] = 'Refuse';
$string['photoreq_approved_ok'] = 'Approved, and the check-in was recorded by hand.';
$string['photoreq_refused_ok'] = 'Refused.';
$string['photoreq_notfound'] = 'That request does not belong to this activity.';
$string['toolbar_students'] = 'For students';
$string['toolbar_teachers'] = 'For teachers';
$string['privacy:metadata:multicheckin_photoreq'] = 'A request a student raised when their camera could not be used, and the teacher who decided it.';
$string['privacy:metadata:multicheckin_photoreq:userid'] = 'The student who raised the request.';
$string['privacy:metadata:multicheckin_photoreq:reason'] = 'The explanation the student gave.';
$string['privacy:metadata:multicheckin_photoreq:status'] = 'Whether the request is still waiting, approved or refused.';
$string['privacy:metadata:multicheckin_photoreq:reviewedby'] = 'The teacher who decided it.';
$string['privacy:metadata:multicheckin_photoreq:timecreated'] = 'When the request was raised.';
// The capture permit. It is a row rather than a session entry as of 2.0.23,
// which is what lets a copied link work in another browser - and why the row
// has to name its owner.
$string['privacy:metadata:multicheckin_pass'] = 'A short-lived permit to open the camera for one check-in point, so the same link works in any browser the student is signed into.';
$string['privacy:metadata:multicheckin_pass:userid'] = 'The student the permit was issued to. Nobody else can use it.';
$string['privacy:metadata:multicheckin_pass:clientgeo'] = 'The position that satisfied the location fence when the permit was issued, re-checked when the check-in is recorded.';
$string['privacy:metadata:multicheckin_pass:timecreated'] = 'When the permit was issued. It lapses ten minutes later.';
