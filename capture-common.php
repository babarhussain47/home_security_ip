<?php

declare(strict_types=1);

/**
 * capture-common.php — shared helpers for capture-url.php and
 * classify-url.php: auth failure envelope, logging, and the YOLOv8n
 * classifier wrapper. Deploy alongside both scripts.
 */

// Model ships in this repo at model/yolov8n.onnx and is deployed alongside
// these scripts, so the path is fixed — no per-server config needed for it.
// classifier_python still comes from config since the Python env legitimately
// differs per server (system python3 vs a dedicated venv).
const MODEL_PATH = __DIR__.'/model/yolov8n.onnx';

// Collapses a possibly multi-line traceback/output blob down to one short
// line for the response header — full detail still goes to the log file.
function shortError(string $text): string
{
    $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn ($l) => $l !== ''));
    $line = end($lines) ?: 'unknown error';

    return mb_substr($line, 0, 150);
}

// Keeps a copy of the last captured/downloaded frame at a fixed path for
// manual inspection, purely a debugging aid — the real response still comes
// from the per-request tempnam() file so concurrent requests never collide.
// No-op unless 'debug_image_path' is set in the config.
function saveDebugImage(array $config, string $capturedFile): void
{
    if (! empty($config['debug_image_path']) && file_exists($capturedFile)) {
        @copy($capturedFile, $config['debug_image_path']);
    }
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

// Best-effort YOLOv8n classification of an image file. Never throws — the
// caller decides what "no result" means for its own response shape. Status
// is one of ok|skipped|error, and failures are logged, so a silent miss is
// visible instead of just absent.
function classify(array $config, string $imagePath): array
{
    if (empty($config['classifier_python'])) {
        return ['status' => 'skipped', 'counts' => null, 'error' => 'classifier_python not set in capture-config.local.php'];
    }
    if (! is_file($config['classifier_python'])) {
        $reason = "classifier_python does not exist: {$config['classifier_python']}";
        logLine($config, "classification skipped: $reason");

        return ['status' => 'skipped', 'counts' => null, 'error' => $reason];
    }
    if (! is_file(MODEL_PATH)) {
        $reason = 'model file missing: '.MODEL_PATH;
        logLine($config, "classification skipped: $reason");

        return ['status' => 'skipped', 'counts' => null, 'error' => $reason];
    }

    $cmd = sprintf(
        '%s %s %s %s 2>&1',
        escapeshellarg($config['classifier_python']),
        escapeshellarg(__DIR__.'/classify.py'),
        escapeshellarg(MODEL_PATH),
        escapeshellarg($imagePath),
    );

    // shell_exec() itself is a fatal (uncatchable in PHP < 8, catchable Error
    // in PHP 8+) if it's listed in disable_functions on this server — guard
    // it so a hardened php.ini degrades to status=error instead of a 500
    // with no body.
    try {
        $output = shell_exec($cmd);
    } catch (\Throwable $e) {
        $reason = 'shell_exec unavailable: '.$e->getMessage();
        logLine($config, "classification failed: $reason");

        return ['status' => 'error', 'counts' => null, 'error' => shortError($reason)];
    }
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
