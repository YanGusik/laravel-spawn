<?php

namespace Spawn\Laravel\Tests\Fixtures;

use Closure;
use Illuminate\Translation\ArrayLoader;

/**
 * A translation loader a test can hold open: loading a group listed in `$holds` runs that
 * group's callback first, which is how a test suspends a coroutine inside `Translator::load()`,
 * where a file or database loader does its I/O.
 */
class HoldingLoader extends ArrayLoader
{
    /** @var array<string, Closure> group => run before the group is answered */
    public array $holds = [];

    public function load($locale, $group, $namespace = null)
    {
        if (isset($this->holds[$group])) {
            ($this->holds[$group])();
        }

        return parent::load($locale, $group, $namespace);
    }
}
