<?php
/**
 * Finds code that reads wp_postmeta with raw SQL (WP 2.6c2).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Migration;

/**
 * Raw SQL against `wp_postmeta` cannot be intercepted (design doc, "Migration"), so the dry-run report lists
 * where it appears. Pure on strings; `scanDirectory()` only reads files.
 */
final class RawSqlScanner {

	/**
	 * Matches `$wpdb->postmeta` (also inside `{...}`) and a SQL verb followed by a `postmeta` table name.
	 */
	private const PATTERN = '/\$wpdb\s*->\s*postmeta\b|\b(?:SELECT|INSERT|UPDATE|DELETE|JOIN|FROM)\b[^\n]*\b\w*postmeta\b/i';

	/**
	 * Scan one source string.
	 *
	 * @param string $code Source code.
	 * @return int[] Line numbers with a hit.
	 */
	public function scanSource( string $code ): array {
		$hits = array();
		foreach ( preg_split( '/\R/', $code ) ?: array() as $i => $line ) {
			if ( 1 === preg_match( self::PATTERN, $line ) ) {
				$hits[] = $i + 1;
			}
		}
		return $hits;
	}

	/**
	 * Scan the PHP files under a directory, skipping `vendor`, `node_modules` and NF itself.
	 *
	 * @param string $dir      Directory.
	 * @param int    $max_size Largest file to read, in bytes.
	 * @return array<string,int[]> Relative path => hit lines.
	 */
	public function scanDirectory( string $dir, int $max_size = 1048576 ): array {
		$out = array();
		if ( ! is_dir( $dir ) ) {
			return $out;
		}
		$filter = new \RecursiveCallbackFilterIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			static function ( \SplFileInfo $f ): bool {
				return ! $f->isDir() || ! in_array( $f->getFilename(), array( 'vendor', 'node_modules', 'normalized-fields', '.git' ), true );
			}
		);
		foreach ( new \RecursiveIteratorIterator( $filter ) as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== strtolower( $file->getExtension() ) || $file->getSize() > $max_size ) {
				continue;
			}
			$code = file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $code ) {
				continue;
			}
			$hits = $this->scanSource( $code );
			if ( array() !== $hits ) {
				$out[ ltrim( substr( $file->getPathname(), strlen( $dir ) ), '/\\' ) ] = $hits;
			}
		}
		ksort( $out );
		return $out;
	}
}
