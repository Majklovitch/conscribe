<?php

declare(strict_types=1);

namespace Tests\Core\Module;

use App\Core\Config;
use App\Core\Logging\Log;
use App\Core\Module\ModuleManager;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModuleManager::class)]
final class ModuleManagerTest extends TestCase {
    private TestHandler $log;
    private string $tmp = '';

    protected function setUp(): void {
        $this->log = new TestHandler();
        Log::set(new Logger('test', [$this->log]));
    }

    protected function tearDown(): void {
        Log::set(null);
        Config::set([]);

        if ($this->tmp !== '') {
            @rmdir($this->tmp . '/Broken');
            @rmdir($this->tmp);
        }
    }

    #[TestDox('A listed module that does not exist is skipped with a warning')]
    public function testTypoIsLogged(): void {
        Config::set(['modules' => ['Auth', 'Autth']]);

        $names = (new ModuleManager())->names();

        self::assertSame(['Auth'], $names);
        self::assertTrue($this->log->hasWarningThatContains('Module "Autth" is listed'));
        self::assertCount(1, $this->log->getRecords());
    }

    #[TestDox('A directory without Module.php is reported with the reason')]
    public function testDirectoryWithoutModuleFile(): void {
        $this->tmp = sys_get_temp_dir() . '/conscribe-modules-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/Broken', 0o777, true);
        Config::set(['modules' => ['Broken']]);

        self::assertSame([], (new ModuleManager($this->tmp))->names());
        self::assertTrue($this->log->hasWarningThatContains('it has no Module.php'));
    }

    #[TestDox('The same missing module is reported once per process')]
    public function testReportedOnce(): void {
        Config::set(['modules' => ['NoSuchModuleOnce']]);

        (new ModuleManager())->modules();
        (new ModuleManager())->modules();

        self::assertCount(1, $this->log->getRecords());
    }

    #[TestDox('Without the modules key everything found loads and nothing is logged')]
    public function testNoListNoWarning(): void {
        self::assertContains('Auth', (new ModuleManager())->names());
        self::assertSame([], $this->log->getRecords());
    }
}
