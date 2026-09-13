<?php

declare(strict_types=1);

namespace Tests\Core\Container;

use App\Core\Container\Container;
use App\Core\Container\ContainerException;
use App\Core\Container\NotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

// Fixtures. Kept in the test file because they exist only to have shapes the
// container must cope with, and naming them elsewhere would suggest otherwise.

interface GreeterInterface {
    public function greet(): string;
}

final class Greeter implements GreeterInterface {
    public function greet(): string {
        return 'hello';
    }
}

final class NoConstructor {
}

final class NeedsGreeter {
    public function __construct(public GreeterInterface $greeter) {
    }
}

final class NeedsConcrete {
    public function __construct(public NoConstructor $dependency) {
    }
}

final class HasDefaults {
    public function __construct(public string $name = 'default', public int $size = 3) {
    }
}

final class OptionalUnknown {
    public function __construct(public ?GreeterInterface $greeter = null) {
    }
}

final class NullableUnknown {
    public function __construct(public ?GreeterInterface $greeter) {
    }
}

/** Its dependency is optional - it copes without one. */
final class OptionalGreeter {
    public function __construct(public ?Greeter $greeter = null) {
    }
}

final class NeedsScalar {
    public function __construct(public string $dsn) {
    }
}

/** Its optional dependency exists as a class but cannot be autowired. */
final class OptionalUnbuildable {
    public function __construct(public ?NeedsScalar $dependency = null) {
    }
}

final class UnionTyped {
    public function __construct(public int|string $value) {
    }
}

final class LoopA {
    public function __construct(public LoopB $b) {
    }
}

final class LoopB {
    public function __construct(public LoopA $a) {
    }
}

final class Throws {
    public function __construct() {
        throw new \DomainException('constructor said no');
    }
}

abstract class NotInstantiable {
}

#[CoversClass(Container::class)]
final class ContainerTest extends TestCase {
    private Container $container;

    protected function setUp(): void {
        $this->container = new Container();
    }

    public function testItIsAPsr11Container(): void {
        self::assertInstanceOf(ContainerInterface::class, $this->container);
    }

    // -----------------------------------------------------------------
    // Autowiring
    // -----------------------------------------------------------------

    public function testItBuildsAClassWithNoConstructor(): void {
        self::assertInstanceOf(NoConstructor::class, $this->container->get(NoConstructor::class));
    }

    public function testItAutowiresAConcreteDependency(): void {
        $resolved = $this->container->get(NeedsConcrete::class);

        self::assertInstanceOf(NeedsConcrete::class, $resolved);
        self::assertInstanceOf(NoConstructor::class, $resolved->dependency);
    }

    #[TestDox('an interface resolves through its binding')]
    public function testItResolvesAnInterfaceThroughABinding(): void {
        $this->container->bind(GreeterInterface::class, static fn (): GreeterInterface => new Greeter());

        self::assertSame('hello', $this->container->get(NeedsGreeter::class)->greeter->greet());
    }

    public function testAliasPointsOneIdentifierAtAnother(): void {
        $this->container->alias(GreeterInterface::class, Greeter::class);

        self::assertInstanceOf(Greeter::class, $this->container->get(GreeterInterface::class));
    }

    #[TestDox('constructor defaults are used for scalars')]
    public function testItFallsBackToDefaults(): void {
        $resolved = $this->container->get(HasDefaults::class);

        self::assertSame('default', $resolved->name);
        self::assertSame(3, $resolved->size);
    }

    #[TestDox('an unknown but optional dependency uses its default')]
    public function testAnUnknownOptionalDependencyIsNotAnError(): void {
        self::assertNull($this->container->get(OptionalUnknown::class)->greeter);
    }

    #[TestDox('an unknown but nullable dependency becomes null')]
    public function testAnUnknownNullableDependencyBecomesNull(): void {
        self::assertNull($this->container->get(NullableUnknown::class)->greeter);
    }

    #[TestDox('an optional dependency does not run a factory that has not run yet')]
    public function testOptionalParametersDoNotTriggerFactories(): void {
        // Repository takes `?PDO $db = null` and connects on its first query;
        // building the connection to fill that parameter would open one on
        // every page that builds a repository, asked a question or not.
        $ran = 0;
        $this->container->bind(Greeter::class, static function () use (&$ran): Greeter {
            $ran++;

            return new Greeter();
        });

        self::assertNull($this->container->get(OptionalGreeter::class)->greeter);
        self::assertSame(0, $ran, 'the factory must not have run');
    }

    #[TestDox('an optional dependency that already exists is still injected')]
    public function testOptionalParametersUseAnAlreadyBuiltEntry(): void {
        // Deferring work is the point; refusing to share would be a different
        // and much less useful rule.
        $greeter = new Greeter();
        $this->container->instance(Greeter::class, $greeter);

        self::assertSame($greeter, $this->container->get(OptionalGreeter::class)->greeter);
    }

    #[TestDox('an optional dependency that cannot be autowired falls back to its default')]
    public function testOptionalUnbuildableDependencyUsesTheDefault(): void {
        // The UserRepository case: `?PDO $db = null` in a container with no PDO
        // binding threw on PDO's DSN instead of passing null.
        self::assertNull($this->container->get(OptionalUnbuildable::class)->dependency);
    }

