<?php
/**
 * Plugin Name:       AJR Starter Core
 * Plugin URI:        https://github.com/andrew-ajrwebdesign/ajr-starter-core
 * Description:       Core functionality scaffold — settings-driven business details, Google tags, redirects, comments control, responsive image sizes and an optional custom post type. Nothing it does is hardcoded.
 * Version:           0.2.0
 * Requires at least: 6.7
 * Requires PHP:      8.1
 * Author:            AJR Web Design
 * Author URI:        https://ajrwebdesign.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ajr-starter-core
 * Domain Path:       /languages
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * RENAMING THIS FOR A CLIENT BUILD
 *
 * Four find-and-replaces, in this order, then rename the folder and this file:
 *
 *   AJR Starter Core     → Acme Core            (display name, in this header)
 *   ajr-starter-core     → acme-core            (slug, text domain, folder, filename)
 *   ajr_starter_core     → acme_core            (UNDERSCORE form — see below)
 *   AJR_STARTER_CORE     → ACME_CORE            (constants)
 *   AJR\StarterCore      → AJR\Acme             (namespace — also composer.json autoload)
 *
 * ⛔ The UNDERSCORE form is a separate replacement and it is easy to miss, because a
 * hyphen-based find-and-replace does not touch it. It is not cosmetic: it names the
 * `wp_options` row holding EVERY setting (Core\Config::OPTION), the rewrite-flush flag,
 * and four public filter hooks. Miss it and two client sites both read
 * `ajr_starter_core_settings`, which on a multisite or a shared staging database means
 * one client's settings load on another's site. Caught on a client build, after the
 * four-item table above reported itself complete.
 *
 * ⚠️ Verify with BOTH forms before committing a rename:
 *     grep -rn 'ajr-starter\|ajr_starter\|AJR_STARTER\|StarterCore' . --exclude-dir=vendor
 *
 * Then run `composer dump-autoload` if you are using Composer, and bump Version.
 * `README.md` carries the same table.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * @package AJR\StarterCore
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/*
 * Version is READ FROM THE HEADER ABOVE, never typed twice.
 *
 * A second literal drifts. On one live build the header said 0.26.1 while the constant still
 * said 0.25.0, so every block script and style was enqueued with a version three releases old and
 * browsers kept serving cached copies of files that had changed. Nothing reports that: the
 * deploy looks clean, and the stale asset is what loads. get_file_data() reads the first 8KB
 * of this file once per request — cheaper than shipping a fix nobody receives.
 */
$ajr_starter_core_meta = get_file_data( __FILE__, [ 'Version' => 'Version' ] );
define( 'AJR_STARTER_CORE_VERSION', '' !== $ajr_starter_core_meta['Version'] ? $ajr_starter_core_meta['Version'] : '0.0.0' );
unset( $ajr_starter_core_meta );

define( 'AJR_STARTER_CORE_FILE', __FILE__ );
define( 'AJR_STARTER_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'AJR_STARTER_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'AJR_STARTER_CORE_BASENAME', plugin_basename( __FILE__ ) );

/*
 * Composer's autoloader when present; otherwise a built-in PSR-4 loader.
 *
 * ⛔ This plugin has NO runtime dependencies, and the deployed folder ships WITHOUT vendor/.
 * Plugin folders often reach client hosts by FTP, and an instruction to upload vendor/ too
 * is how a deploy half-arrives. The fallback below means a fresh git clone or an FTP upload works
 * with no build step at all.
 *
 * The guard matters as much as the loader: a plugin whose autoloader is missing must not
 * white-screen the site. It deactivates itself and says why.
 */
$ajr_starter_core_composer = AJR_STARTER_CORE_PATH . 'vendor/autoload.php';

if ( is_readable( $ajr_starter_core_composer ) ) {
	require_once $ajr_starter_core_composer;
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'AJR\\StarterCore\\';

			if ( ! str_starts_with( $class_name, $prefix ) ) {
				return;
			}

			$relative = substr( $class_name, strlen( $prefix ) );
			$path     = AJR_STARTER_CORE_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	);
}

/*
 * Boot on plugins_loaded so any theme-supports contract the theme declares is already in
 * place, and so a fatal here cannot take down the admin before it can be deactivated.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( \AJR\StarterCore\Core\Plugin::class ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p><strong>AJR Starter Core</strong> could not load its classes. The <code>src/</code> directory is missing or unreadable — re-upload the plugin folder in full.</p></div>';
				}
			);
			return;
		}

		\AJR\StarterCore\Core\Plugin::boot();
	}
);

/*
 * Rewrite rules are registered by the post type module, which only runs on `init`. Flushing
 * on activation alone would therefore flush BEFORE the rules exist. Registering the post
 * type here first, then flushing, is the documented order — and getting it wrong produces
 * 404s on every custom post until something else triggers a flush.
 */
register_activation_hook(
	__FILE__,
	static function (): void {
		if ( class_exists( \AJR\StarterCore\PostTypes\Content_Type::class ) ) {
			( new \AJR\StarterCore\PostTypes\Content_Type() )->register_post_type();
		}
		flush_rewrite_rules();
	}
);

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
