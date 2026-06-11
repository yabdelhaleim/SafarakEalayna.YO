import os
import re

# Directories to search
TARGET_DIRS = [
    'app',
    'resources/js'
]

# File extensions to scan
EXTENSIONS = ['.php', '.vue']

# Regular expression to identify string literals so we can ignore them
# We want to match double quotes, single quotes, and backticks (JS)
string_pattern = re.compile(r'(?:"(?:[^"\\]|\\.)*"|\'(?:[^\'\\]|\\.)*\'|`(?:[^`\\]|\\.)*`)')

# Regex for single line comments
inline_comment_pattern_php = re.compile(r'(?://.*|#.*)')
inline_comment_pattern_js = re.compile(r'(?://.*)')

def clean_line(line, is_php=True):
    # Strip inline comments
    if is_php:
        line = inline_comment_pattern_php.sub('', line)
    else:
        line = inline_comment_pattern_js.sub('', line)
    
    # Strip string literals to avoid matching math symbols inside strings
    line = string_pattern.sub('""', line)
    return line

def find_math_in_file(filepath):
    results = []
    is_php = filepath.endswith('.php')
    
    with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
        lines = f.readlines()
        
    in_multiline_comment = False
    
    for idx, line in enumerate(lines, start=1):
        original_line = line.strip()
        
        # Track multiline comments
        # A simple check: if line contains /* and not */, we enter
        # If we are inside and see */, we exit
        cleaned = line
        
        if in_multiline_comment:
            if '*/' in cleaned:
                # Part of the line after */ might be code
                parts = cleaned.split('*/', 1)
                cleaned = parts[1]
                in_multiline_comment = False
            else:
                continue
                
        if not in_multiline_comment:
            # Check if there is /* in the line
            if '/*' in cleaned:
                # If there's also */ on the same line, just remove the comment block
                while '/*' in cleaned and '*/' in cleaned:
                    start_idx = cleaned.find('/*')
                    end_idx = cleaned.find('*/') + 2
                    cleaned = cleaned[:start_idx] + cleaned[end_idx:]
                if '/*' in cleaned:
                    # Multiline comment starts and doesn't end on this line
                    parts = cleaned.split('/*', 1)
                    cleaned = parts[0]
                    in_multiline_comment = True
                    
        cleaned = clean_line(cleaned, is_php)
        
        # Now search for mathematical operations in the cleaned line
        # Exclude:
        # -> in PHP (object accessor)
        # => in PHP/JS (double arrow / arrow function)
        # ++ or -- (increment/decrement)
        # We look for binary operators: +, -, *, /
        # We also support +=, -=, *=, /=
        
        # Let's replace -> and => and ++ and -- with space so they aren't matched as operators
        cleaned_for_ops = cleaned.replace('->', '  ').replace('=>', '  ').replace('++', '  ').replace('--', '  ')
        
        # We want to identify:
        # Addition: + (but not in string or part of +=, or a standalone + sign for positive number like +5, unless it's addition)
        # Subtraction: - (but not in negative sign like -5, unless it's subtraction like a - b)
        # Multiplication: * (but not as part of docblock or import)
        # Division: / (but not in closing tag </tag> or html comment, or regex, or path)
        
        # Let's write rules to find valid math candidates
        # Rule 1: contains any of +, -, *, /
        found_ops = []
        
        # Let's do a search for operations with variables or numbers
        # e.g. $a + $b, price - discount, amount * rate, total / count, etc.
        # Let's inspect the line content directly. We want to be thorough but avoid noise.
        # In PHP, any + is addition (except array union or unary +).
        # In PHP, - is subtraction or unary minus.
        # In PHP, * is multiplication (excluding comments, which we removed).
        # In PHP, / is division (excluding comments/strings, which we removed).
        
        # To avoid noise, let's find if the line contains:
        # [variable/number] [+ - * /] [variable/number]
        # Or variable/number [+= -= *= /=] variable/number
        # Let's use regular expressions to look for typical math expressions:
        # e.g., $var + $other, $var - 5, 100 * $rate, $var / 2, etc.
        # Let's check for any arithmetic symbol that matches binary usage.
        
        # Let's check for:
        # 1. '+' when not prefix (unary) or suffix
        # 2. '-' when not prefix (unary) or suffix
        # 3. '*' when not part of comments (already cleaned)
        # 4. '/' when not part of tags or comments (already cleaned)
        
        # Let's extract any occurrence of the operators and output them for manual verification.
        # We will filter out obvious non-math things.
        
        # Let's write regex to find candidates:
        # Candidate patterns:
        # 1. Addition: something + something (excluding strings, ++, +=, which we handled)
        #    e.g. \w+\s*\+\s*\w+ or \$?\w+\s*\+\s*\$?\w+ or \$?\w+\s*\+=\s*\$?\w+
        # Let's check if the cleaned line has:
        # - Addition: + or +=
        # - Subtraction: - or -= (but exclude negative number initialization like `= -1` unless there's subtraction)
        # - Multiplication: * or *=
        # - Division: / or /=
        
        # Let's write a simple scanner:
        has_math = False
        
        # Subtraction: check for '-' not followed by > or = (unless -=), and check if it's subtraction.
        # In JS, check if there's `=>`.
        # Let's look for:
        # - Subtraction: `$x - $y`, `x - y`, `x -= y`, `$x -= $y`
        # Let's look for actual operators:
        # We search for:
        # \+ (addition)
        # \- (subtraction)
        # \* (multiplication)
        # \/ (division)
        
        # Let's exclude HTML closing tags in Vue: `</`
        if not is_php:
            cleaned_for_ops = cleaned_for_ops.replace('</', '  ')
            # Exclude Vue HTML comments `<!--` or `-->`
            cleaned_for_ops = cleaned_for_ops.replace('<!--', '    ').replace('-->', '   ')
            
        # Let's find matches and record them
        # We want to match:
        # a + b, a - b, a * b, a / b, or +=, -=, *=, /=
        # We can write a regex for binary operations:
        # (operand) (operator) (operand)
        # Operand: variable (with $ in PHP, or letters/digits/brackets/properties in JS)
        # Let's look for matches of:
        # [A-Za-z0-9_$\]\)]\s*(\+|\-|\*|\/)\s*[A-Za-z0-9_$]
        # or +=, -=, *=, /=
        # [A-Za-z0-9_$\]\)]\s*(\+=|\-=|\*=|\/=)\s*[A-Za-z0-9_$]
        
        # Let's define the regex:
        # Note: we also want to catch things like:
        # $var + 10, 10 - $var, $var * 1.5, $var / $count, etc.
        # Let's use a regex that matches:
        # (\b\w+|\)|\]|\$)\s*([\+\-\*\/]|=)\s*(\b\w+|\$|\()
        # Wait, if operator is '=', that's assignment, not math. So only match +, -, *, / or +=, -=, *=, /=
        
        # Let's write a regex that matches:
        # ([\w\)\$\]]+)\s*(\+|-|\*|\/|\+=|-=|\*=|\/=)\s*([\w\(\$]+)
        # We must be careful:
        # - in PHP, variables start with $
        # - - could be unary negative like `$x = -1;` which is matched if it's `$x = -1`. But `=` is not an operand.
        # - So the left side must be a variable, number, closing parenthesis, or closing bracket.
        # Let's check this regex:
        # r'([a-zA-Z0-9_\$\]\)]+)\s*(\+|-|\*|\/|\+=|-=|\*=|\/=)\s*([a-zA-Z0-9_\(\$]+)'
        
        # Let's search using this regex
        match = re.search(r'([a-zA-Z0-9_\$\]\)]+)\s*(\+|-|\*|\/|\+=|-=|\*=|\/=)\s*([a-zA-Z0-9_\(\$]+)', cleaned_for_ops)
        if match:
            # Let's get the matched operation
            op = match.group(2)
            left = match.group(1)
            right = match.group(3)
            
            # Additional checks to filter out non-math
            # e.g., in Vue templates, we might have things like class names with dashes: `class="flex-col"` -> wait, we stripped strings so that's not an issue.
            # What about CSS classes in style tags? Style tags are not in TARGET_DIRS (we scan resources/js, which contains .vue files. Vue files might have <style>).
            # If the file is .vue, let's ignore lines inside <style>...</style> block.
            # Let's track if we are in style block:
            
            # Let's filter out some common JS non-math additions (string concatenations):
            # e.g. url + '/path', or path + filename (usually strings).
            # But the script will output everything, and we can inspect or filter.
            # Let's write a check to filter out obvious string concat or imports:
            is_valid = True
            
            # Filter: if op is '+', and either side contains 'path', 'url', 'name', 'id', 'title', 'class', 'html', 'text', 'message', 'msg', 'email', 'slug', 'token', 'date', 'time', 'phone', 'ip', 'key', 'type', 'status', 'prefix' (typically string concats), we should flag it as possible string concat.
            # Let's keep it but label it.
            
            # Let's record the match
            results.append({
                'line_no': idx,
                'original': original_line,
                'cleaned': cleaned_for_ops.strip(),
                'match': match.group(0),
                'op': op
            })
            
    return results

