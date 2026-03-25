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
    'block_alma_ai_tutor_get_user_sessions' => [
        'classname'   => 'block_alma_ai_tutor\\external\\get_user_sessions',
        'methodname'  => 'execute',
        'classpath'   => '',
        'description' => 'List chatbot sessions for the current user in a course/section/instance',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],
    'block_alma_ai_tutor_get_session_messages' => [
        'classname'   => 'block_alma_ai_tutor\\external\\get_session_messages',
        'methodname'  => 'execute',
        'classpath'   => '',
        'description' => 'Get chatbot messages for a specific user-owned session',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],
];

$services = [
    'Chatbot Services' => [
        'functions' => [
            'block_alma_ai_tutor_send_question',
            'block_alma_ai_tutor_save_prompt',
            'block_alma_ai_tutor_upload_files',
            'block_alma_ai_tutor_get_user_sessions',
            'block_alma_ai_tutor_get_session_messages'
        ],
        'restrictedusers' => 0,
        'enabled' => 1,
        'downloadfiles' => 0,
        'uploadfiles' => 1
    ]
];