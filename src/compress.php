<?php
/**
 * Compression and per-file tracking.
 *
 * JPEG and WebP: WordPress encodes each size, and the full size when it
 * converts or scales it, at GRIST_JPEG_QUALITY, so every file is encoded
 * once. Grist then measures what WordPress would have made without it (a
 * throwaway encode at its default quality, never written to disk) to record
 * the saving.
 *
 * PNG: every file is reduced to a GRIST_PNG_COLOURS palette after WordPress
 * saves it, and only kept if smaller.
 *
 * A full-size upload WordPress served as it was (any PNG, or a JPEG it
 * didn't convert or scale) is compressed into a `-min` copy, and the upload
 * is kept as the attachment's original, as WordPress does when it scales an
 * image. Regenerating always starts from the untouched upload.
 *
 * Every file (full size, each image size, Crop Thumbnails re-crops) gets a
 * `grist` record in the attachment metadata: bytes before and after, and a
 * status. A file without one hasn't been processed: it was uploaded before
 * Grist, or something else made it.
 */

// WordPress's own defaults, from WP_Image_Editor::get_default_quality(): the "before" for lossy files.
const GRIST_WP_QUALITY = array(
	'image/jpeg' => 82,
	'image/webp' => 86,
);

add_filter( 'big_image_size_threshold', fn() => grist_setting( 'MAX_SIZE' ) );

add_filter(
	'image_editor_output_format',
	fn( array $formats ): array => grist_setting( 'JPEG_TO_WEBP' ) ? array( 'image/jpeg' => 'image/webp' ) + $formats : $formats
);

add_filter(
	'wp_editor_set_quality',
	fn( $quality, $mime ) => isset( GRIST_WP_QUALITY[ $mime ] ) ? (int) grist_setting( 'JPEG_QUALITY' ) : $quality,
	10,
	2
);

/**
 * A file's tracking record.
 *
 * @param int    $before Bytes WordPress would have made (or the file had) before Grist.
 * @param int    $after  Bytes on disk now.
 * @param string $status Set only for files Grist couldn't compress or measure.
 * @return array{before: int, after: int, status: string}
 */
function grist_record( int $before, int $after, string $status = '' ): array {
	return array(
		'before' => $before,
		'after'  => $after,
		'status' => $status ? $status : ( $after < $before ? 'compressed' : 'no_saving' ),
	);
}

/**
 * Compress $path into $dest (which may be $path itself), only if the result
 * is smaller. PNGs are reduced to a palette; JPEG and WebP are re-encoded at
 * GRIST_JPEG_QUALITY.
 *
 * @param string $path Source file.
 * @param string $dest Where the compressed file goes.
 * @return bool Whether $dest was written.
 */
