<?php

namespace Fedale\SettingBundle\Tests\Fixtures;

use Fedale\SettingBundle\Contract\TenantProviderInterface;

final class FixedTenantProvider implements TenantProviderInterface
{
    public function __construct(
        private int $tenantId = 0,
    ) {
    }

    public function setTenantId(int $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function getCurrentTenantId(): int
    {
        return $this->tenantId;
    }
}
