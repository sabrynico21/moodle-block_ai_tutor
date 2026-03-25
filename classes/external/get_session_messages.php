<?php
/**
 * External service for listing messages of a session.
 *
 * @package    block_alma_ai_tutor
 */

namespace block_alma_ai_tutor\external;

use context_course;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use invalid_parameter_exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_session_messages extends external_api {

    /**
     * Define parameters for the external function.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'User ID'),
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'sessionid' => new external_value(PARAM_INT, 'Session ID'),
        ]);
    }

    /**
     * Execute the external function.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $sessionid
     * @return array
     */
    public static function execute($userid, $courseid, $sessionid) {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'courseid' => $courseid,
            'sessionid' => $sessionid,
        ]);

        require_login();
        $context = context_course::instance($params['courseid']);
        self::validate_context($context);

        if ((int)$USER->id !== (int)$params['userid']) {
            throw new invalid_parameter_exception('Invalid user');
        }

        $session = $DB->get_record('block_alma_ai_tutor_chat_sessions', [
            'id' => $params['sessionid'],
            'userid' => $params['userid'],
            'courseid' => $params['courseid'],
        ]);

        if (!$session) {
            throw new invalid_parameter_exception('Session not found');
        }

        $records = $DB->get_records('block_alma_ai_tutor_conversations', [
            'sessionid' => $params['sessionid'],
        ], 'timecreated ASC', 'id, question, answer, timecreated');

        $messages = [];
        foreach ($records as $record) {
            $messages[] = [
                'id' => (int)$record->id,
                'question' => (string)$record->question,
                'answer' => (string)$record->answer,
                'timecreated' => (int)$record->timecreated,
            ];
        }

        return [
            'status' => 'success',
            'sessionid' => (int)$session->id,
            'messages' => $messages,
        ];
    }

    /**
     * Define return values for the external function.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Status of the request'),
            'sessionid' => new external_value(PARAM_INT, 'Session ID'),
            'messages' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Conversation row ID'),
                    'question' => new external_value(PARAM_RAW, 'User message'),
                    'answer' => new external_value(PARAM_RAW, 'Bot answer'),
                    'timecreated' => new external_value(PARAM_INT, 'Message timestamp'),
                ])
            ),
        ]);
    }
}
