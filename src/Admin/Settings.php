<?php
/**
 * Settings — the admin screen, generated from Core\Config::schema().
 *
 * There is deliberately no list of fields in this file. Every input, section, label and
 * sanitiser is derived from the schema, because the failure this plugin is built to prevent
 * is a value existing in code without a way for the client to edit it. If the admin screen
 * were maintained by hand, adding a value and adding its field would be two tasks — and the
 * second one is the one that gets skipped under deadline. Here it is not a second task.
 *
 * That is the whole design: the screen is a CONSEQUENCE of the declaration, not a companion
 * to it, so the two cannot drift.
 *
 * @package AJR\StarterCore
 */

declare( strict_types=1 );

namespace AJR\StarterCore\Admin;

use AJR\StarterCore\Core\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves the plugin's settings page.
 */
class Settings {

	/** Menu slug for the settings page. */
	protected const PAGE = 'ajr-starter-core';

	/**
	 * Human-readable section headings, keyed by the schema's `group`.
	 *
	 * A group with no entry here still renders — it falls back to the raw group name — so
	 * forgetting to add a title degrades the screen rather than hiding the fields.
	 *
	 * @var array<string,array{title:string,blurb:string}>
	 */
	protected const GROUPS = [
		'business'  => [
			'title' => 'Business details',
			'blurb' => 'Used in structured data and anywhere the site states its own name, address or phone number. Change them here and every place they appear updates.',
		],
		'schema'    => [
			'title' => 'Search engine structured data',
			'blurb' => 'Helps Google understand what this business is. Fill in the business details above before switching this on.',
		],
		'cpt'       => [
			'title' => 'Custom content type',
			'blurb' => 'An extra content type alongside Posts and Pages — for case studies, projects, team members, or whatever this site needs.',
		],
		'media'     => [
			'title' => 'Images',
			'blurb' => 'Controls the range of image sizes WordPress generates, so browsers can download a closer match and pages load faster.',
		],
		'google'    => [
			'title' => 'Google',
			'blurb' => 'Analytics, Tag Manager, Ads and Search Console verification. Paste the IDs here instead of installing Site Kit or pasting code into the site header — this is the one place to look when a tag goes missing.',
		],
		'redirects' => [
			'title' => 'Redirects',
			'blurb' => 'Send old addresses to new ones. This is what protects search rankings and existing links after pages move or a site is rebuilt.',
		],
		'site'      => [
			'title' => 'Site behaviour',
			'blurb' => 'Small switches that most sites want, handled here rather than by installing a plugin for each one.',
		],
		'agents'    => [
			'title' => 'AI agents',
			'blurb' => 'Lets AI assistants in the browser recognise and use the site search and your forms (WebMCP). Visitors see no difference; an agent fills a form in front of the visitor, who still presses submit.',
		],
	];

	/**
	 * Register hooks.
	 *
	 * ⛔ Not called from a constructor. Hooks registered in a constructor cannot be tested
	 * and cannot be conditionally skipped, which is why the standard forbids it.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
		add_action( 'admin_init', [ $this, 'register_setting' ] );
	}

	/**
	 * Add the settings page under the Settings menu.
	 */
	public function add_page(): void {
		add_options_page(
			__( 'Site Settings', 'ajr-starter-core' ),
			__( 'Site Settings', 'ajr-starter-core' ),
			'manage_options',
			self::PAGE,
			[ $this, 'render' ]
		);
	}

