<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use RuntimeException;

class AssistantToolRegistry
{
    private const PERIODS = ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_year', 'last_year'];

    public function __construct(
        private AssistantContextService $contextService,
        private AssistantResponseValidator $responseValidator,
        private AssistantVerifiedFallback $verifiedFallback,
        private AssistantCurrentActivityService $currentActivityService,
        private AssistantStockAdjustmentService $stockAdjustmentService,
        private AssistantDamagedAdjustmentService $damagedAdjustmentService,
        private AssistantLatestUnpaidSaleService $latestUnpaidSaleService,
    ) {}

    /** @return array<string, array{description: string, properties: array<string, array<string, mixed>>, required: list<string>, local_only: bool}> */
    private function definitions(): array
    {
        $period = ['type' => 'string', 'enum' => self::PERIODS];

        return [
            'sales_summary' => ['description' => 'Recorded sales count and total for one Philippine-time period. Ask which period if missing.', 'properties' => ['period' => $period], 'required' => ['period'], 'local_only' => false],
            'inventory_summary' => ['description' => 'Current aggregate product count, units and stock value. No individual product lookup.', 'properties' => [], 'required' => [], 'local_only' => false],
            'low_stock' => ['description' => 'Current top ten active products at or below their stock threshold. No category filter.', 'properties' => [], 'required' => [], 'local_only' => false],
            'best_sellers' => ['description' => 'Top five products by units across all recorded paid sales. No period, limit or category filter.', 'properties' => [], 'required' => [], 'local_only' => false],
            'collection_dates' => ['description' => 'Ten most recently collected active products with recorded collection dates. No date filter.', 'properties' => [], 'required' => [], 'local_only' => false],
            'unpaid_aggregate' => ['description' => 'Current aggregate count and total outstanding balance of pending sales. No customer or individual sale filter.', 'properties' => [], 'required' => [], 'local_only' => false],
            'recent_activity' => ['description' => 'Recorded store activity today in Philippine time only.', 'properties' => ['period' => ['type' => 'string', 'enum' => ['today']]], 'required' => ['period'], 'local_only' => false],
            'adjustment_totals' => ['description' => 'Aggregate non-sales stock or damaged-item adjustments for a period. Reasons and product details stay local.', 'properties' => ['kind' => ['type' => 'string', 'enum' => ['stock', 'damaged']], 'period' => $period], 'required' => ['kind', 'period'], 'local_only' => true],
            'latest_unpaid_sale' => ['description' => 'Latest individual pending unpaid sale. Private details are rendered locally and never returned to Gemini.', 'properties' => [], 'required' => [], 'local_only' => true],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function declarations(): array
    {
        $tools = [];

        foreach ($this->definitions() as $name => $definition) {
            $tools[] = [
                'type' => 'function', 'name' => $name, 'description' => $definition['description'],
                'parameters' => [
                    'type' => 'object',
                    'properties' => $definition['properties'] === [] ? (object) [] : $definition['properties'],
                    'required' => $definition['required'],
                ],
            ];
        }

        return $tools;
    }

    /** @return array{valid: bool, reason: ?string, local_only: bool} */
    public function validate(string $name, mixed $arguments): array
    {
        $definition = $this->definitions()[$name] ?? null;

        if ($definition === null) {
            return ['valid' => false, 'reason' => 'unknown_tool', 'local_only' => false];
        }

        if (! Auth::check() || Auth::user()?->role !== 'store_owner' || Auth::user()?->status !== 'active') {
            return ['valid' => false, 'reason' => 'owner_required', 'local_only' => $definition['local_only']];
        }

        if (! config('services.gemini.allow_conversation', false) || ! config('services.gemini.allow_store_data', false)) {
            return ['valid' => false, 'reason' => 'sharing_disabled', 'local_only' => $definition['local_only']];
        }

        if (! is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            return ['valid' => false, 'reason' => 'invalid_arguments', 'local_only' => $definition['local_only']];
        }

        $keys = array_keys($arguments);

        if (array_diff($keys, array_keys($definition['properties'])) !== [] || array_diff($definition['required'], $keys) !== []) {
            return ['valid' => false, 'reason' => 'unsupported_scope', 'local_only' => $definition['local_only']];
        }

        foreach ($arguments as $key => $value) {
            if (! is_string($value) || ! in_array($value, $definition['properties'][$key]['enum'], true)) {
                return ['valid' => false, 'reason' => 'unsupported_argument', 'local_only' => $definition['local_only']];
            }
        }

        return ['valid' => true, 'reason' => null, 'local_only' => $definition['local_only']];
    }

    /**
     * @param  array<string, string>  $arguments
     * @return array{answer: array<string, mixed>, facts: array<string, mixed>, local_only: bool}
     */
    public function execute(string $name, array $arguments, ?CarbonInterface $referenceDate = null): array
    {
        $validation = $this->validate($name, $arguments);

        if (! $validation['valid']) {
            throw new InvalidArgumentException($validation['reason'] ?? 'invalid_tool');
        }

        $today = CarbonImmutable::instance($referenceDate ?? CarbonImmutable::now('Asia/Manila'))->setTimezone('Asia/Manila');
        $asOf = $today->toDateString();

        if ($name === 'latest_unpaid_sale') {
            return ['answer' => ['status' => 'answered', 'message' => $this->latestUnpaidSaleService->answer(), 'visit_context' => ['store_topic' => 'unpaid']], 'facts' => [], 'local_only' => true];
        }

        if ($name === 'recent_activity') {
            return ['answer' => $this->localAnswer($this->currentActivityService->answer($today), 'current_activity', 'recorded_activity', 'Recorded sales, stock adjustments, products, collection dates and settlements', $asOf), 'facts' => [], 'local_only' => false];
        }

        if ($name === 'adjustment_totals') {
            [$period, $date] = $this->period($arguments['period'], $today);
            $damaged = $arguments['kind'] === 'damaged';
            $message = $damaged ? $this->damagedAdjustmentService->answer($period, $date) : $this->stockAdjustmentService->answer($period, $date);

            return ['answer' => $this->localAnswer($message, $damaged ? 'damaged_adjustments' : 'stock_adjustments', 'inventory_adjustments', $damaged ? 'Recorded damaged-item adjustments' : 'Recorded non-sales stock adjustments', $asOf), 'facts' => [], 'local_only' => true];
        }

        [$period, $date] = $this->period($arguments['period'] ?? 'this_month', $today);
        $context = $this->contextService->build($period, $date);
        $intent = $name === 'unpaid_aggregate' ? 'unpaid_summary' : $name;
        $facts = match ($intent) {
            'sales_summary' => ['sales' => $context['sales'], 'as_of' => ['sales' => $context['as_of']['sales']]],
            'inventory_summary' => ['inventory' => ['stats' => $context['inventory']['stats']], 'as_of' => ['inventory' => $context['as_of']['inventory']]],
            'low_stock' => ['inventory' => ['low_stock_products' => $context['inventory']['low_stock_products']], 'as_of' => ['inventory' => $context['as_of']['inventory']]],
            'best_sellers' => ['best_sellers' => $context['best_sellers'], 'as_of' => ['best_sellers' => $context['as_of']['best_sellers']]],
            'collection_dates' => ['collection_dates' => $context['collection_dates'], 'as_of' => ['collection_dates' => $context['as_of']['collection_dates']]],
            default => ['unpaid_summary' => $context['unpaid_summary'], 'as_of' => ['unpaid_summary' => $context['as_of']['unpaid_summary']]],
        };
        $sources = $this->responseValidator->allowedSourcesFor($intent, $context);
        $answer = $this->verifiedFallback->answer(['intent' => $intent, 'period' => $period, 'date' => $date->toDateString()], $facts, $sources);

        if ($answer === null) {
            throw new RuntimeException('Verified local tool answer is unavailable.');
        }

        return ['answer' => $answer, 'facts' => $facts, 'local_only' => false];
    }

    /** @return array{0: string, 1: CarbonImmutable} */
    private function period(string $label, CarbonImmutable $today): array
    {
        return match ($label) {
            'today' => ['daily', $today], 'yesterday' => ['daily', $today->subDay()],
            'this_week' => ['weekly', $today], 'last_week' => ['weekly', $today->subWeek()],
            'this_month' => ['monthly', $today], 'last_month' => ['monthly', $today->subMonth()],
            'this_year' => ['yearly', $today], 'last_year' => ['yearly', $today->subYear()],
            default => ['monthly', $today],
        };
    }

    /** @return array<string, mixed> */
    private function localAnswer(string $message, string $intent, string $type, string $label, string $asOf): array
    {
        return [
            'status' => 'answered', 'message' => $message,
            'sources' => [['type' => $type, 'label' => $label, 'as_of' => $asOf]],
            'answer_type' => 'fact', 'confidence' => 'high', 'visit_context' => ['intent' => $intent],
        ];
    }
}
