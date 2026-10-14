<?php
/**
 * Config — the single source of every value this plugin uses.
 *
 * WHY THIS CLASS IS THE CENTRE OF THE PLUGIN
 *
 * The standing rule is that a core plugin must have a settings page editing
 * EVERYTHING it does, and that features read a Config rather than literals. That rule had
 * been written down for months, and on 2026-08-20 it was
 * broken again in a live client core plugin. A rule that keeps being broken is not a rule
 * that people keep forgetting — it is a rule with no gate.
 *
 * So this is not a helper that features MAY use. It is the only place a value can exist, and
 * the shape of it makes the admin screen a consequence rather than a chore:
 *
 *   1. Every value is DECLARED once in schema(), with its label, type, group and default.
 *   2. Admin\Settings RENDERS ITSELF from that schema. Adding a key to the schema puts a
 *      field on the settings page automatically — there is no second place to update, so
 *      the two can never drift.
 *   3. Features call Config::get( 'business.phone' ). They never see an option name, never
 *      see a default, and have nowhere to put a literal.
 *   4. `bin/check-hardcoded.mjs` fails the build when a feature class contains a literal
 *      that looks like content — a phone number, an email, a URL, a street address.
 *
 * The chain matters: 1 makes it declarative, 2 makes it editable for free, 3 removes the
 * temptation, and 4 is the gate. Without 4 this is documentation, and documentation is
 * exactly what failed.
 *
 * ADDING A VALUE
 *
 * Add one entry to schema(). That is the whole task — the field appears in wp-admin, the
 * default applies on a fresh install, and the checker stops complaining about the literal
 * you were about to write.
 *
 * @package AJR\StarterCore
 */

declare( strict_types=1 );

namespace AJR\StarterCore\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Declarative settings store.
 *
 * A non-technical reader should be able to answer "what can this site's owner change?" by
 * reading schema() alone, without opening any other file.
 */
class Config {

	/**
	 * The wp_options key holding every setting as one array.
	 *
	 * ONE row, not one row per setting. The WordPress performance guidance this follows caps
	 * wp_options at ~500 rows and warns against autoloading anything not needed on every
	 * request; a plugin that writes a row per field is how that limit gets eaten. One
	 * autoloaded array is a single cheap read.
	 */
	protected const OPTION = 'ajr_starter_core_settings';

	/**
	 * Runtime cache of the merged settings array.
	 *
	 * @var array<string,mixed>|null
	 */
	protected static ?array $cache = null;

	/**
	 * Every value this plugin uses, declared once.
	 *
	 * Keys are dot-notated `group.key`. The group becomes a section on the settings page,
	 * so the admin UI's organisation is derived from the same declaration rather than
	 * maintained separately.
	 *
	 * Supported types: text, textarea, url, email, tel, number, checkbox, select, colour.
	 * `options` is required for select and ignored otherwise. `rows` sizes a textarea.
	 *
	 * `pattern` is an optional PCRE the value must match to be stored. Use it on anything
	 * with a known format — a measurement ID, a container ID. It is worth more than the
	 * escaping downstream: a value that cannot contain a quote, an angle bracket or a slash
	 * cannot break out of anything it is later printed into, whatever tool prints it. It
	 * also catches the failure that actually happens, which is someone pasting a whole
	 * <script> tag into a field that wanted an ID.
	 *
	 * ⛔ Everything here is translatable at the LABEL level only. Do not wrap a DEFAULT in a
	 * translation function: defaults are stored to the database on first save, so a
	 * translated default silently freezes whichever language was active at that moment.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function schema(): array {
		return [

			/*
			---- Business identity — used by Schema\Business_Profile and any pattern
			 * that needs to state who the client is. This is the group that most often
			 * gets hardcoded, because it feels stable. It is not: firms rebrand, move
			 * office, and change phone provider. ----
			 */
			'business.name'                    => [
				'group'   => 'business',
				'label'   => 'Business name',
				'type'    => 'text',
				'default' => '',
				'help'    => 'Used in structured data and anywhere the site states its own name. Leave blank to fall back to the WordPress Site Title.',
			],
			'business.legal_name'              => [
				'group'   => 'business',
				'label'   => 'Registered legal name',
				'type'    => 'text',
				'default' => '',
				'help'    => 'Only if it differs from the trading name — e.g. "Example Ltd" vs "Example".',
			],
			'business.phone'                   => [
				'group'   => 'business',
				'label'   => 'Phone number',
				'type'    => 'tel',
				'default' => '',
				'help'    => 'Displayed number. Used for tel: links and structured data.',
			],
			'business.email'                   => [
				'group'   => 'business',
				'label'   => 'Public email address',
				'type'    => 'email',
				'default' => '',
			],
			'business.street'                  => [
				'group'   => 'business',
				'label'   => 'Street address',
				'type'    => 'text',
				'default' => '',
			],
			'business.locality'                => [
				'group'   => 'business',
				'label'   => 'Town / city',
				'type'    => 'text',
				'default' => '',
			],
			'business.region'                  => [
				'group'   => 'business',
				'label'   => 'County / state',
				'type'    => 'text',
				'default' => '',
			],
			'business.postcode'                => [
				'group'   => 'business',
				'label'   => 'Postcode / ZIP',
				'type'    => 'text',
				'default' => '',
			],
			'business.country'                 => [
				'group'   => 'business',
				'label'   => 'Country code',
				'type'    => 'text',
				'default' => '',
				'help'    => 'Two letters, e.g. GB or US.',
			],

