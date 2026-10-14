<?php
/**
 * Redirects — the site's redirect map, owned by the core plugin.
 *
 * WHY THIS IS IN CORE AND NOT A PLUGIN
 *
 * The standing position after a large rebuild: every redirect on a site should be owned
 * by that site's core plugin. Auditing what was actually running there exposed that a set of
 * them had been pointing BACKWARDS — sending live URLs to dead ones — and nothing reported
 * it, because a 301 to a 404 is still a working 301. Redirects spread across a plugin, a
 * .htaccess block and a theme snippet cannot be audited at all; in one file they can.
 *
 * On a rebuild this is the module that earns its place. A migration is a redirect map, and
 * the map is the deliverable that protects the client's rankings.
 *
 * THE FORMAT
 *
 * One rule per line, source first:
 *
 *     /old-page              /new-page
 *     /old-page/             /new-page          302
 *     /category/thing        https://example.com/thing
 *
 * Source must be a path on this site. Target may be a path or an absolute URL. The status
 * defaults to 301; use 302 only while something is genuinely temporary, because browsers
 * cache a 301 hard and a wrong one is expensive to undo.
 *
 * ⛔ THREE GUARDS, EACH FROM A REAL FAILURE
 *
 *   1. A rule whose source and target resolve to the same place is DISCARDED, not obeyed.
 *      That is an infinite redirect, and the browser reports it as the site being down.
 *   2. Matching ignores the trailing slash and the query string, so /thing, /thing/ and
 *      /thing?utm=x all match one rule. Writing three rules by hand is how a map drifts.
 *   3. Rules are checked BEFORE WordPress resolves the request, so a redirect works even
 *      when a page with the old slug still exists — which is exactly the case during a
 *      rebuild, and exactly when a redirect that "does nothing" wastes an afternoon.
 *
 * @package AJR\StarterCore
 */

declare( strict_types=1 );

namespace AJR\StarterCore\Seo;

use AJR\StarterCore\Core\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Path-based redirect map.
 */
class Redirects {

	/**
	 * Cache group for the parsed rule table.
	 *
	 * ⛔ The raw text lives in Core\Config under `redirects.map`, NOT in a private option.
	 * It had its own option until 2026-08-20, and the consequence was that the redirect map
	 * — the deliverable that protects a client's rankings on a migration — had no admin
	 * field and could only be written by WP-CLI. Two independent reviews found it. Any value
	 * this plugin uses goes in the schema; that is the whole point of the schema.
	 */
	protected const CACHE_GROUP = 'ajr-redirects';

	/**
	 * Register hooks.
	 *
	 * Priority 1 on `template_redirect` — before WordPress decides what to render, and
	 * before any canonical redirect core might apply.
	 */
	public function register(): void {
		add_action( 'template_redirect', [ $this, 'maybe_redirect' ], 1 );
	}

	/**
	 * The raw text as the client typed it.
	 *
	 * Stored as text rather than a parsed array on purpose: the client's input is the
	 * source of truth, so a line that fails to parse can be shown back to them exactly as
	 * they typed it. A parsed-and-stored array silently loses whatever it could not read.
	 */
	public static function raw(): string {
		return (string) Config::get( 'redirects.map', '' );
	}

