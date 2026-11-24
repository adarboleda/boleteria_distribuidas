<?php

namespace App\Http\Controllers;

use App\Models\Asiento;
use App\Services\ReservaService;
use Illuminate\Http\Request;
use RuntimeException;

class AsientoController extends Controller
{
    public function reservar(Request $request, Asiento $asiento, ReservaService $servicio)
    {
        $nombre = trim((string) $request->input('nombre'));

        if ($nombre === '') {
            return response()->json(['error' => 'Escribe tu nombre primero'], 422);
        }

        try {
            $servicio->reservar($asiento, $nombre);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['ok' => true]);
    }
}
