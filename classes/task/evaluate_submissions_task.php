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
 * Evaluate Submissions Background Task
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_paper\task;

defined('MOODLE_INTERNAL') || die();

class evaluate_submissions_task extends \core\task\adhoc_task {
    
    /**
     * Run the background processing
     */
    public function execute() {
        global $DB;
        
        $customdata = $this->get_custom_data();
        if (empty($customdata->paperid)) {
            mtrace("Missing required custom data (paperid).");
            return;
        }
        
        $paperid = $customdata->paperid;
        $paper = $DB->get_record('paper', ['id' => $paperid], '*', MUST_EXIST);
        
        mtrace("Starting evaluation for Paper ID {$paperid}");
        
        // Find items that need evaluation or grading
        $sql = "SELECT pei.* 
                FROM {paper_eval_items} pei
                JOIN {paper_evaluations} pe ON pe.id = pei.evalid
                JOIN {paper_response_areas} pra ON pra.id = pei.responseareaid
                WHERE pe.paperid = :paperid 
                  AND pra.isnamefield = 0 
                  AND (pei.correctedtext = '' OR pei.itemgrade IS NULL)";
        
        $items = $DB->get_records_sql($sql, ['paperid' => $paperid]);
        
        if (empty($items)) {
            mtrace("No pending evaluations found for paper {$paperid}");
            return;
        }
        
        // Group items by responseareaid
        $grouped_items = [];
        foreach ($items as $item) {
            $grouped_items[$item->responseareaid][] = $item;
        }
        
        $aimanager = new \mod_paper\ai_manager();
        $affected_evals = [];
        
        foreach ($grouped_items as $areaid => $area_items) {
            $area = $DB->get_record('paper_response_areas', ['id' => $areaid]);
            if (!$area) continue;
            
            mtrace("Processing Area #{$area->responsenumber} (ID: {$areaid}) with " . count($area_items) . " items...");
            
            $batch_texts = [];
            foreach ($area_items as $item) {
                if (!empty(trim($item->ocrtext))) {
                    $batch_texts[$item->id] = trim($item->ocrtext);
                } else {
                    // Mark as processed if empty
                    $DB->set_field('paper_eval_items', 'itemgrade', 0, ['id' => $item->id]);
                    $DB->set_field('paper_eval_items', 'correctedtext', ' ', ['id' => $item->id]); // Use space to mark as 'processed'
                    $affected_evals[$item->evalid] = true;
                }
            }
            
            if (!empty($batch_texts)) {
                try {
                    $results = $aimanager->batch_process_evaluations($area, $batch_texts, $paper->feedbacklanguage);
                    mtrace("Area #{$area->responsenumber} results received: " . count($results) . " items.");
                    foreach ($results as $itemid => $result) {
                        $update = new \stdClass();
                        $update->id = (int)$itemid;
                        $update->correctedtext = $result['correctedtext'] ?? ' ';
                        $update->itemgrade = $result['grade'] ?? 0;
                        $update->feedback = $result['feedback'] ?? '';
                        $DB->update_record('paper_eval_items', $update);
                        
                        // Find the evalid for this item to update total grade later
                        foreach ($area_items as $ai) {
                            if ($ai->id == $itemid) {
                                $affected_evals[$ai->evalid] = true;
                                break;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    mtrace("Failed to process Area #{$area->responsenumber}: " . $e->getMessage());
                }
            }
        }
        
        // Recalculate total grades for affected evaluations
        if (!empty($affected_evals)) {
            mtrace("Recalculating total grades for " . count($affected_evals) . " evaluations...");
            foreach (array_keys($affected_evals) as $evalid) {
                $total = $DB->get_field_sql("SELECT SUM(itemgrade) FROM {paper_eval_items} WHERE evalid = :evalid", ['evalid' => $evalid]);
                $DB->set_field('paper_evaluations', 'totalgrade', $total ?: 0, ['id' => $evalid]);
            }
        }
        
        mtrace("Evaluation complete for Paper ID {$paperid}");
    }
}
