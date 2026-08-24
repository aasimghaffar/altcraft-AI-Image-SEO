<?php
/**
 * Media library statistics with transient caching.
 *
 * @package AltCraft_AI_Image_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stats helper.
 */
class AltCraft_Stats {

	/**
	 * Transient name.
	 */
	const TRANSIENT = 'altcraft_ai_stats';

	/**
	 * Registers invalidation hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'add_attachment', array( __CLASS__, 'invalidate' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'invalidate' ) );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 3 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 3 );
	}

	/**
	 * Invalidates the cache when ALT text changes anywhere (media modal, REST, other plugins).
	 *
	 * @param int|array $meta_id  Meta ID(s).
	 * @param int       $post_id  Post ID.
	 * @param string    $meta_key Meta key.
	 * @return void
	 */
	public static function on_meta_change( $meta_id, $post_id, $meta_key ) {
		if ( '_wp_attachment_image_alt' === $meta_key ) {
			self::invalidate();
		}
	}

	/**
	 * Clears the cached statistics.
	 *
	 * @return void
	 */
	public static function invalidate() {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Returns statistics about image attachments.
	 *
	 * @param bool $force Recalculate even when cached.
	 * @return array {
	 *     @type int $total     Number of image attachments.
	 *     @type int $optimized Images with non-empty ALT text.
	 *     @type int $missing   Images without ALT text.
	 *     @type int $woo       Images attached to WooCommerce products (0 when WooCommerce is absent).
	 *     @type int $percent   Coverage percentage.
	 * }
	 */
	public static function get( $force = false ) {
		global $wpdb;

		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate counts, cached in a transient below.
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(ID) FROM {$wpdb->posts}
			WHERE post_type = 'attachment' AND post_status = 'inherit' AND post_mime_type LIKE 'image/%'"
		);

		$optimized = (int) $wpdb->get_var(
			"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
			WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type LIKE 'image/%'
			AND TRIM(m.meta_value) <> ''"
		);

		$woo = 0;
		if ( post_type_exists( 'product' ) ) {
			$woo = (int) $wpdb->get_var(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent AND parent.post_type = 'product'
				LEFT JOIN {$wpdb->postmeta} thumb ON thumb.meta_key = '_thumbnail_id' AND thumb.meta_value = p.ID
				LEFT JOIN {$wpdb->posts} tp ON tp.ID = thumb.post_id AND tp.post_type = 'product'
				WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type LIKE 'image/%'
				AND ( parent.ID IS NOT NULL OR tp.ID IS NOT NULL )"
			);
		}
		// phpcs:enable

		$optimized = min( $optimized, $total );
		$stats     = array(
			'total'     => $total,
			'optimized' => $optimized,
			'missing'   => max( 0, $total - $optimized ),
			'woo'       => $woo,
			'percent'   => $total > 0 ? (int) round( ( $optimized / $total ) * 100 ) : 100,
		);

		set_transient( self::TRANSIENT, $stats, 10 * MINUTE_IN_SECONDS );

		return $stats;
	}

	/**
	 * Returns IDs of image attachments without ALT text (or all images when $mode is "all").
	 *
	 * @param string $mode     "missing" or "all".
	 * @param int    $limit    Max IDs to return.
	 * @param array  $exclude  IDs to skip.
	 * @param int    $after_id Only return IDs greater than this (keyset pagination for the bulk scanner).
	 * @return array {
	 *     @type array $ids   Attachment IDs.
	 *     @type int   $total Total matching images (before limit/exclusions).
	 * }
	 */
	public static function get_queue( $mode = 'missing', $limit = 100, $exclude = array(), $after_id = 0 ) {
		$args = array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => 'image',
			'posts_per_page'         => max( 1, min( 500, (int) $limit ) ),
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'fields'                 => 'ids',
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ( $exclude ) {
			$args['post__not_in'] = array_map( 'absint', (array) $exclude );
		}

		if ( 'all' !== $mode ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to find images without ALT text.
			$args['meta_query'] = array(
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
		}

		$after_id = absint( $after_id );
		$where    = static function ( $sql, $query ) use ( $after_id ) {
			global $wpdb;
			if ( $query->get( 'altcraft_after_id' ) ) {
				$sql .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after_id );
			}
			return $sql;
		};

		if ( $after_id > 0 ) {
			$args['altcraft_after_id'] = true;
			add_filter( 'posts_where', $where, 10, 2 );
		}

		$query = new WP_Query( $args );

		if ( $after_id > 0 ) {
			remove_filter( 'posts_where', $where, 10 );
		}

		return array(
			'ids'   => array_map( 'intval', $query->posts ),
			'total' => (int) $query->found_posts,
		);
	}
}
