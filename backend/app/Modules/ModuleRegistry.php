<?php

namespace App\Modules;

use InvalidArgumentException;

class ModuleRegistry
{
    /** @var ModuleDescriptor[] kulcs szerint indexelve */
    private array $descriptors = [];

    public function __construct()
    {
        $classes = config('modules', []);

        foreach ($classes as $class) {
            /** @var ModuleDescriptor $descriptor */
            $descriptor = new $class();
            $this->descriptors[$descriptor->key()] = $descriptor;
        }
    }

    /** @return ModuleDescriptor[] */
    public function all(): array
    {
        return array_values($this->descriptors);
    }

    public function find(string $key): ?ModuleDescriptor
    {
        return $this->descriptors[$key] ?? null;
    }

    /** @return string[] */
    public function coreKeys(): array
    {
        return array_keys(array_filter(
            $this->descriptors,
            fn (ModuleDescriptor $d) => $d->isCore()
        ));
    }
}
