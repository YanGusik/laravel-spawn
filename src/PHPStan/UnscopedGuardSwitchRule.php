<?php

declare(strict_types=1);

namespace Spawn\Laravel\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * Flags `Model::unguard()` and `Model::reguard()`, the pair that switches mass assignment for
 * the whole worker.
 *
 * The scoped form, `Model::unguarded(callable)`, is held per coroutine by this package's copy
 * of `Concerns\GuardsAttributes`, so one request's import cannot drop another request's guard.
 * The unscoped pair has no callback to close and writes the class static, which is one value
 * for every request the worker serves — deliberately, because a seeder and a service provider
 * mean exactly that. Called while serving, it leaves mass assignment off until something else
 * turns it back on, and every request in between fills whatever it is given.
 *
 * @implements Rule<StaticCall>
 */
final class UnscopedGuardSwitchRule implements Rule
{
    private const SWITCHES = ['unguard', 'reguard'];

    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Node\Identifier) {
            return [];
        }

        $method = $node->name->toString();

        if (! in_array(strtolower($method), self::SWITCHES, true)) {
            return [];
        }

        if (! $this->callsAModel($node, $scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Model::%s() switches mass assignment for the whole worker and has nothing to close it; '
                .'inside a request use Model::unguarded(callable), which is held per coroutine.',
                $method,
            ))
                ->identifier('coroutine.unscopedGuardSwitch')
                ->line($node->getStartLine())
                ->build(),
        ];
    }

    private function callsAModel(StaticCall $node, Scope $scope): bool
    {
        $type = $node->class instanceof Node\Name
            ? $scope->resolveTypeByName($node->class)
            : $scope->getType($node->class);

        return (new ObjectType(self::MODEL))->isSuperTypeOf($type)->yes();
    }
}
