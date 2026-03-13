<?php
/**
 * AWS Signature Version 4 helper for direct Bedrock HTTP calls.
 *
 * @package    block_alma_ai_tutor
 * @copyright  2025 Universite TELUQ and the UNIVERSITE GASTON BERGER DE SAINT-LOUIS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alma_ai_tutor;

defined('MOODLE_INTERNAL') || die();

class aws_v4_signer {
    /** @var string */
    private $access_key;

    /** @var string */
    private $secret_key;

    /** @var string */
    private $region;

    /** @var string */
    private $service;

    /** @var string */
    private $host;

    /** @var string */
    private $algorithm = 'AWS4-HMAC-SHA256';

    /**
     * @param string $access_key AWS access key
     * @param string $secret_key AWS secret key
     * @param string $region AWS region
     * @param string $service AWS service name (bedrock-runtime, bedrock-agent-runtime, ...)
     * @param string $host Request host
     */
    public function __construct(string $access_key, string $secret_key, string $region, string $service, string $host) {
        $this->access_key = trim($access_key);
        $this->secret_key = trim($secret_key);
        $this->region = trim($region);
        $this->service = trim($service);
        $this->host = trim($host);
    }

    /**
     * Returns signed headers for AWS API requests.
     *
     * @param string $method HTTP method
     * @param string $uri Canonical URI path
     * @param string $query Canonical query string
     * @param string $payload Raw payload
     * @param array $extra_headers Extra headers (name => value)
     * @return array Signed headers
     */
    public function sign_request(string $method, string $uri, string $query, string $payload, array $extra_headers = []): array {
        $amzdate = gmdate('Ymd\THis\Z');
        $datestamp = gmdate('Ymd');
        $payload_hash = hash('sha256', $payload);

        $headers = [
            'host' => $this->host,
            'x-amz-content-sha256' => $payload_hash,
            'x-amz-date' => $amzdate,
        ];

        foreach ($extra_headers as $key => $value) {
            $headers[strtolower(trim($key))] = trim((string)$value);
        }

        ksort($headers);

        $canonical_headers = '';
        $signed_headers_list = [];
        foreach ($headers as $key => $value) {
            $canonical_headers .= $key . ':' . preg_replace('/\s+/', ' ', trim($value)) . "\n";
            $signed_headers_list[] = $key;
        }

        $signed_headers = implode(';', $signed_headers_list);
        $canonical_uri = $this->canonicalize_uri($uri);
        $canonical_request = implode("\n", [
            strtoupper(trim($method)),
            $canonical_uri,
            trim($query),
            $canonical_headers,
            $signed_headers,
            $payload_hash,
        ]);

        $credential_scope = $datestamp . '/' . $this->region . '/' . $this->service . '/aws4_request';
        $string_to_sign = implode("\n", [
            $this->algorithm,
            $amzdate,
            $credential_scope,
            hash('sha256', $canonical_request),
        ]);

        $kdate = hash_hmac('sha256', $datestamp, 'AWS4' . $this->secret_key, true);
        $kregion = hash_hmac('sha256', $this->region, $kdate, true);
        $kservice = hash_hmac('sha256', $this->service, $kregion, true);
        $ksigning = hash_hmac('sha256', 'aws4_request', $kservice, true);
        $signature = hash_hmac('sha256', $string_to_sign, $ksigning);

        $headers['authorization'] = $this->algorithm
            . ' Credential=' . $this->access_key . '/' . $credential_scope
            . ', SignedHeaders=' . $signed_headers
            . ', Signature=' . $signature;

        return $headers;
    }

    /**
     * Build SigV4 canonical URI by encoding each segment while preserving '/'.
     *
     * @param string $uri
     * @return string
     */
    private function canonicalize_uri(string $uri): string {
        $path = trim($uri);
        if ($path === '') {
            return '/';
        }

        // SigV4 canonical URI requires each segment to be URI encoded.
        $segments = explode('/', $path);
        $segments = array_map('rawurlencode', $segments);
        return implode('/', $segments);
    }
}
