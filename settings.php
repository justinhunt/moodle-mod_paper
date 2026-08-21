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
 * Admin settings for mod_paper
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configexecutable('mod_paper/ghostscriptpath',
        get_string('ghostscriptpath', 'mod_paper'),
        get_string('ghostscriptpath_desc', 'mod_paper'),
        '/usr/bin/gs'));

    $settings->add(new admin_setting_configcheckbox('mod_paper/savedebugcrops',
        get_string('savedebugcrops', 'mod_paper'),
        get_string('savedebugcrops_desc', 'mod_paper'),
        0));

    $settings->add(new admin_setting_heading('mod_paper/scanalignment_heading',
        get_string('scanalignment_heading', 'mod_paper'),
        get_string('scanalignment_heading_desc', 'mod_paper')));

    $settings->add(new admin_setting_configtext('mod_paper/croppadmm',
        get_string('croppadmm', 'mod_paper'),
        get_string('croppadmm_desc', 'mod_paper'),
        5, PARAM_FLOAT));

    $settings->add(new admin_setting_configtext('mod_paper/alignoffsetx',
        get_string('alignoffsetx', 'mod_paper'),
        get_string('alignoffsetx_desc', 'mod_paper'),
        0, PARAM_FLOAT));

    $settings->add(new admin_setting_configtext('mod_paper/alignoffsety',
        get_string('alignoffsety', 'mod_paper'),
        get_string('alignoffsety_desc', 'mod_paper'),
        0, PARAM_FLOAT));

    $settings->add(new admin_setting_configtext('mod_paper/alignscalex',
        get_string('alignscalex', 'mod_paper'),
        get_string('alignscalex_desc', 'mod_paper'),
        100, PARAM_FLOAT));

    $settings->add(new admin_setting_configtext('mod_paper/alignscaley',
        get_string('alignscaley', 'mod_paper'),
        get_string('alignscaley_desc', 'mod_paper'),
        100, PARAM_FLOAT));

    $langoptions = \mod_paper\utils::get_lang_options();

    $settings->add(new admin_setting_configselect('mod_paper/defaulttargetlanguage',
        get_string('defaulttargetlanguage', 'mod_paper'),
        get_string('defaulttargetlanguage_desc', 'mod_paper'),
        \mod_paper\constants::M_LANG_ENUS, $langoptions));

    $settings->add(new admin_setting_configselect('mod_paper/defaultfeedbacklanguage',
        get_string('defaultfeedbacklanguage', 'mod_paper'),
        get_string('defaultfeedbacklanguage_desc', 'mod_paper'),
        \mod_paper\constants::M_LANG_ENUS, $langoptions));

    $fontoptions = \mod_paper\utils::get_font_options();

    $settings->add(new admin_setting_configselect('mod_paper/defaulttargetlanguagefont',
        get_string('defaulttargetlanguagefont', 'mod_paper'),
        get_string('defaulttargetlanguagefont_desc', 'mod_paper'),
        'freemono', $fontoptions));

    $settings->add(new admin_setting_configselect('mod_paper/defaultfeedbacklanguagefont',
        get_string('defaultfeedbacklanguagefont', 'mod_paper'),
        get_string('defaultfeedbacklanguagefont_desc', 'mod_paper'),
        'freeserif', $fontoptions));

    $settings->add(new admin_setting_configpasswordunmask('mod_paper/openaicredentials',
        get_string('openaicredentials', 'mod_paper'),
        get_string('openaicredentials_desc', 'mod_paper'),
        ''));

    $settings->add(new admin_setting_heading('mod_paper/openaimodel_heading',
        get_string('openaimodel_heading', 'mod_paper'), ''));

    $modeloptions = [
        'gpt-5.6-luna' => 'gpt-5.6-luna (fastest, cheapest)',
        'gpt-5.6-terra' => 'gpt-5.6-terra (balanced)',
        'gpt-5.6-sol' => 'gpt-5.6-sol (most capable)',
        'gpt-4o' => 'gpt-4o (legacy)',
    ];

    $settings->add(new admin_setting_configselect('mod_paper/openaimodel',
        get_string('openaimodel', 'mod_paper'),
        get_string('openaimodel_desc', 'mod_paper'),
        'gpt-5.6-luna', $modeloptions));

    $reasoningoptions = [
        'none' => 'none',
        'low' => 'low',
        'medium' => 'medium',
        'high' => 'high',
        'xhigh' => 'xhigh',
        'max' => 'max',
    ];

    $settings->add(new admin_setting_configselect('mod_paper/openaireasoningeffort',
        get_string('openaireasoningeffort', 'mod_paper'),
        get_string('openaireasoningeffort_desc', 'mod_paper'),
        'low', $reasoningoptions));

    $settings->add(new admin_setting_configselect('mod_paper/openaiocrmodel',
        get_string('openaiocrmodel', 'mod_paper'),
        get_string('openaiocrmodel_desc', 'mod_paper'),
        'gpt-4o', $modeloptions));

    $settings->add(new admin_setting_configselect('mod_paper/openaiocrreasoningeffort',
        get_string('openaiocrreasoningeffort', 'mod_paper'),
        get_string('openaiocrreasoningeffort_desc', 'mod_paper'),
        'none', $reasoningoptions));

    $settings->add(new admin_setting_configtext('mod_paper/openaimaxtokens',
        get_string('openaimaxtokens', 'mod_paper'),
        get_string('openaimaxtokens_desc', 'mod_paper'),
        2000, PARAM_INT));

    $settings->add(new admin_setting_configtext('mod_paper/openaimaxtokensbatch',
        get_string('openaimaxtokensbatch', 'mod_paper'),
        get_string('openaimaxtokensbatch_desc', 'mod_paper'),
        8000, PARAM_INT));

    $settings->add(new admin_setting_configtext('mod_paper/openaiconcurrency',
        get_string('openaiconcurrency', 'mod_paper'),
        get_string('openaiconcurrency_desc', 'mod_paper'),
        16, PARAM_INT));

    $settings->add(new admin_setting_configtext('mod_paper/openaitimeout',
        get_string('openaitimeout', 'mod_paper'),
        get_string('openaitimeout_desc', 'mod_paper'),
        120, PARAM_INT));

    $settings->add(new admin_setting_heading('mod_paper/gradingpresets_heading',
        get_string('gradingpresets', 'mod_paper'), ''));

    $settings->add(new admin_setting_configtext('mod_paper/gradingprompt_1_name',
        get_string('gradingprompt_name', 'mod_paper', 1),
        '', 'Standard'));
    $settings->add(new admin_setting_configtextarea('mod_paper/gradingprompt_1_content',
        get_string('gradingprompt_content', 'mod_paper', 1),
        '', 'Deduct 1 point for each grammatical or spelling mistake or inappropriate expression.'));

    $settings->add(new admin_setting_configtext('mod_paper/gradingprompt_2_name',
        get_string('gradingprompt_name', 'mod_paper', 2),
        '', 'Comprehensive'));
    $settings->add(new admin_setting_configtextarea('mod_paper/gradingprompt_2_content',
        get_string('gradingprompt_content', 'mod_paper', 2),
        '', 'For each error in grammar or spelling, deduct 1 point.  For inappropriate expression, deduct 2 points.  For incoherence, deduct 3 points.  For irrelevance, deduct 4 points.  For lack of argumentation or poor reasoning, deduct 5 points.  For inappropriate or insufficient documentation, deduct 1 point.  For formatting or presentation issues, deduct 1 point.  '));

    $settings->add(new admin_setting_heading('mod_paper/feedbackpresets_heading',
        get_string('feedbackpresets', 'mod_paper'), ''));

    $settings->add(new admin_setting_configtext('mod_paper/feedbackprompt_1_name',
        get_string('feedbackprompt_name', 'mod_paper', 1),
        '', 'Standard'));
    $settings->add(new admin_setting_configtextarea('mod_paper/feedbackprompt_1_content',
        get_string('feedbackprompt_content', 'mod_paper', 1),
        '', 'For full marks say "Excellent", for partial marks say "Very good", for a score of 0 say "Incorrect"'));

    $settings->add(new admin_setting_configtext('mod_paper/feedbackprompt_2_name',
        get_string('feedbackprompt_name', 'mod_paper', 2),
        '', ''));
    $settings->add(new admin_setting_configtextarea('mod_paper/feedbackprompt_2_content',
        get_string('feedbackprompt_content', 'mod_paper', 2),
        '', ''));
}
