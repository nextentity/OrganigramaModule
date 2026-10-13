<?php

declare(strict_types=1);

namespace App\Infrastructure\Container;

use Closure;
use Throwable;

/**
 * Contenedor de Inyección de Dependencias compatible con PSR-11.
 * Soporta registro de instancias fijas y factories perezosas (lazy singletons).
 */
final class Container
{
    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, Closure(self): mixed> */
    private array $factories = [];

    public function set(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
    }

    /**
     * @param Closure(self): mixed $factory
     */
    public function singleton(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (isset($this->factories[$id])) {
            try {
                $instance = ($this->factories[$id])($this);
                $this->instances[$id] = $instance;
                return $instance;
            } catch (Throwable $e) {
                throw new ContainerException("Error al resolver '{$id}': " . $e->getMessage(), 0, $e);
            }
        }

        throw new NotFoundException($id);
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances) || isset($this->factories[$id]);
    }
}

