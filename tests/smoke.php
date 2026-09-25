<?php
/**
 * End-to-end check against a real WordPress install. Uploads generated
 * images, checks every file got compressed and recorded, then deletes them.
 *
 *     wp eval-file wp-content/mu-plugins/wp-image-compression/tests/smoke.php
 *
 * phpcs:disable -- test script.
 */

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

$tmp = get_temp_dir() . 'wp-image-compression-smoke-' . wp_generate_password( 6, false );
wp_mkdir_p( $tmp );
$ids = array();

function wp_image_compression_check( bool $ok, string $what ): void {
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $what . PHP_EOL;
	if ( ! $ok ) {
		$GLOBALS['wp_image_compression_failed'] = true;
	}
}

// Photo-like: smooth gradients plus noise, the case lossy formats are for.
// Drawn at quarter size and scaled up, since per-pixel GD drawing is slow.
function wp_image_compression_photo( int $w, int $h ): GdImage {
	$qw = intdiv( $w, 4 );
	$qh = intdiv( $h, 4 );
	$i  = imagecreatetruecolor( $qw, $qh );
	for ( $y = 0; $y < $qh; $y++ ) {
		for ( $x = 0; $x < $qw; $x++ ) {
			$n = mt_rand( -12, 12 );
			imagesetpixel( $i, $x, $y, ( max( 0, min( 255, (int) ( 60 + 150 * $y / $qh + $n ) ) ) << 16 ) | ( max( 0, min( 255, (int) ( 90 + 120 * $x / $qw + $n ) ) ) << 8 ) | max( 0, min( 255, (int) ( 200 - 90 * $y / $qh + $n ) ) ) );
		}
	}
	return imagescale( $i, $w, $h, IMG_BILINEAR_FIXED );
}

function wp_image_compression_upload( string $file ): int {
	$copy = $file . '.upload';
	copy( $file, $copy );
	$id = media_handle_sideload( array( 'name' => basename( $file ), 'tmp_name' => $copy ) );
	return is_wp_error( $id ) ? 0 : $id;
}

function wp_image_compression_files( int $id ): array {
	$m    = wp_get_attachment_metadata( $id );
	$dir  = dirname( get_attached_file( $id ) );
	$list = array( 'full' => $m );
	foreach ( $m['sizes'] ?? array() as $name => $s ) {
		$list[ $name ] = $s;
	}
	foreach ( $list as $name => $e ) {
		$list[ $name ]['path'] = $dir . '/' . wp_basename( $e['file'] );
	}
	return $list;
}

function wp_image_compression_all_recorded( int $id, string $label ): void {
	clearstatcache();
	foreach ( wp_image_compression_files( $id ) as $name => $e ) {
		$r = $e['wp_image_compression'] ?? null;
		wp_image_compression_check( is_array( $r ) && $r['after'] === filesize( $e['path'] ) && $e['filesize'] === filesize( $e['path'] ), "$label $name: recorded, sizes match disk" . ( $r ? " ({$r['status']}, {$r['before']} → {$r['after']})" : '' ) );
	}
	wp_image_compression_check( '0' === get_post_meta( $id, '_wp_image_compression_pending', true ), "$label: nothing pending" );
}

imagejpeg( wp_image_compression_photo( 1800, 1200 ), "$tmp/photo.jpg", 95 );
imagejpeg( wp_image_compression_photo( 3200, 2000 ), "$tmp/huge.jpg", 95 );
imagepng( wp_image_compression_photo( 1200, 800 ), "$tmp/graphic.png" );
imagegif( imagecreatetruecolor( 400, 300 ), "$tmp/anim.gif" );

// JPEG under MAX_SIZE: converted to WebP, the upload is the "before" for the full size.
$ids[] = $jpg = wp_image_compression_upload( "$tmp/photo.jpg" );
$m     = wp_get_attachment_metadata( $jpg );
wp_image_compression_check( str_ends_with( $m['file'], '.webp' ) && ! empty( $m['original_image'] ), 'jpeg: full size is WebP, original kept' );
wp_image_compression_check( $m['wp_image_compression']['before'] === filesize( "$tmp/photo.jpg" ), 'jpeg: full-size before = uploaded bytes' );
wp_image_compression_check( 'compressed' === $m['sizes']['medium']['wp_image_compression']['status'], 'jpeg: sizes compressed vs WordPress default' );
wp_image_compression_all_recorded( $jpg, 'jpeg' );

