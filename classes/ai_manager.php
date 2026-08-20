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
 * AI Manager
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_paper;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/openai_handler.php');

class ai_manager {
    /**
     * @var ai_handler Base handler
     */
    protected $handler;

    public function __construct() {
        // Future proofing for multiple AI handlers.
        $this->handler = new openai_handler();
    }

    /**
     * Identify response areas in an image base64
     * We expect an array of bounding boxes: [{x, y, w, h}]
     */
    public function identify_response_areas($imagebase64) {
        return $this->handler->identify_response_areas($imagebase64);
    }

    /**
     * Extract handwritten text from a specific bounded area of an image
     */
    public function extract_text($imagebase64, $bbox) {
        return $this->handler->extract_text($imagebase64, $bbox);
    }

    /**
     * Extract handwritten text from many cropped areas at once, running the requests
     * concurrently rather than one after another.
     *
     * @param array $crops [key => base64 string, or callable returning one].
     * @param int|null $concurrency Max requests in flight; defaults to the admin setting.
     * @return array [key => ['text' => string|null, 'error' => string|null]].
     */
    public function extract_text_multi(array $crops, $concurrency = null) {
        return $this->handler->extract_text_multi($crops, $concurrency);
    }

    /**
     * Evaluate a student's answer vs the required criteria
     */
    public function evaluate_response($studenttext, $criteria, $targetlang, $feedbacklang) {
        return $this->handler->evaluate_response($studenttext, $criteria, $targetlang, $feedbacklang);
    }

    /**
     * Batch process evaluations for a specific response area
     */
    public function batch_process_evaluations($area, $items, $feedbacklanguage = 'English') {
        return $this->handler->batch_process_evaluations($area, $items, $feedbacklanguage);
    }

    /**
     * Batch process evaluations for several response areas concurrently
     *
     * @param array $areas [areaid => paper_response_areas record].
     * @param array $itemsbyarea [areaid => [itemid => ocrtext]].
     * @param string $feedbacklanguage
     * @param int|null $concurrency Max requests in flight; defaults to the admin setting.
     * @return array [areaid => ['results' => array, 'error' => string|null]].
     */
    public function batch_process_evaluations_multi(array $areas, array $itemsbyarea,
            $feedbacklanguage = 'English', $concurrency = null) {
        return $this->handler->batch_process_evaluations_multi($areas, $itemsbyarea, $feedbacklanguage, $concurrency);
    }

    /**
     * Batch correct grammar for multiple texts
     */
    public function batch_correct_grammar($texts) {
        return $this->handler->batch_correct_grammar($texts);
    }
}
