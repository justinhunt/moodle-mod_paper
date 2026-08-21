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
 * Strings for component 'Poodll Paper'.
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['modulename'] = 'Poodll Paper';
$string['modulename_help'] = 'A Moodle activity plugin that gives corrections, feedback and optionally a score on student’s written assignments or worksheets.';
$string['modulenameplural'] = 'Poodll Papers';
$string['pluginname'] = 'Poodll Paper';
$string['pluginadministration'] = 'Poodll Paper administration';

$string['enablemoodleusername'] = 'Enable Moodle username';
$string['enablemoodleusername_help'] = 'If enabled you can specify one of the response areas as a Moodle username field. Then scores can be added to the gradebook and users can see their own results.';
$string['username'] = 'Moodle username';
$string['freetext'] = 'Free text';
$string['targetlanguage'] = 'Target language';
$string['targetlanguage_help'] = 'The language the student is writing in.';
$string['targetlanguagefont'] = 'Target language font';
$string['targetlanguagefont_help'] = 'The font used to display the student\'s writing on the PDF and web view.';
$string['feedbacklanguage'] = 'Native language';
$string['feedbacklanguage_help'] = 'The language feedback should be given in.';
$string['feedbacklanguagefont'] = 'Native language font';
$string['feedbacklanguagefont_help'] = 'The font used to display feedback on the PDF and web view.';

$string['font_freeserif'] = 'FreeSerif (Serif, widest language support)';
$string['font_freesans'] = 'FreeSans (Sans-Serif, no Arabic/Thai/Tamil)';
$string['font_freemono'] = 'FreeMono (Monospace, no Thai/Tamil/Hindi)';
$string['font_courier'] = 'Courier (Monospace, Western only)';
$string['font_helvetica'] = 'Helvetica (Sans-Serif, Western only)';
$string['font_times'] = 'Times (Serif, Western only)';
$string['font_kozminproregular'] = 'KozMinProRegular (Japanese Serif)';
$string['font_kozgopromedium'] = 'KozGoProMedium (Japanese Sans-Serif)';
$string['font_stsongstdlight'] = 'STSongStdLight (Chinese Simplified)';
$string['font_msungstdlight'] = 'MSungStdLight (Chinese Traditional)';
$string['font_hysmyeongjostdmedium'] = 'HYSMyeongJoStdMedium (Korean)';
$string['font_inherit_target'] = 'Target language font - {$a}';
$string['font_inherit_native'] = 'Native language font - {$a}';

// Per response area fonts.
$string['areafonts'] = 'Fonts';
$string['areafonts_help'] = 'Which font this area is printed in. By default the student\'s writing uses the activity\'s target language font and the feedback uses its native language font. Override them here if this area holds something in a different script - a name written in Japanese, for example, cannot be printed in a Latin-only font and comes out as question marks.';
$string['responsefont'] = 'Student text font';
$string['feedbackfont'] = 'Feedback text font';

