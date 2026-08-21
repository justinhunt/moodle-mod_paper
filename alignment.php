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
 * Scan alignment page for mod_paper.
 *
 * Lets a teacher correct for the displacement between the worksheet as printed and the
 * worksheet as scanned back in, which decides where display-only snippets are cut from
 * their stored crops.
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once('lib.php');

$id = required_param('id', PARAM_INT); // Course module ID.
$reset = optional_param('reset', 0, PARAM_INT);
$detect = optional_param('detect', 0, PARAM_INT);
$previewevalid = optional_param('previewevalid', 0, PARAM_INT); // Which evaluation to preview.

$cm = get_coursemodule_from_id('paper', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$paper = $DB->get_record('paper', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/paper:manage', $context);

$PAGE->set_url('/mod/paper/alignment.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('scanalignment', 'mod_paper'));
$PAGE->set_heading(format_string($course->fullname));

$returnurl = new moodle_url('/mod/paper/reports.php', ['id' => $cm->id]);
$thisurl = new moodle_url('/mod/paper/alignment.php', ['id' => $cm->id]);

// The preview shows a real generated evaluation PDF, so the teacher can see the effect of a
// change without leaving for reports.php and coming back. Carry the chosen one through every
// redirect on this page, otherwise saving would silently reset the preview to the first student.
$evaluations = $DB->get_records('paper_evaluations', ['paperid' => $paper->id], 'id ASC', 'id, studentnametext');
if ($previewevalid && !isset($evaluations[$previewevalid])) {
    $previewevalid = 0;
}
if (!$previewevalid && !empty($evaluations)) {
    $previewevalid = reset($evaluations)->id;
}
if ($previewevalid) {
    $thisurl->param('previewevalid', $previewevalid);
}

if ($reset) {
    require_sesskey();
    $DB->update_record('paper', (object) [
        'id' => $paper->id,
        'alignoffsetx' => null,
        'alignoffsety' => null,
        'alignscalex' => null,
        'alignscaley' => null,
    ]);
    \core\notification::success(get_string('alignmentreset', 'mod_paper'));
    redirect($thisurl);
}

$sitedefaults = [
    'offsetx' => (float) get_config('mod_paper', 'alignoffsetx'),
    'offsety' => (float) get_config('mod_paper', 'alignoffsety'),
    'scalex' => (float) get_config('mod_paper', 'alignscalex'),
    'scaley' => (float) get_config('mod_paper', 'alignscaley'),
];

// Detection only reads stored images, so it is safe to run inline on a GET rather than
// needing its own post-and-redirect.
$detector = new \mod_paper\alignment_detector($paper);
$candetect = $detector->can_detect();
$detection = null;
if ($detect) {
    require_sesskey();
    if ($candetect) {
        $detection = $detector->detect();
    }
    if ($detection === null) {
        \core\notification::error(get_string('alignmentdetectfailed', 'mod_paper'));
    } else {
        \core\notification::info(get_string('alignmentdetected', 'mod_paper'));
    }
}

$mform = new \mod_paper\form\alignment_form($thisurl->out(false), ['sitedefaults' => $sitedefaults]);

if ($detection !== null) {
    // Offered as a suggestion in the form, not written to the database - the teacher
    // reviews the numbers and saves them, or discards them by navigating away.
    // Offsets are rounded to a hundredth of a millimetre before being offered: the scan is
    // 150dpi, so a pixel is already 0.17mm, and anything finer is noise the teacher would
    // have to read past.
    $mform->set_data([
        'id' => $cm->id,
        'alignoffsetx' => round($detection['offsetx'], 2),
        'alignoffsety' => round($detection['offsety'], 2),
        'alignscalex' => $detection['scalex'],
        'alignscaley' => $detection['scaley'],
    ]);
} else {
    // A NULL column means "inherit", which the form shows as an empty field rather than as 0.
    $mform->set_data([
        'id' => $cm->id,
        'alignoffsetx' => $paper->alignoffsetx === null ? '' : $paper->alignoffsetx,
        'alignoffsety' => $paper->alignoffsety === null ? '' : $paper->alignoffsety,
        'alignscalex' => $paper->alignscalex === null ? '' : $paper->alignscalex,
        'alignscaley' => $paper->alignscaley === null ? '' : $paper->alignscaley,
    ]);
}

if ($mform->is_cancelled()) {
    redirect($returnurl);
} else if ($data = $mform->get_data()) {
    $update = (object) ['id' => $paper->id];
    foreach (['alignoffsetx', 'alignoffsety', 'alignscalex', 'alignscaley'] as $name) {
        $value = $data->$name ?? '';
        $update->$name = ($value === '') ? null : (float) $value;
    }
    $DB->update_record('paper', $update);
    \core\notification::success(get_string('alignmentsaved', 'mod_paper'));
    // Back to this page rather than to reports, so the preview re-renders with the new
    // numbers and the teacher can keep adjusting.
    redirect($thisurl);
}

$reseturl = clone $thisurl;
$reseturl->params(['reset' => 1, 'sesskey' => sesskey()]);
$detecturl = clone $thisurl;
$detecturl->params(['detect' => 1, 'sesskey' => sesskey()]);

$templatecontext = [
    'formhtml' => $mform->render(),
    'reseturl' => $reseturl->out(false),
    'detecturl' => $detecturl->out(false),
    'candetect' => $candetect,
    'returntoreportsurl' => $returnurl->out(false),
    'croppadmm' => format_float((float) get_config('mod_paper', 'croppadmm'), 2, true, true),
    'hasevaluations' => !empty($evaluations),
];

if (!empty($evaluations)) {
    // Both the selector's options and the frame's initial source need this, and the selector
    // carries a URL per option so switching student does not have to reload the whole page.
    $previewurlfor = static function ($evalid) use ($context) {
        return moodle_url::make_pluginfile_url(
            $context->id,
            'mod_paper',
            'downloadevaluations',
            $evalid,
            '/',
            'evaluation.pdf'
        )->out(false);
    };

    $previewoptions = [];
    foreach ($evaluations as $evaluation) {
        $name = trim($evaluation->studentnametext ?? '');
        $previewoptions[] = [
            'value' => $evaluation->id,
            // A submission whose name area failed to OCR still has to be pickable.
            'label' => ($name !== '') ? $name : get_string('evaluationnumber', 'mod_paper', $evaluation->id),
            'selected' => ($evaluation->id == $previewevalid),
            'url' => $previewurlfor($evaluation->id),
        ];
    }

    $templatecontext['previewoptions'] = $previewoptions;
    $templatecontext['previewurl'] = $previewurlfor($previewevalid);
}

if ($detection !== null) {
    // The per-band displacements are what the fit was drawn through, so showing them lets
    // a teacher see for themselves whether an axis really agreed on a trend.
    $bands = static function(array $values) {
        $parts = [];
        foreach ($values as $value) {
            $parts[] = ($value === null) ? '-' : sprintf('%+d', $value);
        }
        return implode(', ', $parts) . 'px';
    };

    $templatecontext['detection'] = [
        'offsetx' => format_float($detection['offsetx'], 2, true, true),
        'offsety' => format_float($detection['offsety'], 2, true, true),
        'scalex' => format_float($detection['scalex'], 4, true, true),
        'scaley' => format_float($detection['scaley'], 4, true, true),
        'bandsx' => $bands($detection['bandsx']),
        'bandsy' => $bands($detection['bandsy']),
        'reliablex' => $detection['reliablex'],
        'reliabley' => $detection['reliabley'],
        'anyunreliable' => (!$detection['reliablex'] || !$detection['reliabley']),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('scanalignmentfor', 'mod_paper', format_string($paper->name)));
echo $OUTPUT->render_from_template('mod_paper/alignment_page', $templatecontext);

if (!empty($evaluations)) {
    $PAGE->requires->js_call_amd('mod_paper/alignment', 'init');
}

echo $OUTPUT->footer();
