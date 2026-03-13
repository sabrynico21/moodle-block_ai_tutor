<?php
/**
 * External services for alma_ai_tutor block
 * @copyright 2025 Université TÉLUQ and the UNIVERSITÉ GASTON BERGER DE SAINT-LOUIS
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'block_alma_ai_tutor_send_question' => [
        'classname'   => 'block_alma_ai_tutor\external\send_question',
        'methodname'  => 'execute',
        'classpath'   => '',
        'description' => 'Send a question to the chatbot',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],
    'block_alma_ai_tutor_save_prompt' => [
        'classname'   => 'block_alma_ai_tutor\external\save_prompt',
        'methodname'  => 'execute',
        'classpath'   => '',
        'description' => 'Save or update a chatbot prompt',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],
    'block_alma_ai_tutor_upload_files' => [
        'classname'   => 'block_alma_ai_tutor\external\upload_files',
        'methodname'  => 'execute',
        'classpath'   => '',
        'description' => 'Upload and index multiple files for the chatbot',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'moodle/course:update',
        'loginrequired' => true,
    ],
];

$services = [
    'Chatbot Services' => [
        'functions' => [
            'block_alma_ai_tutor_send_question',
            'block_alma_ai_tutor_save_prompt',
            'block_alma_ai_tutor_upload_files'
        ],
        'restrictedusers' => 0,
        'enabled' => 1,
        'downloadfiles' => 0,
        'uploadfiles' => 1
    ]
];