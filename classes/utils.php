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
 * Utils for paper plugin
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_paper;

defined('MOODLE_INTERNAL') || die();


/**
 * Functions used generally across this mod
 *
 * @package    mod_paper
 * @copyright  2026 Justin Hunt (poodllsupport@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class utils {

    public static function do_markup($originaltext, $correctedtext) {
        $markeduporiginal = self::render_passage($originaltext, 'passage');
        $markedupcorrected = self::render_passage($correctedtext, 'corrections');
        return [$markeduporiginal, $markedupcorrected];
    }

    public static function fetch_grammar_correction_diff($originaltext, $correction, $direction = 'l2r') {

        // turn the passage and transcript into an array of words
        $alternatives = diff::fetchAlternativesArray('');
        $wildcards = diff::fetchWildcardsArray($alternatives);

        // the direction of diff depends on which text we want to mark up. Because we only highlight
        // this is because if we show the pre-text (eg student typed text) we can not highlight corrections .. they are not there
        // if we show post-text (eg corrections) we can not highlight mistakes .. they are not there
        // the diffs tell us where the diffs are with relation to text A
        // NB this is not a language direction thing(arabic hebrew etc), its a markup direction thing
        if ($direction == 'l2r') {
            $passagebits = diff::fetchWordArray($originaltext);
            $transcriptbits = diff::fetchWordArray($correction);
        } else {
            $passagebits = diff::fetchWordArray($correction);
            $transcriptbits = diff::fetchWordArray($originaltext);
        }

        // Fetch sequences of transcript/passage matched words.
        // Then prepare an array of "differences"
        $passagecount = count($passagebits);
        $transcriptcount = count($transcriptbits);
        // Rough estimate of insertions.
        $insertioncount = $transcriptcount - $passagecount;
        if ($insertioncount < 0) {
            $insertioncount = 0;
        }

        $language = 'en-US';
        $sequences = diff::fetchSequences($passagebits, $transcriptbits, $alternatives, $language);

        // Fetch diffs.
        $diffs = diff::fetchDiffs($sequences, $passagecount, $transcriptcount);
        $diffs = diff::applyWildcards($diffs, $passagebits, $wildcards);

        // From the array of differences build error data, match data, markers, scores and metrics.
        $errors = new \stdClass();
        $matches = new \stdClass();
        $currentword = 0;
        $lastunmodified = 0;
        // Loop through diffs.
        foreach ($diffs as $diff) {
            $currentword++;
            switch ($diff[0]) {
                case Diff::UNMATCHED:
                    // We collect error info so we can count and display them on passage.
                    $error = new \stdClass();
                    $error->word = $passagebits[$currentword - 1];
                    $error->wordnumber = $currentword;
                    $errors->{$currentword} = $error;
                    break;

                case Diff::MATCHED:
                    // We collect match info so we can play audio from selected word.
                    $match = new \stdClass();
                    $match->word = $passagebits[$currentword - 1];
                    $match->pposition = $currentword;
                    $match->tposition = $diff[1];
                    $matches->{$currentword} = $match;
                    $lastunmodified = $currentword;
                    break;

                default:
                    // Do nothing.
                    // Should never get here.
            }
        }
        $sessionendword = $lastunmodified;

        // Discard errors that happen after session end word.
        $errorcount = 0;
        $finalerrors = new \stdClass();
        foreach ($errors as $key => $error) {
            if ($key < $sessionendword) {
                $finalerrors->{$key} = $error;
                $errorcount++;
            }
        }
        // Finalise and serialise session errors.
        $sessionerrors = json_encode($finalerrors);
        $sessionmatches = json_encode($matches);

        return [$sessionerrors, $sessionmatches, $insertioncount];
    }

    /**
     * Render a passage of text into span-wrapped words for further processing
     *
     * @param string The passage of text to convert
     * @param string The markup type (passage|corrections)
     * @return string The converted passage of text
     */
    public static function render_passage($passage, $markuptype = 'passage') {
        // Load the HTML document.
        $doc = new \DOMDocument();

        // Clean up weird encoding before loading into domdocument
        $safepassage = htmlspecialchars($passage, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        @$doc->loadHTML(mb_encode_numericentity($safepassage, [0x80, 0x10FFFF, 0, ~0], 'UTF-8'));

        // Select all the text nodes.
        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query('//text()');

        // Base CSS class.
        // We will add _mu_passage_word and _mu_passage_space. Can be customized though.
        $cssword = 'mod_paper_mu_' . $markuptype . '_word';
        $cssspace = 'mod_paper_mu_' . $markuptype . '_space';

        // Original CSS classes
        // The original classes are to show the original passage word before or after the corrections word
        // because of the layout, "rewritten/added words" [corrections] will show in green, after the original words [red]
        // but "removed(omitted) words" [corrections] will show as a green space  after the original words [red]
        // so the span layout for each word in the corrections is:
        // [original_preword][correctionsword][original_postword][correctionsspace]
        // e.g rewritten/added word: (original)He eat apples => (corrected)He eats apples =>
        // [original_preword: "eat->"][correctionsword: "eats"][original_postword][correctionsspace]
        // e.g removed/omitted word: (original)He eat devours the apples=> (corrected)He devours the apples =>
        // [original_preword: ][correctionsword: "He"][original_postword: "eat->" ][correctionsspace: " "].

        $cssoriginalpreword = 'mod_paper_mu_original_preword';
        $cssoriginalpostword = 'mod_paper_mu_original_postword';

        // Init the text count.
        $wordcount = 0;
        foreach ($nodes as $node) {
            $trimmednode = self::super_trim($node->nodeValue);
            if (empty($trimmednode)) {
                continue;
            }

            // Explode missed new lines that had been copied and pasted. eg A[newline]B was not split and was one word.
            // This resulted in ai selected error words, having different index to their passage text counterpart.
            $seperator = ' ';

            $nodevalue = self::lines_to_brs($node->nodeValue, $seperator);
            $words = preg_split('/\s+/', $nodevalue);

            foreach ($words as $word) {
                // If its a new line character from lines_to_brs we add it, but not as a word.
                if ($word == '<br>') {
                    $newnode = $doc->createElement('br', $word);
                    $node->parentNode->appendChild($newnode);
                    continue;
                }

                $wordcount++;
                $newnode = $doc->createElement('span', $word);
                $spacenode = $doc->createElement('span', $seperator);
                $newnode->setAttribute('id', $cssword . '_' . $wordcount);
                $newnode->setAttribute('data-wordnumber', $wordcount);
                $newnode->setAttribute('class', $cssword);
                $spacenode->setAttribute('id', $cssspace . '_' . $wordcount);
                $spacenode->setAttribute('data-wordnumber', $wordcount);
                $spacenode->setAttribute('class', $cssspace);
                // Original pre node.
                if ($markuptype !== 'passage') {
                    $originalprenode = $doc->createElement('span', '');
                    $originalprenode->setAttribute('id', $cssoriginalpreword . '_' . $wordcount);
                    $originalprenode->setAttribute('data-wordnumber', $wordcount);
                    $originalprenode->setAttribute('class', $cssoriginalpreword);
                }
                // Original post node.
                if ($markuptype !== 'passage') {
                    $originalpostnode = $doc->createElement('span', '');
                    $originalpostnode->setAttribute('id', $cssoriginalpostword . '_' . $wordcount);
                    $originalpostnode->setAttribute('data-wordnumber', $wordcount);
                    $originalpostnode->setAttribute('class', $cssoriginalpostword);
                }
                // Add nodes to doc.
                if ($markuptype == 'passage') {
                    $node->parentNode->appendChild($newnode);
                    $node->parentNode->appendChild($spacenode);
                } else {
                    $node->parentNode->appendChild($originalprenode);
                    $node->parentNode->appendChild($newnode);
                    $node->parentNode->appendChild($originalpostnode);
                    $node->parentNode->appendChild($spacenode);
                }
            }
            $node->nodeValue = "";
        }

        $usepassage = $doc->saveHTML();
        // Remove container 'p' tags, they mess up formatting in solo.
        $usepassage = str_replace('<p>', '', $usepassage);
        $usepassage = str_replace('</p>', '', $usepassage);

        if ($markuptype == 'passage') {
            $ret = \html_writer::div(
                $usepassage,
                'mod_paper_original mod_paper_summarytranscriptplaceholder'
            );
        } else {
            $ret = \html_writer::div($usepassage, 'mod_paper_corrections ');
        }
        return $ret;
    }

    /**
     * Turn a passage with text "lines" into html "brs"
     *
     * @param string The passage of text to convert
     * @param string An optional pad on each replacement (needed for processing when marking up words as spans in passage)
     * @return string The converted passage of text
     */
    public static function lines_to_brs($passage, $seperator = '') {
        // See https://stackoverflow.com/questions/5946114/how-to-replace-newline-or-r-n-with-br .
        return str_replace("\r\n", $seperator . '<br>' . $seperator, $passage);
        // This is better but we can not pad the replacement and we need that.
    }

    public static function super_trim($str) {
        if ($str == null) {
            return '';
        } else {
            $str = trim($str);
            return $str;
        }
    }

    /**
     * Get font options for select lists
     *
     * @return array
     */
    public static function get_font_options() {
        return [
            'freesans' => get_string('font_freesans', 'mod_paper'),
            'courier' => get_string('font_courier', 'mod_paper'),
            'helvetica' => get_string('font_helvetica', 'mod_paper'),
            'times' => get_string('font_times', 'mod_paper'),
            'kozminproregular' => get_string('font_kozminproregular', 'mod_paper'),
            'stsongstdlight' => get_string('font_stsongstdlight', 'mod_paper'),
            'msungstdlight' => get_string('font_msungstdlight', 'mod_paper'),
            'cid0kr' => get_string('font_cid0kr', 'mod_paper'),
        ];
    }

    /**
     * Maps a TCPDF font to a CSS font-family string
     *
     * @param string $font The TCPDF font name
     * @return string CSS font-family
     */
    public static function get_css_font_family($font) {
        switch ($font) {
            case 'courier':
                return '"Courier New", Courier, monospace';
            case 'helvetica':
                return 'Helvetica, Arial, sans-serif';
            case 'times':
                return '"Times New Roman", Times, serif';
            case 'kozminproregular':
                return '"Kozuka Mincho Pro", "MS Mincho", serif';
            case 'stsongstdlight':
            case 'msungstdlight':
                return '"STSong", "SimSun", serif';
            case 'cid0kr':
                return '"Malgun Gothic", "Batang", serif';
            case 'freesans':
            default:
                return '"FreeSans", sans-serif';
        }
    }

    /**
     * Get language options for select lists
     *
     * @return array
     */
    public static function get_lang_options() {
        return [
            constants::M_LANG_ARAE => get_string('ar-ae', constants::M_COMPONENT),
            constants::M_LANG_ARSA => get_string('ar-sa', constants::M_COMPONENT),
            constants::M_LANG_BGBG => get_string('bg-bg', constants::M_COMPONENT),
            constants::M_LANG_CSCZ => get_string('cs-cz', constants::M_COMPONENT),
            constants::M_LANG_HRHR => get_string('hr-hr', constants::M_COMPONENT),
            constants::M_LANG_ZHCN => get_string('zh-cn', constants::M_COMPONENT),
            constants::M_LANG_DADK => get_string('da-dk', constants::M_COMPONENT),
            constants::M_LANG_NLNL => get_string('nl-nl', constants::M_COMPONENT),
            constants::M_LANG_NLBE => get_string('nl-be', constants::M_COMPONENT),
            constants::M_LANG_ENUS => get_string('en-us', constants::M_COMPONENT),
            constants::M_LANG_ENGB => get_string('en-gb', constants::M_COMPONENT),
            constants::M_LANG_ENAU => get_string('en-au', constants::M_COMPONENT),
            constants::M_LANG_ENPH => get_string('en-ph', constants::M_COMPONENT),
            constants::M_LANG_ENNZ => get_string('en-nz', constants::M_COMPONENT),
            constants::M_LANG_ENZA => get_string('en-za', constants::M_COMPONENT),
            constants::M_LANG_ENIN => get_string('en-in', constants::M_COMPONENT),
            constants::M_LANG_ENIE => get_string('en-ie', constants::M_COMPONENT),
            constants::M_LANG_ENWL => get_string('en-wl', constants::M_COMPONENT),
            constants::M_LANG_ENAB => get_string('en-ab', constants::M_COMPONENT),
            constants::M_LANG_FIFI => get_string('fi-fi', constants::M_COMPONENT),
            constants::M_LANG_FILPH => get_string('fil-ph', constants::M_COMPONENT),
            constants::M_LANG_FRCA => get_string('fr-ca', constants::M_COMPONENT),
            constants::M_LANG_FRFR => get_string('fr-fr', constants::M_COMPONENT),
            constants::M_LANG_DEDE => get_string('de-de', constants::M_COMPONENT),
            constants::M_LANG_DECH => get_string('de-ch', constants::M_COMPONENT),
            constants::M_LANG_DEAT => get_string('de-at', constants::M_COMPONENT),
            constants::M_LANG_ELGR => get_string('el-gr', constants::M_COMPONENT),
            constants::M_LANG_HIIN => get_string('hi-in', constants::M_COMPONENT),
            constants::M_LANG_HEIL => get_string('he-il', constants::M_COMPONENT),
            constants::M_LANG_HUHU => get_string('hu-hu', constants::M_COMPONENT),
            constants::M_LANG_ISIS => get_string('is-is', constants::M_COMPONENT),
            constants::M_LANG_IDID => get_string('id-id', constants::M_COMPONENT),
            constants::M_LANG_ITIT => get_string('it-it', constants::M_COMPONENT),
            constants::M_LANG_JAJP => get_string('ja-jp', constants::M_COMPONENT),
            constants::M_LANG_KOKR => get_string('ko-kr', constants::M_COMPONENT),
            constants::M_LANG_LTLT => get_string('lt-lt', constants::M_COMPONENT),
            constants::M_LANG_LVLV => get_string('lv-lv', constants::M_COMPONENT),
            constants::M_LANG_MINZ => get_string('mi-nz', constants::M_COMPONENT),
            constants::M_LANG_MSMY => get_string('ms-my', constants::M_COMPONENT),
            constants::M_LANG_MKMK => get_string('mk-mk', constants::M_COMPONENT),
            constants::M_LANG_PLPL => get_string('pl-pl', constants::M_COMPONENT),
            constants::M_LANG_FAIR => get_string('fa-ir', constants::M_COMPONENT),
            constants::M_LANG_PTBR => get_string('pt-br', constants::M_COMPONENT),
            constants::M_LANG_PTPT => get_string('pt-pt', constants::M_COMPONENT),
            constants::M_LANG_RORO => get_string('ro-ro', constants::M_COMPONENT),
            constants::M_LANG_RURU => get_string('ru-ru', constants::M_COMPONENT),
            constants::M_LANG_ESUS => get_string('es-us', constants::M_COMPONENT),
            constants::M_LANG_ESES => get_string('es-es', constants::M_COMPONENT),
            constants::M_LANG_SKSK => get_string('sk-sk', constants::M_COMPONENT),
            constants::M_LANG_SLSI => get_string('sl-si', constants::M_COMPONENT),
            constants::M_LANG_SOSO => get_string('so-so', constants::M_COMPONENT),
            constants::M_LANG_SRRS => get_string('sr-rs', constants::M_COMPONENT),
            constants::M_LANG_SVSE => get_string('sv-se', constants::M_COMPONENT),
            constants::M_LANG_TAIN => get_string('ta-in', constants::M_COMPONENT),
            constants::M_LANG_TEIN => get_string('te-in', constants::M_COMPONENT),
            constants::M_LANG_TRTR => get_string('tr-tr', constants::M_COMPONENT),
            constants::M_LANG_UKUA => get_string('uk-ua', constants::M_COMPONENT),
            constants::M_LANG_VIVN => get_string('vi-vn', constants::M_COMPONENT),
            constants::M_LANG_NONO => get_string('no-no', constants::M_COMPONENT),
            constants::M_LANG_NBNO => get_string('nb-no', constants::M_COMPONENT),
            constants::M_LANG_NNNO => get_string('nn-no', constants::M_COMPONENT),
            constants::M_LANG_PSAF => get_string('ps-af', constants::M_COMPONENT),
            constants::M_LANG_EUES => get_string('eu-es', constants::M_COMPONENT),
        ];
    }

    /**
     * Builds a single HTML string showing struck-out errors and underlined corrections.
     * Uses fetch_grammar_correction_diff to get match positions.
     */
    public static function build_combined_diff($original, $corrected, $ispdf = false) {
        // ocrtext/correctedtext are nullable, and correctedtext is NULL for any item that
        // hasn't been evaluated yet, so callers can legitimately hand us a NULL here.
        $original = (string) ($original ?? '');
        $corrected = (string) ($corrected ?? '');

        if (trim($original) === trim($corrected)) {
            return htmlspecialchars(trim($original));
        }
        if (empty(trim($corrected))) {
            return '<del>' . htmlspecialchars(trim($original)) . '</del>';
        }

        [$originalwords, $raworiginal] = diff::fetchWordArrayWithRaw($original);
        [$correctedwords, $rawcorrected] = diff::fetchWordArrayWithRaw($corrected);

        // Returns [$sessionerrors, $sessionmatches, $insertioncount]
        $result = self::fetch_grammar_correction_diff($original, $corrected, 'l2r');
        $matches = json_decode($result[1], true) ?: [];

        $html = '';
        $pidx = 1;
        $tidx = 1;

        $pcount = count($originalwords);
        $tcount = count($correctedwords);

        while ($pidx <= $pcount || $tidx <= $tcount) {
            $nexttidx = null;
            $nextpidx = null;

            // Find the next match starting from current t_idx
            for ($t = $tidx; $t <= $tcount; $t++) {
                foreach ($matches as $p => $match) {
                    if ($match['tposition'] == $t && $p >= $pidx) {
                        $nexttidx = $t;
                        $nextpidx = $p;
                        break 2;
                    }
                }
            }

            if ($nexttidx !== null && $nextpidx !== null) {
                $deleted = [];
                for ($p = $pidx; $p < $nextpidx; $p++) {
                    $deleted[] = $raworiginal[$p - 1] ?? $originalwords[$p - 1];
                }
                $inserted = [];
                for ($t = $tidx; $t < $nexttidx; $t++) {
                    $inserted[] = $rawcorrected[$t - 1] ?? $correctedwords[$t - 1];
                }

                if (!empty($deleted) || !empty($inserted)) {
                    $html .= $ispdf ? '<b>' : '<span style="color: red;">';
                    if (!empty($deleted)) {
                        $html .= '[' . htmlspecialchars(implode(' ', $deleted)) . ']';
                    }
                    if (!empty($deleted) && !empty($inserted)) {
                        $html .= ' <span style="font-family: freesans, sans-serif;">&rarr;</span> ';
                    } elseif (empty($deleted) && !empty($inserted)) {
                        // pure insertion
                        $html .= ''; // just add the inserted text
                    }
                    if (!empty($inserted)) {
                        $html .= htmlspecialchars(implode(' ', $inserted));
                    }
                    $html .= $ispdf ? '</b> ' : '</span> ';
                }

                // Output match
                $word = $rawcorrected[$nexttidx - 1] ?? $correctedwords[$nexttidx - 1];
                $html .= htmlspecialchars($word) . ' ';

                $pidx = $nextpidx + 1;
                $tidx = $nexttidx + 1;
            } else {
                // No more matches, output remainder
                $deleted = [];
                for ($p = $pidx; $p <= $pcount; $p++) {
                    $deleted[] = $raworiginal[$p - 1] ?? $originalwords[$p - 1];
                }
                $inserted = [];
                for ($t = $tidx; $t <= $tcount; $t++) {
                    $inserted[] = $rawcorrected[$t - 1] ?? $correctedwords[$t - 1];
                }

                if (!empty($deleted) || !empty($inserted)) {
                    $html .= $ispdf ? '<b>' : '<span style="color: red;">';
                    if (!empty($deleted)) {
                        $html .= '[' . htmlspecialchars(implode(' ', $deleted)) . ']';
                    }
                    if (!empty($deleted) && !empty($inserted)) {
                        $html .= ' <span style="font-family: freesans, sans-serif;">&rarr;</span> ';
                    }
                    if (!empty($inserted)) {
                        $html .= htmlspecialchars(implode(' ', $inserted));
                    }
                    $html .= $ispdf ? '</b> ' : '</span> ';
                }
                break;
            }
        }

        return trim($html);
    }


    /**
     * Get grading preset options for select lists
     *
     * @return array
     */
    public static function get_grading_preset_options() {
        global $DB, $USER;

        $options = [0 => get_string('selectpreset', 'mod_paper')];

        // 1. Site-wide presets.
        for ($i = 1; $i <= 2; $i++) {
            $name = get_config('mod_paper', 'gradingprompt_' . $i . '_name');
            if (!empty($name)) {
                $options['site_' . $i] = $name . ' (Site)';
            }
        }

        // 2. User-specific presets.
        $userpresets = $DB->get_records('paper_grading_presets', ['userid' => $USER->id], 'name ASC');
        foreach ($userpresets as $preset) {
            $options['user_' . $preset->id] = $preset->name;
        }

        return $options;
    }

    /**
     * Get grading preset options as a list of objects for Mustache
     *
     * @return array
     */
    public static function get_grading_preset_options_list() {
        $options = self::get_grading_preset_options();
        $list = [];
        foreach ($options as $key => $value) {
            $list[] = ['key' => $key, 'value' => $value];
        }
        return $list;
    }

    /**
     * Get all grading presets as a JSON object for Javascript
     *
     * @return string JSON string
     */
    public static function get_grading_presets_json() {
        global $DB, $USER;

        $presets = [];

        // 1. Site-wide presets.
        for ($i = 1; $i <= 2; $i++) {
            $content = get_config('mod_paper', 'gradingprompt_' . $i . '_content');
            if (!empty($content)) {
                $presets['site_' . $i] = $content;
            }
        }

        // 2. User-specific presets.
        $userpresets = $DB->get_records('paper_grading_presets', ['userid' => $USER->id]);
        foreach ($userpresets as $preset) {
            $presets['user_' . $preset->id] = $preset->content;
        }

        return json_encode($presets);
    }

    /**
     * Get feedback preset options for select lists.
     *
     * @return array
     */
    public static function get_feedback_preset_options() {
        global $DB, $USER;

        $options = [0 => get_string('selectfeedbackpreset', 'mod_paper')];

        // 1. Site-wide presets.
        for ($i = 1; $i <= 2; $i++) {
            $name = get_config('mod_paper', 'feedbackprompt_' . $i . '_name');
            if (!empty($name)) {
                $options['site_' . $i] = $name . ' (Site)';
            }
        }

        // 2. User-specific presets.
        $userpresets = $DB->get_records('paper_feedback_presets', ['userid' => $USER->id], 'name ASC');
        foreach ($userpresets as $preset) {
            $options['user_' . $preset->id] = $preset->name;
        }

        return $options;
    }

    /**
     * Get feedback preset options as a list of objects for Mustache.
     *
     * @return array
     */
    public static function get_feedback_preset_options_list() {
        $options = self::get_feedback_preset_options();
        $list = [];
        foreach ($options as $key => $value) {
            $list[] = ['key' => $key, 'value' => $value];
        }
        return $list;
    }

    /**
     * Get all feedback presets as a JSON object for Javascript.
     *
     * @return string JSON string
     */
    public static function get_feedback_presets_json() {
        global $DB, $USER;

        $presets = [];

        // 1. Site-wide presets.
        for ($i = 1; $i <= 2; $i++) {
            $content = get_config('mod_paper', 'feedbackprompt_' . $i . '_content');
            if (!empty($content)) {
                $presets['site_' . $i] = $content;
            }
        }

        // 2. User-specific presets.
        $userpresets = $DB->get_records('paper_feedback_presets', ['userid' => $USER->id]);
        foreach ($userpresets as $preset) {
            $presets['user_' . $preset->id] = $preset->content;
        }

        return json_encode($presets);
    }

    /**
     * Returns the effective feedback box coordinates for a response area.
     * When fb_x/y/w/h are all zero (unset), defaults to the bottom 30% of
     * the response area — same width, positioned at 70% down from the top.
     *
     * @param object $area DB row from paper_response_areas
     * @return array Associative array with keys x, y, w, h (percentages 0-100)
     */
    public static function get_effective_feedback_box(object $area): array {
        $fbx = (float)($area->fb_x ?? 0);
        $fby = (float)($area->fb_y ?? 0);
        $fbw = (float)($area->fb_w ?? 0);
        $fbh = (float)($area->fb_h ?? 0);
        if ($fbx == 0.0 && $fby == 0.0 && $fbw == 0.0 && $fbh == 0.0) {
            $fbx = (float)$area->box_x;
            $fby = (float)$area->box_y + ((float)$area->box_h * 0.7);
            $fbw = (float)$area->box_w;
            $fbh = (float)$area->box_h * 0.3;
        }
        return ['x' => $fbx, 'y' => $fby, 'w' => $fbw, 'h' => $fbh];
    }
}