function grist_compress_file( string $path, string $dest ): bool {
	$tmp = (string) preg_replace( '/(\.\w+)$/', '.grist-tmp$1', $dest );

	if ( 'image/png' === wp_get_image_mime( $path ) ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a corrupt PNG is recorded as not compressed.
		$image = grist_setting( 'PNG_LOSSY' ) ? @imagecreatefrompng( $path ) : false;

		if ( ! $image || ! imageistruecolor( $image ) ) {
			return false;
		}

		imagesavealpha( $image, true );
		imagetruecolortopalette( $image, (bool) grist_setting( 'PNG_DITHER' ), (int) grist_setting( 'PNG_COLOURS' ) );
		imagepng( $image, $tmp, 9 );
	} else {
		$editor = wp_get_image_editor( $path );
		$saved  = is_wp_error( $editor ) ? $editor : $editor->save( $tmp );

		if ( is_wp_error( $saved ) || $saved['path'] !== $tmp ) {
			// The editor changed format (JPEG_TO_WEBP applies to any save): not a like-for-like replacement.
			is_wp_error( $saved ) || wp_delete_file( $saved['path'] );
			return false;
		}
	}

	if ( is_file( $tmp ) && filesize( $tmp ) < filesize( $path ) ) {
		rename( $tmp, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- same-directory move; WP_Filesystem adds nothing here.
		clearstatcache( true, $dest );
		return true;
	}

	wp_delete_file( $tmp );

	return false;
}

/**
 * Bytes WordPress would have made for this size without Grist: the source
 * resized and encoded at WordPress's default quality, in memory only.
 *
 * @param GdImage $source Decoded original upload.
 * @param string  $mime   Original upload's type (a GRIST_WP_QUALITY key).
 * @param int     $width  Size width.
 * @param int     $height Size height.
 */
function grist_baseline( GdImage $source, string $mime, int $width, int $height ): int {
	$dims  = image_resize_dimensions( imagesx( $source ), imagesy( $source ), $width, $height, true );
	$image = $source;

	if ( $dims ) {
		[ $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h ] = $dims;

		$image = imagecreatetruecolor( $dst_w, $dst_h );
		imagecopyresampled( $image, $source, $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h );
	}

	ob_start();

	if ( 'image/webp' === $mime ) {
		imagewebp( $image, null, GRIST_WP_QUALITY[ $mime ] );
	} else {
		imagejpeg( $image, null, GRIST_WP_QUALITY[ $mime ] );
	}

	return strlen( (string) ob_get_clean() );
}

/**
 * Compress and record every file of an attachment that has no record yet.
 *
 * @param array<string, mixed> $metadata      Attachment metadata.
 * @param int                  $attachment_id Attachment ID.
 * @return array<string, mixed>
 */
function grist_process( array $metadata, int $attachment_id ): array {
	if ( empty( $metadata['file'] ) || ! wp_attachment_is_image( $attachment_id ) ) {
		return $metadata;
	}

	wp_raise_memory_limit( 'image' );

	// From the metadata, not get_attached_file(): when regenerating, the metadata is new and the attached file may not be yet.
	$full     = path_join( wp_get_upload_dir()['basedir'], $metadata['file'] );
	$dir      = dirname( $full );
	$original = empty( $metadata['original_image'] ) ? $full : $dir . '/' . $metadata['original_image'];
	$src_mime = (string) wp_get_image_mime( $original );
	$src_size = wp_getimagesize( $original );
	$source   = null;
	$records  = array();

	foreach ( array_merge( array( '' ), array_keys( $metadata['sizes'] ?? array() ) ) as $size ) {
		$entry = '' === $size ? $metadata : $metadata['sizes'][ $size ];
		$path  = $dir . '/' . wp_basename( $entry['file'] );

		if ( isset( $entry['grist'] ) || ! is_file( $path ) ) {
			continue;
		}

		$mime   = wp_get_image_mime( $path );
		$before = (int) filesize( $path );

		if ( isset( $records[ $path ] ) ) {
			$record = $records[ $path ]; // Two sizes with the same dimensions share a file.
		} elseif ( ! isset( GRIST_WP_QUALITY[ $mime ] ) && 'image/png' !== $mime ) {
			$record = grist_record( $before, $before, 'unsupported' ); // GIF, AVIF, etc.
		} elseif ( '' === $size && $path === $original ) {
			$min = (string) preg_replace( '/(\.\w+)$/', '-min$1', $path );

			if ( grist_compress_file( $path, $min ) ) {
				update_attached_file( $attachment_id, $min );
				$metadata['file']           = _wp_relative_upload_path( $min );
				$metadata['original_image'] = wp_basename( $path );
				$path                       = $min;
			}

			$record = grist_record( $before, (int) filesize( $path ) );
		} elseif ( 'image/png' === $mime ) {
			grist_compress_file( $path, $path );
			$record = grist_record( $before, (int) filesize( $path ) );
		} elseif ( '' === $size && $src_size && array( $entry['width'], $entry['height'] ) === array( $src_size[0], $src_size[1] ) ) {
			$record = grist_record( (int) filesize( $original ), $before ); // Converted, not scaled or rotated: WordPress would have served the upload itself.
		} else {
			$source ??= grist_decode( $original, $metadata );
			$record   = $source && isset( GRIST_WP_QUALITY[ $src_mime ] )
				? grist_record( grist_baseline( $source, $src_mime, (int) $entry['width'], (int) $entry['height'] ), $before )
				: grist_record( $before, $before, 'unmeasured' );
		}

		$records[ $path ] = $record;

		if ( '' === $size ) {
			$metadata['grist']    = $record;
			$metadata['filesize'] = $record['after'];
		} else {
			$metadata['sizes'][ $size ]['grist']    = $record;
			$metadata['sizes'][ $size ]['filesize'] = $record['after'];
		}
	}

	return $metadata;
}

/**
 * Decode the original upload for baselines, turned to match the full-size
 * image when WordPress rotated it from EXIF.
 *
 * @param string               $path     Original upload.
 * @param array<string, mixed> $metadata Attachment metadata.
 */
function grist_decode( string $path, array $metadata ): ?GdImage {
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- undecodable just means unmeasured.
	$image = @imagecreatefromstring( (string) file_get_contents( $path ) );

	if ( ! $image ) {
		return null;
	}

	// ponytail: only the orientation is matched, not the exact rotation; byte counts don't depend on which way up it is.
	if ( ( imagesx( $image ) > imagesy( $image ) ) !== ( $metadata['width'] > $metadata['height'] ) ) {
		$image = imagerotate( $image, 90, 0 );
	}

	return $image ? $image : null;
}

add_filter( 'wp_generate_attachment_metadata', 'grist_process', 10, 2 );
add_filter( 'crop_thumbnails_before_update_metadata', 'grist_process', 10, 2 );

/*
 * Per-attachment totals as plain post meta, so the widget can sum them and
 * the Media Library can filter on them without unpacking every image's
 * metadata. Runs on every metadata save, including WordPress's partial saves
 * during upload, so the totals are never stale.
 */
add_filter(
	'wp_update_attachment_metadata',
	function ( $metadata, $attachment_id ) {
		if ( ! is_array( $metadata ) || empty( $metadata['file'] ) || ! wp_attachment_is_image( $attachment_id ) ) {
			return $metadata;
		}

		$files = array();

		foreach ( array_merge( array( $metadata ), $metadata['sizes'] ?? array() ) as $entry ) {
			$files[ wp_basename( $entry['file'] ) ] = $entry['grist'] ?? null;
		}

		$records = array_filter( $files );
		$before  = array_sum( array_column( $records, 'before' ) );

		update_post_meta( $attachment_id, '_grist_files', count( $files ) );
		update_post_meta( $attachment_id, '_grist_pending', count( $files ) - count( $records ) );
		update_post_meta( $attachment_id, '_grist_before', $before );
		update_post_meta( $attachment_id, '_grist_saved', $before - array_sum( array_column( $records, 'after' ) ) );

		return $metadata;
	},
	20,
	2
);
