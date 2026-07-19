<?php

declare(strict_types=1);

/**
 * capture-url.php — grabs a single JPEG frame from any ffmpeg-readable
 * stream URL (e.g. an Imou/Tuya cloud live-preview HLS url) and returns the
 * raw JPEG bytes. Deploy alongside capture.php in /var/www/html, sharing its
 * capture-config.local.php.
 *
 * Laravel resolves the (signed, time-limited) live-preview URL itself via
 * the vendor's Open Platform API (e.g. ImouService::getLiveStreamUrl()) and
 * just hands it here — this script has no vendor-specific knowledge, it
 * only runs ffmpeg against the given URL. This means it works for any
 * camera regardless of local network/IP, and works unchanged for any future
 * provider whose SDK also exposes a playable stream url.
 *
 * Request: POST /capture-url.php
 *   Headers: X-Api-Key: <shared secret>
 *   Body (JSON): { "stream_url": "https://cmgw-sg.easy4ipcloud.com:8890/iot/.../....m3u8?..." }
 *
 * Response: same envelope as capture.php — 200 + image/jpeg bytes on
 * success, or JSON {code, desc, data} on failure.
 */

$config = require '/var/www/capture-config.local.php';

const TIMEOUT_SECONDS = 20; // cloud HLS handshake can be slower than local RTSP

function fail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['code' => $status, 'desc' => $message, 'data' => new stdClass()]);
    exit;
}

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

$streamUrl = (string) ($body['stream_url'] ?? '');
$parts = parse_url($streamUrl);
// Restrict to http(s) so an arbitrary scheme (file://, etc.) can't be
// smuggled into the ffmpeg -i argument.
if ($streamUrl === '' || $parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
    fail(422, 'A valid http(s) stream_url is required');
}

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
        @unlink($outputFile);
        fail(504, 'Capture timed out');
    }

    usleep(100000);
}

fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

if ($exitCode !== 0 || ! file_exists($outputFile) || filesize($outputFile) === 0) {
    @unlink($outputFile);
    fail(502, 'Capture failed: '.trim(mb_substr($output, -500)));
}

$bytes = file_get_contents($outputFile);
@unlink($outputFile);

http_response_code(200);
header('Content-Type: image/jpeg');
header('Content-Length: '.strlen($bytes));
echo $bytes;
