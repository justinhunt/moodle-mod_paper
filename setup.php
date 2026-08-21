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
 * Setup Script for mod_paper
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once('lib.php');

use mod_paper\constants;
use mod_paper\utils;

$id = required_param('id', PARAM_INT); // Course module ID

$cm = get_coursemodule_from_id('paper', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$paper = $DB->get_record('paper', array('id' => $cm->instance), '*', MUST_EXIST);

require_login($course, true, $cm);
require_capability('mod/paper:manage', $context = context_module::instance($cm->id));

$PAGE->set_url('/mod/paper/setup.php', array('id' => $cm->id));
$PAGE->set_title(format_string($paper->name));
$PAGE->set_heading(format_string($course->fullname));

// Handle form submission to save response areas
if ($_POST && isset($_POST['sesskey']) && confirm_sesskey() && empty($_FILES['templateimage'])) {
    $areas = $DB->get_records('paper_response_areas', ['paperid' => $paper->id]);
    $submittedareatypes = optional_param_array('areatype', [], PARAM_INT);
    
    $questions = optional_param_array('question', [], PARAM_RAW);
    $correctanswers = optional_param_array('correctanswer', [], PARAM_RAW);
    $cam = optional_param_array('correctanswermode', [], PARAM_ALPHANUMEXT);
    $gc = optional_param_array('grammarcorrections', [], PARAM_ALPHANUMEXT);
    $fm = optional_param_array('feedbackmode', [], PARAM_ALPHANUMEXT);
    $fi = optional_param_array('feedbackinstructions', [], PARAM_RAW);
    $gm = optional_param_array('gradingmode', [], PARAM_ALPHANUMEXT);
    $mgrade = optional_param_array('maxgrade', [], PARAM_FLOAT);
    $gi = optional_param_array('gradeinstructions', [], PARAM_RAW);
    
    $bx = optional_param_array('box_x', [], PARAM_FLOAT);
    $by = optional_param_array('box_y', [], PARAM_FLOAT);
    $bw = optional_param_array('box_w', [], PARAM_FLOAT);
    $bh = optional_param_array('box_h', [], PARAM_FLOAT);

    $fbx = optional_param_array('fb_x', [], PARAM_FLOAT);
    $fby = optional_param_array('fb_y', [], PARAM_FLOAT);
    $fbw = optional_param_array('fb_w', [], PARAM_FLOAT);
    $fbh = optional_param_array('fb_h', [], PARAM_FLOAT);
    
    $submitted_ids = array_keys($questions);
    
    // 1. Delete removed areas
    foreach ($areas as $area) {
        if (!in_array($area->id, $submitted_ids)) {
            $DB->delete_records('paper_response_areas', ['id' => $area->id]);
        }
    }
    
    // 2. Update existing and Insert new
    $responsenumber = 1;
    foreach ($questions as $post_id => $question) {
        $record = new stdClass();
        $record->paperid = $paper->id;
        $record->responsenumber = $responsenumber++;
        
        // Anything not offered by the radio group falls back to a standard graded area.
        $type = (int)($submittedareatypes[$post_id] ?? constants::M_AREATYPE_GRADED);
        $record->areatype = in_array($type, constants::M_AREATYPES, true)
            ? $type
            : constants::M_AREATYPE_GRADED;
        $record->question = $question;
        $record->correctanswer = $correctanswers[$post_id] ?? '';
        $record->correctanswermode = $cam[$post_id] ?? 'none';
        $record->grammarcorrections = $gc[$post_id] ?? 'no';
        $record->feedbackmode = $fm[$post_id] ?? 'none';
        $record->feedbackinstructions = $fi[$post_id] ?? '';
        $record->gradingmode = $gm[$post_id] ?? 'none';
        $record->maxgrade = $mgrade[$post_id] ?? 0;
        $record->gradeinstructions = $gi[$post_id] ?? '';
        $record->box_x = $bx[$post_id] ?? 0;
        $record->box_y = $by[$post_id] ?? 0;
        $record->box_w = $bw[$post_id] ?? 0;
        $record->box_h = $bh[$post_id] ?? 0;

        $record->fb_x = $fbx[$post_id] ?? 0;
        $record->fb_y = $fby[$post_id] ?? 0;
        $record->fb_w = $fbw[$post_id] ?? 0;
        $record->fb_h = $fbh[$post_id] ?? 0;
        
        // If the ID contains 'new', it's a dynamically added box
        if (strpos((string)$post_id, 'new') !== false) {
            $DB->insert_record('paper_response_areas', $record);
        } else {
            $record->id = (int)$post_id;
            $DB->update_record('paper_response_areas', $record);
        }
    }
    
    \core\notification::success(get_string('area_configurations_saved', 'mod_paper'));
    redirect(new moodle_url('/mod/paper/setup.php', ['id' => $cm->id]));
}

// Handle 'reset' param to upload a new template
if (optional_param('reset', 0, PARAM_INT)) {
    $DB->delete_records('paper_response_areas', ['paperid' => $paper->id]);
    $fs = get_file_storage();
    $fs->delete_area_files($context->id, 'mod_paper', 'template', 0);
    redirect(new moodle_url('/mod/paper/setup.php', ['id' => $cm->id]));
}

// Check if an image is uploaded for setup
if (isset($_FILES['templateimage']) && $_FILES['templateimage']['error'] === UPLOAD_ERR_OK) {
    $tmpname = $_FILES['templateimage']['tmp_name'];
    $filename = $_FILES['templateimage']['name'];
    
    // Check if it's a PDF
    $mime = mime_content_type($tmpname);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    if ($mime === 'application/pdf' || $ext === 'pdf') {
        $jpgpath = $tmpname . '.jpg';
        $cmd = sprintf(
            "gs -q -dSAFER -dBATCH -dNOPAUSE -sDEVICE=jpeg -r150 -dFirstPage=1 -dLastPage=1 -dUseCropBox -dPDFFitPage -sPAPERSIZE=a4 -sOutputFile=%s %s",
            escapeshellarg($jpgpath),
            escapeshellarg($tmpname)
        );
        exec($cmd, $output, $returnvar);
        
        if ($returnvar === 0 && file_exists($jpgpath)) {
            $imagecontent = file_get_contents($jpgpath);
            unlink($jpgpath);
        } else {
            \core\notification::error("Failed to convert PDF to image for analysis.");
            redirect(new moodle_url('/mod/paper/setup.php', ['id' => $cm->id]));
            exit;
        }
    } else {
        // Process the image directly
        $imagecontent = file_get_contents($tmpname);
    }
    
    $base64image = base64_encode($imagecontent);
    
    // Save image to Moodle File API
    $fs = get_file_storage();
    $fs->delete_area_files($context->id, 'mod_paper', 'template', 0);
    $filerecord = [
        'contextid' => $context->id,
        'component' => 'mod_paper',
        'filearea'  => 'template',
        'itemid'    => 0,
        'filepath'  => '/',
        'filename'  => 'template.jpg'
    ];
    $fs->create_file_from_string($filerecord, $imagecontent);
    
    // Call AI to identify areas
    $aimanager = new \mod_paper\ai_manager();
    try {
        $areas = $aimanager->identify_response_areas($base64image);
        // Save areas to DB... (Simplified for now)
        $DB->delete_records('paper_response_areas', ['paperid' => $paper->id]);
        foreach ($areas as $index => $area) {
            $record = new stdClass();
            $record->paperid = $paper->id;
            $record->responsenumber = $index + 1;
            // Default the first detected area to the student name field.
            $record->areatype = ($index === 0) ? constants::M_AREATYPE_NAME : constants::M_AREATYPE_GRADED;
            
            if (isset($area->xmin)) {
                $record->box_x = $area->xmin / 10;
                $record->box_y = $area->ymin / 10;
                $record->box_w = ($area->xmax - $area->xmin) / 10;
                $record->box_h = ($area->ymax - $area->ymin) / 10;
            } else {
                $record->box_x = $area->x ?? 0;
                $record->box_y = $area->y ?? 0;
                $record->box_w = $area->w ?? 0;
                $record->box_h = $area->h ?? 0;
            }
            // Default feedback area to the bottom 30% of the response area
            $record->fb_x = $record->box_x;
            $record->fb_y = $record->box_y + ($record->box_h * 0.7);
            $record->fb_w = $record->box_w;
            $record->fb_h = $record->box_h * 0.3;
            $DB->insert_record('paper_response_areas', $record);
        }
        redirect(new moodle_url('/mod/paper/setup.php', ['id' => $cm->id]));
    } catch (\Exception $e) {
        \core\notification::error("Error analyzing template: " . $e->getMessage());
    }
}

// Build Context for template
$contextdata = [
    'courseid' => $course->id,
    'cmid' => $cm->id,
    'actionurl' => (new moodle_url('/mod/paper/setup.php', ['id' => $cm->id]))->out(false),
    'sesskey' => sesskey(),
    'enablemoodleusername' => in_array($paper->namefieldrole, ['1', 1, 'username'], true),
    'preset_options' => \mod_paper\utils::get_grading_preset_options_list(),
    'preset_contents_json' => \mod_paper\utils::get_grading_presets_json(),
    'feedback_preset_options' => \mod_paper\utils::get_feedback_preset_options_list(),
    'feedback_preset_contents_json' => \mod_paper\utils::get_feedback_presets_json(),
    'manage_presets_url' => (new moodle_url('/mod/paper/presets.php', ['id' => $cm->id]))->out(false),
    'responseareas' => []
];

// Load image URL if exists
$fs = get_file_storage();
$file = $fs->get_file($context->id, 'mod_paper', 'template', 0, '/', 'template.jpg');
if ($file) {
    $contextdata['imageurl'] = moodle_url::make_pluginfile_url($context->id, 'mod_paper', 'template', 0, '/', 'template.jpg')->out(false);
}

$areas = $DB->get_records('paper_response_areas', ['paperid' => $paper->id], 'responsenumber ASC');
if (!empty($areas)) {
    $contextdata['hastemplate'] = true;
    foreach ($areas as $area) {
        $contextdata['responseareas'][] = [
            'id' => $area->id,
            'responsenumber' => $area->responsenumber,
            'box_x' => (float)$area->box_x,
            'box_y' => (float)$area->box_y,
            'box_w' => (float)$area->box_w,
            'box_h' => (float)$area->box_h,
            'fb_x' => (float)$area->fb_x,
            'fb_y' => (float)$area->fb_y,
            'fb_w' => (float)$area->fb_w,
            'fb_h' => (float)$area->fb_h,
            'areatype' => $area->areatype,
            'areatype_graded' => utils::is_graded_area($area),
            'areatype_name' => ($area->areatype == constants::M_AREATYPE_NAME),
            'areatype_username' => ($area->areatype == constants::M_AREATYPE_USERNAME),
            'areatype_displayonly' => utils::is_displayonly_area($area),
            'areatype_ungraded' => ($area->areatype == constants::M_AREATYPE_UNGRADED),
            // The question/answer/grading fieldsets only apply to a standard graded area.
            'hidestandardfields' => !utils::is_graded_area($area),
            'question' => $area->question,
            'correctanswer' => $area->correctanswer,
            'correctanswermode' => $area->correctanswermode,
            'correctanswermode_none' => ($area->correctanswermode === 'none'),
            'correctanswermode_exactly' => ($area->correctanswermode === 'exactly'),
            'correctanswermode_relevant' => ($area->correctanswermode === 'relevant'),
            'correctanswermode_samemeaning' => ($area->correctanswermode === 'samemeaning'),
            'correctanswermode_manual' => ($area->correctanswermode === 'manual'),
            'show_correctanswer' => ($area->correctanswermode === 'manual' || $area->correctanswermode === 'samemeaning'),
            'grammarcorrections' => $area->grammarcorrections,
            'grammarcorrections_no' => ($area->grammarcorrections === 'no'),
            'grammarcorrections_major' => ($area->grammarcorrections === 'major'),
            'grammarcorrections_all' => ($area->grammarcorrections === 'all'),
            'feedbackmode' => $area->feedbackmode,
            'feedbackinstructions' => $area->feedbackinstructions,
            'gradingmode' => $area->gradingmode,
            'gradeinstructions' => $area->gradeinstructions,
            'feedbackmode_none' => ($area->feedbackmode == 'none'),
            'feedbackmode_grammatical' => ($area->feedbackmode == 'grammatical'),
            'feedbackmode_custom' => ($area->feedbackmode == 'custom'),
            'feedbackmode_overall' => ($area->feedbackmode == 'overall'),
            'gradingmode_none' => ($area->gradingmode == 'none'),
            'gradingmode_incorrect' => ($area->gradingmode == 'incorrect'),
            'gradingmode_overall' => ($area->gradingmode == 'overall'),
            'maxgrade' => ($area->maxgrade !== null) ? round($area->maxgrade, 2) + 0 : 0,
        ];
    }
    
    // Pass json array for Javascript box drawing (via hidden field in template)
    $contextdata['responseareas_json'] = json_encode($contextdata['responseareas']);
}

$PAGE->set_title(get_string('setuptemplate', 'mod_paper'));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('setuptemplatefor', 'mod_paper', format_string($paper->name)));

echo $OUTPUT->box(get_string('setup_help', 'mod_paper'), 'info mb-3');

echo $OUTPUT->render_from_template('mod_paper/setup', $contextdata);

// Initialize JS with minimal params to avoid "Too much data" warning.
$PAGE->requires->js_call_amd('mod_paper/setup', 'init', [
    ['cmid' => $cm->id]
]);

echo $OUTPUT->footer();
