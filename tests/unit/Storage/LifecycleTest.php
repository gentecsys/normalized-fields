<?php
/**
 * NF data goes when WordPress deletes the object.
 *
 * @package NF
 */

use NF\Install\Upgrader;
use NF\Plugin;
use NF\Storage\DataTable;
use NF\Storage\ObjectType;

/**
 * Deleting a post, term, comment or user removes its NF values and Series rows.
 */
class LifecycleTest extends WP_UnitTestCase {

	/**
	 * Tables and fields.
	 */
	public function set_up() {
		parent::set_up();
		NF\Install\Schema::install();
		update_option( Upgrader::OPTION, Upgrader::SCHEMA_VERSION );
		$fields = Plugin::fields();
		$fields->register( 'field_tlc_title', 'lc_title', DataTable::Str );
		$rows = $fields->register( 'field_tlc_rows', 'lc_rows', DataTable::Rows );
		$fields->register( 'field_tlc_rows_n', 'n', DataTable::Int, false, $rows->id );
	}

	/**
	 * Remove the test fields.
	 */
	public function tear_down() {
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field\\_tlc%' ) );
		Plugin::fields()->flush();
		parent::tear_down();
	}

	/**
	 * Write a value and a Series for an object.
	 *
	 * @param ObjectType $type Type.
	 * @param int        $id   ID.
	 * @return void
	 */
	private function seed( ObjectType $type, int $id ): void {
		Plugin::store()->set( $type, $id, 'field_tlc_title', 'x' );
		Plugin::store()->setRows( $type, $id, 'field_tlc_rows', array( array( 'n' => 1 ), array( 'n' => 2 ) ) );
		$this->assertSame( 'x', Plugin::store()->get( $type, $id, 'field_tlc_title' ) );
	}

	/**
	 * Whether any NF row is left for the object.
	 *
	 * @param ObjectType $type Type.
	 * @param int        $id   ID.
	 * @return int
	 */
	private function left( ObjectType $type, int $id ): int {
		$db = nf_db();
		$n  = 0;
		foreach ( array( 'nf_str', 'nf_int', 'nf_rows' ) as $t ) {
			$n += (int) $db->select( 'SELECT COUNT(*) AS c FROM ' . $db->table( $t ) . ' WHERE object_type = ? AND object_id = ?', array( $type->value, $id ) )[0]['c'];
		}
		return $n;
	}

	/**
	 * Every object type.
	 */
	public function test_deleting_objects_removes_their_nf_data() {
		$post = self::factory()->post->create();
		$this->seed( ObjectType::Post, $post );
		wp_delete_post( $post, true );
		$this->assertSame( 0, $this->left( ObjectType::Post, $post ), 'post' );

		$term = self::factory()->term->create();
		$this->seed( ObjectType::Term, $term );
		wp_delete_term( $term, 'post_tag' );
		$this->assertSame( 0, $this->left( ObjectType::Term, $term ), 'term' );

		$comment = self::factory()->comment->create( array( 'comment_post_ID' => self::factory()->post->create() ) );
		$this->seed( ObjectType::Comment, $comment );
		wp_delete_comment( $comment, true );
		$this->assertSame( 0, $this->left( ObjectType::Comment, $comment ), 'comment' );

		$user = self::factory()->user->create();
		$this->seed( ObjectType::User, $user );
		if ( is_multisite() ) {
			wpmu_delete_user( $user );
		} else {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user );
		}
		$this->assertSame( 0, $this->left( ObjectType::User, $user ), 'user' );
	}

	/**
	 * Trashing keeps the data; other objects are untouched.
	 */
	public function test_trash_and_other_objects_keep_data() {
		$a = self::factory()->post->create();
		$b = self::factory()->post->create();
		$this->seed( ObjectType::Post, $a );
		$this->seed( ObjectType::Post, $b );
		wp_trash_post( $a );
		$this->assertSame( 5, $this->left( ObjectType::Post, $a ), 'trashed post keeps its values' );
		wp_delete_post( $a, true );
		$this->assertSame( 5, $this->left( ObjectType::Post, $b ), 'other post untouched' );
	}

	/**
	 * While the schema is behind, nothing is deleted (tables may be missing).
	 */
	public function test_schema_behind_does_nothing() {
		$post = self::factory()->post->create();
		$this->seed( ObjectType::Post, $post );
		update_option( Upgrader::OPTION, Upgrader::SCHEMA_VERSION - 1 );
		wp_delete_post( $post, true );
		update_option( Upgrader::OPTION, Upgrader::SCHEMA_VERSION );
		$this->assertSame( 5, $this->left( ObjectType::Post, $post ) );
	}
}
