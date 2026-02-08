import json
import re
import sys
import os

def flatten_json(y):
    out = {}
    def flatten(x, name=''):
        if type(x) is dict:
            for a in x:
                flatten(x[a], name + a + '.')
        elif type(x) is list:
            i = 0
            for a in x:
                flatten(a, name + str(i) + '.')
                i += 1
        else:
            out[name[:-1]] = x
    flatten(y)
    return out

en_json_path = '/Users/anilcan/StudioProjects/uptizm/assets/lang/en.json'
with open(en_json_path, 'r') as f:
    lang_data = json.load(f)
    valid_keys = flatten_json(lang_data)

results = {}

# Regex to find trans('key') or trans("key")
trans_re = re.compile(r"trans\((['\"])(.*?)\1")

# Use grep to get all occurrences with filename and line number
import subprocess
try:
    grep_output = subprocess.check_output(['grep', '-rn', 'trans(', 'lib/'], stderr=subprocess.STDOUT).decode('utf-8')
except subprocess.CalledProcessError as e:
    grep_output = e.output.decode('utf-8')

for line in grep_output.splitlines():
    if not line: continue
    
    # Format: filename:line:content
    parts = line.split(':', 2)
    if len(parts) < 3: continue
    
    file_path = os.path.abspath(parts[0])
    line_num = parts[1]
    code_part = parts[2].strip()
    
    if file_path not in results:
        results[file_path] = []
        
    matches = trans_re.findall(code_part)
    if matches:
        for quote, key in matches:
            exists = key in valid_keys
            results[file_path].append({
                'line': line_num,
                'key': key,
                'exists': exists,
                'code': code_part
            })
    else:
        # Check for dynamic trans() calls
        if 'trans(' in code_part:
            results[file_path].append({
                'line': line_num,
                'key': '[DYNAMIC or COMPLEX]',
                'exists': 'UNKNOWN',
                'code': code_part
            })

# Print report
print("# Translation Analysis Report\n")
missing_count = 0
total_calls = 0

for file_path in sorted(results.keys()):
    calls = results[file_path]
    if not calls: continue
    print(f"### {file_path}")
    print("| Line | Key | Status | Code |")
    print("|------|-----|--------|------|")
    for call in calls:
        total_calls += 1
        status = "✅ OK"
        if call['exists'] == False:
            status = "❌ MISSING"
            missing_count += 1
        elif call['exists'] == 'UNKNOWN':
            status = "⚠️ DYNAMIC"
            
        code = call['code'].replace('|', '\\|')
        print(f"| {call['line']} | `{call['key']}` | {status} | `{code}` |")
    print()

print(f"\n**Summary:**")
print(f"- Total trans() calls analyzed: {total_calls}")
print(f"- Missing keys: {missing_count}")
