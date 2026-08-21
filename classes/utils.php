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
     * Whether the developer-facing debug features are turned on for this site.
     *
     * Off by default: these are inspection tools for working on the plugin, not something a
     * teacher should meet in the normal run of things.
     *
     * @return bool
     */
    public static function debug_features_enabled() {
        return (bool) get_config(constants::M_COMPONENT, 'enabledebugfeatures');
    }

    /**
     * The font keys we can safely hand to TCPDF, in the order they are offered.
     *
     * Separate from get_font_options() so that validating a stored font does not build (and
     * translate) the whole label list - resolve_font() runs per area per student when a PDF
     * is generated.
     *
     * @return string[]
     */
    public static function get_font_keys() {
        // Only fonts Moodle actually ships in lib/tcpdf/fonts belong here - TCPDF fatals on a
        // font definition file it cannot include, it does not fall back. ('cid0kr' used to be
        // offered for Korean and did exactly that; Moodle ships hysmyeongjostdmedium instead.)
        //
        // The freefont family is embedded, so it renders identically everywhere and covers
        // non-Latin scripts; the core fonts below it are Latin-only and the CJK fonts are CID-0,
        // meaning the glyphs are not embedded and the reader needs the font installed.
        return [
            'freeserif',
            'freesans',
            'freemono',
            'courier',
            'helvetica',
            'times',
            'kozminproregular',
            'kozgopromedium',
            'stsongstdlight',
            'msungstdlight',
            'hysmyeongjostdmedium',
        ];
    }

    /**
     * Get font options for select lists, keyed by font name.
     *
     * @return array
     */
    public static function get_font_options() {
        $options = [];
        foreach (self::get_font_keys() as $key) {
            $options[$key] = get_string('font_' . $key, 'mod_paper');
        }
        return $options;
    }

    /**
     * Get the font options offered per response area.
     *
     * Two "inherit" entries are prepended to the plain font list, naming the font they
     * currently resolve to so a teacher can see what they would be getting. Storing the
     * sentinel rather than the resolved name means an area left alone still follows the
     * activity's language fonts when those are later changed.
     *
     * @param object $paper The paper instance (for the fonts the sentinels resolve to).
     * @return array Font key => label.
     */
    public static function get_area_font_options($paper) {
        $fonts = self::get_font_options();

        // Resolve rather than read the columns directly, so the label names the font the area
        // will really be printed in even if the activity stores one we can no longer honour.
        $targetfont = self::resolve_font(null, $paper, constants::M_FONT_TARGET);
        $nativefont = self::resolve_font(null, $paper, constants::M_FONT_NATIVE);
        $targetlabel = $fonts[$targetfont] ?? $targetfont;
        $nativelabel = $fonts[$nativefont] ?? $nativefont;

        return [
            constants::M_FONT_TARGET => get_string('font_inherit_target', 'mod_paper', $targetlabel),
            constants::M_FONT_NATIVE => get_string('font_inherit_native', 'mod_paper', $nativelabel),
        ] + $fonts;
    }

    /**
     * Resolves a stored per-area font setting to an actual TCPDF font name.
     *
     * @param string|null $font Stored value: a sentinel from constants::M_FONT_*, a literal
     *                          font name, or NULL/'' on a row saved before this setting existed.
     * @param object $paper The paper instance.
     * @param string $fallback Which sentinel an unset value means for this field.
     * @return string A TCPDF font name.
     */
    public static function resolve_font($font, $paper, $fallback = constants::M_FONT_TARGET) {
        $font = ($font === null || $font === '') ? $fallback : $font;

        if ($font === constants::M_FONT_TARGET) {
            $font = $paper->targetlanguagefont ?? '';
        } else if ($font === constants::M_FONT_NATIVE) {
            $font = $paper->feedbacklanguagefont ?? '';
        }

        // Validate whatever we ended up with, including the activity-level fonts - TCPDF fatals
        // on a font it cannot load, so never hand back a name we are not sure of.
        return in_array($font, self::get_font_keys(), true) ? $font : constants::M_FONT_FALLBACK;
    }

    /**
     * The font a response area's student text (OCR / corrected) is printed in.
     *
     * @param object $area The response area row.
     * @param object $paper The paper instance.
     * @return string A TCPDF font name.
     */
    public static function get_response_font($area, $paper) {
        return self::resolve_font($area->responsefont ?? null, $paper, constants::M_FONT_TARGET);
    }

    /**
     * The font a response area's feedback text is printed in.
     *
     * @param object $area The response area row.
     * @param object $paper The paper instance.
     * @return string A TCPDF font name.
     */
    public static function get_feedback_font($area, $paper) {
        return self::resolve_font($area->feedbackfont ?? null, $paper, constants::M_FONT_NATIVE);
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
            case 'freemono':
                return '"FreeMono", "Courier New", monospace';
            case 'helvetica':
                return 'Helvetica, Arial, sans-serif';
            case 'times':
                return '"Times New Roman", Times, serif';
            case 'freeserif':
                return '"FreeSerif", "Times New Roman", serif';
            case 'kozminproregular':
                return '"Kozuka Mincho Pro", "MS Mincho", serif';
            case 'kozgopromedium':
                return '"Kozuka Gothic Pro", "MS Gothic", sans-serif';
            case 'stsongstdlight':
            case 'msungstdlight':
                return '"STSong", "SimSun", serif';
            case 'hysmyeongjostdmedium':
                return '"HYSMyeongJo", "Batang", "Malgun Gothic", serif';
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
                    } else if (empty($deleted) && !empty($inserted)) {
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
     * Is this a standard response area, i.e. one the AI corrects, comments on and grades?
     *
     * This is the check behind almost every piece of AI output: correction diffs, feedback
     * text, item grades and the maximum-grade totals all apply to these areas and no others.
     *
     * @param object $area DB row from paper_response_areas.
     * @return bool
     */
    public static function is_graded_area(object $area): bool {
        return (int)$area->areatype === constants::M_AREATYPE_GRADED;
    }

    /**
     * Does this area capture the student's identity, rather than a response?
     *
     * Covers both the free-text name field and the Moodle username field. Their text is
     * bottom-aligned when printed (it sits on a ruled line on the worksheet) and is kept in
     * paper_evaluations.studentnametext rather than being treated as an answer.
     *
     * @param object $area DB row from paper_response_areas.
     * @return bool
     */
    public static function is_name_area(object $area): bool {
        $areatype = (int)$area->areatype;
        return $areatype === constants::M_AREATYPE_NAME || $areatype === constants::M_AREATYPE_USERNAME;
    }

    /**
     * Is this area reproduced as an image of the student's handwriting instead of being read?
     *
     * @param object $area DB row from paper_response_areas.
     * @return bool
     */
    public static function is_displayonly_area(object $area): bool {
        return (int)$area->areatype === constants::M_AREATYPE_DISPLAYONLY;
    }

    /**
     * Should this area's crop be sent for OCR?
     *
     * Every area type except display-only has its handwriting read back as text - what
     * differs afterwards is how much is done with that text.
     *
     * @param object $area DB row from paper_response_areas.
     * @return bool
     */
    public static function has_ocr_text(object $area): bool {
        return !self::is_displayonly_area($area);
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

    /**
     * Returns the scan alignment correction in force for a paper.
     *
     * Printing a worksheet and scanning it back rarely reproduces the original geometry
     * exactly - the content typically lands a couple of millimetres off, and can be very
     * slightly stretched. These four numbers describe that displacement so the crop taken
     * for a display-only area can be shifted back onto the content it was meant to capture.
     *
     * Offsets are percentages of the page (positive = the scanned content sits further
     * right/down than the template says); scales are percentages where 100 means no
     * stretch. A paper leaves any of the four NULL to inherit the site default.
     *
     * @param object $paper DB row from paper.
     * @return array Associative array with keys offsetx, offsety, scalex, scaley.
     */
    public static function get_scan_alignment(object $paper): array {
        $defaults = [
            'offsetx' => (float) get_config('mod_paper', 'alignoffsetx'),
            'offsety' => (float) get_config('mod_paper', 'alignoffsety'),
            'scalex' => (float) get_config('mod_paper', 'alignscalex'),
            'scaley' => (float) get_config('mod_paper', 'alignscaley'),
        ];

        $alignment = [];
        foreach ($defaults as $key => $default) {
            $value = $paper->{'align' . $key} ?? null;
            $alignment[$key] = ($value === null || $value === '') ? $default : (float) $value;
        }

        // A zero scale would collapse the window to nothing - treat it as "unset".
        foreach (['scalex', 'scaley'] as $key) {
            if ($alignment[$key] <= 0) {
                $alignment[$key] = 100.0;
            }
        }

        return $alignment;
    }

    /**
     * Grows a response area's bounding box by a margin on all sides, for cropping.
     *
     * The crop is taken before anyone knows how far the scan drifted, and the original
     * scan is deleted once OCR finishes, so a crop taken at exactly the box coordinates
     * bakes in whatever misregistration happened: content that should have been captured
     * is simply missing, and correcting the position later cannot bring it back. Cropping
     * a little wider than the box costs some storage but keeps that content available, so
     * the alignment can be adjusted afterwards by windowing into the padded patch (see
     * window_snippet()) instead of re-uploading and re-OCRing the whole batch.
     *
     * This is for display-only areas, whose stored image is transplanted back onto the
     * template. Areas that get OCR'd are deliberately cropped at the box instead: a wider
     * crop feeds the model whatever is printed just outside the area, and it transcribes
     * that too.
     *
     * The padded box is clamped to the page, so the returned fractions describe where the
     * patch that will actually be cropped sits - not where an unclamped one would have.
     *
     * @param object $area DB row from paper_response_areas.
     * @param float $padmm Margin to add on every side, in millimetres. 0 disables padding.
     * @return array{box: \stdClass, snippetx: float, snippety: float, snippetw: float, snippeth: float}
     *               'box' carries box_x/box_y/box_w/box_h for the padded crop; the four
     *               fractions give the patch's position and size as percentages of the
     *               original box ([0, 0, 100, 100] when nothing was added).
     */
    public static function pad_box(object $area, float $padmm): array {
        $bx = (float) $area->box_x;
        $by = (float) $area->box_y;
        $bw = (float) $area->box_w;
        $bh = (float) $area->box_h;

        $identity = static function() use ($area, $bx, $by, $bw, $bh) {
            $box = new \stdClass();
            $box->box_x = $bx;
            $box->box_y = $by;
            $box->box_w = $bw;
            $box->box_h = $bh;
            return ['box' => $box, 'snippetx' => 0.0, 'snippety' => 0.0,
                    'snippetw' => 100.0, 'snippeth' => 100.0];
        };

        // A zero-sized box has no frame of reference to express the padding against.
        if ($padmm <= 0 || $bw <= 0 || $bh <= 0) {
            return $identity();
        }

        $padw = ($padmm / constants::M_PAGE_W_MM) * 100.0;
        $padh = ($padmm / constants::M_PAGE_H_MM) * 100.0;

        $left = max(0.0, $bx - $padw);
        $top = max(0.0, $by - $padh);
        $right = min(100.0, $bx + $bw + $padw);
        $bottom = min(100.0, $by + $bh + $padh);

        $box = new \stdClass();
        $box->box_x = $left;
        $box->box_y = $top;
        $box->box_w = $right - $left;
        $box->box_h = $bottom - $top;

        return [
            'box' => $box,
            'snippetx' => (($left - $bx) / $bw) * 100.0,
            'snippety' => (($top - $by) / $bh) * 100.0,
            'snippetw' => ($box->box_w / $bw) * 100.0,
            'snippeth' => ($box->box_h / $bh) * 100.0,
        ];
    }

    /**
     * Cuts the box-sized region out of a padded crop, shifted by the scan alignment.
     *
     * The stored patch is wider than the response area (see pad_box()); this picks the
     * part of it that corresponds to the area itself, moved by however far the scan
     * drifted. The result is always exactly the region the caller should draw at the
     * area's own coordinates, so both the web preview and the PDF can render it without
     * knowing anything about padding.
     *
     * Where the requested window runs off the edge of the patch - a drift larger than the
     * margin that was cropped, or a legacy unpadded snippet - the missing strip comes back
     * white rather than being clipped, so the content that does exist still lands in the
     * right place.
     *
     * @param string $imagedata Raw JPEG binary of the stored patch.
     * @param object $item DB row from paper_eval_items, carrying snippetx/y/w/h.
     * @param object $area DB row from paper_response_areas.
     * @param array $alignment Alignment from get_scan_alignment().
     * @return string Raw JPEG binary of the windowed region, or $imagedata unchanged if it
     *                could not be decoded or the item has no recorded patch position.
     */
    public static function window_snippet($imagedata, object $item, object $area, array $alignment) {
        if ($item->snippetx === null || $item->snippety === null
                || $item->snippetw === null || $item->snippeth === null) {
            return $imagedata;
        }

        $bw = (float) $area->box_w;
        $bh = (float) $area->box_h;
        $patchw = ((float) $item->snippetw / 100.0) * $bw;
        $patchh = ((float) $item->snippeth / 100.0) * $bh;
        if ($bw <= 0 || $bh <= 0 || $patchw <= 0 || $patchh <= 0) {
            return $imagedata;
        }

        $src = @imagecreatefromstring($imagedata);
        if (!$src) {
            return $imagedata;
        }

        try {
            $srcw = imagesx($src);
            $srch = imagesy($src);

            // Where the stored patch sits on the page, as percentages.
            $patchx = (float) $area->box_x + ((float) $item->snippetx / 100.0) * $bw;
            $patchy = (float) $area->box_y + ((float) $item->snippety / 100.0) * $bh;

            // Where we want to read from instead, once the scan's drift is accounted for.
            $winx = $alignment['offsetx'] + ($alignment['scalex'] / 100.0) * (float) $area->box_x;
            $winy = $alignment['offsety'] + ($alignment['scaley'] / 100.0) * (float) $area->box_y;
            $winw = ($alignment['scalex'] / 100.0) * $bw;
            $winh = ($alignment['scaley'] / 100.0) * $bh;

            // The patch is the only thing that maps percentages to pixels here, and the
            // window is expressed in the same units, so this stays a 1:1 copy - any
            // rescaling happens when the caller draws the result into the area's box.
            $pxper = $srcw / $patchw;
            $pyper = $srch / $patchh;

            $outw = max(1, (int) round($winw * $pxper));
            $outh = max(1, (int) round($winh * $pyper));

            $dest = imagecreatetruecolor($outw, $outh);
            $white = imagecolorallocate($dest, 255, 255, 255);
            imagefilledrectangle($dest, 0, 0, $outw, $outh, $white);

            // Offset of the window's top-left within the patch, in patch pixels.
            $srcx = ($winx - $patchx) * $pxper;
            $srcy = ($winy - $patchy) * $pyper;

            $copyleft = max(0.0, $srcx);
            $copytop = max(0.0, $srcy);
            $copyright = min((float) $srcw, $srcx + $outw);
            $copybottom = min((float) $srch, $srcy + $outh);

            if ($copyright > $copyleft && $copybottom > $copytop) {
                imagecopy(
                    $dest, $src,
                    (int) round($copyleft - $srcx), (int) round($copytop - $srcy),
                    (int) round($copyleft), (int) round($copytop),
                    (int) round($copyright - $copyleft), (int) round($copybottom - $copytop)
                );
            }

            ob_start();
            imagejpeg($dest, null, 90);
            $out = ob_get_clean();

            imagedestroy($dest);

            return $out;
        } finally {
            imagedestroy($src);
        }
    }
}
