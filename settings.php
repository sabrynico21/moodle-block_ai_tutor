<?php
/**
 * @copyright 2025 Université TÉLUQ and the UNIVERSITÉ GASTON BERGER DE SAINT-LOUIS
 */

require_once($CFG->dirroot . '/blocks/alma_ai_tutor/classes/admin_setting_test_button.php');

// settings.php
defined('MOODLE_INTERNAL') || die();

// Check if the user has the capability to manage plugin settings.
if (has_capability('block/alma_ai_tutor:manage', context_system::instance()) && $ADMIN->fulltree) {

    // AWS region for Bedrock services.
    $settings->add(new admin_setting_configtext(
        'block_alma_ai_tutor/bedrock_region',
        get_string('bedrock_region', 'block_alma_ai_tutor'),
        get_string('bedrock_region_desc', 'block_alma_ai_tutor'),
        'eu-north-1',
        PARAM_ALPHANUMEXT
    ));

    // AWS access key for Bedrock calls.
    $settings->add(new admin_setting_configtext(
        'block_alma_ai_tutor/bedrock_access_key',
        get_string('bedrock_access_key', 'block_alma_ai_tutor'),
        get_string('bedrock_access_key_desc', 'block_alma_ai_tutor'),
        '',
        PARAM_TEXT
    ));

    // AWS secret key for Bedrock calls.
    $settings->add(new admin_setting_configpasswordunmask(
        'block_alma_ai_tutor/bedrock_secret_key',
        get_string('bedrock_secret_key', 'block_alma_ai_tutor'),
        get_string('bedrock_secret_key_desc', 'block_alma_ai_tutor'),
        ''
    ));

    // Bedrock Knowledge Base ID used as vector store.
    $settings->add(new admin_setting_configtext(
        'block_alma_ai_tutor/bedrock_knowledge_base_id',
        get_string('bedrock_knowledge_base_id', 'block_alma_ai_tutor'),
        get_string('bedrock_knowledge_base_id_desc', 'block_alma_ai_tutor'),
        '',
        PARAM_TEXT
    ));

    // S3 bucket name backing the Knowledge Base data source.
    $settings->add(new admin_setting_configtext(
        'block_alma_ai_tutor/bedrock_s3_bucket',
        get_string('bedrock_s3_bucket', 'block_alma_ai_tutor'),
        get_string('bedrock_s3_bucket_desc', 'block_alma_ai_tutor'),
        'moodle-s3b',
        PARAM_TEXT
    ));

    // Optional Bedrock data source id for StartIngestionJob.
    $settings->add(new admin_setting_configtext(
        'block_alma_ai_tutor/bedrock_data_source_id',
        get_string('bedrock_data_source_id', 'block_alma_ai_tutor'),
        get_string('bedrock_data_source_id_desc', 'block_alma_ai_tutor'),
        '',
        PARAM_TEXT
    ));

    // Bedrock model id used for generation.
    $settings->add(new admin_setting_configtext(
        'block_alma_ai_tutor/bedrock_chat_model_id',
        get_string('bedrock_chat_model_id', 'block_alma_ai_tutor'),
        get_string('bedrock_chat_model_id_desc', 'block_alma_ai_tutor'),
        'cohere.command-r-v1:0',
        PARAM_TEXT
    ));

    // Session timeout (minutes) before a new chat session is automatically created.
    $settings->add(new admin_setting_configtext(
        'block_alma_ai_tutor/chat_session_timeout_minutes',
        get_string('chat_session_timeout_minutes', 'block_alma_ai_tutor'),
        get_string('chat_session_timeout_minutes_desc', 'block_alma_ai_tutor'),
        30,
        PARAM_INT
    ));

    // Button to test API keys
    $settings->add(new admin_setting_test_button(
        'block_alma_ai_tutor/test_api_keys',
        get_string('test_api_keys', 'block_alma_ai_tutor'),
        get_string('test_api_keys_desc', 'block_alma_ai_tutor'),
        ''
    ));
}
