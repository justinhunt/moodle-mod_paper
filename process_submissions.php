<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Process Submissions Script for mod_paper
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once('lib.php');

$id = required_param('id', PARAM_INT); // Course module ID

$cm = get_coursemodule_from_id('paper', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$paper = $DB->get_record('paper', array('id' => $cm->instance), '*', MUST_EXIST);

require_login($course, true, $cm);
require_capability('mod/paper:manage', context_module::instance($cm->id));

$PAGE->set_url('/mod/paper/process_submissions.php', array('id' => $cm->id));
$PAGE->set_title("Process Submissions");
$PAGE->set_heading(format_string($course->fullname));

$mform = new \mod_paper\form\process_submissions_form(null, ['id' => $cm->id]);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/mod/paper/reports.php', ['id' => $cm->id]));
} else if ($data = $mform->get_data()) {
    $batchid = time();
    $fs = get_file_storage();
    $context = context_module::instance($cm->id);
    
    // Save draft area files to the real file area
    file_save_draft_area_files(
        $data->submissions_filemanager,
        $context->id,
        'mod_paper',
        'submissions',
        $batchid,
        ['subdirs' => 0, 'maxfiles' => 50, 'accepted_types' => ['.pdf', '.jpg', '.jpeg', '.png']]
    );
    
    // Count how many files were actually saved
    $files = $fs->get_area_files($context->id, 'mod_paper', 'submissions', $batchid, 'id', false);
    $uploaded = count($files);
    
    if ($uploaded > 0) {
        $task = new \mod_paper\task\process_submissions_task();
        $task->set_custom_data([
            'paperid' => $paper->id,
            'batchid' => $batchid,
        ]);
        \core\task\manager::queue_adhoc_task($task);
        
        \core\notification::success("Successfully queued $uploaded file(s) for background processing. Please wait for the cron job to run.");
    } else {
        \core\notification::warning("No valid files were uploaded.");
    }
    
    redirect(new moodle_url('/mod/paper/reports.php', ['id' => $cm->id]));
}

// Prepare the draft area before display
$draftitemid = file_get_submitted_draft_itemid('submissions_filemanager');
$filemanageropts = [
    'subdirs' => 0,
    'maxbytes' => 0,
    'maxfiles' => 50,
    'accepted_types' => ['.pdf', '.jpg', '.jpeg', '.png']
];
$context = context_module::instance($cm->id);
file_prepare_draft_area($draftitemid, $context->id, 'mod_paper', 'submissions', null, $filemanageropts);

$mform->set_data(['submissions_filemanager' => $draftitemid]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('uploadscansfor', 'mod_paper', format_string($paper->name)));

echo $OUTPUT->box(get_string('process_submissions_help', 'mod_paper'), 'info mb-3');

$mform->display();

$viewurl = new moodle_url('/mod/paper/view.php', ['id' => $cm->id]);
echo html_writer::link($viewurl, 'Return to Top', ['class' => 'btn btn-secondary mt-3 mb-3']);

echo $OUTPUT->footer();
