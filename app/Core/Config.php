<?php
namespace App\Core;

class Config {
    private static ?array $data = null;

    public static function path(): string {
        return dirname(__DIR__) . '/Config/main.php';
    }

    public static function all(): array {
        if (self::$data === null) {
            $path = self::path();
            $data = is_file($path) ? require $path : [];
            self::$data = is_array($data) ? $data : [];
        }
        return self::$data;
    }

    public static function get(string $key, mixed $default = null): mixed {
        $value = self::all();

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function has(string $key): bool {
        return self::get($key, $sentinel = new \stdClass()) !== $sentinel;
    }

    public static function set(?array $data): void {
        self::$data = $data;
    }
}
