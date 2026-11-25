<?php

use App\Models\Asiento;
use App\Services\ReservaService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('reservas:liberar', function (ReservaService $servicio) {
    $liberados = $servicio->liberarVencidos();

    $this->info("Asientos liberados: {$liberados}");
})->purpose('Libera las reservas temporales vencidas');

Schedule::command('reservas:liberar')->everyTenSeconds();

Artisan::command('reservar {asiento} {nombre}', function (ReservaService $servicio, int $asiento, string $nombre) {
    $servicio->reservar(Asiento::findOrFail($asiento), $nombre);

    $this->info('Asiento reservado');
})->purpose('Reserva un asiento por id');
