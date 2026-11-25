<?php

namespace App\Services;

use App\Events\AsientoActualizado;
use App\Models\Asiento;
use RuntimeException;

class ReservaService
{
    // Reserva temporal de un asiento.
    public function reservar(Asiento $asiento, string $nombre): void
    {
        if ($asiento->estado !== 'libre') {
            throw new RuntimeException("El asiento {$asiento->codigo()} no está disponible");
        }

        $asiento->estado = 'reservado';
        $asiento->reservado_por = $nombre;
        $asiento->hold_hasta = now()->addSeconds(config('boleteria.hold_segundos'));
        $asiento->save();

        $this->notificar($asiento);
    }

    // Liberar los asientos cuyo hold ya venció.
    public function liberarVencidos(): int
    {
        $vencidos = Asiento::where('estado', 'reservado')
            ->where('hold_hasta', '<=', now())
            ->get();

        foreach ($vencidos as $asiento) {
            $asiento->estado = 'libre';
            $asiento->reservado_por = null;
            $asiento->hold_hasta = null;
            $asiento->save();

            $this->notificar($asiento);
        }

        return $vencidos->count();
    }

    private function notificar(Asiento $asiento): void
    {
        AsientoActualizado::dispatch(
            $asiento->evento_id,
            $asiento->id,
            $asiento->estado,
            $asiento->reservado_por,
        );
    }
}
