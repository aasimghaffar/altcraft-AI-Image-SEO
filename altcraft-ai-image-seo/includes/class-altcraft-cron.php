<?php
/**
 * Background (WP-Cron) optimisation.
 *
 * @package AltCraft_AI_Image_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cron handler.
 */
class AltCraft_Cron {

	/**
	 * Transient holding attachment IDs that failed recently (skipped for 12 hours).
	 */
	const SKIP_TRANSIENT = 'altcraft_ai_cron_skip';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( ALTCRAFT_AI_CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
		add_action( 'update_option_' . ALTCRAFT_AI_OPTION, array( __CLASS__, 'on_settings_saved' ), 10, 2 );
	}

	/**
	 * Schedules or clears the daily event to match the setting.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		$enabled = AltCraft_Settings::is_on( 'enable_nightly_cron' );
		$next    = wp_next_scheduled( ALTCRAFT_AI_CRON_HOOK );

		if ( $enabled && ! $next ) {
			wp_schedule_event( self::next_run_timestamp(), 'daily', ALTCRAFT_AI_CRON_HOOK );
		} elseif ( ! $enabled && $next ) {
			wp_clear_scheduled_hook( ALTCRAFT_AI_CRON_HOOK );
		}
	}

	/**
	 * Re-evaluates the schedule after the settings are saved.
	 *
	 * @param mixed $old_value Old value.
	 * @param mixed $value     New value.
	 * @return void
	 */
	public static function on_settings_saved( $old_value, $value ) {
		unset( $old_value, $value );
		AltCraft_Settings::flush_cache();
		self::maybe_schedule();
	}

	/**
	 * Timestamp of the next 02:00 in the site's timezone.
	 *
	 * @return int
	 */
	public static function next_run_timestamp() {
		try {
			$date = new DateTime( 'tomorrow 02:00', wp_timezone() );
			return (int) $date->getTimestamp();
		} catch ( Exception $e ) {
			return time() + DAY_IN_SECONDS;
		}
	}

	/**
	 * Processes a batch of images without ALT text within a time budget.
	 * Schedules a follow-up run when more images remain and progress was made.
	 *
	 * @return void
	 */
	public static function run() {
		if ( ! AltCraft_Settings::is_on( 'enable_nightly_cron' ) ) {
			return;
		}

		if ( '' === AltCraft_Settings::get_api_key() ) {
			return;
		}

		$settings   = AltCraft_Settings::get();
		$batch_size = max( 1, min( 50, (int) $settings['cron_batch_size'] ) );

		/**
		 * Filters the number of seconds a single cron run may spend generating ALT text.
		 *
		 * @param int $budget Default 25.
		 */
		$budget = (int) apply_filters( 'altcraft_ai_cron_time_budget', 25 );
		$start  = time();

		$skip  = get_transient( self::SKIP_TRANSIENT );
		$skip  = is_array( $skip ) ? $skip : array();
		$queue = AltCraft_Stats::get_queue( 'missing', $batch_size, $skip );

		$success = 0;
		$done    = 0;

		foreach ( $queue['ids'] as $attachment_id ) {
			if ( ( time() - $start ) >= $budget ) {
				break;
			}

			++$done;
			$result = AltCraft_Generator::generate( $attachment_id, array( 'force' => false ) );

			if ( is_wp_error( $result ) ) {
				$skip[] = (int) $attachment_id;

				// Stop the run on credential/model problems – every image would fail the same way.
				if ( in_array( $result->get_error_code(), array( 'auth', 'model_not_found', 'no_api_key' ), true ) ) {
					break;
				}
				if ( 'rate_limited' === $result->get_error_code() ) {
					break;
				}
				continue;
			}

			if ( empty( $result['skipped'] ) ) {
				++$success;
			}
		}

		if ( $skip ) {
			set_transient( self::SKIP_TRANSIENT, array_values( array_unique( array_map( 'intval', $skip ) ) ), 12 * HOUR_IN_SECONDS );
		}

		$remaining = max( 0, $queue['total'] - $done );

		if ( $success > 0 && $remaining > 0 && ! wp_next_scheduled( ALTCRAFT_AI_CRON_HOOK, array( 'follow-up' ) ) ) {
			/**
			 * Filters the delay (seconds) before a follow-up cron batch runs.
			 *
			 * @param int $delay Default 120.
			 */
			$delay = (int) apply_filters( 'altcraft_ai_cron_followup_delay', 2 * MINUTE_IN_SECONDS );
			wp_schedule_single_event( time() + max( 30, $delay ), ALTCRAFT_AI_CRON_HOOK, array( 'follow-up' ) );
		}

		update_option(
			'altcraft_ai_last_cron',
			array(
				'time'      => time(),
				'processed' => $done,
				'success'   => $success,
				'remaining' => $remaining,
			),
			false
		);
	}
}
