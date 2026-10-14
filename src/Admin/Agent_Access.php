<?php
/**
 * Agent_Access — create the REST application password for tooling, from the settings page.
 *
 * WHY
 *
 * Every new client site needs an application password before deploy tooling or an agent
 * can read or write anything through the REST API, and today that is a manual trip through Users → Profile → Application
 * Passwords on each site, with a hand-typed name that differs every time. This puts a
 * button on the settings page that creates it with a consistent name, shows it once, and
 * lists what already exists so a stale credential can be revoked.
 *
 * ⛔ WHAT THIS DELIBERATELY DOES NOT DO
 *
 *   - It does NOT create anything on activation. A plugin that mints a credential when it
 *     is switched on leaves a standing password on every install, including the ones that
 *     never wanted API access, and nobody reviews what they did not ask for. Creation is
 *     always a deliberate click by a logged-in administrator.
 *   - It does NOT create a password for another user. It creates one for the person
 *     clicking, so the credential can never be more privileged than the human who made it.
 *   - It does NOT log, email or transmit the password, and it never puts it in a URL — a
 *     query string lands in browser history, in server access logs, and in the next page's
 *     referrer header. WordPress shows a generated application password exactly once; so
 *     does this. If it is lost, revoke and reissue.
 *
 * ⚠️ IT IS BRIEFLY AT REST, AND AN EARLIER VERSION OF THIS DOCBLOCK DENIED THAT.
 *
 * The plaintext passes through a 60-second transient so it survives the redirect to the
 * settings page. On a site with no persistent object cache that is a cleartext row in
 * `wp_options`. The previous wording claimed the password was never stored, which was false
 * and — worse in a scaffold every build copies — would have stopped the next reviewer
 * looking. Rendering the settings page calls consume_new_password(), which deletes it; that
 * caller did not exist until 2026-08-20, so the row was previously never removed early and
 * lingered until WordPress's daily sweep. **Expiry is not deletion.**
 *
 * This is not a new security surface: it is WordPress core's own
 * WP_Application_Passwords API, doing what the Users screen already does. What it adds is
 * consistency and a single place to audit.
 *
 * ⚠️ Application passwords require HTTPS. On a plain-HTTP local site WordPress reports them
 * as unavailable, and that is a correct refusal rather than a bug to work around.
 *
 * @package AJR\StarterCore
 */

declare( strict_types=1 );

namespace AJR\StarterCore\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Issues and revokes the tooling application password.
 */
class Agent_Access {

	/**
	 * The name every credential this module creates is given.
	 *
	 * A fixed, recognisable name is the point: on a security pass, an application password
	 * called "AJR Tooling (REST API)" is self-explanatory, while "test2" is a question.
	 */
	protected const NAME = 'AJR Tooling (REST API)';

	/**
	 * Separate nonce actions for create and revoke.
	 *
	 * ⛔ One shared action meant a create nonce also authorised a revoke. Nonces scope
	 * intent, and two operations sharing one action have no scope at all.
	 */
	public const NONCE_CREATE = 'ajr_agent_access_create';
	public const NONCE_REVOKE = 'ajr_agent_access_revoke';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_ajr_agent_access_create', [ $this, 'handle_create' ] );
		add_action( 'admin_post_ajr_agent_access_revoke', [ $this, 'handle_revoke' ] );
	}

	/**
	 * Is the feature usable on this install?
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return class_exists( \WP_Application_Passwords::class )
			&& wp_is_application_passwords_available();
	}

	/**
	 * Existing application passwords created by this module, for the current user.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function existing(): array {
		if ( ! self::available() ) {
			return [];
		}

		$all = \WP_Application_Passwords::get_user_application_passwords( get_current_user_id() );

		return array_values(
			array_filter(
				is_array( $all ) ? $all : [],
				static fn( $p ) => ( $p['name'] ?? '' ) === self::NAME
			)
		);
	}

	/**
	 * Create a new application password for the current user.
	 */
	public function handle_create(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ajr-starter-core' ), 403 );
		}

		check_admin_referer( self::NONCE_CREATE );

		/*
		 * ⛔ POST only. `admin_post_*` fires on GET as well, and a nonce alone does not
		 * require a Referer — so before 2026-08-20 this was a URL that minted a permanent,
		 * admin-equivalent REST credential for anyone who loaded it while logged in, and
		 * every replay minted another. WordPress nonces stay valid for up to 24 hours, and
		 * that URL lands in history, in synced bookmarks and in front of prefetchers.
		 */
		if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			wp_die( esc_html__( 'Creating a credential requires a form submission.', 'ajr-starter-core' ), 405 );
		}

		if ( ! self::available() ) {
			$this->redirect_with( 'unavailable' );
		}

		$created = \WP_Application_Passwords::create_new_application_password(
			get_current_user_id(),
			[ 'name' => self::NAME ]
		);

		if ( is_wp_error( $created ) ) {
			$this->redirect_with( 'error' );
		}

		/*
		 * $created[0] is the plaintext password and exists only in this request. It is
		 * passed through a one-time transient rather than the URL: a query string lands in
		 * the browser history, in server access logs, and in any referrer header the next
		 * page sends. The transient is deleted the moment it is read.
		 */
		set_transient(
			'ajr_agent_access_' . get_current_user_id(),
			$created[0],
			MINUTE_IN_SECONDS
		);

		$this->redirect_with( 'created' );
	}

	/**
	 * Revoke a specific application password by UUID.
	 */
	public function handle_revoke(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ajr-starter-core' ), 403 );
		}

		check_admin_referer( self::NONCE_REVOKE );

		$uuid = isset( $_GET['uuid'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['uuid'] ) ) : '';

		if ( '' !== $uuid && self::available() ) {
			\WP_Application_Passwords::delete_application_password( get_current_user_id(), $uuid );
		}

		$this->redirect_with( 'revoked' );
	}

	/**
	 * Read and immediately destroy the one-time password, if one is waiting.
	 *
	 * ⛔ CALLING THIS IS WHAT DELETES THE PLAINTEXT. It had no callers until 2026-08-20, so
	 * the transient was never read and therefore never removed early — the comment claiming
	 * "the transient is deleted the moment it is read" described a read that never happened.
	 * Admin\Settings::render_agent_access() is the caller.
	 *
	 * @return string Empty when there is nothing to show.
	 */
	public static function consume_new_password(): string {
		$key      = 'ajr_agent_access_' . get_current_user_id();
		$password = get_transient( $key );

		if ( ! is_string( $password ) || '' === $password ) {
			return '';
		}

		delete_transient( $key );

		return $password;
	}

	/**
	 * Redirect back to the settings page with a status flag.
	 *
	 * @param string $status Status slug.
	 */
	protected function redirect_with( string $status ): never {
		wp_safe_redirect(
			add_query_arg(
				[
					'page'       => 'ajr-starter-core',
					'ajr_access' => $status,
				],
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}
