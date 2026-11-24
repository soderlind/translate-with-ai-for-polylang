<?php
/**
 * WP-CLI commands.
 *
 * @package TranslateWithAiForPolylang
 */

declare(strict_types=1);

namespace Soderlind\TranslateWithAiForPolylang\Cli;

use Soderlind\TranslateWithAiForPolylang\Creator;
use Soderlind\TranslateWithAiForPolylang\Translation\TranslationJob;
use WP_CLI;
use WP_Post;

/**
 * Create Polylang translations with AI.
 */
class Command {

	/**
	 * Constructor.
	 *
	 * @param Creator        $creator Translation creator.
	 * @param TranslationJob $job     Translation job.
	 */
	public function __construct( private Creator $creator, private TranslationJob $job ) {}

	/**
	 * Creates a linked translation of a post and translates it with AI right away.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : ID of the source post.
	 *
	 * --language=<slug>
	 * : Polylang language slug, e.g. nb.
	 *
	 * [--async]
	 * : Start the translation in the background instead of running it now.
	 *
	 * ## EXAMPLES
	 *
	 *     wp twaifp translate 42 --language=nb --user=admin
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Associative arguments.
	 */
	public function translate( array $args, array $assoc_args ): void {
		$post = get_post( (int) $args[0] );
		if ( ! $post instanceof WP_Post ) {
			WP_CLI::error( __( 'Post not found.', 'translate-with-ai-for-polylang' ) );
		}
		if ( ! $this->creator->can_translate( $post ) ) {
			WP_CLI::error( __( 'You are not allowed to translate this post, or Polylang does not translate its post type. Pass --user=<admin>.', 'translate-with-ai-for-polylang' ) );
		}

		$language = $this->creator->language( $post, (string) ( $assoc_args['language'] ?? '' ) );
		if ( ! $language ) {
			$missing = array_keys( $this->creator->languages( $post ) );
			WP_CLI::error(
				$missing
					? sprintf(
						/* translators: %s: Available language slugs. */
						__( 'Unknown language or translation already exists. Choose one of: %s', 'translate-with-ai-for-polylang' ),
						implode( ', ', $missing )
					)
					: __( 'The post is already translated into every language.', 'translate-with-ai-for-polylang' )
			);
		}

		$async          = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'async', false );
		$translation_id = $this->creator->create( $post, $language, $async );
		if ( is_wp_error( $translation_id ) ) {
			WP_CLI::error( $translation_id );
		}

		if ( $async ) {
			$state = $this->job->state( $translation_id );
			if ( $state && $state->status === TranslationJob::STATUS_FAILED ) {
				WP_CLI::error(
					sprintf(
						/* translators: 1: Translation post ID, 2: Error message. */
						__( 'Created translation %1$d, but translation failed: %2$s', 'translate-with-ai-for-polylang' ),
						$translation_id,
						$state->error
					)
				);
			}
			WP_CLI::success(
				sprintf(
					/* translators: 1: Translation post ID, 2: Language name. */
					__( 'Created translation %1$d and queued AI translation to %2$s.', 'translate-with-ai-for-polylang' ),
					$translation_id,
					$language['name']
				)
			);
			return;
		}

		$this->run( [ (string) $translation_id ], [] );
	}

	/**
	 * Runs (or re-runs) the AI translation of a translation post now.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : ID of the translation post.
	 *
	 * ## EXAMPLES
	 *
	 *     wp twaifp run 43
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Associative arguments.
	 */
	public function run( array $args, array $assoc_args ): void {
		$post_id = (int) $args[0];
		WP_CLI::log(
			sprintf(
				/* translators: %d: Translation post ID. */
				__( 'Translating post %d…', 'translate-with-ai-for-polylang' ),
				$post_id
			)
		);

		wp_clear_scheduled_hook( TranslationJob::HOOK, [ $post_id ] );
		$result = $this->job->run( $post_id );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result );
		}

		WP_CLI::success(
			sprintf(
				/* translators: 1: Translation post ID, 2: Post title. */
				__( 'Translated post %1$d: %2$s', 'translate-with-ai-for-polylang' ),
				$post_id,
				get_the_title( $post_id )
			)
		);
	}
}
