<?php
/**
 * Recording WP_CLI double for JobCommandTest.
 *
 * @package NF
 */

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
