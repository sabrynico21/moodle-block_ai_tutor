<?php
/**
 * Bedrock connector class for Moodle chatbot.
 *
 * @package    block_alma_ai_tutor
 * @subpackage weaviateconnector
 * @copyright  2025 Universite TELUQ and the UNIVERSITE GASTON BERGER DE SAINT-LOUIS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alma_ai_tutor;

defined('MOODLE_INTERNAL') || die();

/**
 * Class weaviate_connector
 *
 * Kept for backward compatibility with existing external service wiring,
 * but the implementation now uses Amazon Bedrock Runtime + Knowledge Bases.
 */
class weaviate_connector {
    /** @var string */
    private $region;

    /** @var string */
    private $access_key;

    /** @var string */
    private $secret_key;

    /** @var string */
    private $knowledge_base_id;

    /** @var string */
    private $chat_model_id;

    /** @var string */
    private $data_source_id;

    /** @var string */
    private $s3_bucket;

    /** @var string */
    private $last_prompt;

    /** @var string|null */
    private ?string $last_error = null;

    /** @var string */
    private string $rag_model_arn = '';

    /**
     * @param string $region AWS region
     * @param string $access_key AWS access key
     * @param string $secret_key AWS secret key
     * @param string $knowledge_base_id Bedrock knowledge base id
     * @param string $chat_model_id Bedrock model id used for direct chat
     * @param string $data_source_id Optional data source id for ingestion job
     * @param string $s3_bucket S3 bucket name backing the Knowledge Base data source
     */
    public function __construct(
        string $region,
        string $access_key,
        string $secret_key,
        string $knowledge_base_id,
        string $chat_model_id = 'cohere.command-r-v1:0',
        string $data_source_id = '',
        string $s3_bucket = '',
        string $rag_model_arn = ''
    ) {
        $this->region = trim($region);
        $this->access_key = trim($access_key);
        $this->secret_key = trim($secret_key);
        $this->knowledge_base_id = trim($knowledge_base_id);
        $this->chat_model_id = trim($chat_model_id);
        $this->data_source_id = trim($data_source_id);
        $this->s3_bucket = trim($s3_bucket);
        $this->rag_model_arn = trim($rag_model_arn);
    }

