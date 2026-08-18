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
        'courier', $fontoptions));

    $settings->add(new admin_setting_configselect('mod_paper/defaultfeedbacklanguagefont',
        get_string('defaultfeedbacklanguagefont', 'mod_paper'),
        get_string('defaultfeedbacklanguagefont_desc', 'mod_paper'),
        'freesans', $fontoptions));

    $settings->add(new admin_setting_configpasswordunmask('mod_paper/openaicredentials',
        get_string('openaicredentials', 'mod_paper'),
        get_string('openaicredentials_desc', 'mod_paper'),
        ''));

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
