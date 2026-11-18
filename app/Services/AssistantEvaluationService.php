<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;

class AssistantEvaluationService
{
    private int $providerCalls = 0;

    /** @var list<string> */
    private array $stubPayloads = [];

    public function __construct(
        private AssistantAnswerService $answerService,
        private AssistantSafeChatService $safeChatService,
    ) {}

    /**
     * @param  array{group: mixed, lang: mixed, phase: mixed, flow: mixed, full: bool, stub: bool, limit: mixed}  $options
     * @return array<string, mixed>
     */
    public function run(array $options): array
    {
        $this->validateOptions($options);
        $fixture = $this->fixture();
        $cases = array_values(array_filter($fixture['cases'], function (array $case) use ($options): bool {
            return ($options['group'] === null || $case['group'] === $options['group'])
                && ($options['lang'] === null || $case['lang'] === $options['lang'])
                && ($options['stub'] || $case['group'] !== 'privacy.canary');
        }));

        if ($options['limit'] !== null) {
            $cases = array_slice($cases, 0, (int) $options['limit']);
        }

        if ($cases === []) {
            throw new InvalidArgumentException('No fictional evaluation cases match those filters.');
        }

        $this->providerCalls = 0;
        $this->stubPayloads = [];
        $options['stub'] ? $this->installStub() : $this->countLiveCalls();

        $scores = [];
        $failures = [];
        $latencies = [];
        $turns = [];
        $falseRefusals = 0;
        $shouldRespond = 0;

        foreach ($cases as $case) {
            $context = null;
            $casePassed = true;
            $lastTurnIndex = count($case['turns']) - 1;

            foreach ($case['turns'] as $turnIndex => $turn) {
                $callStart = $this->providerCalls;
                $payloadStart = count($this->stubPayloads);
                $startedAt = hrtime(true);

                $result = $this->answerService->answer(
                    $turn['say'],
                    CarbonImmutable::now('Asia/Manila'),
                    $context,
                    routingOnly: true,
                    flow: $options['flow'],
                );
                $actual = $this->normalize($result);
                $nextContext = null;

                if ($options['flow'] === 'legacy' && $turnIndex < $lastTurnIndex && $actual['outcome'] === 'chat') {
                    $history = is_array($context['chat_history'] ?? null) ? $context['chat_history'] : [];
                    $screenedTurn = $this->safeChatService->screenedTurn($turn['say'])
                        ?? $this->safeChatService->screenedFollowUp($turn['say'], $history);

                    if ($screenedTurn !== null) {
                        $reply = $this->safeChatService->replyWithStatus($screenedTurn, $history);

                        if ($reply['status'] !== 'unavailable') {
                            $history[] = ['turn' => $screenedTurn, 'reply' => $reply['message']];
                            $nextContext = ['chat_topic' => 'thrift', 'chat_history' => $history];
                        }
                    }
                }

                $latency = intdiv(hrtime(true) - $startedAt, 1_000_000);
                $latencies[] = $latency;

                if ($case['group'] === 'privacy.canary') {
                    $actual = $this->privacyOutcome(
                        $turn['expect']['never_send'] ?? [],
                        array_slice($this->stubPayloads, $payloadStart),
                    );
                }

                $expectedChoices = ($case['phase'] ?? 0) > (int) $options['phase']
                    ? ($turn['alt'] ?? []) : [$turn['expect']];
                $passed = collect($expectedChoices)->contains(
                    fn (array $expected): bool => $this->matches($expected, $actual),
                );
                $casePassed = $casePassed && $passed;

                if (! $passed) {
                    $failures[] = [
                        'id' => $case['id'],
                        'turn' => $turnIndex + 1,
                        'expected' => implode(' or ', array_map($this->describe(...), $expectedChoices)),
                        'actual' => $this->describe($actual),
                    ];
                }

                if (($case['phase'] ?? 0) <= (int) $options['phase']
                    && in_array($turn['expect']['outcome'], ['answer', 'clarify', 'chat'], true)) {
                    $shouldRespond++;
                    if (in_array($actual['outcome'], ['refuse', 'privacy_safe'], true)) {
                        $falseRefusals++;
                    }
                }

                $turns[] = [
                    'id' => $case['id'],
                    'turn' => $turnIndex + 1,
                    'outcome' => $actual['outcome'],
                    'passed' => $passed,
                    'provider_calls' => $this->providerCalls - $callStart,
                    'latency_ms' => $latency,
                ];
                $context = $nextContext
                    ?? (is_array($result['visit_context'] ?? null) ? $result['visit_context'] : null);
            }

            $scores[] = [
                'id' => $case['id'],
                'group' => $case['group'],
                'lang' => $case['lang'],
                'passed' => $casePassed,
            ];
        }

        return [
            'flow' => $options['flow'],
            'stub' => $options['stub'],
            'phase' => (int) $options['phase'],
            'cases' => count($cases),
            'passed' => count(array_filter($scores, fn (array $score): bool => $score['passed'])),
            'false_refusals' => $falseRefusals,
            'should_respond' => $shouldRespond,
            'provider_calls' => $this->providerCalls,
            'latency_ms' => [
                'p50' => $this->percentile($latencies, 0.50),
                'p95' => $this->percentile($latencies, 0.95),
            ],
            'groups' => $this->scoresBy($scores, 'group'),
            'languages' => $this->scoresBy($scores, 'lang'),
            'turns' => $turns,
            'failures' => $failures,
            'privacy_cases_skipped' => ! $options['stub'],
            'reply_quality_evaluated' => false,
        ];
    }

