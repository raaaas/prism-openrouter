<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenRouter\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

class Text
{
    use CallsTools;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): Response
    {
        $response = $this->sendRequest($request);

        if (! $response->successful()) {
            throw PrismException::providerRequestError($request->model(), new \Exception($response->body()));
        }

        $data = $response->json();

        $responseMessage = new AssistantMessage(
            data_get($data, 'choices.0.message.content') ?? '',
            [], // OpenRouter doesn't support tool calls yet
        );

        $this->responseBuilder->addResponseMessage($responseMessage);

        $request->addMessage($responseMessage);

        return $this->handleStop($data, $request, $response);
    }

    protected function handleStop(array $data, Request $request, ClientResponse $clientResponse): Response
    {
        $this->addStep($data, $request, $clientResponse);

        return $this->responseBuilder->toResponse();
    }

    protected function sendRequest(Request $request): ClientResponse
    {
        try {
            return $this->client->post(
                'chat/completions',
                [
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
            throw PrismException::providerRequestError($request->model(), $e);
        }
    }

    protected function addStep(array $data, Request $request, ClientResponse $clientResponse): void
    {
        $this->responseBuilder->addStep(new Step(
            text: data_get($data, 'choices.0.message.content') ?? '',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(
                data_get($data, 'usage.prompt_tokens'),
                data_get($data, 'usage.completion_tokens'),
            ),
            meta: new Meta(
                id: data_get($data, 'id', ''),
                model: data_get($data, 'model', ''),
                rateLimits: []
            ),
            messages: $request->messages(),
            additionalContent: [],
            systemPrompts: $request->systemPrompts(),
        ));
    }
}
