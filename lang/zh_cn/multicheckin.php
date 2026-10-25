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
 * Chinese (simplified) language strings for mod_multicheckin.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['photoreq_pending'] = '待教师人工核验';
$string['photoreq_approved'] = '已由教师人工补签';
$string['photoreq_refused'] = '已驳回';
$string['pluginname'] = '多次签到考勤';
$string['pluginnameplural'] = '多次签到考勤';
$string['modulename'] = '多次签到考勤';
$string['modulenameplural'] = '多次签到考勤';
$string['modulename_help'] = '创建一次考勤，允许在一节课内设置多个签到点（签到、课中抽查、签退）。系统会根据各签到点的时间自动判定学生为出勤、迟到、早退或旷课。';
$string['multicheckin:addinstance'] = '添加多次签到考勤';
$string['multicheckin:view'] = '查看多次签到考勤';
$string['multicheckin:checkin'] = '进行签到';
$string['multicheckin:manage'] = '管理签到点与考勤场次';
$string['multicheckin:viewreports'] = '查看考勤报表';
$string['multicheckin:recompute'] = '重新计算考勤状态';
$string['privacy:metadata'] = '多次签到考勤插件会记录签到信息（用户、时间、IP），用于考勤统计。';

// 分区与字段。
$string['timing'] = '时间与判定规则';
$string['timestart'] = '考勤起点时间（T0）';
$string['timestart_help'] = '所有签到窗口以及迟到、早退规则都以该时间为基准计算。';
$string['timeend'] = '考勤结束时间';
$string['lategrace'] = '迟到宽限时间';
$string['lategrace_help'] = '如果第一次（签到点）签到时间晚于"起点时间 + 该宽限时间"，则判定为迟到。';
$string['requiresignout'] = '需要签退';
$string['requiresignout_help'] = '当场次设有「下课签到」时保持开启：始终未签退的学生记为早退。若课程只在开始时考勤、开始签到成功即为完整记录，请关闭此项——此时状态只有「正常」或「迟到」，不再判定早退，也不扣早退分。';
$string['earlygrace'] = '早退宽限时间';
$string['earlygrace_help'] = '如果最后一次（签退点）签到时间早于"结束时间 - 该宽限时间"，则判定为早退。';
$string['timeend_before_start'] = '结束时间必须晚于起点时间。';
$string['earlymode'] = '早退判定方式';
$string['earlymode_help'] = '选择"早退"的判定依据：按签退点的时间窗判定，或按离开时间与结束时间的比较判定。';
$string['earlymode_window'] = '按签到点时间窗（推荐）';
$string['earlymode_threshold'] = '按离开时间与结束时间比较';
$string['selfcheckinsection'] = '自助签到';
$string['allowselfcheckin'] = '允许学生自助签到';
$string['allowselfcheckin_help'] = '启用后，学生可在签到点开放期间于活动页面自助签到（可选用密码保护）。';
$string['selfcheckinpassword'] = '自助签到密码（可选）';
$string['selfcheckinpassword_help'] = '设置后，学生自助签到需输入该密码。留空则不设密码。签到点单独设置的密码优先于此。';
$string['qronly'] = '仅限扫码签到（不显示按钮、密码无效）';
$string['qronly_help'] = '勾选后，本场次所有签到点都关闭签到按钮与密码，学生只能扫描教师屏幕上的二维码签到。比上面的「允许学生自助签到」更严格——那个只是不显示按钮。签到点页面可对单个点另行设置。注意：二维码只证明拿到了当前这一格的码，不证明是谁在扫，因此不能阻止学生把码拍照转给他人；把轮换时间设短些会增加难度。';
$string['audience'] = '签到对象';
$string['audience_help'] = '勾选哪些角色算作本活动的参与者。签到表、报表与成绩计算均以此名单为准：只有此处勾选的角色才会被列入名单（也才会被记为缺勤）。管理员始终不在名单内。默认为仅学生。';
$string['audience_role_student'] = '学生（默认）';
$string['audience_empty'] = '所选角色下没有已选课的用户，名单已回退为学生。';
$string['notifysection'] = '签到提醒';
$string['notifyenabled'] = '提醒学生进行后续签到';
$string['notifyenabled_help'] = '默认关闭，需要时自行勾选：签到本身已经说明问题，不该一建活动就给全班发消息。开启后，签到点到了该提醒的时候，向该点对应的人发送提醒（站内消息、邮件与移动端推送）。哪些点位发提醒、发给谁，由下面的规则决定。';
$string['notifyleadtime'] = '提醒提前时间（秒）';
$string['notifyleadtime_help'] = '签到点开放前多少秒就可以发出提醒。提醒里会写明该点位是否已经开放，因此提前提醒不会把学生指到一个还点不动的按钮上。0 = 只在开放后才发。';
$string['notifyrule'] = '哪些签到发提醒';
$string['notifyrule_help'] = '一个场次的第一个签到点就是签到，通常应该提醒全班。之后各个点位发不发、发给谁，由这里决定。已经签过某个点位的学生不会就同一个点位再收到一次；已请假的跳过；点位自己关掉提醒的，一律不发。';
$string['notifyrule_chain'] = '首次签到发给全班；之后每个点位只提醒在上一个点位已签到的学生';
$string['notifyrule_all'] = '每个签到点都发给尚未签到的学生';
$string['notifyrule_first'] = '每个场次只有第一个签到点发提醒';

// 地理围栏（防代签）。
$string['geosection'] = '防代签地理围栏';
$string['geomode'] = '围栏模式';
$string['geomode_help'] = '限制学生必须在校内现场才能自助签到。IP 校验校园网段；GPS 校验浏览器上报的坐标是否落在教室半径内；“两者都要”则须同时通过。除非在下方勾选，否则扫码签到不受围栏限制。';
$string['geomode_none'] = '不启用围栏';
$string['geomode_ip'] = '仅校验 IP / 校园网';
$string['geomode_gps'] = '仅校验 GPS 半径';
$string['geomode_both'] = 'IP 与 GPS 都要校验';
$string['geoipranges'] = '允许的 IP 网段';
$string['geoipranges_help'] = '每行一个地址或 CIDR 网段，IPv4 与 IPv6 都可以：192.168.10.0/24、10.0.0.5、2001:db8::/32。以 # 开头的行会被忽略。地址只与同一地址族的网段比较，所以只填 IPv4 网段时 IPv6 的学生会被拒绝——请把校园网下发的 IPv6 网段一并填上。模式选了按 IP 围栏却留空这里，等于不启用围栏（这是有意的：空名单不能把全班锁在门外）。';
$string['geoipranges_invalid'] = '这不是有效的 IP 地址或 CIDR 网段：{$a}';
$string['geolat'] = '教室纬度';
$string['geolat_help'] = '教室中心的纬度（十进制度，例如 30.4635000）。';
$string['geolng'] = '教室经度';
$string['geolng_help'] = '教室中心点的经度，十进制度（例如 114.1234000）。'
    . '请按 WGS-84 基准填写，即浏览器或手机直接读到的坐标。';
