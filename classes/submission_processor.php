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
 * Submission processing service for mod_paper
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_paper;

defined('MOODLE_INTERNAL') || die();

/**
 * Bursts uploaded submission PDFs/images into pages, crops each response area, runs OCR,
 * and saves the resulting evaluation records. Used by process_submissions_task, but plain
 * enough to call synchronously from anywhere else that needs to process a batch (or a
 * single image, or a single area) without going through the adhoc task queue.
 */
class submission_processor {

    /** @var object The paper instance record. */
    protected $paper;

    /** @var object The course_modules record for this paper. */
    protected $cm;

    /** @var \context_module The module context. */
    protected $context;

    /** @var array Response areas for this paper, keyed by id. */
    protected $areas;

    /** @var ai_manager */
    protected $aimanager;

    /** @var pdf_processor */
    protected $pdfprocessor;

    /**
     * @param object $paper The paper instance record.
     */
    public function __construct($paper) {
        global $DB;

        $this->paper = $paper;
        $this->cm = get_coursemodule_from_instance('paper', $paper->id, 0, false, MUST_EXIST);
        $this->context = \context_module::instance($this->cm->id);
        $this->areas = $DB->get_records('paper_response_areas', ['paperid' => $paper->id], 'responsenumber ASC');
        $this->aimanager = new \mod_paper\ai_manager();
        $this->pdfprocessor = new \mod_paper\pdf_processor();
    }

    /**
     * Processes every file in a submissions batch: unrolls PDFs into page images, runs
     * each page through every response area, cleans up the original upload, and queues
     * stage-2 grading.
     *
     * @param int $batchid The submissions filearea itemid for this upload batch.
     */
    public function process_batch($batchid) {
        $fs = get_file_storage();
        $files = $fs->get_area_files($this->context->id, 'mod_paper', 'submissions', $batchid, 'filename', false);

        if (empty($files)) {
            mtrace("No files found for batch {$batchid}");
            return;
        }

        mtrace("Starting processing for Paper ID {$this->paper->id}, Batch ID {$batchid}");

        $tempdir = make_request_directory();
        $imagequeue = $this->extract_images_from_files($files, $tempdir);
        mtrace("Extracted " . count($imagequeue) . " images to process.");

        foreach ($imagequeue as $index => $imagepath) {
            mtrace("Processing image " . ($index + 1) . "/" . count($imagequeue) . "...");
            $this->process_image($imagepath);
        }

        mtrace("Cleaning up original files from Moodle File API...");
        $this->cleanup_batch_files($batchid);
        mtrace("OCR complete for batch {$batchid}!");

        $this->queue_evaluation_task($batchid);
    }

