<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Returns the full R2 configuration from WordPress options.
 */
function wp_r2_get_config() {
    return [
        'account_id' => get_option('wp_r2_account_id'),
        'access_key' => get_option('wp_r2_access_key'),
        'secret_key' => get_option('wp_r2_secret_key'),
        'bucket'     => get_option('wp_r2_bucket'),
        'cdn_url'    => rtrim(get_option('wp_r2_cdn_url'), '/'),
    ];
}

/**
 * Build AWS Signature Version 4 Authorization header.
 *
 * @param string $method      HTTP method (GET, PUT, etc.)
 * @param string $path        Path component (e.g. /bucket/key)
 * @param string $host        Host (e.g. accountid.r2.cloudflarestorage.com)
 * @param array  $headers     Associative array of headers (must include x-amz-date and x-amz-content-sha256)
 * @param string $body        Request body (empty string for GET)
 * @param string $access_key  R2 access key
 * @param string $secret_key  R2 secret key
 * @param string $region      Region (default: 'auto')
 * @param string $service     Service (default: 's3')
 *
 * @return string  The Authorization header value
 */
function wp_r2_build_auth_header( $method, $path, $host, $headers, $body, $access_key, $secret_key, $region = 'auto', $service = 's3' ) {
    $amz_date   = $headers['x-amz-date'];
    $date_stamp = substr($amz_date, 0, 8);

    // Normalize headers to lowercase and sort alphabetically
    $headers_lower = [];
    foreach ($headers as $k => $v) {
        $headers_lower[strtolower($k)] = trim($v);
    }
    ksort($headers_lower);

    $canonical_headers = '';
    foreach ($headers_lower as $k => $v) {
        $canonical_headers .= $k . ':' . $v . "\n";
    }
    $signed_headers = implode(';', array_keys($headers_lower));

    $payload_hash = hash('sha256', $body);

    // Canonical request
    $canonical_request = implode("\n", [
        $method,
        $path,
        '',  // canonical query string (empty)
        $canonical_headers,
        $signed_headers,
        $payload_hash,
    ]);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("[R2 SIGNING] Canonical Request:\n" . $canonical_request);
    }

    // Credential scope & string to sign
    $credential_scope = $date_stamp . '/' . $region . '/' . $service . '/aws4_request';
    $string_to_sign = implode("\n", [
        'AWS4-HMAC-SHA256',
        $amz_date,
        $credential_scope,
        hash('sha256', $canonical_request),
    ]);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("[R2 SIGNING] String to Sign:\n" . $string_to_sign);
    }

    // Signing key derivation
    $k_date    = hash_hmac('sha256', $date_stamp,    'AWS4' . $secret_key, true);
    $k_region  = hash_hmac('sha256', $region,        $k_date,              true);
    $k_service = hash_hmac('sha256', $service,       $k_region,            true);
    $k_signing = hash_hmac('sha256', 'aws4_request', $k_service,           true);

    $signature = hash_hmac('sha256', $string_to_sign, $k_signing);

    return "AWS4-HMAC-SHA256 Credential={$access_key}/{$credential_scope}, SignedHeaders={$signed_headers}, Signature={$signature}";
}

/**
 * Upload a local file to Cloudflare R2.
 *
 * @param string $file_path  Absolute path to the local file.
 * @param string $file_name  The key to store in R2 (can include path prefix, e.g. '2026/06/image.jpg').
 *
 * @return array  ['success' => bool, 'url' => string] on success or ['success' => false, 'message' => string] on failure.
 */
