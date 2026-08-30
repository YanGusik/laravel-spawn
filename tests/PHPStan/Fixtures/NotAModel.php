<?php

namespace Spawn\Laravel\Tests\PHPStan\Fixtures;

/**
 * Carries the same method names and none of the meaning: the rule asks about the type, not
 * about the spelling.
 */
class NotAModel
{
    public static function unguard(): void
    {
    }
}
