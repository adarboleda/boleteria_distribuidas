<?php

return [
    // Duración de la reserva temporal (hold). El requisito pide 5 minutos (300 s).
    // En .env se puede bajar (p. ej. 30) para probar la liberación automática.
    'hold_segundos' => (int) env('HOLD_SEGUNDOS', 300),
];
