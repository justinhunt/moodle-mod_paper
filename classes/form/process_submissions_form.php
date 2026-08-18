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
 * Process Submissions Form for mod_paper
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_paper\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class process_submissions_form extends \moodleform {

    public function definition() {
        $mform = $this->_form;
        
        $mform->addElement('header', 'general', get_string('submissions', 'mod_paper'));

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        if (isset($this->_customdata['id'])) {
            $mform->setConstant('id', $this->_customdata['id']);
        }

        $filemanageropts = [
            'subdirs' => 0,
            'maxbytes' => 0, // Admin limit
            'maxfiles' => 50,
            'accepted_types' => ['.pdf', '.jpg', '.jpeg', '.png']
        ];
        
        $mform->addElement('filemanager', 'submissions_filemanager', get_string('submissions', 'mod_paper'), null, $filemanageropts);
        $mform->addRule('submissions_filemanager', null, 'required', null, 'client');

        $this->add_action_buttons(false, get_string('processsubmissions', 'mod_paper'));
    }
}