    /**
     * Copies each uploaded file to a temp path and unrolls PDFs into page images via
     * Ghostscript, passing already-image files through unchanged.
     *
     * @param array $files Stored files from the submissions filearea.
     * @param string $tempdir Writable temp directory to extract into.
     * @return array Flat list of image file paths, one per page, in upload order.
     */
    public function extract_images_from_files(array $files, $tempdir) {
        $imagequeue = [];

        foreach ($files as $file) {
            $filename = $file->get_filename();
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $tmpname = $tempdir . '/' . $file->get_contenthash() . '_' . $filename;

            $file->copy_content_to($tmpname);

            if ($ext === 'pdf') {
                mtrace("Unrolling PDF: {$filename}");
                try {
                    $unrolledjpgs = $this->pdfprocessor->pdf_to_images($tmpname, $tempdir);
                    foreach ($unrolledjpgs as $jpg) {
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

        return $imagequeue;
    }

    /**
     * Processes a single page image against every response area on this paper, creating
     * one paper_evaluations row and one paper_eval_items row per area.
     *
     * @param string $imagepath Path to a single page image.
     * @return int The new evaluation id.
     */
    public function process_image($imagepath) {
        global $DB;

        $evalid = $DB->insert_record('paper_evaluations', [
            'paperid' => $this->paper->id,
            'timecreated' => time(),
            'filename' => basename($imagepath),
            'userid' => null,
            'studentnametext' => null,
            'totalgrade' => null,
        ]);

        foreach ($this->areas as $area) {
            try {
                $this->process_area($imagepath, $area, $evalid);
            } catch (\Exception $e) {
                mtrace("Error processing area {$area->responsenumber} on " . basename($imagepath) . ": " . $e->getMessage());
            }
        }

        return $evalid;
    }

    /**
     * Crops one response area out of a page image, runs OCR on it (skipped for
     * display-only areas, which use the crop itself rather than OCR'd text), and saves
     * the resulting paper_eval_items row (plus a response snippet image for display-only
     * areas).
     *
     * @param string $imagepath Path to the page image.
     * @param object $area A paper_response_areas record.
     * @param int $evalid The evaluation this item belongs to.
     * @return int The new item id.
     */
    public function process_area($imagepath, $area, $evalid) {
        global $DB;

        $croppedbase64 = $this->pdfprocessor->crop_image_to_base64($imagepath, $area);
        if ($area->isnamefield == 3) {
            // Display-only areas show the response snippet image, not OCR'd text, and
            // nothing downstream reads ocrtext for them - skip the AI OCR call.
            $ocrtext = '';
        } else {
            $ocrtext = $this->aimanager->extract_text($croppedbase64, $area);
        }

        if (($area->isnamefield == 1 || $area->isnamefield == 2) && !empty($ocrtext)) {
            $this->apply_name_field($area, $ocrtext, $evalid);
        }

        $item = new \stdClass();
        $item->evalid = $evalid;
        $item->responseareaid = $area->id;
        $item->ocrtext = $ocrtext;
        $item->correctedtext = '';
        $item->feedback = '';
        $item->itemgrade = null;

        $itemid = $DB->insert_record('paper_eval_items', $item);

        if ($area->isnamefield == 3) {
            $this->save_response_snippet($croppedbase64, $itemid);
        } else if (get_config('mod_paper', 'savedebugcrops')) {
            // Display-only areas already persist their crop above; for every other area
            // this is only saved when the admin has opted into the extra storage cost,
            // for inspection on the Developer page.
            $this->save_area_crop($croppedbase64, $itemid);
        }

        return $itemid;
    }

    /**
     * Saves the response-area crop as a display-only item's snippet image, verbatim
     * (no ink-trimming/re-anchoring - the crop already lines up with the box it came
     * from, so it's drawn back at exactly that position/size).
     *
     * @param string $croppedbase64 Base64-encoded JPEG/PNG crop, as returned by
     *                                pdf_processor::crop_image_to_base64().
     * @param int $itemid The paper_eval_items row this snippet belongs to.
     */
    public function save_response_snippet($croppedbase64, $itemid) {
        global $DB;

        $fs = get_file_storage();
        $filerecord = [
            'contextid' => $this->context->id,
            'component' => 'mod_paper',
            'filearea' => 'responsesnippet',
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => 'snippet.jpg',
        ];
        $fs->create_file_from_string($filerecord, base64_decode($croppedbase64));

        $DB->update_record('paper_eval_items', (object)[
            'id' => $itemid,
            'snippetx' => 0,
            'snippety' => 0,
            'snippetw' => 100,
            'snippeth' => 100,
        ]);
    }

    /**
     * Saves a response-area crop for inspection on the Developer page. Gated behind the
     * mod_paper/savedebugcrops setting (off by default) since it adds one extra stored
     * image per response area per submission, purely for debugging.
     *
     * @param string $croppedbase64 Base64-encoded JPEG/PNG crop, as returned by
     *                                pdf_processor::crop_image_to_base64().
     * @param int $itemid The paper_eval_items row this crop belongs to.
     */
    public function save_area_crop($croppedbase64, $itemid) {
        $fs = get_file_storage();
        $filerecord = [
            'contextid' => $this->context->id,
            'component' => 'mod_paper',
            'filearea' => 'areacrop',
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => 'crop.jpg',
        ];
        $fs->create_file_from_string($filerecord, base64_decode($croppedbase64));
    }

    /**
     * Deletes the original uploaded submission files for a batch once processing is done.
     *
     * @param int $batchid The submissions filearea itemid for this upload batch.
     */
    public function cleanup_batch_files($batchid) {
        $fs = get_file_storage();
        $fs->delete_area_files($this->context->id, 'mod_paper', 'submissions', $batchid);
    }

    /**
     * Queues stage-2 grading for a batch.
     *
     * @param int $batchid The submissions filearea itemid for this upload batch.
     */
    public function queue_evaluation_task($batchid) {
        $evaltask = new \mod_paper\task\evaluate_submissions_task();
        $evaltask->set_custom_data([
            'paperid' => $this->paper->id,
            'batchid' => $batchid,
        ]);
        \core\task\manager::queue_adhoc_task($evaltask);
        mtrace("Queued evaluate_submissions_task for Paper ID {$this->paper->id}");
    }

    /**
     * Records OCR'd name-field text against the in-progress evaluation, and matches it
     * to a Moodle user account when the area is a username field.
     *
     * @param object $area A paper_response_areas record with isnamefield 1 (name) or 2 (username).
     * @param string $ocrtext The OCR'd text for this area.
     * @param int $evalid The evaluation to update.
     */
    protected function apply_name_field($area, $ocrtext, $evalid) {
        global $DB;

        $DB->set_field('paper_evaluations', 'studentnametext', $ocrtext, ['id' => $evalid]);

        if ($area->isnamefield == 2) {
            $user = $DB->get_record('user', ['username' => trim($ocrtext), 'deleted' => 0]);
            if ($user) {
                $DB->set_field('paper_evaluations', 'userid', $user->id, ['id' => $evalid]);
            }
        }
    }
}
