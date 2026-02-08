#!/usr/bin/env python3
"""
Translation Scanner - Implements the algorithm from scan-translations skill
"""

import json
import re
import os
from pathlib import Path
from collections import defaultdict


def flatten_json(data, parent_key="", sep="."):
    """Flatten nested JSON to dot notation keys."""
    items = []
    for k, v in data.items():
        new_key = f"{parent_key}{sep}{k}" if parent_key else k
        if isinstance(v, dict):
            items.extend(flatten_json(v, new_key, sep=sep).items())
        else:
            items.append((new_key, v))
    return dict(items)


def load_valid_keys():
    """Load and flatten translation keys from en.json."""
    with open("assets/lang/en.json", "r", encoding="utf-8") as f:
        translations = json.load(f)
    return set(flatten_json(translations).keys())


def extract_trans_calls():
    """Find all trans() calls in lib/ directory."""
    pattern = re.compile(r"trans\(['\"]([^'\"]+)['\"]\)")
    trans_calls = []

    for dart_file in Path("lib").rglob("*.dart"):
        with open(dart_file, "r", encoding="utf-8") as f:
            for line_num, line in enumerate(f, 1):
                stripped = line.strip()
                if stripped.startswith("//") or stripped.startswith("///"):
                    continue

                for match in pattern.finditer(line):
                    key = match.group(1)
                    trans_calls.append(
                        {
                            "file": str(dart_file),
                            "line": line_num,
                            "key": key,
                            "code": line.strip(),
                        }
                    )

    return trans_calls


def check_broken_keys(trans_calls, valid_keys):
    """Cross-reference trans() calls against valid keys."""
    broken = []
    for call in trans_calls:
        if call["key"] not in valid_keys:
            broken.append(call)
    return broken


def main():
    print("=" * 80)
    print("TRANSLATION SCAN REPORT")
    print("=" * 80)
    print()

    # Step 1: Load valid keys
    print("Step 1: Loading valid translation keys from en.json...")
    valid_keys = load_valid_keys()
    print(f"  ✓ Loaded {len(valid_keys)} valid translation keys")
    print()

    # Step 2: Extract trans() calls
    print("Step 2: Scanning lib/ for trans() calls...")
    trans_calls = extract_trans_calls()
    print(f"  ✓ Found {len(trans_calls)} trans() calls")
    print()

    # Step 3: Check for broken keys
    print("Step 3: Cross-referencing keys...")
    broken_keys = check_broken_keys(trans_calls, valid_keys)
    print()

    # Report
    print("=" * 80)
    print("SUMMARY")
    print("=" * 80)
    print(f"Valid translation keys: {len(valid_keys)}")
    print(f"Total trans() calls: {len(trans_calls)}")
    print(f"Broken trans() keys: {len(broken_keys)}")
    print()

    if broken_keys:
        print("=" * 80)
        print("CRITICAL ISSUES - BROKEN TRANS() KEYS")
        print("=" * 80)

        by_file = defaultdict(list)
        for broken in broken_keys:
            by_file[broken["file"]].append(broken)

        for file, issues in sorted(by_file.items()):
            print(f"\n{file}:")
            for issue in issues:
                print(
                    f"  Line {issue['line']}: trans('{issue['key']}') - KEY NOT FOUND"
                )
                print(f"    Code: {issue['code']}")

        print()
        print("=" * 80)
        print(f"❌ SCAN FAILED: {len(broken_keys)} broken translation key(s)")
        print("=" * 80)
        return 1
    else:
        print("=" * 80)
        print("✅ ALL CLEAR")
        print("=" * 80)
        print()
        print("No broken translation keys found.")
        print("All trans() calls reference valid entries in assets/lang/en.json")
        print()
        print("=" * 80)
        return 0


if __name__ == "__main__":
    exit(main())
