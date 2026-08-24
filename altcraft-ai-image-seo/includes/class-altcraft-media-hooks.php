<?php
/**
 * Media library integration.
 *
 * @package AltCraft_AI_Image_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Upload hook, list-table column and media modal field.
 */
class AltCraft_Media_Hooks {

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'auto_generate_on_upload' ), 90, 3 );

		add_filter( 'manage_media_columns', array( $this, 'add_media_columns' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_media_column' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_fields' ), 10, 2 );
	}

	/**
	 * Capability required to generate ALT text for a single image.
	 *
	 * @return string
	 */
	public static function generate_capability() {
		/**
		 * Filters the capability required to generate ALT text for a single image.
		 *
		 * @param string $capability Default "upload_files".
		 */
		return (string) apply_filters( 'altcraft_ai_generate_capability', 'upload_files' );
	}

	/**
	 * Whether the current user may generate/edit ALT text for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function user_can_generate( $attachment_id ) {
		return current_user_can( self::generate_capability() ) && current_user_can( 'edit_post', $attachment_id );
	}

	/**
	 * Generates ALT text right after WordPress has created the image sizes for a new upload.
	 * Runs synchronously so the block editor receives the ALT text with the upload response.
	 *
	 * @param array  $metadata      Attachment metadata.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $context       "create" for new uploads, "update" for regenerated sizes.
	 * @return array Unchanged metadata.
	 */
	public function auto_generate_on_upload( $metadata, $attachment_id, $context = 'create' ) {
		if ( 'create' !== $context || ! AltCraft_Settings::is_on( 'auto_on_upload' ) ) {
			return $metadata;
		}

		if ( ! wp_attachment_is_image( $attachment_id ) || '' === AltCraft_Settings::get_api_key() ) {
			return $metadata;
		}

		/**
		 * Filters whether ALT text should be generated automatically for this upload.
		 *
		 * @param bool $generate      Default true.
		 * @param int  $attachment_id Attachment ID.
		 */
		if ( ! apply_filters( 'altcraft_ai_auto_generate', true, $attachment_id ) ) {
			return $metadata;
		}

		// Sizes exist on disk but the metadata is not saved yet – store it so the generator can pick a small copy.
		if ( is_array( $metadata ) && ! empty( $metadata['file'] ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		// WebP copies are created by AltCraft_WebP on the same filter (priority 100).
		AltCraft_Generator::generate( $attachment_id, array( 'webp' => false ) );

		return $metadata;
	}

	/**
	 * Adds the "AI Alt Text" column to the Media Library list view.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_media_columns( $columns ) {
		$columns['altcraft_alt'] = __( 'AI Alt Text', 'altcraft-ai-image-seo' );
		return $columns;
	}

	/**
	 * Renders the column content.
	 *
	 * @param string $column_name Column name.
	 * @param int    $post_id     Attachment ID.
	 * @return void
	 */
	public function render_media_column( $column_name, $post_id ) {
		if ( 'altcraft_alt' !== $column_name ) {
			return;
		}

		if ( ! wp_attachment_is_image( $post_id ) ) {
			echo '<span class="altcraft-muted">&mdash;</span>';
			return;
		}

		$alt     = trim( (string) get_post_meta( $post_id, '_wp_attachment_image_alt', true ) );
		$file_ok = AltCraft_Image::can_generate( $post_id );

		echo '<div class="altcraft-column" data-id="' . esc_attr( $post_id ) . '">';

		if ( '' !== $alt ) {
			echo '<span class="altcraft-column-alt" title="' . esc_attr( $alt ) . '">' . esc_html( wp_trim_words( $alt, 10 ) ) . '</span>';
		} elseif ( is_wp_error( $file_ok ) ) {
			echo '<span class="altcraft-badge altcraft-badge-nofile" title="' . esc_attr( $file_ok->get_error_message() ) . '">' . esc_html__( 'No image uploaded', 'altcraft-ai-image-seo' ) . '</span>';
		} else {
			echo '<span class="altcraft-badge altcraft-badge-missing">' . esc_html__( 'Missing ALT', 'altcraft-ai-image-seo' ) . '</span>';
		}

		if ( is_wp_error( $file_ok ) && '' !== $alt ) {
			echo ' <span class="altcraft-badge altcraft-badge-nofile" title="' . esc_attr( $file_ok->get_error_message() ) . '">' . esc_html__( 'No image uploaded', 'altcraft-ai-image-seo' ) . '</span>';
		}

		if ( ! is_wp_error( $file_ok ) && self::user_can_generate( $post_id ) ) {
			$label = '' !== $alt ? __( 'Regenerate', 'altcraft-ai-image-seo' ) : __( 'Generate ALT', 'altcraft-ai-image-seo' );
			echo ' <button type="button" class="button button-small altcraft-quick-gen-btn" data-id="' . esc_attr( $post_id ) . '">' . esc_html( $label ) . '</button>';
		}

		echo '<span class="altcraft-inline-status" aria-live="polite"></span>';
		echo '</div>';
	}

	/**
	 * Adds a "Generate with AI" control to the attachment details (media modal and edit screen).
	 *
	 * @param array   $form_fields Fields.
	 * @param WP_Post $post        Attachment post.
	 * @return array
	 */
	public function attachment_fields( $form_fields, $post ) {
		if ( ! wp_attachment_is_image( $post->ID ) || ! self::user_can_generate( $post->ID ) ) {
			return $form_fields;
		}

		$file_ok = AltCraft_Image::can_generate( $post->ID );

		if ( is_wp_error( $file_ok ) ) {
			$html  = '<span class="altcraft-badge altcraft-badge-nofile">' . esc_html__( 'No image uploaded', 'altcraft-ai-image-seo' ) . '</span>';
			$html .= '<p class="description">' . esc_html( $file_ok->get_error_message() ) . '</p>';
		} else {
			$html  = '<button type="button" class="button altcraft-modal-gen-btn" data-id="' . esc_attr( $post->ID ) . '">';
			$html .= esc_html__( 'Generate ALT text with AI', 'altcraft-ai-image-seo' );
			$html .= '</button> <span class="altcraft-inline-status" aria-live="polite"></span>';
			$html .= '<p class="description">' . esc_html__( 'Fills the ALT text (and title/caption when enabled) using your configured AI provider.', 'altcraft-ai-image-seo' ) . '</p>';
		}

		$form_fields['altcraft_generate'] = array(
			'label' => __( 'AltCraft AI', 'altcraft-ai-image-seo' ),
			'input' => 'html',
			'html'  => $html,
		);

		return $form_fields;
	}
}
