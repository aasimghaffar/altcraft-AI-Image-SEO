<?php
/**
 * Usage statistics and per-user generation history.
 *
 * Counters live in a single option; history lives in user meta so each
 * author only ever sees their own work. No custom database tables and no
 * direct $wpdb queries.
 *
 * @package Cubix_AI_Article_Generator
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CXAI_Logger
 */
class CXAI_Logger {

	/**
	 * User meta key holding recent generations.
	 */
	const HISTORY_META = 'cxai_history';

	/**
	 * Number of generations kept per user.
	 */
	const HISTORY_CAP = 15;

	/**
	 * Default statistics shape.
	 *
	 * @return array
	 */
	public static function default_stats() {
		return array(
			'total'       => 0,
			'words'       => 0,
			'by_provider' => array(),
			'by_mode'     => array(),
			'monthly'     => array(),
			'last_run'    => 0,
		);
	}

	/**
	 * Read the statistics option.
	 *
	 * @return array
	 */
	public static function get_stats() {
		$stats = get_option( CXAI_STATS_KEY, array() );

		return wp_parse_args( is_array( $stats ) ? $stats : array(), self::default_stats() );
	}

	/**
	 * Record a successful generation.
	 *
	 * @param string $provider Provider slug.
	 * @param string $mode     Generation mode.
	 * @param string $content  Generated content (used for the word count).
	 * @return void
	 */
	public static function record( $provider, $mode, $content ) {
		$stats = self::get_stats();
		$month = gmdate( 'Y-m' );
		$words = str_word_count( wp_strip_all_tags( $content ) );

		$stats['total']    = (int) $stats['total'] + 1;
		$stats['words']    = (int) $stats['words'] + $words;
		$stats['last_run'] = time();

		$stats['by_provider'][ $provider ] = isset( $stats['by_provider'][ $provider ] ) ? (int) $stats['by_provider'][ $provider ] + 1 : 1;
		$stats['by_mode'][ $mode ]         = isset( $stats['by_mode'][ $mode ] ) ? (int) $stats['by_mode'][ $mode ] + 1 : 1;
		$stats['monthly'][ $month ]        = isset( $stats['monthly'][ $month ] ) ? (int) $stats['monthly'][ $month ] + 1 : 1;

		// Keep only the last 12 months of monthly counters.
		if ( count( $stats['monthly'] ) > 12 ) {
			ksort( $stats['monthly'] );
			$stats['monthly'] = array_slice( $stats['monthly'], -12, null, true );
		}

		update_option( CXAI_STATS_KEY, $stats, false );
	}

	/**
	 * Reset all statistics.
	 *
	 * @return void
	 */
	public static function reset_stats() {
		update_option( CXAI_STATS_KEY, self::default_stats(), false );
	}

	/**
	 * Generations this month.
	 *
	 * @return int
	 */
	public static function get_month_total() {
		$stats = self::get_stats();
		$month = gmdate( 'Y-m' );

		return isset( $stats['monthly'][ $month ] ) ? (int) $stats['monthly'][ $month ] : 0;
	}

	/**
	 * Read a user's recent generations.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array{mode: string, prompt: string, content: string, time: int}>
	 */
	public static function get_history( $user_id ) {
		$history = get_user_meta( $user_id, self::HISTORY_META, true );

		return is_array( $history ) ? $history : array();
	}

	/**
	 * Push a generation onto a user's history (newest first, capped).
	 *
	 * @param int    $user_id User ID.
	 * @param string $mode    Generation mode.
	 * @param string $prompt  Prompt used.
	 * @param string $content Generated content.
	 * @return void
	 */
	public static function add_history( $user_id, $mode, $prompt, $content ) {
		$history = self::get_history( $user_id );

		array_unshift(
			$history,
			array(
				'mode'    => $mode,
				'prompt'  => $prompt,
				'content' => $content,
				'time'    => time(),
			)
		);

		update_user_meta( $user_id, self::HISTORY_META, array_slice( $history, 0, self::HISTORY_CAP ) );
	}

	/**
	 * Clear a user's generation history.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function clear_history( $user_id ) {
		delete_user_meta( $user_id, self::HISTORY_META );
	}
}
