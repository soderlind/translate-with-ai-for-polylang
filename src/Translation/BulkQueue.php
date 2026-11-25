<?php
/**
 * Sequential background translation batches.
 *
 * @package TranslateWithAiForPolylang
 */

declare(strict_types=1);

namespace Soderlind\TranslateWithAiForPolylang\Translation;

use WP_Error;
use WP_Post;

/**
 * Starts one prepared translation at a time and advances after it finishes.
 */
class BulkQueue {

	public const META_BATCH    = '_twaifp_bulk_batch';
	public const OPTION_PREFIX = '_twaifp_bulk_batch_';

	private const WRITE_ATTEMPTS = 3;

	/**
	 * Constructor.
	 *
	 * @param TranslationJob $job Translation worker.
	 */
	public function __construct( private TranslationJob $job ) {}

	/**
	 * Registers the completion hooks, including for non-admin workers.
	 */
	public function register(): void {
		add_action( 'twaifp_translated', [ $this, 'translated' ], 10, 3 );
		add_action( 'twaifp_translation_failed', [ $this, 'failed' ], 10, 3 );
		add_action( 'before_delete_post', [ $this, 'deleting' ] );
	}

	/**
	 * Starts a batch of already prepared, pending translations.
	 *
	 * @param list<int> $post_ids Translation post IDs, in processing order.
	 * @return true|WP_Error
	 */
	public function start( array $post_ids ) {
		if ( ! $post_ids ) {
			return new WP_Error( 'twaifp_empty_batch', __( 'There are no translations to queue.', 'translate-with-ai-for-polylang' ) );
		}

		$batch_id = wp_generate_password( 32, false, false );
		if ( 32 !== strlen( $batch_id ) || ! ctype_alnum( $batch_id ) ) {
			$error = new WP_Error( 'twaifp_batch_storage', __( 'Could not create a bulk translation queue identifier.', 'translate-with-ai-for-polylang' ) );
			$this->fail_prepared( $post_ids, $error );
			return $error;
		}
		$batch    = [
			'post_ids' => array_values( $post_ids ),
			'current'  => 0,
			'updated'  => time(),
			'paused'   => false,
			'revision' => 0,
		];

		if ( ! add_option( self::OPTION_PREFIX . $batch_id, $batch, '', false ) ) {
			$error = new WP_Error( 'twaifp_batch_storage', __( 'Could not save the bulk translation queue.', 'translate-with-ai-for-polylang' ) );
			$this->fail_prepared( $post_ids, $error );
			return $error;
		}

		foreach ( $post_ids as $post_id ) {
			update_post_meta( $post_id, self::META_BATCH, $batch_id );
			if ( $batch_id !== get_post_meta( $post_id, self::META_BATCH, true ) ) {
				$error = new WP_Error( 'twaifp_batch_storage', __( 'Could not associate a translation with the bulk queue.', 'translate-with-ai-for-polylang' ) );
				foreach ( $post_ids as $prepared_id ) {
					delete_post_meta( $prepared_id, self::META_BATCH, $batch_id );
				}
				delete_option( self::OPTION_PREFIX . $batch_id );
				$this->fail_prepared( $post_ids, $error );
				return $error;
			}
		}

		return $this->start_current( $batch_id, $batch );
	}

	/**
	 * Whether a translation belongs to a still-existing batch.
	 *
	 * @param int $post_id Translation post ID.
	 * @return bool
	 */
	public function contains( int $post_id ): bool {
		return null !== self::batch_for_post( $post_id );
	}

	/**
	 * Whether the current user may resume a batch from this translation.
	 *
	 * @param int $post_id Translation post ID.
	 * @return bool
	 */
	public function can_resume( int $post_id ): bool {
		$context = self::batch_for_post( $post_id );
		return null !== $context
			&& ( ! get_post( $context['current_id'] ) instanceof WP_Post || current_user_can( 'edit_post', $context['current_id'] ) );
	}

	/**
	 * Returns the current batch heartbeat for a translation waiting its turn.
	 *
	 * @param int $post_id Translation post ID.
	 * @return array{updated:int,paused:bool}|null
	 */
	public static function waiting_context( int $post_id ): ?array {
		$context = self::batch_for_post( $post_id );
		if ( null === $context || $context['current_id'] === $post_id ) {
			return null;
		}

		return [
			'updated' => max(
				(int) $context['batch']['updated'],
				(int) get_post_meta( $context['current_id'], TranslationJob::META_UPDATED, true )
			),
			'paused'  => (bool) $context['batch']['paused']
				&& TranslationJob::STATUS_PROCESSING !== get_post_meta( $context['current_id'], TranslationJob::META_STATUS, true ),
		];
	}

