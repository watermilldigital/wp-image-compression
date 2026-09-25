<?php
/**
 * Plugin Name: WP Image Compression
 * Author: WaterMill Digital
 * Author URI: https://watermilldigital.com
 * Version: 2.1.2
 * Description: Image compression: JPEG to WebP at a set quality, lossy PNG, and per-file savings tracking with a dashboard widget and Media Library column.
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 *
 * Settings are wp-config.php constants, e.g.:
 *     define( 'WP_IMAGE_COMPRESSION_JPEG_QUALITY', 75 );
 * See wp_image_compression_setting() for every setting and its default.
 */

/**
 * A setting from its WP_IMAGE_COMPRESSION_* constant, or the default.
 *
 * @param string $name Setting name without the WP_IMAGE_COMPRESSION_ prefix.
 * @return mixed
 */
function wp_image_compression_setting( string $name ) {
	$defaults = array(
		'JPEG_QUALITY' => 80,   // 1-100. Also used for WebP output (converted JPEGs and WebP uploads).
		'JPEG_TO_WEBP' => true, // Convert JPEG uploads to WebP. The original JPEG stays on disk.
		'PNG_LOSSY'    => true, // Reduce PNGs to a palette. Off: PNGs stay lossless, as WordPress saves them.
		'PNG_COLOURS'  => 256,  // 2-256. Fewer colours, smaller files.
		'PNG_DITHER'   => true, // Dithering stops smooth gradients banding. Off: slightly smaller, flat-colour art only.
		'MAX_SIZE'     => 2560, // Longest side in px WordPress scales full-size uploads down to. false: never scale.
	);

	return defined( "WP_IMAGE_COMPRESSION_$name" ) ? constant( "WP_IMAGE_COMPRESSION_$name" ) : $defaults[ $name ];
}

require_once __DIR__ . '/src/compress.php';

if ( is_admin() ) {
	require_once __DIR__ . '/src/admin.php';
}