// Admin settings
$string['paper:addinstance'] = 'Add a new Paper activity';
$string['paper:view'] = 'View Paper activity';
$string['paper:manage'] = 'Manage Paper activity';
$string['ghostscriptpath'] = 'Ghostscript path';
$string['ghostscriptpath_desc'] = 'Path to the ghostscript executable (e.g. /usr/bin/gs)';
$string['openaicredentials'] = 'OpenAI API Key';
$string['openaicredentials_desc'] = 'Your OpenAI API Key for processing images and generating feedback.';
$string['openaimodel_heading'] = 'OpenAI model settings';
$string['openaimodel'] = 'Grading model';
$string['openaimodel_desc'] = 'The OpenAI model used for grading, grammar correction and feedback generation. gpt-5.6-* models are reasoning models; gpt-4o is kept as a legacy fallback. See "OCR model" below for the separate setting used for reading student handwriting.';
$string['openaireasoningeffort'] = 'Grading reasoning effort';
$string['openaireasoningeffort_desc'] = 'How much internal reasoning the grading model spends per call. Ignored for non-reasoning models (e.g. gpt-4o). Higher effort can improve grading accuracy but costs more tokens and time.';
$string['openaiocrmodel'] = 'OCR model';
$string['openaiocrmodel_desc'] = 'The OpenAI model used to read student handwriting from worksheet images. Kept separate from the grading model above because vision/transcription accuracy and grading quality don\'t necessarily come from the same model - some models "helpfully" correct spelling/grammar mistakes during transcription, which defeats the purpose of grading them later.';
$string['openaiocrreasoningeffort'] = 'OCR reasoning effort';
$string['openaiocrreasoningeffort_desc'] = 'How much internal reasoning the OCR model spends per call. Ignored for non-reasoning models (e.g. gpt-4o). For transcription, lower effort (or none) tends to produce more literal, verbatim results - higher effort can make the model more prone to "fixing" mistakes instead of transcribing them.';
$string['openaimaxtokens'] = 'Max tokens (normal calls)';
$string['openaimaxtokens_desc'] = 'Maximum output tokens for single-item calls (OCR extraction, single evaluation). For reasoning models this budget also covers internal reasoning tokens, so set it generously.';
$string['openaimaxtokensbatch'] = 'Max tokens (batch calls)';
$string['openaimaxtokensbatch_desc'] = 'Maximum output tokens for batch calls that process multiple responses in one request (batch grading, batch grammar correction). For reasoning models this budget also covers internal reasoning tokens, so set it generously.';
$string['openaiconcurrency'] = 'Parallel requests';
$string['openaiconcurrency_desc'] = 'How many OpenAI requests to run at the same time. OCR sends one request per response area per submitted page, so processing a class set is much faster when these run in parallel. Raise this to speed up large batches; lower it if you hit rate limits (HTTP 429) on your OpenAI account.';
$string['openaitimeout'] = 'Request timeout';
$string['openaitimeout_desc'] = 'How long to wait, in seconds, for a single OpenAI request before giving up on it. Requests that time out are retried a couple of times before the response area is recorded as blank.';
$string['defaulttargetlanguage'] = 'Default target language';
$string['defaultfeedbacklanguage'] = 'Default native language';
$string['defaulttargetlanguage_desc'] = 'The default language the student is writing in.';
$string['defaultfeedbacklanguage_desc'] = 'The default language feedback should be given in.';
$string['defaulttargetlanguagefont'] = 'Default target language font';
$string['defaulttargetlanguagefont_desc'] = 'The default font for the student text.';
$string['defaultfeedbacklanguagefont'] = 'Default native language font';
$string['defaultfeedbacklanguagefont_desc'] = 'The default font for the feedback text.';
$string['gradingpresets'] = 'Grading Presets';
$string['gradingprompt_name'] = 'Grading Preset {$a} Name';
$string['gradingprompt_content'] = 'Grading Preset {$a} Content';
$string['feedbackpresets'] = 'Feedback Presets';
$string['feedbackprompt_name'] = 'Feedback Preset {$a} Name';
$string['feedbackprompt_content'] = 'Feedback Preset {$a} Content';
$string['managegradingpresets'] = 'Manage Grading Presets';
$string['managefeedbackpresets'] = 'Manage Feedback Presets';
$string['managegradingpresetsinstructions'] = 'Set custom prompts that can be used when AI evaluates the student submissions for grading.';
$string['managefeedbackpresetsinstructions'] = 'Set custom prompts that can be used when AI evaluates the student submissions for feedback.';
$string['managepresets'] = 'Manage Presets';

$string['addpreset'] = 'Add Preset';
$string['editpreset'] = 'Edit Preset';
$string['deletepreset'] = 'Delete Preset';
$string['presetname'] = 'Preset Name';
$string['presetcontent'] = 'Preset Content';
$string['nopresets'] = 'No personal presets found.';
$string['selectpreset'] = 'Select Preset';
$string['presetsaved'] = 'Preset saved.';
$string['presetdeleted'] = 'Preset deleted.';
$string['confirmdeletepreset'] = 'Are you sure you want to delete this preset?';

// View / Setup
$string['setup'] = 'Setup Paper';
$string['viewsetup'] = 'Go to Setup';
$string['uploadtemplate'] = 'Upload Template';
$string['uploadsubmissions'] = 'Upload and Evaluate Submissions';
$string['viewreports'] = 'View Evaluations';
$string['processsubmissions'] = 'Process Submissions';
$string['templateimage'] = 'Template Image (JPG/PDF)';
$string['submissions'] = 'Submissions (JPG/PDF)';

