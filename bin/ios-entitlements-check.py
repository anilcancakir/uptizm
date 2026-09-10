#!/usr/bin/env python3
"""Hold the two iOS entitlements files and their build configurations in step.

The Runner target signs Debug and Profile against `Runner.entitlements` and
Release against `RunnerRelease.entitlements`. The split is forced by Apple: a
development provisioning profile carries `aps-environment: development` and a
distribution one carries `production`, and signing against the wrong value
fails at export. What the split costs is drift. Xcode's Signing and
Capabilities tab writes to the file of whichever configuration is selected,
which is Debug by default, so an entitlement added there reaches every build
except the one that ships, and nothing anywhere reports it.

Two things are checked, and the second is the reason the first can be trusted:
every key except `aps-environment` matches between the files, and each build
configuration still points at the file it is supposed to. The pbxproj is
walked structurally, target to configuration list to configuration, so moving
a build setting around cannot make this pass by accident and neither can the
RunnerTests target, which has build settings of its own and signs nothing.

Exits non-zero with one line per problem. Writes nothing. Pure Python with no
`plutil`, because this also runs on the Linux CI runner.
"""

from __future__ import annotations

import plistlib
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
IOS = ROOT / "ios"
PBXPROJ = IOS / "Runner.xcodeproj" / "project.pbxproj"

# Configuration -> the entitlements path its build settings must carry,
# relative to the ios/ directory the way the pbxproj spells it.
EXPECTED_PAIRING = {
    "Debug": "Runner/Runner.entitlements",
    "Profile": "Runner/Runner.entitlements",
    "Release": "Runner/RunnerRelease.entitlements",
}

# The one key the two files are MEANT to disagree on, and what each must say.
APS_KEY = "aps-environment"
EXPECTED_APS = {
    "Runner/Runner.entitlements": "development",
    "Runner/RunnerRelease.entitlements": "production",
}


# One top-level object in the `objects` dictionary: a 24-hex id, an optional
# /* comment */, then a brace-delimited body at exactly two tabs of indent.
# The pbxproj is machine-written by Xcode and that indentation is part of the
# format it emits, which is what makes this tractable without a full OpenStep
# parser.
OBJECT = re.compile(
    r"^\t\t([0-9A-F]{24})(?: /\* .*? \*/)? = \{(.*?)^\t\t\};$",
    re.DOTALL | re.MULTILINE,
)


def read_objects() -> dict[str, str]:
    """Return each pbxproj object id mapped to its raw body text."""
    return {
        match.group(1): match.group(2)
        for match in OBJECT.finditer(PBXPROJ.read_text())
    }


def setting(body: str, key: str) -> str | None:
    """Read one `key = value;` out of an object body, unquoted."""
    match = re.search(rf"^\s*{re.escape(key)} = (.+?);$", body, re.MULTILINE)
    if match is None:
        return None
    return match.group(1).strip().strip('"')


def runner_configurations() -> dict[str, str]:
    """Map each Runner configuration name to its CODE_SIGN_ENTITLEMENTS value.

    Walked rather than searched: the RunnerTests target and the project-level
    configuration list both hold configurations called Debug and Release, and
    neither of them signs the app.
    """
    objects = read_objects()

    targets = [
        body
        for body in objects.values()
        if setting(body, "isa") == "PBXNativeTarget"
        and setting(body, "name") == "Runner"
    ]
    if len(targets) != 1:
        raise SystemExit(
            f"expected exactly one PBXNativeTarget named Runner, found {len(targets)}; "
            "the pbxproj shape this check reads has changed"
        )

    # `buildConfigurationList = <id> /* Build configuration list for ... */;`
    reference = setting(targets[0], "buildConfigurationList")
    list_id = reference.split()[0] if reference else None
    if list_id not in objects:
        raise SystemExit(
            f"could not resolve the Runner target's configuration list from {reference!r}"
        )

    ids = re.findall(r"([0-9A-F]{24}) /\* (\w+) \*/,", objects[list_id])
    if not ids:
        raise SystemExit("the Runner configuration list holds no configurations")

    return {
        name: setting(objects[cid], "CODE_SIGN_ENTITLEMENTS") for cid, name in ids
    }


def main() -> int:
    problems: list[str] = []

    configurations = runner_configurations()

    for name, expected in EXPECTED_PAIRING.items():
        actual = configurations.get(name)
        if actual != expected:
            problems.append(
                f"Runner {name} signs against {actual!r}, expected {expected!r}"
            )

    files = {}
    for relative, expected_aps in EXPECTED_APS.items():
        path = IOS / relative
        if not path.exists():
            problems.append(f"{relative} is missing")
            continue
        files[relative] = plistlib.loads(path.read_bytes())
        actual_aps = files[relative].get(APS_KEY)
        if actual_aps != expected_aps:
            problems.append(
                f"{relative} declares {APS_KEY} {actual_aps!r}, expected {expected_aps!r}"
            )

    if len(files) == 2:
        debug, release = (files[relative] for relative in EXPECTED_APS)
        shared_debug = {k: v for k, v in debug.items() if k != APS_KEY}
        shared_release = {k: v for k, v in release.items() if k != APS_KEY}

        for key in sorted(set(shared_debug) | set(shared_release)):
            if key not in shared_release:
                problems.append(
                    f"{key} is in Runner.entitlements but not RunnerRelease.entitlements, "
                    "so the shipped app does not have it"
                )
            elif key not in shared_debug:
                problems.append(
                    f"{key} is in RunnerRelease.entitlements but not Runner.entitlements, "
                    "so no debug build exercises it"
                )
            elif shared_debug[key] != shared_release[key]:
                problems.append(
                    f"{key} differs: {shared_debug[key]!r} in Runner.entitlements, "
                    f"{shared_release[key]!r} in RunnerRelease.entitlements"
                )

    if problems:
        for problem in problems:
            print(problem, file=sys.stderr)
        return 1

    print("ios-entitlements: debug and release in step")
    return 0


if __name__ == "__main__":
    sys.exit(main())
