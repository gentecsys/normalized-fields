#!/usr/bin/env bash
# Download Secure Custom Fields (free ACF fork incl. the repeater) for the differential baseline.
# Usage: bin/install-scf.sh [target-dir]   (default: ${TMPDIR:-/tmp}/nf-acf)
set -euo pipefail
DIR="${1:-${TMPDIR:-/tmp}/nf-acf}"
rm -rf "$DIR"
mkdir -p "$DIR"
curl -fsSL -o "$DIR/scf.zip" "https://downloads.wordpress.org/plugin/secure-custom-fields.latest-stable.zip"
unzip -q "$DIR/scf.zip" -d "$DIR"
rm "$DIR/scf.zip"
test -f "$DIR/secure-custom-fields/secure-custom-fields.php"
echo "SCF installed in $DIR/secure-custom-fields"
