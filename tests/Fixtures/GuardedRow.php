<?php

namespace Spawn\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A model with one fillable column and one guarded one, saved to a real table.
 *
 * `name` may be mass assigned and `admin` may not, so filling both says whether the guard is
 * on; saving a row fires the model events an application's observers hang on, which is the
 * only way to exercise the event path rather than the dispatcher getter.
 */
class GuardedRow extends Model
{
    public $timestamps = false;

    protected $table = 'guarded_rows';

    protected $fillable = ['name'];
}
