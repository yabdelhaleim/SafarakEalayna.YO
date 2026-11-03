<?php
/**
 * QA audit helper (read-only): detect test files that extend a base class which
 * does not exist on disk (either same-namespace Tests\... or via a `use` import).
 * Such files abort the entire PHPUnit test collection.
 */
$root = dirname(__DIR__, 2);
chdir($root);

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('tests'));
$broken = [];
foreach ($rii as $f) {
    if (! $f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $p = $f->getPathname();
    $src = file_get_contents($p);

    // namespace
    $ns = '';
    if (preg_match('/^namespace\s+([^;]+);/m', $src, $m)) {
        $ns = trim($m[1]);
    }
    // imports
    $uses = [];
    if (preg_match_all('/^use\s+([\\\\\w]+)(?:\s+as\s+(\w+))?;/m', $src, $um, PREG_SET_ORDER)) {
        foreach ($um as $u) {
            $fq = $u[1];
            $alias = ! empty($u[2]) ? $u[2] : substr(strrchr('\\' . $fq, '\\'), 1);
            $uses[$alias] = $fq;
        }
    }
    if (! preg_match('/class\s+\w+\s+extends\s+([\\\\\w]+)/', $src, $m)) {
        continue;
    }
    $parent = $m[1];

    // Resolve to FQCN
    if (strpos($parent, '\\') !== false) {
        $fqcn = $parent;
    } elseif (isset($uses[$parent])) {
        $fqcn = $uses[$parent];
    } else {
        $fqcn = $ns . '\\' . $parent;
    }

    if (strpos($fqcn, 'Tests\\') !== 0) {
        continue;
    }
    $rel = substr($fqcn, 6);
    $cand = 'tests/' . str_replace('\\', '/', $rel) . '.php';
    if (! file_exists($cand)) {
        $broken[] = [$p, $fqcn, $cand];
    }
}

foreach ($broken as [$p, $parent, $cand]) {
    echo "BROKEN: $p -> $parent (missing $cand)\n";
}
echo 'TOTAL_BROKEN=' . count($broken) . "\n";
