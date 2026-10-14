<?php
/**
 * Tags — Google Analytics, Tag Manager, Ads and Search Console verification.
 *
 * WHY THIS IS IN CORE
 *
 * The brief: stop pasting code into headers and stop installing a plugin to hold a
 * measurement ID. Site Kit, a GTM plugin and a "custom code" snippet plugin all end up doing
 * what this file does, while each costs an update cycle, an entry on the security audit, and
 * a place for the tag to hide. When a tag goes missing at 11pm the question "where is it
 * configured?" should have one answer.
 *
 * WHAT IT DOES NOT DO, DELIBERATELY
 *
 * It does not implement a cookie banner. Consent Mode below sets the DEFAULT state to denied
 * and waits to be told otherwise; something else — a banner plugin, or the client's chosen
 * CMP — issues the update. Two things both claiming to own consent state is worse than one,
 * and a banner is a real product, not a side effect of a tag loader.
 *
 * ⚠️ VERIFYING THIS WORKS
 * GA4 Realtime is NOT a valid test from a developer's own browser if it runs Google's
 * Analytics Opt-out add-on: those hits never arrive and working tracking looks broken. Verify by
 * viewing source for the tag, or by watching the network request in a clean browser.
 *
 * TO REMOVE: delete this file, its line in Core\Plugin::modules(), and the `google.*` keys
 * from Core\Config::schema().
 *
 * @package AJR\StarterCore
 */

declare( strict_types=1 );

namespace AJR\StarterCore\Google;

use AJR\StarterCore\Core\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Emits Google tags in the right places, or nothing at all.
 */
