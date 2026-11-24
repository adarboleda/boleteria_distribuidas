<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $evento->nombre }}</title>
    <style>
        body { font-family: sans-serif; max-width: 520px; margin: 1.5rem auto; padding: 0 1rem; text-align: center; }
        .fila { display: flex; justify-content: center; align-items: center; gap: 6px; margin: 6px 0; }
        .fila b { width: 1.5rem; }
        .asiento { width: 40px; height: 40px; border: 0; border-radius: 6px; color: #fff; font-size: 12px; cursor: pointer; }
        .libre { background: #2e9e4f; }
        .reservado { background: #e0a100; cursor: not-allowed; }
        .escenario { background: #444; color: #fff; padding: 6px; margin: 1rem 0; border-radius: 4px; }
        #mensaje { min-height: 1.4rem; color: #c0392b; }
        .leyenda span { margin: 0 .5rem; }
    </style>
</head>
<body>
    <h1>{{ $evento->nombre }}</h1>
    <p>{{ $evento->lugar }} · {{ $evento->fecha->format('d/m/Y H:i') }}</p>

    <p>
        <input id="nombre" placeholder="Tu nombre" autocomplete="off">
    </p>
    <p id="mensaje"></p>

    <div class="escenario">ESCENARIO</div>

    @foreach ($filas as $fila => $asientos)
        <div class="fila">
            <b>{{ $fila }}</b>
            @foreach ($asientos as $asiento)
                <button
                    class="asiento {{ $asiento->estado }}"
                    id="asiento-{{ $asiento->id }}"
                    data-id="{{ $asiento->id }}"
                    data-codigo="{{ $asiento->codigo() }}"
                    title="{{ $asiento->reservado_por ? 'Reservado por ' . $asiento->reservado_por : 'Libre' }}"
                    @disabled($asiento->estado !== 'libre')
                >{{ $asiento->numero }}</button>
            @endforeach
        </div>
    @endforeach

    <p class="leyenda">
        <span style="color:#2e9e4f">■ Libre</span>
        <span style="color:#e0a100">■ Reservado</span>
    </p>
    <p><small>Estado de la conexión: <strong id="estado">iniciando...</strong></small></p>

    <script src="https://cdn.jsdelivr.net/npm/pusher-js@8.4.0/dist/web/pusher.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.19.0/dist/echo.iife.js"></script>
    <script>
        const mensaje = document.getElementById('mensaje');

        // --- Servicio 2: mapa en vivo ---
        const echo = new Echo({
            broadcaster: 'reverb',
            key: '{{ config('broadcasting.connections.reverb.key') }}',
            wsHost: window.location.hostname,
            wsPort: 8080,
            forceTLS: false,
            enabledTransports: ['ws'],
        });

        echo.connector.pusher.connection.bind('state_change', (e) => {
            document.getElementById('estado').textContent = e.current;
        });

        echo.channel('evento.{{ $evento->id }}').listen('AsientoActualizado', (e) => {
            const boton = document.getElementById('asiento-' + e.asientoId);
            boton.className = 'asiento ' + e.estado;
            boton.disabled = e.estado !== 'libre';
            boton.title = e.reservadoPor ? 'Reservado por ' + e.reservadoPor : 'Libre';
        });

        // --- Reservar al hacer clic en un asiento libre ---
        document.querySelectorAll('.asiento').forEach((boton) => {
            boton.addEventListener('click', async () => {
                mensaje.textContent = '';

                const respuesta = await fetch('/asientos/' + boton.dataset.id + '/reservar', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({ nombre: document.getElementById('nombre').value }),
                });

                if (!respuesta.ok) {
                    mensaje.textContent = (await respuesta.json()).error;
                }
            });
        });
    </script>
</body>
</html>
