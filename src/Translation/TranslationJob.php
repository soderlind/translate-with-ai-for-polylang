<?php
/**
 * Background translation job.
 *
 * @package TranslateWithAiForPolylang
 */

declare(strict_types=1);

namespace Soderlind\TranslateWithAiForPolylang\Translation;

use WP_Error;
use WP_Post;

/**
 * Queues and runs the translation of a new Polylang translation.
 */
class TranslationJob {

	public const HOOK = 'twaifp_translate_post';

	public const META_STATUS   = '_twaifp_status';
	public const META_LANGUAGE = '_twaifp_language';
	public const META_ERROR    = '_twaifp_error';
	public const META_USER     = '_twaifp_user';
	public const META_SOURCE   = '_twaifp_source';
	public const META_UPDATED  = '_twaifp_updated';
	public const META_WORKER   = '_twaifp_worker';

	/**
	 * Every post meta key the job writes, e.g. for cleanup on uninstall.
	 */
	public const META_KEYS = [ self::META_STATUS, self::META_LANGUAGE, self::META_ERROR, self::META_USER, self::META_SOURCE, self::META_UPDATED, self::META_WORKER, BulkQueue::META_BATCH ];

	private const WORKER_ACTION = 'twaifp_run_translation';

	public const STATUS_PENDING    = JobState::PENDING;
	public const STATUS_PROCESSING = JobState::PROCESSING;
	public const STATUS_DONE       = JobState::DONE;
	public const STATUS_FAILED     = JobState::FAILED;

	/**
	 * Seconds without a heartbeat after which a job in "processing" is considered dead.
	 */
	public const STALE_AFTER = JobState::STALE_AFTER;

	/**
	 * Option name prefix of the per-post worker lock.
	 */
	public const LOCK_PREFIX = '_twaifp_lock_';

	/**
	 * Constructor.
	 *
	 * @param Translator    $translator Translator.
	 * @param TextGenerator $generator  The translator's text generator, whose requests refresh the job heartbeat.
	 */
	public function __construct( private Translator $translator, private TextGenerator $generator ) {}

	/**
	 * Registers the worker and legacy cron hooks.
	 */
	public function register(): void {
		add_action( self::HOOK, [ $this, 'handle_cron' ] );
		add_action( 'before_delete_post', [ $this, 'unschedule' ] );
		add_action( 'wp_ajax_' . self::WORKER_ACTION, [ $this, 'handle_worker' ] );
		add_action( 'wp_ajax_nopriv_' . self::WORKER_ACTION, [ $this, 'handle_worker' ] );
	}

	/**
	 * Removes pending jobs for a post that is being deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public function unschedule( $post_id ): void {
		wp_clear_scheduled_hook( self::HOOK, [ (int) $post_id ] );
	}

	/**
	 * Cron callback.
	 *
	 * @param int $post_id Translation post ID.
	 */
	public function handle_cron( $post_id ): void {
		$this->run( (int) $post_id );
	}

	/**
	 * Handles an authenticated, single-use background worker request.
	 */
	public function handle_worker(): void {
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$token   = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : '';

		if ( ! $post_id || ! $this->valid_worker_token( $post_id, $token ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid translation worker request.', 'translate-with-ai-for-polylang' ) ], 403 );
		}

		ignore_user_abort( true );
		$result = $this->run( $post_id, $token );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 409 );
		}

