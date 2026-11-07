<?php
/**
 * MySQL backend adapter over $wpdb.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Backend;

defined( 'ABSPATH' ) || exit;

/**
 * The one place NF touches $wpdb for NF tables (ADR-0009). The prefix is read on each call so switch_blog works (ADR-0011).
 */
final class MysqlBackend implements BackendInterface {

	/**
	 * Dialect.
	 *
	 * @var MysqlDialect
	 */
	private MysqlDialect $dialect;

	/**
	 * Open transaction depth.
	 *
	 * @var int
	 */
	private int $depth = 0;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->dialect = new MysqlDialect();
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'mysql';
	}

	/**
	 * Dialect.
	 *
	 * @return DialectInterface
	 */
	public function dialect(): DialectInterface {
		return $this->dialect;
	}

	/**
	 * Physical table name for the current site.
	 *
	 * @param string $logical Logical name, e.g. nf_int.
	 * @return string
	 * @throws \InvalidArgumentException When the name is invalid.
	 */
	public function table( string $logical ): string {
		global $wpdb;
		if ( ! preg_match( '/^nf_[a-z_]+$/', $logical ) ) {
			throw new \InvalidArgumentException( 'Invalid NF table name.' );
		}
		return $wpdb->prefix . $logical;
	}

	/**
	 * Select.
	 *
	 * @param string                        $sql    SQL with ? placeholders.
	 * @param array<int|string,scalar|null> $params Values.
	 * @return array<int,array<string,scalar|null>>
	 */
	public function select( string $sql, array $params = array() ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $this->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->check();
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Execute.
	 *
	 * @param string                        $sql    SQL with ? placeholders.
	 * @param array<int|string,scalar|null> $params Values.
	 * @return int
	 */
	public function execute( string $sql, array $params = array() ): int {
		global $wpdb;
		$result = $wpdb->query( $this->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->check();
		return (int) $result;
	}

	/**
	 * Last insert ID.
	 *
	 * @return int
	 */
	public function insertId(): int {
		global $wpdb;
		return (int) $wpdb->insert_id;
	}

	/**
	 * Transaction with nesting (inner calls join the outer one).
	 *
	 * @param callable $fn Work.
	 * @return mixed
	 * @throws \Throwable Whatever the callable throws, after rollback.
	 */
	public function transaction( callable $fn ): mixed {
		global $wpdb;
		if ( $this->depth > 0 ) {
			++$this->depth;
			try {
				return $fn();
			} finally {
				--$this->depth;
			}
		}
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->depth = 1;
		try {
			$result = $fn();
			$wpdb->query( 'COMMIT' );
			return $result;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		} finally {
			$this->depth = 0;
		}
	}

	/**
	 * Ping.
	 *
	 * @return bool
	 */
	public function ping(): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( 'SELECT 1' );
	}

	/**
	 * Non-blocking lock, hashed per site.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public function tryLock( string $name ): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $this->lockName( $name ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Release lock.
	 *
	 * @param string $name Name.
	 * @return void
	 */
	public function unlock( string $name ): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lockName( $name ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Lock names are limited to 64 chars; hash with the site prefix.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private function lockName( string $name ): string {
		global $wpdb;
		return 'nf_' . md5( $wpdb->prefix . $name );
	}

	/**
	 * Replace ? with %d / %f / %s by value type and prepare.
	 *
	 * @param string                        $sql    SQL.
	 * @param array<int|string,scalar|null> $params Values.
	 * @return string
	 */
	private function prepare( string $sql, array $params ): string {
		global $wpdb;
		if ( array() === $params ) {
			return $sql;
		}
		$values = array_values( $params );
		$i      = 0;
		$sql    = (string) preg_replace_callback(
			'/\?/',
			static function () use ( &$i, $values ): string {
				$v = $values[ $i++ ] ?? null;
				return is_int( $v ) ? '%d' : '%s';
			},
			$sql
		);
		return $wpdb->prepare( $sql, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
	}

	/**
	 * Turn a database error into an exception so transactions roll back.
	 *
	 * @return void
	 * @throws \RuntimeException On a database error.
	 */
	private function check(): void {
		global $wpdb;
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'NF database error: ' . $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}
}
