<?php
/**
 * Bedrock credential validation page.
 *
 * @package    block_alma_ai_tutor
 * @copyright  2025 Universite TELUQ and the UNIVERSITE GASTON BERGER DE SAINT-LOUIS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/filelib.php');

use block_alma_ai_tutor\aws_v4_signer;

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/blocks/alma_ai_tutor/test_api_keys.php');
$PAGE->set_title(get_string('test_api_keys', 'block_alma_ai_tutor'));
$PAGE->set_heading(get_string('test_api_keys', 'block_alma_ai_tutor'));

/**
 * Performs a signed Bedrock JSON request.
 *
 * @param string $region
 * @param string $access_key
 * @param string $secret_key
 * @param string $service
 * @param string $host
 * @param string $path
 * @param array $payload
 * @return array
 */
function bedrock_signed_request(string $region, string $access_key, string $secret_key, string $service, string $host, string $path, array $payload): array {
    $body = json_encode($payload);
    if ($body === false) {
        return [
            'success' => false,
            'message' => get_string('json_encode_error', 'block_alma_ai_tutor') . json_last_error_msg(),
            'data' => null,
        ];
    }

    $signer = new aws_v4_signer($access_key, $secret_key, $region, $service, $host);
    $headers = $signer->sign_request('POST', $path, '', $body, [
        'content-type' => 'application/json',
        'accept' => 'application/json',
    ]);

    $headerlines = [];
    foreach ($headers as $key => $value) {
        $headerlines[] = $key . ': ' . $value;
    }

    $curl = new curl();
    $options = [
        'returntransfer' => true,
        'post' => true,
        'postfields' => $body,
        'httpheader' => $headerlines,
        'ssl_verifypeer' => true,
        'timeout' => 60,
    ];

    $response = $curl->post('https://' . $host . $path, $body, $options);
    $http_status = $curl->get_info()['http_code'] ?? 0;

    if ($curl->get_errno()) {
        return [
            'success' => false,
            'message' => get_string('curl_error', 'block_alma_ai_tutor') . $curl->error,
            'data' => null,
        ];
    }

    if ($http_status < 200 || $http_status >= 300) {
        return [
            'success' => false,
            'message' => get_string('http_error', 'block_alma_ai_tutor') . $http_status . ': ' . $response,
            'data' => null,
        ];
    }

    $decoded = json_decode($response, true);
    if ($response !== '' && json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => false,
            'message' => get_string('json_decode_error', 'block_alma_ai_tutor') . json_last_error_msg(),
            'data' => null,
        ];
    }

    return [
        'success' => true,
        'message' => get_string('bedrock_valid_credentials', 'block_alma_ai_tutor'),
        'data' => $decoded,
    ];
}

/**
 * Validate Bedrock Runtime invocation.
 *
 * @param string $region
 * @param string $access_key
 * @param string $secret_key
 * @param string $model_id
 * @return array
 */
function validate_bedrock_runtime(string $region, string $access_key, string $secret_key, string $model_id): array {
    $host = 'bedrock-runtime.' . $region . '.amazonaws.com';
    $path = '/model/' . rawurlencode($model_id) . '/converse';

    $payload = [
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    ['text' => 'health check'],
                ],
            ],
        ],
        'inferenceConfig' => [
            'maxTokens' => 20,
            'temperature' => 0,
        ],
    ];

    $res = bedrock_signed_request($region, $access_key, $secret_key, 'bedrock', $host, $path, $payload);
    if (!$res['success']) {
        return [
            'success' => false,
            'message' => get_string('bedrock_runtime_invalid', 'block_alma_ai_tutor') . ' ' . $res['message'],
        ];
    }

    return [
        'success' => true,
        'message' => get_string('bedrock_runtime_valid', 'block_alma_ai_tutor'),
    ];
}

/**
 * Validate Bedrock Knowledge Base access.
 *
 * @param string $region
 * @param string $access_key
 * @param string $secret_key
 * @param string $kbid
 * @return array
 */
