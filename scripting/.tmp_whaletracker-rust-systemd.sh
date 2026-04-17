#!/usr/bin/env bash
set -euo pipefail

cd /home/kogasa/Whaletracker-Rust

BIN="/home/kogasa/Whaletracker-Rust/target/debug/whaletracker-rust"
LOG_DIR="${XDG_STATE_HOME:-$HOME/.local/state}/whaletracker-rust"

mkdir -p "$LOG_DIR"
chmod 700 "$LOG_DIR"

if [[ ! -x "$BIN" ]]; then
  echo "missing executable: $BIN" >&2
  exit 1
fi

pids=()

cleanup() {
  for pid in "${pids[@]:-}"; do
    kill "$pid" 2>/dev/null || true
  done

  for pid in "${pids[@]:-}"; do
    wait "$pid" 2>/dev/null || true
  done
}

trap cleanup EXIT INT TERM

env WT_RUST_BIND=127.0.0.1:28017 "$BIN" >>"$LOG_DIR/28017.log" 2>&1 &
pids+=("$!")

env WT_RUST_BIND=127.0.0.1:28018 "$BIN" >>"$LOG_DIR/28018.log" 2>&1 &
pids+=("$!")

wait -n "${pids[@]}"
exit $?
