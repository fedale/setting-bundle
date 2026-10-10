<?php

namespace Fedale\SettingBundle\Bridge\Doctrine\Scoped\Entity;

use Doctrine\ORM\Mapping as ORM;
use Fedale\SettingBundle\Bridge\Doctrine\Scoped\Repository\ScopedSettingRepository;

/**
 * One stored scoped setting value: (tenant, namespace, scope, key) => value.
 * The value is kept in stored form; its type comes from the setting definition.
 */
#[ORM\Entity(repositoryClass: ScopedSettingRepository::class)]
#[ORM\Table(name: 'setting_scoped')]
#[ORM\HasLifecycleCallbacks]
// Column names are explicit, so the constraint holds whatever naming strategy
// the app uses. It also serves the lookup:
// WHERE tenant_id IN (...) AND namespace = ? AND (level, scope_id) IN (...).
#[ORM\UniqueConstraint(name: 'uniq_setting_scoped', columns: ['tenant_id', 'namespace', 'level', 'scope_id', 'setting_key'])]
class ScopedSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // 0 = platform defaults; > 0 = the tenant's own values.
    #[ORM\Column(name: 'tenant_id')]
    private int $tenantId = 0;

    #[ORM\Column(length: 100)]
    private string $namespace = '';

    #[ORM\Column(length: 50)]
    private string $level = '';

    // '' for scopes without an id, never NULL: a NULL would let the unique
    // constraint accept duplicates of the global scope.
    #[ORM\Column(name: 'scope_id', length: 191, options: ['default' => ''])]
    private string $scopeId = '';

    #[ORM\Column(name: 'setting_key', length: 100)]
    private string $settingKey = '';

    #[ORM\Column(type: 'text')]
    private string $value = '';

    #[ORM\Column(name: 'created_at')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at')]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(int $tenantId, string $namespace, string $level, string $scopeId, string $settingKey)
    {
        $this->tenantId = $tenantId;
        $this->namespace = $namespace;
        $this->level = $level;
        $this->scopeId = $scopeId;
        $this->settingKey = $settingKey;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();

        $this->createdAt ??= $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenantId(): int
    {
        return $this->tenantId;
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function getScopeId(): string
    {
        return $this->scopeId;
    }

    public function getSettingKey(): string
    {
        return $this->settingKey;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
