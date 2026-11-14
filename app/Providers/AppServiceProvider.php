<?php

namespace App\Providers;

use App\Services\GeminiClient;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Tests\Support\FakeGeminiClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if (! config('services.gemini.demo_fake', false)) {
            return;
        }

        if (! $this->app->environment('local') || ! class_exists(FakeGeminiClient::class)) {
            throw new LogicException('The local Assistant demo fake cannot be started. No provider request was made.');
        }

        $this->app->bind(GeminiClient::class, FakeGeminiClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