	/**
	 * Parse the raw text into usable rules.
	 *
	 * Invalid lines are returned alongside valid ones so the settings screen can show what
	 * was ignored. A parser that silently drops what it cannot read is how a redirect map
	 * ends up with holes nobody knows about.
	 *
	 * @param string|null $text Raw map text, or null to read the stored value.
	 * @return array{rules:array<string,array{target:string,status:int}>,errors:array<int,string>}
	 */
	public static function parse( ?string $text = null ): array {
		$text   = null === $text ? self::raw() : $text;
		$rules  = [];
		$errors = [];

		$lines = preg_split( '/\r\n|\r|\n/', $text );
		if ( ! is_array( $lines ) ) {
			$lines = [];
		}

		foreach ( $lines as $i => $line ) {
			$line = trim( $line );

			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;   // blank lines and comments
			}

			$parts = preg_split( '/[\s\t]+|\s*(?:->|→|,)\s*/', $line, -1, PREG_SPLIT_NO_EMPTY );
			if ( ! $parts || count( $parts ) < 2 ) {
				$errors[] = sprintf( 'Line %d needs a source and a target: %s', $i + 1, $line );
				continue;
			}

			$source = self::normalise( (string) $parts[0] );
			$target = (string) $parts[1];
			$status = isset( $parts[2] ) ? (int) $parts[2] : 301;

			if ( ! in_array( $status, [ 301, 302, 307, 308 ], true ) ) {
				$errors[] = sprintf( 'Line %d has an unsupported status "%s" — use 301 or 302.', $i + 1, $parts[2] );
				continue;
			}

			if ( '' === $source ) {
				$errors[] = sprintf( 'Line %d has an empty source.', $i + 1 );
				continue;
			}

			// GUARD 1 — a rule that points at itself is an infinite loop, and the browser
			// reports it as the whole site being broken.
			if ( self::normalise( $target ) === $source ) {
				$errors[] = sprintf( 'Line %d points at itself and was ignored: %s', $i + 1, $line );
				continue;
			}

			$rules[ $source ] = [
				'target' => $target,
				'status' => $status,
			];
		}

