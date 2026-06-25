<?php

namespace Fedale\SettingBundle\Bridge\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Fedale\SettingBundle\Bridge\Doctrine\Repository\SettingRepository;
use Fedale\SettingBundle\Setting\ValueCaster;

#[ORM\Entity(repositoryClass: SettingRepository::class)]
#[ORM\Table(name: 'setting')]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_setting_tenant_name', columns: ['tenant_id', 'name'])]
// Indice allineato a SettingRepository::loadTenant() (WHERE tenant_id = ? AND active = true).
#[ORM\Index(columns: ['tenant_id', 'active'], name: 'idx_setting_tenant_active')]
class Setting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // 0 = configurazioni globali di default; > 0 = override del tenant.
    #[ORM\Column]
    private int $tenantId = 0;

    #[ORM\Column(length: 255)]
    private string $name = '';

    // Forma serializzata: scalare leggibile oppure JSON se complesso.
    // Il tipo nativo si ricostruisce tramite $type (vedi ValueCaster).
    #[ORM\Column(type: 'text')]
    private string $value = '';

    #[ORM\Column(length: 20, options: ['default' => ValueCaster::TYPE_STRING])]
    private string $type = ValueCaster::TYPE_STRING;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

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

    /**
     * Valore deserializzato al tipo nativo, secondo $type.
     */
    public function getCastedValue(): mixed
    {
        return ValueCaster::deserialize($this->value, $this->type);
    }

    /**
     * Imposta value/type a partire da un valore nativo, serializzandolo.
     */
    public function setCastedValue(mixed $value, string $type): static
    {
        $this->type = $type;
        $this->value = ValueCaster::serialize($value, $type);

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenantId(): int
    {
        return $this->tenantId;
    }

    public function setTenantId(int $tenantId): static
    {
        $this->tenantId = $tenantId;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

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
