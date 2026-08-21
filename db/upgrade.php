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
 * Upgrade script for mod_paper
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_paper_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2024041701) {

        // Define field targetlanguagefont to be added to paper.
        $table = new xmldb_table('paper');
        $field = new xmldb_field('targetlanguagefont', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'courier', 'targetlanguage');

        // Conditionally launch add field targetlanguagefont.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field feedbacklanguagefont to be added to paper.
        $field2 = new xmldb_field('feedbacklanguagefont', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'freesans', 'feedbacklanguage');

        // Conditionally launch add field feedbacklanguagefont.
        if (!$dbman->field_exists($table, $field2)) {
            $dbman->add_field($table, $field2);
        }

        // Paper savepoint reached.
        upgrade_mod_savepoint(true, 2024041701, 'paper');
    }

    if ($oldversion < 2024042600) {

        // Define table paper_grading_presets to be created.
        $table = new xmldb_table('paper_grading_presets');

        // Adding fields to table paper_grading_presets.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('content', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table paper_grading_presets.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Adding indexes to table paper_grading_presets.
        $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);

        // Conditionally launch create table for paper_grading_presets.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Paper savepoint reached.
        upgrade_mod_savepoint(true, 2024042600, 'paper');
    }

    if ($oldversion < 2024042700) {

        // Define field feedbackmode to be added to paper_response_areas.
        $table = new xmldb_table('paper_response_areas');
        $field = new xmldb_field('feedbackmode', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'none', 'feedbackoverall');

        // Conditionally launch add field feedbackmode.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Paper savepoint reached.
        upgrade_mod_savepoint(true, 2024042700, 'paper');
    }

    if ($oldversion < 2024042701) {
        $table = new xmldb_table('paper');
        $field = new xmldb_field('showtotalscore', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1', 'timemodified');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2024042701, 'paper');
    }

    if ($oldversion < 2024042702) {

        // Define field gradingmode to be added to paper_response_areas.
        $table = new xmldb_table('paper_response_areas');
        $field = new xmldb_field('gradingmode', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'none', 'feedbackmode');

        // Conditionally launch add field gradingmode.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field feedbackinstructions to be added to paper_response_areas.
        $field2 = new xmldb_field('feedbackinstructions', XMLDB_TYPE_TEXT, null, null, null, null, null, 'gradingmode');

        // Conditionally launch add field feedbackinstructions.
        if (!$dbman->field_exists($table, $field2)) {
            $dbman->add_field($table, $field2);
        }

        // Paper savepoint reached.
        upgrade_mod_savepoint(true, 2024042702, 'paper');
    }

    if ($oldversion < 2024042704) {

        // Define field feedbackmode to be added to paper_response_areas.
        $table = new xmldb_table('paper_response_areas');

        // Define fields to check/add.
        $fields = [
            'feedbackmode' => new xmldb_field('feedbackmode', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'none', 'grammarcorrections'),
            'gradingmode' => new xmldb_field('gradingmode', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'none', 'feedbackmode'),
            'feedbackinstructions' => new xmldb_field('feedbackinstructions', XMLDB_TYPE_TEXT, null, null, null, null, null, 'gradingmode')
        ];

        foreach ($fields as $name => $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Define field showtotalscore to be added to paper.
        $table2 = new xmldb_table('paper');
        $field2 = new xmldb_field('showtotalscore', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1', 'timemodified');

        if (!$dbman->field_exists($table2, $field2)) {
            $dbman->add_field($table2, $field2);
        }

        // Paper savepoint reached.
        upgrade_mod_savepoint(true, 2024042704, 'paper');
    }

    if ($oldversion < 2024042705) {
        $table = new xmldb_table('paper_response_areas');

        $fields = [
            'fb_x' => new xmldb_field('fb_x', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0', 'box_h'),
            'fb_y' => new xmldb_field('fb_y', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0', 'fb_x'),
            'fb_w' => new xmldb_field('fb_w', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0', 'fb_y'),
            'fb_h' => new xmldb_field('fb_h', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0', 'fb_w')
        ];

        foreach ($fields as $name => $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_mod_savepoint(true, 2024042705, 'paper');
    }

    if ($oldversion < 2024042707) {
        // Create paper_feedback_presets table.
        $table = new xmldb_table('paper_feedback_presets');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('content', XMLDB_TYPE_TEXT, 'big', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2024042707, 'paper');
    }

    if ($oldversion < 2024042708) {
        // Define fields snippetx/snippety/snippetw/snippeth to be added to paper_eval_items.
        $table = new xmldb_table('paper_eval_items');

        $fields = [
            'snippetx' => new xmldb_field('snippetx', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null, 'itemgrade'),
            'snippety' => new xmldb_field('snippety', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null, 'snippetx'),
            'snippetw' => new xmldb_field('snippetw', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null, 'snippety'),
            'snippeth' => new xmldb_field('snippeth', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null, 'snippetw'),
        ];

        foreach ($fields as $name => $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_mod_savepoint(true, 2024042708, 'paper');
    }

    if ($oldversion < 2024042709) {
        // Move "not yet evaluated" from an empty/space string onto a real NULL.
        //
        // Previously a new item stored correctedtext = '' to mean "pending" and a single
        // space to mean "processed, but there was nothing to correct". MySQL/MariaDB's
        // default PAD SPACE collations compare those two as equal, so every blank answer
        // matched the pending query on every run, forever. NULL can't be confused with
        // either, and both columns were already nullable.

        // Items that never got a grade are the genuinely pending ones.
        $DB->execute("UPDATE {paper_eval_items}
                         SET correctedtext = NULL, feedback = NULL
                       WHERE itemgrade IS NULL");

        // Anything already graded is processed; normalise the old space sentinel so it
        // doesn't print as a stray space in the review UI or the exported PDF. Items whose
        // correction came back empty despite having a grade stay processed - re-running
        // them is a teacher's call via Re-evaluate, not something to trigger on upgrade.
        $DB->execute("UPDATE {paper_eval_items}
                         SET correctedtext = ''
                       WHERE itemgrade IS NOT NULL
                         AND correctedtext = ' '");

        upgrade_mod_savepoint(true, 2024042709, 'paper');
    }

    if ($oldversion < 2024042710) {
        // Per-activity scan alignment. NULL means "inherit the site default", so existing
        // papers keep whatever the site is configured for rather than being pinned to the
        // value that happened to be in force at upgrade time.
        $table = new xmldb_table('paper');
        $fields = [
            'alignoffsetx' => new xmldb_field('alignoffsetx', XMLDB_TYPE_NUMBER, '10, 4', null, null, null, null,
                'showtotalscore'),
            'alignoffsety' => new xmldb_field('alignoffsety', XMLDB_TYPE_NUMBER, '10, 4', null, null, null, null,
                'alignoffsetx'),
            'alignscalex' => new xmldb_field('alignscalex', XMLDB_TYPE_NUMBER, '10, 4', null, null, null, null,
                'alignoffsety'),
            'alignscaley' => new xmldb_field('alignscaley', XMLDB_TYPE_NUMBER, '10, 4', null, null, null, null,
                'alignscalex'),
        ];

        foreach ($fields as $name => $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_mod_savepoint(true, 2024042710, 'paper');
    }

    if ($oldversion < 2024042711) {
        // The area role marker started life as a boolean and grew into an enum, so its name
        // stopped describing its contents. No data migration - the stored integers keep the
        // meanings they already had, and "ungraded" is a new value nothing uses yet.
        $table = new xmldb_table('paper_response_areas');
        $field = new xmldb_field('isnamefield', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'responsenumber');

        if ($dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'areatype');
        }

        upgrade_mod_savepoint(true, 2024042711, 'paper');
    }

    if ($oldversion < 2024042713) {
        // Per-area font overrides. The defaults are the two "inherit" sentinels, which is
        // exactly the behaviour every existing area already had (student text in the target
        // language font, feedback in the native one), so nothing changes on upgrade.
        // The defaults are spelled out rather than taken from constants::M_FONT_*, since an
        // upgrade step has to keep meaning what it meant when it was written.
        $table = new xmldb_table('paper_response_areas');
        $fields = [
            new xmldb_field('responsefont', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'target', 'feedbackinstructions'),
            new xmldb_field('feedbackfont', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'native', 'responsefont'),
        ];

        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_mod_savepoint(true, 2024042713, 'paper');
    }

    return true;
}
