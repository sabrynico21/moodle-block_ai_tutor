<?php
/**
 * External API for file upload and indexing
 * @copyright 2025 Université TÉLUQ and the UNIVERSITÉ GASTON BERGER DE SAINT-LOUIS
 */

namespace block_alma_ai_tutor\external;

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use context_course;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class upload_files extends external_api {

    /**
     * Ensure response messages are valid UTF-8 and safe for WS return validation.
     *
     * @param string $message
     * @return string
     */
    private static function sanitize_ws_message(string $message): string {
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', (string)$message);
        if ($clean === false) {
            $clean = (string)$message;
        }

        // Remove ASCII control chars that can break external response validation.
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $clean);
        return trim((string)$clean);
    }

    /**
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, get_string('course_id', 'block_alma_ai_tutor')),
            'files' => new external_multiple_structure(
                new external_single_structure([
                    'filename' => new external_value(PARAM_TEXT, get_string('file_name', 'block_alma_ai_tutor')),
                    'filecontent' => new external_value(PARAM_RAW, get_string('file_content_base64', 'block_alma_ai_tutor')),
                ])
            )
        ]);
    }

    /**
     * Get file extension
     * @param string $filename
     * @return string
     */
    private static function get_file_extension($filename) {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    /**
     * Check if file is PDF
     * @param string $filename
     * @return bool
     */
    private static function is_pdf($filename) {
        return self::get_file_extension($filename) === 'pdf';
    }

    /**
     * Check if file is text file
     * @param string $filename
     * @return bool
     */
    private static function is_text_file($filename) {
        $textExtensions = ['txt', 'text', 'md'];
        return in_array(self::get_file_extension($filename), $textExtensions);
    }

    /**
     * Execute the file upload and indexing
     * @param int $courseid
     * @param array $files
     * @return array
     */
    public static function execute($courseid, $files) {
        global $CFG, $USER, $DB;

        // Clean any output that might pollute the JSON response
        if (ob_get_level()) {
            ob_end_clean();
        }
        ob_start();

        try {
            // Validate parameters
            $params = self::validate_parameters(self::execute_parameters(), [
                'courseid' => $courseid,
                'files' => $files
            ]);

            // Validate context and capabilities
            $context = context_course::instance($params['courseid']);
            self::validate_context($context);
            
            // Check capabilities with more flexible requirement
            try {
                require_capability('moodle/course:update', $context);
            } catch (Exception $e) {
                // Try alternative capabilities
                if (has_capability('moodle/course:managefiles', $context) || 
                    has_capability('moodle/course:view', $context) || 
                    is_enrolled($context, $USER->id)) {
                    // OK
                } else {
                    throw new Exception(get_string('insufficient_permissions', 'block_alma_ai_tutor'));
                }
            }

            // Bedrock configuration.
            $bedrock_region = trim((string)get_config('block_alma_ai_tutor', 'bedrock_region'));
            $bedrock_access_key = trim((string)get_config('block_alma_ai_tutor', 'bedrock_access_key'));
            $bedrock_secret_key = trim((string)get_config('block_alma_ai_tutor', 'bedrock_secret_key'));
            $bedrock_kb_id = trim((string)get_config('block_alma_ai_tutor', 'bedrock_knowledge_base_id'));
            $bedrock_model_id = trim((string)get_config('block_alma_ai_tutor', 'bedrock_chat_model_id'));
            $bedrock_data_source_id = trim((string)get_config('block_alma_ai_tutor', 'bedrock_data_source_id'));
            $bedrock_s3_bucket = trim((string)get_config('block_alma_ai_tutor', 'bedrock_s3_bucket'));

            // Validate required configurations
            if (empty($bedrock_region) || empty($bedrock_access_key) || empty($bedrock_secret_key) || empty($bedrock_kb_id)) {
                throw new Exception(get_string('missing_api_configuration', 'block_alma_ai_tutor'));
            }

            if (empty($bedrock_s3_bucket)) {
                throw new Exception('S3 bucket is not configured. Set "S3 bucket name for Knowledge Base storage" in plugin settings.');
            }

            if (empty($bedrock_data_source_id)) {
                throw new Exception('Knowledge Base data source ID is not configured. Set "Amazon Bedrock data source ID" in plugin settings.');
            }

            // Check if classes exist
            if (!class_exists('\block_alma_ai_tutor\weaviate_connector')) {
                throw new Exception(get_string('weaviate_connector_not_found', 'block_alma_ai_tutor'));
            }

            // Initialize Bedrock connector object.
            $connector = new \block_alma_ai_tutor\weaviate_connector(
                $bedrock_region,
                $bedrock_access_key,
                $bedrock_secret_key,
                $bedrock_kb_id,
                !empty($bedrock_model_id) ? $bedrock_model_id : 'cohere.command-r-v1:0',
                $bedrock_data_source_id,
                $bedrock_s3_bucket
            );

            $courseName = get_string('collection_prefix', 'block_alma_ai_tutor') . $params['courseid'];

            // Create or get a temporary directory for the plugin
            $temppath = make_temp_directory('block_alma_ai_tutor');
            $uploadDir = $temppath . '/uploads/';

            // If the uploads subdirectory doesn't exist, create it
            if (!file_exists($uploadDir)) {
                if (!mkdir($uploadDir, 0777, true)) {
                    throw new Exception(get_string('failed_create_upload_directory', 'block_alma_ai_tutor'));
                }
            }

            $processedFiles = 0;
            $errors = [];

            foreach ($params['files'] as $index => $file) {
                try {
                    // Validate filename
                    if (empty($file['filename'])) {
                        throw new Exception(get_string('empty_filename', 'block_alma_ai_tutor'));
                    }

                    // Check if file type is supported
                    if (!self::is_pdf($file['filename']) && !self::is_text_file($file['filename'])) {
                        throw new Exception(get_string('unsupported_file_type', 'block_alma_ai_tutor') . $file['filename']);
                    }

                    // Decode base64 content
                    $fileContent = base64_decode($file['filecontent']);
                    if ($fileContent === false) {
                        throw new Exception(get_string('invalid_file_data', 'block_alma_ai_tutor') . $file['filename']);
                    }

                    // Generate unique filename
                    $newFileName = uniqid('file_', true) . '-' . clean_filename($file['filename']);
                    $destination = $uploadDir . $newFileName;

                    // Save file temporarily
                    if (file_put_contents($destination, $fileContent) === false) {
                        throw new Exception(get_string('failed_save_file', 'block_alma_ai_tutor') . $file['filename']);
                    }

                    $destinationTxt = '';

                    // Process based on file type
                    if (self::is_pdf($file['filename'])) {
                        // Use KB parser configured in AWS (e.g., Anthropic Sonnet) by uploading raw PDF.
                        $destinationTxt = $destination;

                    } else if (self::is_text_file($file['filename'])) {
                        // Text file processing — use the file directly.
                        $destinationTxt = $destination;
                    }

                    // Index the text file
                    $indexed = $connector->index_text_file($destinationTxt, $courseName, (string)$params['courseid']);

                    if (!$indexed) {
                        $error = $connector->get_last_error();
                        throw new Exception(get_string('error_indexing_file_unknown', 'block_alma_ai_tutor') . $file['filename'] . ': ' . ($error ? $error : get_string('unknown_error', 'block_alma_ai_tutor')));
                    }

                    // Clean up temporary files
                    if (file_exists($destinationTxt) && $destinationTxt !== $destination) {
                        unlink($destinationTxt);
                    }
                    if (file_exists($destination)) {
                        unlink($destination);
                    }

                    $processedFiles++;

                } catch (Exception $e) {
                    $errors[] = $file['filename'] . ': ' . $e->getMessage();
                    
                    // Clean up files in case of error
                    if (isset($destinationTxt) && file_exists($destinationTxt) && $destinationTxt !== $destination) {
                        unlink($destinationTxt);
                    }
                    if (isset($destination) && file_exists($destination)) {
                        unlink($destination);
                    }
                }
            }

            // Return results
            if ($processedFiles > 0) {
                $message = $processedFiles . get_string('files_indexed_successfully', 'block_alma_ai_tutor');
                if (!empty($errors)) {
                    $message .= get_string('errors_occurred', 'block_alma_ai_tutor') . implode('; ', $errors);
                }
                
                return [
                    'success' => true,
                    'message' => self::sanitize_ws_message($message),
                    'processedfiles' => $processedFiles
                ];
            } else {
                return [
                    'success' => false,
                    'message' => self::sanitize_ws_message(get_string('no_files_processed', 'block_alma_ai_tutor') . implode('; ', $errors)),
                    'processedfiles' => 0
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => self::sanitize_ws_message((string)$e->getMessage()),
                'processedfiles' => 0
            ];
        } finally {
            // Clean output buffer before returning JSON
            if (ob_get_level()) {
                ob_end_clean();
            }
        }
    }

    /**
     * Returns description of method result value
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, get_string('operation_successful', 'block_alma_ai_tutor')),
            'message' => new external_value(PARAM_RAW, get_string('response_message', 'block_alma_ai_tutor')),
            'processedfiles' => new external_value(PARAM_INT, get_string('processed_files_count', 'block_alma_ai_tutor'))
        ]);
    }
}