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
 * Report to track which teacher was teaching at which session (optiondate).
 *
 * @package     mod_booking
 * @copyright   2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\singleton_service;
use mod_booking\table\optiondates_teachers_table;

require_once(__DIR__ . '/../../config.php');

$cmid = required_param('id', PARAM_INT); // Course module id.
$optionid = required_param('optionid', PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);

list($course, $cm) = get_course_and_cm_from_cmid($cmid);
require_course_login($course, false, $cm);

$urlparams = [
    'id' => $cmid,
    'optionid' => $optionid
];

$context = context_module::instance($cmid);
$PAGE->set_context($context);

$baseurl = new moodle_url('/mod/booking/optiondates_teachers_report.php', $urlparams);
$PAGE->set_url($baseurl);

if ((has_capability('mod/booking:updatebooking', $context) || has_capability('mod/booking:addeditownoption', $context)) == false) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('accessdenied', 'mod_booking'), 4);
    echo get_string('nopermissiontoaccesspage', 'mod_booking');
    echo $OUTPUT->footer();
    die();
}

$settings = singleton_service::get_instance_of_booking_option_settings($optionid);

$bookingoptionname = $settings->text;

// File name and sheet name.
$fileandsheetname = $bookingoptionname . "_teachers";

$optiondatesteacherstable = new optiondates_teachers_table('optiondates_teachers_table');

$optiondatesteacherstable->is_downloading($download, $fileandsheetname, $fileandsheetname);

$tablebaseurl = $baseurl;
$tablebaseurl->remove_params('page');
$optiondatesteacherstable->define_baseurl($tablebaseurl);
$optiondatesteacherstable->defaultdownloadformat = 'pdf';
$optiondatesteacherstable->sortable(false);

$optiondatesteacherstable->show_download_buttons_at(array(TABLE_P_BOTTOM));

if (!$optiondatesteacherstable->is_downloading()) {

    // Table will be shown normally.
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('optiondatesteachersreport', 'mod_booking'));

    $instancereportsurl = new moodle_url('/mod/booking/teachers_instance_report.php', ['cmid' => $cmid]);

    // Dismissible alert containing the description of the report.
    echo '<div class="alert alert-secondary alert-dismissible fade show" role="alert">' .
        get_string('optiondatesteachersreport_desc', 'mod_booking') .
        '<button type="button" class="close" data-dismiss="alert" aria-label="Close">
        <span aria-hidden="true">&times;</span>
        </button>
    </div>';

    echo get_string('linktoteachersinstancereport', 'mod_booking', $instancereportsurl->out());

    // Show header with booking option name (and prefix if present).
    if (!empty($settings->titleprefix)) {
        $bookingoptionname = $settings->titleprefix . " - " . $bookingoptionname;
    }
    echo "<h2 class='mt-5'>$bookingoptionname</h2>";

    // Header.
    $optiondatesteacherstable->define_headers([
        get_string('optiondate', 'mod_booking'),
        get_string('teacher', 'mod_booking'),
        get_string('reason', 'mod_booking'),
        get_string('edit')
    ]);

    // Columns.
    $optiondatesteacherstable->define_columns([
        'optiondate',
        'teacher',
        'reason',
        'edit'
    ]);

    // Header column.
    $optiondatesteacherstable->define_header_column('optiondate');

    // SQL query. The subselect will fix the "Did you remember to make the first column something...
    // ...unique in your call to get_records?" bug.
    $fields = "s.optiondateid, s.optionid, s.coursestarttime, s.courseendtime, s.reason, s.teachers";
    $from = "(
        SELECT bod.id optiondateid, bod.optionid, bod.coursestarttime, bod.courseendtime, bod.reason, " .
        $DB->sql_group_concat('u.id', ',', 'u.id') . " teachers
        FROM {booking_optiondates} bod
        LEFT JOIN {booking_optiondates_teachers} bodt
        ON bodt.optiondateid = bod.id
        LEFT JOIN {booking_options} bo
        ON bo.id = bod.optionid
        LEFT JOIN {user} u
        ON u.id = bodt.userid
        WHERE bod.optionid = :optionid
        GROUP BY bod.id, bod.optionid, bod.coursestarttime, bod.courseendtime
        ORDER BY bod.coursestarttime ASC
        ) s";
    $where = "1=1";
    $params = ['optionid' => $optionid];

    // We only have 3 columns, so no need to collapse anything.
    $optiondatesteacherstable->collapsible(false);

    // Now build the table.
    $optiondatesteacherstable->set_sql($fields, $from, $where, $params);
    $optiondatesteacherstable->out(TABLE_SHOW_ALL_PAGE_SIZE, false);

    // Require JS.
    $PAGE->requires->js_call_amd(
        'mod_booking/editteachersforoptiondate_form',
        'initbuttons'
    );

    echo $OUTPUT->footer();

} else {
    // The table is being downloaded.

    // Header.
    $optiondatesteacherstable->define_headers([
        get_string('name'),
        get_string('optiondate', 'mod_booking'),
        get_string('reason', 'mod_booking'),
        get_string('teacher', 'mod_booking')
    ]);
    // Columns.
    $optiondatesteacherstable->define_columns([
        'optionname',
        'optiondate',
        'reason',
        'teacher'
    ]);

    // SQL query. The subselect will fix the "Did you remember to make the first column something...
    // ...unique in your call to get_records?" bug.
    $fields = "s.optiondateid, s.text, s.optionid, s.coursestarttime, s.courseendtime, s.reason, s.teachers";
    $from = "(
        SELECT bod.id optiondateid, bo.text, bod.optionid, bod.coursestarttime, bod.courseendtime, bod.reason, " .
        $DB->sql_group_concat('u.id', ',', 'u.id') . " teachers
        FROM {booking_optiondates} bod
        LEFT JOIN {booking_optiondates_teachers} bodt
        ON bodt.optiondateid = bod.id
        LEFT JOIN {booking_options} bo
        ON bo.id = bod.optionid
        LEFT JOIN {user} u
        ON u.id = bodt.userid
        WHERE bod.optionid = :optionid
        GROUP BY bod.id, bod.optionid, bod.coursestarttime, bod.courseendtime
        ORDER BY bod.coursestarttime ASC
        ) s";
    $where = "1=1";
    $params = ['optionid' => $optionid];

    // Now build the table.
    $optiondatesteacherstable->set_sql($fields, $from, $where, $params);
    $optiondatesteacherstable->setup();
    $optiondatesteacherstable->query_db(TABLE_SHOW_ALL_PAGE_SIZE);
    $optiondatesteacherstable->build_table();
    $optiondatesteacherstable->finish_output();
}
