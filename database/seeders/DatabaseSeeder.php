<?php

namespace Database\Seeders;

use App\Models\Evento;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
{
    $eventos = [
        ['Concierto de Rock',  'Teatro Nacional', 30,             5,     8],
        ['Obra de Teatro',     'Teatro Sucre',    45,             4,     10],
    ];

    foreach ($eventos as [$nombre, $lugar, $dias, $filas, $porFila]) {
        $evento = Evento::updateOrCreate(
            ['nombre' => $nombre],
            ['lugar' => $lugar, 'fecha' => now()->addDays($dias)],
        );

        foreach (range('A', chr(ord('A') + $filas - 1)) as $fila) {
            for ($numero = 1; $numero <= $porFila; $numero++) {
                $evento->asientos()->firstOrCreate(
                    ['fila' => $fila, 'numero' => $numero],
                    ['estado' => 'libre'],
                );
            }
        }
    }
}
}
