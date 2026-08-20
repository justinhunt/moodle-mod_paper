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
 * Library of functions for module paper
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Standard Moodle plugin API: Add instance
 */
function paper_add_instance($paper, $mform = null) {
    global $DB;

    $paper->timecreated = time();
    $paper->timemodified = $paper->timecreated;

    $paper->id = $DB->insert_record('paper', $paper);

    paper_grade_item_update($paper);

    return $paper->id;
}

/**
 * Standard Moodle plugin API: Update instance
 */
function paper_update_instance($paper, $mform = null) {
    global $DB;

    $paper->timemodified = time();
    $paper->id = $paper->instance;

    $DB->update_record('paper', $paper);

    $paper = $DB->get_record('paper', ['id' => $paper->id]);
    paper_grade_item_update($paper);

    return true;
}

/**
 * Standard Moodle plugin API: Delete instance
 */
function paper_delete_instance($id) {
    global $DB;

    if (!$paper = $DB->get_record('paper', ['id' => $id])) {
        return false;
    }

    // Delete items
    $evaluations = $DB->get_records('paper_evaluations', ['paperid' => $id]);
    foreach ($evaluations as $eval) {
        $DB->delete_records('paper_eval_items', ['evalid' => $eval->id]);
    }

    // Delete evaluations and response areas
    $DB->delete_records('paper_evaluations', ['paperid' => $id]);
    $DB->delete_records('paper_response_areas', ['paperid' => $id]);

    // Finally delete paper instance
    $DB->delete_records('paper', ['id' => $id]);

    paper_grade_item_delete($paper);

    return true;
}

/**
 * Define module features
 */
function paper_supports($feature) {
    switch ($feature) {
        case FEATURE_GROUPS:                  return true;
        case FEATURE_GROUPINGS:               return true;
        case FEATURE_MOD_INTRO:               return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return true;
        case FEATURE_COMPLETION_HAS_RULES:    return true;
        case FEATURE_GRADE_HAS_GRADE:         return true;
        case FEATURE_GRADE_OUTCOMES:          return true;
        case FEATURE_BACKUP_MOODLE2:          return true;
        case FEATURE_SHOW_DESCRIPTION:        return true;
        default: return null;
    }
}

/**
 * Create/update grade item
 */
function paper_grade_item_update($paper, $grades = null) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    $params = [
        'itemname' => $paper->name,
        'idnumber' => $paper->course,
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax' => $paper->grade,
        'grademin' => 0
    ];

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update('mod/paper', $paper->course, 'mod', 'paper', $paper->id, 0, $grades, $params);
}

/**
 * Delete grade item
 */
function paper_grade_item_delete($paper) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    return grade_update('mod/paper', $paper->course, 'mod', 'paper', $paper->id, 0, null, ['deleted' => 1]);
}

/**
 * Handle dynamic file serving via pluginfile hook
 */
function paper_pluginfile($course, $cm, $context, $filearea, array $args, $forcedownload, array $options = []) {
    global $DB;

    if ($context->contextlevel != CONTEXT_MODULE) {
        send_file_not_found();
    }

    if ($filearea === 'downloadevaluations') {
        require_login($course, false, $cm);
        require_capability('mod/paper:manage', $context);
        
        $paper = $DB->get_record('paper', ['id' => $cm->instance], '*', MUST_EXIST);
        
        $evalid = (int) array_shift($args);
        if ($evalid > 0) {
            $evaluations = $DB->get_records('paper_evaluations', ['id' => $evalid]);
            $filename = 'evaluation_' . $evalid . '.pdf';
            $includesummary = false;
        } else {
            $evaluations = $DB->get_records('paper_evaluations', ['paperid' => $paper->id]);
            $filename = 'evaluations_' . $paper->id . '.pdf';
            $includesummary = true;
        }

        if (empty($evaluations)) {
            send_file_not_found();
        }

        $pdfprocessor = new \mod_paper\pdf_processor();
        $pdf_binary = $pdfprocessor->generate_evaluations_pdf($paper, $evaluations, $context, $includesummary);
        
        send_file($pdf_binary, $filename, 0, 0, true, false, 'application/pdf');
        return;
    }

    // Default handling for other files (like template or snippets)
    $fs = get_file_storage();
    
    if ($filearea === 'responsesnippet') {
        require_login($course, false, $cm);
        require_capability('mod/paper:manage', $context);

        $itemid = (int) array_shift($args);
        $filename = array_shift($args);
        $file = $fs->get_file($context->id, 'mod_paper', $filearea, $itemid, '/', $filename);
        if (!$file) {
            send_file_not_found();
        }

        // The stored patch is cropped wider than the response area so the scan alignment
        // stays adjustable after processing - cut the area's own region back out of it
        // before serving, so callers can draw the result at the area's coordinates.
        $item = $DB->get_record('paper_eval_items', ['id' => $itemid]);
        $area = $item ? $DB->get_record('paper_response_areas', ['id' => $item->responseareaid]) : false;

        if (!$item || !$area) {
            send_stored_file($file, 0, 0, true);
            return;
        }

        $paper = $DB->get_record('paper', ['id' => $cm->instance], '*', MUST_EXIST);
        $alignment = \mod_paper\utils::get_scan_alignment($paper);
        $windowed = \mod_paper\utils::window_snippet($file->get_content(), $item, $area, $alignment);

        send_file($windowed, $filename, 0, 0, true, false, 'image/jpeg');
        return;
    }

    if ($filearea === 'areacrop') {
        require_login($course, false, $cm);
        require_capability('mod/paper:manage', $context);

        // Served untouched: the Developer page exists to show what was actually cropped.
        $itemid = (int) array_shift($args);
        $filename = array_shift($args);
        $file = $fs->get_file($context->id, 'mod_paper', $filearea, $itemid, '/', $filename);
        if (!$file) {
            send_file_not_found();
        }
        send_stored_file($file, 0, 0, true);
        return;
    }

    $itemid = (int) array_shift($args);
    if (empty($args)) {
        $filepath = '/';
        $filename = '.';
    } else {
        $filename = array_pop($args);
        $filepath = empty($args) ? '/' : '/' . implode('/', $args) . '/';
    }
    
    $file = $fs->get_file($context->id, 'mod_paper', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        send_file_not_found();
    }
    
    send_stored_file($file, 0, 0, $forcedownload, $options);
}
