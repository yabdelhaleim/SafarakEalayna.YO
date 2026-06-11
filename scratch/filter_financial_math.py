import json
import re

with open('scratch/math_audit_coverage.json', 'r', encoding='utf-8') as f:
    data = json.load(f)

# Keywords indicating business/financial context
BUSINESS_KEYWORDS = [
    'price', 'cost', 'fee', 'tax', 'discount', 'amount', 'balance', 'profit', 'margin', 
    'commission', 'comm', 'debit', 'credit', 'total', 'rate', 'quantity', 'qty', 'sum', 
    'penalty', 'refund', 'charge', 'purchase', 'selling', 'revenue', 'expense', 'liquidity',
    'fawry_price', 'unit_price', 'subtotal', 'debt', 'remaining', 'paid', 'currency'
]

# We want to ignore generic loop variables like $i+1 or pagination math like page - 1 or layout variables
IGNORE_PATTERNS = [
    r'\bpage\b', r'\bper_page\b', r'\bperPage\b', r'\bi\b', r'\bj\b', r'\bidx\b', r'\bindex\b', 
    r'\bwidth\b', r'\bheight\b', r'\btop\b', r'\bleft\b', r'\bradius\b', r'\bcircumference\b',
    r'\bprogress\b', r'\boffset\b', r'\bMath\.PI\b', r'\bpercent\b', r'\bopacity\b', r'\bpadding\b',
    r'\bmargin\b\s*[+\-*/]\s*\d', # e.g. margin-top (though usually stripped, just in case)
    r'\bkey\b', r'\bday\b', r'\bmonth\b', r'\byear\b', r'\bhour\b', r'\bminute\b', r'\bsecond\b',
    r'\bdate\b', r'\btime\b'
]

filtered_data = {}
total_business_ops = 0

for file, ops in data.items():
    # Only keep files in app/ (PHP) or resources/js/ (Vue/JS)
    if not (file.startswith('app/') or file.startswith('resources/js/')):
        continue
        
    # Exclude tests files, migration files, seeders (should already be excluded, but let's be safe)
    if 'tests/' in file or 'database/' in file:
        continue
        
    filtered_ops = []
    for op in ops:
        eq_lower = op['equation'].lower()
        extracted_lower = op['extracted'].lower()
        
        # Check if it has any business keywords
        has_business_kw = any(kw in eq_lower or kw in extracted_lower for kw in BUSINESS_KEYWORDS)
        
        # Check if it matches any ignore patterns
        matches_ignore = any(re.search(pat, op['equation']) or re.search(pat, op['extracted']) for pat in IGNORE_PATTERNS)
        
        # In Vue/JS files, sometimes + is used for class names or simple string concat
        # We want to make sure it's mathematical
        is_string_concat = False
        if not file.endswith('.php'):
            # If op is + and it has quotes or looks like string concat, exclude
            if op['op'] == '+' and ("'" in op['equation'] or '"' in op['equation'] or '`' in op['equation']):
                is_string_concat = True
                
        if has_business_kw and not matches_ignore and not is_string_concat:
            filtered_ops.append(op)
            total_business_ops += 1
            
    if filtered_ops:
        filtered_data[file] = filtered_ops

with open('scratch/math_audit_business.json', 'w', encoding='utf-8') as f:
    json.dump(filtered_data, f, ensure_ascii=False, indent=2)

print(f"Filtered to business math. Found {total_business_ops} operations in {len(filtered_data)} files.")
