<?php
/**
 * Tests for localized WP-CLI messages.
 *
 * @package TranslateWithAiForPolylang\Tests
 */

declare(strict_types=1);

namespace Soderlind\TranslateWithAiForPolylang\Tests;

use Brain\Monkey\Functions;
use Mockery;
use Soderlind\TranslateWithAiForPolylang\Cli\Command;
use Soderlind\TranslateWithAiForPolylang\Creator;
use Soderlind\TranslateWithAiForPolylang\Translation\TranslationJob;

final class CliTranslationTest extends TestCase {

	public function test_missing_post_error_uses_the_plugin_text_domain(): void {
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( '__' )->alias(
			function ( $message, $domain ): string {
				$this->assertSame( 'Post not found.', $message );
				$this->assertSame( 'translate-with-ai-for-polylang', $domain );
				return 'Fant ikke innlegget.';
			}
		);

		$cli = Mockery::mock( 'alias:WP_CLI' );
		$cli->shouldReceive( 'error' )->once()->with( 'Fant ikke innlegget.' )
			->andThrow( new \RuntimeException( 'CLI exited.' ) );

		$command = new Command( $this->createMock( Creator::class ), $this->createMock( TranslationJob::class ) );
		$this->expectExceptionMessage( 'CLI exited.' );
		$command->translate( [ '0' ], [ 'language' => 'nb' ] );
	}
}
