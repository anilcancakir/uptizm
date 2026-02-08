import json
import re
import os
import subprocess

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

trans_re = re.compile(r"trans\((['\"])(.*?)\1")

try:
    grep_output = subprocess.check_output(['grep', '-rn', 'trans(', 'lib/'], stderr=subprocess.STDOUT).decode('utf-8')
except subprocess.CalledProcessError as e:
    grep_output = e.output.decode('utf-8')

missing_by_file = {}

for line in grep_output.splitlines():
    if not line: continue
    parts = line.split(':', 2)
    if len(parts) < 3: continue
    
    file_path = os.path.abspath(parts[0])
    line_num = parts[1]
    code_part = parts[2].strip()
    
    if not file_path.endswith('.dart'): continue

    matches = trans_re.findall(code_part)
    for quote, key in matches:
        if key not in valid_keys:
            if file_path not in missing_by_file:
                missing_by_file[file_path] = []
            missing_by_file[file_path].append((line_num, key, code_part))

for file_path in sorted(missing_by_file.keys()):
    print(f"\n### {file_path}")
    for line_num, key, code in missing_by_file[file_path]:
        print(f"- Line {line_num}: `{key}` (Missing) -> `{code}`")
