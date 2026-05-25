<?php
$data = json_encode(['email' => 'admin@admin.com', 'password' => '11223311']);
$options = [
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n" .
                     "Accept: application/json\r\n" .
                     "Content-Length: " . strlen($data) . "\r\n",
        'content' => $data,
        'ignore_errors' => true
    ]
];
$context  = stream_context_create($options);
$result = file_get_contents('http://127.0.0.1:8000/api/v1/auth/login', false, $context);
$status_line = $http_response_header[0] ?? 'NO STATUS';

echo "HTTP STATUS: " . $status_line . PHP_EOL;
echo "RESPONSE BODY: " . $result . PHP_EOL;
file_put_contents('public/curl_output.txt', "HTTP STATUS: " . $status_line . PHP_EOL . "BODY: " . $result);
