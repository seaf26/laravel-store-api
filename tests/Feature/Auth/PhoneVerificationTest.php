<?php

namespace Tests\Feature\Auth;

use App\Enums\OtpPurpose;
use App\Exceptions\TooManyOtpRequestsException;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Otp\OtpService;
use App\Services\Sms\SmsSender;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

class PhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_sends_a_verification_code(): void
    {
        $sms = $this->fakeSms();

        $this->postJson('/api/auth/register', [
            'name' => 'Sara Ali',
            'phone' => '+201234567890',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $this->assertSame(1, $sms->countFor('+201234567890'));
        // The code itself is stored only as a hash.
        $this->assertNotSame($sms->latestCodeFor('+201234567890'), OtpCode::first()->code_hash);
    }

    public function test_registration_uses_the_verification_issue_limiter(): void
    {
        $this->fakeSms();
        $payload = [
            'name' => 'Sara Ali',
            'phone' => '+201234567890',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $this->postJson('/api/auth/register', $payload)->assertCreated();
        $this->postJson('/api/auth/register', $payload)->assertStatus(422);
        $this->postJson('/api/auth/register', $payload)->assertStatus(422);

        $this->assertStableThrottleResponse(
            $this->postJson('/api/auth/register', $payload),
            3,
        );
    }

    public function test_a_user_can_verify_their_phone_and_then_log_in(): void
    {
        $sms = $this->fakeSms();

        $this->postJson('/api/auth/register', [
            'name' => 'Sara Ali',
            'phone' => '+201234567890',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        // Login is blocked until the phone is verified.
        $this->postJson('/api/auth/login', [
            'phone' => '+201234567890',
            'password' => 'password123',
        ])->assertStatus(403);

        $this->postJson('/api/auth/verify-phone', [
            'phone' => '+201234567890',
            'code' => $sms->latestCodeFor('+201234567890'),
        ])->assertOk();

        $this->assertNotNull(User::first()->phone_verified_at);

        $this->postJson('/api/auth/login', [
            'phone' => '+201234567890',
            'password' => 'password123',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_the_verification_response_never_exposes_the_code(): void
    {
        $this->fakeSms();
        User::factory()->unverified()->create(['phone' => '+201234567890']);

        $response = $this->postJson('/api/auth/verify-phone/request', ['phone' => '+201234567890']);

        $response->assertOk();
        $this->assertStringNotContainsString(
            (string) OtpCode::first()->code_hash,
            $response->getContent()
        );
        $response->assertJsonMissingPath('code');
    }

    public function test_an_incorrect_code_is_rejected(): void
    {
        $this->fakeSms();
        User::factory()->unverified()->create(['phone' => '+201234567890']);
        $this->postJson('/api/auth/verify-phone/request', ['phone' => '+201234567890'])->assertOk();

        $this->postJson('/api/auth/verify-phone', [
            'phone' => '+201234567890',
            'code' => '000000',
        ])->assertStatus(422)->assertJsonValidationErrors('code');

        $this->assertNull(User::first()->phone_verified_at);
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $sms = $this->fakeSms();
        User::factory()->unverified()->create(['phone' => '+201234567890']);
        $this->postJson('/api/auth/verify-phone/request', ['phone' => '+201234567890'])->assertOk();
        $code = $sms->latestCodeFor('+201234567890');

        $this->travel(config('store.otp.ttl_minutes') + 1)->minutes();

        $this->postJson('/api/auth/verify-phone', [
            'phone' => '+201234567890',
            'code' => $code,
        ])->assertStatus(422);
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $sms = $this->fakeSms();
        $user = User::factory()->unverified()->create(['phone' => '+201234567890']);
        $this->postJson('/api/auth/verify-phone/request', ['phone' => '+201234567890'])->assertOk();
        $code = $sms->latestCodeFor('+201234567890');

        $this->postJson('/api/auth/verify-phone', ['phone' => '+201234567890', 'code' => $code])->assertOk();

        // Re-submitting the consumed code fails.
        $this->postJson('/api/auth/verify-phone', ['phone' => '+201234567890', 'code' => $code])
            ->assertStatus(422);
    }

    public function test_a_code_claim_lost_to_another_request_is_rejected(): void
    {
        OtpCode::create([
            'phone' => '+201234567890',
            'code_hash' => 'stored-code-hash',
            'purpose' => OtpPurpose::PhoneVerification,
            'expires_at' => now()->addMinutes(10),
        ]);

        Hash::shouldReceive('check')
            ->once()
            ->with('123456', 'stored-code-hash')
            ->andReturnUsing(function (): bool {
                OtpCode::query()->update(['consumed_at' => now()]);

                return true;
            });

        $this->assertFalse(app(OtpService::class)->verify(
            '+201234567890',
            OtpPurpose::PhoneVerification,
            '123456',
        ));
    }

    public function test_requesting_a_new_code_invalidates_the_previous_one(): void
    {
        $sms = $this->fakeSms();
        User::factory()->unverified()->create(['phone' => '+201234567890']);

        $this->postJson('/api/auth/verify-phone/request', ['phone' => '+201234567890'])->assertOk();
        $firstCode = $sms->latestCodeFor('+201234567890');

        $this->postJson('/api/auth/verify-phone/request', ['phone' => '+201234567890'])->assertOk();

        $this->postJson('/api/auth/verify-phone', ['phone' => '+201234567890', 'code' => $firstCode])
            ->assertStatus(422);
    }

    public function test_code_requests_are_rate_limited_per_phone(): void
    {
        $this->fakeSms();
        User::factory()->unverified()->create(['phone' => '+201234567890']);

        $max = (int) config('store.otp.max_per_window');

        for ($i = 0; $i < $max; $i++) {
            $this->postJson('/api/auth/verify-phone/request', ['phone' => '+201234567890'])->assertOk();
        }

        $response = $this->postJson('/api/auth/verify-phone/request', ['phone' => '+201234567890']);

        $this->assertStableThrottleResponse($response, 3);
    }

    public function test_code_requests_are_limited_to_twenty_attempts_per_source(): void
    {
        $this->fakeSms();

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $phone = '+20100000'.str_pad((string) $attempt, 4, '0', STR_PAD_LEFT);

            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
                ->postJson('/api/auth/verify-phone/request', ['phone' => $phone])
                ->assertOk();
        }

        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->postJson('/api/auth/verify-phone/request', ['phone' => '+201000009999']);

        $this->assertStableThrottleResponse($response, 20);
    }

    public function test_verification_attempts_are_limited_per_source_and_phone(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $phone = '+20110000'.str_pad((string) $attempt, 4, '0', STR_PAD_LEFT);

            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.30'])
                ->postJson('/api/auth/verify-phone', ['phone' => $phone, 'code' => '000000'])
                ->assertStatus(422);
        }

        $sourceLimited = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.30'])
            ->postJson('/api/auth/verify-phone', [
                'phone' => '+201100009999',
                'code' => '000000',
            ]);

        $this->assertStableThrottleResponse($sourceLimited, 5);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$attempt}"])
                ->postJson('/api/auth/verify-phone', [
                    'phone' => ' +201234567890 ',
                    'code' => '000000',
                ])->assertStatus(422);
        }

        $phoneLimited = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->postJson('/api/auth/verify-phone', [
                'phone' => '+201234567890',
                'code' => '000000',
            ]);

        $this->assertStableThrottleResponse($phoneLimited, 5);
    }

    public function test_requesting_a_code_does_not_reveal_whether_the_phone_exists(): void
    {
        $sms = $this->fakeSms();

        $unknown = $this->postJson('/api/auth/verify-phone/request', ['phone' => '+209999999999']);
        $unknown->assertOk();

        // No message is actually delivered for an unknown number.
        $this->assertSame(0, $sms->countFor('+209999999999'));
        $this->assertSame(0, OtpCode::where('purpose', OtpPurpose::PhoneVerification)->count());
    }

    public function test_known_and_unknown_phones_receive_the_same_throttled_issue_response(): void
    {
        $this->freezeTime();
        $this->fakeSms();
        User::factory()->unverified()->create(['phone' => '+201234567890']);

        foreach (['+201234567890', '+209999999999'] as $phone) {
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $this->postJson('/api/auth/verify-phone/request', ['phone' => $phone])->assertOk();
            }
        }

        $known = $this->postJson('/api/auth/verify-phone/request', ['phone' => '+201234567890']);
        $unknown = $this->postJson('/api/auth/verify-phone/request', ['phone' => '+209999999999']);

        $this->assertSame($known->getContent(), $unknown->getContent());
        $this->assertSame($known->headers->get('Retry-After'), $unknown->headers->get('Retry-After'));
        $this->assertSame($known->headers->get('X-RateLimit-Limit'), $unknown->headers->get('X-RateLimit-Limit'));
        $this->assertStableThrottleResponse($known, 3);
        $this->assertStableThrottleResponse($unknown, 3);
    }

    public function test_the_internal_issue_guard_does_not_reveal_a_known_phone(): void
    {
        $this->fakeSms();
        User::factory()->unverified()->create(['phone' => '+201234567890']);

        for ($attempt = 1; $attempt <= (int) config('store.otp.max_per_window'); $attempt++) {
            OtpCode::create([
                'phone' => '+201234567890',
                'code_hash' => 'stored-code-hash',
                'purpose' => OtpPurpose::PhoneVerification,
                'expires_at' => now()->addMinutes(10),
            ]);
        }

        $known = $this->postJson('/api/auth/verify-phone/request', ['phone' => '+201234567890']);
        $unknown = $this->postJson('/api/auth/verify-phone/request', ['phone' => '+209999999999']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->getContent(), $unknown->getContent());
    }

    public function test_issue_lock_timeouts_use_the_existing_otp_throttle_exception(): void
    {
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')
            ->once()
            ->andThrow(new LockTimeoutException);

        Cache::shouldReceive('lock')
            ->once()
            ->withArgs(fn (string $key, int $seconds): bool => $seconds === 5
                && str_starts_with($key, 'otp-issue:')
                && ! str_contains($key, '+201234567890'))
            ->andReturn($lock);

        $this->expectException(TooManyOtpRequestsException::class);

        app(OtpService::class)->issue('+201234567890', OtpPurpose::PhoneVerification);
    }

    public function test_sms_delivery_happens_after_commit_and_lock_release(): void
    {
        $phone = '+201234567890';
        $purpose = OtpPurpose::PhoneVerification;
        $lockKey = 'otp-issue:'.hash_hmac(
            'sha256',
            $purpose->value.'|'.$phone,
            (string) config('app.key'),
        );
        $initialTransactionLevel = DB::transactionLevel();
        $sms = new class($lockKey) implements SmsSender
        {
            public ?int $transactionLevel = null;

            public ?bool $lockWasAvailable = null;

            public function __construct(private readonly string $lockKey) {}

            public function send(string $phone, string $message): void
            {
                $this->transactionLevel = DB::transactionLevel();
                $lock = Cache::lock($this->lockKey, 5);
                $this->lockWasAvailable = $lock->get();

                if ($this->lockWasAvailable) {
                    $lock->release();
                }
            }
        };

        (new OtpService($sms))->issue($phone, $purpose);

        $this->assertSame($initialTransactionLevel, $sms->transactionLevel);
        $this->assertTrue($sms->lockWasAvailable);
    }

    private function assertStableThrottleResponse($response, int $limit): void
    {
        $response->assertStatus(429)
            ->assertExactJson([
                'message' => 'Too many attempts. Please try again later.',
            ])
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', (string) $limit)
            ->assertHeader('X-RateLimit-Remaining', '0');
    }
}