$string['georadius'] = '允许半径（米）';
$string['georadius_help'] = '当上报位置与中心的距离在此范围内时判定通过。取值范围 10–5000 米；阶梯教室用 100 米较合适。';
$string['georadius_range'] = '半径必须在 10 至 5000 米之间。';
$string['geocoords_invalid'] = '请填写有效的教室纬度与经度。';
$string['geoenforceqr'] = '扫码签到同样受围栏限制';
$string['geoenforceqr_help'] = '默认开启：扫码签到的学生也须同时满足围栏条件。若课堂上「投影二维码、扫到即视为在场」本身就是证明，可取消勾选，跳过对扫码的位置校验。';
$string['showgeoaudit'] = '显示「围栏审计」入口';
$string['showgeoaudit_help'] = '围栏审计页用于排查「围栏好像没起作用」。入口默认不显示，以免占用日常页面；页面本身对有管理权限的人始终可通过网址直接打开，此处只是把入口放出来。';
$string['geofence_notice_ip'] = '自助签到仅限校园网内使用，请连接校园 Wi-Fi 后再签到。';
$string['geofence_notice_gps'] = '自助签到需要获取您的位置，浏览器会弹出授权提示，系统将据此核对您是否在教室内。';
$string['geofence_ok'] = '位置校验通过。';
$string['geofence_denied'] = '无法核验您的位置，签到未通过。请联系任课教师补签。';
$string['geofence_ip_denied'] = '您当前不在校园网内，无法自助签到。请连接校园 Wi-Fi 后重试。';
$string['geofence_out_of_range'] = '您当前似乎不在教室内，无法自助签到。';
$string['geofence_no_gps'] = '未能读取您的位置。请在浏览器中允许定位后重试。';
$string['geo_locating'] = '正在核验位置…';
$string['geo_denied'] = '定位授权被拒绝，请允许定位后再签到。';
$string['geo_unsupported'] = '当前浏览器无法上报位置，将不带位置继续。';
$string['privacy_geolog'] = '围栏校验记录';
$string['geolog_note'] = '仅显示本场次最近 100 条围栏校验记录。';
$string['ip'] = 'IP 地址';

// 二维码刷新。
$string['qrsection'] = '二维码选项';
$string['qrrotation'] = '二维码刷新间隔（秒）';
$string['qrrotation_help'] = '签名二维码令牌的更换频率。越短越安全（截图更快失效），但图片刷新也更频繁。允许范围 5–300 秒，15 秒是较均衡的取值。';
$string['qrrotation_range'] = '刷新间隔必须在 {$a->min} 至 {$a->max} 秒之间。';


// 签到点。
$string['pointname'] = '签到点';
$string['point_new'] = '签到点 {$a}';
$string['point_signin'] = '签到';
$string['point_mid'] = '课中抽查';
$string['point_signout'] = '签退';
$string['pointwindow'] = '开放时间窗';
$string['pointrole'] = '角色';
$string['role_first'] = '第一个（签到）';
$string['role_mid'] = '中间（抽查）';
$string['role_last'] = '最后一个（签退）';
$string['required'] = '必签';
$string['pointpassword'] = '密码（可选）';
$string['pointqrcode'] = '显示轮换二维码';
$string['pointqrcode_help'] = '显示每 15 秒自动刷新的二维码。学生扫码即可签到、无需密码，且截屏分享会很快失效。';
$string['pointqronly'] = '仅限扫码签到';
$string['pointqronly_help'] = '该签到点只认轮换二维码：签到按钮与密码在此点失效。即使未勾选「显示轮换二维码」，教师端也照常出码，因为扫码是这个点唯一的签到方式。';
$string['pointnotify'] = '该签到点开放时发送提醒';
$string['openoffset'] = '开放（相对起点的偏移）';
$string['openoffset_help'] = '相对考勤起点时间（T0）的秒数偏移。负值表示在考勤开始前开放。';
$string['closeoffset'] = '关闭（相对起点的偏移）';

// 状态。
$string['status'] = '状态';
$string['status_present'] = '出勤';
$string['status_late'] = '迟到';
$string['status_early'] = '早退';
$string['status_late_early'] = '迟到且早退';
$string['status_absent'] = '旷课';
$string['status_leave'] = '请假';
$string['open'] = '开放中';
$string['closed'] = '已关闭';
$string['mystatus'] = '我的状态';

// 请假。
$string['leave'] = '请假';
$string['leave_request'] = '申请请假';
$string['leave_request_intro'] = '申请本场次请假。提交后立即生效，教师之后仍可复核。';
$string['leave_reason'] = '请假理由（选填）';
$string['leave_reason_help'] = '给任课教师的一句说明，可以留空。';
$string['leave_attachment'] = '证明材料（选填）';
$string['leave_attachment_help'] = '一张图片（JPG、PNG、GIF 或 WEBP）或一个 PDF 文件，例如请假条照片。仅你本人与任课教师可见。';
$string['leave_submit'] = '提交请假';
$string['leave_submitted'] = '本场次请假已记录。';
$string['leave_already'] = '本场次已有请假记录。';
$string['leave_haslog'] = '你在本场次已有签到记录，不能再转为请假。';
$string['leave_closed'] = '本场次已结束，无法再申请请假。';
$string['leave_notallowed'] = '你无法对本场次申请请假。';
$string['leave_filetype'] = '请上传图片或 PDF 文件。';
$string['leave_appnote'] = '上传材料在网页浏览器中更可靠。你可以先在此提交请假，之后用浏览器补传材料。';
$string['leave_scan_hint'] = '扫码只用于签到；请假请在活动页面提交。';
$string['leave_back'] = '返回活动';
$string['leave_for'] = '{$a} 的请假';
$string['leave_none'] = '本活动暂无请假记录。';
$string['leaves'] = '请假记录';
$string['leaves_heading'] = '请假记录：{$a}';
$string['leave_grant'] = '请假';
$string['leave_grant_help'] = '把该学生本场次记为请假。签到记录会保留：取消请假后即按签到记录重新判定。';
$string['leave_granted'] = '已为 {$a} 名学生记为请假。';
$string['leave_cleared'] = '已为 {$a} 名学生取消请假。';
$string['leave_pending'] = '待复核';
$string['leave_reviewed'] = '已复核';
$string['leave_markreviewed'] = '标记为已复核';
$string['leave_reviewed_ok'] = '该请假已标记为已复核。';
$string['leave_origin'] = '来源';
$string['leave_origin_student'] = '学生申请';
$string['leave_origin_teacher'] = '教师设置';
$string['leave_attachment_none'] = '无证明材料';
$string['leave_download'] = '下载请假条';
$string['leave_col_session'] = '场次';
$string['leave_col_reason'] = '请假理由';
$string['leave_col_time'] = '提交时间';
$string['leave_pendingcount'] = '{$a} 条请假待复核';
$string['leaves_showall'] = '显示全部请假记录';
$string['leave_clear_link'] = '取消请假';
$string['leave_clear_confirm'] = '确定取消该请假？签到记录会保留，状态将按签到记录重新判定。';
$string['leave_reviewall'] = '全部标记为已复核';
$string['leaves_note'] = '请假提交即生效，本页用于事后复核。';

