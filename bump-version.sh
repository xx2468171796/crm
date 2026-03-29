#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

PACKAGE_JSON="$SCRIPT_DIR/desktop/package.json"
CARGO_TOML="$SCRIPT_DIR/desktop/src-tauri/Cargo.toml"
TAURI_CONF="$SCRIPT_DIR/desktop/src-tauri/tauri.conf.json"

# Read current version from package.json (source of truth for new version base)
CURRENT=$(grep -m1 '"version":' "$PACKAGE_JSON" | sed 's/.*"version": *"\([^"]*\)".*/\1/')

if [ -z "$CURRENT" ]; then
  echo "Error: could not read version from $PACKAGE_JSON" >&2
  exit 1
fi

# Determine new version
if [ $# -ge 1 ]; then
  NEW="$1"
else
  # Auto-increment patch from package.json version
  MAJOR=$(echo "$CURRENT" | cut -d. -f1)
  MINOR=$(echo "$CURRENT" | cut -d. -f2)
  PATCH=$(echo "$CURRENT" | cut -d. -f3)
  NEW="$MAJOR.$MINOR.$((PATCH + 1))"
fi

echo "Bumping version: $CURRENT -> $NEW"

# desktop/package.json  "version": "X.Y.Z"
PKG_CUR=$(grep -m1 '"version":' "$PACKAGE_JSON" | sed 's/.*"version": *"\([^"]*\)".*/\1/')
sed -i "0,/\"version\": \"$PKG_CUR\"/s/\"version\": \"$PKG_CUR\"/\"version\": \"$NEW\"/" "$PACKAGE_JSON"

# desktop/src-tauri/Cargo.toml  version = "X.Y.Z"
CARGO_CUR=$(grep -m1 '^version = ' "$CARGO_TOML" | sed 's/version = "\([^"]*\)"/\1/')
sed -i "0,/^version = \"$CARGO_CUR\"/s/^version = \"$CARGO_CUR\"/version = \"$NEW\"/" "$CARGO_TOML"

# desktop/src-tauri/tauri.conf.json  "version": "X.Y.Z"
TAURI_CUR=$(grep -m1 '"version":' "$TAURI_CONF" | sed 's/.*"version": *"\([^"]*\)".*/\1/')
sed -i "0,/\"version\": \"$TAURI_CUR\"/s/\"version\": \"$TAURI_CUR\"/\"version\": \"$NEW\"/" "$TAURI_CONF"

echo "Done."
echo "  desktop/package.json              $PKG_CUR -> $NEW"
echo "  desktop/src-tauri/Cargo.toml      $CARGO_CUR -> $NEW"
echo "  desktop/src-tauri/tauri.conf.json $TAURI_CUR -> $NEW"
