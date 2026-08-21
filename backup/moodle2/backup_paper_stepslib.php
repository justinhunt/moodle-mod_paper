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
 * Backup steps for mod_paper
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Define the complete paper structure for backup, with file and id annotations
 */
class backup_paper_activity_structure_step extends backup_activity_structure_step {

    protected function define_structure() {

        // To know if we are including userinfo.
        $userinfo = $this->get_setting_value('userinfo');

        // Define each element separated.
        $paper = new backup_nested_element('paper', ['id'], [
            'name', 'intro', 'introformat', 'namefieldrole',
            'targetlanguage', 'targetlanguagefont', 'feedbacklanguage', 'feedbacklanguagefont',
            'grade', 'timecreated', 'timemodified', 'showtotalscore',
        ]);

        $responseareas = new backup_nested_element('response_areas');

        $responsearea = new backup_nested_element('response_area', ['id'], [
            'responsenumber', 'areatype',
            'box_x', 'box_y', 'box_w', 'box_h',
            'fb_x', 'fb_y', 'fb_w', 'fb_h',
            'question', 'correctanswer', 'correctanswermode',
            'grammarcorrections', 'feedbackgrammar', 'feedbackincorrect', 'feedbackoverall',
            'givegrade', 'maxgrade', 'gradeinstructions',
            'feedbackmode', 'gradingmode', 'feedbackinstructions',
            'responsefont', 'feedbackfont',
        ]);

        $evaluations = new backup_nested_element('evaluations');

        $evaluation = new backup_nested_element('evaluation', ['id'], [
            'userid', 'studentnametext', 'totalgrade', 'filename', 'timecreated',
        ]);

        $evalitems = new backup_nested_element('eval_items');

        $evalitem = new backup_nested_element('eval_item', ['id'], [
            'responseareaid', 'ocrtext', 'correctedtext', 'feedback', 'itemgrade',
        ]);

        // Build the tree.
        $paper->add_child($responseareas);
        $responseareas->add_child($responsearea);

        $paper->add_child($evaluations);
        $evaluations->add_child($evaluation);

        $evaluation->add_child($evalitems);
        $evalitems->add_child($evalitem);

        // Define sources.
        $paper->set_source_table('paper', ['id' => backup::VAR_ACTIVITYID]);

        // Template config (response areas) is not personal data, always included.
        $responsearea->set_source_table('paper_response_areas', ['paperid' => backup::VAR_PARENTID]);

        // Evaluations and eval items are individual student submissions, only
        // include them when we are including user info.
        if ($userinfo) {
            $evaluation->set_source_table('paper_evaluations', ['paperid' => backup::VAR_PARENTID]);
            $evalitem->set_source_table('paper_eval_items', ['evalid' => backup::VAR_PARENTID]);
        }

        // Define id annotations.
        $evaluation->annotate_ids('user', 'userid');
        $evalitem->annotate_ids('paper_response_area', 'responseareaid');

        // Define file annotations.
        $paper->annotate_files('mod_paper', 'intro', null); // This filearea hasn't itemid.
        $paper->annotate_files('mod_paper', 'template', null); // Single template image, itemid always 0.
        $evalitem->annotate_files('mod_paper', 'responsesnippet', 'id'); // Snippets, keyed by eval_item->id.

        // Return the root element (paper), wrapped into standard activity structure.
        return $this->prepare_activity_structure($paper);
    }
}
