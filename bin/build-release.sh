#!/usr/bin/env bash
# Build the release zip: production Composer install with an optimised, authoritative classmap,
# vendor/ included, development files excluded. Usage: bin/build-release.sh [output-dir]
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$ROOT/build}"
STAGE="$(mktemp -d)/normalized-fields"
mkdir -p "$STAGE" "$OUT"
rsync -a --exclude-from=- "$ROOT/" "$STAGE/" <<'LIST'
/.git
/.github
/.claude
/.wp-env.json
/bin
/build
/node_modules
/tests
/bench
/.gitignore
/.wp-env.override.json
/.phpunit.result.cache
/reports
/docs
/contracts
/PLAN.md
/DECISIONS.md
/CLAUDE.md
/phpcs.xml.dist
/phpstan.neon.dist
/phpunit.xml.dist
/package.json
/package-lock.json
/vendor
LIST
( cd "$STAGE" && composer install --no-dev --no-interaction --optimize-autoloader --classmap-authoritative )
VERSION="$(sed -n 's/^ \* Version: *//p' "$ROOT/normalized-fields.php")"
( cd "$(dirname "$STAGE")" && zip -qr "$OUT/normalized-fields-$VERSION.zip" normalized-fields )
echo "Built $OUT/normalized-fields-$VERSION.zip"
