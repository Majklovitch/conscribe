<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Bootstrap;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * The version is stated in the code and repeated in two documents for readers.
 *
 * Before 2.0.0 it lived in four places that disagreed with each other; this
 * keeps the repetitions honest instead of relying on remembering them.
 */
#[CoversNothing]
final class VersionTest extends TestCase {
    public function testTheVersionIsSemantic(): void {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Bootstrap::VERSION);
    }

    #[TestDox('the README states the version the code has')]
    public function testReadmeAgrees(): void {
        $readme = (string) file_get_contents(Bootstrap::root() . '/README.md');

        self::assertSame(1, preg_match('/\*\*Version (\d+\.\d+\.\d+)\*\*/', $readme, $match));
        self::assertSame(Bootstrap::VERSION, $match[1]);
    }

    #[TestDox('the newest CHANGELOG entry is the version the code has')]
    public function testChangelogAgrees(): void {
        $changelog = (string) file_get_contents(Bootstrap::root() . '/CHANGELOG.md');

        self::assertSame(1, preg_match('/^## (\d+\.\d+\.\d+)/m', $changelog, $match));
        self::assertSame(Bootstrap::VERSION, $match[1]);
    }
}
