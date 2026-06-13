<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\Program;

echo "=== ALL EXECUTING COMPANIES ===\n";
$companies = HajjUmraExecutingCompany::withTrashed()->get();
foreach ($companies as $c) {
    echo "ID: {$c->id} | Name: '{$c->name}' | Active: " . ($c->is_active ? 'YES' : 'NO') . " | Deleted: " . ($c->deleted_at ? 'YES' : 'NO') . " | Account ID: " . ($c->account_id ?? 'NULL') . "\n";
}

echo "\n=== ALL PROGRAMS AND THEIR EXECUTING COMPANIES ===\n";
$programs = Program::with(['executingCompany'])->get();
foreach ($programs as $p) {
    echo "Prog ID: {$p->id} | Name: '{$p->program_name}' | Active: " . ($p->is_active ? 'YES' : 'NO') . " | Executing Co ID: " . ($p->executing_company_id ?? 'NULL') . " | Executing Co String: '{$p->executing_company}' | Relation Name: '" . ($p->executingCompany?->name ?? 'NONE') . "'\n";
}
