<?php

namespace App\Http\Concerns;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait SortsQueries
{
    /**
     * Resolve a validated (column, direction) pair from the request.
     *
     * The column is checked against a whitelist so a caller can never sort by
     * (or inject) an arbitrary column; an unknown value is a 422.
     *
     * @param  array<int, string>  $allowed
     * @return array{0: string, 1: string}
     */
    protected function resolveSort(Request $request, array $allowed, string $default): array
    {
        $column = (string) $request->query('sort', $default);

        if (! in_array($column, $allowed, true)) {
            throw ValidationException::withMessages([
                'sort' => ['Invalid sort field. Allowed: '.implode(', ', $allowed).'.'],
            ]);
        }

        $direction = strtolower((string) $request->query('direction', 'desc'));

        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw ValidationException::withMessages([
                'direction' => ['Sort direction must be asc or desc.'],
            ]);
        }

        return [$column, $direction];
    }

    protected function perPage(Request $request): int
    {
        return (int) min(max((int) $request->integer('per_page', 15), 1), 100);
    }
}
