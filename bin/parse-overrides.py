#!/usr/bin/env python3
"""Report every `dependency_overrides` path entry that points nowhere.

Reads a `pubspec_overrides.yaml`, prints one `name -> path` line per override
whose `path:` is not a directory, and exits non-zero when it meets a shape it
cannot read. `bin/check` calls it; `bin/parse-overrides-test.py` is its table.

A separate file rather than a heredoc inside `bin/check` for one reason: it can
be tested. Four review rounds found four shapes this parser did not read (PyYAML
absent, flow form, four-space and tab indent, and a flow-form `git:` override),
each fix keyed to the shapes somebody had thought of, and each one finding the
next. Hand-exercising was the instrument every time, and the shapes nobody
thinks of are exactly the failure mode.

Two rules carry the whole thing:

1. Walk by INDENT LEVEL, not by fixed-width line shapes, so two-space,
   four-space and tab files all read the same.
2. Only a `path:` at the TOP level of a package entry counts. A `git:` override
   carries its own `path:` meaning a sub-directory inside the repository, and
   reporting that as a missing checkout fails the gate on a valid file.

Exit codes: 0 read it (any findings are on stdout), 2 could not open the file,
3 met a shape it cannot read.
"""

import os
import re
import sys

KEY = r'(?:"[^"]+"|\'[^\']+\'|[A-Za-z_][A-Za-z0-9_]*)'
ENTRY_RE = re.compile(rf'^({KEY})\s*:\s*(.*)$')


def strip_comment(text: str) -> str:
    """Drop a trailing ` # comment`, leaving a `#` inside quotes alone."""
    quote = None
    for index, char in enumerate(text):
        if quote:
            if char == quote:
                quote = None
        elif char in '"\'':
            quote = char
        elif char == '#' and (index == 0 or text[index - 1] in ' \t'):
            return text[:index]
    return text


def unquote(text: str) -> str:
    text = text.strip()
    if len(text) >= 2 and text[0] == text[-1] and text[0] in '"\'':
        return text[1:-1]
    return text


def split_flow(body: str) -> list[str]:
    """Split a flow mapping's body on its TOP-LEVEL commas.

    `{path: /a, git: {url: x, path: sub}}` yields two items, not four, which is
    what keeps a nested `git:` map from being read as if its keys were the
    entry's own.
    """
    items, depth, start, quote = [], 0, 0, None
    for index, char in enumerate(body):
        if quote:
            if char == quote:
                quote = None
        elif char in '"\'':
            quote = char
        elif char in '{[':
            depth += 1
        elif char in '}]':
            depth -= 1
        elif char == ',' and depth == 0:
            items.append(body[start:index])
            start = index + 1
    items.append(body[start:])
    return [item for item in items if item.strip()]


def flow_entries(value: str) -> list[tuple[str, str]] | None:
    """The top-level `key: value` pairs of a flow mapping, or None if malformed."""
    text = value.strip()
    if not (text.startswith('{') and text.endswith('}')):
        return None
    pairs = []
    for item in split_flow(text[1:-1]):
        matched = ENTRY_RE.match(item.strip())
        if not matched:
            return None
        pairs.append((unquote(matched.group(1)), matched.group(2).strip()))
    return pairs


def flow_path(value: str) -> str | None:
    """The entry's own `path:`, ignoring one nested inside a `git:` map."""
    pairs = flow_entries(value)
    if pairs is None:
        return None
    for key, inner in pairs:
        if key == 'path':
            return inner
    return None


def is_decorator(value: str) -> bool:
    """Whether [value] is only a YAML anchor or tag, so the real value follows."""
    return all(word.startswith(('&', '!')) for word in value.split())


def check(name: str, value: str, missing: list[str]) -> None:
    path = unquote(strip_comment(value).strip())
    if path and not os.path.isdir(path):
        missing.append(f'{name} -> {path}')


def scan(raw: str) -> tuple[list[str], str | None]:
    """Findings, plus the reason this file could not be read (None when it could)."""
    missing: list[str] = []
    stack: list[tuple[int, str]] = []

    for number, line in enumerate(raw.splitlines(), 1):
        expanded = line.expandtabs(2)
        if not expanded.strip() or expanded.lstrip().startswith('#'):
            continue

        indent = len(expanded) - len(expanded.lstrip(' '))
        body = strip_comment(expanded.strip()).strip()
        if not body:
            continue

        while stack and indent <= stack[-1][0]:
            stack.pop()
        path_of = [key for _, key in stack]

        matched = ENTRY_RE.match(body)
        if not matched:
            if path_of[:1] == ['dependency_overrides']:
                return missing, f'unrecognised dependency_overrides line {number}: {line.strip()}'
            continue

        key, value = unquote(matched.group(1)), matched.group(2).strip()

        # `dependency_overrides: {magic: {path: /x}}`, the whole block inline.
        if not path_of and key == 'dependency_overrides' and value.startswith('{'):
            pairs = flow_entries(value)
            if pairs is None:
                return missing, f'unrecognised dependency_overrides line {number}: {line.strip()}'
            for name, inner in pairs:
                own = flow_path(inner)
                if own is not None:
                    check(name, own, missing)
            continue

        # A package entry sits directly under dependency_overrides.
        if path_of == ['dependency_overrides']:
            if value.startswith('{'):
                own = flow_path(value)
                if own is None and flow_entries(value) is None:
                    return missing, f'unrecognised dependency_overrides line {number}: {line.strip()}'
                if own is not None:
                    check(key, own, missing)
                continue
            # An anchor or tag (`magic: &m`, `magic: !!map`) is not the entry's
            # value, it decorates the block child on the following lines. Push
            # the entry so that child's `path:` is still seen; without this the
            # child was swallowed and a stale path passed silently, which is the
            # one class this whole file exists to stop.
            if not value or is_decorator(value):
                stack.append((indent, key))
            continue

        # `path:` directly under a package entry, at whatever indent it uses.
        if len(path_of) == 2 and path_of[0] == 'dependency_overrides' and key == 'path' and value:
            check(path_of[1], value, missing)
            continue

        if not value:
            stack.append((indent, key))

    return missing, None


def main() -> int:
    target = sys.argv[1] if len(sys.argv) > 1 else 'pubspec_overrides.yaml'
    try:
        raw = open(target, encoding='utf-8').read()
    except OSError as exc:
        print(f'cannot read {target}: {exc}', file=sys.stderr)
        return 2

    missing, reason = scan(raw)
    for line in missing:
        print(line)
    if reason:
        print(reason, file=sys.stderr)
        return 3
    return 0


if __name__ == '__main__':
    sys.exit(main())
