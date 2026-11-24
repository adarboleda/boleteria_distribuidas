<?php

namespace App\Http\Controllers;

use App\Models\Evento;

class EventoController extends Controller
{
    public function mapa(Evento $evento)
    {
        $asientos = $evento->asientos()->orderBy('fila')->orderBy('numero')->get();

        return view('mapa', [
            'evento' => $evento,
            'filas' => $asientos->groupBy('fila'),
        ]);
    }
}
