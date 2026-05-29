<?php

namespace App\Providers;

use App\Services\Agent\FakeLlmClient;
use App\Services\Agent\LlmClient;
use App\Services\Agent\LlmClientInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LlmClientInterface::class, FakeLlmClient::class);
        $this->app->bind(LlmClient::class, FakeLlmClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