		wp_send_json_success();
	}

	/**
	 * Queues a translation for background translation.
	 *
	 * @param int                  $post_id   Translation post ID.
	 * @param array<string,string> $language  Target language ( code, name, locale ).
	 * @param int                  $source_id Source post ID.
	 * @param int                  $user_id   User who requested the translation.
	 * @param bool                 $schedule  Whether to start a background worker. Pass false when the caller runs the job itself.
	 */
	public function queue( int $post_id, array $language, int $source_id, int $user_id, bool $schedule = true ): void {
		update_post_meta( $post_id, self::META_LANGUAGE, $language );
		update_post_meta( $post_id, self::META_SOURCE, $source_id );
		update_post_meta( $post_id, self::META_USER, $user_id );
		delete_post_meta( $post_id, self::META_ERROR );
		$this->set_status( $post_id, self::STATUS_PENDING );

		wp_clear_scheduled_hook( self::HOOK, [ $post_id ] );
		delete_post_meta( $post_id, self::META_WORKER );
		if ( ! $schedule ) {
			return;
		}

		$dispatch = $this->prepare_dispatch( $post_id );
		if ( is_wp_error( $dispatch ) ) {
			$this->fail_start( $post_id, $dispatch );
			return;
		}
		$this->dispatch( $post_id, $dispatch );
	}

	/**
	 * Starts a pending job without resetting its saved language or source.
	 *
	 * @param int $post_id Translation post ID.
	 * @return true|WP_Error
	 */
	public function start_pending( int $post_id ) {
		$lock = $this->acquire_lock( $post_id );
		if ( null === $lock ) {
			return new WP_Error( 'twaifp_in_progress', __( 'This post is already being translated.', 'translate-with-ai-for-polylang' ) );
		}

		try {
			clean_post_cache( $post_id );
			if ( self::STATUS_PENDING !== get_post_meta( $post_id, self::META_STATUS, true ) ) {
				return new WP_Error( 'twaifp_not_pending', __( 'This translation is no longer queued.', 'translate-with-ai-for-polylang' ) );
			}
			if ( '' !== get_post_meta( $post_id, self::META_WORKER, true ) ) {
				return new WP_Error( 'twaifp_worker_queued', __( 'This translation already has a worker.', 'translate-with-ai-for-polylang' ) );
			}
			$this->set_status( $post_id, self::STATUS_PENDING );
			$dispatch = $this->prepare_dispatch( $post_id, true );
		} finally {
			$this->release_lock( $post_id, $lock );
		}

		if ( is_wp_error( $dispatch ) ) {
			$this->fail_start( $post_id, $dispatch );
			return $dispatch;
		}
		return $this->dispatch( $post_id, $dispatch );
	}

	/**
	 * Prepares a failed, or optionally stale, job for a later worker.
	 *
	 * @param int                       $post_id     Translation post ID.
	 * @param int                       $user_id     User requesting the retry.
	 * @param bool                      $allow_stale Whether to permit a stale active job.
	 * @param array<string,string>|null $language    Current target language, if supplied.
	 * @return true|WP_Error
	 */
	public function prepare_retry( int $post_id, int $user_id, bool $allow_stale = false, ?array $language = null ) {
		$lock = $this->acquire_lock( $post_id );
		if ( null === $lock ) {
			return new WP_Error( 'twaifp_in_progress', __( 'This post is already being translated.', 'translate-with-ai-for-polylang' ) );
		}

		try {
			clean_post_cache( $post_id );
			$state = $this->state( $post_id );
			if ( ! $state || ( self::STATUS_FAILED !== $state->status && ! ( $allow_stale && $state->is_stale() && ! $state->is_waiting() ) ) ) {
				return new WP_Error( 'twaifp_not_retryable', __( 'This translation cannot be retried.', 'translate-with-ai-for-polylang' ) );
			}
			foreach ( [ 'code', 'name', 'locale' ] as $key ) {
				if ( ! isset( $state->language[ $key ] ) || ! is_string( $state->language[ $key ] ) || '' === trim( $state->language[ $key ] ) ) {
					return new WP_Error( 'twaifp_invalid_job', __( 'Nothing to translate for this post.', 'translate-with-ai-for-polylang' ) );
				}
			}
			if ( null !== $language && $state->language['code'] !== ( $language['code'] ?? '' ) ) {
				return new WP_Error( 'twaifp_invalid_job', __( 'Nothing to translate for this post.', 'translate-with-ai-for-polylang' ) );
			}

			$this->queue(
				$post_id,
				$language ?? $state->language,
				(int) get_post_meta( $post_id, self::META_SOURCE, true ),
				$user_id,
				false
			);
			return true;
		} finally {
			$this->release_lock( $post_id, $lock );
		}
	}

	/**
	 * Marks a prepared job failed if it cannot be started.
	 *
	 * @param int      $post_id Translation post ID.
	 * @param WP_Error $error   Start error.
	 */
	public function fail_pending( int $post_id, WP_Error $error ): void {
		$this->fail_start( $post_id, $error );
	}

	/**
	 * Prepares a worker token, or selects the legacy cron runner.
	 *
	 * @param int  $post_id  Translation post ID.
	 * @param bool $mark_cron Mark a scheduled bulk job while holding its lock.
	 * @return string|WP_Error|null Worker token, error, or null for cron.
	 */
	private function prepare_dispatch( int $post_id, bool $mark_cron = false ) {
		/**
		 * Filters whether to start a worker immediately.
		 *
		 * Return false to retain the legacy behavior of leaving the job to WP-Cron.
		 *
		 * @param bool $spawn   Whether to start immediately. Default true.
		 * @param int  $post_id Translation post ID.
		 */
		if ( ! apply_filters( 'twaifp_spawn_cron', true, $post_id ) ) {
			if ( $mark_cron ) {
				update_post_meta( $post_id, self::META_WORKER, 'cron' );
				if ( 'cron' !== get_post_meta( $post_id, self::META_WORKER, true ) ) {
					return new WP_Error( 'twaifp_worker_storage', __( 'Could not save the translation worker state.', 'translate-with-ai-for-polylang' ) );
				}
			}
			return null;
		}

		$token = wp_generate_password( 64, false, false );
		$hash  = hash( 'sha256', $token );
		update_post_meta( $post_id, self::META_WORKER, $hash );
		if ( $hash !== get_post_meta( $post_id, self::META_WORKER, true ) ) {
			return new WP_Error( 'twaifp_worker_storage', __( 'Could not save the translation worker state.', 'translate-with-ai-for-polylang' ) );
		}
		return $token;
	}

	/**
	 * Dispatches a prepared worker after its lock has been released.
	 *
	 * @param int         $post_id Translation post ID.
	 * @param string|null $token   Worker token, or null for cron.
	 * @return true|WP_Error
	 */
	private function dispatch( int $post_id, ?string $token ) {
		if ( null === $token ) {
			$scheduled = wp_schedule_single_event( time(), self::HOOK, [ $post_id ], true );
			if ( false === $scheduled ) {
				$scheduled = new WP_Error( 'twaifp_schedule_failed', __( 'Could not schedule the translation worker.', 'translate-with-ai-for-polylang' ) );
			}
			if ( is_wp_error( $scheduled ) ) {
				$this->fail_start( $post_id, $scheduled );
				return $scheduled;
			}
			return true;
		}

		$result = wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			[
				'timeout'  => 0.01,
				'blocking' => false,
				'body'     => [
					'action'  => self::WORKER_ACTION,
					'post_id' => $post_id,
					'token'   => $token,
				],
			]
		);
		if ( is_wp_error( $result ) ) {
			$this->fail_start( $post_id, $result, $token );
			return $result;
		}
		return true;
	}

	/**
	 * Translates the post in place.
	 *
	 * @param int         $post_id Translation post ID.
	 * @param string|null $worker_token Single-use token for a background request, or null for a manual run.
	 * @return true|WP_Error
	 */
	public function run( int $post_id, ?string $worker_token = null ) {
		$token = $this->acquire_lock( $post_id );
		if ( null === $token ) {
			return new WP_Error( 'twaifp_in_progress', __( 'This post is already being translated.', 'translate-with-ai-for-polylang' ) );
		}

		try {
			clean_post_cache( $post_id );
			if ( null !== BulkQueue::waiting_context( $post_id ) ) {
				return new WP_Error( 'twaifp_waiting_in_batch', __( 'This translation is waiting for its turn in a bulk queue.', 'translate-with-ai-for-polylang' ) );
			}
			if ( null !== $worker_token && ( ! $this->valid_worker_token( $post_id, $worker_token ) || self::STATUS_PENDING !== get_post_meta( $post_id, self::META_STATUS, true ) ) ) {
				return new WP_Error( 'twaifp_invalid_worker', __( 'This translation worker request is no longer valid.', 'translate-with-ai-for-polylang' ) );
			}
			delete_post_meta( $post_id, self::META_WORKER );
			return $this->run_locked( $post_id, $token );
		} finally {
			$this->generator->before_request( null );
			$this->release_lock( $post_id, $token );
		}
	}

	/**
	 * Checks a worker token without storing the token itself.
	 *
	 * @param int    $post_id Translation post ID.
	 * @param string $token   Worker token.
	 * @return bool
	 */
	private function valid_worker_token( int $post_id, string $token ): bool {
		$stored = get_post_meta( $post_id, self::META_WORKER, true );
		return '' !== $token && is_string( $stored ) && '' !== $stored && hash_equals( $stored, hash( 'sha256', $token ) );
	}

	/**
	 * Records an immediate dispatch failure unless a worker has already started.
	 *
	 * @param int         $post_id Translation post ID.
	 * @param WP_Error    $error   Dispatch error.
	 * @param string|null $worker_token Token for a direct worker request.
	 */
	private function fail_start( int $post_id, WP_Error $error, ?string $worker_token = null ): void {
		$lock = $this->acquire_lock( $post_id );
		if ( null === $lock ) {
			return;
		}

		try {
			clean_post_cache( $post_id );
			if ( self::STATUS_PENDING !== get_post_meta( $post_id, self::META_STATUS, true )
				|| ( null !== $worker_token && ! $this->valid_worker_token( $post_id, $worker_token ) )
			) {
				return;
			}

			delete_post_meta( $post_id, self::META_WORKER );
			$message = sprintf(
				/* translators: %s: Error reported when starting the background worker. */
				__( 'Could not start the translation worker: %s', 'translate-with-ai-for-polylang' ),
				$error->get_error_message()
			);
			$failure  = new WP_Error( 'twaifp_worker_start_failed', $message );
			$language = get_post_meta( $post_id, self::META_LANGUAGE, true );
			$this->record_failure( $post_id, $failure, is_array( $language ) ? $language : [] );
		} finally {
			$this->release_lock( $post_id, $lock );
		}
	}

	/**
	 * Translates the post while holding its worker lock.
	 *
	 * @param int    $post_id Translation post ID.
	 * @param string $token   Lock token.
	 * @return true|WP_Error
	 */
	private function run_locked( int $post_id, string $token ) {
		clean_post_cache( $post_id );
		$post     = get_post( $post_id );
		$language = get_post_meta( $post_id, self::META_LANGUAGE, true );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'twaifp_invalid_job', __( 'Nothing to translate for this post.', 'translate-with-ai-for-polylang' ) );
		}
		if ( ! is_array( $language )
			|| ! isset( $language['name'], $language['locale'] )
			|| ! is_string( $language['name'] ) || '' === trim( $language['name'] )
			|| ! is_string( $language['locale'] ) || '' === trim( $language['locale'] )
		) {
			$error = new WP_Error( 'twaifp_invalid_job', __( 'Nothing to translate for this post.', 'translate-with-ai-for-polylang' ) );
			$this->record_failure( $post_id, $error, is_array( $language ) ? $language : [] );
			return $error;
		}

		$this->set_status( $post_id, self::STATUS_PROCESSING );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		$this->generator->before_request( fn(): bool => $this->heartbeat( $post_id, $token ) );

		// Save as the requesting user so kses and capabilities match a normal edit.
		$previous_user = get_current_user_id();
		$user_id       = (int) get_post_meta( $post_id, self::META_USER, true );
		wp_set_current_user( $user_id ? $user_id : (int) $post->post_author );

		$result = $this->translate_and_save( $post, $language, $token );

		wp_set_current_user( $previous_user );

		// A newer worker owns the job now; leave its status alone.
		if ( ! $this->owns_lock( $post_id, $token ) ) {
			return is_wp_error( $result ) && 'twaifp_lock_lost' === $result->get_error_code()
				? $result
				: new WP_Error( 'twaifp_lock_lost', __( 'Another worker took over this translation.', 'translate-with-ai-for-polylang' ) );
		}

		if ( is_wp_error( $result ) ) {
			$this->record_failure( $post_id, $result, $language );
			return $result;
		}

		delete_post_meta( $post_id, self::META_ERROR );
		$this->set_status( $post_id, self::STATUS_DONE );

		/**
		 * Fires after a post has been translated.
		 *
		 * @param int                  $post_id   Translation post ID.
		 * @param array<string,string> $language  Target language.
		 * @param int                  $source_id Source post ID.
		 */
		do_action( 'twaifp_translated', $post_id, $language, (int) get_post_meta( $post_id, self::META_SOURCE, true ) );
		return true;
	}

	/**
	 * Returns the state of a post's translation job, or null when it has none.
	 *
	 * @param int $post_id Post ID.
	 * @return JobState|null
	 */
	public function state( int $post_id ): ?JobState {
		$status = (string) get_post_meta( $post_id, self::META_STATUS, true );
		if ( ! in_array( $status, [ JobState::PENDING, JobState::PROCESSING, JobState::DONE, JobState::FAILED ], true ) ) {
			return null;
		}

		$language = get_post_meta( $post_id, self::META_LANGUAGE, true );
		$waiting  = self::STATUS_PENDING === $status ? BulkQueue::waiting_context( $post_id ) : null;

		return new JobState(
			$status,
			is_array( $language ) ? $language : [],
			(string) get_post_meta( $post_id, self::META_ERROR, true ),
			(int) get_post_meta( $post_id, self::META_UPDATED, true ),
			$waiting['updated'] ?? null,
			$waiting['paused'] ?? false
		);
	}

	/**
	 * Queues a post's translation again with its original language and source post.
	 *
	 * @param int $post_id Translation post ID.
	 * @param int $user_id User who requested the retry.
	 */
	public function requeue( int $post_id, int $user_id ): void {
		$this->queue(
			$post_id,
			(array) get_post_meta( $post_id, self::META_LANGUAGE, true ),
			(int) get_post_meta( $post_id, self::META_SOURCE, true ),
			$user_id
		);
	}

	/**
	 * Translates the fields and saves the post.
	 *
	 * @param WP_Post              $post     Translation post.
	 * @param array<string,string> $language Target language.
	 * @param string               $token    Lock token.
	 * @return true|WP_Error
	 */
	private function translate_and_save( WP_Post $post, array $language, string $token ) {
		$snapshot = [ $post->post_modified_gmt, $post->post_title, $post->post_excerpt, $post->post_content ];

		$title = $this->translator->translate_text( $post->post_title, $language, 'title' );
		if ( is_wp_error( $title ) ) {
			return $title;
		}

		$excerpt = $this->translator->translate_text( $post->post_excerpt, $language, 'excerpt' );
		if ( is_wp_error( $excerpt ) ) {
			return $excerpt;
		}

		$content = $this->translator->translate_content( $post->post_content, $language );
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		if ( ! $this->heartbeat( $post->ID, $token ) ) {
			return new WP_Error( 'twaifp_lock_lost', __( 'Another worker took over this translation.', 'translate-with-ai-for-polylang' ) );
		}

		// Modification times have one-second precision, so compare the fields too.
		clean_post_cache( $post->ID );
		$current = get_post( $post->ID );
		if ( ! $current instanceof WP_Post
			|| [ $current->post_modified_gmt, $current->post_title, $current->post_excerpt, $current->post_content ] !== $snapshot
		) {
			return new WP_Error(
				'twaifp_post_changed',
				__( 'The post was edited while it was being translated, so the translation was not saved. Retry to translate the current version.', 'translate-with-ai-for-polylang' )
			);
		}

		$data = [
			'ID'           => $post->ID,
			'post_title'   => $title,
			'post_excerpt' => $excerpt,
			'post_content' => $content,
		];
		if ( '' !== $title ) {
			$data['post_name'] = sanitize_title( $title );
		}

		/**
		 * Filters the translated post data before it is saved.
		 *
		 * @param array                $data     Post data passed to wp_update_post().
		 * @param WP_Post              $post     Post before translation.
		 * @param array<string,string> $language Target language.
		 */
		$data = apply_filters( 'twaifp_translated_post_data', $data, $post, $language );

		$updated = wp_update_post( wp_slash( $data ), true );
		return is_wp_error( $updated ) ? $updated : true;
	}

	/**
	 * Stores a job status and its timestamp.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status  Status.
	 */
	private function set_status( int $post_id, string $status ): void {
		update_post_meta( $post_id, self::META_STATUS, $status );
		update_post_meta( $post_id, self::META_UPDATED, time() );
	}

	/**
	 * Records and reports a translation failure.
	 *
	 * @param int                  $post_id  Translation post ID.
	 * @param WP_Error             $error    Error.
	 * @param array<string,string> $language Target language.
	 */
	private function record_failure( int $post_id, WP_Error $error, array $language ): void {
		update_post_meta( $post_id, self::META_ERROR, $error->get_error_message() );
		$this->set_status( $post_id, self::STATUS_FAILED );

		/**
		 * Fires when translating a post failed.
		 *
		 * @param int                  $post_id  Translation post ID.
		 * @param WP_Error             $error    Error.
		 * @param array<string,string> $language Target language.
		 */
		do_action( 'twaifp_translation_failed', $post_id, $error, $language );
	}

	/**
	 * Atomically claims the post's worker lock, taking over a lock whose heartbeat is stale.
	 *
	 * The lock is a row in the options table, read and written with direct queries so the
	 * unique option_name index makes the claim atomic and no object cache can serve stale values.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null Lock token, or null when another worker holds the lock.
	 */
	private function acquire_lock( int $post_id ): ?string {
		global $wpdb;

		$name  = self::LOCK_PREFIX . $post_id;
		$token = wp_generate_password( 20, false );
		$value = $token . '|' . time();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$claimed = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $value ) );
		if ( 1 === $claimed ) {
			return $token;
		}

		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( null === $current ) {
			// Released in the meantime; try once more.
			$claimed = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $value ) );
			return 1 === $claimed ? $token : null;
		}

		$heartbeat = (int) substr( (string) strrchr( (string) $current, '|' ), 1 );
		if ( time() - $heartbeat <= self::STALE_AFTER ) {
			return null;
		}

		// Compare-and-swap so only one worker can take over a stale lock.
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $current ) );
		// phpcs:enable

		return 1 === $taken ? $token : null;
	}

	/**
	 * Whether the given token still holds the post's lock.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $token   Lock token.
	 * @return bool
	 */
	private function owns_lock( int $post_id, string $token ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_PREFIX . $post_id ) );

		return is_string( $current ) && str_starts_with( $current, $token . '|' );
	}

	/**
	 * Refreshes the lock and job heartbeat, if the token still holds the lock.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $token   Lock token.
	 * @return bool False when another worker has taken over.
	 */
	private function heartbeat( int $post_id, string $token ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s", $token . '|' . time(), self::LOCK_PREFIX . $post_id, $wpdb->esc_like( $token . '|' ) . '%' ) );
		if ( ! $this->owns_lock( $post_id, $token ) ) {
			return false;
		}

		update_post_meta( $post_id, self::META_UPDATED, time() );
		return true;
	}

	/**
	 * Releases the post's lock, if the token still holds it.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $token   Lock token.
	 */
	private function release_lock( int $post_id, string $token ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", self::LOCK_PREFIX . $post_id, $wpdb->esc_like( $token . '|' ) . '%' ) );
	}
}