	/**
	 * Advances a batch after a successful translation.
	 *
	 * @param int                  $post_id   Translation post ID.
	 * @param array<string,string> $language  Target language.
	 * @param int                  $source_id Source post ID.
	 */
	public function translated( int $post_id, array $language, int $source_id ): void {
		$this->finish( $post_id );
	}

	/**
	 * Advances after translation failures, but pauses on worker-start failures.
	 *
	 * @param int                  $post_id  Translation post ID.
	 * @param WP_Error             $error    Translation error.
	 * @param array<string,string> $language Target language.
	 */
	public function failed( int $post_id, WP_Error $error, array $language ): void {
		if ( 'twaifp_worker_start_failed' !== $error->get_error_code() ) {
			$this->finish( $post_id );
			return;
		}

		for ( $attempt = 0; $attempt < self::WRITE_ATTEMPTS; $attempt++ ) {
			$context = self::batch_for_post( $post_id );
			if ( null === $context || $context['current_id'] !== $post_id || $context['batch']['paused'] ) {
				return;
			}

			$batch            = $context['batch'];
			$batch['paused']  = true;
			$batch['updated'] = time();
			$saved            = $this->save_batch( $context['id'], $batch, $context['batch'] );
			if ( 1 === $saved ) {
				return;
			}
			if ( false === $saved ) {
				break;
			}
		}
		error_log( 'Translate with AI for Polylang: could not pause a bulk translation queue.' );
	}

	/**
	 * Removes an abandoned batch when its last remaining draft is deleted.
	 *
	 * @param int $post_id Translation post ID.
	 */
	public function deleting( int $post_id ): void {
		$message = 'Translate with AI for Polylang: could not record a deleted bulk translation.';
		for ( $attempt = 0; $attempt < self::WRITE_ATTEMPTS; $attempt++ ) {
			$context = self::batch_for_post( $post_id );
			if ( null === $context ) {
				return;
			}

			$remaining = false;
			foreach ( $context['batch']['post_ids'] as $index => $member ) {
				$member = (int) $member;
				if ( $index >= (int) $context['batch']['current'] && $member !== $post_id && get_post( $member ) instanceof WP_Post ) {
					$remaining = true;
					break;
				}
			}

			if ( $remaining && $context['current_id'] !== $post_id ) {
				return;
			}

			if ( $remaining ) {
				$batch            = $context['batch'];
				$batch['updated'] = time();
				$saved            = $this->save_batch( $context['id'], $batch, $context['batch'] );
			} else {
				$message = 'Translate with AI for Polylang: could not remove an abandoned bulk translation queue.';
				$saved   = $this->delete_batch( $context['id'], $context['batch'] );
			}
			if ( 1 === $saved ) {
				return;
			}
			if ( false === $saved ) {
				break;
			}
		}
		error_log( $message );
	}

	/**
	 * Resumes the current job when a batch has paused or stopped responding.
	 *
	 * @param int $post_id A translation in the batch.
	 * @param int $user_id User requesting the resume.
	 * @return true|WP_Error
	 */
	public function resume( int $post_id, int $user_id ) {
		$context = self::batch_for_post( $post_id );
		if ( null === $context ) {
			return new WP_Error( 'twaifp_batch_missing', __( 'The bulk translation queue is no longer available.', 'translate-with-ai-for-polylang' ) );
		}
		$requested = $this->job->state( $post_id );
		if ( ! $requested || ! $requested->can_retry() || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'twaifp_not_retryable', __( 'This translation cannot be retried.', 'translate-with-ai-for-polylang' ) );
		}

		$current_id = $context['current_id'];
		$current    = get_post( $current_id );
		if ( ! $current instanceof WP_Post ) {
			return $this->advance( $context['id'], $context['batch'] );
		}
		if ( ! current_user_can( 'edit_post', $current_id ) ) {
			return new WP_Error( 'twaifp_batch_forbidden', __( 'You are not allowed to retry the current translation in this bulk queue.', 'translate-with-ai-for-polylang' ) );
		}

		$state = $this->job->state( $current_id );
		if ( ! $state || JobState::DONE === $state->status ) {
			return $this->advance( $context['id'], $context['batch'] );
		}
		if ( ! $state->can_retry() ) {
			return new WP_Error( 'twaifp_batch_running', __( 'The current bulk translation is still running.', 'translate-with-ai-for-polylang' ) );
		}

