<?php

namespace Database\Seeders;

use App\Models\Evento;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $evento = Evento::updateOrCreate(
            ['nombre' => 'Concierto de Rock'],
            ['lugar' => 'Teatro Nacional', 'fecha' => now()->addDays(30)],
        );

        // Mapa de 5 filas (A-E) x 8 asientos
        foreach (['A', 'B', 'C', 'D', 'E'] as $fila) {
            for ($numero = 1; $numero <= 8; $numero++) {
                $evento->asientos()->firstOrCreate(
                    ['fila' => $fila, 'numero' => $numero],
                    ['estado' => 'libre'],
                );
            }
        }
    }
}
