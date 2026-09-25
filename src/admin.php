<?php
/**
 * The wp-admin side: dashboard widget, Media Library column, "Not compressed"
 * filter, and a "Compress" bulk action (Media → Library, list view).
 */

/**
 * One attachment's compression, as shown in the Media Library column.
 *
 * @param int $attachment_id Attachment ID.
 */
function wp_image_compression_describe( int $attachment_id ): string {
	$pending = get_post_meta( $attachment_id, '_wp_image_compression_pending', true );

	if ( '' === $pending ) {
		return __( 'Not compressed', 'wp-image-compression' );
	}

	$files  = (int) get_post_meta( $attachment_id, '_wp_image_compression_files', true );
	$before = (int) get_post_meta( $attachment_id, '_wp_image_compression_before', true );
	$saved  = (int) get_post_meta( $attachment_id, '_wp_image_compression_saved', true );

	if ( (int) $pending > 0 ) {
		/* translators: 1: files not compressed, 2: total files. */
		return sprintf( __( '%1$d of %2$d files not compressed', 'wp-image-compression' ), $pending, $files );
	}

	/* translators: 1: size saved, e.g. 1.2 MB, 2: percentage, 3: number of files. */
	return sprintf( __( 'Saved %1$s (%2$d%%) · %3$d files', 'wp-image-compression' ), size_format( max( 0, $saved ), 1 ), $before ? round( 100 * $saved / $before ) : 0, $files );
}

add_filter(
	'manage_media_columns',
	fn( array $columns ): array => $columns + array( 'wp_image_compression' => __( 'Compression', 'wp-image-compression' ) )
);

add_action(
	'manage_media_custom_column',
	function ( string $column, int $attachment_id ): void {
		if ( 'wp_image_compression' === $column && wp_attachment_is_image( $attachment_id ) ) {
			echo esc_html( wp_image_compression_describe( $attachment_id ) );
		}
	},
	10,
	2
);

