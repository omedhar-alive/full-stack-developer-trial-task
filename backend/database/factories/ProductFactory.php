<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // source_url carries a UNIQUE index — a collision fails the insert, not
        // just an assertion. Both parts of the URL come from unique().
        $slug = fake()->unique()->slug();
        $id = fake()->unique()->numberBetween(100_000_000, 999_999_999);

        return [
            'title' => fake()->words(3, true),
            // A sensible EGP range in minor units: ~300 to ~120,000 EGP.
            'price_minor' => fake()->numberBetween(29_900, 12_000_000),
            'currency' => 'EGP', // Jumia is the only target
            'image_url' => 'https://eg.jumia.is/unsafe/fit-in/680x680/filters:fill(white)/product/'
                .fake()->numberBetween(10, 99).'/'.fake()->numerify('#######').'/1.jpg',
            'source_url' => "https://www.jumia.com.eg/{$slug}-{$id}.html",
        ];
    }
}
