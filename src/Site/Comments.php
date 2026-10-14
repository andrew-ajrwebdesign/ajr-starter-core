<?php
/**
 * Comments — switch the whole commenting system off, properly.
 *
 * WHY THIS IS IN CORE RATHER THAN A PLUGIN
 *
 * Every build needs this and it is 60 lines. Installing Disable Comments to do it adds a
 * plugin to update forever, one more thing to audit on a security pass, and one more thing
 * that can be deactivated by someone tidying the plugins screen — at which point months of
 * spam appears on a site whose owner thought commenting was off.
 *
 * ⛔ ORDER OF OPERATIONS WHEN REPLACING THE PLUGIN ON A LIVE SITE
 * Enable this setting FIRST, confirm comments are closed, and only then deactivate the
 * third-party plugin. Doing it the other way round leaves a window where comment forms are
 * live on every post, and spam bots find a WordPress site faster than anyone expects.
 *
 * WHY "CLOSE COMMENTS" IS NOT ENOUGH ON ITS OWN
 *
 * Unchecking the discussion settings only affects NEW posts. Existing posts keep their
 * stored `comment_status`, the comment feeds keep responding, the admin menu keeps its
 * Comments item, and the REST API keeps serving /wp/v2/comments. Each is a separate switch,
 * which is why this file is longer than "one line" and why doing it by hand gets it wrong.
 *
 * TO REMOVE THIS MODULE: delete this file, delete its line in Core\Plugin::modules(), and
 * delete the `comments.*` keys from Core\Config::schema().
 *
 * @package AJR\StarterCore
 */

declare( strict_types=1 );

namespace AJR\StarterCore\Site;

use AJR\StarterCore\Core\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Disables comments across the front end, the admin and the REST API.
 */
class Comments {

	/**
	 * Register hooks — only when the setting is on.
	 */
	public function register(): void {
		if ( ! Config::get( 'comments.disable' ) ) {
			return;
		}

		// Front end: report every post type as closed, and empty every comment list.
		add_filter( 'comments_open', '__return_false', 20 );
		add_filter( 'pings_open', '__return_false', 20 );
		add_filter( 'comments_array', '__return_empty_array', 20 );
		add_filter( 'get_comments_number', '__return_zero', 20 );

		// Remove comment support from every post type that declares it, so the metabox
		// and the "Discussion" panel disappear rather than merely being ignored.
		add_action( 'init', [ $this, 'remove_post_type_support' ], 100 );

		// Admin: hide the menu, the admin-bar node and the dashboard widget.
		add_action( 'admin_menu', [ $this, 'remove_admin_menu' ] );
		add_action( 'admin_init', [ $this, 'redirect_admin_pages' ] );
		add_action( 'wp_before_admin_bar_render', [ $this, 'remove_admin_bar_node' ] );
		add_action( 'wp_dashboard_setup', [ $this, 'remove_dashboard_widget' ] );

		// Feeds and REST: the two routes that keep answering after the UI is gone.
		add_filter( 'feed_links_show_comments_feed', '__return_false' );
		add_filter( 'rest_endpoints', [ $this, 'remove_rest_endpoints' ] );

		// Do not advertise a form that cannot be used.
		add_filter( 'comments_template', [ $this, 'empty_template' ], 20 );
	}

	/**
	 * Strip comment and trackback support from all post types.
	 */
	public function remove_post_type_support(): void {
		foreach ( get_post_types( [], 'names' ) as $type ) {
			if ( post_type_supports( $type, 'comments' ) ) {
				remove_post_type_support( $type, 'comments' );
				remove_post_type_support( $type, 'trackbacks' );
			}
		}
	}

	/**
	 * Remove the Comments item from the admin menu.
	 */
	public function remove_admin_menu(): void {
		remove_menu_page( 'edit-comments.php' );
	}

	/**
	 * Send anyone who reaches the comment screens directly back to the dashboard.
	 *
	 * Hiding a menu item does not protect the page behind it — the URL still works, and a
	 * bookmarked edit-comments.php would otherwise present a working moderation queue for
	 * a feature the site does not have.
	 */
	public function redirect_admin_pages(): void {
		global $pagenow;

		if ( in_array( $pagenow, [ 'edit-comments.php', 'options-discussion.php' ], true ) ) {
			wp_safe_redirect( admin_url() );
			exit;
		}
	}

	/**
	 * Remove the admin-bar comments bubble.
	 */
	public function remove_admin_bar_node(): void {
		global $wp_admin_bar;

		if ( $wp_admin_bar instanceof \WP_Admin_Bar ) {
			$wp_admin_bar->remove_node( 'comments' );
		}
	}

	/**
	 * Remove the dashboard "Recent Comments" widget.
	 */
	public function remove_dashboard_widget(): void {
		remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
	}

	/**
	 * Unregister the REST comment routes.
	 *
	 * @param array<string,mixed> $endpoints Registered REST endpoints.
	 * @return array<string,mixed>
	 */
	public function remove_rest_endpoints( array $endpoints ): array {
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( str_starts_with( (string) $route, '/wp/v2/comments' ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}

	/**
	 * Point the comments template at an empty file so a theme cannot render a form.
	 *
	 * @return string
	 */
	public function empty_template(): string {
		return __DIR__ . '/empty-comments.php';
	}
}