    /**
     * Direct generation using Bedrock Runtime (kept method name for API compatibility).
     *
     * @param string $question Question to ask
     * @param string $api_key Deprecated parameter kept for compatibility
     * @return string|null
     */
    public function get_cohere_response(string $question, string $api_key = ''): ?string {
        $payload = [
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['text' => $question],
                    ],
                ],
            ],
            'inferenceConfig' => [
                'maxTokens' => 700,
                'temperature' => 0.3,
            ],
        ];

        $result = $this->bedrock_runtime_request('/model/' . rawurlencode($this->chat_model_id) . '/converse', $payload);
        if ($result === null) {
            return null;
        }

        $text = $result['output']['message']['content'][0]['text'] ?? null;
        if (!$text) {
            $this->last_error = get_string('invalid_response_format', 'block_alma_ai_tutor');
            return null;
        }

        $this->last_prompt = $question;
        return $text;
    }

    /**
     * RAG answer generation through Bedrock Knowledge Base.
     */
    public function get_question_answer(
        $course_name,
        string $collection,
        string $question,
        $user_id,
        $course_id,
        int $section_id = 0,
        int $instance_id = 0,
        int $session_id = 0,
        string $section_context = ''
    ): ?string {
        global $DB;

        $taskrecord = $DB->get_record('block_alma_ai_tutor_prompts', [
            'courseid' => $course_id,
            'instanceid' => $instance_id,
        ]);
        if (!$taskrecord && $section_id > 0) {
            $taskrecord = $DB->get_record('block_alma_ai_tutor_prompts', [
                'courseid' => $course_id,
                'sectionid' => $section_id,
            ]);
        }
        if (!$taskrecord) {
            $taskrecord = $DB->get_record('block_alma_ai_tutor_prompts', [
                'courseid' => $course_id,
            ]);
        }
        $task = $taskrecord ? $taskrecord->prompt : get_string('default_prompt', 'block_alma_ai_tutor');

        if (!empty($session_id)) {
            $last_conversations = $DB->get_records_sql(
                "SELECT question, answer
                   FROM {block_alma_ai_tutor_conversations}
                  WHERE sessionid = :sessionid
               ORDER BY timecreated DESC
                  LIMIT 10",
                ['sessionid' => $session_id]
            );
        } else {
            $last_conversations = $DB->get_records_sql(
                "SELECT question, answer
                   FROM {block_alma_ai_tutor_conversations}
                  WHERE userid = :userid
                    AND courseid = :courseid
                    AND sectionid = :sectionid
                    AND instanceid = :instanceid
               ORDER BY timecreated DESC
                  LIMIT 10",
                [
                    'userid' => $user_id,
                    'courseid' => $course_id,
                    'sectionid' => $section_id,
                    'instanceid' => $instance_id,
                ]
            );
        }

        $history = '';
        $conversations = array_values($last_conversations);
        if (count($conversations) > 0) {
            $history = get_string('previous_interactions_history', 'block_alma_ai_tutor') . "\n\n";
            foreach ($conversations as $index => $conversation) {
                $num = $index + 1;
                $history .= get_string('previous_question', 'block_alma_ai_tutor', $num) . $conversation->question . "\n";
                $history .= get_string('answer', 'block_alma_ai_tutor') . $conversation->answer . "\n\n";
            }
        }

        
        $task = str_replace('[[ coursename ]]', (string)$course_name, $task);
        $task = str_replace('[[ question ]]', $question, $task);
        $task = str_replace('[[ history ]]', $history, $task);
        $task = str_replace('[[ section_context ]]', $section_context, $task);


        $has_files = $DB->record_exists('block_alma_ai_tutor_files', [
            'courseid' => (int)$course_id,
            'sectionid' => (int)$section_id,
            'instanceid' => (int)$instance_id,
        ]);

        error_log('alma_ai_tutor: has_files=' . ($has_files ? 'YES' : 'NO') . ' courseid=' . $course_id . ' sectionid=' . $section_id . ' instanceid=' . $instance_id);

        if (!$has_files) {
            $result = $this->direct_generate($question, $task);
            if ($result !== null) {
                $this->last_prompt = $task;
            }
            return $result;
        }

        $response = $this->retrieve_and_generate(
            $question, 
            $task, 
            (string)$course_id, 
            (string)$user_id,
            (string)$instance_id,
            (string)$section_id
        );
        error_log('alma_ai_tutor: retrieve_and_generate result=' . ($response === null ? 'NULL' : 'OK') . ' last_error=' . ($this->last_error ?? 'none'));
        if ($response === null) {
            $kb_error = $this->last_error;
            $this->last_error = null;

            $response = $this->direct_generate($question, $task);

            if ($response === null) {
                $this->last_error = $kb_error;
                return null;
            }
        }

        $this->last_prompt = $task;
        return $response;
    }

    /**
     * Upload a file into the Knowledge Base.
     *
     * This implementation targets S3-backed Knowledge Bases:
     * upload file to S3, then trigger StartIngestionJob for the data source.
     */
    public function index_text_file(
        string $file_path, 
        string $collection, 
        string $user_id, 
        string $course_id, 
        string $section_id = '0',
        string $instance_id = '0',
        string $original_filename = ''
    ): bool {
        if (!file_exists($file_path)) {
            $this->last_error = get_string('file_not_found', 'block_alma_ai_tutor') . $file_path;
            return false;
        }

        if ($this->s3_bucket === '') {
            $this->last_error = 'S3 bucket is not configured. Set "S3 bucket name for Knowledge Base storage" in plugin settings.';
            return false;
        }

        if ($this->data_source_id === '') {
            $this->last_error = 'Knowledge Base data source ID is not configured. Set "Amazon Bedrock data source ID" in plugin settings.';
            return false;
        }

        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION)) ?: 'txt';
        $is_pdf = ($ext === 'pdf');

        $content = file_get_contents($file_path);
        if ($content === false || $content === '') {
            $this->last_error = get_string('unable_to_read_file', 'block_alma_ai_tutor');
            return false;
        }

        $site_hash = substr(md5(get_site_identifier()), 0, 12);

        $section_folder = (!empty($section_id) && $section_id !== '0') ? $section_id : '0';
        $instance_folder = (!empty($instance_id) && $instance_id !== '0') ? $instance_id : '0';

        $file_hash = sha1($file_path . '|' . $user_id . '|' . $course_id . '|' . $instance_id . '|' . microtime());
        $s3_base        = 'sites/' . $site_hash
                        . '/users/' . $user_id
                        . '/courses/' . $course_id
                        . '/sections/' . $section_folder
                        . '/instances/' . $instance_folder
                        . '/' . $file_hash;
        $s3_key = $s3_base . '.' . $ext;
        $content_type = $is_pdf ? 'application/pdf' : 'text/plain; charset=utf-8';

        if (!$this->s3_put_object($this->s3_bucket, $s3_key, $content, $content_type)) {
            return false;
        }

        // Track the uploaded file in the DB for future cleanup.
        try {
            global $DB;
            $filerecord = new \stdClass();
            $filerecord->userid     = (int)$user_id;
            $filerecord->courseid   = (int)$course_id;
            $filerecord->sectionid  = (int)$section_folder;
            $filerecord->instanceid = (int)$instance_folder;
            $filerecord->s3key      = $s3_key;
            $filerecord->filename   = !empty($original_filename) ? $original_filename : basename($file_path);
            $filerecord->timecreated = time();
            $DB->insert_record('block_alma_ai_tutor_files', $filerecord);
        } catch (\Exception $e) {
            // Non-critical: log but don't abort the upload.
            error_log('alma_ai_tutor: failed to track file in DB: ' . $e->getMessage());
        }

        $metadata = json_encode([
            'metadataAttributes' => [
                'sitehash' => $site_hash,
                'userid'    => $user_id,
                'courseid'  => $course_id,
                'sectionid' => $section_folder,
                'instanceid' => $instance_folder,
            ]
        ]);

        $s3_metadata_key = $s3_key . '.metadata.json';
        if (!$this->s3_put_object($this->s3_bucket, $s3_metadata_key, $metadata, 'application/json')) {
            error_log('alma_ai_tutor: failed to upload metadata for ' . $s3_key . ': ' . $this->last_error);
            $this->last_error = null;
        }

        $ingestion = $this->bedrock_agent_request(
            '/knowledgebases/' . rawurlencode($this->knowledge_base_id)
                . '/datasources/' . rawurlencode($this->data_source_id) . '/ingestionjobs',
            [
                'knowledgeBaseId' => $this->knowledge_base_id,
                'dataSourceId'    => $this->data_source_id,
            ],
            'PUT'
        );

        file_put_contents('/tmp/alma_debug.log', 'ingestion response: ' . json_encode($ingestion) . PHP_EOL, FILE_APPEND);

        if ($ingestion === null) {
            $last_err = $this->last_error ?? '';
            file_put_contents('/tmp/alma_debug.log', 'ingestion null error: [' . $last_err . ']' . PHP_EOL, FILE_APPEND);
            if (strpos($last_err, '409') !== false || strpos($last_err, 'currently running') !== false || strpos($last_err, 'STARTING') !== false) {
                $this->last_error = null;
                return true;
            }
            return false;
        }

        return true;
    }

    /**
     * Upload an object to an S3 bucket using AWS SigV4.
     *
     * @param string $bucket Bucket name
     * @param string $key Object key (path inside the bucket)
     * @param string $content Raw bytes
     * @param string $content_type MIME type
     * @return bool
     */
    private function s3_put_object(string $bucket, string $key, string $content, string $content_type = 'text/plain; charset=utf-8'): bool {
        $host = $bucket . '.s3.' . $this->region . '.amazonaws.com';
        $path = '/' . ltrim($key, '/');

        $signer = new aws_v4_signer($this->access_key, $this->secret_key, $this->region, 's3', $host);
        $headers = $signer->sign_request('PUT', $path, '', $content, [
            'content-type' => $content_type,
        ]);

        $headerlines = [];
        foreach ($headers as $k => $v) {
            $headerlines[] = $k . ': ' . $v;
        }

        $ch = curl_init('https://' . $host . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $content,
            CURLOPT_HTTPHEADER     => $headerlines,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 120,
        ]);

        $response  = curl_exec($ch);
        $httpcode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlerror = curl_error($ch);
        curl_close($ch);

        if ($curlerror) {
            $this->last_error = get_string('curl_error', 'block_alma_ai_tutor') . $curlerror;
            return false;
        }

        if ($httpcode < 200 || $httpcode >= 300) {
            $this->last_error = get_string('http_error', 'block_alma_ai_tutor') . $httpcode . ': ' . $response;
            return false;
        }

        return true;
    }

    /**
     * Delete an object from an S3 bucket using AWS SigV4.
     *
     * @param string $bucket
     * @param string $key
     * @return bool  True also if the object was already absent (404).
     */
    private function s3_delete_object(string $bucket, string $key): bool {
        $host = $bucket . '.s3.' . $this->region . '.amazonaws.com';
        $path = '/' . ltrim($key, '/');

        $signer = new aws_v4_signer($this->access_key, $this->secret_key, $this->region, 's3', $host);
        $headers = $signer->sign_request('DELETE', $path, '', '', []);

        $headerlines = [];
        foreach ($headers as $k => $v) {
            $headerlines[] = $k . ': ' . $v;
        }

        $ch = curl_init('https://' . $host . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => $headerlines,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response  = curl_exec($ch);
        $httpcode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlerror = curl_error($ch);
        curl_close($ch);

        if ($curlerror) {
            $this->last_error = 'cURL error (DELETE): ' . $curlerror;
            return false;
        }

        // 204 = deleted, 200 = ok, 404 = already gone → all acceptable.
        if ($httpcode === 204 || $httpcode === 200 || $httpcode === 404) {
            return true;
        }

        $this->last_error = 'S3 DELETE HTTP ' . $httpcode . ': ' . $response;
        return false;
    }

    /**
     * @return string|null
     */
    public function get_last_error(): ?string {
        return $this->last_error;
    }

    /**
     * Trigger a manual re-sync of the Knowledge Base data source.
     * 
     * This will re-index all S3 objects in the data source,
     * clearing outdated cached versions and picking up new/modified files.
     * 
     * @return bool True if ingestion job started successfully
     */
    public function resync_knowledge_base(): bool {
        if ($this->knowledge_base_id === '') {
            $this->last_error = 'Knowledge Base ID is not configured.';
            return false;
        }

        if ($this->data_source_id === '') {
            $this->last_error = 'Knowledge Base data source ID is not configured.';
            return false;
        }

        $ingestion = $this->bedrock_agent_request(
            '/knowledgebases/' . rawurlencode($this->knowledge_base_id)
                . '/datasources/' . rawurlencode($this->data_source_id) . '/ingestionjobs',
            [
                'knowledgeBaseId' => $this->knowledge_base_id,
                'dataSourceId'    => $this->data_source_id,
            ],
            'PUT'
        );

        if ($ingestion === null) {
            $last_err = $this->last_error ?? '';
            if (strpos($last_err, '409') !== false || strpos($last_err, 'currently running') !== false || strpos($last_err, 'STARTING') !== false) {
                $this->last_error = 'Knowledge Base re-sync already in progress.';
                return true;
            }
            return false;
        }

        $this->last_error = 'Knowledge Base re-sync initiated. This may take 1-5 minutes to complete.';
        return true;
    }

    /**
     * Delete all S3 files associated with a block instance and trigger KB re-sync.
     *
     * Reads the tracked S3 keys from the Moodle DB, deletes each object
     * (plus its .metadata.json companion) from S3, then starts a new
     * ingestion job so the Knowledge Base no longer returns stale results.
     *
     * @param string $courseid
     * @param string $sectionid
     * @param string $instanceid
     * @return bool  True if all deletions succeeded (or there were no files).
     */
    public function delete_instance_files(string $courseid, string $sectionid, string $instanceid): bool {
        global $DB;

        if (empty($this->s3_bucket)) {
            $this->last_error = 'S3 bucket not configured — cannot delete instance files.';
            return false;
        }

        $files = $DB->get_records('block_alma_ai_tutor_files', [
            'courseid'   => (int)$courseid,
            'sectionid'  => (int)$sectionid,
            'instanceid' => (int)$instanceid,
        ]);

        if (empty($files)) {
            // Nothing to delete — still a success.
            return true;
        }

        $allok = true;
        foreach ($files as $file) {
            // Delete the document itself.
            if (!$this->s3_delete_object($this->s3_bucket, $file->s3key)) {
                error_log('alma_ai_tutor: failed to delete S3 object ' . $file->s3key . ': ' . $this->last_error);
                $allok = false;
            }

            // Delete the companion metadata file (non-critical).
            $this->s3_delete_object($this->s3_bucket, $file->s3key . '.metadata.json');
        }

        // Trigger KB re-ingestion so deleted files are removed from the index.
        $ingestion = $this->bedrock_agent_request(
            '/knowledgebases/' . rawurlencode($this->knowledge_base_id)
                . '/datasources/' . rawurlencode($this->data_source_id) . '/ingestionjobs',
            [
                'knowledgeBaseId' => $this->knowledge_base_id,
                'dataSourceId'    => $this->data_source_id,
            ],
            'PUT'
        );

        if ($ingestion === null) {
            $err = $this->last_error ?? '';
            // 409 = job already running → the re-sync will pick up our deletions anyway.
            if (strpos($err, '409') !== false
                || strpos($err, 'currently running') !== false
                || strpos($err, 'STARTING') !== false) {
                $this->last_error = null;
            } else {
                error_log('alma_ai_tutor: re-ingestion after file deletion failed: ' . $err);
                $allok = false;
            }
        }

        return $allok;
    }

    /**
     * @return string|null
     */
    public function get_last_prompt(): ?string {
        return $this->last_prompt;
    }

    /**
     * @param string $question
     * @param string $task
     * @param string $courseid
     * @return string|null
     */
    private function retrieve_and_generate(
        string $question, 
        string $task, 
        string $courseid, 
        string $userid = '',
        string $instance_id = '',
        string $section_id = '0'
    ): ?string {
        $prompttemplate = $this->ensure_kb_prompt_template($task);
        if (strpos($prompttemplate, '$search_results$') === false) {
            $prompttemplate .= '\n\nRetrieved context:\n$search_results$';
        }

        // Metadata filter for userid
        $vector_search_config = ['numberOfResults' => 20];

        if (!empty($userid)) {
            $site_hash = substr(md5(get_site_identifier()), 0, 12);

            $filter_conditions = [
                [
                    'equals' => [
                        'key'   => 'sitehash', 
                        'value' => $site_hash,
                    ],
                ],
                [
                    'equals' => [
                        'key'   => 'courseid',
                        'value' => $courseid,
                    ],
                ],
            ];

            if (!empty($instance_id) && $instance_id !== '0') {
                $filter_conditions[] = [
                    'equals' => [
                        'key'   => 'instanceid',
                        'value' => $instance_id,
                    ],
                ];
            }

            if (!empty($section_id) && $section_id !== '0') {
                $filter_conditions[] = [
                    'equals' => [
                        'key'   => 'sectionid',
                        'value' => $section_id,
                    ],
                ];
            }
 
            $vector_search_config['filter'] = ['andAll' => $filter_conditions];
        }


        $model_arn = !empty($this->rag_model_arn)
            ? $this->rag_model_arn
            : 'arn:aws:bedrock:' . $this->region . '::foundation-model/' . $this->chat_model_id;

        $payload = [
            'input' => [
                'text' => $question,
            ],
            'retrieveAndGenerateConfiguration' => [
                'type' => 'KNOWLEDGE_BASE',
                'knowledgeBaseConfiguration' => [
                    'knowledgeBaseId' => $this->knowledge_base_id,
                    'modelArn' => $model_arn,
                    'retrievalConfiguration' => [
                        'vectorSearchConfiguration' => $vector_search_config,
                    ],
                    'generationConfiguration' => [
                        'promptTemplate' => [
                            'textPromptTemplate' => $prompttemplate,
                        ],
                    ],
                ],
            ],
        ];

        // S3-ingested documents do not carry inline "courseid" metadata by default.
        // Keep retrieval unfiltered unless metadata mapping is explicitly configured in AWS.

        $result = $this->bedrock_agent_runtime_request('/retrieveAndGenerate', $payload);
        if ($result === null) {
            return null;
        }

        $text = null;
        if (!empty($result['output']['text']) && is_string($result['output']['text'])) {
            $text = $result['output']['text'];
        } else if (!empty($result['output']['message']['content'][0]['text'])
            && is_string($result['output']['message']['content'][0]['text'])) {
            $text = $result['output']['message']['content'][0]['text'];
        } else if (!empty($result['text']) && is_string($result['text'])) {
            $text = $result['text'];
        }

        if ($text === null || trim($text) === '') {
            $this->last_error = get_string('invalid_response_format', 'block_alma_ai_tutor')
                . ' Raw keys: ' . implode(', ', array_keys($result));
            return null;
        }

        $text = trim($text);
        if (stripos($text, 'unable to assist you with this request') !== false) {
            $diagnostic = $this->diagnose_retrieval_for_question($question);
            if ($diagnostic !== null) {
                $this->last_error = $diagnostic;
                return null;
            }
        }

        return $text;
    }

    /**
     * Bedrock KB prompt templates must include $search_results$.
     *
     * @param string $task
     * @return string
     */
    private function ensure_kb_prompt_template(string $task): string {
        $template = trim($task);
        if ($template === '') {
            $template = 'Answer the user question using only the following retrieved context:\n\n$search_results$\n\nQuestion: $query$';
        }

        if (strpos($template, '$search_results$') === false) {
            $template .= '\n\nRetrieved context:\n$search_results$';
        }

        return $template;
    }

    /**
     * Diagnose why RetrieveAndGenerate returned a generic refusal.
     *
     * @param string $question
     * @return string|null
     */
    private function diagnose_retrieval_for_question(string $question): ?string {
        $retrieval = $this->bedrock_agent_runtime_request(
            '/knowledgebases/' . rawurlencode($this->knowledge_base_id) . '/retrieve',
            [
                'knowledgeBaseId' => $this->knowledge_base_id,
                'retrievalQuery' => ['text' => $question],
                'retrievalConfiguration' => [
                    'vectorSearchConfiguration' => [
                        'numberOfResults' => 5
                    ],
                ],
            ]
        );

        if ($retrieval === null) {
            return $this->last_error ?: 'RetrieveAndGenerate returned a refusal and retrieval diagnostics failed.';
        }

        $results = $retrieval['retrievalResults'] ?? [];
        $count = is_array($results) ? count($results) : 0;
        if ($count === 0) {
            return 'No retrieval results found for this question. Root cause is usually one of: '
                . '1) ingestion job still running, 2) S3 object path not included in KB data source prefix, '
                . '3) KB data source sync failed.';
        }

        return 'RetrieveAndGenerate returned a generic refusal even though retrieval returned '
            . $count . ' result(s). Check your custom prompt in Moodle and KB guardrail/model policy settings in AWS.';
    }

    /**
     * Direct generation via Bedrock Converse API, without Knowledge Base.
     * Used when no files are indexed for the current instance, or as fallback
     * when RetrieveAndGenerate fails.
     *
     * The resolved $task (with course/section context already injected) is passed
     * as system prompt so the model still has full course/section awareness.
     *
     * @param string $question
     * @param string $task Already-resolved prompt with all placeholders substituted.
     * @return string|null
     */
    private function direct_generate(string $question, string $task): ?string {
        $payload = [
            'system' => [
                ['text' => $task],
            ],
            'messages' => [
                [
                    'role'    => 'user',
                    'content' => [['text' => $question]],
                ],
            ],
            'inferenceConfig' => [
                'maxTokens'   => 700,
                'temperature' => 0.3,
            ],
        ];

        $result = $this->bedrock_runtime_request(
            '/model/' . rawurlencode($this->chat_model_id) . '/converse',
            $payload
        );

        if ($result === null) {
            return null;
        }

        $text = $result['output']['message']['content'][0]['text'] ?? null;
        if (!$text) {
            $this->last_error = get_string('invalid_response_format', 'block_alma_ai_tutor');
            return null;
        }

        return trim($text);
    }

    /**
     * @param string $text
     * @return string
     */
    private function clean_text(string $text): string {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    /**
     * @param string $path
     * @param array $payload
     * @return array|null
     */
    private function bedrock_runtime_request(string $path, array $payload): ?array {
        $host = 'bedrock-runtime.' . $this->region . '.amazonaws.com';
        return $this->signed_json_request('bedrock', $host, $path, $payload);
    }

    /**
     * @param string $path
     * @param array $payload
     * @return array|null
     */
    private function bedrock_agent_runtime_request(string $path, array $payload): ?array {
        $host = 'bedrock-agent-runtime.' . $this->region . '.amazonaws.com';
        return $this->signed_json_request('bedrock', $host, $path, $payload);
    }

    /**
     * @param string $path
     * @param array $payload
     * @return array|null
     */
    private function bedrock_agent_request(string $path, array $payload, string $method = 'POST'): ?array {
        $host = 'bedrock-agent.' . $this->region . '.amazonaws.com';
        return $this->signed_json_request('bedrock', $host, $path, $payload, $method);
    }

    /**
     * @param string $service
     * @param string $host
     * @param string $path
     * @param array $payload
     * @return array|null
     */
    private function signed_json_request(string $service, string $host, string $path, array $payload, string $method = 'POST'): ?array {
        $body = json_encode($payload);
        if ($body === false) {
            $this->last_error = get_string('json_encode_error', 'block_alma_ai_tutor') . json_last_error_msg();
            return null;
        }

        $signer = new aws_v4_signer($this->access_key, $this->secret_key, $this->region, $service, $host);
        $headers = $signer->sign_request($method, $path, '', $body, [
            'content-type' => 'application/json',
            'accept' => 'application/json',
        ]);

        $headerlines = [];
        foreach ($headers as $key => $value) {
            $headerlines[] = $key . ': ' . $value;
        }

        $url = 'https://' . $host . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerlines,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlerror = curl_error($ch);
        curl_close($ch);

        if ($curlerror) {
            $this->last_error = get_string('curl_error', 'block_alma_ai_tutor') . $curlerror;
            return null;
        }

        if ($httpcode < 200 || $httpcode >= 300) {
            $this->last_error = get_string('http_error', 'block_alma_ai_tutor') . $httpcode . ': ' . $response;
            return null;
        }

        if ($response === '' || $response === null) {
            $this->last_error = 'Empty response body returned by API endpoint: ' . $path;
            return null;
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->last_error = get_string('json_decode_error', 'block_alma_ai_tutor') . json_last_error_msg();
            return null;
        }

        return $decoded;
    }
}