// 操作与提示。
$string['action'] = '操作';
$string['checkin_now'] = '立即签到';
$string['checkin_recorded_ok'] = '签到成功，已记录。';
$string['point_closed'] = '该签到点当前不在开放时间内。';
$string['already_checked_in'] = '你在该签到点已签到。';
$string['password_invalid'] = '密码不正确。';
$string['selfcheckin_disabled'] = '本考勤未启用自助签到。';
$string['qr_invalid'] = '该二维码已失效或无效，请扫描屏幕上当前显示的二维码。';
$string['qr_rotate_hint'] = '每 {$a} 秒自动刷新。';
$string['qr_scan_in_browser'] = '此签到链接需要在浏览器中直接打开，不能由其他页面嵌入加载。请用手机相机扫描屏幕上的二维码，或直接点击其链接后重试。';
$string['qrcodes'] = '签到二维码';
$string['scan_qr_hint'] = '请扫描屏幕上的二维码';
$string['qrwindow_open'] = '签到二维码';
$string['qrwindow_title'] = '签到二维码 — {$a}';
$string['qrwindow_closed_notice'] = '本场次当前不在签到时段：学生此刻扫码会被拒绝。二维码每 {$a} 秒更新一次，请在开放时段内投影。';
$string['qrwindow_point_notenabled'] = '该签到点未勾选「二维码」方式（扫码仍可签到）。';
$string['qronly_only_scan'] = '该签到点只能扫描教师屏幕上的二维码签到。';
$string['qronly_badge'] = '仅扫码';
$string['qronly_force_show'] = '此点仅限扫码：即使未勾选「二维码」方式，也照常出码。';
$string['qronly_email_hint'] = '该签到点仅认教师屏幕上的二维码，页面上没有签到按钮。';
$string['qrwindow_nopoints'] = '本场次还没有签到点，无法生成二维码。';
$string['qrwindow_hint'] = '本窗口可缩放、可移到副屏；同一场次重复点击只会复用这一个窗口。';
$string['mycheckin'] = '我的签到';
$string['checked_in'] = '已签到';
$string['managepoints'] = '管理签到点';
$string['viewreport'] = '考勤报表';
$string['addpoint'] = '新增签到点';
$string['point_added'] = '签到点已添加。';
$string['point_deleted'] = '签到点已删除。';
$string['pointsupdated'] = '签到点已更新。';
$string['recompute'] = '重新计算状态';
$string['recomputed'] = '已为 {$a} 名用户重新计算状态。';
$string['sendremindersnow'] = '立即发送提醒';
$string['reminders_sent'] = '已发送 {$a} 条提醒。';
$string['exportcsv'] = '导出 CSV';
$string['resetcheckindata'] = '删除全部签到数据';
$string['resetseries'] = '删除批量生成的考勤场次（{$a} 个）';

// 报表列。
$string['user'] = '用户';
$string['studentid'] = '学号';
$string['firstcheckin'] = '首次签到';
$string['lastcheckin'] = '最后签到';
$string['latemin'] = '迟到（分钟）';
$string['earlymin'] = '早退（分钟）';
$string['points'] = '得分';

// 同步备注。
$string['remark_late'] = '迟到 {$a} 分钟';
$string['remark_early'] = '早退 {$a} 分钟';

// 事件与任务。
$string['event_checkin_recorded'] = '已记录签到';
$string['task_finalise_sessions'] = '结算已结束的多次签到考勤场次';
$string['task_send_reminders'] = '发送多次签到考勤提醒';

// 提醒消息。
$string['messageprovider:nextcheckin_reminder'] = '后续签到提醒';
$string['msg_reminder_subject'] = '【{$a->course}】{$a->session} 签到提醒';
$string['msg_reminder_small'] = '记得签到：{$a->course} / {$a->session}';
$string['msg_reminder_linktext'] = '去签到';
$string['msg_reminder_unknowncourse'] = '未知课程';
$string['msg_reminder_body'] = '<p>课程「{$a->course}」的活动「{$a->session}」需要签到。</p><p>签到点「{$a->point}」现已开放，截止 {$a->time}，请记得签到。</p>{$a->linksection}';
$string['msg_reminder_body_soon'] = '<p>课程「{$a->course}」的活动「{$a->session}」需要签到。</p><p>签到点「{$a->point}」将于 {$a->opens} 开放，截止 {$a->time}，请提前准备签到。</p>{$a->linksection}';
$string['msg_reminder_linksection'] = '<p>直接打开签到页面（下面的地址已定位到签到点「{$a->point}」）：{$a->link}<br />若链接无法点击，请把下面的地址复制到浏览器打开：<br />{$a->url}</p>';
$string['msg_reminder_body_nolink'] = '<p>课程「{$a->course}」的活动「{$a->session}」需要签到。</p><p>签到点「{$a->point}」现已开放，截止 {$a->time}，请记得签到。</p>';
$string['msg_reminder_body_soon_nolink'] = '<p>课程「{$a->course}」的活动「{$a->session}」需要签到。</p><p>签到点「{$a->point}」将于 {$a->opens} 开放，截止 {$a->time}，请提前准备签到。</p>';

