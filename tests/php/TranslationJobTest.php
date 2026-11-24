<?php
/**
 * Tests for background translation dispatch.
 *
 * @package TranslateWithAiForPolylang\Tests
 */

declare(strict_types=1);

namespace Soderlind\TranslateWithAiForPolylang\Tests;

use Brain\Monkey\Functions;
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

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb = new class() {
			public string $options = 'wp_options';
			private array $locks = [];

			public function prepare( string $query, mixed ...$args ): array {
				return [ $query, $args ];
			}

			public function query( array $prepared ): int {
				[ $query, $args ] = $prepared;
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
				return $this->locks[ $prepared[1][0] ] ?? null;
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

	private function queue( bool $schedule = true ): void {
		$this->job->queue( 7, [ 'code' => 'nb', 'name' => 'Norwegian', 'locale' => 'nb_NO' ], 3, 5, $schedule );
	}
}