function wp_r2_upload_file($file_path, $file_name) {
    $config     = wp_r2_get_config();
    $bucket     = $config['bucket'];
    $account_id = $config['account_id'];
    $access_key = $config['access_key'];
    $secret_key = $config['secret_key'];

    $region  = 'auto';
    $service = 's3';

    $host = $account_id . '.r2.cloudflarestorage.com';
    $url  = "https://{$host}/{$bucket}/{$file_name}";
    $path = '/' . $bucket . '/' . $file_name;

    $method = 'PUT';
    $body   = file_get_contents($file_path);

    $amz_date = gmdate('Ymd\THis\Z');

    $headers = [
        'Host'                 => $host,
        'x-amz-content-sha256' => hash('sha256', $body),
        'x-amz-date'           => $amz_date,
    ];

    $auth_header = wp_r2_build_auth_header($method, $path, $host, $headers, $body, $access_key, $secret_key, $region, $service);
    $headers['Authorization'] = $auth_header;

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("[R2 REQUEST] PUT {$url}");
    }

    $response = wp_remote_request($url, [
        'method'  => $method,
        'headers' => $headers,
        'body'    => $body,
        'timeout' => 60,
    ]);

    if (is_wp_error($response)) {
        error_log('[R2 UPLOAD ERROR] ' . $response->get_error_message());
        return ['success' => false, 'message' => $response->get_error_message()];
    }

    $status = wp_remote_retrieve_response_code($response);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("[R2 UPLOAD] Status: {$status}, Body: " . wp_remote_retrieve_body($response));
    }

    if ($status === 200) {
        return ['success' => true, 'url' => $url];
    }

    error_log('[R2 UPLOAD FAILED] Status: ' . $status . ' Body: ' . wp_remote_retrieve_body($response));
    return ['success' => false, 'message' => wp_remote_retrieve_body($response)];
}

/**
 * Upload a Base64-encoded image string to Cloudflare R2.
 *
 * @param string $base64_string  Raw or data-URI base64 string.
 *
 * @return array  Same shape as wp_r2_upload_file().
 */
function wp_r2_upload_base64($base64_string) {
    if (empty($base64_string)) {
        return ['error' => 'Base64 string kosong'];
    }

    // Strip data URI prefix (e.g. "data:image/png;base64,")
    if (preg_match('/^data:(.*?);base64,/', $base64_string, $matches)) {
        $mime_type    = $matches[1];
        $base64_string = substr($base64_string, strpos($base64_string, ',') + 1);
    } else {
        $mime_type = 'image/jpeg';
    }

    $data = base64_decode($base64_string);
    if ($data === false) {
        return ['error' => 'Gagal decode base64'];
    }

    $ext_map = [
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'image/jpeg' => 'jpg',
    ];
    $ext = $ext_map[$mime_type] ?? 'jpg';

    $tmpfname = wp_tempnam('r2_upload_' . time() . '.' . $ext);
    file_put_contents($tmpfname, $data);

    $filename = 'img_' . time() . '.' . $ext;
    $result   = wp_r2_upload_file($tmpfname, $filename);

    @unlink($tmpfname);

    return $result;
}

/**
 * List all files in the configured R2 bucket.
 *
 * @return array  Array of ['name' => string, 'url' => string] or empty array on failure.
 */
function wp_r2_list_files() {
    $config     = wp_r2_get_config();
    $bucket     = $config['bucket'];
    $account_id = $config['account_id'];
    $access_key = $config['access_key'];
    $secret_key = $config['secret_key'];

    if (!$bucket || !$account_id || !$access_key || !$secret_key) {
        error_log('[R2 LIST] Missing config');
        return [];
    }

    $region  = 'auto';
    $service = 's3';
    $host    = $account_id . '.r2.cloudflarestorage.com';
    $url     = "https://{$host}/{$bucket}";
    $path    = '/' . $bucket;

    $method   = 'GET';
    $body     = '';
    $amz_date = gmdate('Ymd\THis\Z');

    $headers = [
        'Host'                 => $host,
        'x-amz-content-sha256' => hash('sha256', $body),
        'x-amz-date'           => $amz_date,
    ];

    $auth_header = wp_r2_build_auth_header($method, $path, $host, $headers, $body, $access_key, $secret_key, $region, $service);
    $headers['Authorization'] = $auth_header;

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("[R2 LIST REQUEST] GET {$url}");
    }

    $response = wp_remote_request($url, [
        'method'  => $method,
        'headers' => $headers,
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        error_log('[R2 LIST ERROR] ' . $response->get_error_message());
        return [];
    }

    $status = wp_remote_retrieve_response_code($response);
    $body   = wp_remote_retrieve_body($response);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("[R2 LIST RESPONSE] Status: {$status}");
        error_log("[R2 LIST RESPONSE] Body (first 500 chars): " . substr($body, 0, 500));
    }

    $xml = simplexml_load_string($body);
    if (!$xml) {
        error_log('[R2 LIST] Failed parsing XML response');
        return [];
    }

    $files = [];
    if (isset($xml->Contents)) {
        foreach ($xml->Contents as $c) {
            $name    = (string)$c->Key;
            $files[] = [
                'name' => $name,
                'url'  => $url . '/' . $name,
            ];
        }
    }

    return $files;
}
