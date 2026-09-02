<?php

namespace Spawn\Laravel\Tests\Fixtures;

/**
 * What the pooled transports did, in the order they did it.
 *
 * Shared by every slot of one pool, so the order across slots is the interleaving a test
 * needs to judge: "enter 1", "enter 2", "leave 1" says two connections carried two messages
 * at once, and a second "enter 1" before "leave 1" would say one carried both.
 */
final class SendLog
{
    /** @var list<string> "<event> <slot>", where slot is the connection's serial number */
    public array $events = [];

    public function record(string $event, int $slot): void
    {
        $this->events[] = $event . ' ' . $slot;
    }

    /**
     * The distinct connections that were used, as serial numbers.
     *
     * @return list<string>
     */
    public function slots(): array
    {
        $slots = [];

        foreach ($this->events as $event) {
            $slots[] = explode(' ', $event)[1];
        }

        return array_values(array_unique($slots));
    }
}
