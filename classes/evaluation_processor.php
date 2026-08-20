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
        foreach ($groupeditems as $areaid => $areaitems) {
            $area = $DB->get_record('paper_response_areas', ['id' => $areaid]);
            if (!$area) {
                continue;
            }
            foreach ($this->evaluate_area($area, $areaitems) as $evalid) {
                $affectedevals[$evalid] = true;
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
                  AND (pei.correctedtext = '' OR pei.itemgrade IS NULL)";

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
        global $DB;

        mtrace("Processing Area #{$area->responsenumber} (ID: {$area->id}) with " . count($items) . " items...");

        $affectedevalids = [];
        $batchtexts = [];
        foreach ($items as $item) {
            if (!empty(trim($item->ocrtext))) {
                $batchtexts[$item->id] = trim($item->ocrtext);
            } else {
                // Mark as processed if empty.
                $DB->set_field('paper_eval_items', 'itemgrade', 0, ['id' => $item->id]);
                $DB->set_field('paper_eval_items', 'correctedtext', ' ', ['id' => $item->id]); // Use space to mark as 'processed'.
                $affectedevalids[$item->evalid] = true;
            }
        }

        if (!empty($batchtexts)) {
            try {
                $results = $this->aimanager->batch_process_evaluations($area, $batchtexts, $this->paper->feedbacklanguage);
                mtrace("Area #{$area->responsenumber} results received: " . count($results) . " items.");
                foreach ($results as $itemid => $result) {
                    $update = new \stdClass();
                    $update->id = (int)$itemid;
                    $update->correctedtext = $result['correctedtext'] ?? ' ';
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
            } catch (\Exception $e) {
                mtrace("Failed to process Area #{$area->responsenumber}: " . $e->getMessage());
            }
        }

        return array_keys($affectedevalids);
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
