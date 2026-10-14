<?php
/**
 * WebMCP_Forms — let AI agents recognise and use the site's forms.
 *
 * WHAT WEBMCP IS
 *
 * WebMCP is Chrome's standard for exposing a page's actions to AI agents. Its declarative
 * form is two HTML attributes on a <form>: `toolname` (a unique identifier) and
 * `tooldescription` (what the form does, in plain language). The browser turns the form into
 * a tool, derives the input schema from the fields — `required`, input types, <select>
 * options — and reads each field's description from its <label>. When an agent calls the
 * tool, the browser focuses the form and fills it in front of the visitor.
 *
 * Browsers without WebMCP ignore unknown attributes, so the form is unchanged for everyone
 * else. That is why this costs nothing to ship on every build.
 *
 * WHY IT IS IN THE STARTER KIT
 *
 * Lighthouse's Agentic Browsing category audits WebMCP form coverage, schema validity and
 * registered tools. Those audits are informational today and will not stay that way. A quote
 * form an agent can use is the conversion path for a visitor who arrives through an AI
 * assistant — on one client site that was already the best-converting source by
 * September 2026.
 *
 * WHAT IT ANNOTATES
 *
 *   1. The core Search block, when agents.webmcp_search is on. Search is read-only, so it
 *      also gets `toolautosubmit` when agents.webmcp_search_autosubmit is on.
 *   2. Forms named in the agents.webmcp_forms rules, one per line — the framework's own AJR Forms
 *      block by its form key, or a Gravity Form by ID:
 *        ajr:contact | sendMessage | Send a message to the team.
 *        gf:3 | requestQuote | Request a free quote.
 *      Required fields get the HTML `required` attribute (both plugins print only aria-required
 *      with `novalidate`), and the AJR Forms honeypot is described to agents as "leave empty".
 *
 * ⛔ LEAD FORMS ARE NEVER AUTO-SUBMITTED
 *
 * `toolautosubmit` lets an agent submit without the visitor reviewing the filled form. Google's
 * own guidance limits it to searches and low-risk reversible actions. A quote request sends a
 * person's contact details to a business, so the visitor always presses submit. There is no
 * setting to change that, on purpose.
 *
 * TO REMOVE THIS MODULE: delete this directory, delete its line in Core\Plugin::modules(),
 * and delete the `agents.*` keys from Core\Config::schema().
 *
 * @package AJR\StarterCore
 */

declare( strict_types=1 );

namespace AJR\StarterCore\Agents;

use AJR\StarterCore\Core\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Adds WebMCP declarative attributes to search and configured forms.
 */
class WebMCP_Forms {

	/**
	 * A tool name: starts with a letter, then letters, digits or underscores, 3–64 long.
	 *
	 * Deliberately narrower than anything a browser might accept. A name that cannot contain
	 * a space, quote or angle bracket cannot break out of the attribute it is printed into,
	 * and camelCase names are what agents are trained on.
	 */
	public const NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{2,63}$/';

	/**
	 * Longest description kept. Agents choose tools by reading these; a paragraph dilutes it.
	 */
	public const DESCRIPTION_MAX = 300;

	/**
	 * Per-request memo of the parsed rules.
	 *
	 * @var array{rules:array<string,array{name:string,description:string}>,errors:array<int,string>}|null
	 */
	protected static ?array $memo = null;

	/**
	 * Whether a search form has already been given the tool name on this request.
	 *
	 * @var bool
	 */
	protected bool $search_annotated = false;

	/**
	 * Register hooks — only for the parts that are switched on and configured.
	 */
	public function register(): void {
		if ( Config::get( 'agents.webmcp_search' ) ) {
			add_filter( 'render_block_core/search', [ $this, 'annotate_search' ], 10, 1 );
		}

		if ( Config::filled( 'agents.webmcp_forms' ) ) {
			add_filter( 'render_block_ajr-forms/form', [ $this, 'annotate_ajr_form' ], 10, 2 );
			add_filter( 'gform_form_tag', [ $this, 'annotate_gravity_form' ], 10, 2 );
			add_filter( 'gform_field_content', [ $this, 'mark_required_fields' ], 10, 5 );
		}
	}

