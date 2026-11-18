<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AssistantEvaluationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

#[Signature('assistant:eval {--group=} {--lang=} {--phase=0} {--flow=legacy} {--full} {--json} {--stub} {--limit=}')]
#[Description('Measure Assistant routing with reviewed fictional questions')]
class AssistantEval extends Command
{
    public function handle(AssistantEvaluationService $evaluationService): int
    {
        $owner = User::query()
            ->where('role', 'store_owner')
            ->where('status', 'active')
            ->first(['id', 'role', 'status']);

        if ($owner === null) {
            $this->error('An active Store Owner is required to run the Assistant evaluation.');

            return self::FAILURE;
        }

        Auth::setUser($owner);

        try {
            $report = $evaluationService->run([
                'group' => $this->option('group'),
                'lang' => $this->option('lang'),
                'phase' => $this->option('phase'),
                'flow' => $this->option('flow'),
                'full' => (bool) $this->option('full'),
                'stub' => (bool) $this->option('stub'),
                'limit' => $this->option('limit'),
            ]);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Assistant %s baseline: %d/%d cases passed; false refusals %d/%d; provider calls %d; p50/p95 %d/%d ms.',
            $report['flow'],
            $report['passed'],
            $report['cases'],
            $report['false_refusals'],
            $report['should_respond'],
            $report['provider_calls'],
            $report['latency_ms']['p50'],
            $report['latency_ms']['p95'],
        ));

        foreach ($report['groups'] as $group => $score) {
            $this->line("Group {$group}: {$score['passed']}/{$score['cases']}");
        }

        foreach ($report['languages'] as $language => $score) {
            $this->line("Language {$language}: {$score['passed']}/{$score['cases']}");
        }

        foreach ($report['failures'] as $failure) {
            $this->warn("{$failure['id']} turn {$failure['turn']}: expected {$failure['expected']}, got {$failure['actual']}");
        }

        $this->comment('Routing only: chat wording and final fact phrasing were not graded.');

        return self::SUCCESS;
    }
}