$string['en-us'] = 'English (US)';
$string['es-us'] = 'Spanish (US)';
$string['en-au'] = 'English (Aus.)';
$string['en-ph'] = 'English (Phil.)';
$string['en-gb'] = 'English (GB)';
$string['fr-ca'] = 'French (Can.)';
$string['fr-fr'] = 'French (FR)';
$string['it-it'] = 'Italian (IT)';
$string['pt-br'] = 'Portuguese (BR)';
$string['en-in'] = 'English (IN)';
$string['es-es'] = 'Spanish (ES)';
$string['fil-ph'] = 'Filipino';
$string['de-de'] = 'German (DE)';
$string['de-ch'] = 'German (CH)';
$string['de-at'] = 'German (AT)';
$string['da-dk'] = 'Danish (DK)';
$string['hi-in'] = 'Hindi';
$string['ko-kr'] = 'Korean';
$string['ar-ae'] = 'Arabic (Gulf)';
$string['ar-sa'] = 'Arabic (Modern Standard)';
$string['zh-cn'] = 'Chinese (Mandarin-Mainland)';
$string['nl-nl'] = 'Dutch (NL)';
$string['nl-be'] = 'Dutch (BE)';
$string['en-ie'] = 'English (Ireland)';
$string['en-wl'] = 'English (Wales)';
$string['en-ab'] = 'English (Scotland)';
$string['en-nz'] = 'English (New Zealand)';
$string['en-za'] = 'English (South Africa)';
$string['fa-ir'] = 'Persian';
$string['he-il'] = 'Hebrew';
$string['id-id'] = 'Indonesian';
$string['ja-jp'] = 'Japanese';
$string['ms-my'] = 'Malay';
$string['mi-nz'] = 'Maori';
$string['pt-pt'] = 'Portuguese (PT)';
$string['ru-ru'] = 'Russian';
$string['ta-in'] = 'Tamil';
$string['te-in'] = 'Telugu';
$string['tr-tr'] = 'Turkish';
$string['uk-ua'] = 'Ukranian';
$string['eu-es'] = 'Basque';
$string['fi-fi'] = 'Finnish';
$string['hu-hu'] = 'Hungarian';
$string['sv-se'] = 'Swedish';
$string['no-no'] = 'Norwegian';
$string['nb-no'] = 'Norwegian (Bokmål)';
$string['nn-no'] = 'Norwegian (Nynorsk)';
$string['pl-pl'] = 'Polish';
$string['ro-ro'] = 'Romanian';
$string['bg-bg'] = 'Bulgarian';
$string['cs-cz'] = 'Czech';
$string['el-gr'] = 'Greek';
$string['hr-hr'] = 'Croatian';
$string['lt-lt'] = 'Lithuanian';
$string['lv-lv'] = 'Latvian';
$string['sk-sk'] = 'Slovak';
$string['sl-si'] = 'Slovenian';
$string['so-so'] = 'Somali';
$string['ps-af'] = 'Pashto';
$string['is-is'] = 'Icelandic';
$string['mk-mk'] = 'Macedonian';
$string['sr-rs'] = 'Serbian';
$string['vi-vn'] = 'Vietnamese';
$string['fieldrole'] = 'Field Role';
$string['fieldrole_help'] = 'Designate the purpose of this response area. Non-standard roles will disable automatic AI grading and feedback for this specific area.';
$string['fieldrole_standard'] = 'Standard (Graded)';
$string['fieldrole_fullname'] = 'Full Name';
$string['fieldrole_username'] = 'Moodle Username';
$string['fieldrole_displayonly'] = 'Display Only (No OCR. Not graded)';
$string['fieldrole_ungraded'] = 'Ungraded';
$string['showtotalscore'] = 'Show total score';
$string['showtotalscore_help'] = 'If enabled, the total score will be displayed at the bottom of the evaluation report.';

// Page instructions
$string['view_help'] = 'This is the main activity page. Once you have set up your template, you can upload scanned student submissions here for processing.';
$string['setup_help'] = 'Upload a blank worksheet and drag your mouse over response areas to define them. You can configure each area for OCR, grammar correction, and automated grading.';
$string['presets_help'] = 'Manage your worksheet presets here. You can save your current response area layout as a preset to reuse it in other Paper activities, or apply an existing preset to this instance.';
$string['process_submissions_help'] = 'Upload the scanned PDF of student submissions. The system will process each page as an individual student response and perform AI evaluation.';
$string['reports_help'] = 'Review completed evaluations here. You can view individual student feedback, adjust grades manually, or download all evaluations as a single combined PDF report for printing.';

