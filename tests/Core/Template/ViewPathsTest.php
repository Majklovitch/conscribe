<?php

declare(strict_types=1);

namespace Tests\Core\Template;

use App\Core\Module\Module;
use App\Core\Template\View;
use App\Core\Template\ViewNotFoundException;
use App\Core\Template\ViewPaths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ViewPaths::class)]
#[CoversClass(View::class)]
#[CoversClass(Module::class)]
#[CoversFunction('component')]
final class ViewPathsTest extends TestCase {
    private string $dir;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/viewpaths-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/Views/orders', 0777, true);
        mkdir($this->dir . '/Views/components', 0777, true);
        file_put_contents($this->dir . '/Views/orders/show.php', 'Order <?= $number ?>');
        file_put_contents($this->dir . '/Views/components/price.php', '<b><?= $amount ?></b>');
        ViewPaths::reset();
    }

    protected function tearDown(): void {
        ViewPaths::reset();
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    #[TestDox('a namespaced name resolves into the registered directory and renders')]
    public function testNamespaced(): void {
        ViewPaths::register('demo', $this->dir . '/Views');

        self::assertSame($this->dir . '/Views/orders/show.php', ViewPaths::resolve('demo::orders/show'));
        self::assertTrue(View::exists('demo::orders/show'));
        self::assertSame('Order 7', View::render('demo::orders/show', ['number' => 7], 'none'));
    }

    #[TestDox('a name without a namespace resolves from app/Views as before')]
    public function testCore(): void {
        self::assertSame(ViewPaths::coreDir() . '/home.php', ViewPaths::resolve('home'));
        self::assertSame(ViewPaths::coreDir() . '/home.php', ViewPaths::resolve('/home/'));
    }

    #[TestDox('a prepended directory overrides the module one, falling back for missing files')]
    public function testOverrideOrder(): void {
        $override = $this->dir . '/override';
        mkdir($override);
        file_put_contents($override . '/show.php', 'override');
        ViewPaths::register('demo', $this->dir . '/Views/orders');
        ViewPaths::register('demo', $override, prepend: true);
        file_put_contents($this->dir . '/Views/orders/list.php', 'list');

        self::assertSame($override . '/show.php', ViewPaths::resolve('demo::show'));
        self::assertSame($this->dir . '/Views/orders/list.php', ViewPaths::resolve('demo::list'));
    }

    #[TestDox('registering a directory twice keeps one entry')]
    public function testNoDuplicates(): void {
        ViewPaths::register('demo', $this->dir . '/Views');
        ViewPaths::register('demo', $this->dir . '/Views/');

        self::assertSame([$this->dir . '/Views'], ViewPaths::directories('demo'));
    }

    #[TestDox('a module reports its Views folder, or null without one')]
    public function testModuleViews(): void {
        $dir = $this->dir;
        $module = new class ($dir) extends Module {
            public function __construct(private string $dir) {
            }

            public function path(): string {
                return $this->dir;
            }
        };

        self::assertSame($dir . '/Views', $module->views());
        self::assertNull((new \Modules\Auth\Module())->views());
    }

    #[DataProvider('invalidNames')]
    #[TestDox('invalid name $name is refused')]
    public function testInvalid(string $name): void {
        ViewPaths::register('demo', $this->dir . '/Views');

        self::assertNull(ViewPaths::resolve($name));
    }

    /** @return array<string, array{string}> */
    public static function invalidNames(): array {
        return [
            'traversal'           => ['demo::../Views/orders/show'],
            'core traversal'      => ['../Modules/Auth/Module'],
            'bad namespace'       => ['Demo::orders/show'],
            'namespace traversal' => ['../x::orders/show'],
            'unknown namespace'   => ['nope::orders/show'],
            'empty'               => ['demo::'],
            'dot'                 => ['demo::orders/show.php'],
        ];
    }

    #[TestDox('View throws for an unknown namespaced view')]
    public function testMissing(): void {
        $this->expectException(ViewNotFoundException::class);
        View::render('nope::x', [], 'none');
    }

    #[TestDox('component() reads a module component from Views/components')]
    public function testComponent(): void {
        ViewPaths::register('demo', $this->dir . '/Views');

        ob_start();
        component('demo::price', ['amount' => 42]);
        component('demo::../orders/show', ['number' => 1]);
        self::assertSame('<b>42</b>', ob_get_clean());
    }
}
