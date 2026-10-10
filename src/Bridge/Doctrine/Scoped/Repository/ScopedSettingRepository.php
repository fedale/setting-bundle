<?php

namespace Fedale\SettingBundle\Bridge\Doctrine\Scoped\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Fedale\SettingBundle\Bridge\Doctrine\Scoped\Entity\ScopedSetting;
use Fedale\SettingBundle\Scoped\Scope;
use Fedale\SettingBundle\Scoped\ScopeChain;
use Fedale\SettingBundle\Scoped\Store\ScopedStoreInterface;

/**
 * Doctrine store of scoped settings, on the dedicated `setting_scoped` table.
 * A lookup reads only the scopes of the chain, so high-cardinality levels
 * (occurrences, users, ...) cost one indexed query, not a full tenant load.
 *
 * @extends ServiceEntityRepository<ScopedSetting>
 */
final class ScopedSettingRepository extends ServiceEntityRepository implements ScopedStoreInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScopedSetting::class);
    }

    public function load(string $namespace, ScopeChain $chain, array $tenantIds): array
    {
        if ([] === $tenantIds) {
            return [];
        }

        $qb = $this->createQueryBuilder('s')
            ->select('s.tenantId, s.level, s.scopeId, s.settingKey, s.value')
            ->where('s.namespace = :namespace')
            ->andWhere('s.tenantId IN (:tenants)')
            ->setParameter('namespace', $namespace)
            ->setParameter('tenants', $tenantIds);

        $scopes = $qb->expr()->orX();
        foreach ($chain->scopes as $i => $scope) {
            $scopes->add("s.level = :level$i AND s.scopeId = :scopeId$i");
            $qb->setParameter("level$i", $scope->level)
                ->setParameter("scopeId$i", $scope->id ?? '');
        }
        $qb->andWhere($scopes);

        $result = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $scope = new Scope($row['level'], '' === $row['scopeId'] ? null : $row['scopeId']);
            $result[$row['tenantId']][(string) $scope][$row['settingKey']] = $row['value'];
        }

        return $result;
    }

    public function save(string $namespace, Scope $scope, int $tenantId, array $values): void
    {
        if ([] === $values) {
            return;
        }

        $em = $this->getEntityManager();

        $em->wrapInTransaction(function () use ($em, $namespace, $scope, $tenantId, $values): void {
            /** @var array<string, ScopedSetting> $existing */
            $existing = [];
            foreach ($this->findBy([
                'tenantId' => $tenantId,
                'namespace' => $namespace,
                'level' => $scope->level,
                'scopeId' => $scope->id ?? '',
                'settingKey' => array_map('strval', array_keys($values)),
            ]) as $setting) {
                $existing[$setting->getSettingKey()] = $setting;
            }

            foreach ($values as $key => $value) {
                $setting = $existing[$key] ?? null;

                if (null === $value) {
                    if (null !== $setting) {
                        $em->remove($setting);
                    }
                    continue;
                }

                $setting ??= new ScopedSetting($tenantId, $namespace, $scope->level, $scope->id ?? '', (string) $key);
                $em->persist($setting->setValue($value));
            }

            $em->flush();
        });
    }
}
