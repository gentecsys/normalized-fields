<?php
/**
 * Schema upgrades on plugin update.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Install;

use NF\Install\Migrations\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the stored schema version (nf_db_version, per site) with SCHEMA_VERSION. When it is lower,
 * creates missing tables with dbDelta and runs the versioned migrations in order.
 * Raise SCHEMA_VERSION whenever contracts/schema.sql changes.
 */
final class Upgrader {

	/**
	 * Current schema version. 2 = 1.3 pivot tables added; 3 = 2.2a nf_dict added; 4 = 2.3 nf_archive added; 5 = decimals widened to DECIMAL(24,10) (M005WidenDecimals, before dbDelta).
	 */
	public const SCHEMA_VERSION = 5;

	/**
	 * Option holding the installed schema version for the current site.
	 */
	public const OPTION = 'nf_db_version';

	/**
	 * Migrations, run in version order.
	 *
	 * @var MigrationInterface[]
	 */
	private array $migrations;

	/**
	 * Constructor.
	 *
	 * @param MigrationInterface[]|null $migrations Migrations to run; null for NF's own (defaults()).
	 */
	public function __construct( ?array $migrations = null ) {
		$migrations ??= self::defaults();
		usort( $migrations, static fn( MigrationInterface $a, MigrationInterface $b ): int => $a->version() <=> $b->version() );
		$this->migrations = $migrations;
	}

	/**
	 * NF's migrations.
	 *
	 * @return MigrationInterface[]
	 */
	public static function defaults(): array {
		return array( new Migrations\M005WidenDecimals() );
	}

	/**
	 * Option used as an atomic lock so concurrent requests never run dbDelta at the same time.
	 */
	public const LOCK = 'nf_upgrade_lock';

	/**
	 * Seconds after which a lock left by a crashed upgrade is ignored.
	 */
	public const LOCK_TTL = 600;

	/**
	 * Capability needed to run the upgrade from wp-admin.
	 */
	public const CAPABILITY = 'activate_plugins';

	/**
	 * Option recording the last failed upgrade: array{version:int,time:int,message:string}.
	 */
	public const FAILED = 'nf_upgrade_failed';

	/**
	 * Option set when NF skipped a write because the schema was behind: array{since:int,objects:string[],overflow:bool}.
	 * `objects` lists ACF post IDs (12, 'user_3', 'term_5', 'option') to re-copy from ACF after the upgrade.
	 */
	public const NEEDS_RESYNC = 'nf_needs_resync';

	/**
	 * Action name (admin-post.php and nonce) of the update button.
	 */
	public const ACTION = 'nf_upgrade';

	/**
	 * Message of the last failed upgrade in this request.
	 *
	 * @var string|null
	 */
	private ?string $error = null;

