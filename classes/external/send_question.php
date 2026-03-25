<?php
/**
 * External service for sending questions to chatbot
 * @copyright 2025 Université TÉLUQ and the UNIVERSITÉ GASTON BERGER DE SAINT-LOUIS
 */

namespace block_alma_ai_tutor\external;

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use context_course;
use invalid_parameter_exception;
use dml_exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class send_question extends external_api
{

    /**
     * Define parameters for the external function
     */
    public static function execute_parameters()
    {
        return new external_function_parameters([
            'question' => new external_value(PARAM_RAW, 'The question to send'),
            'userid' => new external_value(PARAM_INT, 'User ID'),
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'sansrag' => new external_value(PARAM_BOOL, 'Without RAG', VALUE_DEFAULT, false),
            'sectionid'  => new external_value(PARAM_INT,  'Section ID', VALUE_DEFAULT, 0),
            'instanceid' => new external_value(PARAM_INT,  'Block instance ID', VALUE_DEFAULT, 0),
            'sessionid' => new external_value(PARAM_INT, 'Conversation session ID', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Execute the external function
     * 
     * @param string $question The user's question or query to be processed
     * @param int $userid The ID of the user asking the question
     * @param int $courseid The ID of the course context where the question is asked
     * @param bool $sansrag Optional. Whether to execute without RAG (Retrieval-Augmented Generation). 
     *                      If true, bypasses document retrieval. Default is false.
     * @return array Response array containing the chatbot's answer and metadata
     * @throws Exception If validation fails or processing encounters an error
     */
    public static function execute($question, $userid, $courseid, $sansrag = false, $sectionid = 0, $instanceid = 0, $sessionid = 0)
    {
        global $DB, $USER;

        // Validate parameters
        $params = self::validate_parameters(self::execute_parameters(), [
            'question' => $question,
            'userid' => $userid,
            'courseid' => $courseid,
            'sansrag' => $sansrag,
            'sectionid'  => $sectionid,
            'instanceid' => $instanceid,
            'sessionid' => $sessionid,
        ]);

        // Security checks
        require_login();

        // Check if user can access this course
        $context = context_course::instance($params['courseid']);
        self::validate_context($context);

        // Verify user is the same as the one making the request
        if ($USER->id != $params['userid']) {
            throw new invalid_parameter_exception('Invalid user');
        }

        try {
            // Sanitize input
            $question = self::sanitize_chatbot_input(trim($params['question']));

            if (empty($question)) {
                throw new invalid_parameter_exception(get_string('invalid_question_after_sanitize', 'block_alma_ai_tutor'));
            }

            $session = self::resolve_or_create_session(
                $params['userid'],
                $params['courseid'],
                $params['sectionid'],
                $params['instanceid'],
                $params['sessionid'],
                $question
            );

            // Get Bedrock configuration.
            $bedrock_region = trim((string)get_config('block_alma_ai_tutor', 'bedrock_region'));
            $bedrock_access_key = trim((string)get_config('block_alma_ai_tutor', 'bedrock_access_key'));
            $bedrock_secret_key = trim((string)get_config('block_alma_ai_tutor', 'bedrock_secret_key'));
            $bedrock_kb_id = trim((string)get_config('block_alma_ai_tutor', 'bedrock_knowledge_base_id'));
            $bedrock_model_id = trim((string)get_config('block_alma_ai_tutor', 'bedrock_chat_model_id'));
            $bedrock_data_source_id = trim((string)get_config('block_alma_ai_tutor', 'bedrock_data_source_id'));
            $bedrock_s3_bucket = trim((string)get_config('block_alma_ai_tutor', 'bedrock_s3_bucket'));

            if (empty($bedrock_region) || empty($bedrock_access_key) || empty($bedrock_secret_key) || empty($bedrock_kb_id)) {
                throw new \moodle_exception('bedrock_not_configured', 'block_alma_ai_tutor');
            }

            // Initialize connector
            $weaviate_connector = new \block_alma_ai_tutor\weaviate_connector(
                $bedrock_region,
                $bedrock_access_key,
                $bedrock_secret_key,
                $bedrock_kb_id,
                !empty($bedrock_model_id) ? $bedrock_model_id : 'cohere.command-r-v1:0',
                !empty($bedrock_data_source_id) ? $bedrock_data_source_id : '',
                $bedrock_s3_bucket
            );

            // Get course information
            $course_record = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
            $course_name = $course_record->fullname;
            $collection_name = 'Collection_course_' . $params['courseid'];

            // Get answer
            if ($params['sansrag']) {
                $answer = $weaviate_connector->get_cohere_response($question, '');
            } else {
                $answer = $weaviate_connector->get_question_answer(
                    $course_name,
                    $collection_name,
                    $question,
                    $params['userid'],
                    $params['courseid'],
                    $params['sectionid'],
                    $params['instanceid'],
                    $session->id
                );
            }

            if (is_null($answer) || $answer === false || trim((string)$answer) === '') {
                $connector_error = $weaviate_connector->get_last_error();
                if (!empty($connector_error)) {
                    throw new \Exception($connector_error);
                }
                throw new \moodle_exception('empty_response_from_api', 'block_alma_ai_tutor');
            }

            // Save conversation
            self::save_conversation(
                $params['userid'],
                $params['courseid'],
                $params['sectionid'],
                $params['instanceid'],
                $session->id,
                $question,
                $answer
            );

            $session->timemodified = time();
            $DB->update_record('block_alma_ai_tutor_chat_sessions', $session);

            return [
                'status' => 'success',
                'answer' => is_string($answer) ? $answer : json_encode($answer),
                'sessionid' => (int)$session->id,
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Define return values for the external function
     */
    public static function execute_returns()
    {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Status of the request'),
            'answer' => new external_value(PARAM_RAW, 'The chatbot answer', VALUE_OPTIONAL),
            'sessionid' => new external_value(PARAM_INT, 'Conversation session ID', VALUE_OPTIONAL),
            'error' => new external_value(PARAM_TEXT, 'Error message if any', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Sanitize chatbot input
     * 
     * Cleans and validates user input to prevent security vulnerabilities
     * such as XSS attacks, SQL injection, and malicious content injection.
     * 
     * @param string $input The raw user input string to be sanitized
     * @return string The sanitized and safe input string
     * @since 1.0.0
     */
    private static function sanitize_chatbot_input($input)
    {
        $dangerous_tags = ['script', 'iframe', 'object', 'embed', 'style', 'form', 'input', 'applet', 'link', 'meta'];
        foreach ($dangerous_tags as $tag) {
            $input = preg_replace('/<\s*\/?\s*' . $tag . '[^>]*>/i', '', $input);
        }

        $input = preg_replace('/\s*on\w+\s*=\s*("([^"]*)"|\'([^\']*)\'|[^\s>]+)/i', '', $input);
        $input = preg_replace('/javascript\s*:/i', '', $input);

        $dangerous_functions = ['eval', 'system', 'exec', 'passthru', 'shell_exec', 'base64_decode', 'phpinfo', 'proc_open', 'popen'];
        foreach ($dangerous_functions as $func) {
            $input = preg_replace('/\b' . preg_quote($func, '/') . '\s*\(/i', '', $input);
        }

        $input = mb_substr($input, 0, 100000, 'UTF-8');
        $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $input;
    }

    /**
     * Save conversation to database
     * 
     * Stores the chatbot conversation (question and answer) in the database
     * for history tracking, analytics, and user reference purposes.
     * 
     * @param int $userid The ID of the user who asked the question
     * @param int $courseid The ID of the course where the conversation took place
     * @param string $question The original question asked by the user
     * @param string $answer The chatbot's response to the question
     * @return bool True if conversation was saved successfully, false otherwise
     * @throws dml_exception If database operation fails
     */
    private static function save_conversation($userid, $courseid, $sectionid, $instanceid, $sessionid, $question, $answer)
    {
        global $DB;

        $max_conversations = 100;

        $record = new \stdClass();
        $record->userid = $userid;
        $record->courseid = $courseid;
        $record->sectionid = $sectionid;
        $record->instanceid = $instanceid;
        $record->sessionid = $sessionid;
        $record->question = $question;
        $record->answer = is_string($answer) ? $answer : json_encode($answer);
        $record->timecreated = time();

        try {
            $count = $DB->count_records('block_alma_ai_tutor_conversations', ['sessionid' => $sessionid]);

            if ($count >= $max_conversations) {
                $oldest_ids = $DB->get_fieldset_sql(
                    "SELECT id FROM {block_alma_ai_tutor_conversations}
                     WHERE sessionid = :sessionid
                     ORDER BY timecreated ASC",
                    ['sessionid' => $sessionid],
                    0,
                    $count - $max_conversations + 1
                );

                if ($oldest_ids) {
                    foreach ($oldest_ids as $old_id) {
                        $DB->delete_records('block_alma_ai_tutor_conversations', ['id' => $old_id]);
                    }
                }
            }

            $DB->insert_record('block_alma_ai_tutor_conversations', $record);

        } catch (dml_exception $e) {
            throw new \moodle_exception('error_saving_conversation', 'block_alma_ai_tutor', '', $e->getMessage());
        }
    }

    /**
     * Resolve current session or create a new one.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $sectionid
     * @param int $instanceid
     * @param int $sessionid
     * @param string $question
     * @return \stdClass
     */
    private static function resolve_or_create_session($userid, $courseid, $sectionid, $instanceid, $sessionid, $question)
    {
        global $DB;

        $timeoutseconds = self::get_session_timeout_seconds();
        $now = time();

        if (!empty($sessionid)) {
            $existingsession = $DB->get_record('block_alma_ai_tutor_chat_sessions', ['id' => $sessionid]);
            if ($existingsession
                && (int)$existingsession->userid === (int)$userid
                && (int)$existingsession->courseid === (int)$courseid
                && (int)$existingsession->sectionid === (int)$sectionid
                && (int)$existingsession->instanceid === (int)$instanceid
                && ($now - (int)$existingsession->timemodified) <= $timeoutseconds) {
                return $existingsession;
            }
        }

        $title = trim($question);
        if (\core_text::strlen($title) > 80) {
            $title = \core_text::substr($title, 0, 80) . '...';
        }

        $newsession = new \stdClass();
        $newsession->userid = $userid;
        $newsession->courseid = $courseid;
        $newsession->sectionid = $sectionid;
        $newsession->instanceid = $instanceid;
        $newsession->title = $title;
        $newsession->timecreated = $now;
        $newsession->timemodified = $now;

        $newsession->id = $DB->insert_record('block_alma_ai_tutor_chat_sessions', $newsession);
        return $newsession;
    }

    /**
     * Session timeout in seconds.
     *
     * @return int
     */
    private static function get_session_timeout_seconds(): int
    {
        $minutes = (int)get_config('block_alma_ai_tutor', 'chat_session_timeout_minutes');
        if ($minutes <= 0) {
            $minutes = 30;
        }
        return $minutes * 60;
    }
}
