#!/usr/bin/env python3
"""The table `bin/parse-overrides.py` is checked against.

Every shape below is here because something read it wrong, or because a review
asked whether it did. Four rounds of review found four shapes the parser did not
handle, each one caught by hand and each fix keyed to the shapes somebody had
thought of. This file is the answer to that: a shape costs one row.

`/tmp` stands in for a directory that exists and `/nonexistent/*` for one that
does not, so no fixture tree is needed and the table stays readable.

Run: `python3 bin/parse-overrides-test.py` (also a `bin/check` job).
"""

import importlib.util
import sys
from pathlib import Path

# Loaded by path because the parser is `parse-overrides.py`, a hyphenated script
# rather than an importable module name.
_spec = importlib.util.spec_from_file_location(
    'parse_overrides', Path(__file__).resolve().parent / 'parse-overrides.py'
)
_parse_overrides = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_parse_overrides)
scan = _parse_overrides.scan

# name, yaml, expected findings, expected-unreadable
CASES: list[tuple[str, str, list[str], bool]] = [
    # --- the block form, at every indentation a hand-written file may use ---
    (
        'block, two-space, stale',
        'dependency_overrides:\n  magic:\n    path: /nonexistent/magic\n',
        ['magic -> /nonexistent/magic'],
        False,
    ),
    (
        'block, four-space, stale',
        'dependency_overrides:\n    magic:\n        path: /nonexistent/magic\n',
        ['magic -> /nonexistent/magic'],
        False,
    ),
    (
        'block, tab-indented, stale',
        'dependency_overrides:\n\tmagic:\n\t\tpath: /nonexistent/magic\n',
        ['magic -> /nonexistent/magic'],
        False,
    ),
    ('block, healthy', 'dependency_overrides:\n  magic:\n    path: /tmp\n', [], False),
    # --- the flow form, which AGENTS.md itself writes ---
    (
        'flow, stale',
        'dependency_overrides:\n  magic: {path: /nonexistent/magic}\n',
        ['magic -> /nonexistent/magic'],
        False,
    ),
    (
        'flow, second key alongside path',
        'dependency_overrides:\n  magic: {path: /nonexistent/m, hosted: x}\n',
        ['magic -> /nonexistent/m'],
        False,
    ),
    (
        'flow, quoted path value',
        'dependency_overrides:\n  magic: {path: "/nonexistent/q"}\n',
        ['magic -> /nonexistent/q'],
        False,
    ),
    ('flow, healthy', 'dependency_overrides:\n  magic: {path: /tmp}\n', [], False),
    (
        'whole block inline',
        'dependency_overrides: {magic: {path: /nonexistent/inline}}\n',
        ['magic -> /nonexistent/inline'],
        False,
    ),
    # --- a git override owns a `path:` that is a sub-directory, not a checkout ---
    (
        'git override, block form',
        'dependency_overrides:\n  magic:\n    git:\n      url: https://x\n      path: packages/magic\n',
        [],
        False,
    ),
    (
        'git override, flow form',
        'dependency_overrides:\n  magic: {git: {url: https://x, path: packages/magic}}\n',
        [],
        False,
    ),
    (
        'git override, flow, with a real path beside it',
        'dependency_overrides:\n  magic: {path: /nonexistent/real, git: {path: sub}}\n',
        ['magic -> /nonexistent/real'],
        False,
    ),
    # --- keys and values a human writes ---
    (
        'quoted entry key',
        'dependency_overrides:\n  "magic":\n    path: /nonexistent/q\n',
        ['magic -> /nonexistent/q'],
        False,
    ),
    (
        'trailing comment, block form',
        'dependency_overrides:\n  magic:\n    path: /tmp # local checkout\n',
        [],
        False,
    ),
    (
        'trailing comment, flow form',
        'dependency_overrides:\n  magic: {path: /tmp} # local\n',
        [],
        False,
    ),
    (
        'name with digits and underscores',
        'dependency_overrides:\n  magic_starter2:\n    path: /nonexistent/s\n',
        ['magic_starter2 -> /nonexistent/s'],
        False,
    ),
    (
        'single-line version, then a stale entry',
        'dependency_overrides:\n  file_picker: ^11.0.2\n  wind:\n    path: /nonexistent/wind\n',
        ['wind -> /nonexistent/wind'],
        False,
    ),
    (
        'sdk override alongside a stale path',
        'dependency_overrides:\n  flutter_test:\n    sdk: flutter\n  wind:\n    path: /nonexistent/w\n',
        ['wind -> /nonexistent/w'],
        False,
    ),
    (
        'comment lines and blank lines inside the block',
        'dependency_overrides:\n  # why this one is here\n\n  magic:\n    path: /nonexistent/c\n',
        ['magic -> /nonexistent/c'],
        False,
    ),
    # --- scope: only dependency_overrides is this guard's business ---
    (
        'a stale path under another top-level key is ignored',
        'dependency_overrides:\n  magic:\n    path: /tmp\ndependencies:\n  foo:\n    path: /nonexistent/foo\n',
        [],
        False,
    ),
    ('no dependency_overrides block at all', 'name: uptizm\nversion: 1.0.0\n', [], False),
    ('empty file', '', [], False),
    # --- two stale entries are both reported, not just the first ---
    (
        'two stale entries',
        'dependency_overrides:\n  magic:\n    path: /nonexistent/a\n  wind:\n    path: /nonexistent/b\n',
        ['magic -> /nonexistent/a', 'wind -> /nonexistent/b'],
        False,
    ),
    # --- a shape it cannot read is LOUD, never a silent pass ---
    ('list form', 'dependency_overrides:\n  - magic\n', [], True),
    (
        'multi-line flow map',
        'dependency_overrides:\n  magic: {path: /nonexistent/m,\n    hosted: x}\n',
        [],
        True,
    ),
]


def main() -> int:
    failures = []
    for name, yaml, expected, expect_unreadable in CASES:
        missing, reason = scan(yaml)
        if expect_unreadable:
            if reason is None:
                failures.append(f'{name}: expected an unreadable-shape failure, got none')
            continue
        if reason is not None:
            failures.append(f'{name}: unexpected failure: {reason}')
            continue
        if missing != expected:
            failures.append(f'{name}: expected {expected}, got {missing}')

    for line in failures:
        print(f'  FAIL {line}', file=sys.stderr)
    print(f'parse-overrides: {len(CASES) - len(failures)}/{len(CASES)} shapes pass')
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
