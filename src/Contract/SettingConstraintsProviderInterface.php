<?php

namespace Fedale\SettingBundle\Contract;

use Symfony\Component\Validator\Constraint;

/**
 * Supplies the validation constraints declared for a given setting key. The
 * consumer implements this to opt specific keys into validation; returning an
 * empty array means "no rules", i.e. the key stays free-form.
 */
interface SettingConstraintsProviderInterface
{
    /**
     * @return list<Constraint> constraints for $key, or [] when none are declared
     */
    public function constraintsFor(string $key): array;
}
