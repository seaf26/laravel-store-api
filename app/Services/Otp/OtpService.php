<?php

namespace App\Services\Otp;

use App\Enums\OtpPurpose;
use App\Exceptions\TooManyOtpRequestsException;
use App\Models\OtpCode;
use App\Services\Sms\SmsSender;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class OtpService
{
    public function __construct(private readonly SmsSender $sms) {}

    /**
     * Generate a one-time code, store only its hash, and deliver it by SMS.
     *
     * The plain code is never returned to the caller so it cannot leak into an
     * API response — the SMS gateway is the only delivery channel.
     *
     * @throws TooManyOtpRequestsException
     */
    public function issue(string $phone, OtpPurpose $purpose): void
    {
        try {
            $code = Cache::lock($this->issueLockKey($phone, $purpose), 5)
                ->block(1, fn () => DB::transaction(function () use ($phone, $purpose): string {
                    $this->assertWithinRateLimit($phone, $purpose);

                    // Mark older codes consumed rather than deleting them, so
                    // they continue to count against the issue window.
                    OtpCode::query()
                        ->where('phone', $phone)
                        ->where('purpose', $purpose)
                        ->whereNull('consumed_at')
                        ->update(['consumed_at' => now()]);

                    $code = $this->generateCode();

                    OtpCode::create([
                        'phone' => $phone,
                        'code_hash' => Hash::make($code),
                        'purpose' => $purpose,
                        'expires_at' => now()->addMinutes($this->ttlMinutes()),
                    ]);

                    return $code;
                }, 3));
        } catch (LockTimeoutException) {
            throw new TooManyOtpRequestsException;
        }

        // The database commit and distributed lock release both happen before
        // the external delivery side effect.
        $this->sms->send($phone, sprintf(
            'Your %s %s code is %s. It expires in %d minutes.',
            config('app.name'),
            $purpose->smsLabel(),
            $code,
            $this->ttlMinutes(),
        ));
    }

    /**
     * Redeem a code. A successful check consumes the code so it is single-use.
     */
    public function verify(string $phone, OtpPurpose $purpose, string $code): bool
    {
        $candidate = OtpCode::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $candidate || ! Hash::check($code, $candidate->code_hash)) {
            return false;
        }

        $claimedAt = now();

        return OtpCode::query()
            ->whereKey($candidate->getKey())
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $claimedAt)
            ->update(['consumed_at' => $claimedAt]) === 1;
    }

    /**
     * Limit how many codes a single phone number can request per window. This
     * is enforced per phone (not per IP) because the phone number is what an
     * attacker would be flooding, and it is authoritative across app servers.
     *
     * @throws TooManyOtpRequestsException
     */
    private function assertWithinRateLimit(string $phone, OtpPurpose $purpose): void
    {
        $recent = OtpCode::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose)
            ->where('created_at', '>', now()->subMinutes($this->ttlMinutes()))
            ->count();

        if ($recent >= (int) config('store.otp.max_per_window')) {
            throw new TooManyOtpRequestsException;
        }
    }

    private function generateCode(): string
    {
        $length = (int) config('store.otp.length');
        $max = (10 ** $length) - 1;

        // random_int() is cryptographically secure.
        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    private function ttlMinutes(): int
    {
        return (int) config('store.otp.ttl_minutes');
    }

    private function issueLockKey(string $phone, OtpPurpose $purpose): string
    {
        $digest = hash_hmac(
            'sha256',
            $purpose->value.'|'.$phone,
            (string) config('app.key'),
        );

        return 'otp-issue:'.$digest;
    }
}