// 隐私。
$string['privacy:metadata:multicheckin_log'] = '用户的签到记录信息。';
$string['privacy:metadata:multicheckin_log:userid'] = '执行签到的用户。';
$string['privacy:metadata:multicheckin_log:timecreated'] = '签到记录时间。';
$string['privacy:metadata:multicheckin_log:ip'] = '签到来源 IP 地址。';
$string['privacy:metadata:multicheckin_result'] = '用户的计算考勤状态。';
$string['privacy:metadata:multicheckin_result:statuscode'] = '计算得到的状态（出勤、迟到、早退或旷课）。';
$string['privacy:metadata:multicheckin_notify'] = '已向用户发送签到提醒的记录。';
$string['privacy:metadata:multicheckin_notify:userid'] = '接收提醒的用户。';
$string['privacy:metadata:multicheckin_notify:timesent'] = '提醒发送时间。';
$string['privacy:metadata:multicheckin_geo_log'] = '每次自助签到围栏校验的审计记录。';
$string['privacy:metadata:multicheckin_geo_log:userid'] = '尝试签到的用户。';
$string['privacy:metadata:multicheckin_geo_log:ip'] = '该次尝试的来源 IP 地址。';
$string['privacy:metadata:multicheckin_geo_log:latitude'] = '启用 GPS 围栏时浏览器上报的纬度。';
$string['privacy:metadata:multicheckin_geo_log:longitude'] = '启用 GPS 围栏时浏览器上报的经度。';
$string['privacy:metadata:multicheckin_geo_log:timecreated'] = '围栏校验时间。';
$string['privacy:metadata:core_files'] = '本活动保存两类文件：请假申请可附带的一份选填证明材料，以及场次要求拍照签到时当场拍摄的现场照。';
$string['privacy:metadata:multicheckin_leave'] = '用户对某一场次的请假声明，含选填的理由与证明材料。';
$string['privacy:metadata:multicheckin_leave:userid'] = '该请假所属的用户。';
$string['privacy:metadata:multicheckin_leave:reason'] = '请假理由。';
$string['privacy:metadata:multicheckin_leave:origin'] = '该请假由学生申请还是由教师设置。';
$string['privacy:metadata:multicheckin_leave:reviewed'] = '教师是否已复核该请假。';
$string['privacy:metadata:multicheckin_leave:reviewedby'] = '复核该请假的教师。';
$string['privacy:metadata:multicheckin_leave:timecreated'] = '该请假的记录时间。';
$string['privacy:metadata:multicheckin_leave:timemodified'] = '该请假最后一次修改的时间。';

// ---- 共用教室位置库 ----
//
// 全校共享的教室清单，教师直接选择即可，无需在每次活动中重复输入坐标。
$string['placesection'] = '常用教室';
$string['placeselectnone'] = '请选择常用教室……';
$string['sharedplaceselect'] = '共享教室（来自地理定位插件）';
$string['sharedplaceselect_help'] = '来自「地理位置限制」（availability_gpsloc）插件维护的共享教室库。选中后会把该教室的坐标与半径填入下方字段；在你保存之前，活动本身不会记录任何内容。仅当该插件已安装时此项才会出现，不强制任何站点安装它。';
$string['sharedplacenone'] = '请选择教室……';
$string['sharedplacehint'] = '选中教室将自动填写下方的纬度、经度与半径。数值均为 WGS-84，与浏览器上报的基准一致。';
$string['sharedplaceunavailable'] = '本站未启用共享教室库。请在下方手动填写纬度、经度与半径。';
$string['placesection_help'] = '从共享库中选择教室，即自动填入下方坐标与半径。可在管理页新增或修正教室。坐标始终采用 WGS-84，与浏览器上报的基准一致，因此同一间教室只录一次，对所有学生的判定都正确。';
$string['place_pickerhint'] = '选择常用教室会填入下方的坐标与半径；也可以继续手动输入。';
$string['place_choosedots'] = '选择常用教室…';
$string['place_usedot'] = '（使用中）';
$string['place_savebutton'] = '将当前坐标存为常用教室';
$string['place_saving'] = '正在保存…';
$string['place_saved'] = '该教室已保存到共享库。';
$string['place_nocoords'] = '请先填写坐标，再保存为常用教室。';
$string['place_nameprompt'] = '为该教室命名（例如：主楼 A301）：';
$string['place_newname'] = '教室名称不能为空。';
$string['place_dupewarn'] = '已存在名为“{$a}”的教室。仍要再保存一个吗？';
$string['place_duplicatewarning'] = '提示：已存在同名教室。';

// 管理页。
$string['place_manage'] = '管理常用教室';
$string['place_managetitle'] = '常用教室';
$string['place_managedesc'] = '这些教室在本站所有课程间共享。在活动中选中教室时会将其坐标复制到该活动，因此在此修正错误录入不会改变已经在使用旧坐标的活动——那些活动需单独编辑。';
$string['place_add'] = '新增教室';
$string['place_edit'] = '编辑教室';
$string['place_backtolist'] = '返回列表';
$string['place_save'] = '保存教室';
$string['placecreated'] = '教室已新增。';
$string['placeupdated'] = '教室已更新。';
$string['placenone'] = '尚未保存任何教室。';
$string['placenotfound'] = '该教室已不存在。';
$string['placedeleteconfirm'] = '确定删除常用教室“{$a}”吗？';
$string['placedeleted'] = '常用教室“{$a}”已删除。';
$string['placedeletedinuse'] = '仍有 {$a} 个活动使用相同坐标；它们不受影响，继续正常工作。';
$string['place_deletenote'] = '删除教室只会将其从此列表中移除。已经使用该坐标的活动继续按原样运行。';
$string['placeusage'] = '使用情况';
$string['placeusecount'] = '{$a} 个活动';
$string['placeusezero'] = '未被使用';
$string['placemodified'] = '最后修改';

// 字段标签。
$string['placename'] = '教室名称';
$string['placename_help'] = '该教室在下拉列表中的显示名称，例如“主楼 A301”或“阶梯教室 3”。不同楼栋的同名教室可以重名。';
$string['placedescription'] = '备注';
$string['placedescription_help'] = '选填。选中该教室时作为提示显示，例如“走北门进入”。';
$string['placelatitude'] = '纬度';
$string['placelatitude_help'] = '十进制 WGS-84 纬度，例如 30.592800。这是浏览器上报的基准，而非国内商业地图偏移后的 GCJ-02——请勿直接照抄高德或百度地图上的数值。';
$string['place_mappick'] = '点击地图自动填入坐标，也可拖动标记微调。存储值为 WGS-84 坐标。';
$string['placelongitude'] = '经度';
$string['placedefaultradius'] = '默认半径（米）';
$string['placedefaultradius_help'] = '选中该教室时建议使用的半径，单位为米。阶梯教室用 100 米较合适。';

// 校验。
$string['placename_required'] = '请填写教室名称。';
$string['placename_toolong'] = '名称过长，请控制在 255 个字符以内。';
$string['placecoords_required'] = '请同时填写纬度和经度。';
$string['placecoords_unset'] = '经纬度不能同时为零，请填写教室的实际位置。';
$string['placelat_range'] = '纬度须在 -90 至 90 之间。';
$string['placelng_range'] = '经度须在 -180 至 180 之间。';
$string['placeradius_range'] = '半径须在 10 至 50000 米之间。';
$string['placeerror_generic'] = '教室保存失败，请检查各项取值后重试。';

// 半径格式，与条件描述共用。
$string['radiusm'] = '{$a} 米';
$string['radiuskm'] = '{$a} 公里';

// 权限。
$string['multicheckin:manageplaces'] = '管理共用教室位置库';

// 隐私：共用教室位置库。
$string['privacy:metadata:multicheckin_place'] = '共用教室位置库。其中的坐标描述的是建筑物而非个人；唯一的个人数据是最后编辑该记录的用户。';
$string['privacy:metadata:multicheckin_place:usermodified'] = '最后新增或编辑该教室的用户。';
$string['privacy:metadata:multicheckin_place:timecreated'] = '该教室首次保存的时间。';
$string['privacy:metadata:multicheckin_place:timemodified'] = '该教室最后编辑的时间。';

