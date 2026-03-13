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
        string $s3_bucket = ''
    ) {
        $this->region = trim($region);
        $this->access_key = trim($access_key);
        $this->secret_key = trim($secret_key);
        $this->knowledge_base_id = trim($knowledge_base_id);
        $this->chat_model_id = trim($chat_model_id);
        $this->data_source_id = trim($data_source_id);
        $this->s3_bucket = trim($s3_bucket);
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
    public function get_question_answer($course_name, string $collection, string $question, $user_id, $course_id): ?string {
        global $DB;

        $taskrecord = $DB->get_record('block_alma_ai_tutor_prompts', ['userid' => $user_id, 'courseid' => $course_id]);
        $task = $taskrecord ? $taskrecord->prompt : get_string('default_prompt', 'block_alma_ai_tutor');

        $last_conversations = $DB->get_records_sql(
            "SELECT question, answer
               FROM {block_alma_ai_tutor_conversations}
              WHERE userid = :userid
           ORDER BY timecreated DESC
              LIMIT 10",
            ['userid' => $user_id]
        );

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

        $response = $this->retrieve_and_generate($question, $task, (string)$course_id);
        if ($response === null) {
            return null;
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
    public function index_text_file(string $file_path, string $collection, string $course_id): bool {
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

        $s3_key = 'courses/' . $course_id . '/' . sha1($file_path . '|' . $course_id . '|' . microtime()) . '.' . $ext;
        $content_type = $is_pdf ? 'application/pdf' : 'text/plain; charset=utf-8';

        if (!$this->s3_put_object($this->s3_bucket, $s3_key, $content, $content_type)) {
            return false;
        }

        $ingestion = $this->bedrock_agent_request(
            '/knowledgebases/' . rawurlencode($this->knowledge_base_id)
                . '/datasources/' . rawurlencode($this->data_source_id) . '/ingestionjobs',
            [
                'knowledgeBaseId' => $this->knowledge_base_id,
                'dataSourceId'    => $this->data_source_id,
            ]
        );

        if ($ingestion === null) {
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
            ]
        );

        if ($ingestion === null) {
            return false;
        }

        $this->last_error = 'Knowledge Base re-sync initiated. This may take 1-5 minutes to complete.';
        return true;
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
    private function retrieve_and_generate(string $question, string $task, string $courseid): ?string {
        $prompttemplate = $this->ensure_kb_prompt_template($task);
        if (strpos($prompttemplate, '$search_results$') === false) {
            $prompttemplate .= '\n\nRetrieved context:\n$search_results$';
        }

        $payload = [
            'input' => [
                'text' => $question,
            ],
            'retrieveAndGenerateConfiguration' => [
                'type' => 'KNOWLEDGE_BASE',
                'knowledgeBaseConfiguration' => [
                    'knowledgeBaseId' => $this->knowledge_base_id,
                    'modelArn' => 'arn:aws:bedrock:' . $this->region . '::foundation-model/' . $this->chat_model_id,
                    'retrievalConfiguration' => [
                        'vectorSearchConfiguration' => [
                            'numberOfResults' => 10,
                        ],
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
                        'numberOfResults' => 5,
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
    private function bedrock_agent_request(string $path, array $payload): ?array {
        $host = 'bedrock-agent.' . $this->region . '.amazonaws.com';
        return $this->signed_json_request('bedrock', $host, $path, $payload);
    }

    /**
     * @param string $service
     * @param string $host
     * @param string $path
     * @param array $payload
     * @return array|null
     */
    private function signed_json_request(string $service, string $host, string $path, array $payload): ?array {
        $body = json_encode($payload);
        if ($body === false) {
            $this->last_error = get_string('json_encode_error', 'block_alma_ai_tutor') . json_last_error_msg();
            return null;
        }

        $signer = new aws_v4_signer($this->access_key, $this->secret_key, $this->region, $service, $host);
        $headers = $signer->sign_request('POST', $path, '', $body, [
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
            CURLOPT_POST => true,
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
