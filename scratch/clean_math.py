import os
import re
import json

TARGET_DIRS = ['app', 'resources/js']
EXTENSIONS = ['.php', '.vue']

KEYWORDS = {'if', 'else', 'return', 'throw', 'new', 'for', 'while', 'switch', 'case', 'break', 'continue', 'function', 'class', 'public', 'private', 'protected', 'static', 'var', 'let', 'const', 'typeof', 'instanceof', 'void', 'delete'}

# Regex to find script block in vue files
vue_script_pattern = re.compile(r'<script\b[^>]*>(.*?)</script>', re.DOTALL)
# Regex to find mustache templates
vue_mustache_pattern = re.compile(r'\{\{(.*?)\}\}', re.DOTALL)
# Regex to find vue attribute bindings
vue_attr_pattern = re.compile(r'\b(?:v-[a-z0-9\-:]+|:[a-z0-9\-]+|@[a-z0-9\-]+)="([^"]*)"')

def clean_php_content(content):
    # Replaces comments and strings in PHP content with spaces, preserving newlines
    n = len(content)
    out = list(content)
    
    i = 0
    in_single_comment = False
    in_multi_comment = False
    in_string = False
    string_char = None
    
    while i < n:
        char = content[i]
        
        if char == '\n':
            in_single_comment = False
            i += 1
            continue
            
        if in_multi_comment:
            if char == '*' and i + 1 < n and content[i+1] == '/':
                out[i] = ' '
                out[i+1] = ' '
                in_multi_comment = False
                i += 2
            else:
                out[i] = ' '
                i += 1
            continue
            
        if in_single_comment:
            out[i] = ' '
            i += 1
            continue
            
        if in_string:
            if char == '\\':
                out[i] = ' '
                if i + 1 < n:
                    out[i+1] = ' '
                i += 2
                continue
            if char == string_char:
                in_string = False
            out[i] = ' '
            i += 1
            continue
            
        # Check for comment start
        if char == '/' and i + 1 < n:
            next_char = content[i+1]
            if next_char == '/':
                out[i] = ' '
                out[i+1] = ' '
                in_single_comment = True
                i += 2
                continue
            elif next_char == '*':
                out[i] = ' '
                out[i+1] = ' '
                in_multi_comment = True
                i += 2
                continue
        elif char == '#' and (i == 0 or content[i-1] == '\n' or content[i-1].isspace()):
            out[i] = ' '
            in_single_comment = True
            i += 1
            continue
            
        # Check for string start
        if char in ("'", '"'):
            in_string = True
            string_char = char
            out[i] = ' '
            i += 1
            continue
            
        i += 1
        
    return "".join(out)

def clean_js_content(content):
    # Replaces comments and strings in JS content with spaces, preserving newlines
    n = len(content)
    out = list(content)
    
    i = 0
    in_single_comment = False
    in_multi_comment = False
    in_string = False
    string_char = None
    
    while i < n:
        char = content[i]
        
        if char == '\n':
            in_single_comment = False
            i += 1
            continue
            
        if in_multi_comment:
            if char == '*' and i + 1 < n and content[i+1] == '/':
                out[i] = ' '
                out[i+1] = ' '
                in_multi_comment = False
                i += 2
            else:
                out[i] = ' '
                i += 1
            continue
            
        if in_single_comment:
            out[i] = ' '
            i += 1
            continue
            
        if in_string:
            if char == '\\':
                out[i] = ' '
                if i + 1 < n:
                    out[i+1] = ' '
                i += 2
                continue
            if char == string_char:
                in_string = False
            out[i] = ' '
            i += 1
            continue
            
        # Check for comment start
        if char == '/' and i + 1 < n:
            next_char = content[i+1]
            if next_char == '/':
                out[i] = ' '
                out[i+1] = ' '
                in_single_comment = True
                i += 2
                continue
            elif next_char == '*':
                out[i] = ' '
                out[i+1] = ' '
                in_multi_comment = True
                i += 2
                continue
                
        # Check for string start
        if char in ("'", '"', '`'):
            in_string = True
            string_char = char
            out[i] = ' '
            i += 1
            continue
            
        i += 1
        
    return "".join(out)