// ---- 中间考勤（v1.4.0） ----
$string['midsection'] = '中间考勤';
$string['midsection_help'] = '签到与签退之间的点名轮次。在此设置次数与每轮的开始时间；精细调整（精确到秒、逐点密码、二维码、提醒）仍在「管理签到点」页面进行。修改次数时尽量保留现有签到点及其记录：末尾多余的点连同记录删除，缺少的按序补建。';
$string['midcount'] = '中间考勤次数';
$string['midcount_help'] = '签到与签退之间进行几次点名。默认为 0，即新建活动只有签到与签退两个点；需要课中点名时把次数调高即可。';
$string['midcount_zero_hint'] = '当前为 0：只有签到与签退。如需在课中增加一次点名，把上方次数改为 1 或更大。';
$string['midopenlabel'] = '第 {$a} 次中间考勤：开始时间（开课后第几分钟）';
$string['midopen'] = '开始时间';
$string['midopen_help'] = '本次中间考勤窗口在开课（T0）后第几分钟打开。例如 45 表示开课 45 分钟后开始。';
$string['midwindow'] = '窗口时长（分钟）';
$string['midwindow_help'] = '每次中间考勤从开始时间起保持开放的分钟数。保存后仍可在管理页逐点单独调整。';
$string['midcount_invalid'] = '中间考勤次数须在 0–10 之间。';
$string['midopen_invalid'] = '开始时间须在 0–2880 分钟之间。';
$string['midwindow_invalid'] = '窗口时长须在 1–480 分钟之间。';
$string['midpoints_synced'] = '已按设置同步 {$a->sessions} 个场次的中间签到：新增 {$a->added} 个、删除 {$a->removed} 个、补签 {$a->signed} 条。已有签到记录的点位已保留。';

// ---- 各状态分值（v1.4.0） ----
$string['statuspointssection'] = '各状态计分分值';
$string['statuspointssection_help'] = '每种最终状态获得的原始分值。课程成绩 = 所得分值 ÷ 各状态中的最大分值 × 上方最高分——即默认设置下「正常」得该活动满分，「迟到」得一半，依此类推。每次签到后、重新计算后、场次结束时都会自动推送成绩到课程成绩册。';
$string['pointspresent'] = '正常（分值）';
$string['pointspresent_help'] = '最终状态为「正常」时的分值。通常应为最大值，即本场次满分。';
$string['pointslate'] = '迟到（分值）';
$string['pointsearly'] = '早退（分值）';
$string['pointslateearly'] = '迟到且早退（分值）';
$string['pointsabsent'] = '缺勤（分值）';
$string['pointsleave'] = '请假（分值）';
$string['statuspoints_invalid'] = '分值须在 0–1000 之间。';

// ---- 批量考勤场次（v1.7.0） ----
//
// 一个活动承载一学期的多次考勤：活动本身保存共用配置（宽限、围栏、分值、
// 中间考勤结构），下面挂若干「场次」，每个场次有自己的日期与时间。
$string['seriessection'] = '批量考勤场次';
$string['seriessection_help'] = '为本次教学周期一次性生成多个考勤场次。活动本身保存共用设置（时间规则、围栏、二维码、分值、中间考勤结构），每个场次各自拥有日期与签到点。不启用批量时，活动本身就是唯一的一场考勤，行为与以前完全一致。';
$string['series_repeat'] = '重复方式';
$string['series_repeat_help'] = '「不重复」保持单场考勤；「按周」在一周中挑几天重复；「按天」按固定天数间隔重复；「无规律」逐条填写每一场的日期和时间。';
$string['series_repeat_none'] = '不重复（单场考勤）';
$string['series_repeat_weekly'] = '按周重复';
$string['series_repeat_daily'] = '按天重复';
$string['series_repeat_custom'] = '无规律（逐场填写日期）';
$string['series_weekdays'] = '星期';
$string['series_weekdays_help'] = '每周在这些星期几各安排一场考勤。可多选；同一天只生成一场。';
$string['weekday_1'] = '周一';
$string['weekday_2'] = '周二';
$string['weekday_3'] = '周三';
$string['weekday_4'] = '周四';
$string['weekday_5'] = '周五';
$string['weekday_6'] = '周六';
$string['weekday_7'] = '周日';
$string['series_interval'] = '间隔天数';
$string['series_interval_help'] = '每隔多少天安排一场考勤。1 表示每天，2 表示隔天，7 表示每周一次。';
$string['series_datefrom'] = '开始日期';
$string['series_datefrom_help'] = '批量生成的起始日期。当天若正好是所选的星期，也会生成一场。';
$string['series_dateto'] = '结束日期';
$string['series_dateto_help'] = '批量生成的截止日期。按日期而非时刻比较，因此结束当天的场次一定会包含在内。';
$string['series_timefrom'] = '每次开始时刻';
$string['series_timefrom_help'] = '每个场次的开始时刻，格式为 HH:MM。场次时长沿用上方的「考勤起点时间」与「考勤结束时间」之差。';
$string['series_preview'] = '将生成的场次';
$string['series_preview_none'] = '尚未配置重复规则。';
$string['series_preview_hint'] = '下方日期在保存时生成；已存在的同名同时刻场次会被跳过，不会重复。';
$string['series_preview_more'] = '……等共 {$a} 场';
$string['series_preview_capped'] = '已达到单次生成上限 {$a} 场。若需更多，请缩短日期范围，或由管理员调整上限。';
$string['series_preview_count'] = '共 {$a} 场';
$string['series_daterange_invalid'] = '结束日期必须晚于开始日期。';
$string['series_timefrom_invalid'] = '请按 HH:MM 格式填写开始时刻，例如 08:30。';
$string['series_weekdays_required'] = '按周重复时至少要选择一个星期。';
$string['series_interval_invalid'] = '间隔天数须在 1–365 之间。';
$string['series_customcount'] = '场次数';
$string['series_customcount_help'] = '本次考勤共几场。选好后下方会出现相应数量的「日期 + 时间」行，一行即一场；可设置的上限为本站点允许的单次生成上限。';
$string['series_customcount_invalid'] = '场次数须在 1–{$a} 之间。';
$string['series_customrow'] = '第 {$a} 场';
$string['series_customdate_hint'] = 'YYYY-MM-DD';
$string['series_customtime_hint'] = 'HH:MM';
$string['series_customrow_invalid'] = '第 {$a} 场：请按 YYYY-MM-DD 填写真实日期、按 HH:MM 填写时间。';
$string['series_customrows_required'] = '至少填写一场的日期与时间，或改选其他重复方式。';

