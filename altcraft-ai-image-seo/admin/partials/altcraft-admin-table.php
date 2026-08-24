<?php
/**
 * Media SEO Table view.
 *
 * Variables provided by AltCraft_Admin::render_media_table_page(): $stats, $has_key.
 *
 * @package AltCraft_AI_Image_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters (GET), no state is changed.
$altcraft_paged  = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
$altcraft_status = isset( $_GET['altcraft_status'] ) ? sanitize_key( wp_unslash( $_GET['altcraft_status'] ) ) : 'all';
$altcraft_type   = isset( $_GET['altcraft_type'] ) ? sanitize_key( wp_unslash( $_GET['altcraft_type'] ) ) : 'all';
$altcraft_search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
// phpcs:enable

$altcraft_status = in_array( $altcraft_status, array( 'all', 'missing', 'optimized' ), true ) ? $altcraft_status : 'all';
$altcraft_type   = in_array( $altcraft_type, array( 'all', 'woo' ), true ) ? $altcraft_type : 'all';

/**
 * Filters the number of rows per page in the Media SEO Table.
 *
 * @param int $per_page Default 40.
 */
$altcraft_per_page = max( 10, min( 200, (int) apply_filters( 'altcraft_ai_table_per_page', 40 ) ) );

$altcraft_query_args = array(
	'post_type'      => 'attachment',
	'post_status'    => 'inherit',
	'post_mime_type' => 'image',
	'posts_per_page' => $altcraft_per_page,
	'paged'          => $altcraft_paged,
	'orderby'        => 'date',
	'order'          => 'DESC',
	's'              => $altcraft_search,
);

if ( 'missing' === $altcraft_status ) {
	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to filter by ALT text presence.
	$altcraft_query_args['meta_query'] = array(
		'relation' => 'OR',
		array(
			'key'     => '_wp_attachment_image_alt',
			'compare' => 'NOT EXISTS',
		),
		array(
			'key'     => '_wp_attachment_image_alt',
			'value'   => '',
			'compare' => '=',
		),
	);
} elseif ( 'optimized' === $altcraft_status ) {
	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to filter by ALT text presence.
	$altcraft_query_args['meta_query'] = array(
		array(
			'key'     => '_wp_attachment_image_alt',
			'value'   => '',
			'compare' => '!=',
		),
	);
}

$altcraft_woo_filter = static function ( $where, $query ) {
	global $wpdb;
	if ( $query->get( 'altcraft_woo_only' ) ) {
		$where .= " AND {$wpdb->posts}.post_parent IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' )";
	}
	return $where;
};

if ( 'woo' === $altcraft_type ) {
	$altcraft_query_args['altcraft_woo_only'] = true;
	add_filter( 'posts_where', $altcraft_woo_filter, 10, 2 );
}

$altcraft_query = new WP_Query( $altcraft_query_args );

if ( 'woo' === $altcraft_type ) {
	remove_filter( 'posts_where', $altcraft_woo_filter, 10 );
}

$altcraft_images   = $altcraft_query->posts;
$altcraft_base_url = admin_url( 'admin.php?page=altcraft-ai-media-table' );