	/**
	 * Add the HTML `required` attribute to a required Gravity Forms field.
	 *
	 * WebMCP reads "required" from the `required` attribute when it builds the tool's input
	 * schema. Gravity Forms prints only `aria-required="true"` and puts `novalidate` on the
	 * form, so without this every field — name and email included — reaches an agent as
	 * optional. Measured on a live quote form, 2026-09-15.
	 *
	 * Safe for visitors: `novalidate` stops the browser's own validation UI and Gravity Forms
	 * still validates on the server. Only forms named in the rules are touched, only fields
	 * Gravity Forms itself marks aria-required, and never hidden inputs.
	 *
	 * @param mixed $content Field markup.
	 * @param mixed $field   GF_Field object.
	 * @param mixed $value   Field value (unused).
	 * @param mixed $lead_id Entry ID — non-zero when rendering an entry, not a form.
	 * @param mixed $form_id Form ID.
	 * @return mixed
	 */
	public function mark_required_fields( mixed $content, mixed $field, mixed $value = '', mixed $lead_id = 0, mixed $form_id = 0 ): mixed {
		unset( $value );

		if ( ! is_string( $content ) || 0 !== (int) $lead_id || ! is_object( $field ) || empty( $field->isRequired ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Gravity Forms property.
			return $content;
		}

		if ( ! isset( self::rules()[ 'gf:' . (int) $form_id ] ) ) {
			return $content;
		}

		return self::mark_required( $content );
	}

	/**
	 * Mirror aria-required="true" as the HTML `required` attribute on real form controls.
	 *
	 * Only what the form plugin itself marks required, never hidden, submit or button inputs.
	 * Shared by the Gravity Forms field filter and the AJR Forms block filter.
	 *
	 * @param string $content Markup containing form controls.
	 * @return string
	 */
	public static function mark_required( string $content ): string {
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return $content;
		}

		$tags    = new \WP_HTML_Tag_Processor( $content );
		$changed = false;

		while ( $tags->next_tag() ) {
			$tag = $tags->get_tag();
			if ( ! in_array( $tag, [ 'INPUT', 'TEXTAREA', 'SELECT' ], true ) ) {
				continue;
			}
			$type = strtolower( (string) $tags->get_attribute( 'type' ) );
			if ( 'INPUT' === $tag && in_array( $type, [ 'hidden', 'submit', 'button', 'image', 'reset' ], true ) ) {
				continue;
			}
			if ( 'true' !== $tags->get_attribute( 'aria-required' ) ) {
				continue;   // mirror only what the form plugin itself marks required
			}
			$tags->set_attribute( 'required', true );
			$changed = true;
		}

		return $changed ? $tags->get_updated_html() : $content;
	}

	/**
	 * Parse the rules text into form annotations.
	 *
	 * Lines that cannot be used come back in `errors` with their line number, so the settings
	 * screen shows the client exactly what was ignored — the same contract as the redirect
	 * map, and for the same reason: a parser that drops lines silently produces a
	 * configuration that reads as complete and is not.
	 *
	 * @param string|null $text Raw rules, or null to read the stored value.
	 * @return array{rules:array<string,array{name:string,description:string}>,errors:array<int,string>}
	 */
	public static function parse( ?string $text = null ): array {
		$text   = null === $text ? (string) Config::get( 'agents.webmcp_forms', '' ) : $text;
		$rules  = [];
		$errors = [];
		$names  = [];

		// The search tool's name is on the same page as every form, so it is taken already.
		if ( Config::get( 'agents.webmcp_search' ) ) {
			$names[ strtolower( (string) Config::get( 'agents.webmcp_search_name', '' ) ) ] = true;
		}

		$lines = preg_split( '/\r\n|\r|\n/', $text );
		if ( ! is_array( $lines ) ) {
			$lines = [];
		}

		foreach ( $lines as $i => $line ) {
			$line = trim( $line );

			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}

			$parts = array_map( 'trim', explode( '|', $line, 3 ) );

			if ( count( $parts ) < 3 || '' === $parts[2] ) {
				$errors[] = sprintf(
					/* translators: 1: the line number, 2: the value from that line that was not accepted. */
					__( 'Line %1$d needs three parts — form | tool name | description: %2$s', 'ajr-starter-core' ),
					$i + 1,
					$line
				);
				continue;
			}

			[ $form, $name, $description ] = $parts;

			$key = self::form_reference( $form );
			if ( null === $key ) {
				$errors[] = sprintf(
					/* translators: 1: the line number, 2: the value from that line that was not accepted. */
					__( 'Line %1$d: "%2$s" is not a form reference. Use ajr: and the AJR Forms form key (ajr:contact), or gf: and the Gravity Forms ID (gf:3).', 'ajr-starter-core' ),
					$i + 1,
					$form
				);
				continue;
			}

			if ( ! preg_match( self::NAME_PATTERN, $name ) ) {
				$errors[] = sprintf(
					/* translators: 1: the line number, 2: the value from that line that was not accepted. */
					__( 'Line %1$d: tool name "%2$s" must start with a letter and use only letters, numbers or underscores (3–64 characters), e.g. requestQuote.', 'ajr-starter-core' ),
					$i + 1,
					$name
				);
				continue;
			}

			if ( isset( $names[ strtolower( $name ) ] ) ) {
				$errors[] = sprintf(
					/* translators: 1: the line number, 2: the value from that line that was not accepted. */
					__( 'Line %1$d: tool name "%2$s" is already used — every tool on a page needs its own name.', 'ajr-starter-core' ),
					$i + 1,
					$name
				);
				continue;
			}

			if ( isset( $rules[ $key ] ) ) {
				$errors[] = sprintf(
					/* translators: 1: the line number, 2: the value from that line that was not accepted. */
					__( 'Line %1$d: %2$s is already described on an earlier line.', 'ajr-starter-core' ),
					$i + 1,
					$key
				);
				continue;
			}

			$description = self::clean_description( $description );
			if ( '' === $description ) {
				$errors[] = sprintf(
					/* translators: %d: the line number. */
					__( 'Line %d has an empty description.', 'ajr-starter-core' ),
					$i + 1
				);
				continue;
			}

			$names[ strtolower( $name ) ] = true;
			$rules[ $key ]                = [
				'name'        => $name,
				'description' => $description,
			];
		}

