#!/usr/bin/env python3
"""
Compares DETACHMENTS in the current index.html against HEAD and prints
a comma-separated list of faction names whose detachments changed.
Used by the GitHub Actions refresh workflow.
"""
import re
import subprocess
import json
import sys


def extract(text):
    m = re.search(r'const DETACHMENTS=(\{.*?\});', text)
    return json.loads(m.group(1)) if m else {}


try:
    old = subprocess.check_output(['git', 'show', 'HEAD:index.html']).decode('utf-8')
except Exception:
    sys.exit(0)

with open('index.html', encoding='utf-8') as f:
    new = f.read()

old_d = extract(old)
new_d = extract(new)
changed = sorted(f for f in set(list(old_d) + list(new_d)) if old_d.get(f) != new_d.get(f))
if changed:
    print(', '.join(changed))
