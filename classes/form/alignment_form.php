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
 * Scan alignment form for mod_paper.
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_paper\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for the per-activity scan alignment correction.
 *
 * Every field may be left blank to inherit the site default, so the inherited value is
 * shown in each field's help text rather than pre-filled into the field itself - filling
 * it in would silently pin the activity to today's default.
 */
class alignment_form extends \moodleform {

    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        $defaults = $this->_customdata['sitedefaults'];

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $fields = [
            'alignoffsetx' => 'offsetx',
            'alignoffsety' => 'offsety',
            'alignscalex' => 'scalex',
            'alignscaley' => 'scaley',
        ];

        foreach ($fields as $name => $key) {
            $mform->addElement('text', $name, get_string($name, 'mod_paper'), ['size' => 10]);
            $mform->setType($name, PARAM_RAW_TRIMMED);
            $mform->addHelpButton($name, $name, 'mod_paper');
            $mform->addElement('static', $name . '_inherited', '',
                get_string('alignmentinherited', 'mod_paper', format_float($defaults[$key], 4, true, true)));
        }

        $this->add_action_buttons();
    }

    /**
     * Rejects anything that isn't blank or a number, and scales that would collapse the
     * snippet to nothing.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors, keyed by field name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        foreach (['alignoffsetx', 'alignoffsety', 'alignscalex', 'alignscaley'] as $name) {
            $value = $data[$name] ?? '';
            if ($value === '') {
                continue;
            }
            if (!is_numeric($value)) {
                $errors[$name] = get_string('alignmentnotnumeric', 'mod_paper');
                continue;
            }
            if (($name === 'alignscalex' || $name === 'alignscaley') && (float) $value <= 0) {
                $errors[$name] = get_string('alignmentscalepositive', 'mod_paper');
            }
        }

        return $errors;
    }
}
