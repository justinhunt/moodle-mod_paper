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
 * Services definition for mod_paper
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$functions = [
    'mod_paper_check_status' => [
        'classname'   => 'mod_paper\external\external_api',
        'methodname'  => 'check_status',
        'description' => 'Check the processing status of evaluations',
        'type'        => 'read',
        'ajax'        => true,
    ],
    'mod_paper_update_eval_item' => [
        'classname'   => 'mod_paper\external\external_api',
        'methodname'  => 'update_eval_item',
        'description' => 'Update an evaluation item with grade and feedback',
        'type'        => 'write',
        'ajax'        => true,
    ],
];
