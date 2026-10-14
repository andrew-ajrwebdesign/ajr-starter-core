<?php
/**
 * Minimal stand-in for WP_Post, which several classes type-check against (`instanceof \WP_Post`).
 *
 * Loaded only when WordPress itself is not, so a real install's class always wins. Tests set what they
 * need on the object; nothing here pretends to behave like WordPress beyond holding data.
 *
 * @package AJR\StarterCore
 */

declare( strict_types=1 );

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * WP_Post stand-in.
	 */
	class WP_Post {

		/** @var int */
		public $ID = 0;

		/** @var string */
		public $post_type = 'post';

		/** @var string */
		public $post_status = 'publish';

		/** @var int|string */
		public $post_author = 0;

		/**
		 * Build from properties.
		 *
		 * @param array<string,mixed> $props Properties.
		 */
		public function __construct( array $props = [] ) {
			foreach ( $props as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}
