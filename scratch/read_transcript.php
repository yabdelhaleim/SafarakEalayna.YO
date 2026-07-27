<?php
$lines = file('C:/Users/PC/.gemini/antigravity/brain/455b590b-2a37-438c-9ef3-6414915617cd/.system_generated/logs/transcript.jsonl');
foreach ($lines as $l) {
    $j = json_decode($l, true);
    if (isset($j['type']) && $j['type'] === 'USER_INPUT') {
        echo "========================================\n";
        echo "STEP: " . $j['step_index'] . "\n";
        echo "USER: " . $j['content'] . "\n";
    }
}