		return [
			'rules'  => $rules,
			'errors' => $errors,
		];
	}

	/**
	 * Reduce a path to its comparable form: leading slash, no trailing slash, no query.
	 *
	 * @param string $path Raw path or URL.
	 */
	protected static function normalise( string $path ): string {
		$path = trim( $path );

		// An absolute URL to this same site is still a local path for comparison purposes.
		if ( preg_match( '#^https?://#i', $path ) ) {
			/*
			 * ⛔ strtolower BOTH sides. parse_url() does not lowercase the host, so before
			 * 2026-08-20 a rule written `/thing  https://EXAMPLE.COM/thing` read as
			 * "genuinely external" here, skipped the self-redirect guard at parse time,
			 * skipped it again at runtime, and then redirected to itself forever —
			 * wp_safe_redirect() DOES lowercase when validating, so it allowed it. One
			 * uppercase character took the whole site down. Found by build-security.
			 */
			$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
			$host = strtolower( (string) wp_parse_url( $path, PHP_URL_HOST ) );
			if ( $host !== $home ) {
				return $path;   // genuinely external — never normalise
			}
			$path = (string) wp_parse_url( $path, PHP_URL_PATH );
		}

		/*
		 * Decode AFTER the query is cut, so an encoded %3F stays part of the path. Browsers
		 * send /caf%C3%A9 while a person types /café into the map; decoding here means both
		 * spellings of a rule match both spellings of a request.
		 */
		$path = rawurldecode( (string) strtok( $path, '?' ) );
		$path = '/' . trim( $path, '/' );

		return strtolower( $path );
	}

	/**
	 * The parsed rule table, cached for the request and in the object cache.
	 *
	 * ⛔ maybe_redirect() called parse() directly until 2026-08-20, so every anonymous page
	 * view re-split and re-walked the entire map — on template_redirect, i.e. squarely on
	 * TTFB, on precisely the cache-MISS requests whose TTFB gets measured. It costs nothing
	 * on a fresh site and scales with the map, and the module's own docblock says a migration
	 * is when it earns its place — which is exactly when the map is hundreds of lines.
	 *
	 * Keyed on a hash of the raw text, so a value changed by anything other than the settings
	 * screen (WP-CLI, a direct database write) cannot serve a stale map.
	 *
	 * @return array{rules:array<string,array{target:string,status:int}>,errors:array<int,string>}
	 */
	public static function rules(): array {
		static $memo = null;
		if ( null !== $memo ) {
			return $memo;
		}

		$raw = self::raw();
		$key = 'rules-' . md5( $raw );

		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			$memo = $cached;
			return $memo;
		}

		$memo = self::parse( $raw );
		wp_cache_set( $key, $memo, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $memo;
	}

	/**
	 * Match the current request and redirect if a rule applies.
	 */
	public function maybe_redirect(): void {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		/*
		 * $_SERVER is slashed by WordPress like any other superglobal.
		 *
		 * ⛔ NOT sanitize_text_field(): it strips every %XX sequence, so /caf%C3%A9/ became
		 * /caf/ and could match the wrong rule, and an encoded utm_campaign lost its spaces
		 * on the way through. The raw path is only compared against the map's own keys and
		 * handed to wp_safe_redirect(), which validates the host and strips CR/LF itself;
		 * stripping control characters here as well keeps header injection impossible.
		 */
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- control characters are stripped on this line; the path is only compared, and the query reaches Location only through wp_safe_redirect().
		$uri       = isset( $_SERVER['REQUEST_URI'] ) ? (string) preg_replace( '/[\x00-\x1F\x7F]/', '', wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$requested = self::normalise( $uri );

		if ( '/' === $requested ) {
			return;   // never redirect the homepage from this map
		}

		$parsed = self::rules();
		if ( ! isset( $parsed['rules'][ $requested ] ) ) {
			return;
		}

		$rule   = $parsed['rules'][ $requested ];
		$target = $rule['target'];

		// Relative targets resolve against the site root. user_trailingslashit() matches the
		// site's permalink structure, so the redirect lands on the canonical form instead of
		// handing off to redirect_canonical for a SECOND hop on every migrated URL.
		if ( ! preg_match( '#^https?://#i', $target ) ) {
			$path  = '/' . ltrim( $target, '/' );
			$query = '';
			if ( str_contains( $path, '?' ) ) {
				[ $path, $query ] = explode( '?', $path, 2 );
				$query            = '?' . $query;
			}
			if ( '' === $query && ! pathinfo( $path, PATHINFO_EXTENSION ) ) {
				$path = user_trailingslashit( $path );
			}
			$target = home_url( $path . $query );
		}

		// Carry the original query string through when the target has none of its own.
		// Dropping it loses utm_* and gclid, and attribution being wrong is most of what
		// analytics work is about.
		$incoming = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		if ( '' !== $incoming && ! wp_parse_url( $target, PHP_URL_QUERY ) ) {
			$target .= '?' . $incoming;
		}

		// GUARD 1 again, at runtime — a rule can become self-referencing after home_url()
		// resolution even when the stored strings differed. Hosts lowercased, per normalise().
		$target_host = strtolower( (string) wp_parse_url( $target, PHP_URL_HOST ) );
		$home_host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( self::normalise( $target ) === $requested && $target_host === $home_host ) {
			return;
		}

		/**
		 * Filter the resolved redirect target.
		 *
		 * @param string $target    Absolute destination URL.
		 * @param string $requested Normalised requested path.
		 */
		$target = (string) apply_filters( 'ajr_starter_core_redirect_target', $target, $requested );

		/*
		 * ⛔ wp_safe_redirect() sends ANY host outside allowed_redirect_hosts to /wp-admin/.
		 *
		 * The documented format allows an absolute cross-domain target, and before 2026-08-20
		 * every one of them landed the visitor on the client's login screen instead — with a
		 * 301 that browsers cache hard. On a rebrand migration that is every legacy inbound
		 * link. Two reviews found it independently.
		 *
		 * The fix is an allowlist built from the client's OWN map, added for this redirect
		 * only. Keeping wp_safe_redirect() preserves the open-redirect protection for
		 * everything not in the map; switching to wp_redirect() would throw it away.
		 */
		$host = strtolower( (string) wp_parse_url( $target, PHP_URL_HOST ) );
		if ( '' !== $host && $host !== $home_host ) {
			add_filter(
				'allowed_redirect_hosts',
				static function ( array $hosts ) use ( $host ): array {
					$hosts[] = $host;
					return $hosts;
				}
			);
		}

		wp_safe_redirect( $target, $rule['status'] );
		exit;
	}
}
