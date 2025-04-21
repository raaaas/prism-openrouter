<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenRouter;

use Closure;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Contracts\Provider;
use Prism\Prism\Embeddings\Request as EmbeddingsRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;

readonly class OpenRouter implements Provider
{
    public function __construct(
        #[\SensitiveParameter] public string $apiKey,
        public string $url = 'https://openrouter.ai/api/v1',
    ) {}

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $handler = new Handlers\Text($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    #[\Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        throw new \RuntimeException('OpenRouter does not support structured output yet');
    }

    #[\Override]
    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        throw new \RuntimeException('OpenRouter does not support embeddings yet');
    }

    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        $handler = new Handlers\Stream($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array{0: array<int, int>|int, 1?: Closure|int, 2?: ?callable, 3?: bool}  $retry
     */
    protected function client(array $options, array $retry): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => sprintf('Bearer %s', $this->apiKey),
            'HTTP-Referer' => config('app.url', 'https://example.com'),
            'X-Title' => config('app.name', 'Laravel'),
        ])
            ->withOptions($options)
            ->retry(...$retry)
            ->baseUrl($this->url);
    }
}
