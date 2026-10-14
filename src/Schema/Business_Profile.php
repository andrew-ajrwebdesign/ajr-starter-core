<?php
/**
 * Business_Profile — JSON-LD for the business, built entirely from settings.
 *
 * ⛔ IT EMITS NOTHING UNTIL IT HAS SOMETHING TRUE TO SAY.
 *
 * An incomplete profile is worse than no profile: a LocalBusiness with an empty `name` or a
 * half-filled address is invalid structured data, and invalid markup is a signal against the
 * site rather than a neutral absence. This has bitten before — an empty Site Title produced
 * a sitewide BreadcrumbList with an empty name on every URL, and nothing reported it because
 * the markup was still well-formed JSON.
 *
 * So the gate is explicit: the feature must be switched on AND the business name must be
 * filled in. Everything else is included only when present.
 *
 * ⚠️ AN EMPTY SETTING IS A SWITCHED-OFF RENDER SITE, NOT AN INERT VALUE. Filling in a blank
 * field here turns on output in every place that reads it. That is the intended behaviour —
 * just never assume filling one field is invisible.
 *
 * @package AJR\StarterCore
 */

declare( strict_types=1 );

namespace AJR\StarterCore\Schema;

use AJR\StarterCore\Core\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Emits a LocalBusiness/Organization JSON-LD block.
 */
class Business_Profile {

	/**
	 * Register hooks.
	 */
	public function register(): void {
		if ( ! Config::get( 'schema.enabled' ) ) {
			return;
		}

		add_action( 'wp_head', [ $this, 'output' ], 20 );
	}

	/**
	 * Print the JSON-LD block, on the front page only.
	 *
	 * Repeating an identical organisation profile on every URL adds bytes to every page and
	 * tells search engines nothing new. One authoritative copy on the homepage is the
	 * documented pattern.
	 */
	public function output(): void {
		if ( ! is_front_page() ) {
			return;
		}

		$data = $this->build();
		if ( [] === $data ) {
			return;
		}

		/*
		 * ⛔ JSON_HEX_TAG, and NOT JSON_UNESCAPED_SLASHES.
		 *
		 * json_encode() escapes `/` as `\/` by default, and that is the only thing
		 * stopping a value containing `</script>` from closing this element — an HTML
		 * parser ends a <script> at the first `</script` regardless of JSON context.
		 * JSON_UNESCAPED_SLASHES turned that off. No stored setting can reach it today
		 * (sanitize_text_field strips tags), but the documented filter below is an
		 * extension point whose obvious first use is pulling a description from post
		 * meta — editor-controlled data. That is stored XSS on the front page of every
		 * site built from this scaffold.
		 *
		 * JSON_HEX_TAG hex-encodes the angle brackets: valid JSON, parsed identically
		 * by Google, and impossible to break out of whatever the filter returns.
		 */
		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode( $data, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE )
		);
	}

	/**
	 * Assemble the profile from settings.
	 *
	 * @return array<string,mixed> Empty when there is not enough to say anything true.
	 */
	protected function build(): array {
		// Fall back to the WordPress Site Title so a site that filled in only that still
		// gets a valid profile.
		$name = Config::filled( 'business.name' )
			? (string) Config::get( 'business.name' )
			: (string) get_bloginfo( 'name' );

		if ( '' === trim( $name ) ) {
			return [];
		}

		$type = (string) Config::get( 'schema.type' );
		$data = [
			'@context' => 'https://schema.org',
			'@type'    => '' !== $type ? $type : 'LocalBusiness',
			'name'     => $name,
			'url'      => home_url( '/' ),
		];

		if ( Config::filled( 'business.legal_name' ) ) {
			$data['legalName'] = (string) Config::get( 'business.legal_name' );
		}

		if ( Config::filled( 'business.phone' ) ) {
			$data['telephone'] = (string) Config::get( 'business.phone' );
		}

		if ( Config::filled( 'business.email' ) ) {
			$data['email'] = (string) Config::get( 'business.email' );
		}

		$address = array_filter(
			[
				'streetAddress'   => (string) Config::get( 'business.street' ),
				'addressLocality' => (string) Config::get( 'business.locality' ),
				'addressRegion'   => (string) Config::get( 'business.region' ),
				'postalCode'      => (string) Config::get( 'business.postcode' ),
				'addressCountry'  => (string) Config::get( 'business.country' ),
			],
			static fn( $v ) => '' !== trim( (string) $v )
		);

		/*
		 * A PostalAddress with only a country is not an address — it is noise that makes
		 * the profile look more complete than it is. Require at least a street and a
		 * locality before claiming a physical presence.
		 */
		if ( isset( $address['streetAddress'], $address['addressLocality'] ) ) {
			$data['address'] = [ '@type' => 'PostalAddress' ] + $address;
		} elseif ( 'Organization' !== $data['@type'] ) {
			/*
			 * ⛔ Google requires BOTH `name` and `address` on LocalBusiness. Without an
			 * address this is not a valid local business — Search Console reports a
			 * missing required field and the item is ineligible for any local result.
			 *
			 * The default type is LocalBusiness and the default address is empty, so any
			 * site that ticked "output structured data" before filling in its address was
			 * shipping exactly that. This file's own docblock says it emits nothing until
			 * it has something true to say; on default settings it emitted something
			 * half-true. Organization carries no address requirement and inherits the same
			 * name/telephone/email/logo, so the profile stays valid AND stays true.
			 */
			$data['@type'] = 'Organization';
		}

		if ( has_custom_logo() ) {
			$logo_id  = (int) get_theme_mod( 'custom_logo' );
			$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';
			if ( $logo_url ) {
				$data['logo'] = $logo_url;
			}
		}

		/**
		 * Filter the business profile before output.
		 *
		 * @param array<string,mixed> $data Assembled JSON-LD.
		 */
		return (array) apply_filters( 'ajr_starter_core_business_profile', $data );
	}
}
