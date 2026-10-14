<?php
/**
 * Unit tests for Core\Config.
 *
 * WHY THESE TESTS EXIST
 *
 * Config is the single gate every feature reads through, and its rules are the kind that
 * fail silently. If defaults stop merging on read, a site that saved its settings before a
 * key existed loses that key with no error. If the stale-key filter stops working, a
 * removed feature's leftover value comes back as live configuration. If the cache stops
 * being busted on save, the admin screen shows the old value and looks like a failed write.
 *
 * None of those throw. They just quietly do the wrong thing, which is exactly what a test
 * suite is for.
 *
 * The tests derive their fixtures from schema() rather than hardcoding key names, so adding
 * or renaming a setting does not break them. Only the invariants are asserted.
 *
 * @package AJR\StarterCore
 */

declare( strict_types=1 );

namespace AJR\StarterCore\Tests\Unit\Core;

use AJR\StarterCore\Core\Config;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Tests for the Config class.
 */
class ConfigTest extends TestCase {

	/**
	 * Clear the static read cache before every test.
	 *
	 * Config caches all() in a protected static, which survives between tests in the same
	 * process. Without this reset the second test in a run reads the first test's values
	 * and passes for the wrong reason.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->reset_cache();
	}

	/**
	 * {@inheritDoc}
	 */
	public function tearDown(): void {
		$this->reset_cache();
		parent::tearDown();
	}

	/**
	 * Null the protected static cache via reflection.
	 */
	protected function reset_cache(): void {
		$property = new \ReflectionProperty( Config::class, 'cache' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	/**
	 * The defaults declared in schema(), as a flat key => value map.
	 *
	 * @return array<string,mixed>
	 */
	protected function schema_defaults(): array {
		$defaults = [];
		foreach ( Config::schema() as $key => $field ) {
			$defaults[ $key ] = $field['default'] ?? '';
		}
		return $defaults;
	}

	/**
	 * With nothing saved, every setting equals its declared default.
	 */
	public function test_all_returns_schema_defaults_when_nothing_saved(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( Config::option_name(), [] )
			->andReturn( [] );

		$this->assertSame( $this->schema_defaults(), Config::all() );
	}

	/**
	 * Saved values win over defaults, and untouched keys keep theirs.
	 *
	 * This is the merge-on-read rule: a key added to schema() in a later release applies
	 * immediately to sites that saved their settings before it existed.
	 */
	public function test_all_merges_saved_values_over_defaults(): void {
		$defaults = $this->schema_defaults();
		$key      = array_key_first( $defaults );

		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( Config::option_name(), [] )
			->andReturn( [ $key => 'saved value' ] );

		$result = Config::all();

		$this->assertSame( 'saved value', $result[ $key ], 'A saved value must override its default.' );
		$this->assertCount( count( $defaults ), $result, 'Merging must not add or drop keys.' );
	}

	/**
	 * A stored key the schema does not know about is discarded, not returned.
	 *
	 * Left in, a value from a feature that was removed comes back as live configuration.
	 */
	public function test_all_discards_keys_absent_from_the_schema(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( Config::option_name(), [] )
			->andReturn( [ 'removed.feature.leftover' => 'should not surface' ] );

		$this->assertArrayNotHasKey( 'removed.feature.leftover', Config::all() );
	}

	/**
	 * A corrupt option that is not an array degrades to defaults instead of fatalling.
	 */
	public function test_all_survives_a_non_array_option(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( Config::option_name(), [] )
			->andReturn( 'not an array' );

		$this->assertSame( $this->schema_defaults(), Config::all() );
	}

	/**
	 * The option is read once per request, however many settings are looked up.
	 */
	public function test_all_reads_the_option_only_once(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( Config::option_name(), [] )
			->andReturn( [] );

		Config::all();
		Config::all();
		Config::get( array_key_first( $this->schema_defaults() ) );

		$this->assertConditionsMet();
	}

	/**
	 * get() returns the fallback only for a key the schema has never heard of.
	 *
	 * A schema key whose default is an empty string must return that empty string — not the
	 * caller's fallback. Blurring the two makes "set to blank on purpose" indistinguishable
	 * from "not a setting", which is how a default silently overrides a deliberate choice.
	 */
	public function test_get_returns_fallback_only_for_an_unknown_key(): void {
		$defaults = $this->schema_defaults();
		$empty    = array_search( '', $defaults, true );

		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( Config::option_name(), [] )
			->andReturn( [] );

		$this->assertSame(
			'FALLBACK',
			Config::get( 'no.such.key', 'FALLBACK' ),
			'An unknown key must return the fallback.'
		);

		if ( false !== $empty ) {
			$this->assertSame(
				'',
				Config::get( $empty, 'FALLBACK' ),
				'A known key with an empty default must return the empty default, not the fallback.'
			);
		}
	}

	/**
	 * filled() returns a boolean setting as-is rather than testing it for emptiness.
	 *
	 * Treating a boolean like a string makes `false` and "switched off" the same as "never
	 * configured", and features gate on this method.
	 */
	public function test_filled_passes_boolean_settings_through_unchanged(): void {
		$defaults = $this->schema_defaults();

		$bool_key = null;
		foreach ( $defaults as $key => $default ) {
			if ( is_bool( $default ) ) {
				$bool_key = $key;
				break;
			}
		}

		if ( null === $bool_key ) {
			$this->markTestSkipped( 'The schema declares no boolean setting to exercise.' );
		}

		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( Config::option_name(), [] )
			->andReturn( [ $bool_key => false ] );

		$this->assertFalse( Config::filled( $bool_key ) );
	}

	/**
	 * A whitespace-only string is not "filled" — it is a render site switched off.
	 */
	public function test_filled_treats_whitespace_as_empty(): void {
		$defaults  = $this->schema_defaults();
		$string_key = null;
		foreach ( $defaults as $key => $default ) {
			if ( is_string( $default ) ) {
				$string_key = $key;
				break;
			}
		}

		if ( null === $string_key ) {
			$this->markTestSkipped( 'The schema declares no string setting to exercise.' );
		}

		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( Config::option_name(), [] )
			->andReturn( [ $string_key => "   \t  " ] );

		$this->assertFalse( Config::filled( $string_key ) );
	}

	/**
	 * save() writes the option and invalidates the cache, so the next read is fresh.
	 *
	 * Without the invalidation the settings screen renders the pre-save values and the write
	 * looks like it failed.
	 */
	public function test_save_writes_the_option_and_busts_the_cache(): void {
		$defaults = $this->schema_defaults();
		$key      = array_key_first( $defaults );
		$values   = [ $key => 'written' ];

		WP_Mock::userFunction( 'get_option' )
			->twice()
			->with( Config::option_name(), [] )
			->andReturn( [], $values );

		// Autoload is stated explicitly (true) since 5e0c159 — the test asserts that too, so a
		// regression to a two-argument update_option() fails here rather than in production.
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( Config::option_name(), $values, true );

		Config::all();
		Config::save( $values );

		$this->assertSame( 'written', Config::all()[ $key ], 'The read after save must not come from the stale cache.' );
	}
}
