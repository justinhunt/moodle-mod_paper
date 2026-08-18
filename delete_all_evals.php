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
 * Delete All Evaluations Script for mod_paper
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once('lib.php');

$id = required_param('id', PARAM_INT); // Course module ID

$cm = get_coursemodule_from_id('paper', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$paper = $DB->get_record('paper', array('id' => $cm->instance), '*', MUST_EXIST);

require_login($course, true, $cm);
require_capability('mod/paper:manage', context_module::instance($cm->id));
require_sesskey();

// Get all evaluations for this paper
$evaluations = $DB->get_records('paper_evaluations', ['paperid' => $paper->id], '', 'id');

if ($evaluations) {
    $evalids = array_keys($evaluations);
    list($insql, $params) = $DB->get_in_or_equal($evalids);
    
    // Delete all eval items first
    $DB->delete_records_select('paper_eval_items', "evalid $insql", $params);
    
    // Delete all evaluations
    $DB->delete_records('paper_evaluations', ['paperid' => $paper->id]);
    
    \core\notification::success("All evaluations deleted successfully.");
} else {
    \core\notification::info("No evaluations to delete.");
}

redirect(new moodle_url('/mod/paper/reports.php', ['id' => $cm->id]));
