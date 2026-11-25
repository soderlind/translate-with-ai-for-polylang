<?php
/**
 * Tests for post-list bulk translation selection.
 *
 * @package TranslateWithAiForPolylang\Tests
 */

declare(strict_types=1);

namespace Soderlind\TranslateWithAiForPolylang\Tests;

use Brain\Monkey\Functions;
use Soderlind\TranslateWithAiForPolylang\Admin\BulkActions;
use Soderlind\TranslateWithAiForPolylang\Creator;
use Soderlind\TranslateWithAiForPolylang\Polylang;
use Soderlind\TranslateWithAiForPolylang\Translation\BulkQueue;
use Soderlind\TranslateWithAiForPolylang\Translation\JobState;
use Soderlind\TranslateWithAiForPolylang\Translation\TranslationJob;
use WP_Error;
use WP_Post;

final class BulkActionsTest extends TestCase {

	private BulkActions $actions;
	private array $posts = [];
	private array $report = [];
	private array $prepared = [];
	private array $retried = [];
	private array $events = [];
	private string $start_error = '';

	private const LANGUAGE = [ 'code' => 'nb', 'name' => 'Norwegian <script>', 'locale' => 'nb_NO' ];

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/vendor/johnpbloch/wordpress-core/wp-includes/class-wp-post.php';

		for ( $id = 1; $id <= 15; $id++ ) {
			$this->posts[ $id ] = $this->post( $id, 12 === $id ? 'page' : 'post', 11 === $id ? 'trash' : 'publish' );
		}
		foreach ( [ 102, 104, 105, 107, 109, 110, 114 ] as $id ) {
			$this->posts[ $id ] = $this->post( $id, 'post', 114 === $id ? 'publish' : 'draft' );
		}

		$polylang = $this->createMock( Polylang::class );
		$polylang->method( 'languages' )->willReturn( [ 'nb' => self::LANGUAGE ] );
		$polylang->method( 'supports_post_type' )->willReturnCallback( static fn( $type ) => 'post' === $type );
		$translations = [ 2 => 102, 3 => 3, 4 => 104, 5 => 105, 7 => 107, 9 => 109, 10 => 110, 14 => 114 ];
		$polylang->method( 'translation_id' )->willReturnCallback(
			static fn( WP_Post $post ) => 15 === $post->ID ? null : ( $translations[ $post->ID ] ?? 0 )
		);

		$creator = $this->createMock( Creator::class );
		$creator->method( 'can_translate' )->willReturnCallback( static fn( WP_Post $post ) => 13 !== $post->ID );
		$creator->method( 'create' )->willReturnCallback(
			function ( WP_Post $post, array $language, bool $schedule ) {
				$this->events[] = 'create-' . $post->ID;
				$this->assertFalse( $schedule );
				$this->assertSame( self::LANGUAGE, $language );
				return 8 === $post->ID ? new WP_Error( 'insert_failed', 'Could not create draft.' ) : 101;
			}
		);

		$job = $this->createMock( TranslationJob::class );
		$job->method( 'state' )->willReturnCallback(
			static function ( int $post_id ): ?JobState {
				if ( 104 === $post_id ) {
					return new JobState( JobState::DONE, self::LANGUAGE, '', time() );
				}
				return in_array( $post_id, [ 102, 105, 107, 109, 110, 114 ], true )
					? new JobState( JobState::FAILED, self::LANGUAGE, 'Failed', time() )
					: null;
			}
		);
		$job->method( 'prepare_retry' )->willReturnCallback(
			function ( int $post_id, int $user_id, bool $allow_stale, array $language ) {
				$this->events[]  = 'retry-' . $post_id;
				$this->retried[] = $post_id;
				$this->assertSame( 5, $user_id );
				$this->assertFalse( $allow_stale );
				$this->assertSame( self::LANGUAGE, $language );
				return match ( $post_id ) {
					109     => new WP_Error( 'twaifp_invalid_job', 'Invalid language.' ),
					110     => new WP_Error( 'twaifp_in_progress', 'Already running.' ),
					default => true,
				};
			}
		);