// UI Strings
$string['reports'] = 'Reports';
$string['evaluationreportsfor'] = 'Evaluation Reports for: {$a}';
$string['noevaluationsfound'] = 'No evaluations found.';
$string['noevaluationsyet'] = 'There are no evaluations to see yet. When they are ready they will show here.';
$string['processingevaluations'] = 'AI is currently evaluating student work. This page will refresh automatically when complete...';
$string['evaluationid'] = 'Eval. ID';
$string['studentname'] = 'Student Name';
$string['totalgrade'] = 'Total Grade';
$string['actions'] = 'Actions';
$string['evaluationpending'] = 'Evaluation Pending...';
$string['deleteevaluationconfirm'] = 'Are you sure you want to delete this evaluation?';
$string['viewallcombinedpdfs'] = 'View All Combined PDFs';
$string['reevaluateall'] = 'Re-evaluate All';
$string['reevaluateallconfirm'] = 'Are you sure you want to clear all existing grammar corrections and re-evaluate them?';
$string['deleteallsubmissions'] = 'Delete All Submissions';
$string['deleteallsubmissionsconfirm'] = 'Are you sure you want to delete ALL evaluations? This cannot be undone.';
$string['returntotop'] = 'Return to Top';

// Setup/View Strings
$string['setupnotcomplete'] = 'Setup is not complete. Please identify response areas first.';
$string['viewsetup'] = 'View Setup';
$string['setuptemplate'] = 'Setup Template';
$string['editsetup'] = 'Edit Setup';
$string['viewreports'] = 'View Reports';
$string['setuptemplatefor'] = 'Setup Template for: {$a}';
$string['managepresetsfor'] = 'Manage Presets for: {$a}';
$string['uploadscansfor'] = 'Upload Scans for: {$a}';
$string['uploadsubmissions'] = 'Upload Submissions';
$string['processingstatus'] = 'Processing Status';
$string['viewevaluation'] = 'View Evaluation';
$string['editmanualevaluation'] = 'Edit Manual Evaluation';
$string['saveevaluation'] = 'Save Evaluation';
$string['backtoreports'] = 'Back to Reports';
$string['returntoreports'] = 'Return to Reports';
$string['papersettings'] = 'Paper Settings';
$string['totalscore'] = 'Total score';
$string['nopresetsfound'] = 'No presets found.';
$string['nofeedbackpresetsfound'] = 'No feedback presets found.';
$string['applypresetconfirm'] = 'Are you sure you want to apply this preset? It will overwrite any existing response areas.';
$string['deletepresetconfirm'] = 'Are you sure you want to delete this preset?';
$string['addnewpreset'] = 'Add New Grading Preset';
$string['addnewfeedbackpreset'] = 'Add New Feedback Preset';
$string['editfeedbackpreset'] = 'Edit Feedback Preset';
$string['deletefeedbackpreset'] = 'Delete Feedback Preset';
$string['feedbackpresetdeleted'] = 'Feedback preset deleted.';
$string['selectfeedbackpreset'] = 'Select Feedback Preset';
$string['savecurrentaspreset'] = 'Save Current Layout as Preset';
$string['presetname'] = 'Preset Name';
$string['presetid'] = 'Preset ID';
$string['apply'] = 'Apply';
$string['saving'] = 'Saving...';
$string['savechanges'] = 'Save Changes';
$string['changessaved'] = 'Changes saved successfully';
$string['error'] = 'Error';
$string['failedtosave'] = 'Failed to save changes';
$string['ok'] = 'OK';
$string['responsearea'] = 'Response Area #{$a}';
$string['configured'] = 'Configured';
$string['notconfigured'] = 'Not configured';
$string['allconfigured'] = 'All configured!!';
$string['noneconfigured'] = 'None configured.';
$string['nconfigured'] = '{$a->configured}/{$a->total} configured.';
$string['uploadtemplate'] = 'Upload Worksheet Template';
$string['uploadtemplate_help'] = 'Upload an image of a blank paper to identify response areas. Response areas should be identifiable by having a clear border around them, and contain no text.';
$string['templatefile'] = 'Template File (JPG/PNG/PDF)';
$string['analyzeimage'] = 'Analyze Image';
$string['identifiedareas'] = 'Identified Areas';
$string['templateloaded'] = 'Template loaded.';
$string['uploadnewtemplate'] = 'Upload New Template';
$string['responseareaconfiguration'] = 'Response Area Configuration';
$string['addarea'] = 'Add Area';
$string['configurationforarea'] = 'Configuration for Area';
$string['deletearea'] = 'Delete Area';
$string['questiontopic'] = 'Question / Topic';
$string['questiontopic_help'] = 'Provide the specific question or topic that the student is responding to in this designated area.';
$string['correctanswer'] = 'Correct Answer';
$string['correctanswer_help'] = 'Specify the expected correct answer and how strictly the AI should evaluate the student\'s response against it.';
$string['answermode_none'] = 'None';
$string['answermode_relevant'] = 'Is relevant to question';
$string['answermode_manual'] = 'Matches the correct answer';
$string['answermode_samemeaning'] = 'Same meaning as correct answer';
$string['thecorrectanswer'] = 'The correct answer';
$string['grammarcorrection'] = 'Grammar Correction';
$string['grammarcorrection_help'] = 'Should the AI provide specific grammar and spelling corrections for this area?';
$string['grammarcorrection_none'] = 'None';
$string['grammarcorrection_major'] = 'Major mistakes';
$string['grammarcorrection_all'] = 'All mistakes';
$string['yes'] = 'Yes';
$string['no'] = 'No';
$string['automatedgrading'] = 'Automated Grading';
$string['automatedgrading_help'] = 'Should the AI assign a grade to this area?';
$string['grading_none'] = 'None';
$string['grading_incorrect'] = 'Deduct point for each grammar/spelling mistake';
$string['grading_overall'] = 'Custom grading instructions';
$string['maxpossiblegrade'] = 'Max Possible Grade';
$string['gradeinstructions'] = 'Grading Instructions';
$string['gradeinstructions_help'] = 'Optionally provide specific grading criteria or rubrics for the AI to follow.';
$string['feedback_none'] = 'No feedback';
$string['feedback_grammatical'] = 'Explain grammatical errors';
$string['feedback_custom'] = 'Custom feedback instructions';
$string['feedbackmode_help'] = 'What feedback should the AI give the student?';

