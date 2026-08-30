<?php

namespace Spawn\Laravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Spawn\Laravel\PHPStan\UnscopedGuardSwitchRule;

/**
 * @extends RuleTestCase<UnscopedGuardSwitchRule>
 */
class UnscopedGuardSwitchRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new UnscopedGuardSwitchRule();
    }

    public function test_it_reports_the_unscoped_pair_and_leaves_the_scoped_form_alone(): void
    {
        $message = 'Model::%s() switches mass assignment for the whole worker and has nothing to close it; '
            .'inside a request use Model::unguarded(callable), which is held per coroutine.';

        $this->analyse([__DIR__ . '/Fixtures/GuardSwitches.php'], [
            [sprintf($message, 'unguard'), 11],
            [sprintf($message, 'reguard'), 12],
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [];
    }
}
