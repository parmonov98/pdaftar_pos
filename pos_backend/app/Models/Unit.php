<?php

declare(strict_types=1);

namespace Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** dona, kg, litr, karobka — per shop, because a "karobka" is not a fixed size. */
class Unit extends Model
{
    use SoftDeletes;

    protected $fillable = ['shop_id', 'name', 'short_name', 'is_default', 'pdaftar_unit_id'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function label(): string
    {
        return $this->short_name ?: $this->name;
    }
}
