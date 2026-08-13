<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookShowResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        return [
            'title' => $this->title,
            'author' => $this->author,
            'isbn' => $this->isbn,
            'description' => $this->description,
            'published_date' => $this->published_date?->format('Y-m-d'),
            'genres' => $this->genres->pluck('name'),
            'reviews' => BookReviewResource::collection($this->reviews),
        ];
    }
}