// 场次列表与批量管理。
$string['occurrences'] = '考勤场次';
$string['occurrence_n'] = '第 {$a} 场';
$string['occurrence_select'] = '选择场次';
$string['occurrence_none'] = '本活动尚未生成批量场次，当前按单场考勤运行。';
$string['occurrence_count'] = '共 {$a} 场';
$string['occurrence_current'] = '进行中';
$string['occurrence_past'] = '已结束';
$string['occurrence_future'] = '未开始';
$string['occurrence_manageall'] = '批量管理场次';
$string['series_manage'] = '批量管理考勤场次';
$string['series_manage_desc'] = '本活动共有 {$a->total} 个考勤场次，其中 {$a->withdata} 个已经产生签到记录。';
$string['series_regenerate'] = '按当前规则重建';
$string['series_regenerate_desc'] = '按活动设置中的重复规则，补齐缺失的场次并重新编号。已有的场次、签到点与签到记录都会保留。';
$string['series_regenerated'] = '已补齐 {$a} 个场次。';
$string['series_clear'] = '清空全部场次';
$string['series_clear_desc'] = '删除本活动下生成的全部场次及其签到点。<strong>已产生的签到记录会一并删除，且无法恢复。</strong>';
$string['series_clear_confirm'] = '确定删除全部 {$a} 个场次及其签到记录吗？此操作不可撤销。';
$string['series_cleared'] = '已删除 {$a} 个场次。';
$string['series_deleteone'] = '删除该场次';
$string['series_deleteone_confirm'] = '确定删除该场次及其签到记录吗？';
$string['series_deleted_one'] = '该场次已删除。';
$string['series_none_yet'] = '本活动还没有生成批量场次。';
$string['series_is_template'] = '该记录是活动的配置模板，不能作为场次删除。';
$string['series_bad_session'] = '该场次不属于本活动，或已被删除。';

// ---- 场次编辑（v1.10.0）----
// 教师可单独给某一场改标题并写明这节要做什么；开始/结束时间同样可改，
// 因此下文有「重算」相关措辞——签到点全部以开始时间为基准。
$string['session_title'] = '标题';
$string['session_title_help'] = '本场次的名称，例如「第 3 周：地图投影」。默认沿用活动名，而活动名对每个场次都一样；给单独一场起名，整期场次才读得出来。';
$string['session_title_required'] = '标题不能为空。';
$string['session_description'] = '场次说明';
$string['session_description_help'] = '本节要做什么，用自己的话写即可。学生会在活动页看到，教师在场次出现的地方也能看到。点预览旁的按钮后在本页弹出的对话框里编辑，可以排版、可以插图；早先以纯文本写入的说明仍按纯文本显示。与「活动简介」不是一回事——简介属于整个活动。';
$string['session_description_add'] = '填写说明';
$string['session_description_edit'] = '编辑说明';
$string['session_description_none'] = '尚未填写说明。';
$string['session_description_toolong'] = '说明过长，最多 2000 字。';
$string['session_start'] = '开始时间';
$string['session_end'] = '结束时间';
$string['session_time_invalid'] = '日期时间无法识别，日期请用 YYYY-MM-DD，时间请用 HH:MM。';
$string['session_time_order'] = '结束时间必须晚于开始时间。';
$string['session_time_range'] = '年份需在 2000 至 2100 之间。';

// 单场次编辑页。
$string['session_edit'] = '编辑本场次';
$string['session_edit_link'] = '编辑本场次';
$string['session_edit_intro'] = '修改本场次的标题或开始/结束时间。说明在弹出的对话框里编辑，点按钮打开。签到点全部相对开始时间安放，改动开始时间会同步移动各签到点的时间窗口。';
$string['session_edit_template'] = '本活动只有一个场次，因此正在编辑的就是活动本身：它的标题即课程页上显示的活动名。';
$string['session_saved'] = '已保存「{$a}」。';
$string['session_unchanged'] = '没有需要保存的改动。';

// 改动已有签到场次的时间。
$string['session_note'] = '编辑本场次说明';
$string['session_note_intro'] = '说明在弹出的对话框里编辑，这样列出整学期场次的页面就不必为每一行都加载一个编辑器。可以排版、可以插图；学生会在活动页看到这段说明。';
$string['session_note_saved'] = '说明已保存。';
$string['session_note_continue'] = '继续编辑';
$string['session_note_close'] = '关闭并返回';
$string['session_recalc'] = '重算签到判定';
$string['session_recalc_help'] = '每个签到点都以「开始时间 + 偏移量」存放，改动开始时间即移动全部时间窗口。已收集的考勤会按新窗口重新判定——原本准时的签到可能变成迟到。签到记录本身不会被删除；把时间改回去即可恢复原判定。';
$string['session_recalc_label'] = '我已知悉：本场次已有 {$a} 条签到记录，保存后将按新时间重新判定。';
$string['session_recalc_notice'] = '本场次已有 {$a} 条签到记录。修改时间会按新时间重新判定，签到记录本身保留。';
$string['session_recalc_required'] = '请先勾选确认：修改时间会按新时间重新判定本场次已收集的签到记录。';
$string['session_recalcd'] = '已重算 {$a} 条判定。';

// 系列页的整表编辑。
$string['series_savebatch'] = '保存全部修改';
$string['series_savebatch_desc'] = '可直接在上表中修改标题、说明与开始/结束时间，一次保存整页。日期格式 YYYY-MM-DD，时间格式 HH:MM。';
$string['series_saved'] = '已保存 {$a->sessions} 个场次，重算 {$a->judged} 条判定。';
$string['series_save_errors'] = '本次未保存：有行无法读取。需要处理的行已在下方标出，你填写的内容均已保留。';
$string['series_recalc_needed'] = '本次未保存：{$a} 个场次已有签到且时间被改动。请在下方勾选确认后再次保存。';
$string['series_recalc_ack'] = '我已知悉：已有签到的场次将按新时间重新判定。';

// 场次结构表。
$string['seqno'] = '序号';
$string['occurrencetime'] = '时间';
$string['occurrencestatus'] = '状态';
$string['occurrencepoints'] = '签到点数';
$string['occurrencecheckins'] = '签到人次';

// 成绩汇总。
$string['gradesection'] = '成绩汇总方式';
$string['gradesection_help'] = '一次活动有多个考勤场次时，课程成绩册中本活动的分数如何由各场次得出。';
$string['gradeaggregate'] = '成绩汇总方式';
$string['gradeaggregate_help'] = '「全部场次平均」把每场考勤看得同等重要，但不计学生无记录的场次；「全部场次合计」把缺勤场次按 0 分计入，每场都是总分的固定一份；「成绩最好的若干次平均」允许学生缺席若干次而不至于毁掉总评，是常见的课堂考勤口径。';
$string['gradeaggregate_all'] = '全部场次平均';
$string['gradeaggregate_best'] = '成绩最好的若干次平均';
$string['gradeaggregate_sum'] = '全部场次合计';
$string['gradebestcount'] = '取最好几次';
$string['gradebestcount_help'] = '仅当汇总方式选择「成绩最好的若干次平均」时生效。超过实际场次数时按实际场次数计算。';
$string['gradebestcount_invalid'] = '取最好的次数须在 1–{$a} 之间。';

