<?php

namespace Fedale\SettingBundle\Scoped;

/**
 * A resolved setting value and where it comes from, so a UI can show
 * "inherited from global (platform)".
 */
final readonly class ResolvedValue
{
    public function __construct(
        /** Native value, already of the definition's type. */
        public mixed $value,
        public Scope $scope,
        public int $tenantId,
    ) {
    }
}
