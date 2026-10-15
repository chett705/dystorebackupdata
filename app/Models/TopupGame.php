<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TopupGame extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'api_game_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function packages(): HasMany
    {
        return $this->hasMany(TopupPackage::class);
    }
}
