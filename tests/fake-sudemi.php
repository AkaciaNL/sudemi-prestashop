<?php
/**
 * A stand-in for sudemi's API, for tests/site-test.php (run with php -S). Records each request's
 * headers to SUDEMI_FAKE_DIR/requests.jsonl and answers with SUDEMI_FAKE_DIR/answer.json.
 */
$dir = getenv('SUDEMI_FAKE_DIR');
$headers = function_exists('getallheaders') ? getallheaders() : [];
file_put_contents($dir . '/requests.jsonl', json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $_SERVER['REQUEST_URI'],
    'headers' => $headers,
]) . "\n", FILE_APPEND);
$answer = json_decode((string) file_get_contents($dir . '/answer.json'), true);
http_response_code((int) $answer['status']);
header('Content-Type: application/json');
echo json_encode($answer['body']);
