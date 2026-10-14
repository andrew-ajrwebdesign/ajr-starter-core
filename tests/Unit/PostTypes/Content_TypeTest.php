<?php
/**
 * Unit tests for PostTypes\Content_Type::flag_flush().
 *
 * WHY THIS FILE EXISTS
 *
 * flag_flush() compares the settings array before a write against the array after it, and
 * raises a flag when a key that shapes the rewrite rules has changed. Everything about it
 * fails quietly: it returns void, it runs on an option-update hook nobody watches, and the
 * consequence — a custom post type whose new slug 404s until somebody saves the permalinks
 * screen by hand — surfaces days later and looks like a hosting problem.
 *
 * It earned a test the hard way. While bringing this plugin under PHPCS, the `$new`
 * parameter was renamed (it is a reserved word) and one reference in the loop body was
 * missed. `php -l` passes on an undefined variable, and no sniff in the WordPress standard
 * looks for one, so the only thing between that rename and a shipped bug was reading the
 * body carefully. These tests are that check, made mechanical.
 *
 * @package AJR\StarterCore
 */

declare( strict_types=1 );

namespace AJR\StarterCore\Tests\Unit\PostTypes;

use AJR\StarterCore\PostTypes\Content_Type;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Tests for the rewrite-flush flag.
 */
class Content_TypeTest extends TestCase {

	/**
	 * The option flag_flush() sets when a rewrite-shaping key changes.
	 */
	protected const FLAG = 'ajr_starter_core_flush_rewrite';

	/**
	 * A change to a rewrite-shaping key raises the flag exactly once.
	 */
	public function test_a_changed_slug_flags_a_flush(): void {
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( self::FLAG, 1, false );

		( new Content_Type() )->flag_flush(
			[ 'cpt.slug' => 'case-study' ],
			[ 'cpt.slug' => 'project' ]
		);

		$this->assertConditionsMet();
	}

	/**
	 * Toggling the post type on is a rewrite change too.
	 */
	public function test_enabling_the_post_type_flags_a_flush(): void {
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( self::FLAG, 1, false );

		( new Content_Type() )->flag_flush(
			[ 'cpt.enabled' => false ],
			[ 'cpt.enabled' => true ]
		);

		$this->assertConditionsMet();
	}

	/**
	 * A write that touches nothing rewrite-shaping must not flag a flush.
	 *
	 * This is the assertion that catches a broken comparison: if the "after" array stops
	 * being read — an undefined variable, a bad rename — every key reads as null, the two
	 * sides stop matching, and a flush is flagged on every settings save.
	 */
	public function test_an_unrelated_change_does_not_flag_a_flush(): void {
		WP_Mock::userFunction( 'update_option' )->never();

		( new Content_Type() )->flag_flush(
			[ 'cpt.slug' => 'case-study', 'business.name' => 'Before' ],
			[ 'cpt.slug' => 'case-study', 'business.name' => 'After' ]
		);

		$this->assertConditionsMet();
	}

	/**
	 * Identical arrays flag nothing.
	 */
	public function test_an_unchanged_save_does_not_flag_a_flush(): void {
		WP_Mock::userFunction( 'update_option' )->never();

		$settings = [
			'cpt.enabled'     => true,
			'cpt.slug'        => 'case-study',
			'cpt.has_archive' => true,
		];

		( new Content_Type() )->flag_flush( $settings, $settings );

		$this->assertConditionsMet();
	}

	/**
	 * A non-array on either side degrades to an empty array rather than fatalling.
	 *
	 * update_option() stores whatever it is given, so "not an array" is a state a real
	 * install can reach.
	 */
	public function test_non_array_input_does_not_fatal(): void {
		WP_Mock::userFunction( 'update_option' )->zeroOrMoreTimes();

		( new Content_Type() )->flag_flush( 'not an array', [ 'cpt.slug' => 'project' ] );
		( new Content_Type() )->flag_flush( [ 'cpt.slug' => 'project' ], null );

		$this->assertConditionsMet();
	}
}
