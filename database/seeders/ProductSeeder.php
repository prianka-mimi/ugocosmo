<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Profile;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = $this->profiles();
        $digital = ProductCategory::where('product_type', 'digital')->first();
        $physical = ProductCategory::where('product_type', 'physical')->first();

        $products = [
            // Alice – design
            ['seller' => 'alice', 'title' => 'Logo Design Package',         'description' => 'Professional logo with 3 concepts and unlimited revisions.',    'price' => 49.99,  'compare_at_price' => 79.99,  'category_id' => $digital?->id,  'product_type' => 'digital',  'stock_quantity' => null, 'condition' => 'new'],
            ['seller' => 'alice', 'title' => 'Complete Brand Identity Kit',  'description' => 'Logo, colour palette, typography, business card & letterhead.',  'price' => 149.99, 'compare_at_price' => 199.99, 'category_id' => $digital?->id,  'product_type' => 'digital',  'stock_quantity' => null, 'condition' => 'new'],
            ['seller' => 'alice', 'title' => 'Social Media Template Pack',   'description' => '50 editable Canva templates for Instagram, Facebook & LinkedIn.', 'price' => 29.99,  'compare_at_price' => 49.99,  'category_id' => $digital?->id,  'product_type' => 'digital',  'stock_quantity' => null, 'condition' => 'new'],
            // Eva – photography
            ['seller' => 'eva',   'title' => 'Lightroom Preset Pack – Portrait', 'description' => '20 professional Lightroom presets for portrait photography.', 'price' => 24.99,  'compare_at_price' => 39.99,  'category_id' => $digital?->id,  'product_type' => 'digital',  'stock_quantity' => null, 'condition' => 'new'],
            ['seller' => 'eva',   'title' => 'Printed Photo Book (30 pages)',    'description' => 'Premium lay-flat printed photo book, A4 size.',               'price' => 59.99,  'compare_at_price' => null,   'category_id' => $physical?->id, 'product_type' => 'physical', 'stock_quantity' => 30,   'condition' => 'new', 'weight' => 20.0, 'package_dimensions' => ['length' => 12, 'width' => 9, 'height' => 1, 'unit' => 'inch']],
            ['seller' => 'eva',   'title' => 'Camera Strap – Leather',           'description' => 'Handcrafted genuine leather camera strap, universal fit.',    'price' => 39.99,  'compare_at_price' => null,   'category_id' => $physical?->id, 'product_type' => 'physical', 'stock_quantity' => 50,   'condition' => 'new', 'weight' => 8.0,  'package_dimensions' => ['length' => 10, 'width' => 4, 'height' => 2, 'unit' => 'inch']],
            // Henry – music
            ['seller' => 'henry', 'title' => 'Royalty-Free Beat Pack (10 beats)', 'description' => '10 exclusive trap/R&B beats, WAV + MP3 included.',          'price' => 79.99,  'compare_at_price' => 120.00, 'category_id' => $digital?->id,  'product_type' => 'digital',  'stock_quantity' => null, 'condition' => 'new'],
            ['seller' => 'henry', 'title' => 'Mixing & Mastering Guide (eBook)', 'description' => 'Step-by-step guide to professional mixing & mastering.',     'price' => 19.99,  'compare_at_price' => 29.99,  'category_id' => $digital?->id,  'product_type' => 'digital',  'stock_quantity' => null, 'condition' => 'new'],
            // Jack – e-commerce
            ['seller' => 'jack',  'title' => 'Wireless Earbuds Pro',             'description' => 'True wireless earbuds with ANC, 30hr battery life.',         'price' => 89.99,  'compare_at_price' => 129.99, 'category_id' => $physical?->id, 'product_type' => 'physical', 'stock_quantity' => 100,  'condition' => 'new', 'weight' => 6.0,  'package_dimensions' => ['length' => 4, 'width' => 2, 'height' => 2, 'unit' => 'inch']],
            ['seller' => 'jack',  'title' => 'Portable Phone Stand (3-pack)',     'description' => 'Adjustable aluminium phone stand, compatible with all phones.', 'price' => 19.99, 'compare_at_price' => 29.99,  'category_id' => $physical?->id, 'product_type' => 'physical', 'stock_quantity' => 200,  'condition' => 'new', 'weight' => 14.0, 'package_dimensions' => ['length' => 6, 'width' => 5, 'height' => 2, 'unit' => 'inch']],
            // Isla – interior
            ['seller' => 'isla',  'title' => 'Interior Design Mood Board Kit',   'description' => 'Digital mood board templates for residential projects.',      'price' => 34.99,  'compare_at_price' => 49.99,  'category_id' => $digital?->id,  'product_type' => 'digital',  'stock_quantity' => null, 'condition' => 'new'],
            // Grace – fitness
            ['seller' => 'grace', 'title' => '12-Week Home Workout Plan',        'description' => 'PDF workout plan with video links, no equipment needed.',     'price' => 27.99,  'compare_at_price' => 39.99,  'category_id' => $digital?->id,  'product_type' => 'digital',  'stock_quantity' => null, 'condition' => 'new'],
        ];

        foreach ($products as $data) {
            $profile = $profiles[$data['seller']] ?? null;
            if (! $profile) {
                continue;
            }
            unset($data['seller']);
            Product::firstOrCreate(['title' => $data['title'], 'seller_id' => $profile->id], array_merge($data, [
                'seller_id' => $profile->id,
                'is_active' => true,
            ]));
        }
    }

    private function profiles(): array
    {
        $map = [];
        foreach (['alice', 'bob', 'carol', 'david', 'eva', 'frank', 'grace', 'henry', 'isla', 'jack'] as $username) {
            $p = Profile::whereHas('user', fn ($q) => $q->where('username', $username))->first();
            if ($p) {
                $map[$username] = $p;
            }
        }

        return $map;
    }
}
