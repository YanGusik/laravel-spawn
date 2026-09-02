<?php

namespace Spawn\Laravel\Process;

/**
 * The state one request installs on the process factory.
 *
 * The factory itself stays one object per worker, because a fresh factory per call would lose
 * a stray guard a provider switched on at boot, and `Pool` and `Pipe` hold the factory they
 * were built with.
 */
final class ProcessState
{
    public bool $recording = false;

    /** @var list<array{0: \Illuminate\Process\PendingProcess, 1: \Illuminate\Contracts\Process\ProcessResult}> */
    public array $recorded = [];

    /** @var array<string, \Closure> by command pattern, `*` for any command */
    public array $fakeHandlers = [];

    public bool $preventStrayProcesses = false;
}