    #[TestDox('a required dependency still runs its factory')]
    public function testRequiredParametersStillRunFactories(): void {
        $this->container->bind(GreeterInterface::class, static fn (): GreeterInterface => new Greeter());

        self::assertInstanceOf(Greeter::class, $this->container->get(NeedsGreeter::class)->greeter);
    }

    #[TestDox('an optional dependency with no binding is still autowired')]
    public function testOptionalParametersStillAutowirePlainClasses(): void {
        // The rule is about not running factories, not about ignoring
        // optional parameters: a plain class costs nothing to build.
        self::assertInstanceOf(Greeter::class, $this->container->get(OptionalGreeter::class)->greeter);
    }

    // -----------------------------------------------------------------
    // Sharing
    // -----------------------------------------------------------------

    #[TestDox('entries are shared by default')]
    public function testEntriesAreSharedByDefault(): void {
        self::assertSame($this->container->get(NoConstructor::class), $this->container->get(NoConstructor::class));
    }

    #[TestDox('a factory bound as not shared runs every time')]
    public function testNonSharedBindingsAreRebuilt(): void {
        $this->container->bind(Greeter::class, static fn (): Greeter => new Greeter(), shared: false);

        self::assertNotSame($this->container->get(Greeter::class), $this->container->get(Greeter::class));
    }

    public function testMakeAlwaysBuildsANewInstance(): void {
        $shared = $this->container->get(NoConstructor::class);

        self::assertNotSame($shared, $this->container->make(NoConstructor::class));
    }

    public function testMakeArgumentsWinOverAutowiring(): void {
        $resolved = $this->container->make(HasDefaults::class, ['name' => 'given']);

        self::assertSame('given', $resolved->name);
        self::assertSame(3, $resolved->size, 'unnamed parameters still autowire');
    }

    public function testInstanceRegistersAnExistingObject(): void {
        $greeter = new Greeter();
        $this->container->instance(GreeterInterface::class, $greeter);

        self::assertSame($greeter, $this->container->get(GreeterInterface::class));
        self::assertSame($greeter, $this->container->get(NeedsGreeter::class)->greeter);
    }

    #[TestDox('rebinding replaces what was already built')]
    public function testRebindingDiscardsTheBuiltInstance(): void {
        $this->container->bind(GreeterInterface::class, static fn (): GreeterInterface => new Greeter());
        $first = $this->container->get(GreeterInterface::class);

        $replacement = new Greeter();
        $this->container->bind(GreeterInterface::class, static fn (): GreeterInterface => $replacement);

        self::assertNotSame($first, $this->container->get(GreeterInterface::class));
        self::assertSame($replacement, $this->container->get(GreeterInterface::class));
    }

    // -----------------------------------------------------------------
    // has()
    // -----------------------------------------------------------------

    public function testHasSeesBindingsInstancesAndBuildableClasses(): void {
        self::assertTrue($this->container->has(NoConstructor::class), 'a concrete class is buildable');
        self::assertFalse($this->container->has(GreeterInterface::class), 'an interface is not, until bound');
        self::assertFalse($this->container->has(NotInstantiable::class), 'an abstract class is not');
        self::assertFalse($this->container->has('No\\Such\\Class'));

        $this->container->bind(GreeterInterface::class, static fn (): GreeterInterface => new Greeter());
        self::assertTrue($this->container->has(GreeterInterface::class));
    }

    // -----------------------------------------------------------------
    // Failure modes
    // -----------------------------------------------------------------

    #[TestDox('an unregistered interface is a PSR-11 NotFound')]
    public function testUnresolvableIdentifierThrowsNotFound(): void {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('cannot be autowired');

        $this->container->get(GreeterInterface::class);
    }

    public function testNotFoundImplementsThePsrInterface(): void {
        self::assertInstanceOf(NotFoundExceptionInterface::class, new NotFoundException('x'));
    }

    #[TestDox('a required scalar with no default names the parameter')]
    public function testUnresolvableScalarThrows(): void {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot resolve $dsn of ' . NeedsScalar::class);

        $this->container->get(NeedsScalar::class);
    }

    public function testUnionTypedParameterThrows(): void {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('union or an intersection');

        $this->container->get(UnionTyped::class);
    }

    #[TestDox('a cycle is reported as the chain, not a stack overflow')]
    public function testCircularDependencyIsDetected(): void {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency');

        $this->container->get(LoopA::class);
    }

    #[TestDox('a throwing constructor is wrapped, keeping the original')]
    public function testConstructorFailureIsWrapped(): void {
        try {
            $this->container->get(Throws::class);
            self::fail('expected a ContainerException');
        } catch (ContainerException $e) {
            self::assertStringContainsString('constructor said no', $e->getMessage());
            self::assertInstanceOf(\DomainException::class, $e->getPrevious());
        }
    }

    #[TestDox('a failing factory is wrapped, keeping the original')]
    public function testFactoryFailureIsWrapped(): void {
        $this->container->bind('broken', static fn (): mixed => throw new \DomainException('factory said no'));

        try {
            $this->container->get('broken');
            self::fail('expected a ContainerException');
        } catch (ContainerException $e) {
            self::assertStringContainsString('factory said no', $e->getMessage());
            self::assertInstanceOf(\DomainException::class, $e->getPrevious());
        }
    }

    public function testMakeRefusesSomethingItCannotBuild(): void {
        $this->expectException(NotFoundException::class);

        $this->container->make(NotInstantiable::class);
    }
}
