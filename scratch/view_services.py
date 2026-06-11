import json
import sys

try:
    sys.stdout.reconfigure(encoding='utf-8')
except AttributeError:
    pass

with open('scratch/math_audit_clean.json', 'r', encoding='utf-8') as f:
    data = json.load(f)

services_ops = {k: v for k, v in data.items() if 'app/Services/' in k}
print(f"Number of files in app/Services with math: {len(services_ops)}")

for file in list(services_ops.keys())[:10]:
    print(f"\nFile: {file} ({len(services_ops[file])} operations)")
    for op in services_ops[file][:5]:
        print(f"  Line {op['line_no']}: {op['extracted']} | {op['equation']}")
    if len(services_ops[file]) > 5:
        print(f"  ... and {len(services_ops[file]) - 5} more")
