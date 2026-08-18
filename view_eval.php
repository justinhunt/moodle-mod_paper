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
 * View Single Evaluation Script for mod_paper
 *
 * @package    mod_paper
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once('lib.php');

$id = required_param('id', PARAM_INT); // Course module ID
$evalid = required_param('evalid', PARAM_INT); // Evaluation ID

$cm = get_coursemodule_from_id('paper', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$paper = $DB->get_record('paper', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
require_capability('mod/paper:manage', context_module::instance($cm->id));
$context = context_module::instance($cm->id);

$PAGE->set_url('/mod/paper/view_eval.php', ['id' => $cm->id, 'evalid' => $evalid]);
$PAGE->set_title(get_string('viewevaluation', 'mod_paper'));
$PAGE->set_heading(format_string($course->fullname));

// Ensure requested eval belongs to this paper instance
$currenteval = $DB->get_record('paper_evaluations', ['id' => $evalid, 'paperid' => $paper->id], '*', MUST_EXIST);

// Calculate total possible grade
$maxpossible = $DB->get_field_sql("SELECT SUM(maxgrade) FROM {paper_response_areas} WHERE paperid = :paperid AND isnamefield = 0", ['paperid' => $paper->id]);
$maxpossible = round($maxpossible, 2) + 0;

echo $OUTPUT->header();

$studentname = !empty($currenteval->studentnametext) ? $currenteval->studentnametext : 'Unknown Student';
echo $OUTPUT->heading(get_string('evaluationreportsfor', 'mod_paper', format_string($studentname)));

// Pagination logic
$evalset = $DB->get_records('paper_evaluations', ['paperid' => $paper->id], 'id ASC', 'id');
$evalids = array_keys($evalset);
$currentindex = array_search($evalid, $evalids);

$templatecontext = [
    'pagination' => [
        'prevurl' => null,
        'nexturl' => null,
        'backurl' => (new moodle_url('/mod/paper/reports.php', ['id' => $cm->id]))->out(false),
    ],
    'hastemplate' => false,
    'imageurl' => null,
    'areas' => [],
    'showtotalscore' => (bool)($paper->showtotalscore ?? 1),
    'scoredisplay' => null,
    'scorestyle' => null,
];

if ($currentindex > 0) {
    $prevalid = $evalids[$currentindex - 1];
    $templatecontext['pagination']['prevurl'] = (new moodle_url('/mod/paper/view_eval.php', ['id' => $cm->id, 'evalid' => $prevalid]))->out(false);
}
if ($currentindex < count($evalids) - 1) {
    $nextevalid = $evalids[$currentindex + 1];
    $templatecontext['pagination']['nexturl'] = (new moodle_url('/mod/paper/view_eval.php', ['id' => $cm->id, 'evalid' => $nextevalid]))->out(false);
}

$fs = get_file_storage();
$file = $fs->get_file($context->id, 'mod_paper', 'template', 0, '/', 'template.jpg');

if ($file) {
    $templatecontext['hastemplate'] = true;
    $templatecontext['imageurl'] = moodle_url::make_pluginfile_url($context->id, 'mod_paper', 'template', 0, '/', 'template.jpg')->out(false);

    $areas = $DB->get_records('paper_response_areas', ['paperid' => $paper->id], 'id ASC');
    $items = $DB->get_records('paper_eval_items', ['evalid' => $evalid]);

    $itemsbyarea = [];
    foreach ($items as $item) {
        $itemsbyarea[$item->responseareaid] = $item;
    }

    $bottommosty = 0;
    foreach ($areas as $area) {
        $item = $itemsbyarea[$area->id] ?? null;
        $itemid = $item ? $item->id : 0;
        $ocr = $item ? $item->ocrtext : '';
        $corrected = $item ? $item->correctedtext : '';
        $feedback = $item ? $item->feedback : '';
        $grade = $item ? $item->itemgrade : null;

        $bottom = $area->box_y + $area->box_h;
        if ($bottom > $bottommosty) {
            $bottommosty = $bottom;
        }

        $targetfontcss = \mod_paper\utils::get_css_font_family($paper->targetlanguagefont ?? 'courier');
        // Bottom-align name/username text and display-only snippets, so both sit on the box's
        // writing line regardless of how generously the box itself was drawn.
        $valign = $area->isnamefield ? 'justify-content: flex-end;' : 'justify-content: flex-start;';
        $style = sprintf(
            'position: absolute; left: %s%%; top: %s%%; width: %s%%; height: %s%%; ' .
            'outline: 2px solid blue; background-color: rgba(0, 0, 255, 0.1); color: black; ' .
            'font-weight: normal; padding: 4px; box-sizing: border-box; overflow: visible; ' .
            'font-family: %s; cursor: pointer; display: flex; flex-direction: column; %s',
            $area->box_x, $area->box_y, $area->box_w, $area->box_h, $targetfontcss, $valign
        );

        $feedbackhtml = null;
        $feedbackstyle = null;
        if (!empty($feedback) && !$area->isnamefield && ($area->feedbackmode ?? 'none') !== 'none') {
            $feedbackfontcss = \mod_paper\utils::get_css_font_family($paper->feedbacklanguagefont ?? 'freesans');
            $feedbackhtml = htmlspecialchars(html_entity_decode($feedback, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            $fb = \mod_paper\utils::get_effective_feedback_box($area);
            $feedbackstyle = sprintf(
                'position: absolute; left: %s%%; top: %s%%; width: %s%%; height: %s%%; ' .
                'font-family: %s; font-size: 1em; font-weight: normal; color: black; ' .
                'background-color: rgba(0, 0, 255, 0.05); padding: 4px; ' .
                'box-sizing: border-box; line-height: 1.4; overflow: visible; z-index: 25;',
                $fb['x'], $fb['y'], $fb['w'], $fb['h'], $feedbackfontcss
            );
        }

        if ($area->isnamefield == 3) {
            $snippeturl = moodle_url::make_pluginfile_url($context->id, 'mod_paper', 'responsesnippet', $itemid, '/', 'snippet.jpg');
            $displayhtml = html_writer::empty_tag('img', [
                'src' => $snippeturl,
                'style' => 'display: block; max-width: 100%; max-height: 100%; object-fit: contain; align-self: center;',
            ]);
        } else if ($corrected !== '' && $area->grammarcorrections !== 'no' && !$area->isnamefield) {
            $displayhtml = \mod_paper\utils::build_combined_diff($ocr, $corrected);
        } else {
            $displayhtml = htmlspecialchars($corrected !== '' ? $corrected : $ocr);
        }

        $gradestyle = null;
        $showgrade = ($grade !== null && !$area->isnamefield && ($area->gradingmode ?? 'none') !== 'none');
        if ($showgrade) {
            $gradestyle = 'position: absolute; top: -20px; right: -25px; font-weight: bold; font-size: 2em; color: green; z-index: 30; background: white; border: 1px solid green; padding: 2px 6px; border-radius: 4px;';
        }

        $templatecontext['areas'][] = [
            'id' => $area->id,
            'itemid' => $itemid,
            'isnamefield' => $area->isnamefield,
            'ocr' => $ocr,
            'corrected' => $corrected,
            'feedback' => $feedback,
            'grade' => ($grade !== null) ? (round($grade, 2) + 0) : null,
            'responsenumber' => $area->responsenumber,
            'style' => $style,
            'displayhtml' => $displayhtml,
            'feedbackhtml' => $feedbackhtml,
            'feedbackstyle' => $feedbackstyle,
            'gradehtml' => ($showgrade),
            'gradestyle' => $gradestyle,
        ];
    }

    if ($templatecontext['showtotalscore']) {
        $templatecontext['scoredisplay'] = (round($currenteval->totalgrade ?? 0, 2) + 0) . ' / ' . $maxpossible;
        $templatecontext['scorestyle'] = sprintf(
            'position: absolute; left: 5%%; top: %s%%; font-size: 22px; font-weight: bold; color: #d9534f; background: rgba(255,255,255,0.9); padding: 5px 15px; border-radius: 8px; border: 2px solid #d9534f; z-index: 30;',
            min(92, $bottommosty + 2)
        );
    }
}

echo $OUTPUT->render_from_template('mod_paper/view_eval', $templatecontext);

// Initialize JS
$PAGE->requires->js_call_amd('mod_paper/view_eval', 'init', [
    'cmid' => $cm->id,
    'evalid' => $evalid,
    'maxpossible' => $maxpossible,
]);

echo $OUTPUT->footer();