$string['feedbackinstructions'] = 'Feedback Instructions';
$string['feedbackinstructions_help'] = 'Provide instructions for the AI on what kind of feedback to generate.';
$string['feedbackarea'] = 'Feedback Area #{$a}';
$string['feedbackpositionandsize'] = 'Feedback Position and Size (%)';
$string['positionandsize'] = 'Position and Size (%)';
$string['pos_left'] = 'Left';
$string['pos_top'] = 'Top';
$string['pos_width'] = 'Width';
$string['pos_height'] = 'Height';
$string['saveallconfigurations'] = 'Save All Areas';
$string['resettemplate'] = 'Reset Template';
$string['area_configurations_saved'] = 'Area configurations saved successfully.';
$string['editresponse'] = 'Edit Response';
$string['grade'] = 'Grade';
$string['originaltext'] = 'Original Text (OCR)';
$string['correctedtext'] = 'Corrected Text';
$string['feedback'] = 'Feedback';
$string['cancel'] = 'Cancel';
$string['outofmaxgrade'] = 'out of {$a}';
$string['gradeexceedsmax'] = 'The grade for this response area cannot be more than {$a}.';
$string['reevaluateitem'] = 'Re-evaluate with AI';
$string['reevaluating'] = 'Re-evaluating...';
$string['reevaluateitemconfirm'] = 'Re-evaluate this response with AI, using the original text as it currently stands? The corrected text, feedback and grade for this response will be replaced, including any manual edits.';
$string['reevaluateitemfailed'] = 'The AI returned no result for this response. It has been left awaiting evaluation and will be picked up by the next evaluation run.';
$string['itemreevaluated'] = 'Response re-evaluated';
$string['templatenotfound'] = 'Warning: Underlying worksheet template image not found. Please re-upload it on the Setup screen.';
$string['totalevaluationsprocessed'] = 'Total evaluations processed: {$a}';
$string['studentviewmessage'] = 'This activity is managed by your teacher. Evaluations will be available here when completed.';
$string['previousstudent'] = 'Previous Student';
$string['nextstudent'] = 'Next Student';
$string['feedbacklabel'] = 'Feedback: {$a}';

