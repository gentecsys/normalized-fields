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

if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
	TESTS_TAG='trunk'
	WP_ZIP='https://wordpress.org/nightly-builds/wordpress-latest.zip'
elif [[ $WP_VERSION == 'latest' ]]; then
	TESTS_TAG='trunk'
	WP_ZIP='https://wordpress.org/latest.zip'
else
	TESTS_TAG="tags/$WP_VERSION"
	WP_ZIP="https://wordpress.org/wordpress-$WP_VERSION.zip"
fi

mkdir -p "$WP_CORE_DIR" "$WP_TESTS_DIR"
curl -fsSL "$WP_ZIP" -o "$TMPDIR/wordpress.zip"
unzip -q -o "$TMPDIR/wordpress.zip" -d "$TMPDIR/wp-extract"
cp -R "$TMPDIR/wp-extract/wordpress/." "$WP_CORE_DIR/"

svn export --quiet --force "https://develop.svn.wordpress.org/$TESTS_TAG/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
svn export --quiet --force "https://develop.svn.wordpress.org/$TESTS_TAG/tests/phpunit/data/" "$WP_TESTS_DIR/data"
svn export --quiet --force "https://develop.svn.wordpress.org/$TESTS_TAG/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"

sed -i "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s/youremptytestdbnamehere/$DB_NAME/; s/yourusernamehere/$DB_USER/; s/yourpasswordhere/$DB_PASS/; s|localhost|$DB_HOST|" "$WP_TESTS_DIR/wp-tests-config.php"
echo "WP $WP_VERSION test suite installed in $WP_TESTS_DIR"
