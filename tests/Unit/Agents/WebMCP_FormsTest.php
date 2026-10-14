<?php
/**
 * Unit tests for Agents\WebMCP_Forms.
 *
 * WHY THESE TESTS EXIST
 *
 * The rules parser is the only thing standing between a client typing into a textarea and an
 * attribute printed into every page's HTML. Its failure modes are silent: a tool name with a
 * space produces a tool no agent can call, a duplicate name makes one form shadow another,
 * and a dropped line reads as "configured" in wp-admin. These tests pin the refusals.
 *
 * @package AJR\StarterCore
 */

declare( strict_types=1 );

namespace AJR\StarterCore\Tests\Unit\Agents;

use AJR\StarterCore\Agents\WebMCP_Forms;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Tests for the WebMCP_Forms rules parser.
 */
class WebMCP_FormsTest extends TestCase {

	/**
	 * Settings with search switched off, so parse() tests only the rules text.
	 */
	public function setUp(): void {
		parent::setUp();

		$config = new \ReflectionProperty( \AJR\StarterCore\Core\Config::class, 'cache' );
		$config->setAccessible( true );
		$config->setValue( null, null );

		WP_Mock::userFunction( 'get_option' )->andReturn( [ 'agents.webmcp_search' => false ] );
		WP_Mock::userFunction( 'wp_strip_all_tags' )->andReturnUsing( static fn( $s ) => strip_tags( (string) $s ) );
	}

	/**
	 * A well-formed line becomes a rule keyed by form reference.
	 */
	public function test_valid_line_parses(): void {
		$out = WebMCP_Forms::parse( 'gf:3 | requestQuote | Request a free quote.' );

		$this->assertSame( [], $out['errors'] );
		$this->assertSame(
			[
				'name'        => 'requestQuote',
				'description' => 'Request a free quote.',
			],
			$out['rules']['gf:3']
		);
	}

	/**
	 * AJR Forms references parse, normalise case, and share the duplicate-form guard.
	 */
	public function test_ajr_forms_reference(): void {
		$out = WebMCP_Forms::parse( "ajr:Contact | sendMessage | Send a message.
ajr:contact | again | Twice." );

		$this->assertSame( 'sendMessage', $out['rules']['ajr:contact']['name'] );
		$this->assertStringContainsString( 'already described', $out['errors'][0] );
		$this->assertSame( 'gf:3', WebMCP_Forms::form_reference( 'GF:03' ) );
		$this->assertNull( WebMCP_Forms::form_reference( 'ajr:bad key' ) );
		$this->assertNull( WebMCP_Forms::form_reference( 'ajr:' ) );
	}

	/**
	 * Comments and blank lines are ignored without an error.
	 */
	public function test_comments_and_blanks_are_ignored(): void {
		$out = WebMCP_Forms::parse( "# forms\n\n   \ngf:1 | contactUs | Send a message." );

		$this->assertSame( [], $out['errors'] );
		$this->assertCount( 1, $out['rules'] );
	}

	/**
	 * A description may itself contain the separator: only the first two pipes split.
	 */
	public function test_description_may_contain_a_pipe(): void {
		$out = WebMCP_Forms::parse( 'gf:2 | bookCall | Book a call | 15 minutes' );

		$this->assertSame( 'Book a call | 15 minutes', $out['rules']['gf:2']['description'] );
	}

	/**
	 * Every refusal is reported with its line number, and nothing invalid becomes a rule.
	 *
	 * @dataProvider invalid_lines
	 *
	 * @param string $line   The rules line.
	 * @param string $reason A fragment the error must contain.
	 */
	public function test_invalid_lines_are_reported( string $line, string $reason ): void {
		$out = WebMCP_Forms::parse( $line );

		$this->assertSame( [], $out['rules'] );
		$this->assertCount( 1, $out['errors'] );
		$this->assertStringContainsString( 'Line 1', $out['errors'][0] );
		$this->assertStringContainsString( $reason, $out['errors'][0] );
	}

	/**
	 * Lines that must be refused.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function invalid_lines(): array {
		return [
			'too few parts'         => [ 'gf:3 | requestQuote', 'three parts' ],
			'unknown form ref'      => [ 'cf7:3 | requestQuote | Quote.', 'not a form reference' ],
			'name with a space'     => [ 'gf:3 | request quote | Quote.', 'must start with a letter' ],
			'name starting digit'   => [ 'gf:3 | 1quote | Quote.', 'must start with a letter' ],
			'name with a quote'     => [ 'gf:3 | quote"x | Quote.', 'must start with a letter' ],
			'description only tags' => [ 'gf:3 | requestQuote | <b></b>', 'empty description' ],
		];
	}

	/**
	 * Two forms cannot share a tool name, case-insensitively.
	 */
	public function test_duplicate_tool_names_are_refused(): void {
		$out = WebMCP_Forms::parse( "gf:1 | requestQuote | One.\ngf:2 | RequestQuote | Two." );

		$this->assertCount( 1, $out['rules'] );
		$this->assertStringContainsString( 'Line 2', $out['errors'][0] );
		$this->assertStringContainsString( 'already used', $out['errors'][0] );
	}

	/**
	 * The same form cannot be described twice.
	 */
	public function test_duplicate_form_is_refused(): void {
		$out = WebMCP_Forms::parse( "gf:1 | first | One.\ngf:1 | second | Two." );

		$this->assertSame( 'first', $out['rules']['gf:1']['name'] );
		$this->assertStringContainsString( 'already described', $out['errors'][0] );
	}

	/**
	 * Markup in a description is stripped and a long description is capped.
	 */
	public function test_description_is_cleaned_and_capped(): void {
		$long = str_repeat( 'word ', 200 );
		$out  = WebMCP_Forms::parse( "gf:4 | longOne | <em>Quote</em>   {$long}" );

		$description = $out['rules']['gf:4']['description'];
		$this->assertStringStartsWith( 'Quote word', $description );
		$this->assertLessThanOrEqual( WebMCP_Forms::DESCRIPTION_MAX, mb_strlen( $description ) );
	}
}
