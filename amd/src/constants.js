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
 * Shared constants, mirroring the PHP side.
 *
 * Keep in step with classes/constants.php - these values are written to the database and
 * rendered into the page by the same code, so the two must agree.
 *
 * @module     mod_paper/constants
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    "use strict";

    return {
        // Response area types (paper_response_areas.areatype).
        AREATYPE_GRADED: 0,
        AREATYPE_NAME: 1,
        AREATYPE_USERNAME: 2,
        AREATYPE_DISPLAYONLY: 3,
        AREATYPE_UNGRADED: 4,

        // Per-area font selection (paper_response_areas.responsefont/feedbackfont).
        FONT_TARGET: 'target',
        FONT_NATIVE: 'native',
    };
});
