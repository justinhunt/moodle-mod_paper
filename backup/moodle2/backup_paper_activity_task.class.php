<?php
/**
 * Defines backup_paper_activity_task
 *
 * @package    mod_paper
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/paper/backup/moodle2/backup_paper_stepslib.php');

/**
 * Provides the steps to perform one complete backup of the Paper instance
 */
class backup_paper_activity_task extends backup_activity_task {

    /**
     * No specific settings for this activity
     */
    protected function define_my_settings() {
    }

    /**
     * Defines a backup step to store the instance data in the paper.xml file
     */
    protected function define_my_steps() {
        $this->add_step(new backup_paper_activity_structure_step('paper_structure', 'paper.xml'));
    }

    /**
     * Encodes URLs to view.php and reports.php scripts
     *
     * @param string $content some HTML text that eventually contains URLs to the activity instance scripts
     * @return string the content with the URLs encoded
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, "/");

        // Link to paper view by moduleid.
        $search = '/(' . $base . '\/mod\/paper\/view.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@PAPERVIEWBYID*$2@$', $content);

        return $content;
    }
}