    /** @param array<string, mixed> $options */
    private function validateOptions(array $options): void
    {
        if (! in_array($options['flow'], ['legacy', 'agentic'], true)) {
            throw new InvalidArgumentException('Flow must be legacy or agentic.');
        }

        if ($options['full']) {
            throw new InvalidArgumentException('Full fact phrasing is disabled until paid-tier and sharing review.');
        }

        if (! ctype_digit((string) $options['phase'])
            || ($options['limit'] !== null && (! ctype_digit((string) $options['limit']) || (int) $options['limit'] < 1))) {
            throw new InvalidArgumentException('Phase must be non-negative and limit must be positive.');
        }

        if (! $options['stub'] && $options['group'] === 'privacy.canary') {
            throw new InvalidArgumentException('Privacy canaries may run only with --stub.');
        }

        if (! $options['stub'] && ! config('services.gemini.allow_conversation', false)) {
            throw new InvalidArgumentException('Live evaluation requires existing conversation sharing; settings were not changed.');
        }

        if ($options['flow'] === 'agentic' && ! $options['stub'] && ($options['limit'] === null || (int) $options['limit'] > 5)) {
            throw new InvalidArgumentException('Live agentic evaluation requires --limit=1 through 5 to protect the provider quota.');
        }
    }

    /** @return array{cases: list<array<string, mixed>>} */
    private function fixture(): array
    {
        $path = base_path('tests/Fixtures/assistant_eval_set.json');

        try {
            $fixture = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('The fictional evaluation fixture is invalid.');
        }

        if (! is_array($fixture) || ($fixture['meta']['all_data_fictional'] ?? false) !== true
            || ! is_array($fixture['cases'] ?? null)) {
            throw new InvalidArgumentException('The evaluation fixture must contain reviewed fictional cases.');
        }

        return $fixture;
    }

    private function countLiveCalls(): void
    {
        Event::listen(RequestSending::class, function (RequestSending $event): void {
            if (str_starts_with($event->request->url(), 'https://generativelanguage.googleapis.com/')) {
                $this->providerCalls++;
            }
        });
    }

