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
 * Presets Management for mod_paper.
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->dirroot . '/mod/paper/lib.php');

$id = required_param('id', PARAM_INT);
$action = optional_param('action', 'list', PARAM_ALPHA);
$presetid = optional_param('presetid', 0, PARAM_INT);
// Type: 'grading' or 'feedback'.
$type = optional_param('type', 'grading', PARAM_ALPHA);
if (!in_array($type, ['grading', 'feedback'])) {
    $type = 'grading';
}

$cm = get_coursemodule_from_id('paper', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$paper = $DB->get_record('paper', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/paper:manage', $context);

$PAGE->set_url('/mod/paper/presets.php', ['id' => $cm->id, 'type' => $type]);
$PAGE->set_title(get_string('managepresets', 'mod_paper'));
$PAGE->set_heading($course->fullname);

$baseurl = new moodle_url('/mod/paper/presets.php', ['id' => $id]);
$gradingbaseurl = new moodle_url('/mod/paper/presets.php', ['id' => $id, 'type' => 'grading']);
$feedbackbaseurl = new moodle_url('/mod/paper/presets.php', ['id' => $id, 'type' => 'feedback']);

// --- CRUD actions ---

// Grading preset delete.
if ($type === 'grading' && $action === 'delete' && $presetid > 0 && confirm_sesskey()) {
    $DB->delete_records('paper_grading_presets', ['id' => $presetid]);
    redirect($gradingbaseurl, get_string('presetdeleted', 'mod_paper'));
}

// Feedback preset delete.
if ($type === 'feedback' && $action === 'delete' && $presetid > 0 && confirm_sesskey()) {
    $DB->delete_records('paper_feedback_presets', ['id' => $presetid]);
    redirect($feedbackbaseurl, get_string('feedbackpresetdeleted', 'mod_paper'));
}

// --- EDIT / ADD ---

if ($action === 'edit' || $action === 'add') {
    if ($type === 'grading') {
        $table = 'paper_grading_presets';
        $formclass = '\\mod_paper\\form\\preset_form';
        $sectiontitle = get_string('managegradingpresets', 'mod_paper');
        $backurl = $gradingbaseurl;
    } else {
        $table = 'paper_feedback_presets';
        $formclass = '\\mod_paper\\form\\feedback_preset_form';
        $sectiontitle = get_string('managefeedbackpresets', 'mod_paper');
        $backurl = $feedbackbaseurl;
    }

    if ($presetid > 0) {
        $preset = $DB->get_record($table, ['id' => $presetid]);
        $data = $preset;
    } else {
        $data = new stdClass();
    }

    $editurl = new moodle_url('/mod/paper/presets.php', ['id' => $id, 'type' => $type, 'action' => $action, 'presetid' => $presetid]);
    $mform = new $formclass($editurl->out(false), ['action' => $action, 'presetid' => $presetid]);
    $data->presetid = $presetid; // Populate the hidden field; DB record uses 'id' not 'presetid'.
    $mform->set_data($data);

    if ($mform->is_cancelled()) {
        redirect($backurl);
    } else if ($formdata = $mform->get_data()) {
        $formdata->userid = $USER->id;
        if ($action === 'add') {
            $formdata->timecreated = time();
            $formdata->timemodified = time();
            $DB->insert_record($table, $formdata);
        } else {
            $formdata->id = $presetid;
            $formdata->timemodified = time();
            $DB->update_record($table, $formdata);
        }
        redirect($backurl);
    }

    // Display the form they requested.
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('managepresetsfor', 'mod_paper', format_string($paper->name)));
    echo $OUTPUT->heading($sectiontitle);
    $mform->display();

} else {
    // List view — show both sections.
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('managepresetsfor', 'mod_paper', format_string($paper->name)));

    // ---- GRADING PRESETS SECTION ----
    echo $OUTPUT->heading(get_string('managegradingpresets', 'mod_paper'), 3);
    echo get_string('managegradingpresetsinstructions', 'mod_paper');

    $gradingpresets = $DB->get_records('paper_grading_presets', ['userid' => $USER->id], 'name ASC');
    if (empty($gradingpresets)) {
        echo $OUTPUT->notification(get_string('nopresetsfound', 'mod_paper'), 'info');
    } else {
        $gtable = new html_table();
        $gtable->head = [
            get_string('presetid', 'mod_paper'),
            get_string('presetname', 'mod_paper'),
            get_string('actions', 'mod_paper'),
        ];
        foreach ($gradingpresets as $p) {
            $editurl = new moodle_url($gradingbaseurl, ['action' => 'edit', 'presetid' => $p->id]);
            $deleteurl = new moodle_url($gradingbaseurl, ['action' => 'delete', 'presetid' => $p->id, 'sesskey' => sesskey()]);
            $editlink = html_writer::link($editurl, $OUTPUT->pix_icon('t/edit', get_string('edit')), ['class' => 'mr-2']);
            $deletelink = html_writer::link($deleteurl, $OUTPUT->pix_icon('t/delete', get_string('delete')), [
                'class' => 'text-danger',
                'onclick' => "return confirm('" . get_string('deletepresetconfirm', 'mod_paper') . "');",
            ]);
            $gtable->data[] = [$p->id, format_string($p->name), $editlink . $deletelink];
        }
        echo html_writer::table($gtable);
    }
    $addgradingurl = new moodle_url($gradingbaseurl, ['action' => 'add']);
    echo html_writer::link($addgradingurl, get_string('addnewpreset', 'mod_paper'), ['class' => 'btn btn-secondary mt-2 mb-4']);

    echo html_writer::empty_tag('hr');

    // ---- FEEDBACK PRESETS SECTION ----
    echo $OUTPUT->heading(get_string('managefeedbackpresets', 'mod_paper'), 3);
    echo get_string('managefeedbackpresetsinstructions', 'mod_paper');

    $feedbackpresets = $DB->get_records('paper_feedback_presets', ['userid' => $USER->id], 'name ASC');
    if (empty($feedbackpresets)) {
        echo $OUTPUT->notification(get_string('nofeedbackpresetsfound', 'mod_paper'), 'info');
    } else {
        $ftable = new html_table();
        $ftable->head = [
            get_string('presetid', 'mod_paper'),
            get_string('presetname', 'mod_paper'),
            get_string('actions', 'mod_paper'),
        ];
        foreach ($feedbackpresets as $p) {
            $editurl = new moodle_url($feedbackbaseurl, ['action' => 'edit', 'presetid' => $p->id]);
            $deleteurl = new moodle_url($feedbackbaseurl, ['action' => 'delete', 'presetid' => $p->id, 'sesskey' => sesskey()]);
            $editlink = html_writer::link($editurl, $OUTPUT->pix_icon('t/edit', get_string('edit')), ['class' => 'mr-2']);
            $deletelink = html_writer::link($deleteurl, $OUTPUT->pix_icon('t/delete', get_string('delete')), [
                'class' => 'text-danger',
                'onclick' => "return confirm('" . get_string('deletepresetconfirm', 'mod_paper') . "');",
            ]);
            $ftable->data[] = [$p->id, format_string($p->name), $editlink . $deletelink];
        }
        echo html_writer::table($ftable);
    }
    $addfeedbackurl = new moodle_url($feedbackbaseurl, ['action' => 'add']);
    echo html_writer::link($addfeedbackurl, get_string('addnewfeedbackpreset', 'mod_paper'), ['class' => 'btn btn-secondary mt-2']);
}

$viewurl = new moodle_url('/mod/paper/view.php', ['id' => $cm->id]);
echo html_writer::link($viewurl, get_string('returntotop', 'mod_paper'), ['class' => 'btn btn-link d-block mt-3']);

echo $OUTPUT->footer();
