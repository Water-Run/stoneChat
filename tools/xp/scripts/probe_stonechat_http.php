<?php
/*
 * Minimal PHP 5.2-compatible HTTP readiness probe for the XP launcher.
 */

if ($argc < 2 || $argv[1] === '') {
    exit(1);
}

$context = stream_context_create(array(
    'http' => array(
        'timeout' => 5
    )
));
$body = @file_get_contents($argv[1], false, $context);

if ($body === false || $body === '') {
    exit(1);
}

if (!isset($http_response_header[0])
    || !preg_match('/^HTTP\/1\.[01] 200/', $http_response_header[0])) {
    exit(1);
}

exit(0);
