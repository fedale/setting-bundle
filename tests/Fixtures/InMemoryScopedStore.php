<?php

namespace Fedale\SettingBundle\Tests\Fixtures;

use Fedale\SettingBundle\Scoped\Scope;
use Fedale\SettingBundle\Scoped\ScopeChain;
use Fedale\SettingBundle\Scoped\Store\ScopedStoreInterface;

/**
 * In-memory scoped store for tests. Records every load() call to check the
 * effect of the caches.
 */
final class InMemoryScopedStore implements ScopedStoreInterface
{
    /** @var list<array{namespace: string, scopes: list<string>, tenants: list<int>}> */
    public array $loads = [];

    /**
     * @param array<string, array<int, array<string, array<string, string>>>> $data namespace => tenant => scope => key => stored value
     */
    public function __construct(
        public array $data = [],
    ) {
    }

    public function load(string $namespace, ScopeChain $chain, array $tenantIds): array
    {
        $scopes = array_map('strval', $chain->scopes);
        $this->loads[] = ['namespace' => $namespace, 'scopes' => $scopes, 'tenants' => $tenantIds];

        $result = [];
        foreach ($tenantIds as $tenantId) {
            foreach ($scopes as $scope) {
                if ([] !== $bag = $this->data[$namespace][$tenantId][$scope] ?? []) {
                    $result[$tenantId][$scope] = $bag;
                }
            }
        }

        return $result;
    }

    public function save(string $namespace, Scope $scope, int $tenantId, array $values): void
    {
        foreach ($values as $key => $value) {
            if (null === $value) {
                unset($this->data[$namespace][$tenantId][(string) $scope][$key]);
            } else {
                $this->data[$namespace][$tenantId][(string) $scope][$key] = $value;
            }
        }
    }
}
