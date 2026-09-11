#!/usr/bin/env python3
"""Apply the required local/Rust handoff guards to the pinned upstream helper.

Run after extracting this source overlay into a WhaleTracker checkout. This is
idempotent, verifies the original Git blob, and makes a backup before an atomic
replacement. It uses no network and never touches a database.
"""
from __future__ import annotations
import argparse
import hashlib
import os
from pathlib import Path
import sys

EXPECTED_BLOB = "2475a5d5209e3bbb70d2a089ed93a4b0b2be8157"
MARKER = "#define WT_CONCURRENCY_HELPERS 1"
PATCHES = (
    ("void PumpSaveQueue()\n{\n", "void PumpSaveQueue()\n{\n    if (!WhaleTracker_RustCanPumpLocal()) { return; }\n"),
    ("void FlushSaveQueueSync()\n{\n", "void FlushSaveQueueSync()\n{\n    if (!WhaleTracker_RustCanPumpLocal()) { return; }\n"),
    ("void RunSaveQuerySync(const char[] query, int userId)\n{\n",
     "void RunSaveQuerySync(const char[] query, int userId)\n{\n"
     "    if (!WhaleTracker_RustCanPumpLocal())\n    {\n"
     "        QueueLocalSaveQuery(query, userId, false);\n        return;\n    }\n"),
    ("void QueueLocalSaveQuery(const char[] query, int userId, bool forceSync = false)\n{\n    if (forceSync || g_bShuttingDown)",
     "void QueueLocalSaveQuery(const char[] query, int userId, bool forceSync = false)\n{\n"
     "    if ((forceSync || g_bShuttingDown) && WhaleTracker_RustCanPumpLocal())"),
    ("public Action WhaleTracker_PumpSaveQueueTimer(Handle timer, any data)\n{\n",
     "public Action WhaleTracker_PumpSaveQueueTimer(Handle timer, any data)\n{\n"
     "    if (timer != g_hSavePumpTimer) { return Plugin_Stop; }\n"),
    ("public Action WhaleTracker_ReconnectTimer(Handle timer, any data)\n{\n",
     "public Action WhaleTracker_ReconnectTimer(Handle timer, any data)\n{\n"
     "    if (timer != g_hReconnectTimer) { return Plugin_Stop; }\n"),
)

def git_blob(raw: bytes) -> str:
    return hashlib.sha1(f"blob {len(raw)}\0".encode() + raw).hexdigest()

def transform(raw: bytes, *, check_hash: bool = True) -> bytes:
    text = raw.decode("utf-8")
    if MARKER in text:
        if all(new in text for _, new in PATCHES):
            return raw
        raise ValueError("helper marker exists but its required guards are missing")
    if check_hash and git_blob(raw) != EXPECTED_BLOB:
        raise ValueError("helper differs from the pinned repository blob; refusing to overwrite local changes")
    for old, new in PATCHES:
        if text.count(old) != 1:
            raise ValueError(f"expected exactly one patch site: {old.splitlines()[0]}")
        text = text.replace(old, new, 1)
    return (MARKER + "\n" + text).encode("utf-8")

def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("checkout", nargs="?", type=Path, default=Path(__file__).resolve().parents[1])
    args = parser.parse_args()
    path = args.checkout / "scripting/include/whaletracker.inc"
    try:
        if path.is_symlink():
            raise ValueError("refusing a symbolic-link helper path")
        original = path.read_bytes()
        result = transform(original)
        if result == original:
            print("Required helper guards are already present.")
            return 0
        backup = path.with_name(path.name + ".before-concurrency-refactor")
        with backup.open("xb") as f:
            f.write(original)
            f.flush()
            os.fsync(f.fileno())
        temp = path.with_name(path.name + ".concurrency-tmp")
        try:
            with temp.open("xb") as f:
                f.write(result)
                f.flush()
                os.fsync(f.fileno())
            os.chmod(temp, path.stat().st_mode & 0o777)
            os.replace(temp, path)
        finally:
            temp.unlink(missing_ok=True)
        print(f"Patched {path}; original preserved as {backup.name}")
        print(f"Patched SHA-256: {hashlib.sha256(result).hexdigest()}")
        return 0
    except (OSError, UnicodeError, ValueError) as err:
        print(f"Not applied: {err}", file=sys.stderr)
        return 1

if __name__ == "__main__":
    raise SystemExit(main())
