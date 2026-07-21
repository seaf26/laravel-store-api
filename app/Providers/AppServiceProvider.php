<?php

namespace App\Providers;

use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsSender;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Resolve the SMS gateway from configuration. Adding a real provider
        // means implementing SmsSender and registering it here.
        $this->app->bind(SmsSender::class, function () {
            return match (config('store.sms_sender')) {
                default => new LogSmsSender,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
