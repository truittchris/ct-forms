#!/usr/bin/env bash
set -euo pipefail

# Build a WordPress-installable zip from the repo contents.
# Usage:
#   ./build-zip.sh
#
# Output:
#   dist/ct-forms-vX.Y.Z.zip

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

PLUGIN_SLUG="ct-forms"
MAIN_FILE="$ROOT_DIR/ct-forms.php"

if [[ ! -f "$MAIN_FILE" ]]; then
  echo "ERROR: Cannot find $MAIN_FILE (run from the plugin root)." >&2
  exit 1
fi

VERSION="$(grep -E '^\s*\*\s*Version:' -m1 "$MAIN_FILE" | sed -E 's/.*Version:\s*//')"
if [[ -z "${VERSION}" ]]; then
  echo "ERROR: Could not determine Version from ct-forms.php header." >&2
  exit 1
fi

DIST_DIR="$ROOT_DIR/dist"
BUILD_DIR="$ROOT_DIR/.build"
ZIP_PATH="$DIST_DIR/${PLUGIN_SLUG}-v${VERSION}.zip"

rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR/$PLUGIN_SLUG" "$DIST_DIR"

# Copy plugin contents excluding common repo-only items
rsync -a --delete       --exclude ".git"       --exclude ".github"       --exclude "dist"       --exclude ".build"       --exclude "node_modules"       --exclude "*.zip"       --exclude ".DS_Store"       --exclude "Thumbs.db"       "$ROOT_DIR/" "$BUILD_DIR/$PLUGIN_SLUG/"

# Ensure executable bit in zip for this script is not required, but fine.
(cd "$BUILD_DIR" && zip -r -9 "$ZIP_PATH" "$PLUGIN_SLUG" >/dev/null)

rm -rf "$BUILD_DIR"
echo "Built: $ZIP_PATH"
