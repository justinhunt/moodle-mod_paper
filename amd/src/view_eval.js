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
define(['jquery', 'core/ajax', 'core/notification', 'core/str', 'core/log'], function($, Ajax, Notification, Str, log) {
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
                var isNameField = area.data('is-name-field');
                var ocr = area.data('ocr');
                var corrected = area.data('corrected');
                var feedback = area.data('feedback');
                var grade = area.data('grade');
                var areaNum = area.data('responsenumber');

                // Fill form.
                $('#field-itemid').val(itemId);
                $('#field-areaid').val(areaId);
                $('#field-grade').val(grade);
                $('#field-ocr-readonly').text(ocr);
                $('#field-correctedtext').val(corrected);
                $('#field-feedback').val(feedback);
                $('#sidebar-area-num').text(areaNum ? '#' + areaNum : '');

                // Toggle fields for name field.
                if (isNameField) {
                    $('#group-grade').hide();
                    $('#group-feedback').hide();
                } else {
                    $('#group-grade').show();
                    $('#group-feedback').show();
                }

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

                var args = {
                    cmid: cmid,
                    evalid: evalid,
                    areaid: parseInt($('#field-areaid').val(), 10),
                    itemid: parseInt($('#field-itemid').val(), 10) || 0,
                    grade: parseFloat($('#field-grade').val()) || null,
                    correctedtext: $('#field-correctedtext').val(),
                    feedback: $('#field-feedback').val()
                };

                $('#btn-save-item').prop('disabled', true).text(dd.strings.saving);

                Ajax.call([{
                    methodname: 'mod_paper_update_eval_item',
                    args: args
                }])[0].then(function(result) {
                    $('#btn-save-item').prop('disabled', false).text(dd.strings.savechanges);

                    if (result.success) {
                        // Update the area HTML.
                        var areaId = '#item_area_' + args.areaid;
                        $(areaId).html(result.newhtml);

                        // Update data attributes.
                        $(areaId).data('item-id', result.itemid);
                        $(areaId).data('corrected', args.correctedtext);
                        $(areaId).data('feedback', args.feedback);
                        $(areaId).data('grade', args.grade);
                        $(areaId).attr('data-item-id', result.itemid);

                        // Update total grade.
                        $('#total-grade-display').text(result.totalgrade + ' / ' + maxpossible);

                        Notification.addNotification({
                            message: dd.strings.changessaved,
                            type: 'success'
                        });

                        $('#edit-sidebar').hide();
                        $('.eval-item-area').css('outline', '2px solid blue');
                    } else {
                        Notification.alert(dd.strings.error, result.error || dd.strings.failedtosave, dd.strings.ok);
                    }
                }).catch(function(ex) {
                    log.error('Error saving item:', ex);
                });
            });
        },

        setup_strings: function() {
            var dd = this;
            Str.get_strings([
                {key: 'savechanges', component: component},
                {key: 'saving', component: component},
                {key: 'changessaved', component: component},
                {key: 'error', component: component},
                {key: 'failedtosave', component: component},
                {key: 'ok', component: component}
            ]).done(function(s) {
                var i = 0;
                dd.strings.savechanges = s[i++];
                dd.strings.saving = s[i++];
                dd.strings.changessaved = s[i++];
                dd.strings.error = s[i++];
                dd.strings.failedtosave = s[i++];
                dd.strings.ok = s[i++];
            });
        }
    };
});
