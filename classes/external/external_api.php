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
use mod_paper\constants;
use mod_paper\utils;

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
                      AND pra.areatype = :graded
                      AND pei.itemgrade IS NULL";
            $params = ['evalid' => $eval->id, 'graded' => constants::M_AREATYPE_GRADED];
            if ($DB->count_records_sql($sql, $params) > 0) {
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
            'ocrtext' => new external_value(PARAM_RAW, 'Original OCR text (null leaves it unchanged)', VALUE_DEFAULT, null),
        ]);
    }

    /**
     * Update an evaluation item
     */
    public static function update_eval_item(
        $cmid,
        $evalid,
        $areaid,
        $itemid = 0,
        $grade = null,
        $correctedtext = '',
        $feedback = '',
        $ocrtext = null
    ) {
        global $DB;

        $params = self::validate_parameters(self::update_eval_item_parameters(), [
            'cmid' => $cmid,
            'evalid' => $evalid,
            'areaid' => $areaid,
            'itemid' => $itemid,
            'grade' => $grade,
            'correctedtext' => $correctedtext,
            'feedback' => $feedback,
            'ocrtext' => $ocrtext,
        ]);

        $cm = get_coursemodule_from_id('paper', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/paper:manage', $context);

        $paper = $DB->get_record('paper', ['id' => $cm->instance], '*', MUST_EXIST);
        // Both are matched against this paper: the capability was checked on this course
        // module, so an evaluation or area belonging to another paper is not ours to edit.
        $DB->get_record('paper_evaluations', ['id' => $params['evalid'], 'paperid' => $paper->id], 'id', MUST_EXIST);
        $area = $DB->get_record('paper_response_areas', ['id' => $params['areaid'], 'paperid' => $paper->id], '*', MUST_EXIST);

        // Get or create item
        $item = null;
        if ($params['itemid'] > 0) {
            $item = $DB->get_record('paper_eval_items', ['id' => $params['itemid'], 'evalid' => $params['evalid']]);
        }
        if (!$item) {
            $item = $DB->get_record('paper_eval_items', ['evalid' => $params['evalid'], 'responseareaid' => $params['areaid']]);
        }

        $itemgrade = self::clamp_grade($area, $params['grade']);

        if (!$item) {
            $item = new stdClass();
            $item->evalid = $params['evalid'];
            $item->responseareaid = $params['areaid'];
            $item->ocrtext = $params['ocrtext'] ?? '';
            $item->correctedtext = $params['correctedtext'];
            $item->feedback = $params['feedback'];
            $item->itemgrade = ($itemgrade !== null) ? $itemgrade : 0;
            $item->id = $DB->insert_record('paper_eval_items', $item);
        } else {
            $item->correctedtext = $params['correctedtext'];
            $item->feedback = $params['feedback'];
            // NULL means the caller is not editing the OCR text, which is not the same as
            // clearing it - only overwrite when a value was actually sent.
            if ($params['ocrtext'] !== null) {
                $item->ocrtext = $params['ocrtext'];
            }
            if ($itemgrade !== null) {
                $item->itemgrade = $itemgrade;
            }
            $DB->update_record('paper_eval_items', $item);
        }

        self::apply_name_edit($area, $item, $params['evalid']);

        return self::build_item_response($paper, $area, $item, $params['evalid']);
    }

    /**
     * Returns for update_eval_item
     */
    public static function update_eval_item_returns() {
        return self::item_response_structure();
    }

    /**
     * Parameters for reevaluate_eval_item
     */
    public static function reevaluate_eval_item_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'evalid' => new external_value(PARAM_INT, 'Evaluation ID'),
            'areaid' => new external_value(PARAM_INT, 'Response area ID'),
            'itemid' => new external_value(PARAM_INT, 'Evaluation item ID'),
            'ocrtext' => new external_value(PARAM_RAW, 'OCR text to grade, null to use the stored text', VALUE_DEFAULT, null),
        ]);
    }

    /**
     * Re-runs AI grammar correction, feedback and grading for a single evaluation item,
     * synchronously, against whatever its OCR text says now.
     *
     * This is the single-item counterpart of re_evaluate.php: the item is put back into the
     * pending state (NULL correctedtext/feedback/itemgrade) and then graded on the spot
     * rather than being left for the adhoc task. If the AI call fails the item simply stays
     * pending, so the next scheduled evaluation run will pick it up.
     */
    public static function reevaluate_eval_item($cmid, $evalid, $areaid, $itemid, $ocrtext = null) {
        global $DB;

        $params = self::validate_parameters(self::reevaluate_eval_item_parameters(), [
            'cmid' => $cmid,
            'evalid' => $evalid,
            'areaid' => $areaid,
            'itemid' => $itemid,
            'ocrtext' => $ocrtext,
        ]);

        $cm = get_coursemodule_from_id('paper', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/paper:manage', $context);

        $paper = $DB->get_record('paper', ['id' => $cm->instance], '*', MUST_EXIST);
        $DB->get_record('paper_evaluations', ['id' => $params['evalid'], 'paperid' => $paper->id], 'id', MUST_EXIST);
        $area = $DB->get_record('paper_response_areas', ['id' => $params['areaid'], 'paperid' => $paper->id], '*', MUST_EXIST);
        $itemselect = [
            'id' => $params['itemid'],
            'evalid' => $params['evalid'],
            'responseareaid' => $params['areaid'],
        ];
        $item = $DB->get_record('paper_eval_items', $itemselect, '*', MUST_EXIST);

        // Only graded areas ever go through stage 2, so there is nothing to re-run for the rest.
        if (!utils::is_graded_area($area)) {
            throw new \invalid_parameter_exception('Response area ' . $area->id . ' is not a graded area');
        }

        // Back to pending - see evaluation_processor's class docblock on why this is NULL.
        // Any OCR text sent with the call is saved first, so what the teacher is looking at
        // in the editing sidebar is what gets graded.
        $reset = (object)[
            'id' => $item->id,
            'correctedtext' => null,
            'feedback' => null,
            'itemgrade' => null,
        ];
        if ($params['ocrtext'] !== null) {
            $reset->ocrtext = $params['ocrtext'];
        }
        $DB->update_record('paper_eval_items', $reset);
        $item = $DB->get_record('paper_eval_items', ['id' => $item->id], '*', MUST_EXIST);

        // Progress is reported by evaluation_processor with mtrace(), which echoes into the
        // response body outside CLI and would corrupt the JSON we return.
        $evalprocessor = new \mod_paper\evaluation_processor($paper);
        // One grading request, which the mod_paper/openaitimeout setting allows up to 120s
        // by default - more than a web request's usual execution limit.
        self::set_timeout();
        ob_start();
        try {
            $evalprocessor->evaluate_area($area, [$item]);
        } finally {
            ob_end_clean();
        }

        $item = $DB->get_record('paper_eval_items', ['id' => $item->id], '*', MUST_EXIST);

        $response = self::build_item_response($paper, $area, $item, $params['evalid']);

        // Still pending means the AI gave us nothing back. Leave it that way so the next
        // evaluation run retries it, and say so rather than reporting a silent success.
        if ($item->correctedtext === null) {
            $response['success'] = false;
            $response['error'] = get_string('reevaluateitemfailed', 'mod_paper');
        }

        return $response;
    }

    /**
     * Returns for reevaluate_eval_item
     */
    public static function reevaluate_eval_item_returns() {
        return self::item_response_structure();
    }

    /**
     * The shared return shape of the two item-editing calls.
     */
    protected static function item_response_structure() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether operation was successful'),
            'newhtml' => new external_value(PARAM_RAW, 'New HTML for the response area'),
            'totalgrade' => new external_value(PARAM_TEXT, 'New total grade for the evaluation'),
            'itemid' => new external_value(PARAM_INT, 'ID of the updated evaluation item'),
            // The stored item as it now stands, so the editing sidebar can resync itself
            // after a call that changed values the caller did not send (a re-evaluation).
            'correctedtext' => new external_value(PARAM_RAW, 'Stored corrected text'),
            'feedback' => new external_value(PARAM_RAW, 'Stored feedback'),
            'grade' => new external_value(PARAM_FLOAT, 'Stored grade, null if ungraded', VALUE_REQUIRED, null, NULL_ALLOWED),
            'error' => new external_value(PARAM_TEXT, 'Error message when success is false', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Constrains a teacher-entered grade to the range the response area allows.
     *
     * A NULL maxgrade means no maximum was ever configured for the area (setup.php always
     * writes one), so nothing is capped in that case.
     *
     * @param object $area A paper_response_areas record.
     * @param float|null $grade The submitted grade, or null if none was submitted.
     * @return float|null The grade to store, or null to leave the stored grade alone.
     */
    protected static function clamp_grade($area, $grade) {
        if ($grade === null || !utils::is_graded_area($area)) {
            return null;
        }
        if ($area->maxgrade === null) {
            return max(0, (float)$grade);
        }

        return min(max(0, (float)$grade), (float)$area->maxgrade);
    }

    /**
     * Keeps the evaluation's student name in step with an edit to a name/username area.
     *
     * Name areas never reach stage 2, so they have no corrected text of their own and the
     * teacher's fix normally lands in the OCR text - fall back to that. A display-only or
     * ungraded area is still someone's answer, not their name, so editing one must not
     * overwrite the evaluation's student name.
     *
     * @param object $area A paper_response_areas record.
     * @param object $item The saved paper_eval_items record.
     * @param int $evalid The evaluation being edited.
     */
    protected static function apply_name_edit($area, $item, $evalid) {
        global $DB;

        if (!utils::is_name_area($area)) {
            return;
        }

        $nametext = (string)$item->correctedtext;
        if (trim($nametext) === '') {
            $nametext = (string)$item->ocrtext;
        }
        $DB->set_field('paper_evaluations', 'studentnametext', $nametext, ['id' => $evalid]);

        // A corrected username should re-link the evaluation to its Moodle user, the same
        // way submission_processor does when the text first comes back from OCR. An
        // unmatched username leaves the existing link alone rather than orphaning grades.
        if ((int)$area->areatype === constants::M_AREATYPE_USERNAME) {
            $user = $DB->get_record('user', ['username' => trim($nametext), 'deleted' => 0]);
            if ($user) {
                $DB->set_field('paper_evaluations', 'userid', $user->id, ['id' => $evalid]);
            }
        }
    }

    /**
     * Recalculates the evaluation total, pushes it to the gradebook, and re-renders the
     * response area for the review page.
     *
     * @param object $paper The paper instance record.
     * @param object $area A paper_response_areas record.
     * @param object $item The saved paper_eval_items record.
     * @param int $evalid The evaluation being edited.
     * @return array The web service response.
     */
    protected static function build_item_response($paper, $area, $item, $evalid) {
        global $DB, $OUTPUT;

        $evalprocessor = new \mod_paper\evaluation_processor($paper);
        $totalgrade = $evalprocessor->recalculate_total_grade($evalid);

        $evaluation = $DB->get_record('paper_evaluations', ['id' => $evalid]);
        if (!empty($evaluation->userid)) {
            $grades = [];
            $grades[$evaluation->userid] = new stdClass();
            $grades[$evaluation->userid]->userid = $evaluation->userid;
            $grades[$evaluation->userid]->rawgrade = $totalgrade;
            paper_grade_item_update($paper, $grades);
        }

        $ocrtext = (string)$item->ocrtext;
        $correctedtext = (string)$item->correctedtext;

        if ($correctedtext !== '' && $area->grammarcorrections !== 'no' && utils::is_graded_area($area)) {
            $displayhtml = utils::build_combined_diff($ocrtext, $correctedtext);
        } else {
            $displayhtml = htmlspecialchars($correctedtext !== '' ? $correctedtext : $ocrtext);
        }

        // Same test view_eval.php uses when it first renders the page, so an edit does not
        // put a grade badge on an area that was not showing one.
        $showgrade = ($item->itemgrade !== null && utils::is_graded_area($area)
            && ($area->gradingmode ?? 'none') !== 'none');
        $gradestyle = null;
        if ($showgrade) {
            $gradestyle = 'position: absolute; top: -20px; right: -25px; font-weight: bold; ' .
                'font-size: 2em; color: green; z-index: 30; background: white; ' .
                'border: 1px solid green; padding: 2px 6px; border-radius: 4px;';
        }

        $rendercontext = [
            'displayhtml' => $displayhtml,
            'gradehtml' => $showgrade,
            'gradestyle' => $gradestyle,
            'grade' => $showgrade ? (round($item->itemgrade, 2) + 0) : null,
        ];

        return [
            'success' => true,
            'newhtml' => $OUTPUT->render_from_template('mod_paper/eval_item_content', $rendercontext),
            'totalgrade' => (string)$totalgrade,
            'itemid' => $item->id,
            'correctedtext' => $correctedtext,
            'feedback' => (string)$item->feedback,
            'grade' => ($item->itemgrade !== null) ? (round($item->itemgrade, 2) + 0) : null,
        ];
    }
}
