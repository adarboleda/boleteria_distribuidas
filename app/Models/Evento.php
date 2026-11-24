<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Evento extends Model
{
    protected $fillable = ['nombre', 'lugar', 'fecha'];

    protected function casts(): array
    {
        return ['fecha' => 'datetime'];
    }

    public function asientos(): HasMany
    {
        return $this->hasMany(Asiento::class);
    }
}
