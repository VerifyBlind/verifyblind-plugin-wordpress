#!/usr/bin/env bash
# Builds dist/verifyblind-<version>.zip from the committed tree. git archive honours .gitattributes export-ignore,
# so only runtime files ship; line endings are kept as committed (LF).
set -euo pipefail
cd "$(dirname "$0")/.."

version=$(sed -n 's/^ \* Version: *//p' verifyblind.php | tr -d '\r')
if [ -z "$version" ]; then
	echo "no Version: header in verifyblind.php" >&2
	exit 1
fi
if [ -n "$(git status --porcelain)" ]; then
	echo "commit your changes first: the zip is built from HEAD" >&2
	git status --short >&2
	exit 1
fi

mkdir -p dist
out="dist/verifyblind-$version.zip"
rm -f "$out"
git -c core.autocrlf=false archive --format=zip --prefix=verifyblind/ -o "$out" HEAD

# Only runtime files may ship.
bad=$(unzip -Z1 "$out" | grep -v -E '^verifyblind/($|verifyblind\.php$|uninstall\.php$|readme\.txt$|LICENSE$|includes/|assets/|languages/|vendor-prefixed/)' || true)
if [ -n "$bad" ]; then
	echo "unexpected files in $out:" >&2
	echo "$bad" >&2
	exit 1
fi
for f in verifyblind.php uninstall.php readme.txt LICENSE includes/Plugin.php includes/autoload.php vendor-prefixed/autoload.php languages/verifyblind-tr_TR.mo; do
	if ! unzip -Z1 "$out" | grep -qx "verifyblind/$f"; then
		echo "missing verifyblind/$f in $out" >&2
		exit 1
	fi
done
echo "$out"
