<?php

namespace Fedale\SettingBundle\Scoped;

/**
 * The scopes a value is looked up in, most specific first, e.g.
 * [grid:users, global]. The consumer builds it; the resolver walks it once per
 * tenant (current tenant first, then tenant 0).
 */
final readonly class ScopeChain
{
    /**
     * @param list<Scope> $scopes most specific first
     */
    public function __construct(
        public array $scopes,
    ) {
        if ([] === $scopes || !array_is_list($scopes)) {
            throw new \InvalidArgumentException('A scope chain needs a non-empty list of scopes.');
        }

        $seen = [];
        foreach ($scopes as $scope) {
            if (!$scope instanceof Scope) {
                throw new \InvalidArgumentException('A scope chain only holds Scope instances.');
            }
            if (isset($seen[(string) $scope])) {
                throw new \InvalidArgumentException(sprintf('Scope "%s" appears twice in the chain.', $scope));
            }
            $seen[(string) $scope] = true;
        }
    }

    public static function of(Scope ...$scopes): self
    {
        return new self(array_values($scopes));
    }
}
