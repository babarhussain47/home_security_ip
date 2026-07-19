<?php
$path = $_SERVER['REQUEST_URI'];
$url = 'http://127.0.0.1:8085' . $path;
$method = $_SERVER['REQUEST_METHOD'];
$body = file_get_contents('php://input');

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
if ($body !== '') {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}
$reqHeaders = [];
foreach (getallheaders() as $k => $v) {
    if (strtolower($k) === 'host') continue;
    $reqHeaders[] = "$k: $v";
}
curl_setopt($ch, CURLOPT_HTTPHEADER, $reqHeaders);

$response = curl_exec($ch);
if ($response === false) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['code' => 502, 'desc' => 'Bad gateway: ' . curl_error($ch), 'data' => new stdClass()]);
    exit;
}
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$rawHeaders = substr($response, 0, $headerSize);
$responseBody = substr($response, $headerSize);
curl_close($ch);

http_response_code($httpCode);
foreach (explode("\r\n", $rawHeaders) as $h) {
    if (stripos($h, 'Content-Type:') === 0) {
        header($h);
    }
}
echo $responseBody;
