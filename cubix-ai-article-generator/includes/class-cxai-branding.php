<?php
/**
 * Brand assets.
 *
 * The mark is an isometric cube — the "Cubix" prism — whose top facet
 * carries a generative spark and whose front facet carries three text
 * lines: AI light entering, written content coming out. Everything is
 * inline SVG so the plugin never loads a remote asset.
 *
 * @package Cubix_AI_Article_Generator
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CXAI_Branding
 */
class CXAI_Branding {

	/**
	 * Full-colour logo mark.
	 *
	 * @param int $size Pixel size of the square mark.
	 * @return string Inline SVG markup (safe, self-generated).
	 */
	public static function logo( $size = 48 ) {
		$size = absint( $size );
		$uid  = 'cxai-lg-' . wp_rand( 1000, 9999 );

		ob_start();
		?>
		<svg class="cx-logo" width="<?php echo esc_attr( $size ); ?>" height="<?php echo esc_attr( $size ); ?>" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true" focusable="false">
			<defs>
				<linearGradient id="<?php echo esc_attr( $uid ); ?>-top" x1="8" y1="6" x2="56" y2="34" gradientUnits="userSpaceOnUse">
					<stop stop-color="#6FE3C4" />
					<stop offset="1" stop-color="#12B886" />
				</linearGradient>
				<linearGradient id="<?php echo esc_attr( $uid ); ?>-left" x1="8" y1="22" x2="32" y2="60" gradientUnits="userSpaceOnUse">
					<stop stop-color="#22385C" />
					<stop offset="1" stop-color="#111C31" />
				</linearGradient>
				<linearGradient id="<?php echo esc_attr( $uid ); ?>-right" x1="56" y1="22" x2="32" y2="60" gradientUnits="userSpaceOnUse">
					<stop stop-color="#16253F" />
					<stop offset="1" stop-color="#0A1120" />
				</linearGradient>
			</defs>

			<!-- Top facet: the light entering the prism. -->
			<path d="M32 3 60 19 32 35 4 19 32 3Z" fill="url(#<?php echo esc_attr( $uid ); ?>-top)" />
			<!-- Left facet: the written output. -->
			<path d="M4 19 32 35v26L4 45V19Z" fill="url(#<?php echo esc_attr( $uid ); ?>-left)" />
			<!-- Right facet. -->
			<path d="M60 19 32 35v26l28-16V19Z" fill="url(#<?php echo esc_attr( $uid ); ?>-right)" />

			<!-- Generative spark on the top facet. -->
			<path d="M32 11.5 34.1 16.4 39 18.5 34.1 20.6 32 25.5 29.9 20.6 25 18.5 29.9 16.4 32 11.5Z" fill="#FFFFFF" fill-opacity=".92" />

			<!-- Content lines on the left facet. -->
			<path d="M9.5 27.2 26.5 37v3.1L9.5 30.3v-3.1Z" fill="#6FE3C4" fill-opacity=".85" />
			<path d="M9.5 34.4 26.5 44.2v3.1L9.5 37.5v-3.1Z" fill="#6FE3C4" fill-opacity=".55" />
			<path d="M9.5 41.6 19.5 47.4v3.1L9.5 44.7v-3.1Z" fill="#6FE3C4" fill-opacity=".3" />

			<!-- Gold facet edge: the premium hairline. -->
			<path d="M32 3 60 19l-2.4 1.4L32 5.8 6.4 20.4 4 19 32 3Z" fill="#F5C451" fill-opacity=".9" />
		</svg>
		<?php
		return ob_get_clean();
	}

	/**
	 * Monochrome menu icon as a base64 data URI.
	 *
	 * WordPress recolours single-colour SVG data URIs to match the admin
	 * colour scheme, so the mark stays legible in every theme.
	 *
	 * @return string
	 */
	public static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><path fill="black" d="M32 3 60 19 32 35 4 19 32 3Zm0 5.8L14.6 19 32 29.2 49.4 19 32 8.8ZM4 23.6 29 38v22.6L4 46.2V23.6Zm5 8.7v10.7l15 8.6V41L9 32.3ZM60 23.6v22.6L35 60.6V38l25-14.4Zm-5 8.7-15 8.7v10.6l15-8.6V32.3Z"/></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Allowed SVG tags/attributes for wp_kses when echoing our own markup.
	 *
	 * @return array
	 */
	public static function svg_kses() {
		return array(
			'svg'            => array(
				'class'       => true,
				'width'       => true,
				'height'      => true,
				'viewbox'     => true,
				'fill'        => true,
				'xmlns'       => true,
				'role'        => true,
				'aria-hidden' => true,
				'focusable'   => true,
			),
			'defs'           => array(),
			'lineargradient' => array(
				'id'           => true,
				'x1'           => true,
				'y1'           => true,
				'x2'           => true,
				'y2'           => true,
				'gradientunits' => true,
			),
			'stop'           => array(
				'stop-color' => true,
				'offset'     => true,
			),
			'path'           => array(
				'd'            => true,
				'fill'         => true,
				'fill-opacity' => true,
			),
		);
	}
}
