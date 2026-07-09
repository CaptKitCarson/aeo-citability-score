#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
SLUG="aeo-citability-score"
VERSION="$(grep -oE "Version:\s+[0-9]+\.[0-9]+\.[0-9]+" "$ROOT/$SLUG.php" | awk '{print $2}')"
[[ -z "${VERSION:-}" ]] && { echo "Cannot detect version"; exit 1; }

CORE_SRC="${WP_PLUGIN_CORE:-$HOME/Projects/wp-plugin-core}"
CORE_DEST="$ROOT/includes/vendor/kitmobley-core"
if [[ -d "$CORE_SRC/src" ]]; then
	mkdir -p "$CORE_DEST/src"
	rsync -a --delete "$CORE_SRC/src/" "$CORE_DEST/src/"
	cp "$CORE_SRC/LICENSE.txt" "$CORE_DEST/LICENSE.txt" 2>/dev/null || true
	echo "Synced wp-plugin-core from $CORE_SRC"
fi

STAGE="$ROOT/dist/build/$SLUG"; DIST="$ROOT/dist"
rm -rf "$STAGE" && mkdir -p "$STAGE"
rsync -a --exclude='dist' --exclude='.git' --exclude='node_modules' \
	--exclude='build.sh' --exclude='*.log' --exclude='.DS_Store' \
	--exclude='tests' --exclude='.editorconfig' "$ROOT/" "$STAGE/"

ZIP="$DIST/$SLUG-$VERSION.zip"; rm -f "$ZIP"
if command -v zip >/dev/null 2>&1; then ( cd "$DIST/build" && zip -qr "$ZIP" "$SLUG" ); else
	python3 -c "
import os, zipfile
root = os.path.join('$DIST','build'); slug='$SLUG'; out='$ZIP'
with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED) as z:
	base = os.path.join(root, slug)
	for dp,_,fns in os.walk(base):
		for fn in fns: z.write(os.path.join(dp,fn), os.path.relpath(os.path.join(dp,fn), root))
"
fi
cp "$ZIP" "$DIST/$SLUG-latest.zip"
echo "Built: $ZIP"; ls -lh "$DIST"/*.zip
