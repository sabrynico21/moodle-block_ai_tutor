<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_block_alma_ai_tutor_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026031801) {

        // Add instanceid to prompts table
        $table = new xmldb_table('block_alma_ai_tutor_prompts');

        $field = new xmldb_field('instanceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'courseid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('sectionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'instanceid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add sectionid to conversations table
        $table = new xmldb_table('block_alma_ai_tutor_conversations');

        $field = new xmldb_field('sectionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'courseid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_block_savepoint(true, 2026031801, 'alma_ai_tutor');
    }

    if ($oldversion < 2026032401) {

        // Ensure prompts table has timemodified.
        $table = new xmldb_table('block_alma_ai_tutor_prompts');
        $field = new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timecreated');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Ensure conversations table has instanceid.
        $table = new xmldb_table('block_alma_ai_tutor_conversations');
        $field = new xmldb_field('instanceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'sectionid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Create chat sessions table.
        $table = new xmldb_table('block_alma_ai_tutor_chat_sessions');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('sectionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('instanceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);

            $table->add_index('usercourseix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);
            $table->add_index('scopeix', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'sectionid', 'instanceid']);
            $table->add_index('timemodifiedix', XMLDB_INDEX_NOTUNIQUE, ['timemodified']);

            $dbman->create_table($table);
        }

        // Add sessionid to conversations table after chat sessions exists.
        $table = new xmldb_table('block_alma_ai_tutor_conversations');
        $field = new xmldb_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'instanceid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_block_savepoint(true, 2026032401, 'alma_ai_tutor');
    }

    return true;
}