			/*
			---- Structured data. Off by default: emitting an incomplete profile is worse
			 * than emitting none, and a fresh install has no address yet. ----
			 */
			'schema.enabled'                   => [
				'group'   => 'schema',
				'label'   => 'Output business structured data',
				'type'    => 'checkbox',
				'default' => false,
				'help'    => 'Adds a LocalBusiness/Organization JSON-LD block to the site. Fill in the business details above first — an incomplete profile is worse than none.',
			],
			'schema.type'                      => [
				'group'   => 'schema',
				'label'   => 'Business type',
				'type'    => 'select',
				'default' => 'LocalBusiness',
				'options' => [
					'LocalBusiness'       => 'Local business (default)',
					'Organization'        => 'Organization (no physical premises)',
					'ProfessionalService' => 'Professional service',
					'Store'               => 'Shop / store',
				],
				'help'    => 'Pick the closest schema.org type. Wrong is worse than generic.',
			],

			/*
			---- Post type. The LABELS are settings because "Case Study" is the client's
			 * word for their own content, and renaming it should not need a developer.
			 * Enabled is a setting because a scaffold that force-registers a CPT leaves
			 * orphan content on every site that did not want one. ----
			 */
			'cpt.enabled'                      => [
				'group'   => 'cpt',
				'label'   => 'Enable the custom post type',
				'type'    => 'checkbox',
				'default' => false,
			],
			'cpt.slug'                         => [
				'group'   => 'cpt',
				'label'   => 'URL slug',
				'type'    => 'text',
				'default' => 'case-study',
				'help'    => 'Appears in the address bar. Changing it after launch breaks existing links unless you add redirects.',
			],
			'cpt.singular'                     => [
				'group'   => 'cpt',
				'label'   => 'Name (singular)',
				'type'    => 'text',
				'default' => 'Case Study',
			],
			'cpt.plural'                       => [
				'group'   => 'cpt',
				'label'   => 'Name (plural)',
				'type'    => 'text',
				'default' => 'Case Studies',
			],
			'cpt.has_archive'                  => [
				'group'   => 'cpt',
				'label'   => 'Give it an archive page',
				'type'    => 'checkbox',
				'default' => true,
			],

			/*
			---- Redirects. ⛔ THIS KEY EXISTS BECAUSE THE MODULE FAILED THE PLUGIN'S OWN
			 * RULE. Until 2026-08-20 Seo\Redirects owned a private option, so the redirect
			 * map — the deliverable that protects a client's rankings on a migration —
			 * could only be populated by WP-CLI or a direct database write. Two independent
			 * reviews found it, and it is exactly the defect this Config was built to make
			 * impossible. Declaring it here is the fix: the textarea appears for free. ----
			 */
			'redirects.map'                    => [
				'group'   => 'redirects',
				'label'   => 'Redirect rules',
				'type'    => 'textarea',
				'default' => '',
				'rows'    => 12,
				'help'    => 'One rule per line, old address first: <code>/old-page  /new-page</code>. Add <code>302</code> at the end for a temporary move; the default is a permanent 301. Lines starting with # are ignored. Anything that cannot be read is listed below the box rather than silently dropped.',
			],

			/*
			---- Site behaviour. Small switches that every build wants and that would
			 * otherwise each arrive as a plugin. ----
			 */
			'comments.disable'                 => [
				'group'   => 'site',
				'label'   => 'Turn off comments completely',
				'type'    => 'checkbox',
				'default' => true,
				'help'    => 'Closes comments on all content, hides the admin menu, and stops the comment feeds and REST routes answering. ⛔ On an existing site, switch this ON and confirm before deactivating any Disable Comments plugin — doing it the other way round leaves comment forms live for a window, and spam bots find a WordPress site quickly.',
			],

