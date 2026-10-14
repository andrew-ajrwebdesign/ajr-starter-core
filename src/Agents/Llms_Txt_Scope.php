<?php
/**
 * Llms_Txt_Scope — keep AI SEO Assistant's llms.txt to pages a site actually wants read.
 *
 * WHY IT IS IN THE STARTER KIT
 *
 * AI SEO Assistant (the framework's llms.txt and Markdown-for-agents plugin; it earns Lighthouse
 * Agentic Browsing 3/3 on production builds) lists every published post of the
 * post types it is given, and skips a post only when an SEO plugin's per-post noindex says so.
 * WooCommerce noindexes Cart, Checkout and My Account through core's wp_robots filter instead, so the
 * plugin cannot see it, and those three pages land in llms.txt as if they were content.
 *
 * This removes them through the plugin's documented `wpmai_is_post_indexable` filter, which runs after
 * its own checks, so it only ever removes. Without WooCommerce, or without the plugin, it does nothing.
 * A build with more to exclude adds IDs through `ajr_starter_llms_excluded_ids`.
 *
 * ⛔ THE PLUGIN RUNS THIS FILTER ON EVERY SINGLE PAGE VIEW (wp_head and send_headers, to print the
 * Markdown alternate link), not only when llms.txt is built. Anything hooked to
 * `ajr_starter_llms_excluded_ids` must be query-free — option reads or memoised values. One client build
 * first built its re-published list there with a meta query and paid it on every uncached page view
 * (caught in performance review); decide per post from cached meta instead.
 *
 * TO REMOVE THIS MODULE: delete this file and its line in Core\Plugin::modules().
 *
 * @package AJR\StarterCore
 */

declare( strict_types=1 );

namespace AJR\StarterCore\Agents;

defined( 'ABSPATH' ) || exit;

/**
 * Narrows llms.txt to pages the site indexes.
 */
class Llms_Txt_Scope {

	/**
	 * WooCommerce page settings that name a transactional page.
	 */
	public const WOO_PAGES = [ 'cart', 'checkout', 'myaccount' ];

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'wpmai_is_post_indexable', [ $this, 'filter' ], 10, 2 );
	}

	/**
	 * Drop transactional and build-excluded posts.
	 *
	 * @param mixed $indexable Whether the plugin would include the post.
	 * @param mixed $post      The post.
	 * @return bool
	 */
	public function filter( mixed $indexable, mixed $post ): bool {
		if ( ! $indexable || ! $post instanceof \WP_Post ) {
			return (bool) $indexable;
		}

		if ( in_array( (int) $post->ID, self::excluded_ids(), true ) ) {
			return false;
		}

		/*
		 * Only the plugin's own allowed post types. Defence in depth: the plugin checks this list
		 * itself, and repeating the check here means a post type it was never given can never be
		 * served, whatever version is installed.
		 */
		if ( class_exists( '\AJR\SEOAssistant\Markdown\Settings' ) ) {
			return in_array( $post->post_type, array_map( 'strval', \AJR\SEOAssistant\Markdown\Settings::allowed_post_types() ), true );
		}

		return true;
	}

	/**
	 * Post IDs kept out of llms.txt, memoised for the request (llms-full.txt checks hundreds of posts).
	 *
	 * @return array<int,int>
	 */
	public static function excluded_ids(): array {
		static $ids = null;

		if ( null === $ids ) {
			$ids = [];
			if ( function_exists( 'wc_get_page_id' ) ) {
				foreach ( self::WOO_PAGES as $page ) {
					$id = (int) wc_get_page_id( $page );
					if ( $id > 0 ) {
						$ids[] = $id;
					}
				}
			}

			/**
			 * Filters the post IDs AI SEO Assistant must leave out of llms.txt and Markdown twins.
			 *
			 * @param array<int,int> $ids Post IDs.
			 */
			$ids = array_map( 'intval', (array) apply_filters( 'ajr_starter_llms_excluded_ids', $ids ) );
		}

		return $ids;
	}
}
