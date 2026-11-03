<?php
/**
 * QA helper: compare two PHPUnit JUnit XML reports and classify the delta.
 *
 * Usage: php tests/Audit/compare_baselines.php main.xml branch.xml
 */
$mainFile = $argv[1] ?? '/tmp/main-junit.xml';
$branchFile = $argv[2] ?? '/tmp/branch-junit.xml';

function loadCases(string $file): array
{
    if (! is_file($file)) {
        fwrite(STDERR, "MISSING: {$file}\n");

        return [];
    }
    $xml = @simplexml_load_file($file);
    if (! $xml) {
        fwrite(STDERR, "UNPARSEABLE: {$file}\n");

        return [];
    }
    $out = [];
    $walk = function ($node) use (&$walk, &$out) {
        foreach ($node->testsuite as $ts) {
            $walk($ts);
        }
        foreach ($node->testcase as $tc) {
            $name = (string) $tc['class'].'::'.(string) $tc['name'];
            $status = 'pass';
            $msg = '';
            foreach (['failure', 'error'] as $kind) {
                if (isset($tc->{$kind})) {
                    $status = $kind;
                    $msg = trim((string) $tc->{$kind});
                    break;
                }
            }
            if (isset($tc->skipped)) {
                $status = 'skipped';
            }
            $out[$name] = ['status' => $status, 'msg' => $msg];
        }
    };
    $walk($xml);

    return $out;
}

$main = loadCases($mainFile);
$branch = loadCases($branchFile);

// Normalize messages: strip absolute paths + line numbers so that the same
// failure in a different worktree path is NOT reported as a "message change".
$normalize = function (string $msg): string {
    $msg = preg_replace('#C:\\\\[^\s:]+\\\\[^\\\\:\s]+#', '<PATH>', $msg);
    $msg = preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', '<TS>', $msg);
    $msg = preg_replace('/\s+/', ' ', $msg);

    return trim($msg);
};

foreach ($main as $k => $v) {
    $main[$k]['msg'] = $normalize($v['msg']);
}
foreach ($branch as $k => $v) {
    $branch[$k]['msg'] = $normalize($v['msg']);
}

printf("MAIN   cases: %d\n", count($main));
printf("BRANCH cases: %d\n\n", count($branch));

$stats = fn (array $c) => array_count_values(array_column($c, 'status'));

// 1. New failures (branch broken, main passed / absent)
$newFail = [];
foreach ($branch as $name => $info) {
    if (in_array($info['status'], ['failure', 'error'], true)) {
        $m = $main[$name] ?? null;
        if ($m === null) {
            $newFail[$name] = ['type' => 'NEW (test only on branch)', 'msg' => $info['msg']];
        } elseif (! in_array($m['status'], ['failure', 'error'], true)) {
            $newFail[$name] = ['type' => 'NEW FAILURE (regression)', 'msg' => $info['msg']];
        } elseif ($m['msg'] !== $info['msg']) {
            $newFail[$name] = ['type' => 'MESSAGE CHANGED', 'msg' => $info['msg'], 'main_msg' => $m['msg']];
        }
    }
}

// 2. Fixed (main broken, branch passed)
$fixed = [];
foreach ($main as $name => $info) {
    if (in_array($info['status'], ['failure', 'error'], true)) {
        $b = $branch[$name] ?? null;
        if ($b === null) {
            $fixed[$name] = 'REMOVED on branch';
        } elseif (! in_array($b['status'], ['failure', 'error'], true)) {
            $fixed[$name] = 'FIXED on branch';
        }
    }
}

// 3. Tests only on main (deleted on branch)
$onlyMain = array_diff_key($main, $branch);
// 4. Tests only on branch (added)
$onlyBranch = array_diff_key($branch, $main);

echo "═══ SUMMARY ═══\n";
echo 'main   : '.json_encode($stats($main))."\n";
echo 'branch : '.json_encode($stats($branch))."\n\n";

echo "═══ NEW FAILURES / MESSAGE CHANGES (".count($newFail).") ═══\n";
foreach ($newFail as $name => $info) {
    echo "[{$info['type']}] {$name}\n";
    echo '    '.mb_substr(str_replace("\n", ' | ', $info['msg']), 0, 260)."\n";
    if (isset($info['main_msg'])) {
        echo '    MAIN WAS: '.mb_substr(str_replace("\n", ' | ', $info['main_msg']), 0, 200)."\n";
    }
}

echo "\n═══ FIXED / REMOVED (".count($fixed).") ═══\n";
foreach ($fixed as $name => $info) {
    echo "[{$info}] {$name}\n";
}

echo "\n═══ ONLY ON MAIN (".count($onlyMain).") ═══\n";
foreach (array_keys($onlyMain) as $n) {
    echo "  {$n}\n";
}

echo "\n═══ ONLY ON BRANCH (".count($onlyBranch).") ═══\n";
foreach (array_keys($onlyBranch) as $n) {
    echo "  {$n}\n";
}