		$prepared = $this->job->prepare_retry( $current_id, $user_id, true );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$batch            = $context['batch'];
		$batch['paused']  = false;
		$batch['updated'] = time();
		$saved            = $this->save_batch( $context['id'], $batch, $context['batch'] );
		if ( 1 !== $saved ) {
			$error = false === $saved
				? new WP_Error( 'twaifp_batch_storage', __( 'Could not resume the bulk translation queue.', 'translate-with-ai-for-polylang' ) )
				: self::changed_error();
			$this->job->fail_pending( $current_id, $error );
			return $error;
		}

		return $this->start_current( $context['id'], $batch );
	}

	/**
	 * Advances only if this job is still the batch's current job.
	 *
	 * @param int $post_id Translation post ID.
	 */
	private function finish( int $post_id ): void {
		$context = self::batch_for_post( $post_id );
		if ( null === $context || $context['current_id'] !== $post_id ) {
			return;
		}

		$result = $this->advance( $context['id'], $context['batch'] );
		if ( is_wp_error( $result ) ) {
			error_log( 'Translate with AI for Polylang: ' . $result->get_error_message() );
		}
	}

	/**
	 * Moves to the next surviving, unfinished translation.
	 *
	 * @param string              $batch_id Batch identifier.
	 * @param array<string,mixed> $batch    Stored batch.
	 * @return true|WP_Error
	 */
	private function advance( string $batch_id, array $batch ) {
		for ( $attempt = 0; $attempt < self::WRITE_ATTEMPTS; $attempt++ ) {
			$old_index = (int) $batch['current'];
			$retired   = [ (int) $batch['post_ids'][ $old_index ] ];
			$count     = count( $batch['post_ids'] );
			$next      = null;

			for ( $index = $old_index + 1; $index < $count; $index++ ) {
				$post_id = (int) $batch['post_ids'][ $index ];
				$post    = get_post( $post_id );
				$state   = $post instanceof WP_Post ? $this->job->state( $post_id ) : null;
				if ( ! $state || $batch_id !== get_post_meta( $post_id, self::META_BATCH, true ) || $state->is_finished() ) {
					$retired[] = $post_id;
					continue;
				}
				$next = $index;
				break;
			}

			if ( null === $next ) {
				$saved = $this->delete_batch( $batch_id, $batch );
			} else {
				$updated            = $batch;
				$updated['current'] = $next;
				$updated['updated'] = time();
				$updated['paused']  = false;
				$saved              = $this->save_batch( $batch_id, $updated, $batch );
			}

			if ( 1 === $saved ) {
				$this->clear_membership( $retired, $batch_id );
				return null === $next ? true : $this->start_current( $batch_id, $updated );
			}
			if ( false === $saved ) {
				$message = null === $next
					? __( 'Could not finish the bulk translation queue.', 'translate-with-ai-for-polylang' )
					: __( 'Could not advance the bulk translation queue.', 'translate-with-ai-for-polylang' );
				return new WP_Error( 'twaifp_batch_storage', $message );
			}

			$latest = self::load_batch( $batch_id );
			if ( null === $latest
				|| $latest['post_ids'] !== $batch['post_ids']
				|| (int) $latest['current'] !== (int) $batch['current']
				|| (bool) $latest['paused'] !== (bool) $batch['paused']
			) {
				return self::changed_error();
			}
			$batch = $latest;
		}

		return self::changed_error();
	}

	/**
	 * Starts the current job, or waits for an independently started worker.
	 *
	 * @param string              $batch_id Batch identifier.
	 * @param array<string,mixed> $batch    Stored batch.
	 * @return true|WP_Error
	 */
	private function start_current( string $batch_id, array $batch ) {
		$post_id = (int) $batch['post_ids'][ (int) $batch['current'] ];
		$post    = get_post( $post_id );
		$state   = $post instanceof WP_Post ? $this->job->state( $post_id ) : null;
		if ( ! $state || $batch_id !== get_post_meta( $post_id, self::META_BATCH, true ) || $state->is_finished() ) {
			return $this->advance( $batch_id, $batch );
		}
		if ( JobState::PROCESSING === $state->status ) {
			return true;
		}

		$previous_user = get_current_user_id();
		$user_id       = (int) get_post_meta( $post_id, TranslationJob::META_USER, true );
		if ( $user_id ) {
			wp_set_current_user( $user_id );
		}
		try {
			$result = $this->job->start_pending( $post_id );
		} finally {
			wp_set_current_user( $previous_user );
		}

		if ( is_wp_error( $result ) ) {
			if ( 'twaifp_not_pending' === $result->get_error_code() ) {
				$context = self::batch_for_post( $post_id );
				$state   = $this->job->state( $post_id );
				if ( null !== $context && $context['current_id'] === $post_id && $state?->is_finished() ) {
					return $this->advance( $context['id'], $context['batch'] );
				}
			}
			if ( in_array( $result->get_error_code(), [ 'twaifp_in_progress', 'twaifp_worker_queued', 'twaifp_not_pending' ], true ) ) {
				return true;
			}
			$context = self::batch_for_post( $post_id );
			$state   = $this->job->state( $post_id );
			if ( null === $context || $context['current_id'] !== $post_id || JobState::PROCESSING === $state?->status || JobState::DONE === $state?->status ) {
				return true;
			}
		}
		return $result;
	}

	/**
	 * Retrieves a valid batch containing the post.
	 *
	 * @param int $post_id Translation post ID.
	 * @return array{id:string,batch:array<string,mixed>,current_id:int}|null
	 */
	private static function batch_for_post( int $post_id ): ?array {
		$batch_id = get_post_meta( $post_id, self::META_BATCH, true );
		if ( ! is_string( $batch_id ) || 32 !== strlen( $batch_id ) || ! ctype_alnum( $batch_id ) ) {
			return null;
		}

		$batch = self::load_batch( $batch_id );
		if ( null === $batch || ! in_array( $post_id, $batch['post_ids'], true ) ) {
			return null;
		}

		return [
			'id'         => $batch_id,
			'batch'      => $batch,
			'current_id' => (int) $batch['post_ids'][ (int) $batch['current'] ],
		];
	}

	/**
	 * Removes this batch's membership without touching a newer batch.
	 *
	 * @param list<int> $post_ids Translation post IDs.
	 * @param string    $batch_id Batch identifier.
	 */
	private function clear_membership( array $post_ids, string $batch_id ): void {
		foreach ( $post_ids as $post_id ) {
			delete_post_meta( $post_id, self::META_BATCH, $batch_id );
		}
	}

	/**
	 * Reads the batch directly so concurrent requests cannot use a cached snapshot.
	 *
	 * @param string $batch_id Batch identifier.
	 * @return array<string,mixed>|null
	 */
	private static function load_batch( string $batch_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_PREFIX . $batch_id ) );
		if ( null === $stored ) {
			if ( '' !== $wpdb->last_error ) {
				error_log( 'Translate with AI for Polylang: could not read a bulk translation queue.' );
			}
			return null;
		}

		$batch = maybe_unserialize( $stored );
		if ( ! is_array( $batch ) || ! isset( $batch['post_ids'], $batch['current'], $batch['updated'], $batch['paused'] )
			|| ! is_array( $batch['post_ids'] ) || ! isset( $batch['post_ids'][ (int) $batch['current'] ] )
		) {
			return null;
		}
		return $batch;
	}

	/**
	 * Saves a batch only if the stored snapshot has not changed.
	 *
	 * @param string              $batch_id Batch identifier.
	 * @param array<string,mixed> $batch    New batch state, updated with its revision.
	 * @param array<string,mixed> $expected Previous batch state.
	 * @return int|false Rows changed, or false on a database error.
	 */
	private function save_batch( string $batch_id, array &$batch, array $expected ): int|false {
		global $wpdb;

		$name = self::OPTION_PREFIX . $batch_id;
		$batch['revision'] = (int) ( $expected['revision'] ?? 0 ) + 1;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$saved = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s",
				maybe_serialize( $batch ),
				$name,
				maybe_serialize( $expected )
			)
		);
		if ( 1 === $saved ) {
			wp_cache_delete( $name, 'options' );
		}
		return $saved;
	}

	/**
	 * Removes a batch only if the stored snapshot has not changed.
	 *
	 * @param string              $batch_id Batch identifier.
	 * @param array<string,mixed> $expected Previous batch state.
	 * @return int|false Rows deleted, or false on a database error.
	 */
	private function delete_batch( string $batch_id, array $expected ): int|false {
		global $wpdb;

		$name = self::OPTION_PREFIX . $batch_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = %s",
				$name,
				maybe_serialize( $expected )
			)
		);
		if ( 1 === $deleted ) {
			wp_cache_delete( $name, 'options' );
		}
		return $deleted;
	}

	/**
	 * Reports a competing batch transition.
	 *
	 * @return WP_Error
	 */
	private static function changed_error(): WP_Error {
		return new WP_Error( 'twaifp_batch_changed', __( 'The bulk translation queue changed while it was being updated. Please try again.', 'translate-with-ai-for-polylang' ) );
	}

	/**
	 * Makes prepared jobs retryable when the queue cannot be persisted.
	 *
	 * @param list<int> $post_ids Translation post IDs.
	 * @param WP_Error  $error    Queue error.
	 */
	private function fail_prepared( array $post_ids, WP_Error $error ): void {
		foreach ( $post_ids as $post_id ) {
			$this->job->fail_pending( $post_id, $error );
		}
	}
}
