<?php
/**
 * Post status codes used by nf_pivot.status.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Compact status codes (contracts/schema.sql, section 9).
 */
enum Status: int {
	case Publish   = 1;
	case Draft     = 2;
	case Pending   = 3;
	case Private   = 4;
	case Future    = 5;
	case Trash     = 6;
	case Inherit   = 7;
	case AutoDraft = 8;

	/**
	 * Code for a WordPress status name, or null when it has none (custom statuses map in a later WP).
	 *
	 * @param string $name Status name.
	 * @return self|null
	 */
	public static function fromName( string $name ): ?self {
		return match ( $name ) {
			'publish'    => self::Publish,
			'draft'      => self::Draft,
			'pending'    => self::Pending,
			'private'    => self::Private,
			'future'     => self::Future,
			'trash'      => self::Trash,
			'inherit'    => self::Inherit,
			'auto-draft' => self::AutoDraft,
			default      => null,
		};
	}
}
