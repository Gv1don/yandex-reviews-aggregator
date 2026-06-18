<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    protected $fillable = [
        'organization_id',
        'author',
        'date',
        'text',
        'rating',
        'external_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'rating' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
