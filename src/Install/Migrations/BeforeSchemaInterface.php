<?php
/**
 * Marker for migrations that run before dbDelta.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Install\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * A migration that runs before Schema::install(). Use it to change several columns of one table in a single
 * ALTER: dbDelta alters one column per statement, and each statement rebuilds the whole table.
 */
interface BeforeSchemaInterface extends MigrationInterface {}
