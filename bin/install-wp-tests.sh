#!/usr/bin/env bash
# Install the WordPress core test suite.
# Usage: install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
# wp-version: a number like 7.0, "latest", or "nightly".
set -euo pipefail

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-127.0.0.1}
WP_VERSION=${5-latest}

TMPDIR=${TMPDIR-/tmp}
TMPDIR=${TMPDIR%/}
WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress}

# Test library ref on GitHub's wordpress-develop mirror. Releases are tagged X.Y.Z there
# (SVN's tags/7.0 is the mirror's 7.0.0).
if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
	TESTS_REF='trunk'
	WP_ZIP='https://wordpress.org/nightly-builds/wordpress-latest.zip'
elif [[ $WP_VERSION == 'latest' ]]; then
	TESTS_REF='trunk'
	WP_ZIP='https://wordpress.org/latest.zip'
else
	if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
		TESTS_REF="$WP_VERSION.0"
	else
		TESTS_REF="$WP_VERSION"
	fi
	WP_ZIP="https://wordpress.org/wordpress-$WP_VERSION.zip"
fi

mkdir -p "$WP_CORE_DIR" "$WP_TESTS_DIR"
curl -fsSL "$WP_ZIP" -o "$TMPDIR/wordpress.zip"
unzip -q -o "$TMPDIR/wordpress.zip" -d "$TMPDIR/wp-extract"
cp -R "$TMPDIR/wp-extract/wordpress/." "$WP_CORE_DIR/"

# Fetched with git from GitHub (no svn, so CI needs no apt-get): shallow, blobs on demand,
# only the two test folders plus the root files.
DEVELOP="$TMPDIR/wordpress-develop"
rm -rf "$DEVELOP"
git clone --quiet --depth 1 --branch "$TESTS_REF" --filter=blob:none --sparse \
	https://github.com/WordPress/wordpress-develop.git "$DEVELOP"
git -C "$DEVELOP" sparse-checkout set tests/phpunit/includes tests/phpunit/data
rm -rf "$WP_TESTS_DIR/includes" "$WP_TESTS_DIR/data"
cp -R "$DEVELOP/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
cp -R "$DEVELOP/tests/phpunit/data" "$WP_TESTS_DIR/data"
cp "$DEVELOP/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
rm -rf "$DEVELOP"

sed -i "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s/youremptytestdbnamehere/$DB_NAME/; s/yourusernamehere/$DB_USER/; s/yourpasswordhere/$DB_PASS/; s|localhost|$DB_HOST|" "$WP_TESTS_DIR/wp-tests-config.php"
echo "WP $WP_VERSION test suite installed in $WP_TESTS_DIR"
