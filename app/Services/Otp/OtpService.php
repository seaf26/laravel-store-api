<?php

namespace App\Services\Otp;

use App\Enums\OtpPurpose;
use App\Exceptions\TooManyOtpRequestsException;
use App\Models\OtpCode;
use App\Services\Sms\SmsSender;
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
        $this->assertWithinRateLimit($phone, $purpose);

        // Any previously issued, still-unused code is invalidated so only the
        // most recent code can ever be redeemed. The rows are marked consumed
        // rather than deleted, because the rate limiter counts issued codes
        // within the window and deleting them would defeat it.
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

        $this->sms->send($phone, sprintf(
            'Your %s verification code is %s. It expires in %d minutes.',
            config('app.name'),
            $code,
            $this->ttlMinutes(),
        ));
    }

    /**
     * Redeem a code. A successful check consumes the code so it is single-use.
     */
    public function verify(string $phone, OtpPurpose $purpose, string $code): bool
    {
        $candidates = OtpCode::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->get();

        foreach ($candidates as $candidate) {
            if (Hash::check($code, $candidate->code_hash)) {
                $candidate->forceFill(['consumed_at' => now()])->save();

                return true;
            }
        }

        return false;
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
}
