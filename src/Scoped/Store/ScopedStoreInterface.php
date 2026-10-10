<?php

namespace Fedale\SettingBundle\Scoped\Store;

use Fedale\SettingBundle\Scoped\Scope;
use Fedale\SettingBundle\Scoped\ScopeChain;

/**
 * Persistence of scoped setting values: one bag of key => stored string per
 * (tenant, namespace, scope). A key missing from a bag means "inherit".
 *
 * Values are already in stored form (see SettingTypeCaster): the store does not
 * know the setting types, the definitions do.
 */
interface ScopedStoreInterface
{
    /**
     * The bags of every (tenant, scope) pair of the lookup, in one round trip.
     * Pairs without values may be left out of the result.
     *
     * @param list<int> $tenantIds
     *
     * @return array<int, array<string, array<string, string>>> tenantId => (string) scope => key => stored value
     */
    public function load(string $namespace, ScopeChain $chain, array $tenantIds): array;

    /**
     * Sets or clears single keys of one bag; a null value clears the key (back to
     * "inherit"). Keys not passed are left untouched.
     *
     * @param array<string, string|null> $values key => stored value
     */
    public function save(string $namespace, Scope $scope, int $tenantId, array $values): void;
}
