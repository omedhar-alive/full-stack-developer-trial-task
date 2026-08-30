<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A scraped product row. Thin by design: no scraping logic, no price
 * arithmetic. price_minor is stored and read as an integer count of minor
 * currency units; the divide-by-100 happens in the API Resource (phase 5).
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'source_url',
        'title',
        'image_url',
        'price_minor',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
