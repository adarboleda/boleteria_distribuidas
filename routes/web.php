<?php

use App\Http\Controllers\AsientoController;
use App\Http\Controllers\EventoController;
use App\Models\Evento;
use Illuminate\Support\Facades\Route;

// Raíz: lleva al primer evento
Route::get('/', function () {
    $evento = Evento::orderBy('id')->first();

    abort_if(! $evento, 404, 'No hay eventos. Ejecuta: php artisan migrate --seed');

    return redirect("/eventos/{$evento->id}");
});

Route::get('/eventos/{evento}', [EventoController::class, 'mapa']);
Route::post('/asientos/{asiento}/reservar', [AsientoController::class, 'reservar']);
