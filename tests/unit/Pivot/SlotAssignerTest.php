<?php
/**
 * Default pivot sets and slot assignment (WP 3.0d).
 *
 * @package NF
 */

use NF\Pivot\Pivot;
use NF\Pivot\SlotAssigner;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

/**
 * Eligibility, slot order, idempotence, dry run and pivot set creation.
 */
class SlotAssignerTest extends WP_UnitTestCase {

	private const TYPE = 'nf_slot_event';

	/**
	 * Pivot.
	 *
	 * @var Pivot
	 */
	private Pivot $pivot;

	/**
	 * Assigner.
	 *
	 * @var SlotAssigner
	 */
	private SlotAssigner $assigner;

	/**
	 * Clean state.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		update_option( NF\Install\Upgrader::OPTION, NF\Install\Upgrader::SCHEMA_VERSION );
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t30d%' ) );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot_sets' ) . ' WHERE subtype = ?', array( self::TYPE ) );
		$fields         = new FieldMap( $db );
		$fields->flush();
		$this->pivot    = new Pivot( $db, $fields, new Store( $db, $fields ) );
		$this->assigner = new SlotAssigner( $this->pivot, $fields );
	}

	/**
	 * Remove the set.
	 */
	public function tear_down() {
		$id = $this->pivot->findSetId( ObjectType::Post, self::TYPE );
		if ( null !== $id ) {
			$db = nf_db();
			$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot_slots' ) . ' WHERE pivot_id = ?', array( $id ) );
			$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot_sets' ) . ' WHERE pivot_id = ?', array( $id ) );
		}
		parent::tear_down();
	}

	/**
	 * Build an ACF field array.
	 *
	 * @param string              $n     Short name.
	 * @param string              $type  ACF type.
	 * @param array<string,mixed> $extra Extra settings.
	 * @return array<string,mixed>
	 */
	private function field( string $n, string $type, array $extra = array() ): array {
		return array_merge(
			array(
				'key'  => 'field_t30d_' . $n,
				'name' => $n,
				'type' => $type,
			),
			$extra
		);
	}

	/**
	 * Letters follow the data table; unsupported types are skipped.
	 */
	public function test_letters(): void {
		$this->assertSame( 'i', SlotAssigner::letter( $this->field( 'a', 'true_false' ) ) );
		$this->assertSame( 'i', SlotAssigner::letter( $this->field( 'a', 'post_object' ) ) );
		$this->assertSame( 'n', SlotAssigner::letter( $this->field( 'a', 'number' ) ) );
		$this->assertSame( 't', SlotAssigner::letter( $this->field( 'a', 'date_picker' ) ) );
		$this->assertSame( 's', SlotAssigner::letter( $this->field( 'a', 'select' ) ) );
		$this->assertSame( 'm', SlotAssigner::letter( $this->field( 'a', 'checkbox' ) ) );
		$this->assertSame( 'm', SlotAssigner::letter( $this->field( 'a', 'select', array( 'multiple' => 1 ) ) ) );
		$this->assertNull( SlotAssigner::letter( $this->field( 'a', 'post_object', array( 'multiple' => 1 ) ) ) );
		$this->assertNull( SlotAssigner::letter( $this->field( 'a', 'text' ) ) );
		$this->assertNull( SlotAssigner::letter( $this->field( 'a', 'repeater' ) ) );
	}

	/**
	 * Post types come from `post_type ==` rules only.
	 */
	public function test_post_types_from_location(): void {
		$rule  = static fn( string $param, string $op, string $value ): array => array(
			array(
				'param'    => $param,
				'operator' => $op,
				'value'    => $value,
			),
		);
		$group = array(
			'location' => array(
				$rule( 'post_type', '==', 'event' ),
				$rule( 'post_type', '!=', 'page' ),
				$rule( 'taxonomy', '==', 'genre' ),
			),
		);
		$this->assertSame( array( 'event' ), SlotAssigner::postTypes( $group ) );
	}

	/**
	 * Slots go out in field order; a second run changes nothing; later fields take later slots.
	 */
	public function test_assign_in_order_and_idempotent(): void {
		$fields = array(
			$this->field( 'price', 'number' ),
			$this->field( 'starts', 'date_picker' ),
			$this->field( 'title2', 'text' ),
			$this->field( 'level', 'select' ),
			$this->field( 'flag', 'true_false' ),
			$this->field( 'tags', 'checkbox' ),
		);
		$res    = $this->assigner->assign( self::TYPE, $fields );
		$this->assertTrue( $res['created'] );
		$this->assertSame(
			array(
				'field_t30d_price'  => 'n1',
				'field_t30d_starts' => 't1',
				'field_t30d_level'  => 's1',
				'field_t30d_flag'   => 'i1',
				'field_t30d_tags'   => 'm1',
			),
			$res['assigned']
		);
		$this->assertCount( 5, $this->pivot->slots( (int) $res['pivot_id'] ) );

		$again = $this->assigner->assign( self::TYPE, $fields );
		$this->assertSame( array(), $again['assigned'] );
		$this->assertFalse( $again['created'] );

		$more = $this->assigner->assign( self::TYPE, array_merge( $fields, array( $this->field( 'cost', 'number' ) ) ) );
		$this->assertSame( array( 'field_t30d_cost' => 'n2' ), $more['assigned'] );
		$slots = $this->pivot->slots( (int) $res['pivot_id'] );
		$this->assertSame( 'field_t30d_price', $slots['n1']['key'] );
	}

	/**
	 * A dry run reports and writes nothing, not even the set.
	 */
	public function test_dry_run_writes_nothing(): void {
		$res = $this->assigner->assign( self::TYPE, array( $this->field( 'price', 'number' ) ), true );
		$this->assertSame( array( 'field_t30d_price' => 'n1' ), $res['assigned'] );
		$this->assertNull( $this->pivot->findSetId( ObjectType::Post, self::TYPE ) );
	}

	/**
	 * Fields past the letter's capacity are skipped, not overwritten.
	 */
	public function test_slots_run_out(): void {
		$fields = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$fields[] = $this->field( 'n' . $i, 'number' );
		}
		$res = $this->assigner->assign( self::TYPE, $fields );
		$this->assertCount( 4, $res['assigned'] );
		$this->assertSame( array( 'field_t30d_n5' ), $res['skipped'] );
	}

	/**
	 * While the schema is behind nothing is written and a resync is flagged.
	 */
	public function test_schema_behind_skips(): void {
		update_option( NF\Install\Upgrader::OPTION, NF\Install\Upgrader::SCHEMA_VERSION - 1 );
		$res = $this->assigner->assign( self::TYPE, array( $this->field( 'price', 'number' ) ) );
		$this->assertSame( array(), $res['assigned'] );
		$this->assertNull( $this->pivot->findSetId( ObjectType::Post, self::TYPE ) );
	}
}
