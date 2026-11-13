<?php
/**
 * WP-CLI job command tests.
 *
 * @package NF
 */

use NF\Core\Jobs\JobCommand;
use NF\Core\Jobs\JobRunner;
use NF\Install\Upgrader;

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Recording WP_CLI double (WP-CLI is not loaded in the PHPUnit run).
	 */
	class WP_CLI { // phpcs:ignore Generic.Classes.OpeningBraceSameLine,PEAR.NamingConventions.ValidClassName,Squiz.Commenting
		/**
		 * Logged lines.
		 *
		 * @var string[]
		 */
		public static array $lines = array();

		/**
		 * Log.
		 *
		 * @param string $m Message.
		 */
		public static function log( $m ): void {
			self::$lines[] = (string) $m;
		}
	}
}

/**
 * `wp nf job status` shows a failed job's error.
 */
class JobCommandTest extends WP_UnitTestCase {

	/**
	 * Status of a failed record prints the state and the error.
	 */
	public function test_status_shows_error_of_failed_job(): void {
		update_option( Upgrader::OPTION, Upgrader::SCHEMA_VERSION );
		update_option(
			JobRunner::OPTION_PREFIX . 'failing-abc',
			array(
				'key'      => 'failing',
				'args'     => array(),
				'state'    => array( 'done' => false ),
				'status'   => 'failed',
				'steps'    => 0,
				'started'  => time(),
				'updated'  => time(),
				'attempts' => 3,
				'error'    => 'boom 3',
			),
			false
		);
		WP_CLI::$lines = array();
		( new JobCommand() )->status( array( 'failing-abc' ) );
		$this->assertStringStartsWith( 'failed: 0 step(s)', WP_CLI::$lines[0] );
		$this->assertSame( 'Error after 3 attempt(s): boom 3', WP_CLI::$lines[1] );
	}
}
