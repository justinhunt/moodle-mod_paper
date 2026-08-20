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
 * Developer Tools page for mod_paper
 *
 * Not intended for regular use - inspects the current state of processing for a paper
 * (initially: the cropped images generated for each response area of a submission).
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once('lib.php');

$id = required_param('id', PARAM_INT); // Course module ID.
$evalid = optional_param('evalid', 0, PARAM_INT);

$cm = get_coursemodule_from_id('paper', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$paper = $DB->get_record('paper', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/paper:manage', $context);

$PAGE->set_url('/mod/paper/developer.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('developer', 'mod_paper'));
$PAGE->set_heading(format_string($course->fullname));

$evaluations = $DB->get_records('paper_evaluations', ['paperid' => $paper->id], 'id ASC');

$templatecontext = [
    'id' => $cm->id,
    'returntoreportsurl' => (new moodle_url('/mod/paper/reports.php', ['id' => $cm->id]))->out(false),
    'selecturl' => (new moodle_url('/mod/paper/developer.php', ['id' => $cm->id]))->out(false),
    'noevaluations' => empty($evaluations),
    'evaluationoptions' => [],
    'showgallery' => false,
    'evalid' => $evalid,
    'areas' => [],
];

foreach ($evaluations as $eval) {
    $templatecontext['evaluationoptions'][] = [
        'id' => $eval->id,
        'label' => !empty($eval->studentnametext)
            ? format_string($eval->studentnametext) . ' (#' . $eval->id . ')'
            : get_string('evaluationid', 'mod_paper') . ' ' . $eval->id,
        'selected' => ($eval->id == $evalid),
    ];
}

if ($evalid > 0) {
    $eval = $DB->get_record('paper_evaluations', ['id' => $evalid, 'paperid' => $paper->id], '*', MUST_EXIST);

    $areas = $DB->get_records('paper_response_areas', ['paperid' => $paper->id], 'responsenumber ASC');
    $items = $DB->get_records('paper_eval_items', ['evalid' => $evalid]);
    $itemsbyarea = [];
    foreach ($items as $item) {
        $itemsbyarea[$item->responseareaid] = $item;
    }

    $fs = get_file_storage();
    $areatypestrings = [
        0 => get_string('areatype_response', 'mod_paper'),
        1 => get_string('areatype_name', 'mod_paper'),
        2 => get_string('areatype_username', 'mod_paper'),
        3 => get_string('areatype_displayonly', 'mod_paper'),
    ];

    foreach ($areas as $area) {
        $item = $itemsbyarea[$area->id] ?? null;
        $imageurl = null;

        if ($item) {
            $filearea = ($area->isnamefield == 3) ? 'responsesnippet' : 'areacrop';
            $filename = ($area->isnamefield == 3) ? 'snippet.jpg' : 'crop.jpg';
            $file = $fs->get_file($context->id, 'mod_paper', $filearea, $item->id, '/', $filename);
            if ($file) {
                $imageurl = moodle_url::make_pluginfile_url(
                    $context->id, 'mod_paper', $filearea, $item->id, '/', $filename
                )->out(false);
            }
        }

        $templatecontext['areas'][] = [
            'responsenumber' => $area->responsenumber,
            'areatype' => $areatypestrings[$area->isnamefield] ?? $area->isnamefield,
            'imageurl' => $imageurl,
        ];
    }

    $templatecontext['showgallery'] = true;
    $templatecontext['galleryheading'] = get_string('submissioncropsfor', 'mod_paper', $evalid);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('developertools', 'mod_paper'));
echo $OUTPUT->render_from_template('mod_paper/developer_page', $templatecontext);
echo $OUTPUT->footer();
