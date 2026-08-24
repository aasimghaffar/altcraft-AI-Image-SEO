<?php
/**
 * Gathers contextual information about an image to improve the generated text.
 *
 * @package AltCraft_AI_Image_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Context helper.
 */
class AltCraft_Context {

	/**
	 * Returns context for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array {
	 *     @type int    $parent_id    Related post ID (0 if none).
	 *     @type string $parent_type  Post type of the related post.
	 *     @type string $parent_title Title of the related post.
	 *     @type bool   $is_product   Whether the related post is a WooCommerce product.
	 *     @type array  $categories   Product category names.
	 *     @type string $sku          Product SKU.
	 *     @type string $filename     Human readable filename hint.
	 * }
	 */
	public static function get( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$context       = array(
			'parent_id'    => 0,
			'parent_type'  => '',
			'parent_title' => '',
			'is_product'   => false,
			'categories'   => array(),
			'sku'          => '',
			'filename'     => self::filename_hint( $attachment_id ),
		);

		$parent_id = self::find_related_post( $attachment_id );

		if ( $parent_id ) {
			$parent = get_post( $parent_id );
			if ( $parent && 'trash' !== $parent->post_status ) {
				$context['parent_id']    = (int) $parent->ID;
				$context['parent_type']  = $parent->post_type;
				$context['parent_title'] = self::clean_text( get_the_title( $parent ) );
				$context['is_product']   = ( 'product' === $parent->post_type );

				if ( $context['is_product'] ) {
					$terms = get_the_terms( $parent->ID, 'product_cat' );
					if ( is_array( $terms ) ) {
						foreach ( $terms as $term ) {
							$context['categories'][] = self::clean_text( $term->name );
						}
					}
					$context['sku'] = self::clean_text( (string) get_post_meta( $parent->ID, '_sku', true ) );
				}
			}
		}

		/**
		 * Filters the context array passed to the prompt builder.
		 *
		 * @param array $context       Context data.
		 * @param int   $attachment_id Attachment ID.
		 */
		return (array) apply_filters( 'altcraft_ai_context', $context, $attachment_id );
	}

	/**
	 * Finds the post an image belongs to: its parent, or a post/product using it as
	 * featured image or in a WooCommerce product gallery.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int Post ID or 0.
	 */
	public static function find_related_post( $attachment_id ) {
		global $wpdb;

		$parent_id = (int) wp_get_post_parent_id( $attachment_id );
		if ( $parent_id > 0 ) {
			return $parent_id;
		}

		$cache_key = 'altcraft_related_' . $attachment_id;
		$cached    = wp_cache_get( $cache_key, 'altcraft_ai' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		// Featured image of any post type (products included).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reverse meta lookup, result cached below.
		$post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				WHERE m.meta_key = '_thumbnail_id' AND m.meta_value = %s AND p.post_status <> 'trash'
				ORDER BY (p.post_type = 'product') DESC, p.ID DESC LIMIT 1",
				(string) $attachment_id
			)
		);

		if ( ! $post_id && post_type_exists( 'product' ) ) {
			// WooCommerce gallery (comma separated list of IDs).
			$like = '%' . $wpdb->esc_like( (string) $attachment_id ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reverse meta lookup, result cached below.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND meta_value LIKE %s LIMIT 20",
					$like
				)
			);
			if ( $rows ) {
				foreach ( $rows as $row ) {
					$ids = array_map( 'absint', explode( ',', (string) $row->meta_value ) );
					if ( in_array( $attachment_id, $ids, true ) ) {
						$post_id = (int) $row->post_id;
						break;
					}
				}
			}
		}

		wp_cache_set( $cache_key, $post_id, 'altcraft_ai', 5 * MINUTE_IN_SECONDS );

		return $post_id;
	}

	/**
	 * Turns a filename such as "red-leather-handbag-2.jpg" into "red leather handbag".
	 * Camera style names (IMG_1234, DSC0001, screenshots) return an empty string.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function filename_hint( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file ) {
			return '';
		}

		$name = pathinfo( $file, PATHINFO_FILENAME );
		$name = preg_replace( '/-scaled$|-e\d+$|-\d+x\d+$/', '', $name );
		$name = preg_replace( '/[-_]+/', ' ', $name );
		$name = preg_replace( '/\b\d+\b/', ' ', $name );
		$name = trim( preg_replace( '/\s+/', ' ', $name ) );

		if ( '' === $name || preg_match( '/^(img|dsc|dscn|pxl|image|photo|screenshot|screen shot|untitled|scan|file|picture|pic)\s*$/i', $name ) ) {
			return '';
		}

		return mb_substr( $name, 0, 80 );
	}

	/**
	 * Removes tags/entities and trims a piece of text for use inside a prompt.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function clean_text( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = str_replace( array( "\r", "\n", "\t", '"' ), ' ', $text );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		return mb_substr( $text, 0, 150 );
	}
}
