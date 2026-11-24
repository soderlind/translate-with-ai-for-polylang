<?php
/**
 * Tests for bulk translation status and retry controls.
 *
 * @package TranslateWithAiForPolylang\Tests
 */

declare(strict_types=1);

namespace Soderlind\TranslateWithAiForPolylang\Tests;

use Brain\Monkey\Functions;
use Soderlind\TranslateWithAiForPolylang\Admin\Admin;
use Soderlind\TranslateWithAiForPolylang\Creator;
use Soderlind\TranslateWithAiForPolylang\Translation\BulkQueue;
use Soderlind\TranslateWithAiForPolylang\Translation\JobState;
use Soderlind\TranslateWithAiForPolylang\Translation\TranslationJob;
use WP_Post;

final class AdminBulkTest extends TestCase {

	private Admin $admin;
	private WP_Post $post;
	private JobState $state;
	private bool $in_batch = true;
	private bool $can_resume = true;
	private array $response = [];

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/vendor/johnpbloch/wordpress-core/wp-includes/class-wp-post.php';

		$this->post  = new WP_Post( (object) [ 'ID' => 12, 'post_type' => 'post', 'post_status' => 'draft' ] );
		$this->state = new JobState(
			JobState::PENDING,
			[ 'code' => 'nb', 'name' => 'Norwegian', 'locale' => 'nb_NO' ],
			'',
			time(),
			time(),
			true
		);
		$creator = $this->createMock( Creator::class );
		$job     = $this->createMock( TranslationJob::class );
		$queue   = $this->createMock( BulkQueue::class );
		$job->method( 'state' )->willReturnCallback( fn() => $this->state );
		$queue->method( 'contains' )->willReturnCallback( fn() => $this->in_batch );
		$queue->method( 'can_resume' )->willReturnCallback( fn() => $this->can_resume );
		$this->admin = new Admin( $creator, $job, $queue );

		Functions\when( 'get_post' )->alias( fn( $id ) => 12 === $id ? $this->post : null );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'admin_url' )->alias( static fn( $path ) => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . '?' . http_build_query( $args ) );
		Functions\when( 'wp_nonce_url' )->alias( static fn( $url ) => $url . '&_wpnonce=test' );
		Functions\when( 'absint' )->alias( static fn( $value ) => abs( (int) $value ) );
		Functions\when( 'wp_unslash' )->alias( static fn( $value ) => $value );
		Functions\when( 'get_edit_post_link' )->justReturn( 'https://example.test/edit/12' );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data ): void {
				$this->response = $data;
			}
		);
	}

	public function test_paused_waiting_draft_offers_a_resume_action_and_status(): void {
		$actions = $this->admin->row_actions( [], $this->post );
		$states  = $this->admin->post_states( [], $this->post );

		$this->assertStringContainsString( 'Resume bulk translation', $actions['twaifp_retry'] );
		$this->assertStringContainsString( 'Bulk translation paused', $states['twaifp'] );
	}

	public function test_user_without_access_to_current_draft_cannot_resume_from_waiting_draft(): void {
		$this->can_resume = false;
		$this->assertSame( [], $this->admin->row_actions( [], $this->post ) );

		$_POST = [ 'post_id' => '12' ];
		try {
			$this->admin->ajax_translation_status();
		} finally {
			$_POST = [];
		}
		$this->assertTrue( $this->response['waiting'] );
		$this->assertTrue( $this->response['paused'] );
		$this->assertFalse( $this->response['retryable'] );
		$this->assertSame( '', $this->response['retryUrl'] );
	}

	public function test_failed_individual_translation_keeps_its_retry_label(): void {
		$this->in_batch = false;
		$this->state    = new JobState( JobState::FAILED, [], 'Failed', time() );

		$actions = $this->admin->row_actions( [], $this->post );
		$this->assertStringContainsString( 'Retry translation', $actions['twaifp_retry'] );
		$this->assertStringNotContainsString( 'Resume bulk translation', $actions['twaifp_retry'] );
	}
}
