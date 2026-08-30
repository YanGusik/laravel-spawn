<?php

namespace Spawn\Laravel\Tests\PHPStan\Fixtures;

use Illuminate\Database\Eloquent\Model;

class GuardSwitches
{
    public function switchesForTheWorker(): void
    {
        Model::unguard();
        ImportedRow::reguard();
    }

    public function scopesItToItself(): void
    {
        Model::unguarded(fn () => null);
    }

    public function isNotAModel(): void
    {
        NotAModel::unguard();
    }
}
