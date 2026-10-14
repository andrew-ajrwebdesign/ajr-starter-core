<?php
/**
 * Image_Sizes — fill the gaps in core's responsive image ladder.
 *
 * WHY THIS IS IN THE PLUGIN AND NOT THE THEME
 *
 * A proven win on a full rebuild, and the reason is structural: image sizes must survive a
 * theme change. Sizes registered in a theme stop existing the moment the theme does, and
 * every `srcset` that referenced them silently narrows to whatever core still generates.
 * Worse, regenerating thumbnails on a live library is expensive and slow, so the mistake is
 * costly to undo long after it is noticed.
 *
 * WHAT IT ACTUALLY FIXES
 *
 * Core's default ladder is 150 / 300 / 768 / 1024 / 1536 / 2048. The gap between 300 and 768
 * is the one that hurts: a card slot around 400–600px wide has nothing close to offer, so
 * the browser takes 768w and the visitor downloads roughly twice the pixels they can see.
 * Adding rungs is the cheapest page-weight win available on an image-heavy build.
 *
 * ⛔ THE RULE THAT GOVERNS ADDING A RUNG
 * `srcset` selection is "smallest candidate >= the requested width". A rung at 400 does NOT
 * serve a 430px slot — it is simply too small, and the browser takes the next one up. So
 * never describe a rung as serving a slot without running that comparison for the pair; a
 * docblock that claimed otherwise once argued for deleting a rung that was doing real work.
 *
 * @package AJR\StarterCore
 */

declare( strict_types=1 );

namespace AJR\StarterCore\Media;

use AJR\StarterCore\Core\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Registers extra intermediate image sizes.
 */
class Image_Sizes {

	/**
	 * The rungs this plugin adds, width => crop.
	 *
	 * Chosen to close core's 300→768 gap and to give large hero slots a step below 1536.
	 * All uncropped: a crop changes the aspect ratio and is a design decision, which
	 * belongs in the theme's markup, not in a global size registration.
	 *
	 * @var array<int,bool>
	 */
	protected const SIZES = [
		400  => false,
		600  => false,
		900  => false,
		1200 => false,
	];

	/**
	 * Register hooks.
	 */
	public function register(): void {
		if ( ! Config::get( 'media.extra_sizes' ) ) {
			return;
		}

		add_action( 'after_setup_theme', [ $this, 'add_sizes' ] );
		add_filter( 'wp_calculate_image_srcset', [ $this, 'keep_srcset_sane' ], 10, 5 );
	}

	/**
	 * Add the intermediate sizes.
	 */
	public function add_sizes(): void {
		foreach ( self::SIZES as $width => $crop ) {
			add_image_size( 'ajr-' . $width, $width, 9999, $crop );
		}
	}

	/**
	 * Drop candidates wider than the original from srcset.
	 *
	 * WordPress will happily list an upscaled candidate when a registered size exceeds the
	 * uploaded file's own width. The browser then downloads a larger file that contains no
	 * more detail — a pure loss, and invisible unless someone reads the markup.
	 *
	 * @param array<int,array<string,mixed>> $sources    Candidate sources keyed by width.
	 * @param array<int,int>                 $size_array Requested size.
	 * @param string                         $image_src  Source URL.
	 * @param array<string,mixed>            $image_meta Attachment metadata.
	 * @param int                            $attachment_id Attachment ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function keep_srcset_sane( $sources, $size_array, $image_src, $image_meta, $attachment_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature is fixed by the wp_calculate_image_srcset filter.
		if ( ! is_array( $sources ) || empty( $image_meta['width'] ) ) {
			return $sources;
		}

		$original = (int) $image_meta['width'];

		foreach ( array_keys( $sources ) as $width ) {
			if ( (int) $width > $original ) {
				unset( $sources[ $width ] );
			}
		}

		return $sources;
	}
}
