#!/usr/bin/env python3
"""Parse phpunit failure output and extract (class::method) pairs."""
import re
import sys
import io

# Force utf-8 stdout
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='ignore')

path = sys.argv[1] if len(sys.argv) > 1 else r'/tmp/after_full.txt'
with open(path, 'r', encoding='utf-8', errors='ignore') as f:
    text = f.read()

# Strip ANSI escape sequences
ansi_re = re.compile(r'\x1B\[[0-9;]*[A-Za-z]')
text = ansi_re.sub('', text)

# Pattern: FAILED  Tests\Feature\XXX\YYY > method-name
# Examples:
#   FAILED  Tests\Feature\Finance\TourismTrialBalanceIntegrityTest > flight group receivable appears...
#   FAILED  Tests\Feature\FinancialReportTest > debts report correctly maps flight to touri… InvalidArgumentException
pat = re.compile(r'FAILED\s+Tests\\Feature\\([\w\\]+)\s+>\s+([\w\.\-]+)')
tests = set()
for m in pat.finditer(text):
    cls = m.group(1).split('\\')[0]
    method = m.group(2)
    tests.add(f'{cls}::{method}')

print(f'Total unique failed tests: {len(tests)}')
for t in sorted(tests):
    print(f'  - {t}')