// 全场次报表。
$string['reportall'] = '全场次考勤汇总';
$string['report_expectedcount'] = '应签到 {$a} 人';
$string['reportall_link'] = '全部场次汇总报表';
$string['reportone_link'] = '单场考勤报表';
$string['occurrence_short'] = '第 {$a} 场';
$string['attendancecount'] = '出勤次数';
$string['aggregate_note_all'] = '「成绩」列为该生所有已产生结果场次的平均分。';
$string['aggregate_note_best'] = '「成绩」列为该生成绩最好的 {$a} 场次的平均分。';

// ---- 站点管理 ----
$string['maxsessions'] = '单次生成的场次上限';
$string['maxsessions_desc'] = '一次保存或重建，最多能为一个活动生成多少场考勤。此上限用于约束单次表单提交可能触发的工作量：若按天重复却把结束年份填错，会生成数千个场次及其签到点。默认 60 场，适用于一学期每周两次课的安排。超过 {$a} 的取值按 {$a} 处理。';

// ---- 教师手动改签（v1.7.7） ----
// 学生走 checkin.php 自己签；这里是有权限的教师补录、改正与撤销的通道。
$string['marks_edit'] = '编辑';
$string['marks_edit_link'] = '编辑签到记录';
$string['marks_edit_heading'] = '编辑 {$a} 的签到记录';
$string['marks_intro'] = '勾选表示该签到点已签到；取消勾选会删除该点的签到记录。时间按 24 小时制填写，须落在该签到点自己的开放窗口内。此处保存的记录会标记为「教师代签」。';
$string['mark_checked'] = '已签到';
$string['mark_time'] = '签到时间（时:分）';
$string['mark_window'] = '该签到点开放窗口：{$a->open} – {$a->close}';
$string['mark_time_invalid'] = '时间要写成 24 小时制的「时:分」，例如 08:05。';
$string['mark_time_outside'] = '时间须在该签到点的开放窗口（{$a->open} – {$a->close}）之内。';
$string['mark_manual'] = '教师代签';
$string['marks_clear'] = '删除该生本场次的全部签到';
$string['marks_cleared'] = '已删除 {$a} 在本场次的全部签到记录。';
$string['marks_saved'] = '已保存：新增 {$a->added} 条，修改 {$a->updated} 条，删除 {$a->removed} 条。';
$string['marks_some_rejected'] = '其中 {$a} 个签到点因时间不合法未保存。';
$string['marks_all_heading'] = '整班点名';
$string['marks_all_intro'] = '一人一行、一个签到点一格：打勾记为已签到，取消勾选删除该点记录，时间可直接在格内改。一次保存本页。';
$string['marks_saveall'] = '保存本页';
$string['marks_filled'] = '已为缺失的签到补录 {$a} 条记录，时间取各签到点窗口的开始时刻。';
$string['marks_fillmissing'] = '把本页未签到的学生补记为已签到';
$string['marks_fillmissing_confirm'] = '将为本页 {$a} 位学生中尚未签到的签到点补录记录（时间取窗口开始时刻），已有记录不会被改动。继续？';
$string['marks_fillmissing_note'] = '补录只为填补空白：学生自己签过的记录与时间不会被覆盖。';
$string['marks_nostudents'] = '本活动还没有可以签到的学生。';
$string['marks_pages'] = '共 {$a->total} 位学生，分 {$a->pages} 页，每页 {$a->perpage} 位。';
$string['marks_truncated'] = '注意：本次提交只收到 {$a->got} / {$a->want} 个格子，说明有部分内容没有送达，请重试或减小每页人数。';
$string['markall_link'] = '整班点名';
$string['nopoints'] = '本场次还没有签到点，请先到场次管理页添加。';