// JPEG over MAX_SIZE: scaled, so the full size's before is a measured encode, not the upload.
$ids[] = $huge = wp_image_compression_upload( "$tmp/huge.jpg" );
$m     = wp_get_attachment_metadata( $huge );
wp_image_compression_check( max( $m['width'], $m['height'] ) === wp_image_compression_setting( 'MAX_SIZE' ), 'huge jpeg: scaled to MAX_SIZE' );
wp_image_compression_check( $m['wp_image_compression']['before'] < filesize( "$tmp/huge.jpg" ), 'huge jpeg: before measured at scaled size' );
wp_image_compression_all_recorded( $huge, 'huge jpeg' );

// PNG: palette, every file; the upload is kept as the original.
$ids[] = $png = wp_image_compression_upload( "$tmp/graphic.png" );
foreach ( wp_image_compression_files( $png ) as $name => $e ) {
	wp_image_compression_check( ! imageistruecolor( imagecreatefrompng( $e['path'] ) ), "png $name: palette" );
}
$m = wp_get_attachment_metadata( $png );
wp_image_compression_check( str_ends_with( get_attached_file( $png ), '-min.png' ) && md5_file( wp_get_original_image_path( $png ) ) === md5_file( "$tmp/graphic.png" ), 'png: full size is a -min copy, upload kept untouched as original' );
wp_image_compression_all_recorded( $png, 'png' );
$png_first = $m;

// Crop Thumbnails re-crop: a fresh truecolor size without a record gets processed.
$path = dirname( get_attached_file( $png ) ) . '/' . $m['sizes']['medium']['file'];
imagepng( wp_image_compression_photo( 300, 200 ), $path );
clearstatcache();
$m['sizes']['medium'] = array( 'file' => basename( $path ), 'width' => 300, 'height' => 200, 'mime-type' => 'image/png', 'filesize' => filesize( $path ) );
wp_update_attachment_metadata( $png, apply_filters( 'crop_thumbnails_before_update_metadata', $m, $png ) );
wp_image_compression_all_recorded( $png, 'png after re-crop' );

// Regenerate (WP-CLI, same path as the bulk action): starts again from the untouched upload.
WP_CLI::runcommand( "media regenerate $png --yes --quiet", array( 'launch' => false ) );
$m = wp_get_attachment_metadata( $png );
wp_image_compression_check( $m['wp_image_compression'] === $png_first['wp_image_compression'] && $m['sizes']['large']['wp_image_compression'] === $png_first['sizes']['large']['wp_image_compression'], 'png regenerate: same results as the first upload, nothing compressed twice' );
wp_image_compression_all_recorded( $png, 'png after regenerate' );

// GIF: left alone, recorded as unsupported.
$ids[] = $gif = wp_image_compression_upload( "$tmp/anim.gif" );
wp_image_compression_check( 'unsupported' === wp_get_attachment_metadata( $gif )['wp_image_compression']['status'], 'gif: unsupported' );

// WP_IMAGE_COMPRESSION_JPEG_TO_WEBP off: JPEG stays JPEG, full size compressed into a -min copy.
remove_all_filters( 'image_editor_output_format' );
$ids[] = $keep = wp_image_compression_upload( "$tmp/photo.jpg" );
$m     = wp_get_attachment_metadata( $keep );
wp_image_compression_check( str_ends_with( $m['file'], '-min.jpg' ) && 'image/jpeg' === wp_get_image_mime( get_attached_file( $keep ) ) && 'compressed' === $m['wp_image_compression']['status'], "jpeg kept as jpeg: full size compressed, {$m['wp_image_compression']['before']} → {$m['wp_image_compression']['after']}" );
wp_image_compression_all_recorded( $keep, 'jpeg kept as jpeg' );

// An upload from before WP Image Compression: no totals until it's compressed.
delete_post_meta( $jpg, '_wp_image_compression_pending' );
wp_image_compression_check( in_array( $jpg, get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_wp_image_compression_pending', 'compare' => 'NOT EXISTS' ) ) ) ), true ), 'pre-WP Image Compression image shows as not compressed' );

$left = array();
foreach ( $ids as $id ) {
	$paths = array_merge( array( wp_get_original_image_path( $id ) ), array_column( wp_image_compression_files( $id ), 'path' ) );
	wp_delete_attachment( $id, true );
	$left = array_merge( $left, array_filter( $paths, 'file_exists' ) );
}
wp_image_compression_check( ! $left, 'delete: original, full size and every size removed' . ( $left ? ': ' . implode( ', ', $left ) : '' ) );
array_map( 'unlink', glob( "$tmp/*" ) );
rmdir( $tmp );

echo empty( $GLOBALS['wp_image_compression_failed'] ) ? "\nAll passed.\n" : "\nFAILURES above.\n";
