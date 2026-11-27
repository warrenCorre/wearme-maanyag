<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use JsonException;
use LogicException;
use RuntimeException;

class AssistantScopeAuditService
{
    private const RESPONSE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'covered' => ['type' => 'boolean'],
            'uncovered' => ['type' => 'string'],
            'clarification' => ['type' => 'string'],
            'offer_ungrouped' => ['type' => 'boolean'],
            'language' => ['type' => 'string', 'enum' => ['en', 'ceb', 'mixed']],
        ],
        'required' => ['covered', 'uncovered', 'clarification', 'offer_ungrouped', 'language'],
    ];

    private const INSTRUCTION = <<<'INSTRUCTION'
        You are Maanyag's separate request-coverage auditor. Another model selected a read-only tool; do not trust its choice. Compare the Owner's current screened turn and any trusted pending offer with the exact tool description and arguments. A trusted pending sales_period means the Owner already requested sales totals and was asked to choose a reporting period; a new turn supplying that period can complete the request. It does not excuse a missing period or any new unsupported qualifier. Would executing this exact call fully answer every material qualifier, including category, person, grouping, metric, period, comparison, exclusion and requested output type? Judge a grouping from the meaning of the whole request, not from a preposition or discourse opener alone. If the Owner asks for a breakdown by a person, category or another dimension the tool cannot return, mark it uncovered even when the overall total would be useful. Never silently drop one. A brief acceptance is covered only when it clearly accepts the matching trusted offer. Set language=en for a clearly English current turn, ceb for clearly Cebuano, or mixed otherwise. If covered, return covered=true and empty strings for uncovered and clarification, with offer_ungrouped=false. Otherwise return covered=false, an exact short quote of the unsupported or ambiguous part, and one short natural clarification in the Owner's current language. Set offer_ungrouped=true only if the reply explicitly asks whether the Owner wants this same public aggregate without its unsupported filter; do not offer a different period, private detail or write. Never claim a store fact or figure. Return only the required JSON fields.
        A trusted pending store period means the Owner explicitly gave that period in the prior broad store question and was asked to choose a measure. If this turn chooses a measure, the selected tool may reuse the period only when its arguments match that exact period. A tool without that period cannot silently discard it. The pending period supplies no measure, filter or grouping. A trusted previous measure of sales_summary means Laravel verified sales totals and transaction count in the previous answer. It supplies that measure only if the current turn clearly continues it by selecting a new period. Check that the selected period matches the current request and reject any omitted filter or comparison. A new topic or a bare acknowledgement does not continue the measure.
        A request about how sales performed over an explicit supported period, with no extra filter, grouping or comparison, is covered by sales_summary because its verified answer includes recorded amount and transaction count. Do not demand another choice between those two outputs. For sales_summary with only a trusted pending store period, the current turn must independently choose recorded sales amount, totals or transaction count; a generic request for totals without any store measure is not covered. Do not infer sales_summary from a broad shop question or silently drop an unsupported qualifier.
        INSTRUCTION;

    public function __construct(
        private GeminiClient $geminiClient,
        private AssistantResponseValidator $responseValidator,
    ) {}

    /** @param array<string, string> $arguments
     * @param  array{tool: string, arguments: array<string, string>}|null  $trustedOffer
     * @return array{covered: bool, unavailable: bool, message: ?string, uncovered: string, offer_ungrouped: bool, language: ?string}
     */
    public function audit(string $turn, string $tool, array $arguments, string $description, ?array $trustedOffer, ?string $trustedPending = null, bool $continuesSalesSummary = false, int $timeoutSeconds = 7, ?string $pendingStorePeriod = null): array
    {
        $startedAt = hrtime(true);

        try {
            $input = json_encode([
                'current_screened_turn' => $turn,
                'selected_tool' => $tool,
                'selected_arguments' => $arguments,
                'tool_contract' => $description,
                'trusted_pending_offer' => $trustedOffer,
                'trusted_pending_clarification' => $trustedPending === 'sales_period' ? $trustedPending : null,
                'trusted_pending_store_period' => in_array($pendingStorePeriod, ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_year', 'last_year'], true) ? $pendingStorePeriod : null,
                'trusted_previous_measure' => $tool === 'sales_summary' && $continuesSalesSummary ? 'sales_summary' : null,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $raw = $this->geminiClient->generateText($input, self::INSTRUCTION, self::RESPONSE_SCHEMA, timeoutSeconds: $timeoutSeconds, retryTransient: true);
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (ConnectionException|RequestException|RuntimeException|LogicException|JsonException $exception) {
            Log::warning('Assistant agentic provider failed', [
                'stage' => 'scope_audit',
                'http_status' => $exception instanceof RequestException ? $exception->response->status() : null,
                'exception' => $exception::class,
                'latency_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
            ]);

            return $this->unavailable();
        }

        if (! is_array($decoded) || count($decoded) !== 5
            || ! is_bool($decoded['covered'] ?? null)
            || ! is_string($decoded['uncovered'] ?? null)
            || ! is_string($decoded['clarification'] ?? null)
            || ! is_bool($decoded['offer_ungrouped'] ?? null)
            || ! in_array($decoded['language'] ?? null, ['en', 'ceb', 'mixed'], true)) {
            return $this->unavailable();
        }

        if ($decoded['covered']) {
            return $decoded['uncovered'] === '' && $decoded['clarification'] === '' && ! $decoded['offer_ungrouped']
                ? ['covered' => true, 'unavailable' => false, 'message' => null, 'uncovered' => '', 'offer_ungrouped' => false, 'language' => $decoded['language']]
                : $this->unavailable();
        }

        $uncovered = trim($decoded['uncovered']);
        $clarification = trim($decoded['clarification']);

        if ($uncovered === '' || mb_strlen($uncovered) > 160 || mb_stripos($turn, $uncovered) === false
            || mb_strlen($clarification) > 320 || ! $this->responseValidator->isGroundedAgenticReply($clarification)) {
            return $this->unavailable();
        }

        return ['covered' => false, 'unavailable' => false, 'message' => $clarification, 'uncovered' => $uncovered, 'offer_ungrouped' => $decoded['offer_ungrouped'], 'language' => $decoded['language']];
    }

    /** @return array{covered: false, unavailable: true, message: string, uncovered: string, offer_ungrouped: false, language: null} */
    private function unavailable(): array
    {
        return [
            'covered' => false,
            'unavailable' => true,
            'message' => AssistantSafeChatService::TEMPORARY_UNAVAILABLE_MESSAGE,
            'uncovered' => '',
            'offer_ungrouped' => false,
            'language' => null,
        ];
    }
}
