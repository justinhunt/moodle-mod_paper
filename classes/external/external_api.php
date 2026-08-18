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
 * External API for mod_paper
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_paper\external;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;
use context_module;
use stdClass;
use html_writer;

/**
 * External API for mod_paper
 */
class external_api extends \core_external\external_api {

    /**
     * Parameters for check_status
     */
    public static function check_status_parameters() {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Course module ID'),
            'currentcount' => new external_value(PARAM_INT, 'Current number of evaluations on page', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Check processing status
     */
    public static function check_status($id, $currentcount = 0) {
        global $DB;

        $params = self::validate_parameters(self::check_status_parameters(), [
            'id' => $id,
            'currentcount' => $currentcount,
        ]);

        $cm = get_coursemodule_from_id('paper', $params['id'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);

        $paper = $DB->get_record('paper', ['id' => $cm->instance], '*', MUST_EXIST);

        $evals = $DB->get_records('paper_evaluations', ['paperid' => $paper->id]);
        $count = count($evals);

        // Check for queued background tasks — the definitive "still processing" signal.
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

        // Also check for any eval items still awaiting evaluation.
        $pendingitems = false;
        foreach ($evals as $eval) {
            $sql = "SELECT COUNT(pei.id)
                    FROM {paper_eval_items} pei
                    JOIN {paper_response_areas} pra ON pra.id = pei.responseareaid
                    WHERE pei.evalid = :evalid
                      AND pra.isnamefield = 0
                      AND pei.itemgrade IS NULL";
            if ($DB->count_records_sql($sql, ['evalid' => $eval->id]) > 0) {
                $pendingitems = true;
                break;
            }
        }

        // Complete only when no tasks queued and no items pending.
        $complete = !$hastask && !$pendingitems;

        return [
            'complete' => $complete,
            'count' => $count,
        ];
    }

    /**
     * Returns for check_status
     */
    public static function check_status_returns() {
        return new external_single_structure([
            'complete' => new external_value(PARAM_BOOL, 'Whether processing is complete'),
            'count' => new external_value(PARAM_INT, 'Total count of evaluations'),
        ]);
    }

    /**
     * Parameters for update_eval_item
     */
    public static function update_eval_item_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'evalid' => new external_value(PARAM_INT, 'Evaluation ID'),
            'areaid' => new external_value(PARAM_INT, 'Response area ID'),
            'itemid' => new external_value(PARAM_INT, 'Evaluation item ID (0 for new)', VALUE_DEFAULT, 0),
            'grade' => new external_value(PARAM_FLOAT, 'Grade value', VALUE_DEFAULT, null),
            'correctedtext' => new external_value(PARAM_RAW, 'Corrected text', VALUE_DEFAULT, ''),
            'feedback' => new external_value(PARAM_RAW, 'Feedback text', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Update an evaluation item
     */
    public static function update_eval_item($cmid, $evalid, $areaid, $itemid = 0, $grade = null, $correctedtext = '', $feedback = '') {
        global $DB, $PAGE, $OUTPUT;

        $params = self::validate_parameters(self::update_eval_item_parameters(), [
            'cmid' => $cmid,
            'evalid' => $evalid,
            'areaid' => $areaid,
            'itemid' => $itemid,
            'grade' => $grade,
            'correctedtext' => $correctedtext,
            'feedback' => $feedback,
        ]);

        $cm = get_coursemodule_from_id('paper', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/paper:manage', $context);

        $paper = $DB->get_record('paper', ['id' => $cm->instance], '*', MUST_EXIST);
        $area = $DB->get_record('paper_response_areas', ['id' => $params['areaid']], '*', MUST_EXIST);

        // Get or create item
        $item = null;
        if ($params['itemid'] > 0) {
            $item = $DB->get_record('paper_eval_items', ['id' => $params['itemid'], 'evalid' => $params['evalid']]);
        }
        if (!$item) {
            $item = $DB->get_record('paper_eval_items', ['evalid' => $params['evalid'], 'responseareaid' => $params['areaid']]);
        }

        if (!$item) {
            $item = new stdClass();
            $item->evalid = $params['evalid'];
            $item->responseareaid = $params['areaid'];
            $item->ocrtext = '';
            $item->correctedtext = $params['correctedtext'];
            $item->feedback = $params['feedback'];
            $item->itemgrade = ($params['grade'] !== null && !$area->isnamefield) ? $params['grade'] : 0;
            $item->id = $DB->insert_record('paper_eval_items', $item);
        } else {
            $item->correctedtext = $params['correctedtext'];
            $item->feedback = $params['feedback'];
            if ($params['grade'] !== null && !$area->isnamefield) {
                $item->itemgrade = $params['grade'];
            }
            $DB->update_record('paper_eval_items', $item);
        }

        if ($area->isnamefield) {
            $DB->set_field('paper_evaluations', 'studentnametext', $item->correctedtext, ['id' => $params['evalid']]);
        }

        // Recalculate total grade
        $sql = "SELECT SUM(pei.itemgrade) 
                FROM {paper_eval_items} pei
                JOIN {paper_response_areas} pra ON pra.id = pei.responseareaid
                WHERE pei.evalid = :evalid AND pra.isnamefield = 0";
        $totalgrade = $DB->get_field_sql($sql, ['evalid' => $params['evalid']]);
        $totalgrade = round($totalgrade, 2) + 0;

        $DB->set_field('paper_evaluations', 'totalgrade', $totalgrade, ['id' => $params['evalid']]);

        // Update Gradebook
        $evaluation = $DB->get_record('paper_evaluations', ['id' => $params['evalid']]);
        if (!empty($evaluation->userid)) {
            $grades = [];
            $grades[$evaluation->userid] = new stdClass();
            $grades[$evaluation->userid]->userid = $evaluation->userid;
            $grades[$evaluation->userid]->rawgrade = $totalgrade;
            paper_grade_item_update($paper, $grades);
        }

        // Build HTML context.
        if ($item->correctedtext !== '' && $area->grammarcorrections !== 'no' && !$area->isnamefield) {
            $displayhtml = \mod_paper\utils::build_combined_diff($item->ocrtext, $item->correctedtext);
        } else {
            $displayhtml = htmlspecialchars($item->correctedtext !== '' ? $item->correctedtext : $item->ocrtext);
        }

        $gradestyle = null;
        if ($item->itemgrade !== null && !$area->isnamefield) {
            $gradestyle = 'position: absolute; top: -20px; right: -25px; font-weight: bold; ' .
                'font-size: 2em; color: green; z-index: 30; background: white; ' .
                'border: 1px solid green; padding: 2px 6px; border-radius: 4px;';
        }

        $rendercontext = [
            'displayhtml' => $displayhtml,
            'gradehtml' => ($item->itemgrade !== null && !$area->isnamefield),
            'gradestyle' => $gradestyle,
            'grade' => (round($item->itemgrade, 2) + 0),
        ];

        $newhtml = $OUTPUT->render_from_template('mod_paper/eval_item_content', $rendercontext);

        return [
            'success' => true,
            'newhtml' => $newhtml,
            'totalgrade' => (string)$totalgrade,
            'itemid' => $item->id,
        ];
    }

    /**
     * Returns for update_eval_item
     */
    public static function update_eval_item_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether operation was successful'),
            'newhtml' => new external_value(PARAM_RAW, 'New HTML for the response area'),
            'totalgrade' => new external_value(PARAM_TEXT, 'New total grade for the evaluation'),
            'itemid' => new external_value(PARAM_INT, 'ID of the updated evaluation item'),
        ]);
    }
}
