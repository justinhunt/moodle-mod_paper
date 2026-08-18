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
 * OpenAI Handler
 *
 * @package    mod_paper
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_paper;

defined('MOODLE_INTERNAL') || die();

class openai_handler {

    protected $apikey;

    public function __construct() {
        $this->apikey = get_config('mod_paper', 'openaicredentials');
    }

    protected function call_openai($messages, $maxtokens = 1000) {
        if (empty($this->apikey)) {
            throw new \moodle_exception('openaicredentialsnotset', 'mod_paper');
        }

        $url = 'https://api.openai.com/v1/chat/completions';

        $data = [
            'model' => 'gpt-4o',
            'messages' => $messages,
            'max_tokens' => $maxtokens,
            'temperature' => 0.2,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apikey,
        ]);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \moodle_exception('A curl error occurred: ' . $error);
        }
        curl_close($ch);

        $response = json_decode($result);
        if (isset($response->error)) {
            throw new \moodle_exception('OpenAI Error: ' . $response->error->message);
        }

        return $response->choices[0]->message->content;
    }

    public function identify_response_areas($imagebase64) {
        $prompt = "Analyze this image. It is a worksheet table. Your task is to identify the empty response areas (table cells with clear borders containing no text) where students are supposed to write their answers. Do not include rows or cells that contain text.
        For each empty response area you find, provide its bounding box as a JSON array of objects with keys 'ymin', 'xmin', 'ymax', 'xmax' representing normalized coordinates from 0 to 1000.
        Return ONLY valid JSON.
        Example: [{\"ymin\": 200, \"xmin\": 100, \"ymax\": 350, \"xmax\": 900}]";

        $messages = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => "data:image/jpeg;base64,{$imagebase64}"]],
                ],
            ],
        ];

        $jsonstr = $this->call_openai($messages);

        // clean any markdown from response
        $jsonstr = preg_replace('/```json\s*/', '', $jsonstr);
        $jsonstr = preg_replace('/```\s*/', '', $jsonstr);

        return json_decode($jsonstr);
    }

    public function extract_text($imagebase64, $bbox) {
        $prompt = "Extract the handwritten or typed text from the image provided. Only output the text you see, nothing else. If it's empty, output NOTHING.";

        // Note: For a real implementation, we either need to crop the image *before* sending to save tokens,
        // or ask the AI to only look at the specific bounding box coordinates. Since GPT-4V doesn't
        // accept coordinate-based cropping natively, cropping in PHP (using GD/Imagick) before calling this
        // is the standard approach. For now we assume $imagebase64 is the ALREADY CROPPED image.

        $messages = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => "data:image/jpeg;base64,{$imagebase64}"]],
                ],
            ],
        ];

        $text = trim($this->call_openai($messages));

        if (strcasecmp($text, 'NOTHING') === 0) {
            return '';
        }

        return $text;
    }

    public function evaluate_response($studenttext, $criteria, $targetlang, $feedbacklang) {
        // Construct detailed prompt based on criteria
        $prompt = "You are evaluating a student's answer.\n";
        $prompt .= "Target language: {$targetlang}\n";
        $prompt .= "Feedback language: {$feedbacklang}\n";
        $prompt .= "Question asked: {$criteria->question}\n";
        $prompt .= "Student's answer: {$studenttext}\n";

        if ($criteria->correctanswermode !== 'none' && !empty($criteria->correctanswer)) {
            $prompt .= "Correct answer: {$criteria->correctanswer}. Mode: {$criteria->correctanswermode}.\n";
        }

        $prompt .= "Instructions: Provide the evaluation in JSON format containing the following keys:\n";
        $prompt .= "- 'correctedtext': The grammatically corrected text. Strikethrough incorrect text like ~~this~~ and bold corrected text like **this**. (If grammar corrections are 'no', return empty).\n";
        $prompt .= "- 'feedback': Overall feedback in the feedback language. Explain why it is wrong and what is right.\n";
        $prompt .= "- 'score': A number from 0 to {$criteria->maxgrade} based on these instructions: {$criteria->gradeinstructions}\n";

        $messages = [
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ];

        $jsonstr = $this->call_openai($messages);

        $jsonstr = preg_replace('/```json\s*/', '', $jsonstr);
        $jsonstr = preg_replace('/```\s*/', '', $jsonstr);

        return json_decode($jsonstr);
    }
    public function batch_process_evaluations($area, $items, $feedbacklanguage = 'English') {
        if (empty($items)) {
            return [];
        }

        $prompt = "You are an expert teacher grading student responses for a specific response area on a worksheet.\n";
        $prompt .= "### Area Configuration\n";
        $prompt .= "- Question/Topic: " . ($area->question ?: 'Not provided') . "\n";
        $prompt .= "- Correct Answer Mode: " . $area->correctanswermode . "\n";
        if ($area->correctanswermode !== 'none') {
            $prompt .= "- Expected Correct Answer: " . ($area->correctanswer ?: 'Not provided') . "\n";
        }
        $prompt .= "- Max Grade: " . $area->maxgrade . "\n";
        $prompt .= "- Grading Mode: " . ($area->gradingmode ?? 'none') . "\n";
        if (($area->gradingmode ?? 'none') === 'overall') {
            $prompt .= "- Grading Instructions: " . ($area->gradeinstructions ?: 'No specific instructions provided.') . "\n";
        }
        $prompt .= "- Grammar Corrections: " . $area->grammarcorrections . "\n";
        $prompt .= "- Feedback Mode: " . ($area->feedbackmode ?? 'none') . "\n";
        if (($area->feedbackmode ?? 'none') === 'custom') {
            $prompt .= "- Feedback Instructions: " . ($area->feedbackinstructions ?: 'No specific instructions provided.') . "\n";
        }
        $prompt .= "- Feedback Language: " . $feedbacklanguage . "\n\n";

        $prompt .= "### Evaluation Logic\n";
        $prompt .= "1. Correctness Status: Determine if the answer is 'correct', 'partially correct', or 'incorrect'.\n";

        $prompt .= "2. Grading: Calculate a numerical grade (0 to " . $area->maxgrade . ") based on the status and 'Grading Mode':\n";
        $prompt .= "   - 'none': Do not calculate a grade (return 0).\n";
        $prompt .= "   - 'incorrect': Deduct point for each grammar/spelling mistake. Starting from " . $area->maxgrade . ".\n";
        $prompt .= "   - 'overall': Use the 'Grading Instructions' provided above.\n\n";

        $prompt .= "3. Grammar: Provide the 'correctedtext' field as follows:\n";
        $prompt .= "   - If 'Grammar Corrections' is 'no': return the student's original text verbatim. Do not alter it.\n";
        $prompt .= "   - If 'Grammar Corrections' is 'major': correct only significant grammar and spelling errors.\n";
        $prompt .= "     IGNORE trivial errors such as: wrong articles (a/an/the), minor preposition choices, and sentences\n";
        $prompt .= "     that are grammatically correct but sound slightly unnatural. Focus only on errors that clearly\n";
        $prompt .= "     impede meaning or demonstrate a significant grammatical mistake.\n";
        $prompt .= "   - If 'Grammar Corrections' is 'all': correct every grammar and spelling error, including articles,\n";
        $prompt .= "     prepositions, unnatural phrasing, and any other deviation from standard correct usage.\n";
        $prompt .= "   In all cases: plain text only (no markdown), and 'correctedtext' must NEVER be empty —\n";
        $prompt .= "   always return either the corrected text or the original text verbatim.\n\n";

        $prompt .= "4. Feedback: Provide feedback based on the 'Feedback Mode':\n";
        $prompt .= "   - 'none': DO NOT provide any feedback. Return an empty string.\n";
        $prompt .= "   - 'grammatical': Explain grammatical and spelling errors found in the student response.\n";
        $prompt .= "   - 'custom': Use the 'Feedback Instructions' provided above.\n";
        $prompt .= "   This MUST be written in " . $feedbacklanguage . ".\n\n";

        if ($area->correctanswermode === 'relevant') {
            $prompt .= "   - Mode 'relevant': Check if the response is relevant to the question. 'relevant' -> correct, 'not relevant' -> incorrect.\n";
        } else if ($area->correctanswermode === 'manual') {
            $prompt .= "   - Mode 'manual': The response should match the 'Expected Correct Answer' closely. Minor spelling/grammar errors are acceptable for 'partially correct'.\n";
        } else if ($area->correctanswermode === 'samemeaning') {
            $prompt .= "   - Mode 'samemeaning': The response must have the same semantic meaning as the 'Expected Correct Answer', even if phrased differently.\n";
        } else {
            $prompt .= "   - No specific correctness criteria provided. Use your best judgment based on the question and instructions.\n";
        }

        $prompt .= "### Input Responses (JSON format: {id: ocrtext})\n";
        $prompt .= json_encode($items) . "\n\n";

        $prompt .= "### Output Format\n";
        $prompt .= "Return ONLY a valid JSON object where keys are the item IDs and values are objects containing:\n";
        $prompt .= "- 'correctedtext': string — ALWAYS required. Return the grammatically corrected student text if grammar corrections are enabled, OR return the student's original text verbatim if grammar corrections are 'no'. Never return an empty string.\n";
        $prompt .= "- 'status': 'correct' | 'partially correct' | 'incorrect'\n";
        $prompt .= "- 'grade': number (0 to " . $area->maxgrade . ")\n";
        $prompt .= "- 'feedback': string (in " . $feedbacklanguage . ")\n";

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a precise grading assistant. Return only valid JSON.',
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ];

        // Max tokens needs to be higher for batch processing.
        $maxtokens = 4000;
        $jsonstr = $this->call_openai($messages, $maxtokens);

        $jsonstr = preg_replace('/```json\s*/', '', $jsonstr);
        $jsonstr = preg_replace('/```\s*/', '', $jsonstr);

        return json_decode($jsonstr, true) ?: [];
    }

    public function batch_correct_grammar($texts) {
        if (empty($texts)) {
            return [];
        }

        $prompt = "You are an English teacher correcting grammar. You are given a JSON object where keys are IDs and values are student responses.\n";
        $prompt .= "Return a JSON object with the exact same keys, where the values are the grammatically corrected responses. Do not use strikethroughs or bold in this response, just the corrected plain text.\n";
        $prompt .= "If a response is already perfectly correct, return it exactly as is.\n";
        $prompt .= "If a response is empty, return an empty string.\n";
        $prompt .= "Input:\n" . json_encode($texts);

        $messages = [
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ];

        // Max tokens needs to be higher for batch processing.
        $maxtokens = 4000;
        $jsonstr = $this->call_openai($messages, $maxtokens);

        $jsonstr = preg_replace('/```json\s*/', '', $jsonstr);
        $jsonstr = preg_replace('/```\s*/', '', $jsonstr);

        return json_decode($jsonstr, true) ?: [];
    }
}
