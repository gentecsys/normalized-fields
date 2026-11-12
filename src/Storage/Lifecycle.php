<?php
/**
 * Remove NF data when WordPress deletes the object it belongs to.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Storage;

use NF\Backend\BackendInterface;
use NF\Install\Upgrader;
use NF\Pivot\Pivot;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes an object's values, Series rows, pivot row and archive snapshots after WordPress deleted the post,
 * term, comment or user, so nothing is left behind (found on nf-dev: `wp_delete_post()` left every NF row).
 *
 * Runs whatever deleted the object (admin, REST, WP-CLI, or cron emptying the trash): it removes data of an
 * object that no longer exists. While the schema is behind it does nothing (the tables may be missing) and
 * fires `nf/lifecycle/schema_behind`.
 */
final class Lifecycle {

	/**
	 * Constructor.
	 *
	 * @param BackendInterface $db     Backend.
	 * @param FieldMap         $fields Field map.
	 * @param Store            $store  Store.
	 */
	public function __construct( private BackendInterface $db, private FieldMap $fields, private Store $store ) {}

	/**
	 * Add hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'after_delete_post', array( $this, 'onPostDeleted' ), 10, 2 );
		add_action( 'delete_term', array( $this, 'onTermDeleted' ), 10, 1 );
		add_action( 'deleted_comment', array( $this, 'onCommentDeleted' ), 10, 1 );
		// User values are network-wide on multisite (ADR-0011): only a network deletion removes them.
		add_action( is_multisite() ? 'wpmu_delete_user' : 'deleted_user', array( $this, 'onUserDeleted' ), 10, 1 );
	}

	/**
	 * A post (or revision) was deleted.
	 *
	 * @param int           $id   Post ID.
	 * @param \WP_Post|null $post Post as it was.
	 * @return void
	 */
	public function onPostDeleted( int $id, $post = null ): void {
		$this->purge(
			ObjectType::Post,
			$id,
			function () use ( $id, $post ): void {
				$archive = new Archive( $this->db, $this->fields, $this->store );
				$archive->delete( ObjectType::Post, $id );
				$archive->deleteForParent( ObjectType::Post, $id );
				if ( $post instanceof \WP_Post ) {
					$pivot = new Pivot( $this->db, $this->fields, $this->store );
					$set   = $pivot->findSetId( ObjectType::Post, $post->post_type );
					if ( null !== $set ) {
						$pivot->remove( $set, $id );
					}
				}
			}
		);
	}

	/**
	 * A term was deleted.
	 *
	 * @param int $id Term ID.
	 * @return void
	 */
	public function onTermDeleted( int $id ): void {
		$this->purge( ObjectType::Term, $id );
	}

	/**
	 * A comment was deleted.
	 *
	 * @param int|string $id Comment ID.
	 * @return void
	 */
	public function onCommentDeleted( int|string $id ): void {
		$this->purge( ObjectType::Comment, (int) $id );
	}

	/**
	 * A user was deleted.
	 *
	 * @param int $id User ID.
	 * @return void
	 */
	public function onUserDeleted( int $id ): void {
		$this->purge( ObjectType::User, $id );
	}

	/**
	 * Delete an object's NF data.
	 *
	 * @param ObjectType    $type  Object type.
	 * @param int           $id    Object ID.
	 * @param callable|null $extra More to delete (archive, pivot).
	 * @return void
	 */
	private function purge( ObjectType $type, int $id, ?callable $extra = null ): void {
		if ( $id <= 0 ) {
			return;
		}
		if ( ! Upgrader::isCurrent() ) {
			do_action( 'nf/lifecycle/schema_behind', $type, $id ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
			return;
		}
		try {
			$this->store->deleteObject( $type, $id );
			if ( null !== $extra ) {
				$extra();
			}
		} catch ( \Throwable $e ) {
			do_action( 'nf/lifecycle/error', $e, $type, $id ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		}
	}
}
