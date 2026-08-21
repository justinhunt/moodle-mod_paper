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
 * Drives the evaluation PDF preview on the scan alignment page.
 *
 * The preview is the real generated evaluation, served by the pluginfile hook and rebuilt on
 * every request, so switching student or hitting reload is enough to see the current
 * alignment, fonts and layout without leaving the page.
 *
 * @module     mod_paper/alignment
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery'], function($) {
    "use strict";

    /**
     * Adds a changing cache-buster to a pluginfile URL.
     *
     * The PDF is generated fresh per request but the browser's PDF viewer will happily reuse
     * what it already has for an unchanged URL, which is exactly the stale render this
     * preview exists to avoid.
     *
     * @param {String} url The base preview URL.
     * @return {String} The URL with a unique parameter appended.
     */
    var bust = function(url) {
        var separator = (url.indexOf('?') === -1) ? '?' : '&';
        return url + separator + 'cachebust=' + Date.now();
    };

    return {
        init: function() {
            var frame = $('#paper-preview-frame');
            if (!frame.length) {
                return;
            }

            var load = function(url) {
                frame.attr('src', bust(url));
            };

            load(frame.data('previewurl'));

            $('#paper-preview-selector').on('change', function() {
                var url = $(this).find('option:selected').data('url');
                if (url) {
                    frame.data('previewurl', url);
                    load(url);
                }
            });

            $('#paper-preview-reload').on('click', function(e) {
                e.preventDefault();
                load(frame.data('previewurl'));
            });
        }
    };
});
