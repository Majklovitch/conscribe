<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use App\Core\Config;
use App\Core\Http\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(Response::class)]
final class RedirectTest extends TestCase {
    protected function setUp(): void {
        Config::set(['conscribe' => ['base_url' => 'https://example.cz/']]);
    }

    protected function tearDown(): void {
        Config::set([]);
    }

    /** @return array<string, array{string}> */
    public static function offSite(): array {
        return [
            'protocol-relative' => ['//evil.com'],
            'slash backslash' => ['/\\evil.com'],
            'backslash' => ['\\evil.com'],
            'two backslashes' => ['\\\\evil.com'],
            'backslash slash' => ['\\/evil.com'],
            'tab between slashes' => ["/\t/evil.com"],
            'other host' => ['https://evil.com/'],
            'script' => ['javascript:alert(1)'],
        ];
    }

    #[DataProvider('offSite')]
    #[TestDox('refuses $url')]
    public function testOffSiteTargetsAreRefused(string $url): void {
        // Browsers read '\' as '/' and drop tabs, so each of these is a way of
        // writing '//evil.com'.
        $this->expectException(InvalidArgumentException::class);

        Response::sanitizeRedirectUrl($url);
    }

    /** @return array<string, array{string, string}> */
    public static function local(): array {
        return [
            'path' => ['/kontakt', '/kontakt'],
            'relative' => ['kontakt', '/kontakt'],
            'with query' => ['/a/b?c=d', '/a/b?c=d'],
            'same host' => ['https://example.cz/account', 'https://example.cz/account'],
        ];
    }

    #[DataProvider('local')]
    public function testLocalTargetsAreKept(string $url, string $expected): void {
        self::assertSame($expected, Response::sanitizeRedirectUrl($url));
    }
}
