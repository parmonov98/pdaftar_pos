<?php

declare(strict_types=1);

namespace Pos\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $fillable = ['code', 'name', 'sign', 'pdaftar_currency_id'];
}