	/**
	 * Register the single option with the Settings API.
	 *
	 * One option holding one array — see Config::OPTION for why this is not a row per field.
	 */
	public function register_setting(): void {
		register_setting(
			self::PAGE,
			Config::option_name(),
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => [],
			]
		);
	}

	/**
	 * Sanitise every submitted value according to its declared type.
	 *
	 * Validation is driven by the schema for the same reason rendering is: a field whose
	 * sanitiser has to be remembered separately is a field that eventually gets an unsafe
	 * one. Anything not in the schema is discarded rather than stored.
	 *
	 * @param mixed $input Raw $_POST payload for this option.
	 * @return array<string,mixed>
	 */
	public function sanitize( mixed $input ): array {
		$input = is_array( $input ) ? $input : [];
		$clean = [];

		foreach ( Config::schema() as $key => $field ) {
			$type = $field['type'] ?? 'text';

			// An unchecked checkbox posts nothing at all, so absence means false rather
			// than "leave unchanged". Every other type keeps its stored value when absent.
			if ( 'checkbox' === $type ) {
				$clean[ $key ] = ! empty( $input[ $key ] );
				continue;
			}

			if ( ! isset( $input[ $key ] ) ) {
				$clean[ $key ] = Config::get( $key );
				continue;
			}

			$raw = $input[ $key ];

			/*
			 * A field posted as an array where a scalar was declared would otherwise emit a
			 * PHP "Array to string conversion" notice and store the literal string "Array".
			 * Harmless in production; on staging with display_errors on it prints into the
			 * admin page.
			 */
			if ( ! is_scalar( $raw ) ) {
				$clean[ $key ] = Config::get( $key );
				continue;
			}

			/*
			 * ⛔ Declared format wins before any sanitiser runs.
			 *
			 * A value that cannot contain a quote, an angle bracket or a slash cannot break
			 * out of anything it is later printed into — which settles the escaping question
			 * for the Google IDs permanently, whatever tool prints them. It also catches the
			 * failure that actually happens in the field: someone pasting an entire <script>
			 * tag into a box that wanted `G-XXXXXXXXXX`.
			 *
			 * A value that fails its pattern is REJECTED, keeping the previous value, rather
			 * than being silently mangled into something that looks saved but does not work.
			 */
			if ( ! empty( $field['pattern'] ) && '' !== trim( (string) $raw )
				&& ! preg_match( (string) $field['pattern'], (string) $raw ) ) {
				add_settings_error(
					self::PAGE,
					'invalid-' . sanitize_key( $key ),
					sprintf(
						/* translators: %s: the field label */
						__( '%s was not saved — it is not in the expected format. Paste only the ID itself, not the whole tag.', 'ajr-starter-core' ),
						$field['label'] ?? $key
					),
					'error'
				);
				$clean[ $key ] = Config::get( $key );
				continue;
			}

			$clean[ $key ] = match ( $type ) {
				'email'    => sanitize_email( (string) $raw ),
				'url'      => esc_url_raw( (string) $raw ),
				'number'   => is_numeric( $raw ) ? (float) $raw : ( $field['default'] ?? 0 ),
				// NOT sanitize_textarea_field(): it strips every %XX, which silently rewrote an
				// encoded redirect source like /caf%C3%A9 to /caf. Tags and control characters
				// (other than line breaks and tabs) still go; output is esc_textarea()'d.
				'textarea' => (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', wp_strip_all_tags( wp_check_invalid_utf8( (string) $raw ) ) ),
				'colour'   => sanitize_hex_color( (string) $raw ) ?? '',
				'select'   => array_key_exists( (string) $raw, $field['options'] ?? [] )
					? (string) $raw
					: (string) ( $field['default'] ?? '' ),
				default    => sanitize_text_field( (string) $raw ),
			};
		}

		/*
		 * Rewrite rules are not this class's business. PostTypes\Content_Type watches the
		 * settings that shape them and flags its own flush — see REWRITE_KEYS there.
		 *
		 * It was done here until 2026-08-21, and watching only `cpt.slug` from this side is
		 * what let enabling the post type ship a site whose every custom URL 404s. Owning it
		 * in the module also keeps the removal note in Content_Type honest: deleting that
		 * file no longer leaves rewrite logic stranded in here.
		 */
		return $clean;
	}

	/**
	 * Render the page.
	 *
	 * Late escaping throughout: values are escaped at the point of output, never earlier.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$schema = Config::schema();
		$values = Config::all();

		// Preserve declaration order within each group.
		$grouped = [];
		foreach ( $schema as $key => $field ) {
			$grouped[ $field['group'] ?? 'general' ][ $key ] = $field;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<p class="description" style="max-width:46em">
				<?php esc_html_e( 'Everything this plugin puts on the site is editable here. If you find something on the site that you cannot change on this page, that is a bug worth reporting.', 'ajr-starter-core' ); ?>
			</p>

			<form action="options.php" method="post">
				<?php settings_fields( self::PAGE ); ?>

				<?php foreach ( $grouped as $group => $fields ) : ?>
					<h2><?php echo esc_html( self::GROUPS[ $group ]['title'] ?? ucfirst( $group ) ); ?></h2>
					<?php if ( ! empty( self::GROUPS[ $group ]['blurb'] ) ) : ?>
						<p class="description" style="max-width:46em">
							<?php echo esc_html( self::GROUPS[ $group ]['blurb'] ); ?>
						</p>
					<?php endif; ?>

					<table class="form-table" role="presentation">
						<tbody>
						<?php foreach ( $fields as $key => $field ) : ?>
							<?php
							$id    = 'ajr-' . str_replace( '.', '-', $key );
							$name  = Config::option_name() . '[' . $key . ']';
							$value = $values[ $key ] ?? '';
							$type  = $field['type'] ?? 'text';
							?>
							<tr>
								<th scope="row">
									<label for="<?php echo esc_attr( $id ); ?>">
										<?php echo esc_html( $field['label'] ?? $key ); ?>
									</label>
								</th>
								<td>
									<?php if ( 'checkbox' === $type ) : ?>
										<input type="checkbox"
											id="<?php echo esc_attr( $id ); ?>"
											name="<?php echo esc_attr( $name ); ?>"
											value="1"
											<?php checked( (bool) $value ); ?> />

									<?php elseif ( 'textarea' === $type ) : ?>
										<textarea id="<?php echo esc_attr( $id ); ?>"
											name="<?php echo esc_attr( $name ); ?>"
											rows="<?php echo esc_attr( (string) ( $field['rows'] ?? 4 ) ); ?>" class="large-text code"><?php echo esc_textarea( (string) $value ); ?></textarea>

									<?php elseif ( 'select' === $type ) : ?>
										<select id="<?php echo esc_attr( $id ); ?>"
											name="<?php echo esc_attr( $name ); ?>">
											<?php foreach ( ( $field['options'] ?? [] ) as $opt => $label ) : ?>
												<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( (string) $value, (string) $opt ); ?>>
													<?php echo esc_html( $label ); ?>
												</option>
											<?php endforeach; ?>
										</select>

									<?php else : ?>
										<input type="<?php echo esc_attr( $type ); ?>"
											id="<?php echo esc_attr( $id ); ?>"
											name="<?php echo esc_attr( $name ); ?>"
											value="<?php echo esc_attr( (string) $value ); ?>"
											class="regular-text" />
									<?php endif; ?>

									<?php if ( ! empty( $field['help'] ) ) : ?>
										<p class="description">
											<?php
											/*
											 * wp_kses with a tiny allowlist, not esc_html:
											 * help text carries <code> examples, and the
											 * redirect field's help is unreadable without it.
											 */
											echo wp_kses(
												$field['help'],
												[
													'code' => [],
													'strong' => [],
													'em'   => [],
												]
											);
											?>
										</p>
									<?php endif; ?>

									<?php
									/*
									 * ⛔ The redirect parser's error list, shown where the
									 * client can act on it.
									 *
									 * parse() has always returned these, and the docblock has
									 * always said they exist "so the settings screen can show
									 * what was ignored" — but until 2026-08-20 nothing
									 * rendered them, so a mistyped rule was computed, dropped
									 * and never mentioned. A map with silent holes is worse
									 * than no map, because it reads as complete.
									 */
									if ( 'redirects.map' === $key ) :
										$parsed = \AJR\StarterCore\Seo\Redirects::parse( (string) $value );
										$count  = count( $parsed['rules'] );
										/* translators: %d: number of redirect rules currently active. */
										$rule_summary = sprintf( _n( '%d active rule.', '%d active rules.', $count, 'ajr-starter-core' ), $count );
										?>
										<p class="description">
											<strong><?php echo esc_html( $rule_summary ); ?></strong>
										</p>
										<?php if ( ! empty( $parsed['errors'] ) ) : ?>
											<div class="notice notice-warning inline" style="margin:8px 0;padding:8px 12px">
												<p><strong><?php esc_html_e( 'These lines were ignored:', 'ajr-starter-core' ); ?></strong></p>
												<ul style="margin:0;list-style:disc inside">
													<?php foreach ( $parsed['errors'] as $error ) : ?>
														<li><?php echo esc_html( $error ); ?></li>
													<?php endforeach; ?>
												</ul>
											</div>
										<?php endif; ?>
									<?php endif; ?>

									<?php
									// Same contract as the redirect map: show what the parser ignored.
									if ( 'agents.webmcp_forms' === $key ) :
										$webmcp = \AJR\StarterCore\Agents\WebMCP_Forms::parse( (string) $value );
										$tools  = count( $webmcp['rules'] );
										/* translators: %d: number of forms exposed to AI agents. */
										$tool_summary = sprintf( _n( '%d form exposed to agents.', '%d forms exposed to agents.', $tools, 'ajr-starter-core' ), $tools );
										?>
										<p class="description">
											<strong><?php echo esc_html( $tool_summary ); ?></strong>
										</p>
										<?php if ( ! empty( $webmcp['errors'] ) ) : ?>
											<div class="notice notice-warning inline" style="margin:8px 0;padding:8px 12px">
												<p><strong><?php esc_html_e( 'These lines were ignored:', 'ajr-starter-core' ); ?></strong></p>
												<ul style="margin:0;list-style:disc inside">
													<?php foreach ( $webmcp['errors'] as $error ) : ?>
														<li><?php echo esc_html( $error ); ?></li>
													<?php endforeach; ?>
												</ul>
											</div>
										<?php endif; ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endforeach; ?>

				<?php submit_button(); ?>
			</form>

			<?php $this->render_agent_access(); ?>
		</div>
		<?php
	}

	/**
	 * The REST application-password panel.
	 *
	 * ⛔ THIS SECTION IS WHY Admin\Agent_Access EXISTS, AND IT WAS MISSING.
	 *
	 * Until 2026-08-20 the module registered two `admin_post_` handlers and nothing rendered
	 * a form, a nonce, or the generated password — its own docblock said "this puts a button
	 * on the settings page" and there was no button. Two consequences, both found by review:
	 * the feature was unreachable, and `consume_new_password()` had no caller, so the
	 * plaintext credential was written to a transient that nothing ever read and therefore
	 * nothing ever deleted early. It sat in `wp_options` in cleartext until the daily sweep.
	 *
	 * This renders it, and reading the password here is what deletes it.
	 *
	 * Rendered outside the settings <form> deliberately — it posts to admin-post.php with
	 * its own nonce, and nesting a form inside options.php's form would break both.
	 */
	protected function render_agent_access(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$password = Agent_Access::consume_new_password();
		$existing = Agent_Access::existing();
		?>
		<hr />
		<h2><?php esc_html_e( 'Tooling access (REST API)', 'ajr-starter-core' ); ?></h2>

		<p class="description" style="max-width:46em">
			<?php esc_html_e( 'Creates a WordPress application password so external tooling can read and write this site over the REST API. It is shown once and cannot be retrieved afterwards — if it is lost, revoke it and create another.', 'ajr-starter-core' ); ?>
		</p>

		<?php if ( '' !== $password ) : ?>
			<div class="notice notice-success">
				<p><strong><?php esc_html_e( 'Copy this now. It will not be shown again.', 'ajr-starter-core' ); ?></strong></p>
				<p><code style="font-size:15px;user-select:all"><?php echo esc_html( $password ); ?></code></p>
			</div>
		<?php endif; ?>

		<?php if ( ! Agent_Access::available() ) : ?>
			<div class="notice notice-warning inline" style="margin:8px 0;padding:8px 12px">
				<p><?php esc_html_e( 'Application passwords are unavailable on this site. WordPress disables them without HTTPS — that is a correct refusal, not a fault to work around.', 'ajr-starter-core' ); ?></p>
			</div>
			<?php return; ?>
		<?php endif; ?>

		<?php if ( $existing ) : ?>
			<table class="widefat striped" style="max-width:46em;margin-bottom:12px">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Name', 'ajr-starter-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last used', 'ajr-starter-core' ); ?></th>
						<th scope="col"></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $existing as $item ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $item['name'] ?? '' ) ); ?></td>
						<td>
							<?php
							echo esc_html(
								! empty( $item['last_used'] )
									? date_i18n( get_option( 'date_format' ), (int) $item['last_used'] )
									: __( 'never', 'ajr-starter-core' )
							);
							?>
						</td>
						<?php
						$revoke_url = wp_nonce_url(
							add_query_arg(
								[
									'action' => 'ajr_agent_access_revoke',
									'uuid'   => (string) ( $item['uuid'] ?? '' ),
								],
								admin_url( 'admin-post.php' )
							),
							Agent_Access::NONCE_REVOKE
						);
						?>
						<td style="text-align:right">
							<a class="button button-link-delete" href="<?php echo esc_url( $revoke_url ); ?>">
								<?php esc_html_e( 'Revoke', 'ajr-starter-core' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php
		/*
		 * ⛔ POST, not a link. `admin_post_*` fires on GET too, and check_admin_referer()
		 * with a named action is nonce-only — so a GET URL that mints a permanent,
		 * admin-equivalent REST credential would live in browser history, in synced
		 * bookmarks, in referrer chains and in front of link prefetchers, minting another
		 * one on every replay for up to 24 hours. A form makes replay require intent.
		 */
		?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="ajr_agent_access_create" />
			<?php wp_nonce_field( Agent_Access::NONCE_CREATE ); ?>
			<?php submit_button( __( 'Create application password', 'ajr-starter-core' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}
}
