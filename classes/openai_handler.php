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
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_paper;

defined('MOODLE_INTERNAL') || die();

class openai_handler {

    protected $apikey;
    protected $model;
    protected $ocrmodel;
    protected $reasoningeffort;
    protected $ocrreasoningeffort;
    protected $maxtokens;
    protected $maxtokensbatch;
    protected $concurrency;
    protected $timeout;

    /** @var string The Responses API endpoint every call in this class targets. */
    const API_URL = 'https://api.openai.com/v1/responses';

    /** @var int Connect timeout, in seconds. Not admin-configurable - a TCP handshake to
     * api.openai.com either happens quickly or is not going to happen at all. */
    const CONNECT_TIMEOUT = 10;

    /** @var array Backoff, in seconds, before each retry round in call_openai_multi(). The
     * number of entries is also the number of retry rounds. */
    const RETRY_BACKOFF = [2, 5];

    public function __construct() {
        $this->apikey = get_config('mod_paper', 'openaicredentials');
        $this->model = get_config('mod_paper', 'openaimodel') ?: 'gpt-5.6-luna';
        $this->ocrmodel = get_config('mod_paper', 'openaiocrmodel') ?: 'gpt-4o';
        $this->reasoningeffort = get_config('mod_paper', 'openaireasoningeffort') ?: 'low';
        $this->ocrreasoningeffort = get_config('mod_paper', 'openaiocrreasoningeffort') ?: 'none';
        $this->maxtokens = (int) (get_config('mod_paper', 'openaimaxtokens') ?: 2000);
        $this->maxtokensbatch = (int) (get_config('mod_paper', 'openaimaxtokensbatch') ?: 8000);
        $this->concurrency = (int) (get_config('mod_paper', 'openaiconcurrency') ?: 16);
        $this->timeout = (int) (get_config('mod_paper', 'openaitimeout') ?: 120);
    }

    /**
     * Reasoning models (gpt-5.x, o-series) take a nested reasoning.effort object instead
     * of a flat temperature, unlike gpt-4o and other classic models. OCR and grading can
     * use different models, so this takes the model to check rather than assuming $this->model.
     */
    protected function is_reasoning_model($model) {
        return (bool) preg_match('/^(o[0-9]|gpt-5)/', $model);
    }

    /**
     * Translate a chat-completions-style content value (string, or array of
     * {type: text|image_url, ...} parts) into Responses API input_text/input_image parts.
     */
    protected function convert_content($content) {
        if (is_string($content)) {
            return $content;
        }

        $parts = [];
        foreach ($content as $part) {
            if ($part['type'] === 'text') {
                $parts[] = ['type' => 'input_text', 'text' => $part['text']];
            } else if ($part['type'] === 'image_url') {
                $parts[] = ['type' => 'input_image', 'image_url' => $part['image_url']['url']];
            }
        }
        return $parts;
    }

    /**
     * Pull the assistant's text out of a Responses API result, falling back to walking
     * the output array if the output_text convenience field isn't present.
     */
    protected function extract_output_text($response) {
        if (isset($response->output_text)) {
            return $response->output_text;
        }

        if (!empty($response->output)) {
            foreach ($response->output as $item) {
                if (($item->type ?? null) === 'message' && !empty($item->content)) {
                    foreach ($item->content as $part) {
                        if (($part->type ?? null) === 'output_text') {
                            return $part->text;
                        }
                    }
                }
            }
        }

        return '';
    }