function validate_bedrock_kb(string $region, string $access_key, string $secret_key, string $kbid): array {
    $host = 'bedrock-agent-runtime.' . $region . '.amazonaws.com';
    $path = '/knowledgebases/' . rawurlencode($kbid) . '/retrieve';

    $payload = [
        'knowledgeBaseId' => $kbid,
        'retrievalQuery' => [
            'text' => 'health check',
        ],
        'retrievalConfiguration' => [
            'vectorSearchConfiguration' => [
                'numberOfResults' => 5,
            ],
        ],
    ];

    $res = bedrock_signed_request($region, $access_key, $secret_key, 'bedrock', $host, $path, $payload);
    if (!$res['success']) {
        return [
            'success' => false,
            'message' => get_string('bedrock_kb_invalid', 'block_alma_ai_tutor') . ' ' . $res['message'],
            'document_count' => 0,
            'documents' => [],
        ];
    }

    $results = $res['data']['retrievalResults'] ?? [];
    $count = is_array($results) ? count($results) : 0;
    
    $documents = [];
    foreach ($results as $doc) {
        $documents[] = [
            'source' => $doc['location']['s3Location']['uri'] ?? 'unknown',
            'content' => substr($doc['content']['text'] ?? '', 0, 200),
        ];
    }

    return [
        'success' => true,
        'message' => get_string('bedrock_kb_valid', 'block_alma_ai_tutor') . " (Found $count documents)",
        'document_count' => $count,
        'documents' => $documents,
    ];
}

$region = trim((string)get_config('block_alma_ai_tutor', 'bedrock_region'));
$accesskey = trim((string)get_config('block_alma_ai_tutor', 'bedrock_access_key'));
$secretkey = trim((string)get_config('block_alma_ai_tutor', 'bedrock_secret_key'));
$kbid = trim((string)get_config('block_alma_ai_tutor', 'bedrock_knowledge_base_id'));
$modelid = trim((string)get_config('block_alma_ai_tutor', 'bedrock_chat_model_id'));

if (empty($modelid)) {
    $modelid = 'cohere.command-r-v1:0';
}

$results = [];
$debug = [
    'bedrock_region'            => $region    !== '' ? $region    : '(empty)',
    'bedrock_access_key'        => $accesskey !== '' ? '*** (set, ' . strlen($accesskey) . ' chars)' : '(empty)',
    'bedrock_secret_key'        => $secretkey !== '' ? '*** (set, ' . strlen($secretkey) . ' chars)' : '(empty)',
    'bedrock_knowledge_base_id' => $kbid      !== '' ? $kbid      : '(empty)',
    'bedrock_chat_model_id'     => $modelid,
];
echo $OUTPUT->header();
echo html_writer::tag('h4', 'Loaded config values (debug)');
echo html_writer::start_tag('ul');
foreach ($debug as $k => $v) {
    echo html_writer::tag('li', html_writer::tag('code', s($k)) . ': ' . html_writer::tag('strong', s($v)));
}
echo html_writer::end_tag('ul');
echo html_writer::tag('hr', '');
$output_header_already_sent = true;

if (empty($region) || empty($accesskey) || empty($secretkey) || empty($kbid)) {
    $results['bedrock'] = [
        'success' => false,
        'message' => get_string('bedrock_not_configured', 'block_alma_ai_tutor'),
    ];
} else {
    $results['bedrock runtime'] = validate_bedrock_runtime($region, $accesskey, $secretkey, $modelid);
    $results['bedrock knowledge base'] = validate_bedrock_kb($region, $accesskey, $secretkey, $kbid);
}

foreach ($results as $service => $result) {
    $messageclass = $result['success'] ? 'alert alert-success' : 'alert alert-danger';
    echo html_writer::div(
        html_writer::tag('strong', ucfirst($service) . ': ') . $result['message'],
        $messageclass
    );
    
    // Display document count if present
    if (!empty($result['documents'])) {
        echo html_writer::tag('p', '<strong>Retrieved Documents:</strong>', ['style' => 'margin-top: 10px;']);
        echo html_writer::start_tag('ul', ['style' => 'margin-left: 20px;']);
        foreach ($result['documents'] as $doc) {
            echo html_writer::tag('li', 
                '<strong>Source:</strong> ' . s($doc['source']) . '<br/>' .
                '<strong>Preview:</strong> ' . nl2br(s($doc['content'])) . '...',
                ['style' => 'margin-bottom: 10px;']
            );
        }
        echo html_writer::end_tag('ul');
    }
}

echo html_writer::div(
    $OUTPUT->single_button(
        new moodle_url('/admin/settings.php', ['section' => 'blocksettingchatbot']),
        get_string('back', 'block_alma_ai_tutor'),
        'get'
    ),
    'mt-3'
);

echo $OUTPUT->footer();
