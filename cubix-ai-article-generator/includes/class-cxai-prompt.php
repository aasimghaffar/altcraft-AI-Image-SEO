<?php
/**
 * Prompt composer.
 *
 * Turns a mode, tone, length, language, and optional post context into the
 * system instruction and user prompt actually sent to the engine. All of
 * this happens server-side so the browser can never dictate instructions.
 *
 * @package Cubix_AI_Article_Generator
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CXAI_Prompt
 */
class CXAI_Prompt {

	/**
	 * Maximum characters of post context passed to the engine.
	 */
	const CONTEXT_LIMIT = 6000;

	/**
	 * Build the system instruction for a mode.
	 *
	 * @param string $mode     Whitelisted mode.
	 * @param string $tone     Whitelisted tone.
	 * @param string $length   Whitelisted length key.
	 * @param string $language Target language (free text, sanitized).
	 * @return string
	 */
	public static function system( $mode, $tone, $length, $language ) {
		$parts = array( 'You are an expert content writer working inside a WordPress editor.' );

		switch ( $mode ) {
			case 'title':
				$parts[] = 'Produce one compelling post title only. No quotation marks, no numbering, no explanation.';
				break;
			case 'excerpt':
				$parts[] = 'Produce one excerpt / meta description of at most 155 characters. Output the excerpt text only.';
				break;
			case 'outline':
				$parts[] = 'Produce a structured article outline using H2 and H3 headings with short bullet points beneath each.';
				break;
			case 'faq':
				$parts[] = 'Produce a FAQ section of five to seven question-and-answer pairs. Format each question as a heading.';
				break;
			case 'rewrite':
				$parts[] = 'Rewrite the supplied draft so it reads more clearly and confidently. Preserve every fact and the original meaning. Output the rewritten text only.';
				break;
			case 'expand':
				$parts[] = 'Expand the supplied draft with additional depth, examples, and detail while keeping the original voice. Output the expanded text only.';
				break;
			case 'summary':
				$parts[] = 'Summarize the supplied draft into its key points. Output the summary only.';
				break;
			case 'translate':
				$parts[] = 'Translate the supplied text faithfully, preserving formatting and tone. Output the translation only.';
				break;
			case 'keywords':
				$parts[] = 'Produce a list of 10 SEO keywords and key phrases, one per line, ordered from most to least important. No commentary.';
				break;
			case 'tags':
				$parts[] = 'Produce 8 concise WordPress tags as a single comma-separated line. No commentary.';
				break;
			case 'cta':
				$parts[] = 'Produce three distinct call-to-action paragraphs, each on its own line. No commentary.';
				break;
			default:
				$parts[] = 'Produce well-structured article content using short paragraphs and H2 subheadings where they help. Do not repeat the post title as a heading.';
				break;
		}

		if ( '' !== $tone ) {
			$parts[] = 'Write in a ' . $tone . ' tone.';
		}

		$lengths = CXAI_Options::get_lengths();

		if ( isset( $lengths[ $length ] ) && $lengths[ $length ]['words'] > 0 ) {
			$target = (int) $lengths[ $length ]['words'];
			$floor  = (int) round( $target * 0.9 );

			// Models undershoot soft targets badly, so state a hard floor,
			// forbid early stopping, and give a structural hint for how to
			// fill the length honestly rather than padding.
			$ceiling = (int) round( $target * 1.1 );

			$parts[] = sprintf(
				'Length requirement: write between %1$d and %2$d words, targeting %3$d. Do not stop early and do not exceed the upper limit — finish the final section cleanly before reaching it. Develop the topic with specific detail and examples rather than padding or repetition.',
				$floor,
				$ceiling,
				$target
			);
		}

		if ( '' !== $language ) {
			$parts[] = 'Write the response in ' . $language . '.';
		}

		$parts[] = 'Return clean prose or simple HTML. Never wrap the whole answer in a code block.';

		return implode( ' ', $parts );
	}

	/**
	 * Build the user message.
	 *
	 * @param string $prompt  Sanitized user prompt.
	 * @param string $mode    Whitelisted mode.
	 * @param string $context Optional post content for context.
	 * @return string
	 */
	public static function user( $prompt, $mode, $context = '' ) {
		$modes = CXAI_Options::get_modes();
		$needs = ! empty( $modes[ $mode ]['needs_context'] );

		if ( '' !== $context ) {
			$context = self::trim_context( $context );

			if ( $needs ) {
				return "Draft to work from:\n\n" . $context . "\n\nAdditional instruction: " . ( '' !== $prompt ? $prompt : 'Follow the system instruction.' );
			}

			return 'Request: ' . $prompt . "\n\nExisting post content for context:\n\n" . $context;
		}

		return 'Request: ' . $prompt;
	}

	/**
	 * Trim post context to a safe length on a word boundary.
	 *
	 * @param string $context Raw context.
	 * @return string
	 */
	private static function trim_context( $context ) {
		$context = wp_strip_all_tags( $context );

		if ( strlen( $context ) <= self::CONTEXT_LIMIT ) {
			return $context;
		}

		return substr( $context, 0, self::CONTEXT_LIMIT ) . '…';
	}
}
