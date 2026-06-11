import json

with open('scratch/math_audit_business.json', 'r', encoding='utf-8') as f:
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
        
        if op['has_test']:
            tested_ops += 1
            categories[cat]['tested'] += 1
        else:
            if file not in untested_by_file:
                untested_by_file[file] = []
            untested_by_file[file].append(op)

print(f"Overall Business Math Coverage:")
print(f"  Total Business Math Operations Scanned: {total_ops}")
print(f"  Total Scanned with Tests (Statically Linked): {tested_ops}")
print(f"  Overall Coverage Percentage: {tested_ops / total_ops * 100:.2f}%" if total_ops > 0 else "N/A")

print("\nCoverage by Category:")
for cat, stats in categories.items():
    tot = stats['total']
    tst = stats['tested']
    pct = (tst / tot * 100) if tot > 0 else 0
    print(f"  {cat}: {tst} / {tot} ({pct:.2f}%)")

print("\nUntested Business Math by File (app/Services only):")
services_untested = {k: v for k, v in untested_by_file.items() if 'app/Services/' in k}
sorted_services = sorted(services_untested.items(), key=lambda x: len(x[1]), reverse=True)
for file, ops in sorted_services:
    print(f"  {file}: {len(ops)} untested operations")
    for op in ops[:5]:
        print(f"    Line {op['line_no']}: {op['extracted']} | {op['equation']}")
    if len(ops) > 5:
        print(f"    ... and {len(ops) - 5} more")
