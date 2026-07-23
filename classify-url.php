<?php

declare(strict_types=1);

/**
 * classify-url.php — downloads a single image from a given URL and runs it
 * through the YOLOv8n classifier, no ffmpeg/stream capture involved. Deploy
 * alongside capture-url.php, sharing its capture-config.local.php,
 * capture-common.php, classify.py and model/yolov8n.onnx.
 *
 * Request: POST /classify-url.php
 *   Headers: X-Api-Key: <shared secret>
 *   Body (JSON): { "image_url": "https://example.com/frame.jpg" }
 *
 * Response: 200 + JSON {code, desc, data} on success, same failure envelope
 * as capture-url.php otherwise. data is
 * {status: ok|skipped|error, counts: {...}|null, error: string|null} —
 * same shape classify() returns, just delivered as the body instead of
 * response headers since there's no image to attach them to.
 */

$config = require '/var/www/capture-config.local.php';
require __DIR__.'/capture-common.php';

const DOWNLOAD_TIMEOUT_SECONDS = 20;

$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($providedKey === '' || ! hash_equals($config['api_key'], $providedKey)) {
    fail(401, 'Unauthorized');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Method not allowed');
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (! is_array($body)) {
    fail(422, 'Invalid JSON body');
}

$imageUrl = (string) ($body['image_url'] ?? '');
$parts = parse_url($imageUrl);
// Restrict to http(s) so an arbitrary scheme (file://, etc.) can't be used
// to read local files instead of fetching a remote image.
if ($imageUrl === '' || $parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
    fail(422, 'A valid http(s) image_url is required');
}

$context = stream_context_create([
    'http' => ['timeout' => DOWNLOAD_TIMEOUT_SECONDS],
    'https' => ['timeout' => DOWNLOAD_TIMEOUT_SECONDS],
]);
$imageBytes = @file_get_contents($imageUrl, false, $context);
if ($imageBytes === false || $imageBytes === '') {
    fail(502, 'Could not download image_url');
}

$imageFile = tempnam(sys_get_temp_dir(), 'cls_').'.jpg';
file_put_contents($imageFile, $imageBytes);
saveDebugImage($config, $imageFile);

$detection = classify($config, $imageFile);
@unlink($imageFile);

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['code' => 200, 'desc' => 'ok', 'data' => $detection]);
