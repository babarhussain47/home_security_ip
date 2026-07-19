<?php

declare(strict_types=1);

/**
 * capture.php — grabs a single JPEG frame from a camera's RTSP live stream
 * and returns the raw image bytes. Deploy alongside index.php in
 * /var/www/html on the openpicdecode server (this box already has ffmpeg +
 * network access to the cameras via the existing decode pipeline).
 *
 * Called server-to-server by the Home Security Laravel API — never exposed
 * to end users directly. Guarded by a shared API key read from a config file
 * kept OUTSIDE the web root so it's never servable over HTTP.
 *
 * Not currently wired up (cameras are Imou cloud-synced with no static LAN
 * IP) — kept for a possible future locally-reachable camera. See
 * capture-url.php for what's actually in use today.
 *
 * Request:  POST /capture.php
 *   Headers: X-Api-Key: <shared secret>
 *   Body (JSON): {
 *     "ip_address": "192.168.1.50",
 *     "rtsp_port": 554,                 // optional, default 554
 *     "rtsp_path": "/cam/realmonitor?channel=1&subtype=0", // optional
 *     "username": "admin",
 *     "password": "secret"
 *   }
 *
 * Response: 200 with Content-Type: image/jpeg and the raw JPEG bytes, or a
 * JSON error body { "code": <http status>, "desc": "...", "data": {} } on
 * failure — same envelope shape as index.php's proxy so callers can branch
 * on Content-Type alone.
 */

$config = require '/var/www/capture-config.local.php';

const TIMEOUT_SECONDS = 15;

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

$ip = $body['ip_address'] ?? null;
$port = (int) ($body['rtsp_port'] ?? 554);
$path = (string) ($body['rtsp_path'] ?? '/cam/realmonitor?channel=1&subtype=0');
$username = (string) ($body['username'] ?? '');
$password = (string) ($body['password'] ?? '');

if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
    fail(422, 'A valid ip_address is required');
}
if ($port < 1 || $port > 65535) {
    fail(422, 'Invalid rtsp_port');
}
if ($path === '' || $path[0] !== '/') {
    $path = '/'.$path;
}

// Credentials are URL-encoded into the RTSP url, never shell-interpolated
// raw — the whole url is passed through escapeshellarg() below.
$auth = $username !== '' ? rawurlencode($username).':'.rawurlencode($password).'@' : '';
$rtspUrl = "rtsp://{$auth}{$ip}:{$port}{$path}";

$outputFile = tempnam(sys_get_temp_dir(), 'cap_').'.jpg';

$cmd = sprintf(
    '%s -y -rtsp_transport tcp -i %s -frames:v 1 -q:v 2 -f image2 %s 2>&1',
    escapeshellcmd($config['ffmpeg_bin'] ?? 'ffmpeg'),
    escapeshellarg($rtspUrl),
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
        fail(504, 'Camera capture timed out');
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
