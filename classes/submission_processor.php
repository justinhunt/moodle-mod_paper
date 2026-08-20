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
     * OCR is the slowest thing this plugin does - a batch costs (pages x response areas)
     * separate Vision requests, and each one has to carry its own image crop so they can't
     * be collapsed into a single prompt the way stage-2 grading is. So rather than walking
     * the pages one at a time, this runs in three phases: crop everything locally, fire all
     * the OCR requests concurrently, then write the results away in order. On a class set
     * that turns hundreds of serial round trips into a handful of concurrent rounds.
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

        // Phase 1: crop every response area out of every page, on disk.
        $jobs = $this->build_crop_jobs($imagequeue, $tempdir);
        mtrace("Cropped " . count($jobs) . " response areas from " . count($imagequeue) . " pages.");

        // Phase 2: OCR them all concurrently.
        $ocrresults = $this->run_ocr_jobs($jobs);

        // Phase 3: write the evaluations and items away, in page/response order.
        $this->save_ocr_jobs($jobs, $ocrresults);

        mtrace("Cleaning up original files from Moodle File API...");
        $this->cleanup_batch_files($batchid);
        mtrace("OCR complete for batch {$batchid}!");

        $this->queue_evaluation_task($batchid);
    }

    /**
     * Phase 1. Crops every response area out of every page, writing each crop to a temp
     * file rather than holding it in memory - a full class set is easily hundreds of crops,
     * and they only need to exist as strings while their request is on the wire.
     *
     * Each page is decoded once and all of its areas cropped from that one handle.
     *
     * No database writes happen here: the paper_evaluations row is created in phase 3 as
     * results land, so the progress counter on the upload page (which counts those rows)
     * keeps climbing instead of jumping to full and sitting there for the whole run.
     *
     * @param array $imagequeue Page image paths, in order.
     * @param string $tempdir Writable temp directory for the crops.
     * @return array Job descriptors, in page order then responsenumber order.
     */
    protected function build_crop_jobs(array $imagequeue, $tempdir) {
        $jobs = [];

        foreach ($imagequeue as $pageindex => $imagepath) {
            try {
                list($src, $ext) = $this->pdfprocessor->load_image($imagepath);
            } catch (\Exception $e) {
                mtrace("Skipping page " . basename($imagepath) . ": " . $e->getMessage());
                continue;
            }

            try {
                foreach ($this->areas as $area) {
                    try {
                        $croppath = $tempdir . '/crop_' . $pageindex . '_' . $area->id . '.' . $ext;
                        file_put_contents($croppath, base64_decode($this->pdfprocessor->crop_loaded_image($src, $area, $ext)));

                        $jobs[] = (object) [
                            'pageindex' => $pageindex,
                            'imagepath' => $imagepath,
                            'area' => $area,
                            'croppath' => $croppath,
                        ];
                    } catch (\Exception $e) {
                        mtrace("Error cropping area {$area->responsenumber} on " . basename($imagepath) . ": "
                            . $e->getMessage());
                    }
                }
            } finally {
                imagedestroy($src);
            }
        }

        return $jobs;
    }

    /**
     * Phase 2. Runs the OCR requests for every job concurrently.
     *
     * Display-only areas are skipped entirely - they show the crop itself rather than any
     * transcribed text, and nothing downstream reads their ocrtext.
     *
     * @param array $jobs Job descriptors from build_crop_jobs().
     * @return array [job index => ['text' => string|null, 'error' => string|null]].
     */
    protected function run_ocr_jobs(array $jobs) {
        $crops = [];
        foreach ($jobs as $i => $job) {
            if ($job->area->isnamefield == 3) {
                continue;
            }
            // Deferred so the crop is only read off disk when its request is actually sent.
            $crops[$i] = function() use ($job) {
                return base64_encode(file_get_contents($job->croppath));
            };
        }

        if (empty($crops)) {
            return [];
        }

        mtrace("Running OCR on " . count($crops) . " response areas...");

        return $this->aimanager->extract_text_multi($crops);
    }

    /**
     * Phase 3. Writes the OCR results away: one paper_evaluations row per page, and one
     * paper_eval_items row per response area.
     *
     * Runs in job order (page, then responsenumber ASC) because apply_name_field() writes
     * to the shared paper_evaluations row - with more than one name field on a worksheet
     * the highest responsenumber wins, and that's only deterministic if the writes are
     * ordered even though the OCR that fed them was not.
     *
     * @param array $jobs Job descriptors from build_crop_jobs().
     * @param array $ocrresults Results from run_ocr_jobs(), keyed by job index.
     * @return array [pageindex => evaluation id].
     */
    protected function save_ocr_jobs(array $jobs, array $ocrresults) {
        global $DB;

        $evalids = [];

        foreach ($jobs as $i => $job) {
            if (!isset($evalids[$job->pageindex])) {
                $evalids[$job->pageindex] = $DB->insert_record('paper_evaluations', [
                    'paperid' => $this->paper->id,
                    'timecreated' => time(),
                    'filename' => basename($job->imagepath),
                    'userid' => null,
                    'studentnametext' => null,
                    'totalgrade' => null,
                ]);
            }
            $evalid = $evalids[$job->pageindex];

            // Display-only areas are never sent for OCR, so having no result is expected
            // for them and only for them - anywhere else a missing result is a failure.
            if ($job->area->isnamefield == 3) {
                $result = ['text' => '', 'error' => null];
            } else {
                $result = $ocrresults[$i] ?? ['text' => null, 'error' => 'no response'];
            }

            if (!empty($result['error'])) {
                // Record the item anyway. Dropping the row would hide the response area
                // from the review page entirely and quietly shrink the total grade, which
                // is far worse than an empty answer a teacher can see and fill in.
                mtrace("OCR failed for area {$job->area->responsenumber} on " . basename($job->imagepath)
                    . ": " . $result['error']);
            }

            try {
                $this->save_area_result($job, $evalid, $result['text'] ?? '');
            } catch (\Exception $e) {
                mtrace("Error saving area {$job->area->responsenumber} on " . basename($job->imagepath) . ": "
                    . $e->getMessage());
            }
        }

        return $evalids;
    }

    /**
     * Saves one response area's OCR result: the paper_eval_items row, the name-field
     * write-back, and the crop image for display-only (and optionally debug) areas.
     *
     * @param object $job A job descriptor from build_crop_jobs().
     * @param int $evalid The evaluation this item belongs to.
     * @param string $ocrtext The transcribed text ('' for display-only areas and failures).
     * @return int The new item id.
     */
    protected function save_area_result($job, $evalid, $ocrtext) {
        global $DB;

        $area = $job->area;

        if (($area->isnamefield == 1 || $area->isnamefield == 2) && !empty($ocrtext)) {
            $this->apply_name_field($area, $ocrtext, $evalid);
        }

        $item = new \stdClass();
        $item->evalid = $evalid;
        $item->responseareaid = $area->id;
        $item->ocrtext = $ocrtext;
        // NULL, not '', marks this as awaiting stage-2 grading - see evaluation_processor.
        $item->correctedtext = null;
        $item->feedback = null;
        $item->itemgrade = null;

        $itemid = $DB->insert_record('paper_eval_items', $item);

        if ($area->isnamefield == 3) {
            $this->save_response_snippet(base64_encode(file_get_contents($job->croppath)), $itemid);
        } else if (get_config('mod_paper', 'savedebugcrops')) {
            // Display-only areas already persist their crop above; for every other area
            // this is only saved when the admin has opted into the extra storage cost,
            // for inspection on the Developer page.
            $this->save_area_crop(base64_encode(file_get_contents($job->croppath)), $itemid);
        }

        return $itemid;
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
     * one paper_evaluations row and one paper_eval_items row per area. The page's areas
     * are OCR'd concurrently, as in a full batch.
     *
     * @param string $imagepath Path to a single page image.
     * @return int|null The new evaluation id, or null if the page yielded no areas.
     */
    public function process_image($imagepath) {
        $tempdir = make_request_directory();

        $jobs = $this->build_crop_jobs([$imagepath], $tempdir);
        if (empty($jobs)) {
            return null;
        }

        $evalids = $this->save_ocr_jobs($jobs, $this->run_ocr_jobs($jobs));

        return reset($evalids) ?: null;
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
        $tempdir = make_request_directory();
        $croppath = $tempdir . '/crop_single_' . $area->id;

        $croppedbase64 = $this->pdfprocessor->crop_image_to_base64($imagepath, $area);
        file_put_contents($croppath, base64_decode($croppedbase64));

        if ($area->isnamefield == 3) {
            // Display-only areas show the response snippet image, not OCR'd text, and
            // nothing downstream reads ocrtext for them - skip the AI OCR call.
            $ocrtext = '';
        } else {
            $ocrtext = $this->aimanager->extract_text($croppedbase64, $area);
        }

        $job = (object) [
            'pageindex' => 0,
            'imagepath' => $imagepath,
            'area' => $area,
            'croppath' => $croppath,
        ];

        return $this->save_area_result($job, $evalid, $ocrtext);
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
