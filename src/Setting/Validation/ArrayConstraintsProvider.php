<?php

namespace Fedale\SettingBundle\Setting\Validation;

use Fedale\SettingBundle\Contract\SettingConstraintsProviderInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Ready-made constraints provider backed by a static map of key => constraints.
 * Convenient when the rules are known at build time, e.g.:
 *
 *     new ArrayConstraintsProvider([
 *         'myKey'         => [new Assert\Length(min: 3, max: 10)],
 *         'general.title' => [new Assert\NotBlank()],
 *     ]);
 */
final class ArrayConstraintsProvider implements SettingConstraintsProviderInterface
{
    /** @var array<string, list<Constraint>> */
    private array $map;

    /**
     * @param array<string, list<Constraint>> $map
     */
    public function __construct(array $map = [])
    {
        $this->map = $map;
    }

    public function constraintsFor(string $key): array
    {
        return $this->map[$key] ?? [];
    }
}
