<?php

namespace Tests\Support;

use App\Services\Sms\SmsSender;

/**
 * Test double that records outbound messages instead of sending them, so tests
 * can read the one-time code the same way a real handset would.
 */
class FakeSmsSender implements SmsSender
{
    /** @var array<int, array{phone: string, message: string}> */
    public array $sent = [];

    public function send(string $phone, string $message): void
    {
        $this->sent[] = ['phone' => $phone, 'message' => $message];
    }

    /**
     * Extract the numeric code from the most recent message sent to a phone.
     */
    public function latestCodeFor(string $phone): ?string
    {
        foreach (array_reverse($this->sent) as $message) {
            if ($message['phone'] === $phone && preg_match('/\b(\d{4,8})\b/', $message['message'], $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    public function countFor(string $phone): int
    {
        return count(array_filter($this->sent, fn ($m) => $m['phone'] === $phone));
    }
}