def find_math_in_line(clean_line, original_line, line_no, context):
    results = []
    
    # Remove helpers that contain operators but aren't math
    # -> in PHP, => in PHP/JS
    # ++, -- in PHP/JS
    cleaned_no_arrows = clean_line.replace('->', '  ').replace('=>', '  ')
    cleaned_no_arrows = cleaned_no_arrows.replace('++', '  ').replace('--', '  ')
    
    # Scan for +, -, *, /
    n = len(cleaned_no_arrows)
    i = 0
    while i < n:
        op = None
        op_len = 1
        
        # Check for +=, -=, *=, /=
        if i + 1 < n and cleaned_no_arrows[i:i+2] in ('+=', '-=', '*=', '/='):
            op = cleaned_no_arrows[i:i+2]
            op_len = 2
        elif cleaned_no_arrows[i] in ('+', '-', '*', '/'):
            op = cleaned_no_arrows[i]
            op_len = 1
            
        if op:
            left_part = cleaned_no_arrows[:i].strip()
            right_part = cleaned_no_arrows[i+op_len:].strip()
            
            is_math = False
            
            if op_len == 2:
                # e.g., $x += $y
                # Left side must end with identifier, bracket, paren
                if left_part and re.search(r'[a-zA-Z0-9_\$\]\)]$', left_part):
                    is_math = True
            else:
                # Check binary usage: left and right operands exist
                left_match = re.search(r'([a-zA-Z0-9_\$\]\)])$', left_part)
                right_match = re.match(r'^([a-zA-Z0-9_\(\$]|\+|-)', right_part)
                
                if left_match and right_match:
                    left_word_match = re.search(r'\b([a-zA-Z0-9_\$]+)$', left_part)
                    if left_word_match:
                        left_word = left_word_match.group(1)
                        if left_word in KEYWORDS:
                            is_math = False
                        else:
                            is_math = True
                    else:
                        is_math = True
                        
            if is_math:
                # Extract snippet
                snippet_left = left_part[-15:] if len(left_part) > 15 else left_part
                snippet_right = right_part[:15] if len(right_part) > 15 else right_part
                extracted = f"{snippet_left} {op} {snippet_right}".strip()
                extracted = re.sub(r'\s+', ' ', extracted)
                
                results.append({
                    'line_no': line_no,
                    'equation': original_line.strip(),
                    'extracted': extracted,
                    'op': op,
                    'context': context
                })
                i += op_len
                continue
                
        i += 1
        
    return results

def process_php_file(filepath):
    with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
        content = f.read()
        
    clean_content = clean_php_content(content)
    
    original_lines = content.split('\n')
    clean_lines = clean_content.split('\n')
    
    results = []
    for idx, (clean_line, original_line) in enumerate(zip(clean_lines, original_lines), start=1):
        line_results = find_math_in_line(clean_line, original_line, idx, 'php')
        results.extend(line_results)
        
    return results

def process_vue_file(filepath):
    with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
        content = f.read()
        
    results = []
    
    # 1. Process <script> blocks
    for match in vue_script_pattern.finditer(content):
        script_content = match.group(1)
        start_pos = match.start(1)
        line_offset = content[:start_pos].count('\n') + 1
        
        # Clean script contents
        clean_script = clean_js_content(script_content)
        
        script_lines = script_content.split('\n')
        clean_lines = clean_script.split('\n')
        
        for idx, (clean_line, original_line) in enumerate(zip(clean_lines, script_lines)):
            line_no = line_offset + idx
            line_results = find_math_in_line(clean_line, original_line, line_no, 'vue-script')
            results.extend(line_results)
            
    # 2. Process mustache {{ ... }}
    for match in vue_mustache_pattern.finditer(content):
        expr = match.group(1)
        start_pos = match.start(1)
        line_no = content[:start_pos].count('\n') + 1
        
        clean_expr = clean_js_content(expr)
        # Scan mustache expr (usually a single line/block)
        line_results = find_math_in_line(clean_expr, expr, line_no, 'vue-mustache')
        results.extend(line_results)
        
    # 3. Process dynamic attributes
    for match in vue_attr_pattern.finditer(content):
        expr = match.group(1)
        start_pos = match.start(1)
        line_no = content[:start_pos].count('\n') + 1
        
        clean_expr = clean_js_content(expr)
        line_results = find_math_in_line(clean_expr, expr, line_no, 'vue-attr')
        results.extend(line_results)
        
    return results

def process_js_file(filepath):
    with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
        content = f.read()
        
    clean_content = clean_js_content(content)
    
    original_lines = content.split('\n')
    clean_lines = clean_content.split('\n')
    
    results = []
    for idx, (clean_line, original_line) in enumerate(zip(clean_lines, original_lines), start=1):
        line_results = find_math_in_line(clean_line, original_line, idx, 'js')
        results.extend(line_results)
        
    return results

def scan_all():
    all_results = {}
    
    # PHP files in app/
    for root, dirs, files in os.walk('app'):
        if 'node_modules' in root or 'vendor' in root:
            continue
        for file in files:
            if file.endswith('.php'):
                filepath = os.path.join(root, file).replace('\\', '/')
                file_results = process_php_file(filepath)
                if file_results:
                    all_results[filepath] = file_results
                    
    # Vue/JS files in resources/js/
    for root, dirs, files in os.walk('resources/js'):
        if 'node_modules' in root or 'vendor' in root:
            continue
        for file in files:
            filepath = os.path.join(root, file).replace('\\', '/')
            if file.endswith('.vue'):
                file_results = process_vue_file(filepath)
                if file_results:
                    all_results[filepath] = file_results
            elif file.endswith('.js'):
                file_results = process_js_file(filepath)
                if file_results:
                    all_results[filepath] = file_results
                    
    return all_results

if __name__ == '__main__':
    results = scan_all()
    print(f"Scanned files. Found clean math operations in {len(results)} files.")
    with open('scratch/math_audit_clean.json', 'w', encoding='utf-8') as f:
        json.dump(results, f, ensure_ascii=False, indent=2)
    
    total_ops = sum(len(ops) for ops in results.values())
    print(f"Total operations: {total_ops}")
