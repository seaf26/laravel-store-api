<?php

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\Notification;

class NotificationDeduplicator
{
    public function sendOnce(User $recipient, Notification $notification, string $logicalKey): bool
    {
        $notification->id = $this->idFor($recipient, $notification, $logicalKey);

        if ($recipient->notifications()->whereKey($notification->id)->exists()) {
            return false;
        }

        try {
            $recipient->notify($notification);

            return true;
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return false;
            }

            throw $exception;
        }
    }

    public function idFor(User $recipient, Notification $notification, string $logicalKey): string
    {
        $hex = substr(hash('sha256', implode('|', [
            $notification::class,
            $recipient->getMorphClass(),
            (string) $recipient->getKey(),
            $logicalKey,
        ])), 0, 32);

        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        if ($state === '23505' || ($state === '23000' && $driverCode === 1062)) {
            return true;
        }

        return $state === '23000'
            && $driverCode === 19
            && str_contains(
                strtolower((string) ($exception->errorInfo[2] ?? $exception->getMessage())),
                'unique constraint failed: notifications.id',
            );
    }
}