// Course reset and deletion.
$string['resetevaluations'] = 'Delete all submissions and evaluations';
$string['resetevaluations_help'] = 'Deletes every scanned submission, evaluation and grade for the Paper activities in this course, including the uploaded scans and cropped images. The worksheet templates and their response area configuration are kept, so the activities are ready to receive a new set of submissions.';
$string['allevaluationsdeleted'] = 'All evaluations deleted successfully.';
$string['noevaluationstodelete'] = 'No evaluations to delete.';
$string['evaluationdeleted'] = 'Evaluation deleted successfully.';

$string['enabledebugfeatures'] = 'Enable debug features for developers';
$string['enabledebugfeatures_desc'] = 'When enabled, the Developer button appears on the reports page, giving access to tools for inspecting how a submission was processed. Off by default - these are for working on the plugin, not for teaching with. Turning this off also blocks the Developer page itself, not just the button.';
$string['debugfeaturesdisabled'] = 'Developer tools are turned off for this site. An administrator can enable them with the "Enable debug features for developers" setting in the Paper activity settings.';
$string['savedebugcrops'] = 'Save debug crops';
$string['savedebugcrops_desc'] = 'When enabled, processing a submission additionally saves every response area\'s cropped image (not just display-only snippets) so they can be inspected on the Developer page. Off by default - adds one extra stored image per response area per submission.';
$string['developer'] = 'Developer';
$string['developertools'] = 'Developer Tools';
$string['developerpagehelp'] = 'Tools for inspecting the current state of processing for this paper. This page is not intended for regular use.';
$string['viewsubmissioncrops'] = 'View submission crops';
$string['viewsubmissioncropshelp'] = 'Choose a processed submission to see the cropped image captured for every response area.';
$string['selectsubmission'] = 'Select a submission';
$string['choosedots'] = 'Choose...';
$string['viewcrops'] = 'View crops';
$string['submissioncropsfor'] = 'Cropped images for evaluation #{$a}';
$string['nocropavailable'] = 'No crop saved for this area. Enable "Save debug crops" in the plugin settings and reprocess the submission to capture it.';
$string['areatype_response'] = 'Response';
$string['areatype_name'] = 'Name field';
$string['areatype_username'] = 'Username field';
$string['areatype_displayonly'] = 'Display only';
$string['areatype_ungraded'] = 'Ungraded';

