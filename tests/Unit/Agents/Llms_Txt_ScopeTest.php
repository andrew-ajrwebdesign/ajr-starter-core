<?php
/**
 * Unit tests for Agents\Llms_Txt_Scope.
 *
 * The filter decides, per post, what AI SEO Assistant may hand to agents. A wrong "true" publishes
 * something (the cart, an excluded page) and nothing visible on the site shows it.
 *
 * @package AJR\StarterCore
 */

declare( strict_types=1 );

namespace AJR\StarterCore\Tests\Unit\Agents;

use AJR\StarterCore\Agents\Llms_Txt_Scope;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Tests for the llms.txt scope filter.
 */
class Llms_Txt_ScopeTest extends TestCase {

	/**
	 * A post object of the given type and ID.
	 *
	 * @param int    $id   Post ID.
	 * @param string $type Post type.
	 */
	protected function post( int $id, string $type ): \WP_Post {
		return new \WP_Post(
			[
				'ID'        => $id,
				'post_type' => $type,
			]
		);
	}

	/**
	 * Refused posts stay refused; a non-post passes through unchanged.
	 */
	public function test_never_adds(): void {
		$scope = new Llms_Txt_Scope();
		$this->assertFalse( $scope->filter( false, $this->post( 5, 'page' ) ) );
		$this->assertTrue( $scope->filter( true, 'not a post' ) );
	}

	/**
	 * IDs from the build's filter are refused; other posts are kept.
	 *
	 * Runs in its own process: excluded_ids() memoises in a static for the request.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_filtered_ids_are_refused(): void {
		WP_Mock::onFilter( 'ajr_starter_llms_excluded_ids' )->with( [] )->reply( [ 42 ] );
		$scope = new Llms_Txt_Scope();

		$this->assertFalse( $scope->filter( true, $this->post( 42, 'page' ) ) );
		$this->assertTrue( $scope->filter( true, $this->post( 43, 'page' ) ) );
	}
}
