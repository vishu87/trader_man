<?php

namespace App\Services\Trading;

use Anthropic\Client;
use Illuminate\Support\Facades\Http;

/**
 * Asks an LLM (OpenAI or Claude) for a buy / sell / hold / avoid call on ONE stock, as strict JSON.
 *
 * The model only ever sees data computed by SignalContext and only ever returns an opinion: nothing it says reaches an
 * order or a paper trade without passing RiskRules first.
 */
class SignalAnalyst
{
    const PROMPT_VERSION = 'v1';

    /** @var callable|null fn(string $system, string $user, array $schema): array{text: string, input_tokens: int, output_tokens: int, model: string} */
    private $llm;

    /** @var array extra SDK request options (tests pass a fake HTTP transporter and maxRetries 0) */
    private array $clientOptions;

    /**
     * @param callable|null $llm            swap the whole model call out (tests do); null = the real Claude API
     * @param array         $clientOptions  extra SDK request options, merged over the defaults
     */
    public function __construct(?callable $llm = null, array $clientOptions = [])
    {
        $this->llm = $llm;
        $this->clientOptions = $clientOptions;
    }

    // ─── Which provider ──────────────────────────────────────────────────────

    /** 'openai' | 'anthropic' — AI_PROVIDER if set, else whichever key exists (OpenAI first), else OpenAI. */
    public static function provider(): string
    {
        $p = strtolower(trim((string) config('services.ai.provider')));
        if (in_array($p, ['openai', 'anthropic'], true)) return $p;
        if (config('services.openai.api_key')) return 'openai';
        if (config('services.anthropic.api_key')) return 'anthropic';
        return 'openai';
    }

    public static function providerLabel(): string { return self::provider() === 'openai' ? 'OpenAI' : 'Claude'; }
    public static function keyName(): string       { return self::provider() === 'openai' ? 'OPENAI_API_KEY' : 'ANTHROPIC_API_KEY'; }
    public static function keySet(): bool          { return (bool) config('services.' . self::provider() . '.api_key'); }
    public static function modelName(): string     { return (string) config('services.' . self::provider() . '.model'); }

