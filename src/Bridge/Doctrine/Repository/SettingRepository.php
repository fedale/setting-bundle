<?php

namespace Fedale\SettingBundle\Bridge\Doctrine\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Fedale\SettingBundle\Bridge\Doctrine\Entity\Setting;
use Fedale\SettingBundle\Contract\SettingStorageInterface;

/**
 * Storage Doctrine dei settings. Implementa SettingStorageInterface, cosi'
 * il SettingsManager resta agnostico rispetto alla persistenza.
 *
 * @extends ServiceEntityRepository<Setting>
 */
final class SettingRepository extends ServiceEntityRepository implements SettingStorageInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Setting::class);
    }

    /**
     * @return array<string, Setting> entity del tenant indicizzate per nome
     */
    public function findAllIndexedByName(int $tenantId): array
    {
        $result = [];

        foreach ($this->findBy(['tenantId' => $tenantId]) as $setting) {
            $result[$setting->getName()] = $setting;
        }

        return $result;
    }

    public function findOneByTenantAndName(int $tenantId, string $name): ?Setting
    {
        return $this->findOneBy(['tenantId' => $tenantId, 'name' => $name]);
    }

    public function loadTenant(int $tenantId): array
    {
        $result = [];

        $settings = $this->findBy([
            'tenantId' => $tenantId,
            'active' => true,
        ]);

        foreach ($settings as $setting) {
            $result[$setting->getName()] = $setting->getCastedValue();
        }

        return $result;
    }

    public function upsert(
        int $tenantId,
        string $name,
        mixed $value,
        string $type,
        bool $active,
    ): void {
        $setting = $this->findOneByTenantAndName($tenantId, $name)
            ?? (new Setting())
                ->setTenantId($tenantId)
                ->setName($name);

        $setting
            ->setCastedValue($value, $type)
            ->setActive($active);

        $em = $this->getEntityManager();
        $em->persist($setting);
        $em->flush();
    }
}