$string['scanalignment'] = 'Scan alignment';
$string['scanalignment_heading'] = 'Scan alignment';
$string['scanalignment_heading_desc'] = 'Printing a worksheet and scanning it back rarely reproduces the original geometry exactly - the content usually lands a millimetre or two off, and can be slightly stretched. These settings are the site-wide defaults for correcting that; an individual activity can override them from the Scan alignment button on its Reports page, where the values can also be measured automatically from a scanned submission.';
$string['scanalignmentfor'] = 'Scan alignment for {$a}';
$string['scanalignmenthelp'] = 'These values correct for the difference between the worksheet as printed and the worksheet as scanned back in. ';
$string['scanalignmentpadnote'] = 'Corrections apply only to display-only areas. They affect where a display-only area\'s image is cut from a scanned student response. The crop margin setting (currently {$a}mm) limits how far it can be adjusted in any direction. The position at which the display-only image is displayed on the PDF/evaluation is not affected. Submissions do not need to be re-uploaded or re-processed. Leave a field blank to use the site default.';
$string['scanalignmentunits'] = 'Offsets are in millimetres on the printed A4 page. Most scans need a shift of one or two millimetres. The scales are separate: they are percentages, where 100 means the scan is the same size as the template, and they are only needed when the misalignment grows across the page instead of staying the same.';
$string['croppadmm'] = 'Crop margin (mm)';
$string['croppadmm_desc'] = 'How much extra to capture around a display-only area when cropping a scanned submission, in millimetres. The margin is what allows the scan alignment to be corrected afterwards without re-uploading and re-OCRing the batch. Larger values tolerate worse scans at the cost of some storage. Set to 0 to crop exactly at the response area. Areas that are read by OCR are always cropped exactly at the area, since a wider crop would feed the model the printing just outside it.';
$string['alignoffsetx'] = 'Horizontal offset (mm)';
$string['alignoffsetx_desc'] = 'How far right the scanned content sits compared with the template, in millimetres. Use a negative value when the scan sits to the left.';
$string['alignoffsetx_help'] = 'How far right the scanned content sits compared with the template, in millimetres on the printed page. Use a negative value when the scan sits to the left. If the scanned handwriting appears about 2mm too far right of where it should be, enter 2.';
$string['alignoffsety'] = 'Vertical offset (mm)';
$string['alignoffsety_desc'] = 'How far down the scanned content sits compared with the template, in millimetres. Use a negative value when the scan sits higher.';
$string['alignoffsety_help'] = 'How far down the scanned content sits compared with the template, in millimetres on the printed page. Use a negative value when the scan sits higher. If the scanned handwriting appears about 2mm too low, enter 2.';
$string['alignscalex'] = 'Horizontal scale (%)';
$string['alignscalex_desc'] = 'How much wider the scanned content is than the template, as a percentage. 100 means no difference.';
$string['alignscalex_help'] = 'How much wider the scanned content is than the template, as a percentage - not a millimetre value like the offsets above. 100 means no difference; 102 means the scan is stretched two percent wider. Use this when the misalignment grows across the page rather than staying constant.';
$string['alignscaley'] = 'Vertical scale (%)';
$string['alignscaley_desc'] = 'How much taller the scanned content is than the template, as a percentage. 100 means no difference.';
$string['alignscaley_help'] = 'How much taller the scanned content is than the template, as a percentage - not a millimetre value like the offsets above. 100 means no difference; 102 means the scan is stretched two percent taller. Use this when the misalignment grows down the page rather than staying constant.';
$string['alignmentinherited'] = 'Site default: {$a}';
$string['alignmentnotnumeric'] = 'Enter a number, or leave blank to use the site default.';
$string['alignmentscalepositive'] = 'Scale must be greater than zero.';
$string['alignmentsaved'] = 'Scan alignment saved.';
$string['alignmentreset'] = 'Scan alignment reset to the site defaults.';
$string['alignmentresetbutton'] = 'Reset to site defaults';
$string['alignmentdetect'] = 'Auto detect from a scanned submission';
$string['alignmentdetecthelp'] = 'Measures a scanned page from the most recent upload against the worksheet template and suggests values. Nothing is saved until you review the suggestion and choose Save changes below.';
$string['alignmentdetectbutton'] = 'Auto Detect Alignment';
$string['alignmentdetectunavailable'] = 'Nothing to measure yet. A page is kept from each upload for this purpose, so process a submission and then come back.';
$string['alignmentdetectfailed'] = 'Could not measure the alignment. The retained page or the worksheet template could not be read.';
$string['alignmentdetected'] = 'Measured alignment filled into the form below. Review the values and save them if they look right.';
$string['alignmentpreview'] = 'Evaluation preview';
$string['alignmentpreviewstudent'] = 'Student to preview';
$string['alignmentpreviewreload'] = 'Reload';
$string['alignmentpreviewhelp'] = 'This is a real evaluation PDF. It is rebuilt each time the page loads or you save changes here.';
$string['alignmentpreviewnone'] = 'No submissions have been processed for this activity yet, so there is nothing to preview.';
$string['evaluationnumber'] = 'Evaluation {$a}';
$string['alignmentaxis'] = 'Direction';
$string['alignmenthorizontal'] = 'Horizontal';
$string['alignmentvertical'] = 'Vertical';
$string['alignmentmeasuredoffset'] = 'Offset (mm)';
$string['alignmentmeasuredscale'] = 'Scale (%)';
$string['alignmentbands'] = 'Measured across the page';
$string['alignmentunreliable'] = 'inconsistent';
$string['alignmentunreliablehelp'] = 'A direction is marked inconsistent when the measurements taken across the page disagree with each other, which means there was not enough printing on the page to pin that direction down. Worksheets are usually full of horizontal rules, so the vertical figure is normally the dependable one. Consider keeping the inconsistent direction at its existing value rather than accepting the suggestion.';
