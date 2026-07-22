<?php

namespace App\Providers;

use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsSender;
use App\Services\Sms\TwilioSmsSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
                'twilio' => new TwilioSmsSender(
                    sid: (string) config('store.twilio.sid'),
                    token: (string) config('store.twilio.token'),
                    from: (string) config('store.twilio.from'),
                ),
                default => new LogSmsSender,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $throttleResponse = fn (Request $request, array $headers) => response()->json([
            'message' => 'Too many attempts. Please try again later.',
        ], 429, $headers);

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)
                ->by('source:'.$this->limiterDigest('source', (string) $request->ip()))
                ->response($throttleResponse),
            Limit::perMinute(5)
                ->by('phone:'.$this->limiterDigest('phone', $this->canonicalPhone($request)))
                ->response($throttleResponse),
        ]);

        RateLimiter::for('phone-verification-issue', fn (Request $request) => $this->issueLimits(
            $request,
            'phone-verification',
            $throttleResponse,
        ));

        RateLimiter::for('password-reset-issue', fn (Request $request) => $this->issueLimits(
            $request,
            'password-reset',
            $throttleResponse,
        ));

        RateLimiter::for('phone-verification-attempt', fn (Request $request) => $this->attemptLimits(
            $request,
            'phone-verification',
            $throttleResponse,
        ));

        RateLimiter::for('password-reset-attempt', fn (Request $request) => $this->attemptLimits(
            $request,
            'password-reset',
            $throttleResponse,
        ));
    }

    /**
     * @return array<int, Limit>
     */
    private function issueLimits(Request $request, string $purpose, callable $response): array
    {
        return [
            Limit::perMinutes(10, 3)
                ->by('phone:'.$this->limiterDigest($purpose.'-phone', $this->canonicalPhone($request)))
                ->response($response),
            Limit::perMinute(20)
                ->by('source:'.$this->limiterDigest($purpose.'-source', (string) $request->ip()))
                ->response($response),
        ];
    }

    /**
     * @return array<int, Limit>
     */
    private function attemptLimits(Request $request, string $purpose, callable $response): array
    {
        return [
            Limit::perMinute(5)
                ->by('source:'.$this->limiterDigest($purpose.'-source', (string) $request->ip()))
                ->response($response),
            Limit::perMinute(5)
                ->by('phone:'.$this->limiterDigest($purpose.'-phone', $this->canonicalPhone($request)))
                ->response($response),
        ];
    }

    private function canonicalPhone(Request $request): string
    {
        return trim((string) $request->input('phone', ''));
    }

    private function limiterDigest(string $scope, string $value): string
    {
        return hash_hmac('sha256', $scope.'|'.$value, (string) config('app.key'));
    }
}
