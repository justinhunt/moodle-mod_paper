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
 * Interactive canvas UI for drawing and configuring response/feedback areas on the worksheet template.
 *
 * @module     mod_paper/setup
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/log', 'core/str', 'mod_paper/constants'], function($, log, Str, constants) {
    "use strict";

    var component = 'mod_paper';
    var newIdCounter = 1;

    return {
        strings: {},

        init: function(params) {
            var dd = this;
            this.setup_strings();

            log.debug("mod_paper setup initialized", params);

            var container = $('#image-container');
            var areasData = $('#responseareas-json').val();
            var responseareas = [];

            if (areasData) {
                try {
                    responseareas = JSON.parse(areasData);
                } catch (e) {
                    log.error("Failed to parse responseareas-json", e);
                }
            } else if (params && params.responseareas) {
                responseareas = params.responseareas;
            }

            var img = container.find('img');

            var onImageLoaded = function() {
                console.log("Image loaded, creating boxes. Areas:", responseareas);
                // Draw bounding boxes once image is loaded.
                if (responseareas && responseareas.length > 0) {
                    responseareas.forEach(function(area) {
                        console.log("Creating boxes for area:", area.id);
                        dd.createBoxElement(area.id, area.responsenumber, area.box_x, area.box_y, area.box_w, area.box_h);
                        dd.createFeedbackBoxElement(area.id, area.responsenumber, area.fb_x, area.fb_y, area.fb_w, area.fb_h);
                    });
                    // Select first by default.
                    dd.selectArea(responseareas[0].id);
                    dd.renderStatusIcons();
                }
            };

            if (img.length) {
                if (img[0].complete) {
                    onImageLoaded();
                } else {
                    img.on('load', onImageLoaded);
                }
            }

            // Bind live updates for manual coordinate input tweaking.
            $('#setup-form').on('input', '.pos-input', function() {
                var val = $(this).val();
                var areaId = $(this).data('area');
                var attr = $(this).data('attr');
                if (val !== '') {
                    $('#box_' + areaId).css(attr, val + '%');
                }
            });

            $('#setup-form').on('input', '.fb-pos-input', function() {
                var val = $(this).val();
                var areaId = $(this).data('area');
                var attr = $(this).data('attr');
                if (val !== '') {
                    $('#fb_box_' + areaId).css(attr, val + '%');
                }
            });

            // Toggle correct answer textarea based on radio selection.
            $('#setup-form').on('change', '.cam-radio', function() {
                var areaId = $(this).closest('.area-card').data('area-id');
                var val = $(this).val();
                if (val === 'manual' || val === 'samemeaning') {
                    $('#manual_grade_wrapper_' + areaId).show();
                } else {
                    $('#manual_grade_wrapper_' + areaId).hide();
                }
            });

            // Toggle feedback instructions textarea based on radio selection.
            $('#setup-form').on('change', '.fm-radio', function() {
                var areaId = $(this).closest('.area-card').data('area-id');
                var val = $(this).val();
                if (val === 'custom') {
                    $('#feedback_instructions_wrapper_' + areaId).show();
                } else {
                    $('#feedback_instructions_wrapper_' + areaId).hide();
                }


                if (val === 'none') {
                    $('#fb_box_' + areaId).hide();
                    $(this).closest('.area-card').find('.fb-pos-fieldset').hide();
                } else {
                    var fbBox = $('#fb_box_' + areaId);
                    var card = $(this).closest('.area-card');

                    // If feedback coords are unset (all zeros), recalculate from the
                    // parent box's *current* position so a moved response area gets the
                    // right default rather than the original creation position.
                    var fbLeft   = parseFloat(card.find('.fb-pos-input[data-attr="left"]').val()) || 0;
                    var fbTop    = parseFloat(card.find('.fb-pos-input[data-attr="top"]').val()) || 0;
                    var fbWidth  = parseFloat(card.find('.fb-pos-input[data-attr="width"]').val()) || 0;
                    var fbHeight = parseFloat(card.find('.fb-pos-input[data-attr="height"]').val()) || 0;

                    if (fbLeft === 0 && fbTop === 0 && fbWidth === 0 && fbHeight === 0) {
                        var parentBox = $('#box_' + areaId);
                        if (parentBox.length) {
                            var container = $('#image-container');
                            var cw = container.width();
                            var ch = container.height();
                            var px = parseFloat(parentBox.css('left')) / cw * 100;
                            var py = parseFloat(parentBox.css('top')) / ch * 100;
                            var pw = parseFloat(parentBox.css('width')) / cw * 100;
                            var ph = parseFloat(parentBox.css('height')) / ch * 100;

                            var newFbX = px;
                            var newFbY = py + ph * 0.7;
                            var newFbW = pw;
                            var newFbH = ph * 0.3;

                            // Update the visual box.
                            fbBox.css({left: newFbX + '%', top: newFbY + '%',
                                       width: newFbW + '%', height: newFbH + '%'});

                            // Sync the numeric inputs so the values are saved correctly.
                            card.find('.fb-pos-input[data-attr="left"]').val(newFbX.toFixed(1));
                            card.find('.fb-pos-input[data-attr="top"]').val(newFbY.toFixed(1));
                            card.find('.fb-pos-input[data-attr="width"]').val(newFbW.toFixed(1));
                            card.find('.fb-pos-input[data-attr="height"]').val(newFbH.toFixed(1));
                        }
                    }

                    fbBox.show();
                    card.find('.fb-pos-fieldset').show();
                }
            });

            // Toggle grade instructions textarea based on radio selection.
            $('#setup-form').on('change', '.gm-radio', function() {
                var areaId = $(this).closest('.area-card').data('area-id');
                var val = $(this).val();
                if (val === 'overall') {
                    $('#grade_instructions_wrapper_' + areaId).show();
                } else {
                    $('#grade_instructions_wrapper_' + areaId).hide();
                }
            });

            // Toggle all fields based on role selection.
            $('#setup-form').on('change', '.areatype-radio', function() {
                var wrapper = $(this).closest('.area-card').find('.standard-fields-wrapper');
                if (Number($(this).val()) === constants.AREATYPE_GRADED) {
                    wrapper.show();
                } else {
                    wrapper.hide();
                }
            });
            $('.areatype-radio:checked').trigger('change');

            // Delete area.
            $('#setup-form').on('click', '.btn-trash-area', function() {
                var areaId = $(this).closest('.area-card').data('area-id');
                $('#box_' + areaId).remove();
                $('#fb_box_' + areaId).remove();
                $(this).closest('.area-card').remove();
                dd.updateLabels();
            });

            // Handle grading preset selection.
            var presetContents = JSON.parse($('#preset-contents-json').val() || '{}');
            $('#setup-form').on('change', '.preset-selector', function() {
                var areaId = $(this).data('area-id');
                var presetId = $(this).val();
                if (presetId && presetContents[presetId]) {
                    $('#gradeinstructions_' + areaId).val(presetContents[presetId]);
                }
                // Reset selector so it can be used again for the same preset if needed.
                $(this).val('0');
            });

            // Handle feedback preset selection.
            var feedbackPresetContents = JSON.parse($('#feedback-preset-contents-json').val() || '{}');
            $('#setup-form').on('change', '.feedback-preset-selector', function() {
                var areaId = $(this).data('area-id');
                var presetId = $(this).val();
                if (presetId && feedbackPresetContents[presetId]) {
                    $('#feedbackinstructions_' + areaId).val(feedbackPresetContents[presetId]);
                }
                // Reset selector so it can be used again for the same preset if needed.
                $(this).val('0');
            });

            // Add area button.
            $('#add-area-btn').on('click', function() {
                var id = 'new_' + newIdCounter++;
                var num = $('#setup-form .area-card').length + 1;

                // Add card (we'll clone a template if we had one, but for now we just use the first card as template).
                var template = $('#setup-form .area-card').first();
                var newCard;
                if (template.length) {
                    newCard = template.clone();
                    newCard.attr('id', 'card_' + id);
                    newCard.attr('data-area-id', id);
                    newCard.find('input, textarea, select').each(function() {
                        var name = $(this).attr('name');
                        if (name) {
                            $(this).attr('name', name.replace(/\[[^\]]+\]/, '[' + id + ']'));
                        }
                        var oldId = $(this).attr('id');
                        if (oldId) {
                            $(this).attr('id', oldId.replace(/_\d+$/, '_' + id).replace(/_new_\d+$/, '_' + id));
                        }
                        if (!$(this).is(':radio')) {
                            $(this).val('');
                        } else {
                            $(this).prop('checked', false);
                        }
                    });

                    newCard.find('label').each(function() {
                        var oldFor = $(this).attr('for');
                        if (oldFor) {
                            $(this).attr('for', oldFor.replace(/_\d+$/, '_' + id).replace(/_new_\d+$/, '_' + id));
                        }
                    });

                    newCard.find('.manual-grade-wrapper, .grade-instructions-wrapper').each(function() {
                        var oldId = $(this).attr('id');
                        if (oldId) {
                            $(this).attr('id', oldId.replace(/_\d+$/, '_' + id).replace(/_new_\d+$/, '_' + id));
                        }
                    });
                    // Set some defaults.
                    newCard.find('input[name^="areatype["][value="' + constants.AREATYPE_GRADED + '"]').prop('checked', true);
                    newCard.find('input[name*="[grammarcorrections]"][value="major"]').prop('checked', true);
                    newCard.find('input[name*="[feedbackmode]"][value="none"]').prop('checked', true);
                    newCard.find('input[name*="[gradingmode]"][value="none"]').prop('checked', true);
                    newCard.find('.manual-grade-wrapper').hide();
                    newCard.find('.grade-instructions-wrapper').hide();
                    newCard.find('.feedback-instructions-wrapper').hide();
                    newCard.find('.standard-fields-wrapper').show();

                    // Set default position values (10, 10, 20, 10) so the form saves them correctly.
                    newCard.find('input[data-attr="left"]').val('10');
                    newCard.find('input[data-attr="top"]').val('10');
                    newCard.find('input[data-attr="width"]').val('20');
                    newCard.find('input[data-attr="height"]').val('10');

                    $('#areas-container').append(newCard);
                }

                dd.createBoxElement(id, num, 10, 10, 20, 10);
                dd.createFeedbackBoxElement(id, num, 0, 0, 0, 0);
                dd.selectArea(id);
                dd.updateLabels();
            });

            // Drag to create new box.
            container.on('mousedown', function(e) {
                if (e.target !== container[0] && !$(e.target).is('img')) {
                    return;
                }

                var startX = e.pageX - container.offset().left;
                var startY = e.pageY - container.offset().top;

                var dragBox = $('<div/>', {
                    css: {
                        position: 'absolute',
                        border: '1px dashed blue',
                        backgroundColor: 'rgba(0,0,255,0.1)',
                        left: startX + 'px',
                        top: startY + 'px',
                        width: 0,
                        height: 0,
                        zIndex: 100
                    }
                });
                container.append(dragBox);

                $(document).on('mousemove.newBox', function(me) {
                    var curX = me.pageX - container.offset().left;
                    var curY = me.pageY - container.offset().top;

                    var w = curX - startX;
                    var h = curY - startY;

                    dragBox.css({
                        width: Math.abs(w) + 'px',
                        height: Math.abs(h) + 'px',
                        left: (w > 0 ? startX : curX) + 'px',
                        top: (h > 0 ? startY : curY) + 'px'
                    });
                });

                $(document).on('mouseup.newBox', function() {
                    $(document).off('mousemove.newBox mouseup.newBox');
                    var finalW = dragBox.width();
                    var finalH = dragBox.height();

                    if (finalW > 10 && finalH > 10) {
                        var id = 'new_' + newIdCounter++;
                        var num = $('#setup-form .area-card').length + 1;

                        var boxX = parseFloat(dragBox.css('left')) / container.width() * 100;
                        var boxY = parseFloat(dragBox.css('top')) / container.height() * 100;
                        var boxW = finalW / container.width() * 100;
                        var boxH = finalH / container.height() * 100;

                        // Add card.
                        var template = $('#setup-form .area-card').first();
                        if (template.length) {
                            var newCard = template.clone();
                            newCard.attr('id', 'card_' + id);
                            newCard.attr('data-area-id', id);
                            newCard.find('input, textarea, select').each(function() {
                                var name = $(this).attr('name');
                                if (name) {
                                    $(this).attr('name', name.replace(/\[[^\]]+\]/, '[' + id + ']'));
                                }
                                var oldInputId = $(this).attr('id');
                                if (oldInputId) {
                                    $(this).attr('id', oldInputId.replace(/_\d+$/, '_' + id).replace(/_new_\d+$/, '_' + id));
                                }
                                if (!$(this).is(':radio')) {
                                    $(this).val('');
                                } else {
                                    $(this).prop('checked', false);
                                }
                            });
                            newCard.find('label').each(function() {
                                var oldFor = $(this).attr('for');
                                if (oldFor) {
                                    $(this).attr('for', oldFor.replace(/_\d+$/, '_' + id).replace(/_new_\d+$/, '_' + id));
                                }
                            });
                            newCard.find('.manual-grade-wrapper, .grade-instructions-wrapper').each(function() {
                                var oldWrapId = $(this).attr('id');
                                if (oldWrapId) {
                                    $(this).attr('id', oldWrapId.replace(/_\d+$/, '_' + id).replace(/_new_\d+$/, '_' + id));
                                }
                            });
                            // Set defaults.
                            newCard.find('input[name^="areatype["][value="' + constants.AREATYPE_GRADED + '"]')
                                .prop('checked', true);
                            newCard.find('input[name*="[grammarcorrections]"][value="major"]').prop('checked', true);
                            newCard.find('input[name*="[feedbackmode]"][value="none"]').prop('checked', true);
                            newCard.find('input[name*="[gradingmode]"][value="none"]').prop('checked', true);
                            newCard.find('.manual-grade-wrapper').hide();
                            newCard.find('.grade-instructions-wrapper').hide();
                            newCard.find('.feedback-instructions-wrapper').hide();
                            newCard.find('.standard-fields-wrapper').show();
                            // Set position from drag.
                            newCard.find('input[data-attr="left"]').val(boxX.toFixed(1));
                            newCard.find('input[data-attr="top"]').val(boxY.toFixed(1));
                            newCard.find('input[data-attr="width"]').val(boxW.toFixed(1));
                            newCard.find('input[data-attr="height"]').val(boxH.toFixed(1));

                            $('#areas-container').append(newCard);
                        }


                        dd.createBoxElement(id, num, boxX, boxY, boxW, boxH);
                        dd.createFeedbackBoxElement(id, num, 0, 0, 0, 0);
                        dd.selectArea(id);
                        dd.updateLabels();
                    }
                    dragBox.remove();
                });
            });
        },

        setup_strings: function() {
            var dd = this;
            Str.get_strings([
                {key: 'responsearea', component: component},
                {key: 'configured', component: component},
                {key: 'notconfigured', component: component},
                {key: 'allconfigured', component: component},
                {key: 'noneconfigured', component: component},
                {key: 'nconfigured', component: component},
                {key: 'feedbackarea', component: component}
            ]).done(function(s) {
                var i = 0;
                dd.strings.responsearea = s[i++];
                dd.strings.configured = s[i++];
                dd.strings.notconfigured = s[i++];
                dd.strings.allconfigured = s[i++];
                dd.strings.noneconfigured = s[i++];
                dd.strings.nconfigured = s[i++];
                dd.strings.feedbackarea = s[i++];
            });
        },

        bindVanillaJSInteractions: function(boxElement) {
            var dd = this;
            var isDragging = false;
            var isResizing = false;
            var startX, startY, startLeft, startTop, startWidth, startHeight;
            var currentHandle = '';

            // Add resize handles.
            var hs = 'position:absolute; width:10px; height:10px; background:white; border:1px solid black;';
            var nw = $('<div class="resize-handle nw" style="' + hs + ' top:-5px; left:-5px; cursor:nwse-resize;"></div>');
            var ne = $('<div class="resize-handle ne" style="' + hs + ' top:-5px; right:-5px; cursor:nesw-resize;"></div>');
            var sw = $('<div class="resize-handle sw" style="' + hs + ' bottom:-5px; left:-5px; cursor:nesw-resize;"></div>');
            var se = $('<div class="resize-handle se" style="' + hs + ' bottom:-5px; right:-5px; cursor:nwse-resize;"></div>');
            boxElement.append(nw).append(ne).append(sw).append(se);

            boxElement.on('mousedown', function(e) {
                e.preventDefault();
                dd.selectArea(boxElement.attr('data-area-id'));

                var container = $('#image-container');
                startX = e.clientX;
                startY = e.clientY;
                startLeft = parseFloat(boxElement.css('left')) || 0;
                startTop = parseFloat(boxElement.css('top')) || 0;
                startWidth = parseFloat(boxElement.css('width')) || 0;
                startHeight = parseFloat(boxElement.css('height')) || 0;

                if ($(e.target).hasClass('resize-handle')) {
                    isResizing = true;
                    if ($(e.target).hasClass('nw')) {
                        currentHandle = 'nw';
                    }
                    if ($(e.target).hasClass('ne')) {
                        currentHandle = 'ne';
                    }
                    if ($(e.target).hasClass('sw')) {
                        currentHandle = 'sw';
                    }
                    if ($(e.target).hasClass('se')) {
                        currentHandle = 'se';
                    }
                } else {
                    isDragging = true;
                    boxElement.css('cursor', 'move');
                }

                $(document).on('mousemove.paperBox', function(me) {
                    var containerW = container.width();
                    var containerH = container.height();
                    var dx = me.clientX - startX;
                    var dy = me.clientY - startY;

                    if (isDragging) {
                        var newLeft = startLeft + dx;
                        var newTop = startTop + dy;
                        boxElement.css({left: newLeft + 'px', top: newTop + 'px'});
                    } else if (isResizing) {
                        if (currentHandle === 'se') {
                            boxElement.css({width: (startWidth + dx) + 'px', height: (startHeight + dy) + 'px'});
                        } else if (currentHandle === 'sw') {
                            boxElement.css({
                                left: (startLeft + dx) + 'px',
                                width: (startWidth - dx) + 'px',
                                height: (startHeight + dy) + 'px'
                            });
                        } else if (currentHandle === 'ne') {
                            boxElement.css({
                                top: (startTop + dy) + 'px',
                                width: (startWidth + dx) + 'px',
                                height: (startHeight - dy) + 'px'
                            });
                        } else if (currentHandle === 'nw') {
                            boxElement.css({
                                left: (startLeft + dx) + 'px',
                                top: (startTop + dy) + 'px',
                                width: (startWidth - dx) + 'px',
                                height: (startHeight - dy) + 'px'
                            });
                        }
                    }

                    // Update input form percentages.
                    var areaId = boxElement.attr('data-area-id');
                    var isFeedback = boxElement.hasClass('feedback-box');
                    var inputPrefix = isFeedback ? 'input.fb-pos-input' : 'input.pos-input';

                    var cLeft = parseFloat(boxElement.css('left')) / containerW * 100;
                    var cTop = parseFloat(boxElement.css('top')) / containerH * 100;
                    var cWidth = parseFloat(boxElement.css('width')) / containerW * 100;
                    var cHeight = parseFloat(boxElement.css('height')) / containerH * 100;

                    $(inputPrefix + '[data-area="' + areaId + '"][data-attr="left"]').val(cLeft.toFixed(1));
                    $(inputPrefix + '[data-area="' + areaId + '"][data-attr="top"]').val(cTop.toFixed(1));
                    $(inputPrefix + '[data-area="' + areaId + '"][data-attr="width"]').val(cWidth.toFixed(1));
                    $(inputPrefix + '[data-area="' + areaId + '"][data-attr="height"]').val(cHeight.toFixed(1));
                });

                $(document).on('mouseup.paperBox', function() {
                    isDragging = false;
                    isResizing = false;
                    boxElement.css('cursor', 'default');
                    $(document).off('mousemove.paperBox mouseup.paperBox');
                });
            });
        },

        selectArea: function(areaId) {
            $('.paper-box').css('border', '2px solid green').css('z-index', 10);
            $('#box_' + areaId).css('border', '3px solid blue').css('z-index', 20);

            $('.feedback-box').css('border', '2px solid orange').css('z-index', 11);
            $('#fb_box_' + areaId).css('border', '3px solid red').css('z-index', 21);

            $('.area-card').hide();
            $('#card_' + areaId).fadeIn();
        },

        createBoxElement: function(id, num, x, y, w, h) {
            var dd = this;
            var boxTitle = dd.strings.responsearea ? dd.strings.responsearea.replace('{$a}', num) : 'Response Area #' + num;
            var box = $('<div/>', {
                id: 'box_' + id,
                'class': 'paper-box',
                'data-area-id': id,
                css: {
                    position: 'absolute',
                    border: '2px solid green',
                    backgroundColor: 'rgba(0,128,0,0.2)',
                    left: x + '%',
                    top: y + '%',
                    width: w + '%',
                    height: h + '%'
                },
                title: boxTitle
            });

            var label = $('<span/>', {
                'class': 'box-label',
                text: '#' + num,
                css: {
                    backgroundColor: 'green',
                    color: 'white',
                    padding: '2px 5px',
                    position: 'absolute',
                    top: 0,
                    left: 0,
                    fontSize: '12px'
                }
            });

            box.append(label);
            $('#image-container').append(box);
            this.bindVanillaJSInteractions(box);
            return box;
        },

        createFeedbackBoxElement: function(id, num, x, y, w, h) {
            var dd = this;
            console.log("createFeedbackBoxElement called", {id: id, x: x, y: y, w: w, h: h});
            var boxTitle = dd.strings.feedbackarea ? dd.strings.feedbackarea.replace('{$a}', num) : 'Feedback Area #' + num;
            
            // If coordinates are 0, default to bottom 30% of parent box.
            if (parseFloat(x) == 0 && parseFloat(y) == 0 && parseFloat(w) == 0 && parseFloat(h) == 0) {
                var parentBox = $('#box_' + id);
                if (parentBox.length) {
                    var px = parseFloat(parentBox[0].style.left);
                    var py = parseFloat(parentBox[0].style.top);
                    var pw = parseFloat(parentBox[0].style.width);
                    var ph = parseFloat(parentBox[0].style.height);
                    x = px;
                    w = pw;
                    h = ph * 0.3;
                    y = py + ph * 0.7;
                    console.log("Defaulting feedback box to bottom 30% of parent", {x: x, y: y, w: w, h: h});
                }
            }

            var box = $('<div/>', {
                id: 'fb_box_' + id,
                'class': 'paper-box feedback-box',
                'data-area-id': id,
                css: {
                    position: 'absolute',
                    border: '2px solid orange',
                    backgroundColor: 'rgba(255,165,0,0.2)',
                    left: x + '%',
                    top: y + '%',
                    width: w + '%',
                    height: h + '%',
                    display: 'none',
                    zIndex: 11
                },
                title: boxTitle
            });

            var label = $('<span/>', {
                'class': 'box-label',
                text: 'FB #' + num,
                css: {
                    backgroundColor: 'orange',
                    color: 'white',
                    padding: '2px 5px',
                    position: 'absolute',
                    bottom: 0,
                    right: 0,
                    fontSize: '10px'
                }
            });

            box.append(label);
            $('#image-container').append(box);
            console.log("Feedback box appended to DOM", '#fb_box_' + id);

            // Sync with initial feedback mode.
            var card = $('#card_' + id);
            if (card.length) {
                var fm = card.find('.fm-radio:checked').val();
                console.log("Initial feedback mode for area " + id + ":", fm);
                if (fm && fm !== 'none') {
                    box.show();
                }
            }

            this.bindVanillaJSInteractions(box);
            return box;
        },

        renderStatusIcons: function() {
            var dd = this;
            var container = $('#status-icons-container');
            if (!container.length) {
                return;
            }

            container.empty();

            var num = 1;
            var totalAreas = 0;
            var configuredAreas = 0;

            $('#setup-form .area-card').each(function() {
                totalAreas++;
                var areaId = $(this).data('area-id');
                var questionVal = $(this).find('textarea[name^="question"]').val() || '';
                var areaTypeVal = $(this).find('.areatype-radio:checked').val();
                // Only a standard graded area needs a question to count as configured; every
                // other type is fully described by the role itself.
                var needsQuestion = areaTypeVal === undefined
                    || Number(areaTypeVal) === constants.AREATYPE_GRADED;

                var isConfigured = !needsQuestion || questionVal.trim() !== '';
                if (isConfigured) {
                    configuredAreas++;
                }

                var iconClass = isConfigured ? 'fa-check-square-o text-success' : 'fa-square-o text-secondary';
                var statusText = isConfigured ? dd.strings.configured : dd.strings.notconfigured;
                var iconTitle = (dd.strings.responsearea ? dd.strings.responsearea.replace('{$a}', num) : 'Area #' + num);
                iconTitle += ' (' + statusText + ')';

                var iconWrapper = $('<div/>', {
                    'class': 'status-icon mr-3',
                    'data-area-id': areaId,
                    'title': iconTitle,
                    css: {cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: '5px'}
                });

                iconWrapper.append($('<i/>', {'class': 'fa ' + iconClass + ' fa-lg'}));
                iconWrapper.append($('<span/>', {text: '#' + num, css: {fontWeight: 'bold'}}));

                iconWrapper.on('click', function() {
                    dd.selectArea(areaId);
                });

                container.append(iconWrapper);
                num++;
            });

            var summaryLabel = $('#status-summary-label');
            if (summaryLabel.length) {
                if (totalAreas === 0) {
                    summaryLabel.text('');
                } else if (configuredAreas === totalAreas) {
                    summaryLabel.text(dd.strings.allconfigured || 'All configured!!');
                    summaryLabel.removeClass('text-secondary text-warning').addClass('text-success');
                } else if (configuredAreas === 0) {
                    summaryLabel.text(dd.strings.noneconfigured || 'None configured.');
                    summaryLabel.removeClass('text-success text-warning').addClass('text-secondary');
                } else {
                    var summaryText = (dd.strings.nconfigured || '{$a->configured}/{$a->total} configured.')
                        .replace('{$a->configured}', configuredAreas)
                        .replace('{$a->total}', totalAreas);
                    summaryLabel.text(summaryText);
                    summaryLabel.removeClass('text-success text-secondary').addClass('text-warning');
                }
            }
        },

        updateLabels: function() {
            var dd = this;
            var num = 1;
            $('#setup-form .area-card').each(function() {
                var areaId = $(this).data('area-id');
                var labelText = dd.strings.responsearea ? dd.strings.responsearea.replace('{$a}', num) : 'Response Area #' + num;
                // Update the card header title (e.g. "Configuration for Area #5").
                $(this).find('.card-title .area-display-num').text('#' + num);
                // Update the first fieldset legend inside the card body.
                $(this).find('legend').first().text(labelText);
                $('#box_' + areaId + ' .box-label').text('#' + num);
                $('#fb_box_' + areaId + ' .box-label').text('FB #' + num);
                num++;
            });
            this.renderStatusIcons();
        }
    };
});
