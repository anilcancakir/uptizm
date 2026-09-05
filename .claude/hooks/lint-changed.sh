#!/bin/sh
# Lint the file Claude just wrote, and report a failure instead of hiding it.
#
# `Backend (pint + tests, SQLite)` is one of the required checks on this repository, so a style
# violation that reaches a push costs a CI round. This reports it at the moment it is written.
#
# Why a script rather than a settings one-liner: a JSON-escaped command is where a malformed
# settings file comes from, and a malformed one is skipped silently in -p and CI runs.
#
# Why the linter call is not wrapped to discard stderr and swallow the exit code: a suppressed
# hook is indistinguishable from a hook that never fired.
#
# `set -e` is deliberately absent: the linter's nonzero exit is the case worth reporting, and
# -e would exit before the report is written. Every condition the hook cannot judge exits 0.

set -u

TOOL=pint            # baked in at construction time, never read from repository content
CHECK_ARGS='--test'  # the flag that makes pint REPORT rather than rewrite

command -v jq >/dev/null 2>&1 || exit 0
payload=$(cat) || exit 0
file=$(printf '%s' "$payload" | jq -r '.tool_input.file_path // empty') || exit 0
[ -n "$file" ] || exit 0

# file_path is absolute, but nothing promises `..` is collapsed or symlinks resolved. Resolve
# BOTH sides the same way before comparing: `cd` collapses `..` and `pwd -P` resolves symlinks.
root=$(cd "${CLAUDE_PROJECT_DIR:-.}" && pwd -P) || exit 0
dir=$(cd -- "$(dirname -- "$file")" && pwd -P) || exit 0
real="$dir/$(basename -- "$file")"

case "$real" in
    "$root"/*) ;;                                   # the trailing slash stops /root-evil matching
    *) exit 0 ;;
esac
case "$real" in
    */.git/*|*/.env|*/.env.*|*/vendor/*|*.pem|*.key|*id_rsa*) exit 0 ;;
esac
# Pint governs `backend/` only, and it is the only linter installed on disk here. Dart is left
# out on purpose: `flutter analyze` is a whole-project pass costing seconds per call, and the
# Dart language server already surfaces the same diagnostics without a hook.
case "$real" in
    *.php) ;;
    *) exit 0 ;;
esac

bin=''
for candidate in "backend/vendor/bin/$TOOL" "vendor/bin/$TOOL"; do
    if [ -x "$root/$candidate" ]; then bin="$root/$candidate"; break; fi
done
[ -n "$bin" ] || bin=$(command -v "$TOOL") || exit 0

# Run from `backend/`, which is where a `pint.json` would be read from if one is ever added.
# `--` is not passed: pint takes the path as a plain argument.
output=$(cd "$root/backend" && "$bin" $CHECK_ARGS "$real" 2>&1)
status=$?
[ "$status" -eq 0 ] && exit 0

printf '%s' "$output" | head -c 4000 | jq -Rs --arg f "$real" \
    '{hookSpecificOutput: {hookEventName: "PostToolUse",
      additionalContext: ("Pint failed on \($f):\n" + .)}}'
exit 0
