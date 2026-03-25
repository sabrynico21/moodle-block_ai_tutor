<?php
/**
 * External service for listing a user's chatbot sessions.
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

class get_user_sessions extends external_api {

    /**
     * Define parameters for the external function.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'User ID'),
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'sectionid' => new external_value(PARAM_INT, 'Section ID', VALUE_DEFAULT, 0),
            'instanceid' => new external_value(PARAM_INT, 'Block instance ID', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Execute the external function.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $sectionid
     * @param int $instanceid
     * @return array
     */
    public static function execute($userid, $courseid, $sectionid = 0, $instanceid = 0) {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'courseid' => $courseid,
            'sectionid' => $sectionid,
            'instanceid' => $instanceid,
        ]);

        require_login();
        $context = context_course::instance($params['courseid']);
        self::validate_context($context);

        if ((int)$USER->id !== (int)$params['userid']) {
            throw new invalid_parameter_exception('Invalid user');
        }

        $sql = "SELECT s.id,
                       s.title,
                       s.timecreated,
                       s.timemodified,
                       COUNT(c.id) AS messagecount
                  FROM {block_alma_ai_tutor_chat_sessions} s
             LEFT JOIN {block_alma_ai_tutor_conversations} c ON c.sessionid = s.id
                 WHERE s.userid = :userid
                   AND s.courseid = :courseid
                   AND s.sectionid = :sectionid
                   AND s.instanceid = :instanceid
              GROUP BY s.id, s.title, s.timecreated, s.timemodified
              ORDER BY s.timemodified DESC";

        $records = $DB->get_records_sql($sql, [
            'userid' => $params['userid'],
            'courseid' => $params['courseid'],
            'sectionid' => $params['sectionid'],
            'instanceid' => $params['instanceid'],
        ]);

        $sessions = [];
        foreach ($records as $record) {
            $sessions[] = [
                'id' => (int)$record->id,
                'title' => (string)($record->title ?? ''),
                'timecreated' => (int)$record->timecreated,
                'timemodified' => (int)$record->timemodified,
                'messagecount' => (int)$record->messagecount,
            ];
        }

        return [
            'status' => 'success',
            'sessions' => $sessions,
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
            'sessions' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Session ID'),
                    'title' => new external_value(PARAM_TEXT, 'Session title'),
                    'timecreated' => new external_value(PARAM_INT, 'Creation timestamp'),
                    'timemodified' => new external_value(PARAM_INT, 'Last activity timestamp'),
                    'messagecount' => new external_value(PARAM_INT, 'Number of message pairs in session'),
                ])
            ),
        ]);
    }
}
