<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

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