			/*
			---- Google. The point of this group is to delete a plugin and empty the
			 * header. Site Kit, a GTM plugin and a "custom code" snippet all end up doing
			 * what these six fields do, while costing a plugin update cycle each and
			 * hiding the tag somewhere nobody can find it at 11pm. Paste the ID here and
			 * the tag is emitted correctly, or leave it blank and nothing is emitted. ----
			 */
			'google.ga4_id'                    => [
				'group'   => 'google',
				'label'   => 'GA4 Measurement ID',
				'type'    => 'text',
				'default' => '',
				'pattern' => '/^G-[A-Z0-9]{4,}$/i',
				'help'    => 'Looks like G-XXXXXXXXXX. Leave blank to output no analytics at all. Found in Google Analytics under Admin → Data streams.',
			],
			'google.gtm_id'                    => [
				'group'   => 'google',
				'label'   => 'Tag Manager container ID',
				'type'    => 'text',
				'default' => '',
				'pattern' => '/^GTM-[A-Z0-9]{4,}$/i',
				'help'    => 'Looks like GTM-XXXXXXX. If you fill this in, put GA4 inside Tag Manager rather than also filling in the GA4 field above — running both loads Analytics twice and doubles your traffic numbers.',
			],
			'google.site_verification'         => [
				'group'   => 'google',
				'label'   => 'Search Console verification code',
				'type'    => 'text',
				'default' => '',
				'pattern' => '/^[A-Za-z0-9_-]{20,}$/',
				'help'    => 'The content value from Google\'s HTML tag method — the long string only, not the whole tag. Only needed until verification completes; harmless to leave in place.',
			],
			'google.ads_conversion_id'         => [
				'group'   => 'google',
				'label'   => 'Google Ads conversion ID',
				'type'    => 'text',
				'default' => '',
				'pattern' => '/^AW-[0-9]{6,}$/i',
				'help'    => 'Looks like AW-000000000. Only needed if you run Ads and are not already handling conversions inside Tag Manager.',
			],
			'google.exclude_logged_in'         => [
				'group'   => 'google',
				'label'   => 'Do not track logged-in users',
				'type'    => 'checkbox',
				'default' => true,
				'help'    => 'Strongly recommended, and on by default for a measured reason: on one site roughly HALF of all recorded conversions turned out to be the site\'s own team browsing. Excluding logged-in users is the cheapest correction available.',
			],
			'google.defer_until_interaction'   => [
				'group'   => 'google',
				'label'   => 'Delay tag loading until the visitor interacts',
				'type'    => 'checkbox',
				'default' => false,
				'help'    => 'Holds Tag Manager (or gtag.js) until the first scroll, tap, key or pointer move. Measured on a real build: mobile 67 to 95, desktop 84 to 96, FCP 4.4s to 1.6s. Conversions are unaffected because a form submit, click-to-call or click-to-SMS always follows an interaction, and events queued before the tags load are processed when they arrive. Traffic carrying gclid/msclkid/utm_ loads immediately regardless, so paid attribution is never delayed. What you lose is the pageview from a session with no interaction at all: instant bounces and bots. Leave OFF until you have verified tracking on the wire for this site.',
			],

			'google.consent_mode'              => [
				'group'   => 'google',
				'label'   => 'Start with consent denied (Consent Mode v2)',
				'type'    => 'checkbox',
				'default' => true,
				'help'    => 'Sets analytics and ad storage to denied until your cookie banner grants them. Required in the UK/EU. Leave on unless a consent plugin is already issuing these commands itself — two sources of consent state fight each other.',
			],

