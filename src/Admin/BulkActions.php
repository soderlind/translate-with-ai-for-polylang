<?php
/**
 * Post-list bulk translation actions.
 *
 * @package TranslateWithAiForPolylang
 */

declare(strict_types=1);

namespace Soderlind\TranslateWithAiForPolylang\Admin;

use Soderlind\TranslateWithAiForPolylang\Creator;
use Soderlind\TranslateWithAiForPolylang\Polylang;
use Soderlind\TranslateWithAiForPolylang\Translation\BulkQueue;
use Soderlind\TranslateWithAiForPolylang\Translation\JobState;
use Soderlind\TranslateWithAiForPolylang\Translation\TranslationJob;
use WP_Post;
use WP_Screen;

/**
 * Creates or retries translations selected in a post list.
 */
class BulkActions {

	private const ACTION_PREFIX = 'twaifp_translate_to_';

	/**
	 * Constructor.
	 *
	 * @param Creator        $creator  Translation creator.
	 * @param Polylang       $polylang Polylang integration.
	 * @param TranslationJob $job      Translation worker.
	 * @param BulkQueue      $queue    Sequential batch runner.
	 */
	public function __construct(
		private Creator $creator,
		private Polylang $polylang,
		private TranslationJob $job,
		private BulkQueue $queue
	) {}

	/**
	 * Registers the post-list hooks.
	 */
	public function register(): void {
		add_action( 'current_screen', [ $this, 'register_screen' ] );
		add_action( 'admin_notices', [ $this, 'notice' ] );
	}

	/**
	 * Adds bulk hooks only to translated post types.
	 *
	 * @param WP_Screen $screen Current screen.
	 */
	public function register_screen( WP_Screen $screen ): void {
		if ( 'edit' !== $screen->base || ! $this->polylang->supports_post_type( $screen->post_type ) ) {
			return;
		}

		add_filter( 'bulk_actions-' . $screen->id, [ $this, 'actions' ] );
		add_filter( 'handle_bulk_actions-' . $screen->id, [ $this, 'handle' ], 10, 3 );
	}

	/**
	 * Adds one action per target language.
	 *
	 * @param array<string,string> $actions Existing bulk actions.
	 * @return array<string,string>
	 */
	public function actions( array $actions ): array {
		foreach ( $this->polylang->languages() as $code => $language ) {
			$actions[ self::ACTION_PREFIX . $code ] = esc_html(
				sprintf(
					/* translators: %s: Target language name. */
					__( 'Translate to %s with AI', 'translate-with-ai-for-polylang' ),
					$language['name']
				)
			);
		}
		return $actions;
	}

	/**
	 * Processes the core-nonced bulk action and returns a report URL.
	 *
	 * @param string   $redirect Redirect URL.
	 * @param string   $action   Selected action.
	 * @param string[] $post_ids Selected post IDs.
	 * @return string
	 */
	public function handle( string $redirect, string $action, array $post_ids ): string {
		if ( ! str_starts_with( $action, self::ACTION_PREFIX ) ) {
			return $redirect;
		}

		$code      = substr( $action, strlen( self::ACTION_PREFIX ) );
		$languages = $this->polylang->languages();
		$screen    = get_current_screen();
		if ( ! isset( $languages[ $code ] ) || ! $screen || 'edit' !== $screen->base || ! $this->polylang->supports_post_type( $screen->post_type ) ) {
			return add_query_arg( 'twaifp_bulk_invalid', 1, $redirect );
		}

		$language = $languages[ $code ];
		$prepared = [];
		$queued   = 0;
		$retried  = 0;
		$skipped  = 0;
		$failed   = 0;
		$user_id  = get_current_user_id();

		foreach ( array_unique( array_map( 'absint', $post_ids ) ) as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post || $post->post_type !== $screen->post_type
				|| in_array( $post->post_status, [ 'trash', 'auto-draft', 'inherit' ], true )
				|| ! current_user_can( 'edit_post', $post_id ) || ! $this->creator->can_translate( $post )
			) {
				++$skipped;
				continue;
			}

			$translation_id = $this->polylang->translation_id( $post, $code );
			if ( null === $translation_id ) {
				++$skipped;
				continue;
			}
			if ( $translation_id ) {
				$translation = get_post( $translation_id );
				$state       = $this->job->state( $translation_id );
				if ( $translation_id === $post_id || ! $translation instanceof WP_Post || 'draft' !== $translation->post_status
					|| $translation->post_type !== $post->post_type || ! current_user_can( 'edit_post', $translation_id )
					|| ! $state || JobState::FAILED !== $state->status || $this->queue->contains( $translation_id )
				) {
					++$skipped;
					continue;
				}

				$result = $this->job->prepare_retry( $translation_id, $user_id, false, $language );
				if ( is_wp_error( $result ) ) {
					if ( in_array( $result->get_error_code(), [ 'twaifp_in_progress', 'twaifp_not_retryable' ], true ) ) {
						++$skipped;
					} else {
						error_log( 'Translate with AI for Polylang: could not prepare a bulk retry: ' . $result->get_error_message() );
						++$failed;
					}
					continue;
				}
				$prepared[] = $translation_id;
				++$retried;
				continue;
			}

			$result = $this->creator->create( $post, $language, false );
			if ( is_wp_error( $result ) ) {
				error_log( 'Translate with AI for Polylang: could not create a bulk translation: ' . $result->get_error_message() );
				++$failed;
				continue;
			}
			$prepared[] = $result;
			++$queued;
		}

