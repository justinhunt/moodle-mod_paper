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
 * mod_form.php for mod_paper
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/course/moodleform_mod.php');

class mod_paper_mod_form extends moodleform_mod {

    public function definition() {
        global $CFG, $COURSE;

        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        $mform->addElement('header', 'papersettings', get_string('papersettings', 'mod_paper'));

        $options = [
            1 => get_string('yes'),
            0 => get_string('no')
        ];
        $mform->addElement('select', 'namefieldrole', get_string('enablemoodleusername', 'mod_paper'), $options);
        $mform->addHelpButton('namefieldrole', 'enablemoodleusername', 'mod_paper');
        $mform->setDefault('namefieldrole', 0);

        $langoptions = \mod_paper\utils::get_lang_options();
        $fontoptions = \mod_paper\utils::get_font_options();

        $mform->addElement('select', 'targetlanguage', get_string('targetlanguage', 'mod_paper'), $langoptions);
        $mform->addHelpButton('targetlanguage', 'targetlanguage', 'mod_paper');
        $mform->setDefault('targetlanguage', get_config('mod_paper', 'defaulttargetlanguage') ?: \mod_paper\constants::M_LANG_ENUS);

        $mform->addElement('select', 'targetlanguagefont', get_string('targetlanguagefont', 'mod_paper'), $fontoptions);
        $mform->addHelpButton('targetlanguagefont', 'targetlanguagefont', 'mod_paper');
        $mform->setDefault('targetlanguagefont', get_config('mod_paper', 'defaulttargetlanguagefont') ?: 'freemono');

        $mform->addElement('select', 'feedbacklanguage', get_string('feedbacklanguage', 'mod_paper'), $langoptions);
        $mform->addHelpButton('feedbacklanguage', 'feedbacklanguage', 'mod_paper');
        $mform->setDefault('feedbacklanguage', get_config('mod_paper', 'defaultfeedbacklanguage') ?: \mod_paper\constants::M_LANG_ENUS);

        $mform->addElement('select', 'feedbacklanguagefont', get_string('feedbacklanguagefont', 'mod_paper'), $fontoptions);
        $mform->addHelpButton('feedbacklanguagefont', 'feedbacklanguagefont', 'mod_paper');
        $mform->setDefault('feedbacklanguagefont', get_config('mod_paper', 'defaultfeedbacklanguagefont') ?: 'freeserif');

        $mform->addElement('select', 'showtotalscore', get_string('showtotalscore', 'mod_paper'), $options);
        $mform->addHelpButton('showtotalscore', 'showtotalscore', 'mod_paper');
        $mform->setDefault('showtotalscore', 1);

        $this->standard_grading_coursemodule_elements();
        
        $this->standard_coursemodule_elements();

        $this->add_action_buttons();
    }
}
