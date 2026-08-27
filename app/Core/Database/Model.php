<?php
namespace App\Core\Database;

use ArrayAccess;
use JsonSerializable;
use ReflectionClass;
use ReflectionProperty;

/**
 * Základ datového objektu.
 *
 * Data modelu = jeho veřejné vlastnosti + volný "bag" ostatních atributů.
 * Chráněný a privátní stav potomků je interní: nepatří do toArray() ani do JSONu
 * a nejde ho přepsat přes pole ani magické metody.
 */
abstract class Model implements ArrayAccess, JsonSerializable {
    protected array $attributes = [];

    /**
     * Cache veřejných vlastností podle třídy: třída => [název => ReflectionProperty].
     *
     * @var array<class-string, array<string, ReflectionProperty>>
     */
    private static array $propertyCache = [];

    public function __construct(array $attributes = []) {
        $this->fill($attributes);
    }

    /**
     * Naplní model z pole.
     */
    public function fill(array $attributes): static {
        foreach ($attributes as $key => $value) {
            $this->offsetSet((string) $key, $value);
        }

        return $this;
    }

    /**
     * Veřejné (nestatické) vlastnosti aktuální třídy.
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

    /**
     * Je klíč deklarovanou veřejnou vlastností?
     */
    private function isDeclared(string $key): bool {
        return isset(self::declaredProperties()[$key]);
    }

    /**
     * Přizpůsobí skalární hodnotu (typicky řetězec z databáze) deklarovanému typu vlastnosti,
     * aby hydratace neshodila aplikaci na TypeError.
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
     * Data modelu jako pole: veřejné vlastnosti + volné atributy.
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
     * Vrátí jen volné atributy, tedy hodnoty bez odpovídající vlastnosti.
     */
    public function getAttributes(): array {
        return $this->attributes;
    }

    // ArrayAccess implementation
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

            // Pozor: u typované vlastnosti po unset() by čtení znovu spustilo __get.
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
            // unset() místo přiřazení null – null by u nenullable typu skončilo TypeError.
            unset($this->$offset);
            return;
        }

        unset($this->attributes[$offset]);
    }

    // Magic property access – schválně jen delegace, aby platila jedna sada pravidel.
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

    // JsonSerializable implementation
    public function jsonSerialize(): mixed {
        return $this->toArray();
    }
}
