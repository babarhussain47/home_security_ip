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
 * success, or JSON {code, desc, data} on failure. On success, the
 * X-Detection-Status header is always one of ok|skipped|error: ok pairs with
 * X-Detections (JSON counts), error pairs with X-Detection-Error (truncated
 * message, also logged server-side per 'log_file' in the config).
 */

$config = require '/var/www/capture-config.local.php';

const TIMEOUT_SECONDS = 60; // cloud HLS handshake can be slow, especially over weak camera wifi

// Collapses a possibly multi-line traceback/output blob down to one short
// line for the response header — full detail still goes to the log file.
function shortError(string $text): string
{
    $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn ($l) => $l !== ''));
    $line = end($lines) ?: 'unknown error';

    return mb_substr($line, 0, 150);
}

function logLine(array $config, string $message): void
{
    $logFile = $config['log_file'] ?? sys_get_temp_dir().'/capture-url.log';
    $line = sprintf('[%s] %s%s', date('Y-m-d H:i:s'), $message, PHP_EOL);
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

function fail(int $status, string $message): never
{
    global $config;
    logLine($config ?? [], "FAIL [$status] $message");
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['code' => $status, 'desc' => $message, 'data' => new stdClass()]);
    exit;
}

// Best-effort YOLOv8n classification of the captured frame. Never
// throws/fails the request if the classifier isn't configured or errors out
// — returning the captured frame is the actual requirement here, detection
// counts are a bonus. Status is always reported back via response headers
// (see X-Detection-Status) and failures are logged, so a silent miss is
// visible instead of just absent.
function classify(array $config, string $imagePath): array
{
    $missing = [];
    if (empty($config['classifier_python'])) {
        $missing[] = 'classifier_python';
    }
    if (empty($config['classifier_model'])) {
        $missing[] = 'classifier_model';
    }
    if ($missing !== []) {
        $reason = implode(', ', $missing).' not set in capture-config.local.php';
        return ['status' => 'skipped', 'counts' => null, 'error' => $reason];
    }

    if (! is_file($config['classifier_python'])) {
        $reason = "classifier_python does not exist: {$config['classifier_python']}";
        logLine($config, "classification skipped: $reason");

        return ['status' => 'skipped', 'counts' => null, 'error' => $reason];
    }
    if (! is_file($config['classifier_model'])) {
        $reason = "classifier_model does not exist: {$config['classifier_model']}";
        logLine($config, "classification skipped: $reason");

        return ['status' => 'skipped', 'counts' => null, 'error' => $reason];
    }

    $cmd = sprintf(
        '%s %s %s %s 2>&1',
        escapeshellarg($config['classifier_python']),
        escapeshellarg(__DIR__.'/classify.py'),
        escapeshellarg($config['classifier_model']),
        escapeshellarg($imagePath),
    );

    $output = shell_exec($cmd);
    $result = $output !== null ? json_decode(trim((string) $output), true) : null;

    if (is_array($result) && isset($result['counts'])) {
        return ['status' => 'ok', 'counts' => $result['counts'], 'error' => null];
    }

    $rawError = is_array($result) && isset($result['error'])
        ? $result['error']
        : trim((string) $output);

    logLine($config, "classification failed: $rawError");

    return ['status' => 'error', 'counts' => null, 'error' => shortError($rawError)];
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
        fail(504, 'Capture timed out: '.trim(mb_substr($output, -500)));
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

$detection = classify($config, $outputFile);

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
