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
 * Evaluation processing service for mod_paper
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_paper;

defined('MOODLE_INTERNAL') || die();

/**
 * Grammar-corrects and grades OCR'd response text via AI, then recalculates evaluation
 * totals. Used by evaluate_submissions_task, but plain enough to call synchronously from
 * anywhere else that needs to (re-)evaluate a paper - or a single response area, or a
 * single evaluation's total - without going through the adhoc task queue.
 *
 * Whether an item still needs processing is carried by paper_eval_items itself, in three
 * distinct states:
 *
 *   correctedtext IS NULL  - never processed; waiting for this class to pick it up
 *   correctedtext = ''     - processed, but there was nothing to correct (blank answer)
 *   correctedtext = '...'  - processed, with content
 *
 * itemgrade follows the same shape (NULL until graded, a number afterwards), and either
 * being NULL marks the item pending. Both are nullable columns, so "no value yet" is
 * stored as an actual NULL rather than as a magic string: an earlier version used a
 * single space to mean "processed, nothing to say", which MySQL/MariaDB's default
 * PAD SPACE collations compare equal to '', so those items matched the pending query on
 * every run forever.
 */
class evaluation_processor {

    /** @var object The paper instance record. */
    protected $paper;

    /** @var ai_manager */
    protected $aimanager;

    /**
     * @param object $paper The paper instance record.
     */
    public function __construct($paper) {
        $this->paper = $paper;
        $this->aimanager = new \mod_paper\ai_manager();
    }

    /**
     * Grammar-corrects and grades every pending response item for this paper, grouped by
     * response area for batching, then recalculates the total grade for every evaluation
     * that changed.
     */
    public function process_paper() {
        mtrace("Starting evaluation for Paper ID {$this->paper->id}");

        $items = $this->find_pending_items();
        if (empty($items)) {
            mtrace("No pending evaluations found for paper {$this->paper->id}");
            return;
        }

        $groupeditems = [];
        foreach ($items as $item) {
            $groupeditems[$item->responseareaid][] = $item;
        }

        global $DB;
        $affectedevals = [];

        // Each area is one request covering all of its pending items. Collect them first so
        // the areas can then be graded concurrently rather than one after another.
        $areas = [];
        $itemsbyarea = [];
        foreach ($groupeditems as $areaid => $areaitems) {
            $area = $DB->get_record('paper_response_areas', ['id' => $areaid]);
            if (!$area) {
                continue;
            }
            $areas[$areaid] = $area;

            $batchtexts = $this->prepare_area_batch($areaitems, $affectedevals);
            if (!empty($batchtexts)) {
                $itemsbyarea[$areaid] = $batchtexts;
            }
            mtrace("Prepared Area #{$area->responsenumber} (ID: {$areaid}) with " . count($areaitems) . " items...");
        }

        if (!empty($itemsbyarea)) {
            mtrace("Grading " . count($itemsbyarea) . " response areas...");
            $batchresults = $this->aimanager->batch_process_evaluations_multi(
                $areas,
                $itemsbyarea,
                $this->paper->feedbacklanguage
            );

            foreach ($batchresults as $areaid => $batchresult) {
                if (!empty($batchresult['error'])) {
                    mtrace("Failed to process Area #{$areas[$areaid]->responsenumber}: " . $batchresult['error']);
                    continue;
                }
                mtrace("Area #{$areas[$areaid]->responsenumber} results received: "
                    . count($batchresult['results']) . " items.");
                $this->apply_area_results($groupeditems[$areaid], $batchresult['results'], $affectedevals);
            }
        }

        if (!empty($affectedevals)) {
            mtrace("Recalculating total grades for " . count($affectedevals) . " evaluations...");
            foreach (array_keys($affectedevals) as $evalid) {
                $this->recalculate_total_grade($evalid);
            }
        }

        mtrace("Evaluation complete for Paper ID {$this->paper->id}");
    }

    /**
     * Finds response items for this paper that still need grammar correction and/or
     * grading (excludes name fields, which are never graded).
     *
     * "Not yet processed" is NULL on either field - see the class docblock for the three
     * states correctedtext can be in. Testing for an empty string here instead would
     * re-select every blank answer forever, because MySQL/MariaDB's default PAD SPACE
     * collations treat '' and ' ' as equal.
     *
     * @return array paper_eval_items records.
     */
    public function find_pending_items() {
        global $DB;

        $sql = "SELECT pei.*
                FROM {paper_eval_items} pei
                JOIN {paper_evaluations} pe ON pe.id = pei.evalid
                JOIN {paper_response_areas} pra ON pra.id = pei.responseareaid
                WHERE pe.paperid = :paperid
                  AND pra.isnamefield = 0
                  AND (pei.correctedtext IS NULL OR pei.itemgrade IS NULL)";

        return $DB->get_records_sql($sql, ['paperid' => $this->paper->id]);
    }

