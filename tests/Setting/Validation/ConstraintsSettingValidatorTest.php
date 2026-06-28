<?php

namespace Fedale\SettingBundle\Tests\Setting\Validation;

use Fedale\SettingBundle\Exception\SettingValidationException;
use Fedale\SettingBundle\Setting\Validation\ArrayConstraintsProvider;
use Fedale\SettingBundle\Setting\Validation\ConstraintsSettingValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;

#[CoversClass(ConstraintsSettingValidator::class)]
#[CoversClass(ArrayConstraintsProvider::class)]
#[CoversClass(SettingValidationException::class)]
final class ConstraintsSettingValidatorTest extends TestCase
{
    private function validator(array $map): ConstraintsSettingValidator
    {
        return new ConstraintsSettingValidator(
            Validation::createValidator(),
            new ArrayConstraintsProvider($map),
        );
    }

    public function testValueWithinDeclaredRulePasses(): void
    {
        $validator = $this->validator([
            'myKey' => [new Assert\Length(min: 3, max: 10)],
        ]);

        $validator->validate('myKey', 'abcd', 'string');

        $this->expectNotToPerformAssertions();
    }

    public function testValueViolatingDeclaredRuleThrows(): void
    {
        $validator = $this->validator([
            'myKey' => [new Assert\Length(min: 3, max: 10)],
        ]);

        try {
            $validator->validate('myKey', 'ab', 'string');
            self::fail('Expected SettingValidationException was not thrown');
        } catch (SettingValidationException $e) {
            self::assertSame('myKey', $e->getKey());
            self::assertNotEmpty($e->getViolationMessages());
        }
    }

    public function testKeyWithoutRulesIsFreeForm(): void
    {
        $validator = $this->validator([
            'myKey' => [new Assert\Length(min: 3, max: 10)],
        ]);

        // No constraints declared for 'other' => anything is accepted.
        $validator->validate('other', 'x', 'string');

        $this->expectNotToPerformAssertions();
    }
}
