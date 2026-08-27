<?php
namespace App\Core\Database;

use ArrayAccess;
use JsonSerializable;
use ReflectionClass;
use ReflectionProperty;

/**
 * Base data object.
 *
 * The data of a model = its public properties + a loose "bag" of other
 * attributes. Protected and private state of subclasses is internal: it belongs
 * neither in toArray() nor in the JSON, and cannot be overwritten through array
 * access or the magic methods.
 */
abstract class Model implements ArrayAccess, JsonSerializable {
    protected array $attributes = [];

    /**
     * Public properties cached per class: class => [name => ReflectionProperty].
     *
     * @var array<class-string, array<string, ReflectionProperty>>
     */
    private static array $propertyCache = [];

    public function __construct(array $attributes = []) {
        $this->fill($attributes);
    }

    public function fill(array $attributes): static {
        foreach ($attributes as $key => $value) {
            $this->offsetSet((string) $key, $value);
        }

        return $this;
    }

    /**
     * Public (non-static) properties of the current class.
     *
     * @return array<string, ReflectionProperty>
     */
    private static function declaredProperties(): array {
        $class = static::class;

        if (!isset(self::$propertyCache[$class])) {
            $properties = [];
            foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if (!$property->isStatic()) {
                    $properties[$property->getName()] = $property;
                }
            }
            self::$propertyCache[$class] = $properties;
        }

        return self::$propertyCache[$class];
    }

    private function isDeclared(string $key): bool {
        return isset(self::declaredProperties()[$key]);
    }

    /**
     * Adapts a scalar value (typically a string from the database) to the
     * declared property type, so that hydration does not bring the application
     * down with a TypeError.
     */
    private function coerce(string $key, mixed $value): mixed {
        $type = self::declaredProperties()[$key]->getType();

        if (!$type instanceof \ReflectionNamedType || !$type->isBuiltin() || !is_scalar($value)) {
            return $value;
        }

        return match ($type->getName()) {
            'int'    => is_numeric($value) ? (int) $value : $value,
            'float'  => is_numeric($value) ? (float) $value : $value,
            'bool'   => is_bool($value) ? $value : (filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $value),
            'string' => (string) $value,
            default  => $value,
        };
    }

    /**
     * The model data as an array: public properties + loose attributes.
     */
    public function toArray(): array {
        $data = [];

        foreach (self::declaredProperties() as $name => $property) {
            if ($property->isInitialized($this)) {
                $data[$name] = $property->getValue($this);
            }
        }

        return array_merge($data, $this->attributes);
    }

    /**
     * Returns only the loose attributes, i.e. values with no matching property.
     */
    public function getAttributes(): array {
        return $this->attributes;
    }

    public function offsetExists(mixed $offset): bool {
        $offset = (string) $offset;

        if ($this->isDeclared($offset)) {
            return self::declaredProperties()[$offset]->isInitialized($this);
        }

        return isset($this->attributes[$offset]);
    }

    public function offsetGet(mixed $offset): mixed {
        $offset = (string) $offset;

        if ($this->isDeclared($offset)) {
            $property = self::declaredProperties()[$offset];

            // Careful: on a typed property after unset(), reading would re-enter __get.
            return $property->isInitialized($this) ? $property->getValue($this) : null;
        }

        return $this->attributes[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void {
        if ($offset === null) {
            return;
        }

        $offset = (string) $offset;

        if ($this->isDeclared($offset)) {
            $this->$offset = $this->coerce($offset, $value);
            return;
        }

        $this->attributes[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void {
        $offset = (string) $offset;

        if ($this->isDeclared($offset)) {
            // unset() rather than assigning null - null on a non-nullable type
            // would end in a TypeError.
            unset($this->$offset);
            return;
        }

        unset($this->attributes[$offset]);
    }

    // Magic property access - deliberately pure delegation, so one set of rules applies.
    public function __get(string $key): mixed {
        return $this->offsetGet($key);
    }

    public function __set(string $key, mixed $value): void {
        $this->offsetSet($key, $value);
    }

    public function __isset(string $key): bool {
        return $this->offsetExists($key);
    }

    public function __unset(string $key): void {
        $this->offsetUnset($key);
    }

    public function jsonSerialize(): mixed {
        return $this->toArray();
    }
}
