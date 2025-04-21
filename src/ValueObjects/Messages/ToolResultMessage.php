<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects\Messages;

use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\ToolResult;

readonly class ToolResultMessage implements Message
{
    /**
     * @param  ToolResult[]  $toolResults
     */
    public function __construct(
        public array $toolResults
    ) {}
    public function role(): string
    {
        return 'assistant';
    }

    public function content(): string
    {
        return collect($this->toolResults)
            ->map(fn (ToolResult $result): string => sprintf(
                "Name: %s\nResult: %s",
                $result->name,
                $result->result
            ))
            ->implode("\n\n");
    }
}
