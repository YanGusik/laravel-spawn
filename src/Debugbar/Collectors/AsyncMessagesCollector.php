<?php

namespace Spawn\Laravel\Debugbar\Collectors;

use DebugBar\DataCollector\MessagesCollector;

/**
 * Context-backed messages collector. One shared instance, per-coroutine data.
 * @see DelegatesToContext
 */
class AsyncMessagesCollector extends MessagesCollector
{
    use DelegatesToContext;

    public function __construct(string $name = 'messages')
    {
        parent::__construct($name);
        $this->bindContextDelegate('debugbar.collector.' . $name, fn () => new MessagesCollector($name));
    }

    public function addMessage($message, $label = 'info', $context = []): void
    {
        $this->delegate()->addMessage($message, $label, $context);
    }

    public function log($level, $message, array $context = []): void
    {
        $this->delegate()->log($level, $message, $context);
    }

    public function getMessages(): array
    {
        return $this->delegate()->getMessages();
    }

    public function collect(): array
    {
        return $this->delegate()->collect();
    }

    public function reset(): void
    {
        $this->delegate()->reset();
    }

    public function clear(): void
    {
        $this->delegate()->clear();
    }
}
