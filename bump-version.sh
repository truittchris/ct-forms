#!/usr/bin/env bash
set -euo pipefail

# Usage: ./bump-version.sh 1.2.3
NEWVER="${1:-}"
if [[ -z "$NEWVER" ]]; then
  echo "Usage: $0 <version>  (e.g. $0 1.0.34)"
  exit 1
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CTPHP="$ROOT_DIR/ct-forms.php"
README="$ROOT_DIR/README.txt"
CHANGELOG="$ROOT_DIR/CHANGELOG.md"

# Update ct-forms.php header and constant
perl -0777 -pe 's/(\*\s*Version:\s*)\d+\.\d+\.\d+/$1'"$NEWVER"'/g; s/(define\(\s*\x27CT_FORMS_VERSION\x27\s*,\s*\x27)\d+\.\d+\.\d+(\x27\s*\)\s*;)/$1'"$NEWVER"'$2/g' -i "$CTPHP"

# Update README.txt header (first line)
perl -0777 -pe 's/^CT Forms \(v\d+\.\d+\.\d+\)\s*$/CT Forms (v'"$NEWVER"')/m' -i "$README"

# Prepend changelog entry
TODAY="$(date +%Y-%m-%d)"
if ! grep -q "^## $NEWVER" "$CHANGELOG"; then
  tmpfile="$(mktemp)"
  awk -v ver="$NEWVER" -v today="$TODAY" '
    NR==1 { print; next }
    NR==2 {
      print ""
      print "## " ver " – " today
      print "- Version bump (automated)."
      print ""
      print
      next
    }
    { print }
  ' "$CHANGELOG" > "$tmpfile"
  mv "$tmpfile" "$CHANGELOG"
fi

echo "Bumped to $NEWVER"
if [[ -x "$ROOT_DIR/build-zip.sh" ]]; then
  "$ROOT_DIR/build-zip.sh"
else
  echo "build-zip.sh not found/executable. Run your packaging step manually."
fi
