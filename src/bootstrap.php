<?php

use Spawn\Laravel\Database\Eloquent\EloquentOverrides;

// Composer includes this while it is still loading files, which is the last moment early
// enough: the copies are put in front of Laravel's classes through the class map, and a
// class already loaded cannot be replaced. Files of packages this one depends on — Laravel's
// own among them — run before it, so an entry there that touches Eloquent wins.
$refusedEloquentOverrides = EloquentOverrides::install();

// A group left out is a fix that is not there, and the application goes on serving without it:
// a request reads another's rows, or writes past its guard. The one refusal that is nobody's
// fault is the operator's own switch.
foreach ($refusedEloquentOverrides as $group => $reason) {
    if ($reason !== EloquentOverrides::SWITCHED_OFF) {
        trigger_error("laravel-spawn: the Eloquent copies for $group are not installed — $reason", E_USER_WARNING);
    }
}
