<?php

declare(strict_types=1);

/**
 * capture-url.php — grabs a single JPEG frame from any ffmpeg-readable
 * stream URL (e.g. an Imou/Tuya cloud live-preview HLS url) and returns the
 * raw JPEG bytes. Deploy alongside capture.php in /var/www/html, sharing its
 * capture-config.local.php. Also deploy classify.py, model/yolov8n.onnx
 * (this repo's model/ dir), and capture-common.php into the same directory
 * as this script.
 *
 * Laravel resolves the (signed, time-limited) live-preview URL itself via
 * the vendor's Open Platform API (e.g. ImouService::getLiveStreamUrl()) and
 * just hands it here — this script has no vendor-specific knowledge, it
 * only runs ffmpeg against the given URL. This means it works for any
 * camera regardless of local network/IP, and works unchanged for any future
 * provider whose SDK also exposes a playable stream url.
 *
 * Request: POST /capture-url.php?classify=1
 *   Headers: X-Api-Key: <shared secret>
 *   Body (JSON): { "stream_url": "https://cmgw-sg.easy4ipcloud.com:8890/iot/.../....m3u8?..." }
 *
 * Classification only runs when ?classify=1 (or true/yes) is passed — it
 * adds real latency (Python startup + inference), so callers opt in per
 * request instead of it always running. Omit it (or pass 0) to just get the
 * frame back as fast as ffmpeg allows.
 *
 * ?ping=1 (or true/yes) short-circuits everything below the api key check
 * and just returns 200 — a cheap way to verify the endpoint is reachable
 * and authenticated without spawning ffmpeg.
 *
 * Response: same envelope as capture.php — 200 + image/jpeg bytes on
 * success, or JSON {code, desc, data} on failure. On success, the
 * X-Detection-Status header is always one of ok|skipped|error: ok pairs with
 * X-Detections (JSON counts), error/skipped pair with X-Detection-Error
 * (short reason, full detail logged server-side per 'log_file' in config).
 */

$config = require '/var/www/capture-config.local.php';
require __DIR__.'/capture-common.php';

const TIMEOUT_SECONDS = 60; // cloud HLS handshake can be slow, especially over weak camera wifi

$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($providedKey === '' || ! hash_equals($config['api_key'], $providedKey)) {
    fail(401, 'Unauthorized');
}

if (filter_var($_GET['ping'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Method not allowed');
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (! is_array($body)) {
    fail(422, 'Invalid JSON body');
}

$streamUrl = (string) ($body['stream_url'] ?? '');
$parts = parse_url($streamUrl);
// Restrict to http(s) so an arbitrary scheme (file://, etc.) can't be
// smuggled into the ffmpeg -i argument.
if ($streamUrl === '' || $parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
    fail(422, 'A valid http(s) stream_url is required');
}

$classificationRequested = filter_var($_GET['classify'] ?? false, FILTER_VALIDATE_BOOLEAN);

$outputFile = tempnam(sys_get_temp_dir(), 'cap_').'.jpg';

$cmd = sprintf(
    '%s -y -i %s -frames:v 1 -q:v 2 -f image2 %s 2>&1',
    escapeshellcmd($config['ffmpeg_bin'] ?? 'ffmpeg'),
    escapeshellarg($streamUrl),
    escapeshellarg($outputFile),
);

$process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (! is_resource($process)) {
    fail(500, 'Could not start ffmpeg');
}

stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$start = time();
$output = '';
while (true) {
    $output .= (string) stream_get_contents($pipes[1]);
    $output .= (string) stream_get_contents($pipes[2]);

    $status = proc_get_status($process);
    if (! $status['running']) {
        break;
    }

    if (time() - $start > TIMEOUT_SECONDS) {
        proc_terminate($process, 9);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        saveDebugImage($config, $outputFile);
        @unlink($outputFile);
        fail(504, 'Capture timed out: '.trim(mb_substr($output, -500)));
    }

    usleep(100000);
}

fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

if ($exitCode !== 0 || ! file_exists($outputFile) || filesize($outputFile) === 0) {
    saveDebugImage($config, $outputFile);
    @unlink($outputFile);
    fail(502, 'Capture failed: '.trim(mb_substr($output, -500)));
}

saveDebugImage($config, $outputFile);

$detection = $classificationRequested
    ? classify($config, $outputFile)
    : ['status' => 'skipped', 'counts' => null, 'error' => 'classification not requested (add ?classify=1)'];

$bytes = file_get_contents($outputFile);
@unlink($outputFile);

http_response_code(200);
header('Content-Type: image/jpeg');
header('Content-Length: '.strlen($bytes));
header('X-Detection-Status: '.$detection['status']); // ok | skipped | error
if ($detection['status'] === 'ok') {
    header('X-Detections: '.json_encode($detection['counts']));
} elseif ($detection['error'] !== null) {
    header('X-Detection-Error: '.$detection['error']);
}
echo $bytes;
