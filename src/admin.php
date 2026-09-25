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
function grist_describe( int $attachment_id ): string {
	$pending = get_post_meta( $attachment_id, '_grist_pending', true );

	if ( '' === $pending ) {
		return __( 'Not compressed', 'grist' );
	}

	$files  = (int) get_post_meta( $attachment_id, '_grist_files', true );
	$before = (int) get_post_meta( $attachment_id, '_grist_before', true );
	$saved  = (int) get_post_meta( $attachment_id, '_grist_saved', true );

	if ( (int) $pending > 0 ) {
		/* translators: 1: files not compressed, 2: total files. */
		return sprintf( __( '%1$d of %2$d files not compressed', 'grist' ), $pending, $files );
	}

	/* translators: 1: size saved, e.g. 1.2 MB, 2: percentage, 3: number of files. */
	return sprintf( __( 'Saved %1$s (%2$d%%) · %3$d files', 'grist' ), size_format( max( 0, $saved ), 1 ), $before ? round( 100 * $saved / $before ) : 0, $files );
}

add_filter(
	'manage_media_columns',
	fn( array $columns ): array => $columns + array( 'grist' => __( 'Compression', 'grist' ) )
);

add_action(
	'manage_media_custom_column',
	function ( string $column, int $attachment_id ): void {
		if ( 'grist' === $column && wp_attachment_is_image( $attachment_id ) ) {
			echo esc_html( grist_describe( $attachment_id ) );
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
		$current = sanitize_key( $_GET['grist'] ?? '' );
		?>
		<select name="grist">
			<option value=""><?php esc_html_e( 'All compression', 'grist' ); ?></option>
			<option value="pending" <?php selected( $current, 'pending' ); ?>><?php esc_html_e( 'Not compressed', 'grist' ); ?></option>
		</select>
		<?php
	}
);

add_action(
	'pre_get_posts',
	function ( WP_Query $query ): void {
		global $pagenow;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		if ( 'upload.php' !== $pagenow || ! $query->is_main_query() || ! isset( $_GET['grist'] ) || 'pending' !== sanitize_key( $_GET['grist'] ) ) {
			return;
		}

		$query->set( 'post_mime_type', 'image' );
		$query->set(
			'meta_query',
			array(
				'relation' => 'OR',
				array(
					'key'     => '_grist_pending',
					'value'   => 0,
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
				array(
					'key'     => '_grist_pending',
					'compare' => 'NOT EXISTS',
				),
			)
		);
	}
);

add_filter(
	'bulk_actions-upload',
	fn( array $actions ): array => $actions + array( 'grist_compress' => __( 'Compress', 'grist' ) )
);

/*
 * Regenerates the selected images from their original uploads, which runs
 * them through Grist. upload.php has already checked the bulk-media nonce.
 * ponytail: one request for the whole selection; fine for a page of the
 * list (20 by default), use `wp media regenerate` for a whole library.
 */
add_filter(
	'handle_bulk_actions-upload',
	function ( string $redirect, string $action, array $ids ): string {
		if ( 'grist_compress' !== $action ) {
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

		return add_query_arg( 'grist_compressed', $done, $redirect );
	},
	10,
	3
);

add_action(
	'admin_notices',
	function (): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only count from our own redirect.
		$count = isset( $_GET['grist_compressed'] ) ? absint( $_GET['grist_compressed'] ) : null;

		if ( null !== $count ) {
			wp_admin_notice(
				/* translators: %d: number of images. */
				esc_html( sprintf( _n( 'Compressed %d image.', 'Compressed %d images.', $count, 'grist' ), $count ) ),
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
function grist_totals(): array {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- aggregate sums; WP has no API for these and they're cheap.
	$sums   = $wpdb->get_row(
		"SELECT
			SUM( CASE WHEN meta_key = '_grist_files' THEN meta_value END ) AS files,
			SUM( CASE WHEN meta_key = '_grist_pending' THEN meta_value END ) AS pending_files,
			SUM( CASE WHEN meta_key = '_grist_pending' AND meta_value = '0' THEN 1 END ) AS done_images,
			SUM( CASE WHEN meta_key = '_grist_before' THEN meta_value END ) AS before_bytes,
			SUM( CASE WHEN meta_key = '_grist_saved' THEN meta_value END ) AS saved
		FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_grist\_%'",
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
			'grist',
			__( 'Image compression', 'grist' ),
			function (): void {
				$t = grist_totals();
				?>
				<style>
					#grist .inside { margin: 0; padding: 0; }
					.grist-body { padding: 16px 12px 4px; }
					.grist-saved { display: flex; align-items: baseline; gap: 8px; margin: 0 0 4px; }
					.grist-saved strong { font-size: 32px; line-height: 1.1; font-weight: 600; }
					.grist-saved span { color: #008a20; font-weight: 600; }
					.grist-bar { height: 6px; margin: 16px 0 6px; background: #f0f0f1; border-radius: 3px; overflow: hidden; }
					.grist-bar div { height: 100%; background: var(--wp-admin-theme-color, #2271b1); }
					.grist-body .notice { margin: 12px 0 0; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
					.grist-body .notice p { margin: 8px 0; }
					.grist-settings { margin: 12px 0 0; padding: 10px 12px; border-top: 1px solid #f0f0f1; background: #f6f7f7; color: #646970; font-size: 12px; }
				</style>
				<div class="grist-body">
					<?php if ( $t['before'] > 0 ) : ?>
						<p class="grist-saved">
							<strong><?php echo esc_html( size_format( max( 0, $t['saved'] ), 1 ) ); ?></strong>
							<?php /* translators: %d: percentage. */ ?>
							<span><?php echo esc_html( sprintf( __( '%d%% smaller', 'grist' ), (int) round( 100 * $t['saved'] / $t['before'] ) ) ); ?></span>
						</p>
						<p class="description"><?php esc_html_e( 'Saved compared with WordPress on its own.', 'grist' ); ?></p>
					<?php endif; ?>
					<div class="grist-bar"><div style="width: <?php echo (int) ( $t['files'] ? round( 100 * $t['done'] / $t['files'] ) : 0 ); ?>%"></div></div>
					<p class="description">
						<?php
						/* translators: 1: compressed files, 2: files, 3: images. */
						printf( esc_html__( '%1$s of %2$s files compressed across %3$s images (full size and every image size).', 'grist' ), esc_html( number_format_i18n( $t['done'] ) ), esc_html( number_format_i18n( $t['files'] ) ), esc_html( number_format_i18n( $t['images'] ) ) );
						?>
					</p>
					<?php if ( $t['pending'] > 0 ) : ?>
						<div class="notice notice-warning inline">
							<p>
								<?php
								/* translators: %s: number of images. */
								printf( esc_html( _n( '%s image needs compressing.', '%s images need compressing.', $t['pending'], 'grist' ) ), esc_html( number_format_i18n( $t['pending'] ) ) );
								?>
								<br><span class="description"><?php esc_html_e( 'Select all, then Bulk actions → Compress.', 'grist' ); ?></span>
							</p>
							<a class="button" href="<?php echo esc_url( admin_url( 'upload.php?mode=list&grist=pending' ) ); ?>"><?php esc_html_e( 'Show them', 'grist' ); ?></a>
						</div>
					<?php endif; ?>
				</div>
				<p class="grist-settings">
					<?php
					printf(
						/* translators: 1: quality 1-100, 2: on/off, 3: PNG setting summary, 4: max size. */
						esc_html__( 'Quality %1$d · JPEG to WebP %2$s · PNG %3$s · max %4$s. Change with GRIST_* constants in wp-config.php.', 'grist' ),
						(int) grist_setting( 'JPEG_QUALITY' ),
						esc_html( grist_setting( 'JPEG_TO_WEBP' ) ? __( 'on', 'grist' ) : __( 'off', 'grist' ) ),
						/* translators: 1: colours, 2: "dithered" or empty. */
						esc_html( grist_setting( 'PNG_LOSSY' ) ? sprintf( __( '%1$d colours%2$s', 'grist' ), grist_setting( 'PNG_COLOURS' ), grist_setting( 'PNG_DITHER' ) ? __( ', dithered', 'grist' ) : '' ) : __( 'lossless', 'grist' ) ),
						esc_html( grist_setting( 'MAX_SIZE' ) ? grist_setting( 'MAX_SIZE' ) . 'px' : __( 'none', 'grist' ) )
					);
					?>
				</p>
				<?php
			}
		);
	}
);
