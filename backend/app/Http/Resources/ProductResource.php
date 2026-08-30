<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Decides what leaves the application. The database schema is not the public
 * contract: fields are chosen, named and shaped here, so a column rename does
 * not break every consumer, and `price_minor` / `updated_at` never reach the
 * client.
 *
 * This is also the one and only place money is divided by 100 — the frontend
 * receives major units and carries no money arithmetic.
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'price' => round($this->price_minor / 100, 2),
            'currency' => $this->currency,
            'image_url' => $this->image_url,
            'source_url' => $this->source_url,
            // Explicit, not left to Carbon's implicit JsonSerializable: the
            // format is part of the contract. toJSON() -> toISOString() ->
            // YYYY-MM-DDTHH:mm:ss.SSSSSSZ in UTC; microseconds are always
            // .000000 because the timestamp columns are second-precision.
            'created_at' => $this->created_at->toJSON(),
        ];
    }
}
