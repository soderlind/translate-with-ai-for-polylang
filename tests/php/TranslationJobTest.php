<?php
/**
 * Tests for background translation dispatch.
 *
 * @package TranslateWithAiForPolylang\Tests
 */

declare(strict_types=1);

namespace Soderlind\TranslateWithAiForPolylang\Tests;

use Brain\Monkey\Functions;
use Soderlind\TranslateWithAiForPolylang\Translation\BulkQueue;
use Soderlind\TranslateWithAiForPolylang\Translation\JobState;
use Soderlind\TranslateWithAiForPolylang\Translation\TranslationJob;
use Soderlind\TranslateWithAiForPolylang\Translation\Translator;
use WP_Error;

final class TranslationJobTest extends TestCase {

	private array $meta = [];
	private array $requests = [];
	private mixed $response = [];
	private bool $spawn = true;
	private int $token_number = 0;
	private TranslationJob $job;
	private array $posts = [];
	private array $options = [];
	private array $denied = [];
	private bool $allow_batch_storage = true;
	private int $current_user = 5;

	private const LANGUAGE = [ 'code' => 'nb', 'name' => 'Norwegian', 'locale' => 'nb_NO' ];

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb = new class( $this->options ) {
			public string $options = 'wp_options';
			public string $last_error = '';
			public ?\Closure $before_batch_write = null;
			public bool $allow_batch_writes = true;
			private array $batch_options;
			private array $locks = [];

			public function __construct( array &$batch_options ) {
				$this->batch_options = &$batch_options;
			}

			public function prepare( string $query, mixed ...$args ): array {
				return [ $query, $args ];
			}

			public function query( array $prepared ): int|false {
				[ $query, $args ] = $prepared;
				$batch_update = str_starts_with( $query, 'UPDATE' ) && str_starts_with( $args[1], BulkQueue::OPTION_PREFIX );
				$batch_delete = str_starts_with( $query, 'DELETE' ) && str_starts_with( $args[0], BulkQueue::OPTION_PREFIX );
				if ( $batch_update || $batch_delete ) {
					if ( null !== $this->before_batch_write ) {
						$callback = $this->before_batch_write;
						$this->before_batch_write = null;
						$callback();
					}
					if ( ! $this->allow_batch_writes ) {
						return false;
					}
					$name     = $batch_update ? $args[1] : $args[0];
					$expected = $batch_update ? $args[2] : $args[1];
					if ( ! isset( $this->batch_options[ $name ] ) || serialize( $this->batch_options[ $name ] ) !== $expected ) {
						return 0;
					}
					if ( $batch_update ) {
						$this->batch_options[ $name ] = unserialize( $args[0], [ 'allowed_classes' => false ] );
					} else {
						unset( $this->batch_options[ $name ] );
					}
					return 1;
				}
				if ( str_starts_with( $query, 'INSERT IGNORE' ) ) {
					if ( isset( $this->locks[ $args[0] ] ) ) {
						return 0;
					}
					$this->locks[ $args[0] ] = $args[1];
					return 1;
				}
				if ( str_starts_with( $query, 'UPDATE' ) ) {
					if ( ! isset( $this->locks[ $args[1] ] ) || ! str_starts_with( $this->locks[ $args[1] ], substr( $args[2], 0, -1 ) ) ) {
						return 0;
					}
					$this->locks[ $args[1] ] = $args[0];
					return 1;
				}
				if ( str_starts_with( $query, 'DELETE' ) ) {
					if ( isset( $this->locks[ $args[0] ] ) && str_starts_with( $this->locks[ $args[0] ], substr( $args[1], 0, -1 ) ) ) {
						unset( $this->locks[ $args[0] ] );
						return 1;
					}
				}
				return 0;
			}

			public function get_var( array $prepared ): ?string {
				$name = $prepared[1][0];
				if ( str_starts_with( $name, BulkQueue::OPTION_PREFIX ) ) {
					return isset( $this->batch_options[ $name ] ) ? serialize( $this->batch_options[ $name ] ) : null;
				}
				return $this->locks[ $name ] ?? null;
			}

			public function esc_like( string $value ): string {
				return $value;
			}
		};

		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ): void {
				$this->meta[ $id ][ $key ] = $value;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $id, $key ): void {
				unset( $this->meta[ $id ][ $key ] );
			}
		);
		Functions\when( 'wp_generate_password' )->alias( fn( $length ) => 64 === $length ? 'worker-' . ++$this->token_number : 'lock-token' );
		Functions\when( 'admin_url' )->alias( static fn( $path ) => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) {
				$this->requests[] = [ $url, $args ];
				return is_callable( $this->response ) ? ( $this->response )( $args ) : $this->response;
			}
		);
		Functions\when( 'apply_filters' )->alias( fn( $hook, $value ) => 'twaifp_spawn_cron' === $hook ? $this->spawn : $value );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'do_action' )->justReturn( null );

		$generator = new FakeGenerator();
		$this->job = new TranslationJob( new Translator( $generator ), $generator );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_queue_dispatches_directly_and_stores_only_a_token_hash(): void {
		Functions\expect( 'wp_schedule_single_event' )->never();
		$this->queue();

		$this->assertCount( 1, $this->requests );
		[ $url, $args ] = $this->requests[0];
		$this->assertSame( 'https://example.test/wp-admin/admin-ajax.php', $url );
		$this->assertFalse( $args['blocking'] );
		$this->assertSame( 'twaifp_run_translation', $args['body']['action'] );
		$this->assertSame( 7, $args['body']['post_id'] );
		$this->assertSame( hash( 'sha256', $args['body']['token'] ), $this->meta[7][ TranslationJob::META_WORKER ] );
		$this->assertSame( TranslationJob::STATUS_PENDING, $this->meta[7][ TranslationJob::META_STATUS ] );
	}

	public function test_explicit_legacy_filter_still_schedules_cron(): void {
		$this->spawn = false;
		Functions\expect( 'wp_schedule_single_event' )->once()->andReturn( true );

		$this->queue();

		$this->assertSame( [], $this->requests );
		$this->assertArrayNotHasKey( TranslationJob::META_WORKER, $this->meta[7] );
	}

	public function test_manual_queue_does_not_dispatch(): void {
		$this->queue( false );

		$this->assertSame( [], $this->requests );
		$this->assertArrayNotHasKey( TranslationJob::META_WORKER, $this->meta[7] );
	}

	public function test_start_failure_is_reported_as_retryable(): void {
		$this->response = new WP_Error( 'http_request_failed', 'Loopback blocked.' );
		$this->queue();

		$state = $this->job->state( 7 );
		$this->assertSame( TranslationJob::STATUS_FAILED, $state->status );
		$this->assertStringContainsString( 'Loopback blocked.', $state->error );
		$this->assertTrue( $state->can_retry() );
		$this->assertArrayNotHasKey( TranslationJob::META_WORKER, $this->meta[7] );
	}

	public function test_legacy_scheduling_failure_is_reported(): void {
		$this->spawn = false;
		Functions\when( 'wp_schedule_single_event' )->justReturn( new WP_Error( 'schedule_failed', 'Scheduling blocked.' ) );
		$this->queue();

		$this->assertSame( TranslationJob::STATUS_FAILED, $this->job->state( 7 )->status );
		$this->assertStringContainsString( 'Scheduling blocked.', $this->job->state( 7 )->error );
	}

	public function test_worker_token_is_single_use_and_requeue_invalidates_old_requests(): void {
		$this->queue();
		$old_token = $this->requests[0][1]['body']['token'];
		$this->queue();
		$token = $this->requests[1][1]['body']['token'];

		$this->assertSame( 'twaifp_invalid_worker', $this->job->run( 7, $old_token )->get_error_code() );
		$this->assertSame( hash( 'sha256', $token ), $this->meta[7][ TranslationJob::META_WORKER ] );

		// The fake post is missing, but the valid token is consumed before attempting the job.
		$this->assertSame( 'twaifp_invalid_job', $this->job->run( 7, $token )->get_error_code() );
		$this->assertSame( 'twaifp_invalid_worker', $this->job->run( 7, $token )->get_error_code() );
	}

	public function test_manual_run_invalidates_a_pending_worker(): void {
		$this->queue();
		$token = $this->requests[0][1]['body']['token'];

		$this->assertSame( 'twaifp_invalid_job', $this->job->run( 7 )->get_error_code() );
		$this->assertSame( 'twaifp_invalid_worker', $this->job->run( 7, $token )->get_error_code() );
	}

	public function test_worker_rejects_an_invalid_token_before_running(): void {
		$this->queue();
		Functions\when( 'absint' )->alias( static fn( $value ) => abs( (int) $value ) );
		Functions\when( 'wp_unslash' )->alias( static fn( $value ) => $value );
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data, $status ): void {
				throw new \RuntimeException( $data['message'], $status );
			}
		);
		$_POST = [ 'post_id' => '7', 'token' => 'wrong' ];
		try {
			$this->job->handle_worker();
			$this->fail( 'The request should have been rejected.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 403, $exception->getCode() );
			$this->assertSame( TranslationJob::STATUS_PENDING, $this->job->state( 7 )->status );
		} finally {
			$_POST = [];
		}
	}

	public function test_worker_completes_only_once_even_if_dispatch_reports_an_error(): void {
		require_once dirname( __DIR__, 2 ) . '/vendor/johnpbloch/wordpress-core/wp-includes/class-wp-post.php';
		$post = new \WP_Post(
			(object) [
				'ID'                => 7,
				'post_author'       => '5',
				'post_title'        => 'Hello',
				'post_excerpt'      => '',
				'post_content'      => '',
				'post_modified_gmt' => '2026-01-01 00:00:00',
			]
		);
		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'get_current_user_id' )->justReturn( 99 );
		Functions\when( 'wp_set_current_user' )->justReturn( null );
		Functions\when( 'wp_slash' )->alias( static fn( $value ) => $value );
		Functions\when( 'sanitize_title' )->justReturn( 'hei' );
		Functions\when( 'absint' )->alias( static fn( $value ) => abs( (int) $value ) );
		Functions\when( 'wp_unslash' )->alias( static fn( $value ) => $value );
		Functions\when( 'wp_send_json_success' )->alias(
			static function (): void {
				throw new \RuntimeException( 'Worker completed.', 200 );
			}
		);
		$saved = null;
		Functions\when( 'wp_update_post' )->alias(
			static function ( $data ) use ( &$saved ): int {
				$saved = $data;
				return 7;
			}
		);
		$generator = new FakeGenerator( [ 'Hei' ] );
		$this->job = new TranslationJob( new Translator( $generator ), $generator );
		$this->response = function ( $args ): WP_Error {
			$ignore_abort = ignore_user_abort();
			$_POST = $args['body'];
			try {
				$this->job->handle_worker();
				$this->fail( 'The worker should have sent a response.' );
			} catch ( \RuntimeException $exception ) {
				$this->assertSame( 200, $exception->getCode() );
			} finally {
				$_POST = [];
				ignore_user_abort( (bool) $ignore_abort );
			}
			return new WP_Error( 'http_request_failed', 'Connection closed.' );
		};

		$this->queue();

		$this->assertSame( TranslationJob::STATUS_DONE, $this->job->state( 7 )->status );
		$this->assertSame( 'Hei', $saved['post_title'] );
		$this->assertSame( 'twaifp_invalid_worker', $this->job->run( 7, $this->requests[0][1]['body']['token'] )->get_error_code() );
		$this->assertCount( 1, $generator->prompts );
	}

	public function test_bulk_jobs_run_in_order_and_remove_their_batch(): void {
		$queue = $this->prepare_bulk( [ 11, 12, 13 ] );

		$this->assertTrue( $queue->start( [ 11, 12, 13 ] ) );
		$this->assertSame( [ 11 ], $this->request_ids() );
		$this->assertTrue( $this->job->state( 12 )->is_waiting() );
		$this->assertSame( 'twaifp_waiting_in_batch', $this->job->run( 12 )->get_error_code() );

		$this->assertTrue( $this->run_request( 0 ) );
		$this->assertSame( [ 11, 12 ], $this->request_ids() );
		$this->assertSame( TranslationJob::STATUS_DONE, $this->job->state( 11 )->status );
		$queue->translated( 11, self::LANGUAGE, 111 );
		$this->assertSame( [ 11, 12 ], $this->request_ids(), 'A repeated completion must not start another worker.' );

		$this->assertTrue( $this->run_request( 1 ) );
		$this->assertSame( [ 11, 12, 13 ], $this->request_ids() );
		$this->assertTrue( $this->run_request( 2 ) );
		$this->assertSame( [], $this->options );
		foreach ( [ 11, 12, 13 ] as $post_id ) {
			$this->assertSame( TranslationJob::STATUS_DONE, $this->job->state( $post_id )->status );
			$this->assertArrayNotHasKey( BulkQueue::META_BATCH, $this->meta[ $post_id ] );
		}
	}

	public function test_translation_failure_does_not_stop_the_rest_of_the_batch(): void {
		$generator = new FakeGenerator( [ new WP_Error( 'ai_failed', 'The AI request failed.' ) ] );
		$queue     = $this->prepare_bulk( [ 11, 12 ], $generator );
		$this->posts[11]->post_title = 'Needs translation';
		$queue->start( [ 11, 12 ] );

		$this->assertSame( 'ai_failed', $this->run_request( 0 )->get_error_code() );
		$this->assertSame( TranslationJob::STATUS_FAILED, $this->job->state( 11 )->status );
		$this->assertSame( [ 11, 12 ], $this->request_ids() );
		$this->assertTrue( $this->run_request( 1 ) );
		$this->assertSame( [], $this->options );
	}

	public function test_start_failure_pauses_and_an_authorized_retry_resumes_in_order(): void {
		$queue          = $this->prepare_bulk( [ 11, 12 ] );
		$this->response = new WP_Error( 'http_request_failed', 'Loopback blocked.' );

		$this->assertTrue( is_wp_error( $queue->start( [ 11, 12 ] ) ) );
		$this->assertSame( TranslationJob::STATUS_FAILED, $this->job->state( 11 )->status );
		$this->assertTrue( $this->job->state( 12 )->batch_paused );
		$this->assertTrue( $this->job->state( 12 )->can_retry() );
		$this->assertSame( [ 11 ], $this->request_ids() );

		$this->response = [];
		$this->assertTrue( $queue->resume( 12, 9 ) );
		$this->assertSame( [ 11, 11 ], $this->request_ids() );
		$this->assertSame( 9, $this->meta[11][ TranslationJob::META_USER ] );
		$this->assertTrue( $this->run_request( 1 ) );
		$this->assertSame( [ 11, 11, 12 ], $this->request_ids() );
		$this->assertTrue( $this->run_request( 2 ) );
		$this->assertSame( [], $this->options );
	}

	public function test_later_worker_start_failure_pauses_the_remaining_jobs(): void {
		$queue = $this->prepare_bulk( [ 11, 12, 13 ] );
		$queue->start( [ 11, 12, 13 ] );
		$this->response = new WP_Error( 'http_request_failed', 'Loopback blocked.' );

		$this->assertTrue( $this->run_request( 0 ) );
		$this->assertSame( [ 11, 12 ], $this->request_ids() );
		$this->assertSame( TranslationJob::STATUS_FAILED, $this->job->state( 12 )->status );
		$this->assertTrue( $this->job->state( 13 )->batch_paused );

		$this->response = [];
		$this->assertTrue( $queue->resume( 13, 5 ) );
		$this->assertTrue( $this->run_request( 2 ) );
		$this->assertSame( [ 11, 12, 12, 13 ], $this->request_ids() );
		$this->assertTrue( $this->run_request( 3 ) );
	}

	public function test_waiting_job_uses_current_job_heartbeat_and_can_recover_after_deletion(): void {
		$queue = $this->prepare_bulk( [ 11, 12 ] );
		$queue->start( [ 11, 12 ] );
		$option = array_key_first( $this->options );
		$old    = time() - JobState::STALE_AFTER - 1;
		$this->options[ $option ]['updated']            = $old;
		$this->meta[12][ TranslationJob::META_UPDATED ] = $old;

		$this->assertFalse( $this->job->state( 12 )->can_retry(), 'A fresh current worker keeps the waiting job alive.' );
		$this->meta[11][ TranslationJob::META_UPDATED ] = $old;
		$queue->deleting( 11 );
		unset( $this->posts[11], $this->meta[11] );
		$this->assertFalse( $this->job->state( 12 )->can_retry(), 'Deleting the current draft starts the recovery wait.' );
		$this->options[ $option ]['updated'] = $old;
		$this->assertTrue( $this->job->state( 12 )->can_retry() );
		$this->assertTrue( $queue->resume( 12, 5 ) );
		$this->assertSame( [ 11, 12 ], $this->request_ids() );
		$this->assertTrue( $this->run_request( 1 ) );
		$this->assertSame( [], $this->options );
	}

	public function test_concurrent_resumes_preserve_a_worker_start_failure(): void {
		$queue = $this->prepare_bulk( [ 11, 12, 13 ] );
		$queue->start( [ 11, 12, 13 ] );
		$option = array_key_first( $this->options );
		$this->options[ $option ]['updated'] = time() - JobState::STALE_AFTER - 1;
		unset( $this->posts[11], $this->meta[11] );
		$raced = false;

		global $wpdb;
		$wpdb->before_batch_write = function () use ( $queue, &$raced ): void {
			$raced = true;
			$this->response = new WP_Error( 'http_request_failed', 'Loopback blocked.' );
			$this->assertSame( 'http_request_failed', $queue->resume( 13, 5 )->get_error_code() );
		};

		$this->assertSame( 'twaifp_batch_changed', $queue->resume( 13, 5 )->get_error_code() );

		$this->assertTrue( $raced );
		$this->assertSame( 1, $this->options[ $option ]['current'] );
		$this->assertTrue( $this->options[ $option ]['paused'] );
		$this->assertSame( [ 11, 12 ], $this->request_ids() );
		$this->assertTrue( $this->job->state( 13 )->can_retry() );
	}

	public function test_concurrent_resume_cannot_skip_a_failure_after_another_job_finishes(): void {
		$queue = $this->prepare_bulk( [ 11, 12, 13, 14 ] );
		$queue->start( [ 11, 12, 13, 14 ] );
		$option = array_key_first( $this->options );
		$this->options[ $option ]['updated'] = time() - JobState::STALE_AFTER - 1;
		unset( $this->posts[11], $this->meta[11] );
		$raced = false;

		global $wpdb;
		$wpdb->before_batch_write = function () use ( $queue, &$raced ): void {
			$raced = true;
			$this->assertTrue( $queue->resume( 14, 5 ) );
			$this->response = new WP_Error( 'http_request_failed', 'Loopback blocked.' );
			$this->assertTrue( $this->run_request( 1 ) );
		};

		$this->assertSame( 'twaifp_batch_changed', $queue->resume( 14, 5 )->get_error_code() );

		$this->assertTrue( $raced );
		$this->assertSame( 2, $this->options[ $option ]['current'] );
		$this->assertTrue( $this->options[ $option ]['paused'] );
		$this->assertSame( [ 11, 12, 13 ], $this->request_ids() );

		$this->response = [];
		$this->assertTrue( $queue->resume( 14, 9 ) );
		$this->assertTrue( $this->run_request( 3 ) );
		$this->assertSame( [ 11, 12, 13, 13, 14 ], $this->request_ids() );
		$this->assertTrue( $this->run_request( 4 ) );
		$this->assertSame( [], $this->options );
	}

	public function test_advancement_retries_after_a_concurrent_batch_heartbeat(): void {
		$queue = $this->prepare_bulk( [ 11, 12 ] );
		$queue->start( [ 11, 12 ] );
		$raced = false;
		global $wpdb;
		$wpdb->before_batch_write = static function () use ( $queue, &$raced ): void {
			$raced = true;
			$queue->deleting( 11 );
		};

		$this->assertTrue( $this->run_request( 0 ) );
		$this->assertTrue( $raced );
		$this->assertSame( [ 11, 12 ], $this->request_ids() );
		$this->assertTrue( $this->run_request( 1 ) );
		$this->assertSame( [], $this->options );
	}

	public function test_worker_start_failure_retries_its_pause_after_a_concurrent_heartbeat(): void {
		$queue = $this->prepare_bulk( [ 11, 12 ] );
		$this->response = new WP_Error( 'http_request_failed', 'Loopback blocked.' );
		$raced = false;
		global $wpdb;
		$wpdb->before_batch_write = static function () use ( $queue, &$raced ): void {
			$raced = true;
			$queue->deleting( 11 );
		};

		$this->assertSame( 'http_request_failed', $queue->start( [ 11, 12 ] )->get_error_code() );
		$this->assertTrue( $raced );
		$this->assertTrue( $this->job->state( 12 )->batch_paused );
		$this->response = [];
		$this->assertTrue( $queue->resume( 12, 5 ) );
		$this->assertTrue( $this->run_request( 1 ) );
		$this->assertTrue( $this->run_request( 2 ) );
	}

	public function test_a_failed_atomic_batch_write_is_reported(): void {
		$queue = $this->prepare_bulk( [ 11, 12 ] );
		$this->response = new WP_Error( 'http_request_failed', 'Loopback blocked.' );
		$queue->start( [ 11, 12 ] );
		global $wpdb;
		$wpdb->allow_batch_writes = false;

		$this->assertSame( 'twaifp_batch_storage', $queue->resume( 12, 5 )->get_error_code() );
		$this->assertTrue( $this->job->state( 12 )->batch_paused );
		$this->assertSame( [ 11 ], $this->request_ids() );
	}

	public function test_deleting_all_drafts_removes_only_their_abandoned_batch(): void {
		$queue = $this->prepare_bulk( [ 11, 12 ] );
		$queue->start( [ 11, 12 ] );

		$queue->deleting( 12 );
		$this->assertCount( 1, $this->options );
		unset( $this->posts[12], $this->meta[12] );
		$queue->deleting( 11 );
		$this->assertSame( [], $this->options );
	}

	public function test_deletion_during_batch_setup_keeps_unassociated_remaining_jobs(): void {
		$queue     = $this->prepare_bulk( [ 11, 12 ] );
		$triggered = false;
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( $queue, &$triggered ): void {
				$this->meta[ $post_id ][ $key ] = $value;
				if ( ! $triggered && 11 === $post_id && BulkQueue::META_BATCH === $key ) {
					$triggered = true;
					$queue->deleting( 11 );
				}
			}
		);

		$this->assertTrue( $queue->start( [ 11, 12 ] ) );
		$this->assertCount( 1, $this->options );
		$this->assertSame( [ 11 ], $this->request_ids() );
	}

	public function test_resuming_a_batch_requires_permission_for_its_current_translation(): void {
		$queue          = $this->prepare_bulk( [ 11, 12 ] );
		$this->response = new WP_Error( 'http_request_failed', 'Loopback blocked.' );
		$queue->start( [ 11, 12 ] );
		$this->denied = [ 11 ];

		$this->assertFalse( $queue->can_resume( 12 ) );
		$this->assertSame( 'twaifp_batch_forbidden', $queue->resume( 12, 5 )->get_error_code() );
		$this->assertSame( [ 11 ], $this->request_ids() );
	}

	public function test_stale_worker_is_replaced_before_the_batch_continues(): void {
		$queue  = $this->prepare_bulk( [ 11, 12 ] );
		$queue->start( [ 11, 12 ] );
		$option = array_key_first( $this->options );
		$old    = time() - JobState::STALE_AFTER - 1;
		$this->options[ $option ]['updated']           = $old;
		$this->meta[11][ TranslationJob::META_STATUS ]  = TranslationJob::STATUS_PROCESSING;
		$this->meta[11][ TranslationJob::META_UPDATED ] = $old;

		$this->assertTrue( $queue->resume( 12, 9 ) );
		$this->assertSame( [ 11, 11 ], $this->request_ids() );
		$this->assertSame( 'twaifp_invalid_worker', $this->run_request( 0 )->get_error_code() );
		$this->assertTrue( $this->run_request( 1 ) );
		$this->assertSame( [ 11, 11, 12 ], $this->request_ids() );
		$this->assertTrue( $this->run_request( 2 ) );
	}

	public function test_a_second_resume_does_not_restart_a_job_already_being_prepared(): void {
		$queue          = $this->prepare_bulk( [ 11, 12 ] );
		$this->response = new WP_Error( 'http_request_failed', 'Loopback blocked.' );
		$queue->start( [ 11, 12 ] );
		$this->meta[11][ TranslationJob::META_STATUS ]  = TranslationJob::STATUS_PENDING;
		$this->meta[11][ TranslationJob::META_UPDATED ] = time();

		$this->assertSame( 'twaifp_batch_running', $queue->resume( 12, 5 )->get_error_code() );
		$this->assertSame( [ 11 ], $this->request_ids() );
	}

	public function test_manually_running_a_paused_current_job_clears_the_waiting_display(): void {
		$queue          = $this->prepare_bulk( [ 11, 12 ] );
		$this->response = new WP_Error( 'http_request_failed', 'Loopback blocked.' );
		$queue->start( [ 11, 12 ] );
		$this->meta[11][ TranslationJob::META_STATUS ] = TranslationJob::STATUS_PROCESSING;

		$this->assertFalse( $this->job->state( 12 )->batch_paused );
		$this->assertFalse( $this->job->state( 12 )->can_retry() );
	}

	public function test_invalid_current_job_fails_and_advances_the_batch(): void {
		$queue = $this->prepare_bulk( [ 11, 12 ] );
		$queue->start( [ 11, 12 ] );
		$this->meta[11][ TranslationJob::META_LANGUAGE ]['locale'] = [ 'invalid' ];

		$this->assertSame( 'twaifp_invalid_job', $this->run_request( 0 )->get_error_code() );
		$this->assertSame( TranslationJob::STATUS_FAILED, $this->job->state( 11 )->status );
		$this->assertSame( [ 11, 12 ], $this->request_ids() );
	}

	public function test_legacy_cron_batches_schedule_only_one_job_at_a_time(): void {
		$queue       = $this->prepare_bulk( [ 11, 12 ] );
		$this->spawn = false;
		$scheduled   = [];
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function ( $time, $hook, $args ) use ( &$scheduled ): bool {
				$scheduled[] = $args[0];
				return true;
			}
		);

		$this->assertTrue( $queue->start( [ 11, 12 ] ) );
		$this->assertSame( [ 11 ], $scheduled );
		$this->assertSame( 'twaifp_worker_queued', $this->job->start_pending( 11 )->get_error_code() );
		$this->job->handle_cron( 11 );
		$this->assertSame( [ 11, 12 ], $scheduled );
		$this->job->handle_cron( 12 );
		$this->assertSame( [], $this->options );
	}

	public function test_failed_batch_storage_leaves_prepared_jobs_retryable(): void {
		$queue                     = $this->prepare_bulk( [ 11, 12 ] );
		$this->allow_batch_storage = false;

		$this->assertSame( 'twaifp_batch_storage', $queue->start( [ 11, 12 ] )->get_error_code() );
		$this->assertSame( [], $this->request_ids() );
		$this->assertTrue( $this->job->state( 11 )->can_retry() );
		$this->assertTrue( $this->job->state( 12 )->can_retry() );
	}

	public function test_failed_batch_association_cleans_up_and_fails_prepared_jobs(): void {
		$queue = $this->prepare_bulk( [ 11, 12 ] );
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ): void {
				if ( 12 !== $post_id || BulkQueue::META_BATCH !== $key ) {
					$this->meta[ $post_id ][ $key ] = $value;
				}
			}
		);

		$this->assertSame( 'twaifp_batch_storage', $queue->start( [ 11, 12 ] )->get_error_code() );
		$this->assertSame( [], $this->options );
		$this->assertArrayNotHasKey( BulkQueue::META_BATCH, $this->meta[11] );
		$this->assertTrue( $this->job->state( 11 )->can_retry() );
		$this->assertTrue( $this->job->state( 12 )->can_retry() );
	}

	public function test_completed_batch_is_not_reported_as_failed_when_dispatch_returns_an_error(): void {
		$queue = $this->prepare_bulk( [ 11, 12 ] );
		$this->response = function ( $args ): WP_Error {
			$this->assertTrue( $this->job->run( $args['body']['post_id'], $args['body']['token'] ) );
			return new WP_Error( 'http_request_failed', 'Connection closed after the worker started.' );
		};

		$this->assertTrue( $queue->start( [ 11, 12 ] ) );
		$this->assertSame( [ 11, 12 ], $this->request_ids() );
		$this->assertSame( TranslationJob::STATUS_DONE, $this->job->state( 11 )->status );
		$this->assertSame( TranslationJob::STATUS_DONE, $this->job->state( 12 )->status );
		$this->assertSame( [], $this->options );
	}

	public function test_preparing_a_retry_rejects_mismatched_language_metadata(): void {
		$this->queue( false );
		$this->meta[7][ TranslationJob::META_STATUS ] = TranslationJob::STATUS_FAILED;
		$wrong = [ 'code' => 'de', 'name' => 'German', 'locale' => 'de_DE' ];

		$this->assertSame( 'twaifp_invalid_job', $this->job->prepare_retry( 7, 5, false, $wrong )->get_error_code() );
		$this->assertSame( TranslationJob::STATUS_FAILED, $this->job->state( 7 )->status );
		$updated = [ 'code' => 'nb', 'name' => 'Norwegian', 'locale' => 'nb_NO_new' ];
		$this->assertTrue( $this->job->prepare_retry( 7, 5, false, $updated ) );
		$this->assertSame( $updated, $this->job->state( 7 )->language );
		$this->assertSame( [], $this->requests );
	}

	/**
	 * Sets up a real batch and in-memory WordPress storage.
	 *
	 * @param list<int>          $post_ids  Translation post IDs.
	 * @param FakeGenerator|null $generator Optional scripted generator.
	 * @return BulkQueue
	 */
	private function prepare_bulk( array $post_ids, ?FakeGenerator $generator = null ): BulkQueue {
		require_once dirname( __DIR__, 2 ) . '/vendor/johnpbloch/wordpress-core/wp-includes/class-wp-post.php';
		foreach ( $post_ids as $post_id ) {
			$this->posts[ $post_id ] = new \WP_Post(
				(object) [
					'ID'                => $post_id,
					'post_author'       => '5',
					'post_status'       => 'draft',
					'post_type'         => 'post',
					'post_title'        => '',
					'post_excerpt'      => '',
					'post_content'      => '',
					'post_modified_gmt' => '2026-01-01 00:00:00',
				]
			);
		}

		Functions\when( 'wp_generate_password' )->alias(
			fn( $length ) => match ( $length ) {
				32      => str_repeat( 'b', 32 ),
				64      => 'worker-' . ++$this->token_number,
				default => 'lock-token',
			}
		);
		Functions\when( 'get_post' )->alias( fn( $post_id ) => $this->posts[ $post_id ] ?? null );
		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key, $value = '' ): void {
				if ( '' === $value || ( $this->meta[ $post_id ][ $key ] ?? '' ) === $value ) {
					unset( $this->meta[ $post_id ][ $key ] );
				}
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( $name, $value ): bool {
				if ( ! $this->allow_batch_storage || isset( $this->options[ $name ] ) ) {
					return false;
				}
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_option' )->alias( fn( $name ) => $this->options[ $name ] ?? false );
		Functions\when( 'maybe_serialize' )->alias( static fn( $value ) => serialize( $value ) );
		Functions\when( 'maybe_unserialize' )->alias( static fn( $value ) => unserialize( $value, [ 'allowed_classes' => false ] ) );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'delete_option' )->alias(
			function ( $name ): bool {
				if ( ! isset( $this->options[ $name ] ) ) {
					return false;
				}
				unset( $this->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'current_user_can' )->alias( fn( $cap, $post_id ) => ! in_array( $post_id, $this->denied, true ) );
		Functions\when( 'get_current_user_id' )->alias( fn() => $this->current_user );
		Functions\when( 'wp_set_current_user' )->alias(
			function ( $user_id ): void {
				$this->current_user = (int) $user_id;
			}
		);
		Functions\when( 'wp_slash' )->alias( static fn( $value ) => $value );
		Functions\when( 'wp_update_post' )->alias( static fn( $data ) => $data['ID'] );

		$generator = $generator ?? new FakeGenerator();
		$this->job = new TranslationJob( new Translator( $generator ), $generator );
		$queue     = new BulkQueue( $this->job );
		Functions\when( 'do_action' )->alias(
			static function ( $hook, ...$args ) use ( $queue ): void {
				if ( 'twaifp_translated' === $hook ) {
					$queue->translated( ...$args );
				} elseif ( 'twaifp_translation_failed' === $hook ) {
					$queue->failed( ...$args );
				}
			}
		);
		foreach ( $post_ids as $post_id ) {
			$this->job->queue( $post_id, self::LANGUAGE, $post_id + 100, 5, false );
		}
		return $queue;
	}

	/**
	 * @return list<int>
	 */
	private function request_ids(): array {
		return array_map( static fn( $request ) => $request[1]['body']['post_id'], $this->requests );
	}

	/**
	 * @param int $index Recorded request index.
	 * @return true|WP_Error
	 */
	private function run_request( int $index ) {
		$body = $this->requests[ $index ][1]['body'];
		return $this->job->run( $body['post_id'], $body['token'] );
	}

	private function queue( bool $schedule = true ): void {
		$this->job->queue( 7, self::LANGUAGE, 3, 5, $schedule );
	}
}
