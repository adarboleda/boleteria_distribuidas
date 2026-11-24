<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se emite cada vez que un asiento cambia de estado (libre <-> reservado).
 * Todos los clientes que miran el evento reciben el cambio por WebSocket.
 */
class AsientoActualizado implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public int $eventoId,
        public int $asientoId,
        public string $estado,
        public ?string $reservadoPor,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('evento.' . $this->eventoId);
    }
}