    /**
     * Grammar-corrects and grades a batch of pending items belonging to a single response
     * area (items with no OCR text are marked as processed with a zero grade instead of
     * being sent to the AI).
     *
     * @param object $area A paper_response_areas record.
     * @param array $items Pending paper_eval_items records for this area.
     * @return array Ids of evaluations that had an item change, for total-grade recalculation.
     */
    public function evaluate_area($area, array $items) {
        mtrace("Processing Area #{$area->responsenumber} (ID: {$area->id}) with " . count($items) . " items...");

        $affectedevalids = [];
        $batchtexts = $this->prepare_area_batch($items, $affectedevalids);

        if (!empty($batchtexts)) {
            try {
                $results = $this->aimanager->batch_process_evaluations($area, $batchtexts, $this->paper->feedbacklanguage);
                mtrace("Area #{$area->responsenumber} results received: " . count($results) . " items.");
                $this->apply_area_results($items, $results, $affectedevalids);
            } catch (\Exception $e) {
                mtrace("Failed to process Area #{$area->responsenumber}: " . $e->getMessage());
            }
        }

        return array_keys($affectedevalids);
    }

    /**
     * Picks out the items that actually need grading, short-circuiting empty responses -
     * there is nothing for the AI to say about a blank answer, so it is marked processed
     * with a zero grade instead of costing a request.
     *
     * @param array $items Pending paper_eval_items records for one area.
     * @param array $affectedevalids Set of evaluation ids that changed, added to in place.
     * @return array [itemid => ocrtext] for the items worth sending.
     */
    protected function prepare_area_batch(array $items, array &$affectedevalids) {
        global $DB;

        $batchtexts = [];
        foreach ($items as $item) {
            if (!empty(trim($item->ocrtext))) {
                $batchtexts[$item->id] = trim($item->ocrtext);
            } else {
                // Nothing to correct, so mark it processed with a zero grade rather than
                // spending a request on it. Empty string (not NULL) is what says "done".
                $DB->set_field('paper_eval_items', 'itemgrade', 0, ['id' => $item->id]);
                $DB->set_field('paper_eval_items', 'correctedtext', '', ['id' => $item->id]);
                $affectedevalids[$item->evalid] = true;
            }
        }

        return $batchtexts;
    }

    /**
     * Writes one area's grading results back onto its items.
     *
     * @param array $items The paper_eval_items records the results belong to.
     * @param array $results [itemid => ['correctedtext' => ..., 'grade' => ..., 'feedback' => ...]].
     * @param array $affectedevalids Set of evaluation ids that changed, added to in place.
     */
    protected function apply_area_results(array $items, array $results, array &$affectedevalids) {
        global $DB;

        foreach ($results as $itemid => $result) {
            $update = new \stdClass();
            $update->id = (int)$itemid;
            // Never NULL here: the item has been processed, even if the model gave us
            // nothing usable back, and NULL would put it straight back in the queue.
            $update->correctedtext = $result['correctedtext'] ?? '';
            $update->itemgrade = $result['grade'] ?? 0;
            $update->feedback = $result['feedback'] ?? '';
            $DB->update_record('paper_eval_items', $update);

            // Find the evalid for this item to update total grade later.
            foreach ($items as $ai) {
                if ($ai->id == $itemid) {
                    $affectedevalids[$ai->evalid] = true;
                    break;
                }
            }
        }
    }

    /**
     * Recalculates and stores one evaluation's total grade from the sum of its items'
     * grades.
     *
     * @param int $evalid The evaluation to recalculate.
     * @return float The recalculated total grade, rounded to 2 decimal places.
     */
    public function recalculate_total_grade($evalid) {
        global $DB;

        $total = $DB->get_field_sql("SELECT SUM(itemgrade) FROM {paper_eval_items} WHERE evalid = :evalid", ['evalid' => $evalid]);
        $total = ($total !== null && $total !== false) ? round($total, 2) + 0 : 0;

        $DB->set_field('paper_evaluations', 'totalgrade', $total, ['id' => $evalid]);

        return $total;
    }
}