    /**
     * Translates $messages - which uses the familiar chat-completions shape (role/content,
     * with an optional leading system message) - into a Responses API (v1/responses)
     * request body: system -> top-level instructions, max_output_tokens, reasoning.effort.
     *
     * @return array The request body, ready to json_encode.
     */
    protected function build_request_payload($messages, $maxtokens = null, $model = null, $reasoningeffort = null) {
        $maxtokens = $maxtokens ?? $this->maxtokens;
        $model = $model ?? $this->model;

        $instructions = null;
        $input = [];
        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $instructions = is_string($message['content']) ? $message['content'] : json_encode($message['content']);
                continue;
            }
            $input[] = [
                'role' => $message['role'],
                'content' => $this->convert_content($message['content']),
            ];
        }

        $data = [
            'model' => $model,
            'input' => $input,
            'max_output_tokens' => $maxtokens,
        ];

        if ($instructions !== null) {
            $data['instructions'] = $instructions;
        }

        if ($this->is_reasoning_model($model)) {
            $data['reasoning'] = ['effort' => $reasoningeffort ?? $this->reasoningeffort];
        } else {
            $data['temperature'] = 0.2;
        }

        return $data;
    }

    /**
     * Builds a ready-to-run curl handle for one request body. Shared by the single-request
     * and parallel paths so they can't drift apart on headers or timeouts.
     *
     * @param array $payload A request body from build_request_payload().
     * @return \CurlHandle
     */
    protected function make_curl_handle(array $payload) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::API_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apikey,
        ]);
        // Without these a hung request blocks the whole adhoc task indefinitely.
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        return $ch;
    }

    /**
     * Turns a raw response body into the assistant's text.
     *
     * @param string $body The raw HTTP response body.
     * @return string
     * @throws \moodle_exception If the API reported an error.
     */
    protected function parse_response($body) {
        $response = json_decode($body);

        if (isset($response->error)) {
            throw new \moodle_exception('OpenAI Error: ' . $response->error->message);
        }

        // An undecodable body has to be an error, not an empty answer. A proxy returning an
        // HTML error page with a 200 would otherwise silently read as "this response area
        // was blank" - indistinguishable from a student who genuinely left it empty.
        if (!is_object($response)) {
            throw new \moodle_exception('OpenAI Error: unreadable response: ' . s(shorten_text(trim($body), 200)));
        }

        return $this->extract_output_text($response);
    }

    /**
     * Sends one request and returns the assistant's text.
     */
    protected function call_openai($messages, $maxtokens = null, $model = null, $reasoningeffort = null) {
        if (empty($this->apikey)) {
            throw new \moodle_exception('openaicredentialsnotset', 'mod_paper');
        }

        $payload = $this->build_request_payload($messages, $maxtokens, $model, $reasoningeffort);

        $ch = $this->make_curl_handle($payload);
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \moodle_exception('A curl error occurred: ' . $error);
        }
        curl_close($ch);

        return $this->parse_response($result);
    }

    /**
     * Sends many requests concurrently via curl_multi, keeping at most $concurrency in
     * flight at a time and topping the queue up as each one lands.
     *
     * Unlike a single call_openai(), a failure here is reported rather than thrown: with a
     * batch of hundreds of requests, one bad response should not discard the other results.
     * Callers get an explicit 'error' per key and decide what to do with it.
     *
     * Distinguishing transport failure from an empty answer is the whole point of checking
     * the HTTP status here. For OCR especially, a failed request and a genuinely blank
     * response box both produce empty text - conflating them would silently record "the
     * student wrote nothing" for every rate-limited request.
     *
     * @param array $payloads [key => request body from build_request_payload(), or a
     *                        callable returning one]. Callables are resolved at the moment
     *                        the request is actually sent, so a caller with large payloads
     *                        (image crops) can keep peak memory proportional to the number
     *                        in flight rather than to the size of the batch.
     * @param int|null $concurrency Max requests in flight; defaults to the admin setting.
     * @return array [key => ['text' => string|null, 'error' => string|null]], same keys as $payloads.
     */
    protected function call_openai_multi(array $payloads, $concurrency = null) {
        if (empty($this->apikey)) {
            throw new \moodle_exception('openaicredentialsnotset', 'mod_paper');
        }

        $results = [];
        if (empty($payloads)) {
            return $results;
        }

        $pendingkeys = array_keys($payloads);

        // Round 0 is the initial attempt; each subsequent round retries whatever failed in
        // a way that looks transient (connection error, rate limit, server error).
        foreach (array_merge([0], self::RETRY_BACKOFF) as $round => $backoff) {
            if (empty($pendingkeys)) {
                break;
            }

            if ($round > 0) {
                mtrace("Retrying " . count($pendingkeys) . " failed request(s) in {$backoff}s...");
                sleep($backoff);
            }

            $roundpayloads = [];
            foreach ($pendingkeys as $key) {
                $roundpayloads[$key] = $payloads[$key];
            }

            $pendingkeys = [];
            foreach ($this->run_multi_round($roundpayloads, $concurrency) as $key => $result) {
                $results[$key] = ['text' => $result['text'], 'error' => $result['error']];
                if ($result['retryable']) {
                    $pendingkeys[] = $key;
                }
            }
        }

        // Guarantee an entry per input key. If the multi handle ever bails out early a key
        // could otherwise come back missing, and a caller reading that as an empty string
        // would record a failed request as an empty answer.
        foreach (array_keys($payloads) as $key) {
            if (!isset($results[$key])) {
                $results[$key] = ['text' => null, 'error' => 'no response'];
            }
        }

        return $results;
    }

    /**
     * Runs one round of concurrent requests to completion.
     *
     * @param array $payloads [key => request body].
     * @param int|null $concurrency Max requests in flight.
     * @return array [key => ['text' => ?string, 'error' => ?string, 'retryable' => bool]]
     */
    protected function run_multi_round(array $payloads, $concurrency = null) {
        $concurrency = max(1, (int) ($concurrency ?? $this->concurrency));

        $results = [];
        $queue = array_keys($payloads);
        $handles = [];

        $mh = curl_multi_init();
        // Let the requests share HTTP/2 connections to api.openai.com rather than opening
        // one socket each.
        @curl_multi_setopt($mh, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);

        // Seed the first window of requests.
        while (count($handles) < $concurrency && !empty($queue)) {
            $this->add_multi_handle($mh, $handles, $payloads, $queue);
        }

        do {
            $status = curl_multi_exec($mh, $running);

            // Block until something actually happens instead of spinning a CPU core. A
            // negative return means there is nothing to wait on, so fall through.
            if ($running > 0 && curl_multi_select($mh, 1.0) === -1) {
                usleep(10000);
            }

            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $id = spl_object_id($ch);
                if (!isset($handles[$id])) {
                    continue;
                }
                $key = $handles[$id];
                unset($handles[$id]);

                $results[$key] = $this->interpret_multi_result($ch, $info, $key);

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                // Top the window back up as soon as a slot frees.
                if (!empty($queue)) {
                    $this->add_multi_handle($mh, $handles, $payloads, $queue);
                    $running++;
                }
            }
        } while ($running > 0 && $status === CURLM_OK);

        curl_multi_close($mh);

        return $results;
    }

    /**
     * Pops the next key off $queue, creates its handle, adds it to the multi handle and
     * records the handle -> key mapping.
     *
     * curl_init() returns a CurlHandle object on PHP 8, so handles are keyed by
     * spl_object_id() rather than by casting to int.
     *
     * @param \CurlMultiHandle $mh
     * @param array $handles [spl_object_id => key], modified in place.
     * @param array $payloads [key => request body].
     * @param array $queue Remaining keys, modified in place.
     */
    protected function add_multi_handle($mh, array &$handles, array $payloads, array &$queue) {
        $key = array_shift($queue);

        // Resolve lazily: the payload (and the base64 image inside it) exists only long
        // enough to be encoded onto the handle, then goes out of scope.
        $payload = $payloads[$key];
        $ch = $this->make_curl_handle(is_callable($payload) ? $payload() : $payload);

        $handles[spl_object_id($ch)] = $key;
        curl_multi_add_handle($mh, $ch);
    }

    /**
     * Classifies one finished request as success, retryable failure, or permanent failure.
     *
     * @param \CurlHandle $ch The finished handle.
     * @param array $info The curl_multi_info_read() entry for it.
     * @param string|int $key The caller's key, for the log line.
     * @return array ['text' => ?string, 'error' => ?string, 'retryable' => bool]
     */
    protected function interpret_multi_result($ch, array $info, $key) {
        if ($info['result'] !== CURLE_OK) {
            $error = 'curl error: ' . curl_strerror($info['result']);
            mtrace("Request {$key} failed ({$error})");
            return ['text' => null, 'error' => $error, 'retryable' => true];
        }

        $body = curl_multi_getcontent($ch);
        $httpcode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        // 429 (rate limited) and 5xx (server side) are worth another go; 4xx is not - a bad
        // key or a malformed request will fail identically every time.
        if ($httpcode === 429 || $httpcode >= 500) {
            $error = "HTTP {$httpcode}";
            mtrace("Request {$key} failed ({$error})");
            return ['text' => null, 'error' => $error, 'retryable' => true];
        }

        if ($httpcode !== 200) {
            $error = "HTTP {$httpcode}";
            $decoded = json_decode($body);
            if (isset($decoded->error->message)) {
                $error .= ': ' . $decoded->error->message;
            }
            mtrace("Request {$key} failed ({$error})");
            return ['text' => null, 'error' => $error, 'retryable' => false];
        }

        try {
            return ['text' => $this->parse_response($body), 'error' => null, 'retryable' => false];
        } catch (\Exception $e) {
            mtrace("Request {$key} returned an unusable response: " . $e->getMessage());
            return ['text' => null, 'error' => $e->getMessage(), 'retryable' => false];
        }
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

        // OCR/perception uses its own admin-configured model and reasoning effort,
        // independent of the grading model/effort - vision fidelity and grading quality
        // don't necessarily come from the same model.
        $jsonstr = $this->call_openai($messages, null, $this->ocrmodel, $this->ocrreasoningeffort);

        // clean any markdown from response
        $jsonstr = preg_replace('/```json\s*/', '', $jsonstr);
        $jsonstr = preg_replace('/```\s*/', '', $jsonstr);

        return json_decode($jsonstr);
    }

    /**
     * Builds the OCR messages for one cropped response area.
     *
     * Note: For a real implementation, we either need to crop the image *before* sending to save tokens,
     * or ask the AI to only look at the specific bounding box coordinates. Since GPT-4V doesn't
     * accept coordinate-based cropping natively, cropping in PHP (using GD/Imagick) before calling this
     * is the standard approach. So $imagebase64 is the ALREADY CROPPED image.
     *
     * @param string $imagebase64 Base64-encoded crop.
     * @return array Chat-shaped messages.
     */
    protected function build_ocr_messages($imagebase64) {
        $prompt = "You are an OCR transcription engine, not a proofreader or editor. Extract the handwritten or "
            . "typed text from the image EXACTLY as it appears, character for character.\n"
            . "Do NOT correct spelling, grammar, punctuation, or capitalization, even if it looks like an obvious "
            . "mistake. Preserve every error exactly as written - this text will be graded for those errors later.\n"
            . "Example: if the image shows \"I has a aple\", output exactly \"I has a aple\", NOT \"I have an apple\".\n"
            . "Only output the text you see, nothing else. If it's empty, output NOTHING.";

        return [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => "data:image/jpeg;base64,{$imagebase64}"]],
                ],
            ],
        ];
    }

    /**
     * Applies the "blank box" sentinel to a raw OCR result.
     *
     * @param string $text Raw model output.
     * @return string The transcribed text, or '' for an empty response area.
     */
    protected function clean_ocr_text($text) {
        $text = trim($text);

        if (strcasecmp($text, 'NOTHING') === 0) {
            return '';
        }

        return $text;
    }

    public function extract_text($imagebase64, $bbox) {
        $messages = $this->build_ocr_messages($imagebase64);

        // OCR/perception uses its own admin-configured model and reasoning effort,
        // independent of the grading model/effort - preserving errors verbatim is the
        // whole point of this plugin, and different models vary a lot on that.
        return $this->clean_ocr_text($this->call_openai($messages, null, $this->ocrmodel, $this->ocrreasoningeffort));
    }

    /**
     * OCRs many cropped response areas concurrently.
     *
     * Each crop is an independent request (the image has to travel with it, so unlike
     * grading these can't be collapsed into one prompt), which makes this the single
     * biggest win from running them in parallel: a batch costs pages x response areas
     * requests, and serially that is the slowest thing the plugin does.
     *
     * Crops are supplied as callables rather than strings so the caller can hold them on
     * disk and only base64-encode the one being sent, keeping peak memory proportional to
     * the number of requests in flight rather than to the size of the batch.
     *
     * @param array $crops [key => base64 string, or callable returning one].
     * @param int|null $concurrency Max requests in flight; defaults to the admin setting.
     * @return array [key => ['text' => string|null, 'error' => string|null]]. On failure
     *               'text' is null and 'error' is set - never conflate the two, an empty
     *               string is a legitimately blank response area.
     */
    public function extract_text_multi(array $crops, $concurrency = null) {
        if (empty($crops)) {
            return [];
        }

        $payloads = [];
        foreach ($crops as $key => $crop) {
            // Deferred: the crop is only read/encoded when this request reaches the front
            // of the queue, and is released again as soon as it is on the wire.
            $payloads[$key] = function() use ($crop) {
                return $this->build_request_payload(
                    $this->build_ocr_messages(is_callable($crop) ? $crop() : $crop),
                    null,
                    $this->ocrmodel,
                    $this->ocrreasoningeffort
                );
            };
        }

        $results = [];
        foreach ($this->call_openai_multi($payloads, $concurrency) as $key => $result) {
            $results[$key] = [
                'text' => $result['text'] === null ? null : $this->clean_ocr_text($result['text']),
                'error' => $result['error'],
            ];
        }

        return $results;
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
    /**
     * Builds the grading messages for one response area and its pending items.
     *
     * @param object $area A paper_response_areas record.
     * @param array $items [itemid => ocrtext].
     * @param string $feedbacklanguage
     * @return array Chat-shaped messages.
     */
    protected function build_evaluation_messages($area, $items, $feedbacklanguage = 'English') {
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

        return [
            [
                'role' => 'system',
                'content' => 'You are a precise grading assistant. Return only valid JSON.',
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ];
    }

    /**
     * Strips markdown fences the model sometimes wraps JSON in, and decodes it.
     *
     * @param string $jsonstr Raw model output.
     * @return array Decoded results, or [] if unparseable.
     */
    protected function decode_json_results($jsonstr) {
        $jsonstr = preg_replace('/```json\s*/', '', $jsonstr);
        $jsonstr = preg_replace('/```\s*/', '', $jsonstr);

        return json_decode($jsonstr, true) ?: [];
    }

    public function batch_process_evaluations($area, $items, $feedbacklanguage = 'English') {
        if (empty($items)) {
            return [];
        }

        $messages = $this->build_evaluation_messages($area, $items, $feedbacklanguage);

        return $this->decode_json_results($this->call_openai($messages, $this->maxtokensbatch));
    }

    /**
     * Grades several response areas concurrently - one request per area, as
     * batch_process_evaluations() does, but with all the areas in flight at once.
     *
     * @param array $areas [areaid => paper_response_areas record].
     * @param array $itemsbyarea [areaid => [itemid => ocrtext]].
     * @param string $feedbacklanguage
     * @param int|null $concurrency Max requests in flight; defaults to the admin setting.
     * @return array [areaid => ['results' => array, 'error' => string|null]].
     */
    public function batch_process_evaluations_multi(array $areas, array $itemsbyarea,
            $feedbacklanguage = 'English', $concurrency = null) {

        $payloads = [];
        foreach ($areas as $areaid => $area) {
            if (empty($itemsbyarea[$areaid])) {
                continue;
            }
            $payloads[$areaid] = $this->build_request_payload(
                $this->build_evaluation_messages($area, $itemsbyarea[$areaid], $feedbacklanguage),
                $this->maxtokensbatch
            );
        }

        $out = [];
        foreach ($this->call_openai_multi($payloads, $concurrency) as $areaid => $result) {
            $out[$areaid] = [
                'results' => $result['text'] === null ? [] : $this->decode_json_results($result['text']),
                'error' => $result['error'],
            ];
        }

        return $out;
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

        $jsonstr = $this->call_openai($messages, $this->maxtokensbatch);

        $jsonstr = preg_replace('/```json\s*/', '', $jsonstr);
        $jsonstr = preg_replace('/```\s*/', '', $jsonstr);

        return json_decode($jsonstr, true) ?: [];
    }
}