add_action(
	'restrict_manage_posts',
	function ( string $post_type ): void {
		if ( 'attachment' !== $post_type ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$current = sanitize_key( $_GET['wp_image_compression'] ?? '' );
		?>
		<select name="wp_image_compression">
			<option value=""><?php esc_html_e( 'All compression', 'wp-image-compression' ); ?></option>
			<option value="pending" <?php selected( $current, 'pending' ); ?>><?php esc_html_e( 'Not compressed', 'wp-image-compression' ); ?></option>
		</select>
		<?php
	}
);

add_action(
	'pre_get_posts',
	function ( WP_Query $query ): void {
		global $pagenow;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		if ( 'upload.php' !== $pagenow || ! $query->is_main_query() || ! isset( $_GET['wp_image_compression'] ) || 'pending' !== sanitize_key( $_GET['wp_image_compression'] ) ) {
			return;
		}

		$query->set( 'post_mime_type', 'image' );
		$query->set(
			'meta_query',
			array(
				'relation' => 'OR',
				array(
					'key'     => '_wp_image_compression_pending',
					'value'   => 0,
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
				array(
					'key'     => '_wp_image_compression_pending',
					'compare' => 'NOT EXISTS',
				),
			)
		);
	}
);

add_filter(
	'bulk_actions-upload',
	fn( array $actions ): array => $actions + array( 'wp_image_compression_compress' => __( 'Compress', 'wp-image-compression' ) )
);

/*
 * Regenerates the selected images from their original uploads, which runs
 * them through WP Image Compression. upload.php has already checked the bulk-media nonce.
 * ponytail: one request for the whole selection; fine for a page of the
 * list (20 by default), use `wp media regenerate` for a whole library.
 */
add_filter(
	'handle_bulk_actions-upload',
	function ( string $redirect, string $action, array $ids ): string {
		if ( 'wp_image_compression_compress' !== $action ) {
			return $redirect;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$done = 0;

		foreach ( array_map( 'intval', $ids ) as $id ) {
			$file = wp_get_original_image_path( $id );

			if ( $file && is_file( $file ) && current_user_can( 'edit_post', $id ) && wp_attachment_is_image( $id ) ) {
				wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
				++$done;
			}
		}

		return add_query_arg( 'wp_image_compression_compressed', $done, $redirect );
	},
	10,
	3
);

add_action(
	'admin_notices',
	function (): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only count from our own redirect.
		$count = isset( $_GET['wp_image_compression_compressed'] ) ? absint( $_GET['wp_image_compression_compressed'] ) : null;

		if ( null !== $count ) {
			wp_admin_notice(
				/* translators: %d: number of images. */
				esc_html( sprintf( _n( 'Compressed %d image.', 'Compressed %d images.', $count, 'wp-image-compression' ), $count ) ),
				array(
					'type'        => 'success',
					'dismissible' => true,
				)
			);
		}
	}
);

/**
 * Library-wide totals, summed from the per-attachment meta.
 *
 * @return array{images: int, pending: int, files: int, done: int, before: int, saved: int}
 */
function wp_image_compression_totals(): array {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- aggregate sums; WP has no API for these and they're cheap.
	$sums   = $wpdb->get_row(
		"SELECT
			SUM( CASE WHEN meta_key = '_wp_image_compression_files' THEN meta_value END ) AS files,
			SUM( CASE WHEN meta_key = '_wp_image_compression_pending' THEN meta_value END ) AS pending_files,
			SUM( CASE WHEN meta_key = '_wp_image_compression_pending' AND meta_value = '0' THEN 1 END ) AS done_images,
			SUM( CASE WHEN meta_key = '_wp_image_compression_before' THEN meta_value END ) AS before_bytes,
			SUM( CASE WHEN meta_key = '_wp_image_compression_saved' THEN meta_value END ) AS saved
		FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_wp\_image\_compression\_%'",
		ARRAY_A
	);
	$images = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" );
	// phpcs:enable

	return array(
		'images'  => $images,
		'pending' => $images - (int) $sums['done_images'],
		'files'   => (int) $sums['files'],
		'done'    => (int) $sums['files'] - (int) $sums['pending_files'],
		'before'  => (int) $sums['before_bytes'],
		'saved'   => (int) $sums['saved'],
	);
}

add_action(
	'wp_dashboard_setup',
	function (): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'wp_image_compression',
			__( 'Image compression', 'wp-image-compression' ),
			function (): void {
				$t = wp_image_compression_totals();
				?>
				<style>
					#wp_image_compression .inside { margin: 0; padding: 0; }
					.wp-image-compression-body { padding: 16px 12px 4px; }
					.wp-image-compression-saved { display: flex; align-items: baseline; gap: 8px; margin: 0 0 4px; }
					.wp-image-compression-saved strong { font-size: 32px; line-height: 1.1; font-weight: 600; }
					.wp-image-compression-saved span { color: #008a20; font-weight: 600; }
					.wp-image-compression-bar { height: 6px; margin: 16px 0 6px; background: #f0f0f1; border-radius: 3px; overflow: hidden; }
					.wp-image-compression-bar div { height: 100%; background: var(--wp-admin-theme-color, #2271b1); }
					.wp-image-compression-body .notice { margin: 12px 0 0; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
					.wp-image-compression-body .notice p { margin: 8px 0; }
					.wp-image-compression-settings { margin: 12px 0 0; padding: 10px 12px; border-top: 1px solid #f0f0f1; background: #f6f7f7; color: #646970; font-size: 12px; }
				</style>
				<div class="wp-image-compression-body">
					<?php if ( $t['before'] > 0 ) : ?>
						<p class="wp-image-compression-saved">
							<strong><?php echo esc_html( size_format( max( 0, $t['saved'] ), 1 ) ); ?></strong>
							<?php /* translators: %d: percentage. */ ?>
							<span><?php echo esc_html( sprintf( __( '%d%% smaller', 'wp-image-compression' ), (int) round( 100 * $t['saved'] / $t['before'] ) ) ); ?></span>
						</p>
						<p class="description"><?php esc_html_e( 'Saved compared with WordPress on its own.', 'wp-image-compression' ); ?></p>
					<?php endif; ?>
					<div class="wp-image-compression-bar"><div style="width: <?php echo (int) ( $t['images'] ? round( 100 * ( $t['images'] - $t['pending'] ) / $t['images'] ) : 0 ); ?>%"></div></div>
					<p class="description">
						<?php
						/* translators: 1: compressed images, 2: images, 3: compressed files. */
						printf( esc_html__( '%1$s of %2$s images compressed (%3$s files, counting every image size).', 'wp-image-compression' ), esc_html( number_format_i18n( $t['images'] - $t['pending'] ) ), esc_html( number_format_i18n( $t['images'] ) ), esc_html( number_format_i18n( $t['done'] ) ) );
						?>
					</p>
					<?php if ( $t['pending'] > 0 ) : ?>
						<div class="notice notice-warning inline">
							<p>
								<?php
								/* translators: %s: number of images. */
								printf( esc_html( _n( '%s image needs compressing.', '%s images need compressing.', $t['pending'], 'wp-image-compression' ) ), esc_html( number_format_i18n( $t['pending'] ) ) );
								?>
								<br><span class="description"><?php esc_html_e( 'Select all, then Bulk actions → Compress.', 'wp-image-compression' ); ?></span>
							</p>
							<a class="button" href="<?php echo esc_url( admin_url( 'upload.php?mode=list&wp_image_compression=pending' ) ); ?>"><?php esc_html_e( 'Show them', 'wp-image-compression' ); ?></a>
						</div>
					<?php endif; ?>
				</div>
				<p class="wp-image-compression-settings">
					<?php
					printf(
						/* translators: 1: quality 1-100, 2: on/off, 3: PNG setting summary, 4: max size. */
						esc_html__( 'Quality %1$d · JPEG to WebP %2$s · PNG %3$s · max %4$s. Change with WP_IMAGE_COMPRESSION_* constants in wp-config.php.', 'wp-image-compression' ),
						(int) wp_image_compression_setting( 'JPEG_QUALITY' ),
						esc_html( wp_image_compression_setting( 'JPEG_TO_WEBP' ) ? __( 'on', 'wp-image-compression' ) : __( 'off', 'wp-image-compression' ) ),
						/* translators: 1: colours, 2: "dithered" or empty. */
						esc_html( wp_image_compression_setting( 'PNG_LOSSY' ) ? sprintf( __( '%1$d colours%2$s', 'wp-image-compression' ), wp_image_compression_setting( 'PNG_COLOURS' ), wp_image_compression_setting( 'PNG_DITHER' ) ? __( ', dithered', 'wp-image-compression' ) : '' ) : __( 'lossless', 'wp-image-compression' ) ),
						esc_html( wp_image_compression_setting( 'MAX_SIZE' ) ? wp_image_compression_setting( 'MAX_SIZE' ) . 'px' : __( 'none', 'wp-image-compression' ) )
					);
					?>
				</p>
				<?php
			}
		);
	}
);