    public static function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a disciplined technical analyst for Indian equities (NSE / BSE, delivery trades, long-only: no shorting, no derivatives).
You are given pre-computed market data for ONE stock: indicators calculated by code, and monthly / weekly / daily candle tables with moving averages.

Decide one of:
- "buy"   — a long entry worth taking, now or on a defined pullback / breakout.
- "sell"  — exit or reduce if the user holds it; otherwise it means the structure is deteriorating and the stock should be avoided.
- "hold"  — no edge yet: wait, or keep what is held.
- "avoid" — poor structure or risk; do not buy.

For a "buy":
- entry_price is a price level you would actually enter at: a pullback to support (below the current price) or a breakout level just above resistance. Say which in the pattern name.
- stop_loss is where the trade idea is proven wrong — just beyond a real swing low / support, ideally 1–2 ATR from entry — never an arbitrary percentage.
- target_price is the next real resistance or a measured move.
- reward:risk must be at least 1.5. If you cannot construct that from the levels in the data, the answer is "hold".
- valid_days: how many trading days the entry level stays valid (1–10).
For any other action, entry_price, stop_loss and target_price must be null.

Rules:
- Use only the data given. Do not invent news, earnings, fundamentals or price levels that are not in the tables.
- Every price you quote must be consistent with the data (check levels against the candles).
- Read the monthly and weekly charts for the primary trend first, then use the daily chart for timing.
- Prefer "hold" when the picture is unclear. Confidence is 0–100 and should be honest: 50 means a coin flip; most calls are below 70.
- summary is 2–3 plain sentences. reasons are 3–6 short, specific points that cite levels or readings. risks are what could go wrong. invalidation is the concrete event that would make the call wrong.
PROMPT;
    }

    /** JSON schema of the answer (structured outputs: every field required, no extras). */
    public static function schema(): array
    {
        $nullableNumber = ['anyOf' => [['type' => 'number'], ['type' => 'null']]];

        return [
            'type' => 'object',
            'properties' => [
                'action'       => ['type' => 'string', 'enum' => ['buy', 'sell', 'hold', 'avoid']],
                'confidence'   => ['type' => 'integer'],
                'pattern'      => ['type' => 'string'],
                'entry_price'  => $nullableNumber,
                'stop_loss'    => $nullableNumber,
                'target_price' => $nullableNumber,
                'valid_days'   => ['type' => 'integer'],
                'summary'      => ['type' => 'string'],
                'reasons'      => ['type' => 'array', 'items' => ['type' => 'string']],
                'risks'        => ['type' => 'array', 'items' => ['type' => 'string']],
                'invalidation' => ['type' => 'string'],
            ],
            'required' => ['action', 'confidence', 'pattern', 'entry_price', 'stop_loss', 'target_price', 'valid_days', 'summary', 'reasons', 'risks', 'invalidation'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array{data: array, input_tokens: int, output_tokens: int, model: string}
     * @throws \RuntimeException with a message that is fine to show the user
     */
    public function analyse(string $context): array
    {
        $call   = $this->llm ?? (self::provider() === 'openai' ? [$this, 'callOpenAi'] : [$this, 'callClaude']);
        $result = $call(self::systemPrompt(), $context, self::schema());

        $data = json_decode($result['text'] ?? '', true);
        if (!is_array($data) || !isset($data['action'])) {
            throw new \RuntimeException(self::providerLabel() . ' did not return a usable answer (not valid JSON) — try again');
        }

        return ['data' => $data] + array_intersect_key($result, array_flip(['input_tokens', 'output_tokens', 'model']));
    }

    /** The OpenAI call: Chat Completions with a strict JSON-schema answer. */
    private function callOpenAi(string $system, string $user, array $schema): array
    {
        $key = config('services.openai.api_key');
        if (!$key) {
            throw new \RuntimeException('OPENAI_API_KEY is not set. Add it to .env (and save the file); if the config is cached run `php artisan config:clear`.');
        }

        $payload = [
            'model'    => config('services.openai.model'),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'trade_call', 'strict' => true, 'schema' => $schema]],
            'max_completion_tokens' => 12000,   // a reasoning model's hidden thinking counts against this too
        ];
        // only reasoning models accept this; an empty setting leaves it out (gpt-4o and friends reject it)
        if ($effort = config('services.openai.reasoning_effort')) {
            $payload['reasoning_effort'] = $effort;
        }

        try {
            $response = Http::withToken($key)->timeout(180)->acceptJson()->post('https://api.openai.com/v1/chat/completions', $payload);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not reach OpenAI: ' . $e->getMessage());
        }

        if (!$response->successful()) {
            $status  = $response->status();
            $detail  = (string) ($response->json('error.message') ?? '');
            $code    = (string) ($response->json('error.code') ?? '');
            throw new \RuntimeException(match (true) {
                $status === 401 => 'OpenAI rejected the API key — check OPENAI_API_KEY',
                $code === 'insufficient_quota' => 'OpenAI says the account is out of credit or over its quota — check billing',
                $status === 429 => 'OpenAI rate limit reached — wait a minute and try again',
                $status === 404 => 'OpenAI does not know the model "' . $payload['model'] . '" for this key — set OPENAI_MODEL to one you can use',
                $status >= 500  => 'OpenAI is having trouble right now — try again shortly',
                default         => 'OpenAI request failed (' . $status . ')' . ($detail !== '' ? ': ' . $detail : ''),
            });
        }

        $choice = $response->json('choices.0') ?? [];
        $msg    = $choice['message'] ?? [];

        if (!empty($msg['refusal'])) {
            throw new \RuntimeException('OpenAI declined to answer this request');
        }
        if (($choice['finish_reason'] ?? '') === 'length') {
            throw new \RuntimeException('OpenAI ran out of room before finishing — try again');
        }

        return [
            'text'          => (string) ($msg['content'] ?? ''),
            'input_tokens'  => (int) $response->json('usage.prompt_tokens', 0),
            'output_tokens' => (int) $response->json('usage.completion_tokens', 0),
            'model'         => (string) ($response->json('model') ?? $payload['model']),
        ];
    }

    /** The Claude call, through the official SDK. */
    private function callClaude(string $system, string $user, array $schema): array
    {
        $key = config('services.anthropic.api_key');
        if (!$key) {
            throw new \RuntimeException('ANTHROPIC_API_KEY is not set. Add it to .env (and save the file); if the config is cached run `php artisan config:clear`.');
        }

        $model  = config('services.anthropic.model');
        $client = new Client(apiKey: $key, requestOptions: ['timeout' => 150] + $this->clientOptions);

        try {
            $message = $client->messages->create(
                model: $model,
                maxTokens: 12000,   // thinking tokens count against this too; the JSON itself is small
                system: $system,
                messages: [['role' => 'user', 'content' => $user]],
                outputConfig: ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
            );
        } catch (\Anthropic\Core\Exceptions\APIStatusException $e) {
            $status = $e->status ?? null;
            throw new \RuntimeException(match (true) {
                $status === 401 => 'Anthropic rejected the API key — check ANTHROPIC_API_KEY',
                $status === 429 => 'Anthropic rate limit reached — wait a minute and try again',
                $status !== null && $status >= 500 => 'Anthropic is having trouble right now — try again shortly',
                default => 'Anthropic request failed: ' . $e->getMessage(),
            });
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not reach Anthropic: ' . $e->getMessage());
        }

        if ($message->stopReason === 'refusal') {
            throw new \RuntimeException('Claude declined to answer this request');
        }
        if ($message->stopReason === 'max_tokens') {
            throw new \RuntimeException('Claude ran out of room before finishing — try again');
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return [
            'text'          => $text,
            'input_tokens'  => $message->usage->inputTokens,
            'output_tokens' => $message->usage->outputTokens,
            'model'         => $message->model,
        ];
    }
}
