<?php

declare(strict_types=1);

namespace Tests\Core\Database;

use App\Core\Database\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * A failed connection ends up in the log and in Tracy's saved blue screens,
 * so neither the message nor the stack trace may carry the password.
 */
#[CoversClass(Connection::class)]
final class ConnectionErrorTest extends TestCase {
    private const PASSWORD = 'pa55-must-not-leak';

    private string|false $ignoreArgs;

    protected function setUp(): void {
        // With arguments in the trace, the check below means something.
        $this->ignoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void {
        ini_set('zend.exception_ignore_args', (string) $this->ignoreArgs);
    }

    private static function failedConnection(): RuntimeException {
        // A driver that does not exist fails the same way on every machine,
        // without reaching for a server.
        // A closure bound to the class, not ReflectionMethod::invoke(): that
        // frame would carry the password in the trace on its own.
        $connect = \Closure::bind(
            static fn () => Connection::connect('nosuchdriver:host=db.internal;dbname=shop', 'shop_user', ConnectionErrorTest::PASSWORD),
            null,
            Connection::class,
        );

        try {
            $connect();
        } catch (RuntimeException $e) {
            return $e;
        }

        self::fail('The connection was expected to fail.');
    }

    #[TestDox('The connection error keeps the reason but drops the DSN')]
    public function testMessage(): void {
        $e = self::failedConnection();

        self::assertStringStartsWith('Could not connect to the database: ', $e->getMessage());
        self::assertStringNotContainsString('db.internal', $e->getMessage());
        self::assertStringNotContainsString(self::PASSWORD, $e->getMessage());
    }

    #[TestDox('The password appears nowhere in the exception chain')]
    public function testPasswordNotInTrace(): void {
        $e = self::failedConnection();
        self::assertInstanceOf(\PDOException::class, $e->getPrevious());

        for ($current = $e; $current instanceof Throwable; $current = $current->getPrevious()) {
            self::assertStringNotContainsString(self::PASSWORD, $current->getMessage());
            self::assertStringNotContainsString(self::PASSWORD, $current->getTraceAsString());
            // The frames of the connection itself, with their arguments.
            foreach ($current->getTrace() as $frame) {
                if (in_array($frame['class'] ?? '', [Connection::class, \PDO::class], true)) {
                    self::assertStringNotContainsString(self::PASSWORD, print_r($frame['args'] ?? [], true));
                }
            }
        }
    }
}
