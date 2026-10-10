<?php

namespace Fedale\SettingBundle\Scoped\Store;

use Fedale\SettingBundle\Scoped\Scope;
use Fedale\SettingBundle\Scoped\ScopeChain;

/**
 * Default store: holds nothing and refuses writes, so scoped settings stay off
 * until `fedale_setting.scoped.store` is configured.
 */
final class NullScopedStore implements ScopedStoreInterface
{
    public function load(string $namespace, ScopeChain $chain, array $tenantIds): array
    {
        return [];
    }

    public function save(string $namespace, Scope $scope, int $tenantId, array $values): void
    {
        throw new \LogicException('Scoped settings are disabled: configure fedale_setting.scoped.store to save them.');
    }
}
