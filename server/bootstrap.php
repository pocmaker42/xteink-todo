<?php
/**
 * Load local config (config.php) + optional API auth.
 */

function xteink_config(): array {
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $defaults = [
        'base_url' => '',
        'api_token' => '',
    ];

    $path = __DIR__ . '/config.php';
    if (!file_exists($path)) {
        $cfg = $defaults;
        return $cfg;
    }

    $loaded = require $path;
    $cfg = array_merge($defaults, is_array($loaded) ? $loaded : []);
    return $cfg;
}

function xteink_request_token(): string {
    $header = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if ($header !== '') {
        return (string)$header;
    }
    return (string)($_GET['token'] ?? '');
}

/** If api_token is set in config.php, require the same token. */
function xteink_require_auth(): void {
    $expected = (string)(xteink_config()['api_token'] ?? '');
    if ($expected === '') {
        return;
    }

    $provided = xteink_request_token();
    if (!hash_equals($expected, $provided)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
