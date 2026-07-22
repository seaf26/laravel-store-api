<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['disk', 'path', 'attempts', 'last_error', 'last_attempted_at'])]
class PendingFileDeletion extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'last_attempted_at' => 'datetime',
        ];
    }
}