def scan_all():
    all_results = {}
    for target in TARGET_DIRS:
        if not os.path.exists(target):
            continue
        for root, dirs, files in os.walk(target):
            # Exclude node_modules or vendor just in case
            if 'node_modules' in root or 'vendor' in root:
                continue
            for file in files:
                ext = os.path.splitext(file)[1]
                if ext in EXTENSIONS:
                    filepath = os.path.join(root, file)
                    filepath = filepath.replace('\\', '/')
                    file_results = find_math_in_file(filepath)
                    if file_results:
                        all_results[filepath] = file_results
                        
    return all_results

if __name__ == '__main__':
    import sys
    import json
    try:
        sys.stdout.reconfigure(encoding='utf-8')
    except AttributeError:
        pass # Not all environments support reconfigure
        
    results = scan_all()
    print(f"Scanned files. Found math candidates in {len(results)} files.")
    
    # Save results to JSON file
    with open('scratch/math_audit_raw.json', 'w', encoding='utf-8') as f:
        json.dump(results, f, ensure_ascii=False, indent=2)
        
    for file, ops in results.items():
        print(f"\n--- {file} ({len(ops)} candidates) ---")
        for op in ops[:10]: # limit preview
            print(f"  Line {op['line_no']}: {op['original']}  --> Matched: {op['match']}")
        if len(ops) > 10:
            print(f"  ... and {len(ops) - 10} more")
