<?php
/**
 * Tests for the bundled Norwegian Bokmål translation.
 *
 * @package TranslateWithAiForPolylang\Tests
 */

declare(strict_types=1);

namespace Soderlind\TranslateWithAiForPolylang\Tests;

final class NorwegianCatalogTest extends TestCase {

	public function test_compiled_catalogs_match_the_complete_source_translation(): void {
		$root = dirname( __DIR__, 2 );
		$core = $root . '/vendor/johnpbloch/wordpress-core/wp-includes';
		require_once $core . '/pomo/po.php';
		require_once $core . '/l10n/class-wp-translation-file.php';
		require_once $core . '/l10n/class-wp-translation-file-mo.php';
		require_once $core . '/l10n/class-wp-translation-file-php.php';

		$template = new \PO();
		$catalog  = new \PO();
		$base     = $root . '/languages/translate-with-ai-for-polylang';
		$this->assertTrue( $template->import_from_file( $base . '.pot' ) );
		$this->assertTrue( $catalog->import_from_file( $base . '-nb_NO.po' ) );
		$this->assertEqualsCanonicalizing( array_keys( $template->entries ), array_keys( $catalog->entries ) );

		$expected = [];
		foreach ( $catalog->entries as $key => $entry ) {
			$this->assertNotContains( 'fuzzy', $entry->flags );
			$this->assertNotEmpty( $entry->translations, $key );
			$expected[ $key ] = implode( "\0", $entry->translations );
		}

		foreach ( [ '.mo', '.l10n.php' ] as $extension ) {
			$file = \WP_Translation_File::create( $base . '-nb_NO' . $extension );
			$this->assertInstanceOf( \WP_Translation_File::class, $file );
			$this->assertSame( 'nb_NO', $file->headers()['language'] ?? '' );
			$this->assertEquals( $expected, $file->entries() );
		}
	}
}