		return [
			'rules'  => $rules,
			'errors' => $errors,
		];
	}

	/**
	 * The parsed rules for this request.
	 *
	 * @return array<string,array{name:string,description:string}>
	 */
	public static function rules(): array {
		if ( null === self::$memo ) {
			self::$memo = self::parse();
		}
		return self::$memo['rules'];
	}

	/**
	 * Add the search tool attributes to a rendered core/search block.
	 *
	 * @param string $html Rendered block HTML.
	 * @return string
	 */
	public function annotate_search( string $html ): string {
		// One tool per name per page: a second Search block (e.g. header + 404 body) stays plain.
		if ( $this->search_annotated ) {
			return $html;
		}

		$name        = (string) Config::get( 'agents.webmcp_search_name', '' );
		$description = self::clean_description( (string) Config::get( 'agents.webmcp_search_description', '' ) );

		// A blank description must not silently switch search off: use the declared default.
		if ( '' === $description ) {
			$description = self::clean_description( (string) ( Config::schema()['agents.webmcp_search_description']['default'] ?? '' ) );
		}

		if ( ! preg_match( self::NAME_PATTERN, $name ) || '' === $description ) {
			return $html;
		}

		$this->search_annotated = true;

		return self::add_tool_attributes( $html, $name, $description, (bool) Config::get( 'agents.webmcp_search_autosubmit' ) );
	}

	/**
	 * Normalise a form reference: `ajr:<formKey>` or `gf:<id>`, or null when it is neither.
	 *
	 * AJR Forms keys are lowercase letters, digits and hyphens; comparing lowercase means
	 * "ajr:Contact" in the settings box still matches the form the editor saved as "contact".
	 *
	 * @param string $form Raw reference from the rules text.
	 * @return string|null
	 */
	public static function form_reference( string $form ): ?string {
		if ( preg_match( '/^ajr:([a-z0-9][a-z0-9-]{0,63})$/i', $form, $m ) ) {
			return 'ajr:' . strtolower( $m[1] );
		}
		if ( preg_match( '/^gf:(\d+)$/i', $form, $m ) ) {
			return 'gf:' . (int) $m[1];
		}
		return null;
	}

	/**
	 * Add tool attributes, `required` and the honeypot hint to an AJR Forms Form block in the rules.
	 *
	 * AJR Forms prints aria-required plus `novalidate` (its own error summary replaces the browser's),
	 * so, as with Gravity Forms, every field would reach an agent as optional. Radio groups already
	 * carry native `required` from AJR Forms 0.2.0. A sent form renders its confirmation without a
	 * <form>, and is left alone.
	 *
	 * @param mixed               $html  Rendered block HTML.
	 * @param array<string,mixed> $block Parsed block.
	 * @return mixed
	 */
	public function annotate_ajr_form( mixed $html, array $block ): mixed {
		if ( ! is_string( $html ) || ! str_contains( $html, '<form' ) ) {
			return $html;
		}

		$key  = self::form_reference( 'ajr:' . (string) ( $block['attrs']['formKey'] ?? '' ) );
		$rule = null === $key ? null : ( self::rules()[ $key ] ?? null );
		if ( null === $rule ) {
			return $html;
		}

		// Never autosubmit a lead form — see the class docblock.
		return self::describe_honeypot( self::mark_required( self::add_tool_attributes( $html, $rule['name'], $rule['description'], false ) ) );
	}

	/**
	 * Tell agents, in the tool schema, that the AJR Forms spam trap must stay empty.
	 *
	 * The browser builds the input schema from every control, and the honeypot (`ajr_hp`, hidden from
	 * people) is one. An agent that fills it gets the submission discarded as spam.
	 * `toolparamdescription` is the declarative API's per-field description and overrides the label.
	 *
	 * @param string $html Rendered form HTML.
	 * @return string
	 */
	public static function describe_honeypot( string $html ): string {
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$tags = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( [ 'tag_name' => 'input' ] ) ) {
			if ( 'ajr_hp' === $tags->get_attribute( 'name' ) ) {
				$tags->set_attribute( 'toolparamdescription', 'Spam check. Always leave this empty; any value discards the whole submission.' );
				return $tags->get_updated_html();
			}
		}

		return $html;
	}

	/**
	 * Add tool attributes to a Gravity Forms <form> tag named in the rules.
	 *
	 * @param mixed $form_tag The opening <form> tag Gravity Forms is about to print.
	 * @param mixed $form     The Gravity Forms form array.
	 * @return mixed The annotated tag, or the input unchanged if it was not a string.
	 */
	public function annotate_gravity_form( mixed $form_tag, mixed $form ): mixed {
		// Another plugin's gform_form_tag filter may hand back a non-string; never fatal a page over it.
		if ( ! is_string( $form_tag ) ) {
			return $form_tag;
		}

		$id  = is_array( $form ) && isset( $form['id'] ) ? (int) $form['id'] : 0;
		$key = 'gf:' . $id;

		$rules = self::rules();
		if ( 0 === $id || ! isset( $rules[ $key ] ) ) {
			return $form_tag;
		}

		// Never autosubmit a lead form — see the class docblock.
		return self::add_tool_attributes( $form_tag, $rules[ $key ]['name'], $rules[ $key ]['description'], false );
	}

	/**
	 * Set toolname / tooldescription (and optionally toolautosubmit) on the first <form>.
	 *
	 * Uses WP_HTML_Tag_Processor rather than string replacement, so attribute values are
	 * escaped by core and an existing attribute is updated rather than duplicated.
	 *
	 * @param string $html        HTML containing a <form> opening tag.
	 * @param string $name        Validated tool name.
	 * @param string $description Cleaned description.
	 * @param bool   $autosubmit  Whether the agent may submit without review.
	 * @return string
	 */
	public static function add_tool_attributes( string $html, string $name, string $description, bool $autosubmit ): string {
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return $html;   // WordPress < 6.2: leave the form exactly as it was
		}

		$tags = new \WP_HTML_Tag_Processor( $html );
		if ( ! $tags->next_tag( [ 'tag_name' => 'form' ] ) ) {
			return $html;
		}

		$tags->set_attribute( 'toolname', $name );
		$tags->set_attribute( 'tooldescription', $description );

		if ( $autosubmit ) {
			$tags->set_attribute( 'toolautosubmit', true );
		} else {
			$tags->remove_attribute( 'toolautosubmit' );
		}

		return $tags->get_updated_html();
	}

	/**
	 * Plain text, one line, capped.
	 *
	 * @param string $text Raw description.
	 * @return string
	 */
	protected static function clean_description( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );

		if ( mb_strlen( $text ) > self::DESCRIPTION_MAX ) {
			$text = rtrim( mb_substr( $text, 0, self::DESCRIPTION_MAX - 1 ) ) . '…';
		}

		return $text;
	}
}
