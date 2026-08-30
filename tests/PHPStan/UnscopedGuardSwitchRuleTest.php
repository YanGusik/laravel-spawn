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
    /**
     * Analysing a fixture that extends Model pulls in the whole Eloquent hierarchy, which
     * costs more than the 128 MB a stock PHP allows — the CI image runs with that default and
     * died inside PHPStan's own cache writer. The other rule tests analyse plain classes and
     * never come near it.
     */
    protected function setUp(): void
    {
        parent::setUp();

        ini_set('memory_limit', '512M');
    }

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
