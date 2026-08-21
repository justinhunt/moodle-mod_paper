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
 * Restore steps for mod_paper
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Structure step to restore one paper activity
 */
class restore_paper_activity_structure_step extends restore_activity_structure_step {

    protected function define_structure() {

        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('paper', '/activity/paper');
        $paths[] = new restore_path_element('paper_response_area', '/activity/paper/response_areas/response_area');

        if ($userinfo) {
            $paths[] = new restore_path_element('paper_evaluation', '/activity/paper/evaluations/evaluation');
            $paths[] = new restore_path_element(
                'paper_eval_item',
                '/activity/paper/evaluations/evaluation/eval_items/eval_item'
            );
        }

        // Return the paths wrapped into standard activity structure.
        return $this->prepare_activity_structure($paths);
    }

    protected function process_paper($data) {
        global $DB;

        $data = (object) $data;
        $data->course = $this->get_courseid();

        $newitemid = $DB->insert_record('paper', $data);
        // Immediately after inserting "activity" record, call this.
        $this->apply_activity_instance($newitemid);
    }

    protected function process_paper_response_area($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->paperid = $this->get_new_parentid('paper');

        // Backups taken before the field was renamed carry isnamefield. The values it held
        // are the same ones areatype uses, so carry it across rather than let insert_record
        // silently drop the unknown column and default every area to standard.
        if (isset($data->isnamefield) && !isset($data->areatype)) {
            $data->areatype = $data->isnamefield;
            unset($data->isnamefield);
        }

        $newitemid = $DB->insert_record('paper_response_areas', $data);
        $this->set_mapping('paper_response_area', $oldid, $newitemid);
    }

    protected function process_paper_evaluation($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->paperid = $this->get_new_parentid('paper');
        $data->userid = !empty($data->userid) ? $this->get_mappingid('user', $data->userid) : null;

        $newitemid = $DB->insert_record('paper_evaluations', $data);
        $this->set_mapping('paper_evaluation', $oldid, $newitemid);
    }

    protected function process_paper_eval_item($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->evalid = $this->get_new_parentid('paper_evaluation');
        $data->responseareaid = $this->get_mappingid('paper_response_area', $data->responseareaid);

        $newitemid = $DB->insert_record('paper_eval_items', $data);
        $this->set_mapping('paper_eval_item', $oldid, $newitemid, true); // Has related files (responsesnippet).
    }

    protected function after_execute() {
        // Add paper related files, no need to match by itemname (just internally handled context).
        $this->add_related_files('mod_paper', 'intro', null);
        $this->add_related_files('mod_paper', 'template', null);
        // Add eval item snippet files, matched by itemname (paper_eval_item).
        $this->add_related_files('mod_paper', 'responsesnippet', 'paper_eval_item');
    }
}
