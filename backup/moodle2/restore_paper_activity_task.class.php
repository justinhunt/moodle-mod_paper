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
 * Defines restore_paper_activity_task
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/paper/backup/moodle2/restore_paper_stepslib.php');

/**
 * paper restore task that provides all the settings and steps to perform one
 * complete restore of the activity
 */
class restore_paper_activity_task extends restore_activity_task {

    /**
     * No specific settings for this activity
     */
    protected function define_my_settings() {
    }

    /**
     * Paper only has one structure step
     */
    protected function define_my_steps() {
        $this->add_step(new restore_paper_activity_structure_step('paper_structure', 'paper.xml'));
    }

    /**
     * Define the contents in the activity that must be processed by the link decoder
     */
    public static function define_decode_contents() {
        $contents = [];

        $contents[] = new restore_decode_content('paper', ['intro'], 'paper');

        return $contents;
    }

    /**
     * Define the decoding rules for links belonging to the activity
     */
    public static function define_decode_rules() {
        $rules = [];

        $rules[] = new restore_decode_rule('PAPERVIEWBYID', '/mod/paper/view.php?id=$1', 'course_module');

        return $rules;
    }

    /**
     * Define the restore log rules that will be applied by the restore_logs_processor
     * when restoring paper logs.
     */
    public static function define_restore_log_rules() {
        $rules = [];

        $rules[] = new restore_log_rule('paper', 'add', 'view.php?id={course_module}', '{paper}');
        $rules[] = new restore_log_rule('paper', 'update', 'view.php?id={course_module}', '{paper}');
        $rules[] = new restore_log_rule('paper', 'view', 'view.php?id={course_module}', '{paper}');

        return $rules;
    }
}
