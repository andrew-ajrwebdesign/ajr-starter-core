<?php
/**
 * Content_Type — one optional custom post type, entirely driven by settings.
 *
 * A CPT is normal on a client build, and so is not needing one. This scaffold is therefore
 * OFF by default and trivially removable — see the removal note below. A starter kit that
 * force-registers a post type leaves every site that copied it carrying an admin menu,
 * rewrite rules and an archive nobody asked for.
 *
 * WHY THE LABELS ARE SETTINGS
 *
 * "Case Study" is the client's word for the client's own content, and renaming it to
 * "Project" or "Success Story" should not require a developer, a deploy, or an invoice. The
 * slug is editable for the same reason — with the caveat, stated in the admin help text,
 * that changing it after launch breaks existing links unless redirects are added. Seo\Redirects
 * is in this plugin for exactly that job.
 *
 * ⛔ TO REMOVE THIS MODULE ENTIRELY
 *   1. Delete this file (and the src/PostTypes/ directory if it is now empty).
 *   2. Delete its line from Core\Plugin::modules().
 *   3. Delete the `cpt.*` keys from Core\Config::schema().
 *   4. Delete the Content_Type reference in the activation hook in ajr-starter-core.php.
 * Nothing else in the codebase refers to it.
 *
 * @package AJR\StarterCore
 */

declare( strict_types=1 );

namespace AJR\StarterCore\PostTypes;

use AJR\StarterCore\Core\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the configurable custom post type.
 */
class Content_Type {

	/**
	 * The settings whose value changes the rewrite rules.
	 *
	 * ⛔ ALL THREE, not just the slug. Until 2026-08-21 only `cpt.slug` was watched, and
	 * the two settings that switch this module on and off were invisible to the flush:
	 *
	 *   • Ticking "Enable the custom post type" registered it and left every one of its
	 *     URLs 404ing until something unrelated happened to flush. The client sees posts
	 *     that look fine in wp-admin and 404 on the site.
	 *   • Unticking "Give it an archive page" left the archive still being served from
	 *     stale rules — a page the setting says does not exist.
	 *
	 * Both were caught on the test rig, and neither reports anything: no error, no notice,
	 * just a setting that appears to do nothing.
	 */
	protected const REWRITE_KEYS = [ 'cpt.enabled', 'cpt.slug', 'cpt.has_archive' ];

	/**
	 * Register hooks.
	 *
	 * ⛔ The two flush hooks are registered UNCONDITIONALLY, above the enabled check.
	 * The moments that most need a flush are the moments this module is switching on and
	 * off, and a hook that only exists while the post type is enabled cannot see either of
	 * them: on enable it is not yet listening, and on disable it has already stopped.
	 */
	public function register(): void {
		add_action( 'update_option_' . Config::option_name(), [ $this, 'flag_flush' ], 10, 2 );

		/*
		 * Consume the flag AFTER the post type is registered with its current slug.
		 *
		 * Flushing at the moment of saving cannot work: by then `init` has already run and
		 * the rules would be rebuilt from the OLD slug, then written to the `rewrite_rules`
		 * option. WP_Rewrite only regenerates when that option is empty, so the new slug
		 * would 404 indefinitely until somebody saved Settings → Permalinks by hand.
		 * Priority 99 on the NEXT request is the first moment the correct rules exist.
		 */
		add_action( 'init', [ $this, 'flush_if_flagged' ], 99 );

		if ( ! Config::get( 'cpt.enabled' ) ) {
			return;
		}

		add_action( 'init', [ $this, 'register_post_type' ] );
	}

	/**
	 * Flag a rewrite flush when a setting that shapes the rewrite rules changes.
	 *
	 * Hooked to the option itself rather than to the settings form, so a value changed by
	 * WP-CLI or a direct database write flushes too. The same reasoning already applies to
	 * the redirect map's cache key: anything that can change a value can leave rules stale.
	 *
	 * The two raw arrays are compared, never Config::get() — Config caches statically and
	 * has not seen the write yet at this point in the request.
	 *
	 * @param mixed $old The stored settings array before the write.
	 * @param mixed $new_settings The stored settings array after it.
	 */
	public function flag_flush( mixed $old, mixed $new_settings ): void {
		$old          = is_array( $old ) ? $old : [];
		$new_settings = is_array( $new_settings ) ? $new_settings : [];

		foreach ( self::REWRITE_KEYS as $key ) {
			if ( ( $old[ $key ] ?? null ) !== ( $new_settings[ $key ] ?? null ) ) {
				update_option( 'ajr_starter_core_flush_rewrite', 1, false );
				return;
			}
		}
	}

	/**
	 * Flush the rewrite rules once, if a change flagged it.
	 */
	public function flush_if_flagged(): void {
		if ( ! get_option( 'ajr_starter_core_flush_rewrite' ) ) {
			return;
		}

		delete_option( 'ajr_starter_core_flush_rewrite' );
		flush_rewrite_rules();
	}

	/**
	 * The post type key.
	 *
	 * ⛔ Prefixed with the site short code per the naming rule — `ajr_case_study`, never a
	 * bare `case_study`. An unprefixed key collides with whatever plugin the client
	 * installs next, and the collision presents as content vanishing from the admin.
	 *
	 * The key is derived from the slug but NEVER changes once content exists: it is the
	 * database value that ties posts to their type. Only the public-facing slug and the
	 * labels are editable.
	 */
	public static function key(): string {
		return 'ajr_content';
	}

	/**
	 * Register the post type.
	 *
	 * Public so the activation hook can call it before flushing rewrite rules — registering
	 * after the flush is the classic ordering bug that leaves every custom post 404ing
	 * until something else triggers a second flush.
	 */
	public function register_post_type(): void {
		$singular = (string) ( Config::filled( 'cpt.singular' ) ? Config::get( 'cpt.singular' ) : 'Item' );
		$plural   = (string) ( Config::filled( 'cpt.plural' ) ? Config::get( 'cpt.plural' ) : 'Items' );
		$slug     = (string) ( Config::filled( 'cpt.slug' ) ? Config::get( 'cpt.slug' ) : 'item' );

		register_post_type(
			self::key(),
			[
				'labels'       => [
					'name'          => $plural,
					'singular_name' => $singular,
					'add_new_item'  => sprintf( 'Add New %s', $singular ),
					'edit_item'     => sprintf( 'Edit %s', $singular ),
					'new_item'      => sprintf( 'New %s', $singular ),
					'view_item'     => sprintf( 'View %s', $singular ),
					'search_items'  => sprintf( 'Search %s', $plural ),
					'not_found'     => sprintf( 'No %s found', strtolower( $plural ) ),
					'all_items'     => $plural,
					'menu_name'     => $plural,
				],
				'public'       => true,
				'has_archive'  => (bool) Config::get( 'cpt.has_archive' ),
				'rewrite'      => [
					'slug'       => sanitize_title( $slug ),
					'with_front' => false,
				],
				'menu_icon'    => 'dashicons-portfolio',
				'supports'     => [ 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields' ],

				/*
				 * show_in_rest is required for the block editor. Without it the post type
				 * silently falls back to the classic editor, which on a block-theme build
				 * means the client cannot use any of the patterns the theme ships.
				 */
				'show_in_rest' => true,
			]
		);
	}
}
