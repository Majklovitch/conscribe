<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Bootstrap;
use App\Core\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(Bootstrap::class)]
final class TimezoneTest extends TestCase {
    protected function tearDown(): void {
        Config::set([]);
    }

    #[TestDox('without conscribe.timezone php.ini keeps deciding')]
    public function testUnset(): void {
        Config::set([]);
        self::assertNull(Bootstrap::timezone());
    }

    #[TestDox('a known zone is used as it is')]
    public function testKnown(): void {
        Config::set(['conscribe' => ['timezone' => 'Europe/Prague']]);
        self::assertSame('Europe/Prague', Bootstrap::timezone());
    }

    #[TestDox('an unknown zone falls back to UTC')]
    public function testUnknown(): void {
        Config::set(['conscribe' => ['timezone' => 'Mars/Olympus']]);
        $log = ini_set('error_log', '/dev/null');

        try {
            self::assertSame('UTC', Bootstrap::timezone());
        } finally {
            ini_set('error_log', (string) $log);
        }
    }
}
