<?php
/**
 * Re-evaluate Submissions Script for mod_paper
 *
 * @package    mod_paper
 * @copyright  2024 Your Name
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

// Clear existing correctedtext, feedback and grade for all evaluations of this paper
$sql = "UPDATE {paper_eval_items}
        SET correctedtext = '', feedback = '', itemgrade = NULL
        WHERE evalid IN (
            SELECT id FROM {paper_evaluations} WHERE paperid = :paperid
        )";
$DB->execute($sql, ['paperid' => $paper->id]);

// Queue the evaluation task
$evaltask = new \mod_paper\task\evaluate_submissions_task();
$evaltask->set_custom_data([
    'paperid' => $paper->id
]);
\core\task\manager::queue_adhoc_task($evaltask);

\core\notification::success("Cleared existing evaluations and queued background task to re-evaluate all submissions.");
redirect(new moodle_url('/mod/paper/reports.php', ['id' => $cm->id]));
