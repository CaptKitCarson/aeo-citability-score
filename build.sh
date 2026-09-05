#!/usr/bin/env bash
#
#   ./build.sh           full build, sold from kitmobley.com (free core + Pro)
#   ./build.sh --free    WordPress.org build
#
# The free build omits includes/pro/ entirely, along with the licence and updater
# machinery. Guideline 5 forbids shipping functionality that is restricted or
# locked behind payment, so the directory build contains no AI code at all rather
# than AI code behind an is_pro() check. The free plugin is a complete, ungated
# citability scorer; the Pro add-on attaches through hooks when present.
set -euo pipefail

FREE_BUILD=0
for arg in "$@"; do
	case "$arg" in
		--free) FREE_BUILD=1 ;;
		*) echo "Unknown option: $arg" >&2; exit 1 ;;
	esac
done
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

if [[ "$FREE_BUILD" == "1" ]]; then
	echo "Building WordPress.org (free) variant: omitting Pro, licence and updater."

	rm -rf "$STAGE/includes/pro"
	rm -rf "$STAGE/includes/vendor"
	rm -f  "$STAGE/includes/class-license.php"
	rm -f  "$STAGE/includes/class-updater.php"
	rm -f  "$STAGE/README.md" "$STAGE/.gitignore"

	# Nothing outside includes/pro may reference the Pro classes unguarded, or
	# the free build fatals the moment that path runs.
	if grep -rnE "AECS_LLM|AECS_Pro|new AECS_License|new AECS_Updater|PluginCore" "$STAGE" | grep -vE "class_exists|file_exists" | grep -q .; then
		echo "free build: unguarded Pro references survive in the stage:" >&2
		grep -rnE "AECS_LLM|AECS_Pro|new AECS_License|new AECS_Updater|PluginCore" "$STAGE" | grep -vE "class_exists|file_exists" >&2
		exit 1
	fi
	# And no AI feature may be present at all, gated or otherwise.
	if grep -rniE "aecs_ai_analyze|aecs_ai_rewrite|llm_api_key|Pro required" "$STAGE" --include='*.php' | grep -q .; then
		echo "free build: AI functionality still present in the stage:" >&2
		grep -rniE "aecs_ai_analyze|aecs_ai_rewrite|llm_api_key|Pro required" "$STAGE" --include='*.php' >&2
		exit 1
	fi
	if command -v php >/dev/null 2>&1; then
		while read -r f; do php -l "$f" >/dev/null || { echo "free build: $f is not valid PHP" >&2; exit 1; }; done < <(find "$STAGE" -name '*.php')
	fi

	ZIP="$DIST/$SLUG-free-$VERSION.zip"
else
	ZIP="$DIST/$SLUG-$VERSION.zip"
fi; rm -f "$ZIP"
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
if [[ "$FREE_BUILD" == "1" ]]; then
	:
else
	cp "$ZIP" "$DIST/$SLUG-latest.zip"
fi
echo "Built: $ZIP"; ls -lh "$DIST"/*.zip
