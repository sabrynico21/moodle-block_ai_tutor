<?php
/**
 * Debug script to test RAG retrieval pipeline step-by-step
 * 
 * Place this file in: /blocks/alma_ai_tutor/debug_retrieval.php
 * Access at: http://yoursite.local/blocks/alma_ai_tutor/debug_retrieval.php
 * 
 * @package    block_alma_ai_tutor
 * @copyright  2025
 */

require_once('../../config.php');
require_once('classes/weaviate_connector.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/blocks/alma_ai_tutor/debug_retrieval.php');
$PAGE->set_title('RAG Retrieval Debug');
$PAGE->set_heading('RAG Retrieval Debug');

echo $OUTPUT->header();

// Get config
$region = trim((string)get_config('block_alma_ai_tutor', 'bedrock_region'));
$access_key = trim((string)get_config('block_alma_ai_tutor', 'bedrock_access_key'));
$secret_key = trim((string)get_config('block_alma_ai_tutor', 'bedrock_secret_key'));
$kb_id = trim((string)get_config('block_alma_ai_tutor', 'bedrock_knowledge_base_id'));
$model_id = trim((string)get_config('block_alma_ai_tutor', 'bedrock_chat_model_id'));
$data_source_id = trim((string)get_config('block_alma_ai_tutor', 'bedrock_data_source_id'));
$s3_bucket = trim((string)get_config('block_alma_ai_tutor', 'bedrock_s3_bucket'));

echo '<h3>Configuration Check</h3>';
echo '<table border="1" cellpadding="10">';
echo '<tr><td><strong>Region</strong></td><td>' . (empty($region) ? '<span style="color: red;">NOT SET</span>' : s($region)) . '</td></tr>';
echo '<tr><td><strong>Access Key</strong></td><td>' . (empty($access_key) ? '<span style="color: red;">NOT SET</span>' : '****' . substr($access_key, -4)) . '</td></tr>';
echo '<tr><td><strong>Secret Key</strong></td><td>' . (empty($secret_key) ? '<span style="color: red;">NOT SET</span>' : '****' . substr($secret_key, -4)) . '</td></tr>';
echo '<tr><td><strong>Knowledge Base ID</strong></td><td>' . (empty($kb_id) ? '<span style="color: red;">NOT SET</span>' : s($kb_id)) . '</td></tr>';
echo '<tr><td><strong>Model ID</strong></td><td>' . (empty($model_id) ? 'cohere.command-r-v1:0 (default)' : s($model_id)) . '</td></tr>';
echo '<tr><td><strong>Data Source ID</strong></td><td>' . (empty($data_source_id) ? '<span style="color: orange;">NOT SET (may be needed)</span>' : s($data_source_id)) . '</td></tr>';
echo '<tr><td><strong>S3 Bucket</strong></td><td>' . (empty($s3_bucket) ? '<span style="color: orange;">NOT SET (may be needed)</span>' : s($s3_bucket)) . '</td></tr>';
echo '</table>';

if (empty($region) || empty($access_key) || empty($secret_key) || empty($kb_id)) {
    echo '<p style="color: red; font-weight: bold;">ERROR: Missing required configuration. Update plugin settings.</p>';
    echo $OUTPUT->footer();
    exit;
}

// Initialize connector
$connector = new \block_alma_ai_tutor\weaviate_connector(
    $region, $access_key, $secret_key, $kb_id,
    !empty($model_id) ? $model_id : 'cohere.command-r-v1:0',
    $data_source_id, $s3_bucket
);

// Test queries
$test_queries = [
    'health check',
    'valor vector',
    'valor',
];

echo '<h3>Test Retrieval Queries</h3>';
echo '<p>These queries test if documents are being retrieved from your Knowledge Base:</p>';

foreach ($test_queries as $query) {
    echo '<div style="border: 1px solid #ccc; padding: 10px; margin: 10px 0;">';
    echo '<h4>Query: <code>' . s($query) . '</code></h4>';
    
    // Call the private retrieve method via reflection
    try {
        $reflection = new ReflectionClass($connector);
        $method = $reflection->getMethod('bedrock_agent_runtime_request');
        $method->setAccessible(true);
        
        $payload = [
            'knowledgeBaseId' => $kb_id,
            'retrievalQuery' => ['text' => $query],
            'retrievalConfiguration' => [
                'vectorSearchConfiguration' => [
                    'numberOfResults' => 5,
                ],
            ],
        ];
        
        $result = $method->invoke($connector, 
            '/knowledgebases/' . rawurlencode($kb_id) . '/retrieve', 
            $payload
        );
        
        if ($result === null) {
            echo '<p style="color: red;"><strong>ERROR:</strong> Retrieval request failed. Check API credentials.</p>';
        } else {
            $retrieval_results = $result['retrievalResults'] ?? [];
            $count = is_array($retrieval_results) ? count($retrieval_results) : 0;
            
            echo '<p><strong>Results Found: ' . $count . '</strong></p>';
            
            if ($count === 0) {
                echo '<p style="color: orange;">⚠️ No documents retrieved for this query. Your uploaded files may not be indexed yet.</p>';
            } else {
                echo '<ul>';
                foreach ($retrieval_results as $i => $result_item) {
                    $source = $result_item['location']['s3Location']['uri'] ?? 'unknown source';
                    $content = $result_item['content']['text'] ?? '';
                    $content_preview = substr($content, 0, 300);
                    
                    echo '<li>';
                    echo '<strong>Document ' . ($i + 1) . ':</strong><br/>';
                    echo 'Source: <code>' . s($source) . '</code><br/>';
                    echo 'Score: ' . ($result_item['score'] ?? 'N/A') . '<br/>';
                    echo 'Preview: <pre>' . s($content_preview) . '...</pre>';
                    echo '</li>';
                }
                echo '</ul>';
            }
        }
    } catch (Exception $e) {
        echo '<p style="color: red;">Exception: ' . s($e->getMessage()) . '</p>';
    }
    
    echo '</div>';
}

echo '<h3>Database Check: Recently Uploaded Files</h3>';
global $DB;
$conversations = $DB->get_records(
    'block_alma_ai_tutor_conversations',
    [],
    'timecreated DESC',
    'courseid, userid, question, answer, timecreated',
    0, 5
);

if (empty($conversations)) {
    echo '<p>No conversations found in database.</p>';
} else {
    echo '<table border="1" cellpadding="10">';
    echo '<thead><tr><th>Course ID</th><th>User ID</th><th>Question (first 100 chars)</th><th>Answer (first 100 chars)</th><th>Time</th></tr></thead>';
    foreach ($conversations as $conv) {
        echo '<tr>';
        echo '<td>' . $conv->courseid . '</td>';
        echo '<td>' . $conv->userid . '</td>';
        echo '<td><code>' . htmlspecialchars(substr($conv->question, 0, 100)) . '...</code></td>';
        echo '<td><code>' . htmlspecialchars(substr($conv->answer, 0, 100)) . '...</code></td>';
        echo '<td>' . date('Y-m-d H:i:s', $conv->timecreated) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
}

echo '<p><a href="' . new moodle_url('/admin/settings.php', ['section' => 'blocksettingchatbot']) . '">← Back to Plugin Settings</a></p>';

echo $OUTPUT->footer();