			/*
			---- AI agents. WebMCP attributes make the site's forms usable by AI agents in
			 * Chrome; browsers without WebMCP ignore them, so the defaults are ON for search
			 * and empty for lead forms, which need a human-written description. ----
			 */
			'agents.webmcp_search'             => [
				'group'   => 'agents',
				'label'   => 'Let AI agents use the site search',
				'type'    => 'checkbox',
				'default' => true,
				'help'    => 'Marks the Search block as a WebMCP tool so an AI agent can search the site for a visitor. Invisible to people and to browsers without WebMCP.',
			],
			'agents.webmcp_search_name'        => [
				'group'   => 'agents',
				'label'   => 'Search tool name',
				'type'    => 'text',
				'default' => 'searchSite',
				'pattern' => '/^[A-Za-z][A-Za-z0-9_]{2,63}$/',
				'help'    => 'Letters, numbers and underscores only, starting with a letter, e.g. <code>searchSite</code>.',
			],
			'agents.webmcp_search_description' => [
				'group'   => 'agents',
				'label'   => 'Search tool description',
				'type'    => 'text',
				'default' => 'Search this website by keyword and list the matching pages.',
				'help'    => 'One sentence an agent reads to decide when to use search.',
			],
			'agents.webmcp_search_autosubmit'  => [
				'group'   => 'agents',
				'label'   => 'Allow agents to run a search without the visitor pressing Search',
				'type'    => 'checkbox',
				'default' => true,
				'help'    => 'Safe for search because it changes nothing. Lead forms below never get this — the visitor always presses submit.',
			],
			'agents.webmcp_forms'              => [
				'group'   => 'agents',
				'label'   => 'Forms agents can fill in',
				'type'    => 'textarea',
				'default' => '',
				'rows'    => 5,
				'help'    => 'One form per line: <code>ajr:contact | sendMessage | Send a message to the team.</code> (an AJR Forms form key) or <code>gf:3 | requestQuote | Request a free quote.</code> (a Gravity Forms ID), then a tool name and one sentence saying what the form does. Field descriptions come from the form\'s own labels. Lines starting with # are ignored; anything that cannot be read is listed below the box.',
			],

			/*
			---- Media. The srcset ladder lives in the plugin rather than the theme — a
			 * proven win on a full rebuild — because image sizes must survive a theme
			 * change, and regenerating thumbnails on a live site is expensive. ----
			 */
			'media.extra_sizes'                => [
				'group'   => 'media',
				'label'   => 'Register the extra responsive image sizes',
				'type'    => 'checkbox',
				'default' => true,
				'help'    => 'Fills the gaps in WordPress core\'s default ladder so the browser can pick a closer match. Existing images need Regenerate Thumbnails after enabling.',
			],
		];
	}

	/**
	 * Read a single value.
	 *
	 * @param string $key     Dot-notated key from schema().
	 * @param mixed  $fallback Returned only when the key is not in the schema at all.
	 * @return mixed
	 */
	public static function get( string $key, mixed $fallback = null ): mixed {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Every setting, saved values merged over declared defaults.
	 *
	 * Merging on read rather than on save is deliberate: a key added to the schema in a
	 * later release then applies immediately to sites that saved their settings before it
	 * existed, with no migration step. The alternative — writing defaults at activation —
	 * silently leaves older installs missing the new key.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$defaults = [];
		foreach ( self::schema() as $key => $field ) {
			$defaults[ $key ] = $field['default'] ?? '';
		}

		$saved = get_option( self::OPTION, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		// Only keys the schema knows about. A stale key left by a removed feature must not
		// resurface as a live value.
		$saved = array_intersect_key( $saved, $defaults );

		self::$cache = array_merge( $defaults, $saved );

		return self::$cache;
	}

	/**
	 * Persist a full settings array. Called only by Admin\Settings after sanitisation.
	 *
	 * @param array<string,mixed> $values Sanitised values keyed as in schema().
	 */
	public static function save( array $values ): void {
		/*
		 * ⛔ The autoload flag is STATED, never left to the default — and here it is
		 * deliberately true. Every feature reads Config on the front end, so this option
		 * genuinely is needed on every request and autoloading it is the cheap outcome.
		 *
		 * The flag is explicit because the right answer is not always true, and the call site
		 * gives no hint either way. An option that GROWS — a redirect map, a log, a cache —
		 * left autoloaded is a permanent per-request cost that nothing anywhere reports; on
		 * one client build the equivalent store reached 537KB. The deploy gate now fails a two-argument
		 * update_option() for exactly that reason, and this is the scaffold every build is
		 * forked from, so getting it right here is what stops it recurring.
		 */
		update_option( self::OPTION, $values, true );
		self::$cache = null;
	}

	/**
	 * The option name, for the Settings API registration only.
	 */
	public static function option_name(): string {
		return self::OPTION;
	}

	/**
	 * True when a value is present and non-empty.
	 *
	 * Features should gate on this rather than testing the value themselves, because an
	 * empty setting is not merely falsy — it is a render site that is switched off. Filling
	 * in a blank Site Title once made a firm's name appear twice in a live header, because
	 * two dormant `wp:site-title` blocks woke up at once.
	 *
	 * @param string $key Dot-notated key from schema().
	 * @return bool
	 */
	public static function filled( string $key ): bool {
		$value = self::get( $key );
		return is_bool( $value ) ? $value : ( '' !== trim( (string) $value ) );
	}
}
