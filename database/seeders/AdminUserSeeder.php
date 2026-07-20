<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Create (or refresh) the administrator account used to manage the store.
     * Credentials come from config/store.php (ADMIN_SEED_* env vars).
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['phone' => config('store.admin.phone')],
            [
                'name' => 'Store Admin',
                'password' => Hash::make(config('store.admin.password')),
                'is_admin' => true,
                'phone_verified_at' => now(),
            ],
        );
    }
}