class Tags {

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'wp_head', [ $this, 'site_verification' ], 1 );
		add_action( 'wp_head', [ $this, 'head_tags' ], 2 );
		add_action( 'wp_body_open', [ $this, 'body_tag' ], 1 );
	}

	/**
	 * Should any tracking be emitted for this request?
	 *
	 * ⭐ Excluding logged-in users is on by default for a measured reason: on one client
	 * account roughly HALF of all recorded GA4 conversions turned out to be the site's own
	 * team browsing. Every report built on that data overstated performance, and no amount
	 * of analysis downstream can separate it after the fact. The cheapest correction is to
	 * never record it.
	 */
	protected function should_track(): bool {
		if ( is_admin() ) {
			return false;
		}

		// Never track the customiser/editor previews — they are not visits.
		if ( is_customize_preview() || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) ) {
			return false;
		}

		if ( Config::get( 'google.exclude_logged_in' ) && is_user_logged_in() ) {
			return false;
		}

		/**
		 * Filter whether Google tags are emitted for this request.
		 *
		 * Escape hatch for the cases a setting cannot express — a staging hostname, a
		 * load-test user agent, a country block.
		 *
		 * @param bool $track Whether to emit tracking.
		 */
		return (bool) apply_filters( 'ajr_starter_core_should_track', true );
	}

	/**
	 * Search Console verification meta tag.
	 *
	 * Emitted regardless of tracking consent or login state: it is an ownership proof, not
	 * a tracker, and it must be present when Google fetches the page as a bot.
	 */
	public function site_verification(): void {
		if ( ! Config::filled( 'google.site_verification' ) ) {
			return;
		}

		printf(
			'<meta name="google-site-verification" content="%s" />' . "\n",
			esc_attr( (string) Config::get( 'google.site_verification' ) )
		);
	}

	/**
	 * Consent defaults, then GTM or gtag — in that order, which is the part that matters.
	 *
	 * ⛔ Consent Mode must be set BEFORE the tag library loads. A default issued afterwards
	 * applies to nothing that already fired, so the first pageview — the one that matters
	 * most for attribution — is recorded under the wrong consent state.
	 */
	public function head_tags(): void {
		if ( ! $this->should_track() ) {
			return;
		}

		$gtm = trim( (string) Config::get( 'google.gtm_id' ) );
		$ga4 = trim( (string) Config::get( 'google.ga4_id' ) );
		$ads = trim( (string) Config::get( 'google.ads_conversion_id' ) );

		if ( '' === $gtm && '' === $ga4 && '' === $ads ) {
			return;
		}

		if ( Config::get( 'google.consent_mode' ) ) {
			$this->consent_defaults();
		}

		if ( '' !== $gtm ) {
			$this->gtm_head( $gtm );
			return;   // GTM owns the page; loading gtag alongside it double-counts traffic
		}

		if ( '' !== $ga4 || '' !== $ads ) {
			$this->gtag( $ga4, $ads );
		}
	}

	/**
	 * Consent Mode v2 defaults — everything denied until a banner says otherwise.
	 */
	protected function consent_defaults(): void {
		?>
		<script>
			window.dataLayer = window.dataLayer || [];
			function gtag(){dataLayer.push(arguments);}
			gtag('consent', 'default', {
				'ad_storage': 'denied',
				'ad_user_data': 'denied',
				'ad_personalization': 'denied',
				'analytics_storage': 'denied',
				'functionality_storage': 'granted',
				'security_storage': 'granted',
				'wait_for_update': 500
			});
		</script>
		<?php
	}

	/**
	 * Is the tag loader held back until the visitor does something?
	 *
	 * @return bool
	 */
	protected function defer_enabled(): bool {
		return (bool) Config::get( 'google.defer_until_interaction' );
	}

	/**
	 * Prints the shared gate that both loaders queue against. Once per request.
	 *
	 * WHY DEFER AT ALL — measured on a live client site, 26 Aug 2026, identical audits
	 * minutes apart: mobile 67 vs 95, desktop 84 vs 96, FCP 4.4s vs 1.6s, TBT 90ms
	 * vs 0ms, purely from whether the container loaded. That is a real improvement
	 * for real people, not a lab trick: the bytes and the main-thread work genuinely
	 * are not spent before the visitor has done anything.
	 *
	 * ⛔ WHY CAMPAIGN TRAFFIC IS EXEMPT — this is the part that must not be removed.
	 *
	 * This same technique has already cost real money. On a client site in Aug 2026,
	 * LiteSpeed delayed an inline GTM loader until interaction and every visitor who
	 * bounced without interacting was invisible to GA4 — on paid traffic, the most
	 * expensive visitors to lose (on another account, 10% of paid clicks never
	 * registered a session). The conclusion: eager still wins on attribution-critical
	 * pages.
	 *
	 * The specific damage is the Conversion Linker. It is what writes the gclid into a
	 * first-party cookie. Defer it and a paid visitor who lands, does not interact,
	 * leaves, and converts on a later visit has no stored click id — the conversion
	 * detaches from the ad that bought it, and the reporting quietly under-credits the
	 * campaign. Nothing reports a fault; the number is just wrong.
	 *
	 * So any URL carrying a click id or utm_* loads eagerly. Lab audits never carry
	 * those parameters, so the score is unaffected, and every bought click is measured
	 * the way it was before. The exemption costs nothing on a site with no paid
	 * traffic, because the pattern simply never matches.
	 *
	 * @return void
	 */
	protected function defer_runtime(): void {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<script>(function(w,d){
		if(w.ajrLoadTags){return;}
		var q=[],fired=false,
		ev=['pointerdown','touchstart','keydown','scroll','mousemove','wheel'],
		o={passive:true,capture:true},
		/* click ids and utm_* — see the docblock; do not narrow this without reading it */
		CAMPAIGN=/[?&](gclid|gbraid|wbraid|msclkid|fbclid|ttclid|li_fat_id|utm_[a-z_]+)=/i;
		function run(){if(fired){return;}fired=true;
		for(var i=0;i<ev.length;i++){w.removeEventListener(ev[i],run,o);}
		d.removeEventListener('wpcf7mailsent',run);
		while(q.length){(q.shift())();}}
		w.ajrLoadTags=function(fn){if(fired){fn();return;}q.push(fn);
		if(CAMPAIGN.test(w.location.search)){run();}};
		for(var i=0;i<ev.length;i++){w.addEventListener(ev[i],run,o);}
		d.addEventListener('wpcf7mailsent',run);
		})(window,document);</script>
		<?php
	}

	/**
	 * Tag Manager container snippet for <head>.
	 *
	 * The dataLayer and the gtm.start stamp are emitted immediately either way. Only the
	 * container script is ever queued, so anything pushed before it arrives sits in the
	 * array and is processed on init — which is how the dataLayer is designed to work,
	 * and why event tags still fire normally.
	 *
	 * @param string $id Container ID, already trimmed.
	 */
	protected function gtm_head( string $id ): void {
		if ( ! $this->defer_enabled() ) {
			?>
			<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
			new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
			j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
			'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
			})(window,document,'script','dataLayer','<?php echo esc_js( $id ); ?>');</script>
			<?php
			return;
		}

		$this->defer_runtime();
		?>
		<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
		new Date().getTime(),event:'gtm.js'});
		w.ajrLoadTags(function(){var f=d.getElementsByTagName(s)[0],
		j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
		'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);});
		})(window,document,'script','dataLayer','<?php echo esc_js( $id ); ?>');</script>
		<?php
	}

	/**
	 * Prints gtag.js for GA4 and/or Google Ads, when Tag Manager is not in use.
	 *
	 * The gtag() shim and its config calls are ALWAYS emitted eagerly — they only push
	 * onto dataLayer, cost nothing, and queue correctly for a library that arrives
	 * later. Only the library request itself is deferred.
	 *
	 * @param string $ga4 GA4 measurement ID, possibly empty.
	 * @param string $ads Ads conversion ID, possibly empty.
	 */
	protected function gtag( string $ga4, string $ads ): void {
		$primary = '' !== $ga4 ? $ga4 : $ads;
		$defer   = $this->defer_enabled();
		$src     = 'https://www.googletagmanager.com/gtag/js?id=' . $primary;

		if ( $defer ) {
			$this->defer_runtime();
		} else {
			?>
			<script async src="<?php echo esc_url( $src ); ?>"></script>
			<?php
		}
		?>
		<script>
			window.dataLayer = window.dataLayer || [];
			function gtag(){dataLayer.push(arguments);}
			gtag('js', new Date());
			<?php if ( '' !== $ga4 ) : ?>
			gtag('config', <?php echo wp_json_encode( $ga4 ); ?>);
			<?php endif; ?>
			<?php if ( '' !== $ads ) : ?>
			gtag('config', <?php echo wp_json_encode( $ads ); ?>);
			<?php endif; ?>
			<?php if ( $defer ) : ?>
			window.ajrLoadTags(function(){
				var j=document.createElement('script');
				j.async=true;j.src=<?php echo wp_json_encode( $src ); ?>;
				document.head.appendChild(j);
			});
			<?php endif; ?>
		</script>
		<?php
	}

	/**
	 * Tag Manager's noscript iframe, immediately after <body>.
	 *
	 * ⚠️ Requires the theme to call wp_body_open(), which every block theme does. A classic
	 * theme that omits it loses this half silently — the container still works for
	 * JavaScript visitors, so nothing looks broken.
	 */
	public function body_tag(): void {
		if ( ! $this->should_track() ) {
			return;
		}

		$gtm = trim( (string) Config::get( 'google.gtm_id' ) );
		if ( '' === $gtm ) {
			return;
		}
		?>
		<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo esc_attr( $gtm ); ?>"
			height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
		<?php
	}
}
