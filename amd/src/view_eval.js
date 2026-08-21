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
 * Interactive teacher review page: live inline editing and grade recalculation for evaluations.
 *
 * @module     mod_paper/view_eval
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/notification', 'core/str', 'core/log', 'mod_paper/constants'],
        function($, Ajax, Notification, Str, log, constants) {
    "use strict";

    var component = 'mod_paper';

    return {
        strings: {},

        init: function(cmid, evalid, maxpossible) {
            var dd = this;
            this.setup_strings();

            $('.eval-item-area').on('click', function() {
                var area = $(this);
                var itemId = area.data('item-id');
                var areaId = area.data('area-id');
                var areaType = Number(area.data('areatype'));
                var maxGrade = area.data('maxgrade');
                var ocr = area.data('ocr');
                var corrected = area.data('corrected');
                var feedback = area.data('feedback');
                var grade = area.data('grade');
                var areaNum = area.data('responsenumber');

                // Fill form.
                $('#field-itemid').val(itemId);
                $('#field-areaid').val(areaId);
                $('#field-grade').val(grade);
                $('#field-ocrtext').val(ocr);
                $('#field-correctedtext').val(corrected);
                $('#field-feedback').val(feedback);
                $('#sidebar-area-num').text(areaNum ? '#' + areaNum : '');

                // An area with no maximum configured is passed through as an empty string,
                // and is left uncapped rather than capped at zero.
                if (maxGrade === '' || maxGrade === undefined || maxGrade === null) {
                    $('#field-grade').removeAttr('max');
                    $('#grade-max-hint').text('');
                } else {
                    $('#field-grade').attr('max', maxGrade);
                    $('#grade-max-hint').text(dd.strings.outofmaxgrade.replace('{$a}', maxGrade));
                }

                // Grade, feedback and re-evaluation only apply to a standard graded area.
                if (areaType === constants.AREATYPE_GRADED) {
                    $('#group-grade').show();
                    $('#group-feedback').show();
                    // Nothing to re-evaluate until the response has been OCR'd at least once.
                    $('#btn-reevaluate-item').toggle(Number(itemId) > 0);
                } else {
                    $('#group-grade').hide();
                    $('#group-feedback').hide();
                    $('#btn-reevaluate-item').hide();
                }

                // Display-only areas are reproduced as an image and never OCR'd.
                $('#group-ocr').toggle(Number(area.data('hasocr')) === 1);

                $('#edit-sidebar').show();

                // Highlight active area.
                $('.eval-item-area').css('outline', '2px solid blue');
                area.css('outline', '4px solid red');
            });

            $('#btn-cancel-edit').on('click', function() {
                $('#edit-sidebar').hide();
                $('.eval-item-area').css('outline', '2px solid blue');
            });

            $('#edit-item-form').on('submit', function(e) {
                e.preventDefault();

                var grade = dd.read_grade();
                if (grade === false) {
                    return;
                }

                var args = {
                    cmid: cmid,
                    evalid: evalid,
                    areaid: parseInt($('#field-areaid').val(), 10),
                    itemid: parseInt($('#field-itemid').val(), 10) || 0,
                    grade: grade,
                    correctedtext: $('#field-correctedtext').val(),
                    feedback: $('#field-feedback').val(),
                    // Null leaves the stored OCR text alone, which is what an area that has
                    // no OCR text of its own (display only) needs.
                    ocrtext: $('#group-ocr').is(':visible') ? $('#field-ocrtext').val() : null
                };

                $('#btn-save-item').prop('disabled', true).text(dd.strings.saving);

                Ajax.call([{
                    methodname: 'mod_paper_update_eval_item',
                    args: args
                }])[0].then(function(result) {
                    $('#btn-save-item').prop('disabled', false).text(dd.strings.savechanges);
                    dd.apply_result(args.areaid, result, maxpossible, args.ocrtext, dd.strings.changessaved, true);
                    return result;
                }).catch(function(ex) {
                    $('#btn-save-item').prop('disabled', false).text(dd.strings.savechanges);
                    log.error('Error saving item:', ex);
                    Notification.exception(ex);
                });
            });

            $('#btn-reevaluate-item').on('click', function() {
                var areaid = parseInt($('#field-areaid').val(), 10);
                var itemid = parseInt($('#field-itemid').val(), 10) || 0;
                if (!itemid) {
                    return;
                }
                // Graded areas always have OCR text, and it is graded as it stands in the
                // sidebar - the teacher does not have to save an OCR fix first.
                var ocrtext = $('#field-ocrtext').val();

                Notification.confirm(dd.strings.reevaluateitem, dd.strings.reevaluateitemconfirm,
                        dd.strings.reevaluateitem, dd.strings.cancel, function() {
                    $('#btn-reevaluate-item').prop('disabled', true).text(dd.strings.reevaluating);
                    $('#btn-save-item').prop('disabled', true);

                    Ajax.call([{
                        methodname: 'mod_paper_reevaluate_eval_item',
                        args: {cmid: cmid, evalid: evalid, areaid: areaid, itemid: itemid, ocrtext: ocrtext}
                    }])[0].then(function(result) {
                        $('#btn-reevaluate-item').prop('disabled', false).text(dd.strings.reevaluateitem);
                        $('#btn-save-item').prop('disabled', false);
                        // The sidebar stays open on success: the teacher asked the AI for a
                        // new answer, so show them what it came back with.
                        dd.apply_result(areaid, result, maxpossible, ocrtext, dd.strings.itemreevaluated, false);
                        $('#field-correctedtext').val(result.correctedtext);
                        $('#field-feedback').val(result.feedback);
                        $('#field-grade').val(result.grade);
                        return result;
                    }).catch(function(ex) {
                        $('#btn-reevaluate-item').prop('disabled', false).text(dd.strings.reevaluateitem);
                        $('#btn-save-item').prop('disabled', false);
                        log.error('Error re-evaluating item:', ex);
                        Notification.exception(ex);
                    });
                });
            });
        },

        /**
         * Reads the grade field, holding it to the response area's maximum.
         *
         * @return {number|null|boolean} The grade, null if the field is empty, or false if
         *     it is out of range - in which case the user has been told and nothing should
         *     be submitted.
         */
        read_grade: function() {
            var raw = $('#field-grade').val();
            if (raw === '' || raw === null || raw === undefined) {
                return null;
            }

            var grade = parseFloat(raw);
            if (isNaN(grade)) {
                return null;
            }

            var max = $('#field-grade').attr('max');
            if (max !== undefined && grade > parseFloat(max)) {
                Notification.alert(this.strings.error,
                    this.strings.gradeexceedsmax.replace('{$a}', max), this.strings.ok);
                return false;
            }

            return grade;
        },

        /**
         * Applies a saved or re-evaluated item back onto the worksheet.
         *
         * @param {number} areaid The response area that was edited.
         * @param {object} result The web service response.
         * @param {string} maxpossible Total grade available for this paper, for the score line.
         * @param {string|null} ocrtext OCR text just saved, or null if it was not changed.
         * @param {string} message Success notification to show.
         * @param {boolean} closesidebar Whether to close the editing sidebar on success.
         */
        apply_result: function(areaid, result, maxpossible, ocrtext, message, closesidebar) {
            // The response carries the item as it now stands either way, so a failed call
            // (a re-evaluation the AI did not answer) still redraws rather than leaving the
            // worksheet showing values the database no longer holds.
            var area = $('#item_area_' + areaid);
            area.html(result.newhtml);

            // Both the property and the attribute: jQuery caches data() on first read, so
            // the attribute alone would not be seen again by this page.
            area.data('item-id', result.itemid).attr('data-item-id', result.itemid);
            area.data('corrected', result.correctedtext);
            area.data('feedback', result.feedback);
            area.data('grade', result.grade);
            if (ocrtext !== null) {
                area.data('ocr', ocrtext);
            }

            $('#total-grade-display').text(result.totalgrade + ' / ' + maxpossible);

            if (!result.success) {
                Notification.alert(this.strings.error, result.error || this.strings.failedtosave, this.strings.ok);
                return;
            }

            Notification.addNotification({
                message: message,
                type: 'success'
            });

            if (closesidebar) {
                $('#edit-sidebar').hide();
                $('.eval-item-area').css('outline', '2px solid blue');
            }
        },

        setup_strings: function() {
            var dd = this;
            Str.get_strings([
                {key: 'savechanges', component: component},
                {key: 'saving', component: component},
                {key: 'changessaved', component: component},
                {key: 'error', component: component},
                {key: 'failedtosave', component: component},
                {key: 'ok', component: component},
                {key: 'cancel', component: component},
                {key: 'outofmaxgrade', component: component},
                {key: 'gradeexceedsmax', component: component},
                {key: 'reevaluateitem', component: component},
                {key: 'reevaluating', component: component},
                {key: 'reevaluateitemconfirm', component: component},
                {key: 'itemreevaluated', component: component}
            ]).done(function(s) {
                var i = 0;
                dd.strings.savechanges = s[i++];
                dd.strings.saving = s[i++];
                dd.strings.changessaved = s[i++];
                dd.strings.error = s[i++];
                dd.strings.failedtosave = s[i++];
                dd.strings.ok = s[i++];
                dd.strings.cancel = s[i++];
                dd.strings.outofmaxgrade = s[i++];
                dd.strings.gradeexceedsmax = s[i++];
                dd.strings.reevaluateitem = s[i++];
                dd.strings.reevaluating = s[i++];
                dd.strings.reevaluateitemconfirm = s[i++];
                dd.strings.itemreevaluated = s[i++];
            });
        }
    };
});
