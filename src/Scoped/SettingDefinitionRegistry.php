<?php

namespace Fedale\SettingBundle\Scoped;

/**
 * Every declared scoped setting, indexed by namespace and key. Fed with the
 * services tagged `fedale_setting.definition`.
 */
final class SettingDefinitionRegistry
{
    private const NAME_PATTERN = '/^[a-z0-9_.]{1,100}$/';

    /** @var array<string, array<string, SettingDefinitionInterface>> namespace => key => definition */
    private array $definitions = [];

    /**
     * @param iterable<SettingDefinitionInterface> $definitions
     */
    public function __construct(iterable $definitions = [])
    {
        foreach ($definitions as $definition) {
            $this->add($definition);
        }
    }

    public function has(string $namespace, string $key): bool
    {
        return isset($this->definitions[$namespace][$key]);
    }

    public function get(string $namespace, string $key): ?SettingDefinitionInterface
    {
        return $this->definitions[$namespace][$key] ?? null;
    }

    /**
     * @return array<string, SettingDefinitionInterface> key => definition
     */
    public function all(string $namespace): array
    {
        return $this->definitions[$namespace] ?? [];
    }

    /**
     * The settings that can be set at $scope, e.g. to build its form.
     *
     * @return array<string, SettingDefinitionInterface> key => definition
     */
    public function forScope(string $namespace, Scope $scope): array
    {
        return array_filter(
            $this->all($namespace),
            static fn (SettingDefinitionInterface $d): bool => $d->supportsScope($scope),
        );
    }

    private function add(SettingDefinitionInterface $definition): void
    {
        $namespace = $definition->namespace();
        $key = $definition->key();

        foreach (['namespace' => $namespace, 'key' => $key] as $what => $name) {
            if (1 !== preg_match(self::NAME_PATTERN, $name)) {
                throw new \LogicException(sprintf(
                    'Invalid %s "%s" in %s: use 1-100 characters among a-z, 0-9, "_" and ".".',
                    $what,
                    $name,
                    $definition::class,
                ));
            }
        }

        if (isset($this->definitions[$namespace][$key])) {
            throw new \LogicException(sprintf(
                'Setting "%s" is declared twice in namespace "%s" (%s and %s).',
                $key,
                $namespace,
                $this->definitions[$namespace][$key]::class,
                $definition::class,
            ));
        }

        $this->definitions[$namespace][$key] = $definition;
    }
}
