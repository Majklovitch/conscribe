<?php

namespace App\Core\Container;

use Closure;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use Throwable;

/**
 * A small autowiring container.
 *
 * Written rather than installed, for the same reason the rest of the core is:
 * the wiring surface here is a handful of services, and a general-purpose
 * container would bring far more machinery - and, for any real speed, a
 * compile step - than that is worth. The reflection it does is the same
 * mechanism Router::buildArguments() already uses for action parameters.
 *
 * It implements PSR-11 so that decision stays reversible: anything holding a
 * ContainerInterface does not care that this is the implementation, and a
 * bigger container can replace it without touching a single call site.
 *
 *   $container->bind(LoggerInterface::class, fn () => Log::get());
 *   $container->get(OrderService::class);   // autowired, then remembered
 *
 * Entries are shared by default: get() builds an entry once and returns the
 * same instance afterwards. make() is the escape hatch for things that must be
 * fresh every time.
 */
class Container implements ContainerInterface {
    /**
     * Factories, by identifier.
     *
     * @var array<string, Closure(self): mixed>
     */
    private array $bindings = [];

    /**
     * Entries already built (or handed in), by identifier.
     *
     * @var array<string, mixed>
     */
    private array $instances = [];

    /**
     * Identifiers whose factory said "a new one every time".
     *
     * @var array<string, true>
     */
    private array $fresh = [];

    /**
     * Identifiers currently being built, in order.
     *
     * Kept so a cycle can be reported as the chain that caused it rather than
     * as a stack overflow, which says nothing about which two classes are at
     * fault.
     *
     * @var array<int, string>
     */
    private array $building = [];

    /**
     * Registers a factory for an identifier.
     *
     * The factory receives the container, so it can resolve its own
     * dependencies. With $shared = false the factory runs on every get().
     *
     * @param Closure(self): mixed $factory
     */
    public function bind(string $id, Closure $factory, bool $shared = true): void {
        $this->bindings[$id] = $factory;

        // A rebind replaces whatever was already built under this id; leaving
        // the old instance in place would mean bind() silently did nothing.
        unset($this->instances[$id]);

        if ($shared) {
            unset($this->fresh[$id]);
        } else {
            $this->fresh[$id] = true;
        }
    }

    /**
     * Registers an object that already exists.
     *
     * This is how the request-scoped objects get in - Request and Session are
     * built by the bootstrap before the container has any use for them.
     */
    public function instance(string $id, object $object): void {
        $this->instances[$id] = $object;
        unset($this->fresh[$id]);
    }

    /**
     * Aliases an identifier to another one, so an interface can resolve to a
     * concrete class without a factory that only says `get()`.
     */
    public function alias(string $id, string $target): void {
        $this->bind($id, static fn (self $container): mixed => $container->get($target));
    }

    public function has(string $id): bool {
        return isset($this->instances[$id])
            || isset($this->bindings[$id])
            || $this->isInstantiable($id);
    }

    /**
     * The entry for the identifier, built on first use and remembered.
     *
     * @throws NotFoundException  nothing is registered and it is not a buildable class
     * @throws ContainerException the entry exists but could not be built
     */
    public function get(string $id): mixed {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (in_array($id, $this->building, true)) {
            throw new ContainerException(sprintf(
                'Circular dependency while building %s: %s.',
                $id,
                implode(' -> ', [...$this->building, $id]),
            ));
        }

        $this->building[] = $id;

        try {
            $value = $this->build($id);
        } finally {
            array_pop($this->building);
        }

        if (!isset($this->fresh[$id])) {
            $this->instances[$id] = $value;
        }

        return $value;
    }

    /**
     * Builds the class fresh, ignoring anything already remembered.
     *
     * $arguments are matched by parameter name and win over autowiring, which
     * is what makes a controller action or a command able to pass a value the
     * container has no way to know.
     *
     * @param array<string, mixed> $arguments
     * @throws ContainerException
     */
    public function make(string $class, array $arguments = []): object {
        if (!$this->isInstantiable($class)) {
            throw new NotFoundException(sprintf('%s is not a class the container can build.', $class));
        }

        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException $e) {
            throw new ContainerException($e->getMessage(), 0, $e);
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $args = [];
        foreach ($constructor->getParameters() as $parameter) {
            $args[] = array_key_exists($parameter->getName(), $arguments)
                ? $arguments[$parameter->getName()]
                : $this->resolveParameter($parameter, $class);
        }

        try {
            return $reflection->newInstanceArgs($args);
        } catch (ContainerException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ContainerException(sprintf('Could not construct %s: %s', $class, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @throws NotFoundException
     * @throws ContainerException
     */
    private function build(string $id): mixed {
        if (isset($this->bindings[$id])) {
            try {
                return ($this->bindings[$id])($this);
            } catch (ContainerException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new ContainerException(sprintf('Factory for %s failed: %s', $id, $e->getMessage()), 0, $e);
            }
        }

        if (!$this->isInstantiable($id)) {
            throw new NotFoundException(sprintf(
                '%s is not registered in the container and cannot be autowired.',
                $id,
            ));
        }

        return $this->make($id);
    }

    /**
     * One constructor parameter, from the container or from its own default.
     *
     * @throws ContainerException
     */
    private function resolveParameter(ReflectionParameter $parameter, string $class): mixed {
        $type = $parameter->getType();

        // A union or intersection type does not name one thing to build, and
        // guessing which branch was meant would be worse than saying so.
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }

            throw new ContainerException($this->cannotResolve($parameter, $class, 'its type is a union or an intersection'));
        }

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $name = $type->getName();

            if ($this->has($name)) {
                // An optional dependency is not worth *creating* a bound
                // factory for. Repository's constructor says it plainly - the
                // connection is only injected in tests, otherwise it is taken
                // on the first query - and autowiring a registered PDO into it
                // would open a connection on every page that builds a
                // repository, whether or not anything asks it a question.
                //
                // An entry that already exists is still injected: this defers
                // work, it does not refuse to share.
                if ($parameter->isDefaultValueAvailable()
                    && isset($this->bindings[$name])
                    && !array_key_exists($name, $this->instances)
                ) {
                    return $parameter->getDefaultValue();
                }

                return $this->get($name);
            }

            // An optional dependency the container knows nothing about is the
            // parameter's own business, not an error.
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }

            if ($type->allowsNull()) {
                return null;
            }

            throw new ContainerException($this->cannotResolve($parameter, $class, $name . ' is not registered'));
        }

        // Scalars and untyped parameters: only a default can answer for them.
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($type !== null && $type->allowsNull()) {
            return null;
        }

        throw new ContainerException($this->cannotResolve($parameter, $class, 'it has no type the container can resolve and no default'));
    }

    private function cannotResolve(ReflectionParameter $parameter, string $class, string $why): string {
        return sprintf(
            'Cannot resolve $%s of %s::__construct(): %s.',
            $parameter->getName(),
            $class,
            $why,
        );
    }

    private function isInstantiable(string $id): bool {
        if (!class_exists($id)) {
            return false;
        }

        try {
            return (new ReflectionClass($id))->isInstantiable();
        } catch (ReflectionException) {
            return false;
        }
    }
}
