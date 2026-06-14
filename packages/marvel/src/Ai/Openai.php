<?php

namespace Marvel\Ai;

use Exception;
use Marvel\Ai\AiInterface;
use Marvel\Ai\Base;
use OpenAI as OpenAIClient;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Openai extends Base implements AiInterface
{
    private $openAiClient;

    public function __construct()
    {
        $this->openAiClient = OpenAIClient::client(config('shop.openai.secret_Key'));
        parent::__construct();
    }
    /**
     * createCustomer
     *
     * @param  mixed  $request
     * @return array
     */
    public function generateDescription($request): mixed
    {
        try {
            $language = $request->language ?? 'en';
            $response = $this->openAiClient->chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => "You write concise, compelling e-commerce product descriptions. "
                            . "Respond in the language with ISO 639 code '{$language}' (use English if you don't know it).",
                    ],
                    [
                        'role' => 'user', 'content' => $request->prompt
                    ],
                ],
            ]);

            foreach ($response->choices as $result) {
                $result->index; // 0
                $result->message->role; // 'assistant'
                $result->message->content; // '\n\nHello there! How can I assist you today?'
                $result->finishReason; // 'stop'
            }

            return ['status' => 'success', 'result' => $result->message->content];
        } catch (Exception $e) {
            throw new HttpException(400, SOMETHING_WENT_WRONG);
        }
    }
}
