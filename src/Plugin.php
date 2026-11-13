<?php
/**
 * Plugin service wiring.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF;

defined( 'ABSPATH' ) || exit;

/**
 * Wires services. Later WPs add their services to services() and each registers its own hooks.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Boot NF once.
	 *
	 * @return Plugin
	 */
	public static function boot(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			add_action( 'plugins_loaded', array( self::$instance, 'register' ) );
		}
		return self::$instance;
	}

	/**
	 * Backend adapter (ADR-0009), created on first use.
	 *
	 * @var Backend\BackendInterface|null
	 */
	private static ?Backend\BackendInterface $db = null;

	/**
	 * The backend adapter behind nf_db().
	 *
	 * @return Backend\BackendInterface
	 */
	public static function db(): Backend\BackendInterface {
		return self::$db ??= new Backend\MysqlBackend();
	}

	/**
	 * Field map, created on first use.
	 *
	 * @var Storage\FieldMap|null
	 */
	private static ?Storage\FieldMap $fieldMap = null;

	/**
	 * Store, created on first use.
	 *
	 * @var Storage\Store|null
	 */
	private static ?Storage\Store $store = null;

	/**
	 * The field map.
	 *
	 * @return Storage\FieldMap
	 */
	public static function fields(): Storage\FieldMap {
		return self::$fieldMap ??= new Storage\FieldMap( self::db() );
	}

	/**
	 * The typed-table store.
	 *
	 * @return Storage\Store
	 */
	public static function store(): Storage\Store {
		return self::$store ??= new Storage\Store( self::db(), self::fields() );
	}

	/**
	 * ACF bridge, created on first use.
	 *
	 * @var Acf\Bridge|null
	 */
	private static ?Acf\Bridge $bridge = null;

	/**
	 * The ACF bridge (the instance whose hooks are registered).
	 *
	 * @return Acf\Bridge
	 */
	public static function bridge(): Acf\Bridge {
		return self::$bridge ??= new Acf\Bridge( self::store(), self::fields() );
	}

	/**
	 * Services to register. Each exposes a register() method that adds its hooks.
	 *
	 * @return object[]
	 */
	private function services(): array {
		$services = array( new Install\Upgrader(), self::bridge(), new Storage\Lifecycle( self::db(), self::fields(), self::store() ) );
		/**
		 * Whether NF answers the core meta API for its fields (Layer 1). Off until standalone mode lands (WP 3.6).
		 *
		 * @param bool $on Default false.
		 */
		if ( apply_filters( 'nf/meta/intercept', false ) ) { // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
			$services[] = new Acf\MetaInterceptor( self::store(), self::db() );
		}
		return $services;
	}

	/**
	 * Register every service, then announce that NF has loaded.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( $this->services() as $service ) {
			$service->register();
		}
		/**
		 * Fires once NF has loaded.
		 */
		do_action( 'nf_loaded' );
	}
}
