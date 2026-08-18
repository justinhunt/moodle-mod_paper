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
 * Reports Script for mod_paper
 *
 * @package    mod_paper
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once('lib.php');

$id = required_param('id', PARAM_INT); // Course module ID

$cm = get_coursemodule_from_id('paper', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$paper = $DB->get_record('paper', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/paper:manage', $context);

$PAGE->set_url('/mod/paper/reports.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('reports', 'mod_paper'));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('evaluationreportsfor', 'mod_paper', format_string($paper->name)));

$evaluations = $DB->get_records('paper_evaluations', ['paperid' => $paper->id]);
$evalcount = count($evaluations);

$templatecontext = [
    'noevaluations' => empty($evaluations),
    'evaluations' => [],
    'pendingoverall' => false,
    'actionbuttons' => [],
    'returntotopurl' => (new moodle_url('/mod/paper/view.php', ['id' => $cm->id]))->out(false),
];

// Check if any gradable areas exist for this paper (gradingmode != 'none').
$hasgradeareas = $DB->record_exists_select(
    'paper_response_areas',
    "paperid = :paperid AND isnamefield = 0 AND gradingmode != 'none'",
    ['paperid' => $paper->id]
);

if (!empty($evaluations)) {
    foreach ($evaluations as $eval) {
        $viewurl = new moodle_url('/mod/paper/view_eval.php', ['id' => $cm->id, 'evalid' => $eval->id]);
        $deleteurl = new moodle_url('/mod/paper/delete_eval.php', ['id' => $cm->id, 'evalid' => $eval->id, 'sesskey' => sesskey()]);
        $individualdownloadurl = moodle_url::make_pluginfile_url($context->id, 'mod_paper', 'downloadevaluations', $eval->id, '/', 'evaluation.pdf');

        // Check if evaluation is pending.
        $sql = "SELECT COUNT(pei.id)
                FROM {paper_eval_items} pei
                JOIN {paper_response_areas} pra ON pra.id = pei.responseareaid
                WHERE pei.evalid = :evalid AND pra.isnamefield = 0 AND pei.itemgrade IS NULL";
        $pendingcount = $DB->count_records_sql($sql, ['evalid' => $eval->id]);

        $actions = [];
        if ($pendingcount == 0) {
            $actions[] = [
                'url' => $viewurl->out(false),
                'icon' => $OUTPUT->pix_icon('t/preview', get_string('viewevaluation', 'mod_paper')),
                'class' => 'mr-2',
                'title' => get_string('viewevaluation', 'mod_paper'),
            ];
        } else {
            $templatecontext['pendingoverall'] = true;
            $actions[] = [
                'url' => '#',
                'icon' => '...',
                'class' => 'text-muted mr-2',
                'title' => get_string('evaluationpending', 'mod_paper'),
                'onclick' => 'return false;',
            ];
        }

        $actions[] = [
            'url' => $individualdownloadurl->out(false),
            'icon' => $OUTPUT->pix_icon('f/pdf', get_string('download')),
            'class' => 'mr-2',
            'title' => get_string('download'),
            'target' => '_blank',
        ];

        $actions[] = [
            'url' => $deleteurl->out(false),
            'icon' => $OUTPUT->pix_icon('t/delete', get_string('delete')),
            'class' => 'text-danger',
            'title' => get_string('delete'),
            'onclick' => "return confirm('" . get_string('deleteevaluationconfirm', 'mod_paper') . "');",
        ];

        $templatecontext['evaluations'][] = [
            'id' => $eval->id,
            'studentname' => $eval->studentnametext,
            'totalgrade' => $hasgradeareas ? $eval->totalgrade : '',
            'actions' => $actions,
        ];
    }
}

// Detect if any background processing tasks are queued for this paper.
// This is the definitive signal — catches re-evaluate, fresh uploads, etc.
$taskclasses = [
    '\\mod_paper\\task\\process_submissions_task',
    '\\mod_paper\\task\\evaluate_submissions_task',
];
$hastask = false;
foreach ($taskclasses as $taskclass) {
    $sql = "SELECT COUNT(id) FROM {task_adhoc} WHERE classname = :classname";
    if ($DB->count_records_sql($sql, ['classname' => $taskclass]) > 0) {
        $hastask = true;
        break;
    }
}

// Poll whenever there is a queued task OR pending eval items.
$shouldpoll = $hastask || $templatecontext['pendingoverall'];
if ($shouldpoll) {
    $PAGE->requires->js_call_amd('mod_paper/reports', 'init', [
        $cm->id,
        $evalcount,
        $templatecontext['pendingoverall'] || $hastask,
    ]);
}

// Action buttons — disabled when there is nothing to act on.
$nodata = empty($evaluations);
$disabledtitle = $nodata ? get_string('noevaluationsyet', 'mod_paper') : '';

$templatecontext['actionbuttons'][] = [
    'url' => moodle_url::make_pluginfile_url($context->id, 'mod_paper', 'downloadevaluations', 0, '/', 'evaluations.pdf')->out(false),
    'text' => get_string('viewallcombinedpdfs', 'mod_paper'),
    'class' => 'btn btn-primary',
    'target' => '_blank',
    'disabled' => $nodata,
    'title' => $disabledtitle,
];
$templatecontext['actionbuttons'][] = [
    'url' => (new moodle_url('/mod/paper/re_evaluate.php', ['id' => $cm->id, 'sesskey' => sesskey()]))->out(false),
    'text' => get_string('reevaluateall', 'mod_paper'),
    'class' => 'btn btn-warning',
    'onclick' => "return confirm('" . get_string('reevaluateallconfirm', 'mod_paper') . "');",
    'disabled' => $nodata,
    'title' => $disabledtitle,
];
$templatecontext['actionbuttons'][] = [
    'url' => (new moodle_url('/mod/paper/delete_all_evals.php', ['id' => $cm->id, 'sesskey' => sesskey()]))->out(false),
    'text' => get_string('deleteallsubmissions', 'mod_paper'),
    'class' => 'btn btn-danger',
    'onclick' => "return confirm('" . get_string('deleteallsubmissionsconfirm', 'mod_paper') . "');",
    'disabled' => $nodata,
    'title' => $disabledtitle,
];

echo $OUTPUT->render_from_template('mod_paper/reports_page', $templatecontext);

echo $OUTPUT->footer();
