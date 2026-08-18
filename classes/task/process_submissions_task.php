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
 * Process Submissions Background Task
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_paper\task;

defined('MOODLE_INTERNAL') || die();

class process_submissions_task extends \core\task\adhoc_task {
    
    /**
     * Run the background processing
     */
    public function execute() {
        global $DB;
        
        $customdata = $this->get_custom_data();
        if (empty($customdata->paperid) || empty($customdata->batchid)) {
            mtrace("Missing required custom data.");
            return;
        }
        
        $paperid = $customdata->paperid;
        $batchid = $customdata->batchid;
        
        $paper = $DB->get_record('paper', ['id' => $paperid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('paper', $paper->id, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        
        mtrace("Starting processing for Paper ID {$paperid}, Batch ID {$batchid}");
        
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_paper', 'submissions', $batchid, 'filename', false);
        
        if (empty($files)) {
            mtrace("No files found for batch {$batchid}");
            return;
        }
        
        $aimanager = new \mod_paper\ai_manager();
        $pdfprocessor = new \mod_paper\pdf_processor();
        $areas = $DB->get_records('paper_response_areas', ['paperid' => $paper->id], 'responsenumber ASC');
        
        $tempdir = make_request_directory();
        $imagequeue = [];
        
        // 1. Unroll PDFs and extract images
        foreach ($files as $file) {
            $filename = $file->get_filename();
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $tmpname = $tempdir . '/' . $file->get_contenthash() . '_' . $filename;
            
            $file->copy_content_to($tmpname);
            
            if ($ext === 'pdf') {
                mtrace("Unrolling PDF: {$filename}");
                try {
                    $unrolled_jpgs = $pdfprocessor->pdf_to_images($tmpname, $tempdir);
                    foreach ($unrolled_jpgs as $jpg) {
                        $imagequeue[] = $jpg;
                    }
                } catch (\Exception $e) {
                    mtrace("Failed to unroll PDF $filename: " . $e->getMessage());
                }
            } else if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
                $imagequeue[] = $tmpname;
            } else {
                mtrace("Skipped unsupported file type: {$filename}");
            }
        }
        
        mtrace("Extracted " . count($imagequeue) . " images to process.");
        
        // 2. Process image queue with AI
        foreach ($imagequeue as $index => $imagepath) {
            mtrace("Processing image " . ($index + 1) . "/" . count($imagequeue) . "...");
            
            $evalid = $DB->insert_record('paper_evaluations', [
                'paperid' => $paper->id,
                'timecreated' => time(),
                'filename' => basename($imagepath),
                'userid' => null,
                'studentnametext' => null,
                'totalgrade' => null
            ]);
            
            $ocr_texts_to_correct = [];
            
            foreach ($areas as $area) {
                try {
                    $cropped_base64 = $pdfprocessor->crop_image_to_base64($imagepath, $area);
                    $ocrtext = $aimanager->extract_text($cropped_base64, $area);
                    
                    if (($area->isnamefield == 1 || $area->isnamefield == 2) && !empty($ocrtext)) {
                        $DB->set_field('paper_evaluations', 'studentnametext', $ocrtext, ['id' => $evalid]);
                        if ($area->isnamefield == 2) {
                            $user = $DB->get_record('user', ['username' => trim($ocrtext), 'deleted' => 0]);
                            if ($user) {
                                $DB->set_field('paper_evaluations', 'userid', $user->id, ['id' => $evalid]);
                            }
                        }
                    }
                    
                    $item = new \stdClass();
                    $item->evalid = $evalid;
                    $item->responseareaid = $area->id;
                    $item->ocrtext = $ocrtext;
                    $item->correctedtext = ''; 
                    $item->feedback = ''; 
                    $item->itemgrade = null; 
                    
                    $itemid = $DB->insert_record('paper_eval_items', $item);
                    
                    // If it's a Display Only field, save the cropped image snippet
                    if ($area->isnamefield == 3) {
                        $snippetdata = $pdfprocessor->trim_whitespace_border(base64_decode($cropped_base64));
                        $filerecord = [
                            'contextid' => $context->id,
                            'component' => 'mod_paper',
                            'filearea' => 'responsesnippet',
                            'itemid' => $itemid,
                            'filepath' => '/',
                            'filename' => 'snippet.jpg'
                        ];
                        $fs->create_file_from_string($filerecord, $snippetdata);
                    }
                    
                    if (!$area->isnamefield && !empty(trim($ocrtext))) {
                        $ocr_texts_to_correct[$itemid] = trim($ocrtext);
                    }
                    
                } catch (\Exception $e) {
                    mtrace("Error processing area {$area->responsenumber} on " . basename($imagepath) . ": " . $e->getMessage());
                }
            }
        }
            
        // 3. Cleanup files from DB to save space
        mtrace("Cleaning up original files from Moodle File API...");
        $fs->delete_area_files($context->id, 'mod_paper', 'submissions', $batchid);
        
        mtrace("OCR complete for batch {$batchid}!");
        
        // 4. Queue the evaluation task
        $evaltask = new \mod_paper\task\evaluate_submissions_task();
        $evaltask->set_custom_data([
            'paperid' => $paperid,
            'batchid' => $batchid
        ]);
        \core\task\manager::queue_adhoc_task($evaltask);
        mtrace("Queued evaluate_submissions_task for Paper ID {$paperid}");
    }
}
