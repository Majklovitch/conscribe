<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Which of main.php / main.prod.php is wanted. path() itself also checks the
 * files exist, which depends on the checkout, so the decision is tested alone.
 */
#[CoversClass(Config::class)]
final class ConfigTest extends TestCase {
    private string|false $appEnv;
    private array $server;

    protected function setUp(): void {
        $this->appEnv = getenv('APP_ENV');
        $this->server = $_SERVER;
    }

    protected function tearDown(): void {
        putenv($this->appEnv === false ? 'APP_ENV' : 'APP_ENV=' . $this->appEnv);
        $_SERVER = $this->server;
    }

    private static function wantsProduction(): bool {
        return (new ReflectionMethod(Config::class, 'wantsProduction'))->invoke(null);
    }

    #[TestDox('APP_ENV=$env decides, whatever the host')]
    #[DataProvider('appEnvCases')]
    public function testAppEnvWins(string $env, bool $production): void {
        putenv('APP_ENV=' . $env);
        $_SERVER['HTTP_HOST'] = 'localhost';

        self::assertSame($production, self::wantsProduction());
    }

    public static function appEnvCases(): array {
        return [
            'production'  => ['production', true],
            'development' => ['development', false],
            'local'       => ['local', false],
        ];
    }

    #[TestDox('the console without APP_ENV never picks production')]
    public function testConsoleDefaultsToDevelopment(): void {
        putenv('APP_ENV');
        $_SERVER['HTTP_HOST'] = 'www.example.com';

        // PHPUnit runs under the cli SAPI, which is the case being tested.
        self::assertFalse(self::wantsProduction());
    }

    #[TestDox('$host counts as local: $local')]
    #[DataProvider('hostCases')]
    public function testLocalHosts(string $host, bool $local): void {
        $method = new ReflectionMethod(Config::class, 'isLocalHost');

        self::assertSame($local, $method->invoke(null, $host));
    }

    public static function hostCases(): array {
        return [
            'localhost'         => ['localhost', true],
            'localhost:8080'    => ['localhost:8080', true],
            'IPv4'              => ['127.0.0.1', true],
            'IPv4 with port'    => ['192.168.1.20:8001', true],
            'IPv6 in brackets'  => ['[::1]', true],
            'IPv6 with port'    => ['[::1]:8080', true],
            '.local'            => ['shop.local', true],
            '.test with port'   => ['Shop.TEST:443', true],
            'public domain'     => ['www.example.com', false],
            'public with port'  => ['example.com:8443', false],
            'local as a prefix' => ['localhost.example.com', false],
        ];
    }

    #[TestDox('the main.php fallback is a real path')]
    public function testPathPointsIntoTheConfigDirectory(): void {
        self::assertMatchesRegularExpression('#/Config/main(\.prod)?\.php$#', Config::path());
    }

    #[TestDox('the overlay replaces file values by dot key, and fileValue() ignores it')]
    public function testOverlay(): void {
        Config::set(['shop' => ['name' => 'File', 'currency' => 'CZK', 'langs' => ['cs', 'en']]]);
        Config::overlay(['shop.name' => 'Database', 'shop.langs' => ['de'], 'new.key' => 1]);

        try {
            self::assertSame('Database', Config::get('shop.name'));
            self::assertSame('CZK', Config::get('shop.currency'));
            self::assertSame(['de'], Config::get('shop.langs'));
            self::assertSame(1, Config::get('new.key'));
            self::assertSame('File', Config::fileValue('shop.name'));
            self::assertArrayNotHasKey('new', Config::file());
        } finally {
            Config::set([]);
        }
    }

    #[TestDox('set() drops the overlay')]
    public function testSetClearsOverlay(): void {
        Config::set(['a' => 1]);
        Config::overlay(['a' => 2]);
        Config::set(['a' => 3]);

        try {
            self::assertSame(3, Config::get('a'));
        } finally {
            Config::set([]);
        }
    }
}
