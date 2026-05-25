<?php
try {
    $res = file_get_contents('http://127.0.0.1:8000/api/v1/settings/trip-types');
    file_put_contents('public/curl_output.txt', 'HTTP SUCCESS: ' . (string)$res);
} catch (Throwable $e) {
    file_put_contents('public/curl_output.txt', 'HTTP ERROR: ' . $e->getMessage());
}
