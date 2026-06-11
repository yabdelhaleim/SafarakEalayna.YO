import os
import re
import json

with open('scratch/math_audit_clean.json', 'r', encoding='utf-8') as f:
    data = json.load(f)

# Find all test files and read their contents to check for references
test_files = {}
for root, dirs, files in os.walk('tests'):
    for file in files:
        if file.endswith('.php'):
            filepath = os.path.join(root, file).replace('\\', '/')
            with open(filepath, 'r', encoding='utf-8', errors='ignore') as f_test:
                test_files[filepath] = f_test.read()

def get_class_and_method(filepath, line_no):
    # Reads the file up to line_no and finds the enclosing class and function
    if not os.path.exists(filepath):
        return None, None
        
    with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
        lines = f.readlines()
        
    class_name = None
    method_name = None
    
    # We inspect backwards from line_no (0-indexed, so line_no - 1)
    # 1. Enclosing class: scan forwards or backwards
    # Preceding class declaration:
    for idx in range(min(line_no, len(lines)) - 1, -1, -1):
        line = lines[idx].strip()
        class_match = re.search(r'\bclass\s+([a-zA-Z0-9_]+)', line)
        if class_match:
            class_name = class_match.group(1)
            break
            
    # Enclosing method:
    # We look for public/protected/private function name(
    # Also need to make sure we don't pick up an inner anonymous function if possible, but let's do a simple check
    brace_count = 0
    for idx in range(min(line_no, len(lines)) - 1, -1, -1):
        line = lines[idx].strip()
        # Look for function
        func_match = re.search(r'\bfunction\s+([a-zA-Z0-9_]+)', line)
        if func_match:
            method_name = func_match.group(1)
            break
            
    return class_name, method_name

audited_data = {}

for file, ops in data.items():
    if file.endswith('.php'):
        file_ops = []
        for op in ops:
            class_name, method_name = get_class_and_method(file, op['line_no'])
            
            # Now, check if this class and method are referenced in tests
            coverage_tests = []
            
            # Exclude filament files since they are usually not tested directly, or check if they are
            # If the class or method is tested, we check test files contents
            if class_name:
                for test_file, test_content in test_files.items():
                    # We check if test references the class name, and if it's a test for that feature
                    # e.g., if class is BusBookingService, check if test references it
                    class_ref = class_name in test_content
                    method_ref = (method_name in test_content) if method_name else False
                    
                    if class_ref and (method_ref or 'Service' not in class_name):
                        coverage_tests.append(test_file)
            
            op['class'] = class_name
            op['method'] = method_name
            op['coverage_tests'] = coverage_tests
            op['has_test'] = len(coverage_tests) > 0
            
            file_ops.append(op)
        audited_data[file] = file_ops
    else:
        # Vue/JS files
        file_ops = []
        for op in ops:
            # For JS/Vue files, let's look for test files that test the component or store
            # For example, if it's in resources/js/views/visa/VisaCreate.vue, is there a Dusk test?
            # Or general JS tests? Let's check.
            coverage_tests = []
            filename_base = os.path.basename(file).replace('.vue', '').replace('.js', '')
            
            for test_file, test_content in test_files.items():
                if filename_base in test_content:
                    coverage_tests.append(test_file)
                    
            op['class'] = None
            op['method'] = None
            op['coverage_tests'] = coverage_tests
            op['has_test'] = len(coverage_tests) > 0
            file_ops.append(op)
        audited_data[file] = file_ops

with open('scratch/math_audit_coverage.json', 'w', encoding='utf-8') as f:
    json.dump(audited_data, f, ensure_ascii=False, indent=2)

print("Done. Saved to scratch/math_audit_coverage.json")