$altcraft_pill_url = static function ( $status, $type ) use ( $altcraft_base_url, $altcraft_search ) {
	$args = array(
		'altcraft_status' => $status,
		'altcraft_type'   => $type,
	);
	if ( '' !== $altcraft_search ) {
		$args['s'] = $altcraft_search;
	}
	return add_query_arg( $args, $altcraft_base_url );
};
?>
<div class="wrap altcraft-wrap">

	<div class="altcraft-header">
		<div class="altcraft-brand">
			<span class="altcraft-logo" aria-hidden="true"><span class="dashicons dashicons-images-alt2"></span></span>
			<div>
				<h1><?php esc_html_e( 'Media SEO Table', 'altcraft-ai-image-seo' ); ?></h1>
				<p class="altcraft-subline"><?php esc_html_e( 'Edit ALT text, titles and captions inline, or let the AI write them.', 'altcraft-ai-image-seo' ); ?></p>
			</div>
		</div>
		<div class="altcraft-header-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=altcraft-ai-settings' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Settings', 'altcraft-ai-image-seo' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=altcraft-ai-scanner' ) ); ?>" class="button button-primary altcraft-btn-brand"><?php esc_html_e( 'Bulk Scanner', 'altcraft-ai-image-seo' ); ?></a>
		</div>
	</div>

	<?php if ( ! $has_key ) : ?>
		<div class="notice notice-warning altcraft-notice">
			<p>
				<?php esc_html_e( 'No API key configured – the Generate buttons will not work until you add one.', 'altcraft-ai-image-seo' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=altcraft-ai-settings' ) ); ?>"><?php esc_html_e( 'Open settings', 'altcraft-ai-image-seo' ); ?></a>
			</p>
		</div>
	<?php endif; ?>

	<div class="altcraft-card">
		<div class="altcraft-toolbar">
			<div class="altcraft-pills" role="navigation" aria-label="<?php esc_attr_e( 'Filter images', 'altcraft-ai-image-seo' ); ?>">
				<?php $altcraft_in_woo = ( 'woo' === $altcraft_type ); ?>
				<a class="altcraft-pill <?php echo ( ! $altcraft_in_woo && 'all' === $altcraft_status ) ? 'active' : ''; ?>" href="<?php echo esc_url( $altcraft_pill_url( 'all', 'all' ) ); ?>">
					<?php
					printf(
						/* translators: %s: number of images */
						esc_html__( 'All images (%s)', 'altcraft-ai-image-seo' ),
						esc_html( number_format_i18n( $stats['total'] ) )
					);
					?>
				</a>
				<a class="altcraft-pill <?php echo ( ! $altcraft_in_woo && 'missing' === $altcraft_status ) ? 'active' : ''; ?>" href="<?php echo esc_url( $altcraft_pill_url( 'missing', 'all' ) ); ?>">
					<?php
					printf(
						/* translators: %s: number of images */
						esc_html__( 'Missing ALT (%s)', 'altcraft-ai-image-seo' ),
						esc_html( number_format_i18n( $stats['missing'] ) )
					);
					?>
				</a>
				<a class="altcraft-pill <?php echo ( ! $altcraft_in_woo && 'optimized' === $altcraft_status ) ? 'active' : ''; ?>" href="<?php echo esc_url( $altcraft_pill_url( 'optimized', 'all' ) ); ?>">
					<?php
					printf(
						/* translators: %s: number of images */
						esc_html__( 'Optimized (%s)', 'altcraft-ai-image-seo' ),
						esc_html( number_format_i18n( $stats['optimized'] ) )
					);
					?>
				</a>
				<?php if ( post_type_exists( 'product' ) ) : ?>
					<a class="altcraft-pill <?php echo $altcraft_in_woo ? 'active' : ''; ?>" href="<?php echo esc_url( $altcraft_pill_url( 'all', 'woo' ) ); ?>">
						<?php
						printf(
							/* translators: %s: number of images */
							esc_html__( 'WooCommerce (%s)', 'altcraft-ai-image-seo' ),
							esc_html( number_format_i18n( $stats['woo'] ) )
						);
						?>
					</a>
				<?php endif; ?>
			</div>

			<form method="get" action="<?php echo esc_url( $altcraft_base_url ); ?>" class="altcraft-search">
				<input type="hidden" name="page" value="altcraft-ai-media-table">
				<input type="hidden" name="altcraft_status" value="<?php echo esc_attr( $altcraft_status ); ?>">
				<input type="hidden" name="altcraft_type" value="<?php echo esc_attr( $altcraft_type ); ?>">
				<label class="screen-reader-text" for="altcraft-media-search"><?php esc_html_e( 'Search images', 'altcraft-ai-image-seo' ); ?></label>
				<input type="search" id="altcraft-media-search" name="s" value="<?php echo esc_attr( $altcraft_search ); ?>" placeholder="<?php esc_attr_e( 'Search title, filename, caption…', 'altcraft-ai-image-seo' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Search', 'altcraft-ai-image-seo' ); ?></button>
			</form>
		</div>

		<?php if ( 'woo' === $altcraft_type ) : ?>
			<p class="altcraft-subfilter">
				<span class="altcraft-muted"><?php esc_html_e( 'Product images:', 'altcraft-ai-image-seo' ); ?></span>
				<a class="<?php echo 'all' === $altcraft_status ? 'active' : ''; ?>" href="<?php echo esc_url( $altcraft_pill_url( 'all', 'woo' ) ); ?>"><?php esc_html_e( 'All', 'altcraft-ai-image-seo' ); ?></a>
				<a class="<?php echo 'missing' === $altcraft_status ? 'active' : ''; ?>" href="<?php echo esc_url( $altcraft_pill_url( 'missing', 'woo' ) ); ?>"><?php esc_html_e( 'Missing ALT', 'altcraft-ai-image-seo' ); ?></a>
				<a class="<?php echo 'optimized' === $altcraft_status ? 'active' : ''; ?>" href="<?php echo esc_url( $altcraft_pill_url( 'optimized', 'woo' ) ); ?>"><?php esc_html_e( 'Optimized', 'altcraft-ai-image-seo' ); ?></a>
			</p>
		<?php endif; ?>

		<?php if ( empty( $altcraft_images ) ) : ?>
			<div class="altcraft-empty">
				<span class="dashicons dashicons-format-image" aria-hidden="true"></span>
				<?php if ( '' !== $altcraft_search || 'all' !== $altcraft_status || 'all' !== $altcraft_type ) : ?>
					<h4><?php esc_html_e( 'No images match this filter', 'altcraft-ai-image-seo' ); ?></h4>
					<p><a href="<?php echo esc_url( $altcraft_base_url ); ?>"><?php esc_html_e( 'Show all images', 'altcraft-ai-image-seo' ); ?></a></p>
				<?php else : ?>
					<h4><?php esc_html_e( 'No images in your Media Library yet', 'altcraft-ai-image-seo' ); ?></h4>
					<p><?php esc_html_e( 'Upload images in Media → Add New and they will appear here.', 'altcraft-ai-image-seo' ); ?></p>
					<a href="<?php echo esc_url( admin_url( 'media-new.php' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Upload images', 'altcraft-ai-image-seo' ); ?></a>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<p class="altcraft-quickfilter">
				<label for="altcraft-quick-filter"><?php esc_html_e( 'Filter rows on this page:', 'altcraft-ai-image-seo' ); ?></label>
				<input type="text" id="altcraft-quick-filter" placeholder="<?php esc_attr_e( 'Type to narrow the list…', 'altcraft-ai-image-seo' ); ?>">
			</p>

			<div class="altcraft-table-wrap">
				<table class="wp-list-table widefat striped altcraft-table">
					<thead>
						<tr>
							<th scope="col" class="altcraft-col-thumb"><?php esc_html_e( 'Image', 'altcraft-ai-image-seo' ); ?></th>
							<th scope="col" class="altcraft-col-file"><?php esc_html_e( 'File', 'altcraft-ai-image-seo' ); ?></th>
							<th scope="col" class="altcraft-col-alt"><?php esc_html_e( 'ALT text', 'altcraft-ai-image-seo' ); ?></th>
							<th scope="col" class="altcraft-col-title"><?php esc_html_e( 'Title & caption', 'altcraft-ai-image-seo' ); ?></th>
							<th scope="col" class="altcraft-col-status"><?php esc_html_e( 'Status', 'altcraft-ai-image-seo' ); ?></th>
							<th scope="col" class="altcraft-col-actions"><?php esc_html_e( 'Actions', 'altcraft-ai-image-seo' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $altcraft_images as $altcraft_img ) :
							$altcraft_id        = (int) $altcraft_img->ID;
							$altcraft_alt       = (string) get_post_meta( $altcraft_id, '_wp_attachment_image_alt', true );
							$altcraft_missing   = ( '' === trim( $altcraft_alt ) );
							$altcraft_file      = get_attached_file( $altcraft_id );
							$altcraft_filename  = $altcraft_file ? wp_basename( $altcraft_file ) : $altcraft_img->post_title;
							$altcraft_meta      = wp_get_attachment_metadata( $altcraft_id );
							$altcraft_dim       = ( ! empty( $altcraft_meta['width'] ) && ! empty( $altcraft_meta['height'] ) ) ? $altcraft_meta['width'] . ' × ' . $altcraft_meta['height'] : '';
							$altcraft_size      = ( ! empty( $altcraft_meta['filesize'] ) ) ? size_format( (int) $altcraft_meta['filesize'] ) : '';
							$altcraft_thumb     = wp_get_attachment_image_url( $altcraft_id, 'thumbnail' );
							$altcraft_generated = (int) get_post_meta( $altcraft_id, AltCraft_Generator::META_GENERATED, true );
							$altcraft_error     = get_post_meta( $altcraft_id, AltCraft_Generator::META_LAST_ERROR, true );
							$altcraft_context   = AltCraft_Context::get( $altcraft_id );
							$altcraft_row_type  = ! empty( $altcraft_context['is_product'] ) ? 'woo' : 'general';
							$altcraft_can_edit  = AltCraft_Media_Hooks::user_can_generate( $altcraft_id );
							$altcraft_file_ok   = AltCraft_Image::can_generate( $altcraft_id );
							$altcraft_no_file   = is_wp_error( $altcraft_file_ok );
							?>
							<tr class="altcraft-row" data-id="<?php echo esc_attr( $altcraft_id ); ?>" data-status="<?php echo $altcraft_missing ? 'missing' : 'optimized'; ?>" data-type="<?php echo esc_attr( $altcraft_row_type ); ?>">
								<td class="altcraft-col-thumb">
									<a href="<?php echo esc_url( get_edit_post_link( $altcraft_id ) ); ?>" title="<?php esc_attr_e( 'Open in Media Library', 'altcraft-ai-image-seo' ); ?>">
										<?php if ( $altcraft_thumb ) : ?>
											<img src="<?php echo esc_url( $altcraft_thumb ); ?>" alt="" class="altcraft-thumb" loading="lazy">
										<?php else : ?>
											<span class="altcraft-thumb altcraft-thumb-empty"><span class="dashicons dashicons-format-image"></span></span>
										<?php endif; ?>
									</a>
								</td>
								<td class="altcraft-col-file">
									<strong class="altcraft-filename"><?php echo esc_html( $altcraft_filename ); ?></strong>
									<span class="altcraft-muted">
										<?php echo esc_html( trim( $altcraft_dim . ( $altcraft_size ? ' · ' . $altcraft_size : '' ) ) ); ?>
									</span>
									<?php if ( ! empty( $altcraft_context['parent_title'] ) ) : ?>
										<span class="altcraft-tag <?php echo ! empty( $altcraft_context['is_product'] ) ? 'altcraft-tag-woo' : ''; ?>">
											<?php
											echo ! empty( $altcraft_context['is_product'] )
												? esc_html__( 'Product:', 'altcraft-ai-image-seo' )
												: esc_html__( 'Used in:', 'altcraft-ai-image-seo' );
											?>
											<?php echo esc_html( $altcraft_context['parent_title'] ); ?>
										</span>
									<?php endif; ?>
									<?php if ( $altcraft_generated ) : ?>
										<span class="altcraft-muted altcraft-ai-stamp">
											<?php
											printf(
												/* translators: %s: human readable time difference */
												esc_html__( 'AI generated %s ago', 'altcraft-ai-image-seo' ),
												esc_html( human_time_diff( $altcraft_generated ) )
											);
											?>
										</span>
									<?php endif; ?>
								</td>
								<td class="altcraft-col-alt">
									<label class="screen-reader-text" for="altcraft-alt-<?php echo esc_attr( $altcraft_id ); ?>"><?php esc_html_e( 'ALT text', 'altcraft-ai-image-seo' ); ?></label>
									<textarea id="altcraft-alt-<?php echo esc_attr( $altcraft_id ); ?>" class="altcraft-alt-input" rows="2" placeholder="<?php esc_attr_e( 'No ALT text yet', 'altcraft-ai-image-seo' ); ?>" <?php disabled( ! $altcraft_can_edit ); ?>><?php echo esc_textarea( $altcraft_alt ); ?></textarea>
									<?php if ( is_array( $altcraft_error ) && ! empty( $altcraft_error['message'] ) && $altcraft_missing ) : ?>
										<span class="altcraft-row-error" title="<?php echo esc_attr( $altcraft_error['message'] ); ?>"><?php esc_html_e( 'Last attempt failed – hover for details', 'altcraft-ai-image-seo' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="altcraft-col-title">
									<label class="screen-reader-text" for="altcraft-title-<?php echo esc_attr( $altcraft_id ); ?>"><?php esc_html_e( 'Title', 'altcraft-ai-image-seo' ); ?></label>
									<input type="text" id="altcraft-title-<?php echo esc_attr( $altcraft_id ); ?>" class="altcraft-title-input" value="<?php echo esc_attr( $altcraft_img->post_title ); ?>" placeholder="<?php esc_attr_e( 'Title', 'altcraft-ai-image-seo' ); ?>" <?php disabled( ! $altcraft_can_edit ); ?>>
									<label class="screen-reader-text" for="altcraft-caption-<?php echo esc_attr( $altcraft_id ); ?>"><?php esc_html_e( 'Caption', 'altcraft-ai-image-seo' ); ?></label>
									<input type="text" id="altcraft-caption-<?php echo esc_attr( $altcraft_id ); ?>" class="altcraft-caption-input" value="<?php echo esc_attr( $altcraft_img->post_excerpt ); ?>" placeholder="<?php esc_attr_e( 'Caption (optional)', 'altcraft-ai-image-seo' ); ?>" <?php disabled( ! $altcraft_can_edit ); ?>>
								</td>
								<td class="altcraft-col-status">
									<span class="altcraft-badge <?php echo $altcraft_missing ? 'altcraft-badge-missing' : 'altcraft-badge-optimized'; ?>">
										<?php echo $altcraft_missing ? esc_html__( 'Missing ALT', 'altcraft-ai-image-seo' ) : esc_html__( 'Optimized', 'altcraft-ai-image-seo' ); ?>
									</span>
									<?php if ( $altcraft_no_file ) : ?>
										<span class="altcraft-badge altcraft-badge-nofile" title="<?php echo esc_attr( $altcraft_file_ok->get_error_message() ); ?>"><?php esc_html_e( 'No image uploaded', 'altcraft-ai-image-seo' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="altcraft-col-actions">
									<?php if ( $altcraft_can_edit ) : ?>
										<button type="button" class="button altcraft-btn-brand altcraft-row-ai-btn" data-id="<?php echo esc_attr( $altcraft_id ); ?>" <?php disabled( ! $has_key || $altcraft_no_file ); ?> <?php echo $altcraft_no_file ? 'title="' . esc_attr( $altcraft_file_ok->get_error_message() ) . '"' : ''; ?>><?php esc_html_e( 'Generate', 'altcraft-ai-image-seo' ); ?></button>
										<button type="button" class="button altcraft-row-save-btn" data-id="<?php echo esc_attr( $altcraft_id ); ?>"><?php esc_html_e( 'Save', 'altcraft-ai-image-seo' ); ?></button>
									<?php endif; ?>
									<?php if ( $altcraft_no_file ) : ?>
										<span class="altcraft-muted altcraft-no-file"><?php esc_html_e( 'No image uploaded – nothing to describe.', 'altcraft-ai-image-seo' ); ?></span>
									<?php endif; ?>
									<span class="altcraft-inline-status" aria-live="polite"></span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php
			$altcraft_total_pages = (int) $altcraft_query->max_num_pages;
			if ( $altcraft_total_pages > 1 ) :
				$altcraft_pagination = paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%', $altcraft_pill_url( $altcraft_status, $altcraft_type ) ),
						'format'    => '',
						'current'   => $altcraft_paged,
						'total'     => $altcraft_total_pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
						'type'      => 'plain',
					)
				);
				?>
				<div class="altcraft-pagination tablenav-pages">
					<span class="displaying-num">
						<?php
						printf(
							/* translators: %s: number of images */
							esc_html( _n( '%s image', '%s images', (int) $altcraft_query->found_posts, 'altcraft-ai-image-seo' ) ),
							esc_html( number_format_i18n( (int) $altcraft_query->found_posts ) )
						);
						?>
					</span>
					<?php echo wp_kses_post( $altcraft_pagination ); ?>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</div>