	/**
	 * Add the update notice, the button handler and `wp nf upgrade`.
	 *
	 * The upgrade never runs on its own: not on visitor, REST, admin-ajax, cron or admin page requests.
	 * Only plugin activation, the button and `wp nf upgrade` run it. Until then NF skips its writes
	 * (Bridge) and records that a re-sync is needed. wp-cron.php is public, so cron is not trusted.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handleButton' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\\WP_CLI' ) ) {
			\WP_CLI::add_command( 'nf upgrade', array( $this, 'cliUpgrade' ) );
		}
	}

	/**
	 * Admin notice: database update required (with the button), or the result of the last click.
	 *
	 * @return void
	 */
	public function notice(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		// Display only: the result of our own redirect, never acted on.
		$result = isset( $_GET['nf_upgrade'] ) ? sanitize_key( wp_unslash( (string) $_GET['nf_upgrade'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::isCurrent() ) {
			if ( 'done' === $result ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The Normalized Fields database is up to date.', 'normalized-fields' ) . '</p>';
				$pending = self::resyncPending();
				if ( null !== $pending ) {
					echo '<p>' . esc_html(
						$pending['all']
							? __( 'More ACF values were saved while the update was pending than NF could list. Copy them again from ACF with wp nf resync --all.', 'normalized-fields' )
							/* translators: %d: number of objects (posts, terms, users, options) still to re-sync. */
							: sprintf( _n( '%d object saved while the update was pending still needs a re-sync from ACF. Run wp nf resync.', '%d objects saved while the update was pending still need a re-sync from ACF. Run wp nf resync.', $pending['count'], 'normalized-fields' ), $pending['count'] )
					) . '</p>';
				}
				echo '</div>';
			}
			return;
		}
		$failed = get_option( self::FAILED );
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Normalized Fields needs a database update.', 'normalized-fields' ) . '</strong> ';
		printf(
			/* translators: 1: installed schema version, 2: required schema version. */
			esc_html__( 'Schema %1$d is installed; this version needs %2$d. Until the update runs, NF does not write to its tables.', 'normalized-fields' ),
			(int) get_option( self::OPTION, 0 ),
			(int) self::SCHEMA_VERSION
		);
		echo '</p>';
		if ( is_array( $failed ) && (int) ( $failed['version'] ?? 0 ) === self::SCHEMA_VERSION ) {
			echo '<p>' . esc_html__( 'The last update failed:', 'normalized-fields' ) . ' <code>' . esc_html( (string) ( $failed['message'] ?? '' ) ) . '</code></p>';
		} elseif ( 'busy' === $result ) {
			echo '<p>' . esc_html__( 'Another update is running. Try again in a few minutes.', 'normalized-fields' ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		wp_nonce_field( self::ACTION );
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Update the NF database', 'normalized-fields' ) . '</button> ';
		echo esc_html__( 'or run', 'normalized-fields' ) . ' <code>wp nf upgrade</code> ' . esc_html__( '(recommended on large sites: some updates rebuild tables).', 'normalized-fields' ) . '</p></form></div>';
	}

	/**
	 * Handle the button (admin-post): checks capability and nonce, upgrades, redirects back.
	 *
	 * @return void
	 */
	public function handleButton(): void {
		$result = $this->runFromAdmin();
		$back   = wp_get_referer();
		wp_safe_redirect( add_query_arg( 'nf_upgrade', $result, $back ? $back : admin_url() ) );
		exit;
	}

	/**
	 * Run the upgrade for an admin click. Dies on a missing capability or a bad nonce.
	 *
	 * @return string 'done', 'current', 'busy' or 'failed'.
	 */
	public function runFromAdmin(): string {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to update the Normalized Fields database.', 'normalized-fields' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		// Table rebuilds can be slow on large sites; wp nf upgrade avoids web server timeouts altogether.
		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		if ( self::isCurrent() ) {
			return 'current';
		}
		if ( $this->maybe_upgrade() ) {
			return 'done';
		}
		if ( null !== $this->lastError() ) {
			return 'failed';
		}
		// Not run: either another request finished it meanwhile, or it still holds the lock.
		wp_cache_delete( self::OPTION, 'options' );
		return (int) get_option( self::OPTION, 0 ) >= self::SCHEMA_VERSION ? 'current' : 'busy';
	}

	/**
	 * Message of the last failed upgrade run by this instance, or null.
	 *
	 * @return string|null
	 */
	public function lastError(): ?string {
		return $this->error;
	}

	/**
	 * Bring the NF schema up to date.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nf upgrade
	 *     wp site list --field=url | xargs -I{} wp nf upgrade --url={}
	 *
	 * @return void
	 */
	public function cliUpgrade(): void {
		$before = (int) get_option( self::OPTION, 0 );
		if ( $this->maybe_upgrade() ) {
			\WP_CLI::success( sprintf( 'NF schema upgraded from %d to %d.', $before, self::SCHEMA_VERSION ) );
		} elseif ( null !== $this->lastError() ) {
			\WP_CLI::error( 'NF schema upgrade failed: ' . $this->lastError() );
		} elseif ( self::isCurrent() ) {
			\WP_CLI::success( sprintf( 'NF schema is current (%d).', self::SCHEMA_VERSION ) );
		} else {
			\WP_CLI::error( 'Another NF upgrade is running; try again shortly.' );
		}
	}

	/**
	 * What still needs a re-sync from ACF, or null when nothing does.
	 *
	 * @return array{count:int,all:bool}|null
	 */
	public static function resyncPending(): ?array {
		$flag = get_option( self::NEEDS_RESYNC );
		if ( false === $flag ) {
			return null;
		}
		if ( ! is_array( $flag ) ) {
			// Recorded before objects were listed: which objects is unknown.
			return array(
				'count' => 0,
				'all'   => true,
			);
		}
		return array(
			'count' => count( (array) ( $flag['objects'] ?? array() ) ),
			'all'   => ! empty( $flag['overflow'] ),
		);
	}

	/**
	 * Whether the stored schema version is current. Code that writes NF tables checks this and skips
	 * the write instead of creating tables itself.
	 *
	 * @return bool
	 */
	public static function isCurrent(): bool {
		return (int) get_option( self::OPTION, 0 ) >= self::SCHEMA_VERSION;
	}

	/**
	 * Upgrade the current site when its schema version is behind. Callers are activation, the admin
	 * button and `wp nf upgrade` only; see register(). A failure is recorded (FAILED) and not retried
	 * until someone runs the upgrade again.
	 *
	 * After a successful run, `nf/upgraded` fires (outside the lock): the ACF bridge re-syncs what it had to skip.
	 *
	 * @return bool True when an upgrade ran; false when current, locked by another request, or failed.
	 */
	public function maybe_upgrade(): bool {
		$from = (int) get_option( self::OPTION, 0 );
		$ran  = $this->runUpgrade();
		if ( $ran ) {
			do_action( 'nf/upgraded', $from, self::SCHEMA_VERSION ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		}
		return $ran;
	}

	/**
	 * The upgrade itself, under the lock.
	 *
	 * @return bool
	 */
	private function runUpgrade(): bool {
		$this->error = null;
		if ( self::isCurrent() ) {
			return false;
		}
		if ( ! $this->acquireLock() ) {
			return false;
		}
		try {
			// Re-read after taking the lock: another request may have finished the upgrade meanwhile.
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			$stored = (int) get_option( self::OPTION, 0 );
			if ( $stored >= self::SCHEMA_VERSION ) {
				return false;
			}
			$due = array_values(
				array_filter(
					$this->migrations,
					static fn( MigrationInterface $m ): bool => $m->version() > $stored && $m->version() <= self::SCHEMA_VERSION
				)
			);
			// Multi-column changes first, so dbDelta finds nothing left to alter column by column.
			foreach ( $due as $migration ) {
				if ( $migration instanceof Migrations\BeforeSchemaInterface ) {
					$migration->up();
				}
			}
			Schema::install();
			foreach ( $due as $migration ) {
				if ( ! $migration instanceof Migrations\BeforeSchemaInterface ) {
					$migration->up();
				}
			}
			update_option( self::OPTION, self::SCHEMA_VERSION, true );
			delete_option( self::FAILED );
			return true;
		} catch ( \Throwable $e ) {
			$this->error = $e->getMessage();
			update_option(
				self::FAILED,
				array(
					'version' => self::SCHEMA_VERSION,
					'time'    => time(),
					'message' => $this->error,
				),
				false
			);
			return false;
		} finally {
			delete_option( self::LOCK );
		}
	}

	/**
	 * Take the lock. add_option() is an INSERT on a unique option_name, so only one request wins.
	 * A lock older than LOCK_TTL (crashed upgrade) is removed and retaken once.
	 *
	 * @return bool
	 */
	private function acquireLock(): bool {
		if ( add_option( self::LOCK, (string) time(), '', false ) ) {
			return true;
		}
		wp_cache_delete( self::LOCK, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$since = (int) get_option( self::LOCK, 0 );
		if ( $since > 0 && time() - $since < self::LOCK_TTL ) {
			return false;
		}
		delete_option( self::LOCK );
		return add_option( self::LOCK, (string) time(), '', false );
	}
}