		$queue = $this->createMock( BulkQueue::class );
		$queue->method( 'contains' )->willReturnCallback( static fn( int $post_id ) => 107 === $post_id );
		$queue->method( 'start' )->willReturnCallback(
			function ( array $post_ids ) {
				$this->events[] = 'start';
				$this->prepared = $post_ids;
				return $this->start_error ? new WP_Error( $this->start_error, 'Could not start.' ) : true;
			}
		);

		Functions\when( 'get_post' )->alias( fn( $id ) => $this->posts[ $id ] ?? null );
		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'base' => 'edit', 'post_type' => 'post' ] );
		Functions\when( 'current_user_can' )->alias( static fn( $cap, $id ) => ! in_array( $id, [ 6, 105 ], true ) );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'absint' )->alias( static fn( $value ) => abs( (int) $value ) );
		Functions\when( 'esc_html' )->alias( static fn( $text ) => htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
		Functions\when( 'remove_query_arg' )->justReturn( 'https://example.test/edit.php' );
		Functions\when( 'add_query_arg' )->alias(
			function ( $args, ...$unused ): string {
				$this->report = is_array( $args ) ? $args : [ $args => $unused[0] ];
				return 'https://example.test/edit.php?report';
			}
		);

		$this->actions = new BulkActions( $creator, $polylang, $job, $queue );
	}

	public function test_bulk_selection_prepares_only_eligible_posts_before_starting_the_batch(): void {
		$redirect = $this->actions->handle( 'https://example.test/edit.php', 'twaifp_translate_to_nb', range( 1, 15 ) );

		$this->assertSame( 'https://example.test/edit.php?report', $redirect );
		$this->assertSame( [ 101, 102 ], $this->prepared );
		$this->assertSame( [ 102, 109, 110 ], $this->retried );
		$this->assertSame( 'start', end( $this->events ) );
		$this->assertSame(
			[
				'twaifp_bulk_queued'       => 1,
				'twaifp_bulk_retried'      => 1,
				'twaifp_bulk_skipped'      => 11,
				'twaifp_bulk_failed'       => 2,
				'twaifp_bulk_start_failed' => 0,
				'twaifp_bulk_language'     => 'nb',
			],
			$this->report
		);
	}

	public function test_invalid_target_does_not_create_or_start_translations(): void {
		$this->actions->handle( 'https://example.test/edit.php', 'twaifp_translate_to_unknown', [ 1 ] );

		$this->assertSame( [ 'twaifp_bulk_invalid' => 1 ], $this->report );
		$this->assertSame( [], $this->events );
	}

	public function test_an_unlanguaged_source_cannot_produce_a_linked_translation(): void {
		Functions\when( 'pll_get_post_language' )->justReturn( false );
		Functions\expect( 'pll_get_post_translations' )->never();

		$this->assertNull( ( new Polylang() )->translation_id( $this->posts[15], 'nb' ) );
	}

	public function test_batch_start_failure_is_reported(): void {
		$this->start_error = 'http_request_failed';
		$this->actions->handle( 'https://example.test/edit.php', 'twaifp_translate_to_nb', [ 1 ] );

		$this->assertSame( 1, $this->report['twaifp_bulk_start_failed'] );
		$this->assertSame( [ 101 ], $this->prepared );
	}

	public function test_batch_storage_failure_is_counted_as_failed_not_queued(): void {
		$this->start_error = 'twaifp_batch_storage';
		$this->actions->handle( 'https://example.test/edit.php', 'twaifp_translate_to_nb', [ 1, 2 ] );

		$this->assertSame( 0, $this->report['twaifp_bulk_queued'] );
		$this->assertSame( 0, $this->report['twaifp_bulk_retried'] );
		$this->assertSame( 2, $this->report['twaifp_bulk_failed'] );
	}

	public function test_language_labels_are_escaped_for_the_bulk_action_dropdown(): void {
		$actions = $this->actions->actions( [ 'edit' => 'Edit' ] );

		$this->assertSame( 'Translate to Norwegian &lt;script&gt; with AI', $actions['twaifp_translate_to_nb'] );
		$this->assertSame( 'Edit', $actions['edit'] );
	}

	private function post( int $id, string $type, string $status ): WP_Post {
		return new WP_Post(
			(object) [
				'ID'          => $id,
				'post_type'   => $type,
				'post_status' => $status,
			]
		);
	}
}
