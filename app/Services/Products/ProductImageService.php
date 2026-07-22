<?php

namespace App\Services\Products;

use App\Models\PendingFileDeletion;
use App\Models\Product;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProductImageService
{
    private const DISK = 'public';

    public function __construct(private readonly FilesystemManager $filesystems) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, UploadedFile $image): Product
    {
        $newPath = $this->store($image);

        try {
            return DB::transaction(
                fn (): Product => Product::create([...$attributes, 'image_path' => $newPath]),
                3,
            );
        } catch (Throwable $exception) {
            $this->compensate($newPath);

            throw $exception;
        }
    }

    /** @param array<string, mixed> $attributes */
    public function update(Product $product, array $attributes, ?UploadedFile $image): Product
    {
        if ($image === null) {
            $product->update($attributes);

            return $product->refresh();
        }

        $newPath = $this->store($image);
        $oldPath = $product->image_path;

        try {
            DB::transaction(function () use ($product, $attributes, $newPath, $oldPath): void {
                $product->update([...$attributes, 'image_path' => $newPath]);

                if (is_string($oldPath) && $oldPath !== '' && $oldPath !== $newPath) {
                    $this->schedule($oldPath);
                }
            }, 3);
        } catch (Throwable $exception) {
            $this->compensate($newPath);

            throw $exception;
        }

        if (is_string($oldPath) && $oldPath !== '' && $oldPath !== $newPath) {
            $pending = PendingFileDeletion::where('disk', self::DISK)->where('path', $oldPath)->first();

            if ($pending) {
                $this->attempt($pending);
            }
        }

        return $product->refresh();
    }

    public function delete(Product $product): void
    {
        $oldPath = $product->image_path;

        DB::transaction(function () use ($product, $oldPath): void {
            if (is_string($oldPath) && $oldPath !== '') {
                $this->schedule($oldPath);
            }

            $product->delete();
        }, 3);

        if (is_string($oldPath) && $oldPath !== '') {
            $pending = PendingFileDeletion::where('disk', self::DISK)->where('path', $oldPath)->first();

            if ($pending) {
                $this->attempt($pending);
            }
        }
    }

    public function cleanupPending(int $limit = 100): int
    {
        $completed = 0;

        PendingFileDeletion::query()
            ->oldest('id')
            ->limit(max(1, min($limit, 1000)))
            ->get()
            ->each(function (PendingFileDeletion $pending) use (&$completed): void {
                if ($this->attempt($pending)) {
                    $completed++;
                }
            });

        return $completed;
    }

    private function store(UploadedFile $image): string
    {
        $path = $this->filesystems->disk(self::DISK)->putFile('products', $image);

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('The product image could not be stored.');
        }

        return $path;
    }

    private function compensate(string $path): void
    {
        try {
            $this->attempt($this->schedule($path));
        } catch (Throwable $trackingException) {
            report($trackingException);

            try {
                $disk = $this->filesystems->disk(self::DISK);

                if ($disk->exists($path)) {
                    $disk->delete($path);
                }
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }
        }
    }

    private function schedule(string $path): PendingFileDeletion
    {
        $attributes = [
            'disk' => self::DISK,
            'path' => $path,
        ];

        PendingFileDeletion::query()->insertOrIgnore([
            ...$attributes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return PendingFileDeletion::where($attributes)->firstOrFail();
    }

    private function attempt(PendingFileDeletion $pending): bool
    {
        $disk = $this->filesystems->disk($pending->disk);

        try {
            if (! $disk->exists($pending->path)
                || $disk->delete($pending->path)
                || ! $disk->exists($pending->path)) {
                $pending->delete();

                return true;
            }

            $message = 'The filesystem delete operation returned false.';
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
        }

        $pending->forceFill([
            'attempts' => $pending->attempts + 1,
            'last_error' => Str::limit($message, 2000, ''),
            'last_attempted_at' => now(),
        ])->save();

        return false;
    }
}
