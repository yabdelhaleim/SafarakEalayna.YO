import json

with open('scratch/math_audit_coverage.json', 'r', encoding='utf-8') as f:
    data = json.load(f)

total_ops = 0
tested_ops = 0

categories = {
    'Services (app/Services)': {'total': 0, 'tested': 0},
    'Controllers (app/Http)': {'total': 0, 'tested': 0},
    'Models (app/Models)': {'total': 0, 'tested': 0},
    'Other backend (app/Console, app/Support, etc.)': {'total': 0, 'tested': 0},
    'Frontend (Vue/JS)': {'total': 0, 'tested': 0}
}

untested_by_file = {}

for file, ops in data.items():
    cat = 'Other backend (app/Console, app/Support, etc.)'
    if 'app/Services/' in file:
        cat = 'Services (app/Services)'
    elif 'app/Http/' in file:
        cat = 'Controllers (app/Http)'
    elif 'app/Models/' in file:
        cat = 'Models (app/Models)'
    elif 'resources/js/' in file:
        cat = 'Frontend (Vue/JS)'
        
    for op in ops:
        total_ops += 1
        categories[cat]['total'] += 1
        
        # Determine if it has tests
        # For our heuristic, has_test is true if coverage_tests list is not empty
        if op['has_test']:
            tested_ops += 1
            categories[cat]['tested'] += 1
        else:
            if file not in untested_by_file:
                untested_by_file[file] = []
            untested_by_file[file].append(op)

print(f"Overall Coverage Statistics:")
print(f"  Total Math Operations Scanned: {total_ops}")
print(f"  Total Math Operations with Tests: {tested_ops}")
print(f"  Overall Coverage: {tested_ops / total_ops * 100:.2f}%" if total_ops > 0 else "N/A")

print("\nCoverage by Category:")
for cat, stats in categories.items():
    tot = stats['total']
    tst = stats['tested']
    pct = (tst / tot * 100) if tot > 0 else 0
    print(f"  {cat}: {tst} / {tot} ({pct:.2f}%)")

print("\nFiles with most untested math operations:")
sorted_untested = sorted(untested_by_file.items(), key=lambda x: len(x[1]), reverse=True)
for file, ops in sorted_untested[:15]:
    print(f"  {file}: {len(ops)} untested operations")
    for op in ops[:3]:
        print(f"    Line {op['line_no']}: {op['extracted']} | {op['equation']}")
    if len(ops) > 3:
        print(f"    ... and {len(ops) - 3} more")
