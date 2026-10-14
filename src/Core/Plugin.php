<?php
/**
 * Plugin — the bootstrap. Decides which modules run, and nothing else.
 *
 * MODULES ARE A LIST, SO ADDING OR REMOVING ONE IS A LINE
 *
 * Every feature is a class with a register() method, named once in modules(). That is the
 * only place the plugin's shape is described. To drop a feature from a build: delete its
 * line here, delete its directory, and delete its keys from Core\Config::schema(). Three
 * deletions, no hunting — because nothing else in the codebase references it.
 *
 * This matters more for a scaffold than for a client plugin. A starter kit that is awkward
 * to strip back gets copied wholesale, and every site inherits a case-study post type it
 * never wanted, registering rewrite rules and an admin menu forever. A CPT is normal, and
 * so is not needing one.
 *
 * WHY register() AND NOT __construct()
 *
 * Hooks in a constructor cannot be tested, cannot be skipped conditionally, and fire simply
 * because an object exists. The standard forbids it; this is where that rule is enforced.
 *
 * @package AJR\StarterCore
 */

declare( strict_types=1 );

namespace AJR\StarterCore\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the plugin's modules.
 */
class Plugin {

	/**
	 * Run the plugin.
	 */
	public static function boot(): void {
		add_action( 'init', [ self::class, 'load_textdomain' ] );

		foreach ( self::modules() as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;   // a module deleted from disk must not fatal the site
			}
			$module = new $class();
			if ( method_exists( $module, 'register' ) ) {
				$module->register();
			}
		}
	}

	/**
	 * The modules this build runs, in registration order.
	 *
	 * ⛔ Order is not arbitrary where it matters: Media\Image_Sizes must register before
	 * anything that generates an attachment, and Admin\Settings is listed last only for
	 * readability — it hooks admin_menu and is order-independent.
	 *
	 * @return array<int,class-string>
	 */
	protected static function modules(): array {
		return [
			\AJR\StarterCore\Google\Tags::class,            // analytics + tag manager — replaces Site Kit
			\AJR\StarterCore\Site\Comments::class,          // comments off — replaces Disable Comments
			\AJR\StarterCore\Seo\Redirects::class,          // the redirect map, owned here
			\AJR\StarterCore\Media\Image_Sizes::class,      // responsive image ladder
			\AJR\StarterCore\Schema\Business_Profile::class,
			\AJR\StarterCore\PostTypes\Content_Type::class, // OPTIONAL scaffold — safe to delete
			\AJR\StarterCore\Agents\WebMCP_Forms::class,    // AI agents can use search + forms
			\AJR\StarterCore\Agents\Llms_Txt_Scope::class,  // llms.txt (AI SEO Assistant) skips cart/checkout/account
			\AJR\StarterCore\Admin\Agent_Access::class,     // REST application password issuing
			\AJR\StarterCore\Admin\Settings::class,
		];
	}

	/**
	 * Load translations.
	 *
	 * ⛔ The .mo filename must be `{text-domain}-{locale}.mo` for a PLUGIN. Getting this
	 * wrong produces no error and no translation — the strings simply stay in English,
	 * which reads as "the translation is incomplete" rather than "the file is misnamed".
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain(
			'ajr-starter-core',
			false,
			dirname( plugin_basename( AJR_STARTER_CORE_FILE ) ) . '/languages'
		);
	}
}
