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
 * Deletes one evaluation, its items, and the files those items own.
 *
 * @param object $evaluation The paper_evaluations row.
 * @param object $context The activity's module context.
 */
function paper_delete_evaluation($evaluation, $context) {
    global $DB;

    $fs = get_file_storage();
    $itemids = $DB->get_fieldset_select('paper_eval_items', 'id', 'evalid = ?', [$evaluation->id]);
    foreach ($itemids as $itemid) {
        foreach (\mod_paper\constants::M_ITEM_FILEAREAS as $filearea) {
            $fs->delete_area_files($context->id, 'mod_paper', $filearea, $itemid);
        }
    }

    $DB->delete_records('paper_eval_items', ['evalid' => $evaluation->id]);
    $DB->delete_records('paper_evaluations', ['id' => $evaluation->id]);
}

/**
 * Deletes every submission and evaluation for one paper, files included.
 *
 * Shared by the course reset and the "delete all submissions" action, and deliberately does
 * not touch paper_response_areas or the template image - those are the worksheet's
 * configuration, not student data, and survive a reset the way the backup treats them.
 *
 * @param object $paper The paper instance.
 * @param object $context The activity's module context.
 */
function paper_delete_all_evaluations($paper, $context) {
    global $DB;

    $evalids = $DB->get_fieldset_select('paper_evaluations', 'id', 'paperid = ?', [$paper->id]);
    if (!empty($evalids)) {
        [$insql, $params] = $DB->get_in_or_equal($evalids);
        $DB->delete_records_select('paper_eval_items', "evalid $insql", $params);
    }
    $DB->delete_records('paper_evaluations', ['paperid' => $paper->id]);

    // Files are keyed by eval item or by an ephemeral batch id, so clearing whole areas is
    // both simpler and safer than trying to walk ids we have just deleted.
    $fs = get_file_storage();
    foreach (\mod_paper\constants::M_USERDATA_FILEAREAS as $filearea) {
        $fs->delete_area_files($context->id, 'mod_paper', $filearea);
    }
}

/**
 * Standard Moodle plugin API: course reset form.
 *
 * Without this (and paper_reset_userdata) the activity is listed under "not supported" on the
 * course reset page and a reset silently leaves every scan and grade in place.
 */
function paper_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'paperheader', get_string('modulenameplural', 'mod_paper'));
    $resetlabel = get_string('resetevaluations', 'mod_paper');
    $mform->addElement('advcheckbox', 'reset_paper_evaluations', $resetlabel);
    $mform->addHelpButton('reset_paper_evaluations', 'resetevaluations', 'mod_paper');
}

/**
 * Standard Moodle plugin API: course reset form defaults.
 */
function paper_reset_course_form_defaults($course) {
    return ['reset_paper_evaluations' => 1];
}

/**
 * Standard Moodle plugin API: remove all grades from the gradebook for this course's papers.
 */
function paper_reset_gradebook($courseid, $type = '') {
    global $DB;

    $sql = "SELECT p.*, cm.idnumber AS cmidnumber, p.course AS courseid
              FROM {paper} p
              JOIN {course_modules} cm ON cm.instance = p.id
              JOIN {modules} m ON m.id = cm.module AND m.name = 'paper'
             WHERE p.course = ?";

    foreach ($DB->get_records_sql($sql, [$courseid]) as $paper) {
        paper_grade_item_update($paper, 'reset');
    }
}

/**
 * Standard Moodle plugin API: course reset.
 *
 * @param object $data The reset form data.
 * @return array Status lines for the reset report.
 */
function paper_reset_userdata($data) {
    global $DB;

    $status = [];

    if (empty($data->reset_paper_evaluations)) {
        return $status;
    }

    $componentstr = get_string('modulenameplural', 'mod_paper');

    foreach ($DB->get_records('paper', ['course' => $data->courseid]) as $paper) {
        $cm = get_coursemodule_from_instance('paper', $paper->id, $data->courseid, false, IGNORE_MISSING);
        if (!$cm) {
            // No course module means no context, so there are no files to clear - but the
            // rows still have to go, so fall back to deleting just those.
            $orphansql = 'evalid IN (SELECT id FROM {paper_evaluations} WHERE paperid = ?)';
            $DB->delete_records_select('paper_eval_items', $orphansql, [$paper->id]);
            $DB->delete_records('paper_evaluations', ['paperid' => $paper->id]);
            continue;
        }
        paper_delete_all_evaluations($paper, context_module::instance($cm->id));
    }

    // Skipped when the reset is already clearing all gradebook grades itself.
    if (empty($data->reset_gradebook_grades)) {
        paper_reset_gradebook($data->courseid);
    }

    $status[] = [
        'component' => $componentstr,
        'item' => get_string('resetevaluations', 'mod_paper'),
        'error' => false,
    ];

    return $status;
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
