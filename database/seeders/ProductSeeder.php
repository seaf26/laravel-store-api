<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Seed a small, realistic catalogue for manual/Postman testing.
     * Includes one out-of-stock item so the notify-me / back-in-stock
     * flow has something to subscribe to.
     */
    public function run(): void
    {
        if (Product::query()->exists()) {
            return;
        }

        $catalogue = [
            ['title' => 'Wireless Mouse', 'price' => 12.99, 'stock' => 40, 'description' => 'Ergonomic wireless mouse with USB receiver.'],
            ['title' => 'Mechanical Keyboard', 'price' => 59.99, 'stock' => 15, 'description' => 'Full-size mechanical keyboard, blue switches.'],
            ['title' => '27" Monitor', 'price' => 189.00, 'stock' => 8, 'description' => '27-inch 1440p IPS monitor, 75Hz.'],
            ['title' => 'USB-C Hub', 'price' => 24.50, 'stock' => 60, 'description' => '7-in-1 USB-C hub with HDMI and card reader.'],
            ['title' => 'Laptop Stand', 'price' => 19.99, 'stock' => 25, 'description' => 'Adjustable aluminum laptop stand.'],
            ['title' => 'Noise Cancelling Headphones', 'price' => 149.99, 'stock' => 0, 'description' => 'Over-ear ANC headphones, 30-hour battery.'],
        ];

        foreach ($catalogue as $product) {
            Product::factory()->create($product);
        }
    }
}
