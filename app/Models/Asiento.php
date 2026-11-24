<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Asiento extends Model
{
    protected $table = 'asientos';

    protected $fillable = [
        'evento_id',
        'fila',
        'numero',
        'estado',
        'reservado_por',
        'hold_hasta',
    ];

    protected function casts(): array
    {
        return ['hold_hasta' => 'datetime'];
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Evento::class);
    }

    public function codigo(): string
    {
        return $this->fila . $this->numero;
    }
}