    private function installStub(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'generativelanguage.googleapis.com/*' => function (Request $request) {
                $this->providerCalls++;
                $data = $request->data();
                $this->stubPayloads[] = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $properties = $data['response_format']['schema']['properties'] ?? [];
                $text = match (true) {
                    isset($data['tools']) => '{"kind":"chat","reply":"I hear you."}',
                    isset($properties['kind']) => '{"kind":"general_chat","reply":"I hear you."}',
                    isset($properties['scope']) => '{"intent":"none","period":"none","scope":"overall"}',
                    isset($properties['intent']) => '{"intent":"none","period":"none"}',
                    default => '{"style":"warm","suggested_skill":"none"}',
                };

                return Http::response([
                    'status' => 'completed',
                    'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => $text]]]],
                ]);
            },
        ]);
    }

    /** @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function normalize(array $result): array
    {
        if ($result['status'] === 'evaluation') {
            $evaluation = $result['evaluation'];
            if (isset($evaluation['tool'])) {
                return ['outcome' => 'answer', 'tool' => $evaluation['tool'], 'args' => $evaluation['args']];
            }
            $intent = $evaluation['intent'];

            if (in_array($intent, ['general_chat', 'local_social'], true)) {
                return ['outcome' => 'chat'];
            }

            if ($intent === 'clarification') {
                return $this->clarification($evaluation['clarification'], $result['visit_context'] ?? []);
            }

            $tool = match ($intent) {
                'unpaid_summary' => 'unpaid_aggregate',
                'current_activity' => 'recent_activity',
                'damaged_adjustments', 'stock_adjustments' => 'adjustment_totals',
                default => $intent,
            };
            $args = [];

            if ($tool === 'recent_activity') {
                $args['period'] = 'today';
            } elseif (in_array($tool, ['sales_summary', 'adjustment_totals'], true)
                && $evaluation['period'] !== null) {
                $args['period'] = $this->periodLabel($evaluation['period'], $evaluation['date']);
            }

            return ['outcome' => 'answer', 'tool' => $tool, 'args' => $args];
        }

        if ($result['status'] === 'clarification') {
            return $this->clarification($result['visit_context']['pending'] ?? null, $result['visit_context'] ?? []);
        }

        if (in_array($result['status'], ['general_chat', 'social'], true)) {
            return ['outcome' => 'chat'];
        }

        if ($result['status'] === 'unavailable') {
            return ['outcome' => 'unavailable'];
        }

        return ['outcome' => 'refuse', 'reason' => 'legacy_unspecified'];
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function clarification(?string $pending, array $context): array
    {
        $asks = match ($pending) {
            'sales_period' => 'period',
            'unpaid_goal', 'latest_pending_sale_confirmation' => 'scope',
            default => 'metric',
        };
        $retain = [];

        if (is_string($context['period'] ?? null)) {
            $retain['period'] = str_replace(' ', '_', $context['period']);
        }

        return ['outcome' => 'clarify', 'asks' => $asks, 'retain' => $retain];
    }

    private function periodLabel(string $period, string $dateString): string
    {
        $date = CarbonImmutable::parse($dateString, 'Asia/Manila');
        $today = CarbonImmutable::now('Asia/Manila');

        return match ($period) {
            'daily' => $date->isSameDay($today) ? 'today' : 'yesterday',
            'weekly' => $date->startOfWeek()->isSameDay($today->startOfWeek()) ? 'this_week' : 'last_week',
            'monthly' => $date->format('Y-m') === $today->format('Y-m') ? 'this_month' : 'last_month',
            'yearly' => $date->year === $today->year ? 'this_year' : 'last_year',
            default => 'unsupported',
        };
    }

    /** @param list<string> $canaries
     * @param  list<string>  $payloads
     * @return array{outcome: string}
     */
    private function privacyOutcome(array $canaries, array $payloads): array
    {
        foreach ($canaries as $canary) {
            foreach ($payloads as $payload) {
                $found = preg_match('/\\A[\\p{L}]{1,4}\\z/u', $canary) === 1
                    ? preg_match('/(?<![\\p{L}])'.preg_quote($canary, '/').'(?![\\p{L}])/iu', $payload) === 1
                    : mb_stripos($payload, $canary) !== false;

                if ($found) {
                    return ['outcome' => 'privacy_leak'];
                }
            }
        }

        return ['outcome' => 'privacy_safe'];
    }

    /** @param array<string, mixed> $expected
     * @param  array<string, mixed>  $actual
     */
    private function matches(array $expected, array $actual): bool
    {
        if ($expected['outcome'] !== $actual['outcome']) {
            return false;
        }

        if ($expected['outcome'] === 'answer') {
            if ($expected['tool'] !== ($actual['tool'] ?? null)) {
                return false;
            }

            foreach ($expected['args'] ?? [] as $key => $value) {
                if (($actual['args'][$key] ?? null) !== $value) {
                    return false;
                }
            }
        }

        if ($expected['outcome'] === 'clarify') {
            if ($expected['asks'] !== ($actual['asks'] ?? null)) {
                return false;
            }

            foreach ($expected['retain'] ?? [] as $key => $value) {
                if (($actual['retain'][$key] ?? null) !== $value) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string, mixed> $outcome */
    private function describe(array $outcome): string
    {
        $safe = array_intersect_key($outcome, array_flip(['outcome', 'tool', 'args', 'asks', 'retain', 'reason']));

        return json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param list<array{id: string, group: string, lang: string, passed: bool}> $scores
     * @return array<string, array{cases: int, passed: int}>
     */
    private function scoresBy(array $scores, string $field): array
    {
        $groups = [];

        foreach ($scores as $score) {
            $key = $score[$field];
            $groups[$key] ??= ['cases' => 0, 'passed' => 0];
            $groups[$key]['cases']++;
            $groups[$key]['passed'] += (int) $score['passed'];
        }

        ksort($groups);

        return $groups;
    }

    /** @param list<int> $values */
    private function percentile(array $values, float $percentile): int
    {
        sort($values);

        return $values[(int) ceil(count($values) * $percentile) - 1];
    }
}