		$start_failed = false;
		if ( $prepared ) {
			$result       = $this->queue->start( $prepared );
			$start_failed = is_wp_error( $result );
			if ( $start_failed ) {
				error_log( 'Translate with AI for Polylang: could not start a bulk queue: ' . $result->get_error_message() );
			}
			if ( $start_failed && 'twaifp_batch_storage' === $result->get_error_code() ) {
				$failed += count( $prepared );
				$queued  = 0;
				$retried = 0;
			}
		}

		$redirect = remove_query_arg(
			[ 'twaifp_bulk_invalid', 'twaifp_bulk_queued', 'twaifp_bulk_retried', 'twaifp_bulk_skipped', 'twaifp_bulk_failed', 'twaifp_bulk_start_failed', 'twaifp_bulk_language', 'twaifp_queued' ],
			$redirect
		);
		return add_query_arg(
			[
				'twaifp_bulk_queued'       => $queued,
				'twaifp_bulk_retried'      => $retried,
				'twaifp_bulk_skipped'      => $skipped,
				'twaifp_bulk_failed'       => $failed,
				'twaifp_bulk_start_failed' => (int) $start_failed,
				'twaifp_bulk_language'     => $code,
			],
			$redirect
		);
	}

	/**
	 * Displays a summary without exposing details about skipped posts.
	 */
	public function notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit' !== $screen->base ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only report.
		if ( isset( $_GET['twaifp_bulk_invalid'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The selected bulk translation language is not available.', 'translate-with-ai-for-polylang' ) . '</p></div>';
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only report.
		if ( ! isset( $_GET['twaifp_bulk_queued'] ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only report.
		$queued       = absint( $_GET['twaifp_bulk_queued'] );
		$retried      = isset( $_GET['twaifp_bulk_retried'] ) ? absint( $_GET['twaifp_bulk_retried'] ) : 0;
		$skipped      = isset( $_GET['twaifp_bulk_skipped'] ) ? absint( $_GET['twaifp_bulk_skipped'] ) : 0;
		$failed       = isset( $_GET['twaifp_bulk_failed'] ) ? absint( $_GET['twaifp_bulk_failed'] ) : 0;
		$start_failed = ! empty( $_GET['twaifp_bulk_start_failed'] );
		$code         = isset( $_GET['twaifp_bulk_language'] ) ? sanitize_key( wp_unslash( $_GET['twaifp_bulk_language'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$message = sprintf(
			/* translators: 1: New translations, 2: Retried translations, 3: Skipped posts, 4: Failed attempts. */
			__( 'Bulk translations: %1$d queued, %2$d retried, %3$d skipped, %4$d failed to queue.', 'translate-with-ai-for-polylang' ),
			$queued,
			$retried,
			$skipped,
			$failed
		);
		$class   = $failed || $start_failed || ( ! $queued && ! $retried && $skipped ) ? 'notice-warning' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message );
		if ( $failed ) {
			echo ' ' . esc_html__( 'Check the server error log for details.', 'translate-with-ai-for-polylang' );
		}
		if ( $start_failed ) {
			echo ' ' . esc_html__( 'The bulk queue could not be confirmed as started. Check the translation errors and retry as needed.', 'translate-with-ai-for-polylang' );
		}
		if ( $code && isset( $this->polylang->languages()[ $code ] ) && ( $queued || $retried || $start_failed ) ) {
			$url = add_query_arg(
				[ 'post_type' => $screen->post_type, 'lang' => $code ],
				admin_url( 'edit.php' )
			);
			echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'View translation drafts', 'translate-with-ai-for-polylang' ) . '</a>';
		}
		echo '</p></div>';
	}
}
