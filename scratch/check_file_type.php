<?php
$f = fopen(__DIR__ . '/../safarakealayna', 'rb');
$header = fread($f, 100);
fclose($f);

echo "Hex: " . bin2hex($header) . "\n";
echo "ASCII: " . preg_replace('/[^\x20-\x7E]/', '.', $header) . "\n";
