<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenRouter\Handlers;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismChunkDecodeException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Text\Chunk;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Meta;
use Psr\Http\Message\StreamInterface;
use Throwable;

class Stream
{
    public function __construct(protected PendingRequest $client) {}

    /**
     * @return Generator<Chunk>
     */
    public function handle(Request $request): Generator
    {
        $response = $this->sendRequest($request);

        yield from $this->processStream($response);
    }

    /**
     * @return Generator<Chunk>
     */
    protected function processStream(Response $response): Generator
    {
        $text = '';

        while (! $response->getBody()->eof()) {
            $data = $this->parseNextDataLine($response->getBody());

            // Skip empty data or DONE markers
            if ($data === null) {
                continue;
            }

            // Process regular content
            $content = data_get($data, 'choices.0.delta.content', '') ?? '';
            $text .= $content;

            $finishReason = $this->mapFinishReason($data);

            yield new Chunk(
                text: $content,
                finishReason: $finishReason !== FinishReason::Unknown ? $finishReason : null,
                meta: new Meta(
                    id: data_get($data, 'id', ''),
                    model: data_get($data, 'model', ''),
                    rateLimits: []
                )
            );
        }
    }

    /**
     * @return array<string, mixed>|null Parsed JSON data or null if line should be skipped
     */
    protected function parseNextDataLine(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);

        if (! str_starts_with($line, 'data:')) {
            return null;
        }

        $line = trim(substr($line, strlen('data: ')));

        if (Str::contains($line, 'DONE')) {
            return null;
        }

        try {
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new PrismChunkDecodeException('OpenRouter', $e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapFinishReason(array $data): FinishReason
    {
        return match (data_get($data, 'choices.0.finish_reason')) {
            'stop' => FinishReason::Stop,
            'length' => FinishReason::Length,
            default => FinishReason::Unknown,
        };
    }

    protected function sendRequest(Request $request): Response
    {
        try {
            return $this
                ->client
                ->withOptions(['stream' => true])
                ->throw()
                ->post(
                    'chat/completions',
                    [
                        'stream' => true,
                        'model' => $request->model(),
                        'messages' => array_map(
                            fn ($message): array => [
                                'role' => $message->role(),
                                'content' => $message->content(),
                            ],
                            $request->messages()
                        ),
                        'max_tokens' => $request->maxTokens(),
                        'temperature' => $request->temperature(),
                        'top_p' => $request->topP(),
                    ]
                );
        } catch (Throwable $e) {
            if ($e instanceof RequestException && $e->response->getStatusCode() === 429) {
                throw PrismException::providerRequestError($request->model(), new \Exception('Rate limit exceeded'));
            }

            throw PrismException::providerRequestError($request->model(), $e);
        }
    }

    protected function readLine(StreamInterface $stream): string
    {
        $buffer = '';

        while (! $stream->eof()) {
            $byte = $stream->read(1);

            if ($byte === '') {
                return $buffer;
            }

            $buffer .= $byte;

            if ($byte === "\n") {
                break;
            }
        }

        return $buffer;
    }
}