// ---- 围栏审计（v1.7.7） ----
// 只读页：把 geo_manager 已经写入的判定记录读出来，回答"围栏为什么没起作用"。
$string['audit_heading'] = '围栏审计';
$string['audit_link'] = '围栏审计';
$string['audit_config'] = '当前围栏配置';
$string['audit_enforceqr'] = '扫码是否受围栏限制';
$string['audit_centre'] = '教室中心点';
$string['audit_centre_unset'] = '未设置';
$string['audit_ranges'] = '允许的 IP 网段';
$string['audit_ranges_unset'] = '未配置';
$string['audit_notice_none'] = '本活动的围栏模式是「不启用围栏」，所有签到都不做位置校验。';
$string['audit_notice_centre'] = '围栏模式包含 GPS，但教室中心点仍是 0,0：没有可比较的中心，系统会直接放行。';
$string['audit_notice_ranges'] = '围栏模式包含 IP 校验，但允许的网段是空的：系统会直接放行。';
$string['audit_notice_qr'] = '本活动已关闭「扫码签到同样受围栏限制」，扫码签到不参与围栏判定（表中会标注「因扫码豁免而免检」）。';
$string['audit_stats'] = '统计';
$string['audit_total'] = '尝试次数';
$string['audit_allowed'] = '通过';
$string['audit_rejected'] = '拒绝';
$string['audit_filter'] = '筛选：';
$string['audit_filter_all'] = '全部';
$string['audit_filter_allowed'] = '仅通过';
$string['audit_filter_rejected'] = '仅拒绝';
$string['audit_col_time'] = '时间';
$string['audit_col_method'] = '签到方式';
$string['audit_col_verdict'] = '判定';
$string['audit_col_reason'] = '原因';
$string['audit_col_lat'] = '上报纬度';
$string['audit_col_lng'] = '上报经度';
$string['audit_col_acc'] = '精度（米）';
$string['audit_col_note'] = '备注';
$string['audit_method_self'] = '自助签到';
$string['audit_method_qr'] = '扫码';
$string['audit_method_password'] = '口令';
$string['audit_exempt_qr'] = '因扫码豁免而免检';
$string['audit_exempt_mode'] = '当时围栏为「不启用」';
$string['audit_centre_missing'] = '未配中心点，静默放行';
$string['audit_ranges_missing'] = '未配网段，静默放行';
$string['audit_reason_unknown'] = '未记录原因';
$string['audit_empty'] = '本场次没有符合条件的围栏校验记录。可能是还没有人自助签到，或者本场次的签到都绕过了围栏。';
$string['audit_distance_summary'] = '距离统计（最近 {$a->count} 次 GPS 判定）：最小 {$a->min} 米 / 中位 {$a->median} 米 / 平均 {$a->mean} 米 / 最大 {$a->max} 米。若中位数明显偏大且每次都朝同一方向，多半是教室坐标与手机上报的坐标系不一致。';
$string['audit_pages'] = '共 {$a->total} 条，分 {$a->pages} 页。';
$string['audit_note'] = '本页只读，不改变任何判定。每次自助签到（自助按钮 / 扫码 / 口令）都会留下一条记录；教师代签不经过围栏，因此不在此列。';
$string['photo_capture'] = '现场拍照签到';
$string['photo_host_wechat'] = '微信';
$string['photo_host_wework'] = '企业微信';
$string['photo_host_qq'] = 'QQ';
$string['photo_host_dingtalk'] = '钉钉';
$string['photo_openinbrowser'] = '请勿在{$a}内拍照签到，照片会被裁切。复制下面的链接到手机浏览器打开后再拍。';
$string['photo_openinbrowser_menu'] = '请勿在{$a}内拍照签到，照片会被裁切。请点右上角菜单，选「在浏览器打开」后再拍。';
$string['photo_copylink'] = '复制链接';
$string['photo_copied'] = '已复制';
$string['photo_copyfailed'] = '复制失败，请长按上面的链接选中后复制。';
$string['photo_intro'] = '请用浏览器即时拍摄一张现场照，为「{$a}」完成签到。照片必须当场拍摄，本页没有上传入口。';
$string['photo_watermark'] = '照片上会叠印：';
$string['photo_noretake'] = '按下快门后照片立即提交，本场次不允许重拍，提交后不可撤销。';
$string['photo_shoot'] = '拍照并提交';
$string['photo_shoot_confirm'] = '拍下后会立即提交，本场次不能重拍。确定继续吗？';
$string['photo_starting'] = '正在启动摄像头…';
$string['photo_switchcam'] = '切换摄像头';
$string['photo_switchfailed'] = '切换摄像头失败，已恢复原来的画面。请用原来的摄像头拍照，或请老师人工核验。';
$string['photo_switching'] = '正在切换摄像头…';
$string['photo_focused'] = '已对焦到你点的位置。';
$string['photo_ready'] = '摄像头已就绪，点按钮拍照。';
$string['photo_denied'] = '无法启动摄像头：可能未授权、设备没有摄像头，或站点不是 HTTPS。请改用下方按钮请老师人工核验。';
$string['photo_camera_failed'] = '未能拍照。';
$string['photo_noscript'] = '拍照需要浏览器允许运行脚本。本页不提供上传替代，请请老师人工核验。';
$string['photo_request_byhand'] = '拍不了，请老师人工核验';
$string['photo_request_short'] = '拍不了？';
$string['photo_required_notice'] = '本场次必须现场拍照签到。请用手机浏览器打开本页签到，不要用微信等软件扫码或签到：内置浏览器拍不全。';
$string['photo_pass_expired'] = '拍照许可已过期或不属于本签到点，请重新发起签到。';
$string['photo_checkin_recorded'] = '签到已完成，现场照已保存。';
$string['photo_view'] = '查看现场照';
$string['photo_files'] = '签到现场照';
$string['photo_store_failed'] = '照片没能保存，签到未记录，请重试。';
$string['photo_app_fallback'] = 'Moodle App 内无法调用摄像头，请请老师人工核验。';
$string['photoerr_notjpeg'] = '照片不是 JPEG 格式，请在本页重新拍摄。';
$string['photoerr_toobig'] = '照片体积过大，请重新拍摄。';
$string['photoerr_notimage'] = '内容不是图片，请重新拍摄。';
$string['photoerr_toowide'] = '照片尺寸超过允许范围，请重新拍摄。';
$string['checkin_now_photo'] = '拍照签到';
$string['session_photorequired'] = '要求拍照签到';
$string['session_photorequired_help'] = '打开后，本场次每次签到都必须用浏览器当场拍摄一张照片作证。本功能不提供上传通道，因此摄像头用不了的学生只能申请人工核验，申请会出现在人工核验列表里。教师手工点名不要求拍照。该设置只属于本场次，保存活动表单不会改动它。';
$string['photoreq_title'] = '人工核验';
$string['photoreq_intro'] = '「{$a}」这次签到用不了摄像头。请说明情况，老师会决定是否人工补记；老师处理前，签到不会记录。';
$string['photoreq_current'] = '你的申请：{$a->status}，提交于 {$a->time}。';
$string['photoreq_reason'] = '情况说明（可留空）';
$string['photoreq_reason_help'] = '例如：手机没有摄像头 / 未授权 / 浏览器不支持。';
$string['photoreq_submit'] = '提交核验申请';
$string['photoreq_submitted'] = '申请已提交，老师会在人工核验列表里看到。';
$string['photoreq_notrequired'] = '本场次没有要求拍照签到，无需提交核验申请。';
$string['photoreq_manage'] = '人工核验';
$string['photoreq_manage_intro'] = '学生摄像头用不了时提交的申请。点「通过并补签」会按教师代签记一条签到。';
$string['photoreq_none'] = '没有需要处理的申请。';
$string['photoreq_showall'] = '显示全部';
$string['photoreq_showpending'] = '只看待处理';
$string['photoreq_waiting'] = '待处理';
$string['photoreq_session'] = '场次';
$string['photoreq_student'] = '学生';
$string['photoreq_submittedat'] = '提交时间';
$string['photoreq_reviewedby'] = '处理人';
$string['photoreq_approve'] = '通过并补签';
$string['photoreq_refuse'] = '拒绝';
$string['photoreq_approved_ok'] = '已通过，并已人工补记签到。';
$string['photoreq_refused_ok'] = '已拒绝。';
$string['photoreq_notfound'] = '该申请不属于本活动。';
$string['toolbar_students'] = '学生常用';
$string['toolbar_teachers'] = '教师管理';
$string['privacy:metadata:multicheckin_photoreq'] = '学生摄像头用不了时提交的核验申请，以及做出处理的教师。';
$string['privacy:metadata:multicheckin_photoreq:userid'] = '提交申请的学生。';
$string['privacy:metadata:multicheckin_photoreq:reason'] = '学生填写的情况说明。';
$string['privacy:metadata:multicheckin_photoreq:status'] = '申请的状态：待核验 / 已通过 / 已驳回。';
$string['privacy:metadata:multicheckin_photoreq:reviewedby'] = '处理该申请的教师。';
$string['privacy:metadata:multicheckin_photoreq:timecreated'] = '申请提交时间。';
// 拍照许可。2.0.23 起它是一行记录而不再是会话里的一项，这正是复制出去的链接
// 能在另一个浏览器里生效的原因，也是这一行必须写明属于谁的原因。
$string['privacy:metadata:multicheckin_pass'] = '为某个签到点开具的短期拍照许可，使同一条链接在该学生已登录的任意浏览器中都可使用。';
$string['privacy:metadata:multicheckin_pass:userid'] = '许可所属的学生。他人无法使用。';
$string['privacy:metadata:multicheckin_pass:clientgeo'] = '开具许可时通过位置校验的坐标，在记录签到时重新核验。';
$string['privacy:metadata:multicheckin_pass:timecreated'] = '许可开具时间。十分钟后失效。';
