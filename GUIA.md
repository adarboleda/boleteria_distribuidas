# Guía completa — Boletería en línea (servicios 2 y 3)

Esta guía explica **qué hace la aplicación, de qué se encarga cada pieza (Reverb, Echo, el scheduler, PostgreSQL, Docker…), qué hace el código paso por paso, cómo levantarla y dónde modificar cada cosa.**

## Índice

1. Qué hace la aplicación
2. Glosario rápido
3. Las piezas del sistema y de qué se encarga cada una
4. Estructura de carpetas
5. Cómo levantar y usar la aplicación
6. Recorrido paso a paso de cada flujo
7. El código explicado archivo por archivo
8. Dónde modificar cada cosa
9. Comandos útiles
10. Solución de problemas
11. Limitaciones actuales y qué sigue

---

## 1. Qué hace la aplicación

Es una página web con el mapa de asientos de un evento (5 filas × 8 asientos = 40).

1. Escribes tu nombre y haces clic en un asiento **verde** (libre).
2. El asiento queda **reservado** (amarillo) durante `HOLD_SEGUNDOS` segundos. A esa reserva temporal se le llama *hold*.
3. Todos los navegadores que tengan abierta la página ven el asiento ponerse amarillo **al instante**, sin recargar (**servicio 2: mapa en tiempo real**).
4. Si el hold vence sin pago, el sistema libera el asiento solo y todos los navegadores lo ven volver a verde (**servicio 3: liberación automática**).

Para que existan cambios de estado hubo que incluir una **reserva mínima**. Esa reserva todavía **no controla la concurrencia** (ver sección 11).

---

## 2. Glosario rápido

| Término | Significado |
|---|---|
| **HTTP** | Protocolo normal de la web: el navegador pide, el servidor responde y la conexión se cierra. El servidor no puede avisar por iniciativa propia. |
| **WebSocket** | Conexión que se queda abierta entre navegador y servidor. Permite que el servidor **empuje** datos al navegador cuando quiera. Es lo que hace posible el tiempo real. |
| **Evento (event)** | Un objeto que representa "algo ocurrió" (aquí: un asiento cambió de estado). |
| **Broadcast** | Enviar un evento a todos los clientes suscritos a un canal. |
| **Canal (channel)** | Una "sala" a la que los navegadores se suscriben. Aquí hay un canal por evento: `evento.1`. |
| **Hold** | Reserva temporal de un asiento, con fecha de vencimiento (`hold_hasta`). |
| **Scheduler** | Programador de tareas: ejecuta comandos cada cierto tiempo. |
| **Migración** | Archivo PHP que crea o modifica tablas de la base de datos. |
| **Seeder** | Archivo PHP que inserta datos iniciales. |
| **Modelo (Eloquent)** | Clase PHP que representa una tabla: `Asiento` ↔ tabla `asientos`. |
| **Servicio** | Clase con la lógica de negocio (`ReservaService`). Los controladores solo la llaman. |
| **Controlador** | Clase que recibe la petición web, llama al servicio y devuelve una respuesta. |
| **Blade** | Motor de plantillas de Laravel (`.blade.php`): HTML con partes de PHP. |
| **Contenedor Docker** | Un "mini computador" aislado que corre un programa (la app, la BD…). |

---

## 3. Las piezas del sistema y de qué se encarga cada una

```
 ┌────────────────────────── Tu computador (Docker) ──────────────────────────┐
 │                                                                            │
 │  Navegador ──HTTP :8000──►  [app]  Laravel (artisan serve)                 │
 │      ▲                        │  │                                         │
 │      │                        │  └──SQL──►  [db] PostgreSQL                │
 │      │                        │                                            │
 │      │                        └──HTTP interno──►  [reverb] :8080           │
 │      └──────────WebSocket :8080──────────────────────┘                     │
 │                                                                            │
 │  [scheduler]  cada 10 s ejecuta  reservas:liberar  ──SQL──► [db]           │
 │                                   └──HTTP interno──► [reverb]              │
 └────────────────────────────────────────────────────────────────────────────┘
```

### 3.1 `app` — el servidor web (Laravel)
- Corre `php artisan serve --host=0.0.0.0 --port=8000`, el servidor de desarrollo de PHP.
- Se encarga de: mostrar la página del mapa, recibir las reservas, aplicar las reglas de negocio, guardar en la base de datos y **emitir el evento** de cambio de asiento.
- No mantiene las conexiones de tiempo real; eso lo hace Reverb.
- Es un servidor **de desarrollo**, no de producción.

### 3.2 `db` — PostgreSQL
- Base de datos relacional. Es la **fuente de verdad**: ahí viven los eventos y el estado de cada asiento.
- Datos de acceso (en `docker-compose.yml` y `.env`): base `boleteria`, usuario `boleteria`, clave `secret`.
- Dentro de la red de Docker se llama `db`, por eso `DB_HOST=db` en `.env`.

### 3.3 `reverb` — el servidor de WebSockets
**Qué es:** Laravel Reverb es un servidor de WebSockets escrito en PHP, oficial de Laravel. Habla el mismo protocolo que el servicio comercial *Pusher*, por eso se usan librerías de Pusher para conectarse a él.

**De qué se encarga:** mantener abiertas las conexiones de los navegadores y **repartir los eventos** a quienes estén suscritos al canal correspondiente.

**Cómo trabaja en esta app:**
1. Cada navegador abre una conexión WebSocket a `ws://localhost:8080/app/<clave>` y se suscribe al canal `evento.1`.
2. Cuando Laravel (`app` o `scheduler`) hace `AsientoActualizado::dispatch(...)`, manda el evento a Reverb mediante una petición **HTTP interna** a `reverb:8080`, firmada con la clave y el secreto (`REVERB_APP_KEY` y `REVERB_APP_SECRET`).
3. Reverb reenvía ese evento por los WebSockets a todos los navegadores suscritos al canal.

Por eso hay **dos direcciones distintas** para Reverb:
- `REVERB_HOST=reverb` (en `.env`): lo usa **Laravel** desde dentro de Docker.
- `window.location.hostname` y puerto `8080` (en la vista): lo usa **el navegador**, que entra por el puerto publicado en tu máquina.

### 3.4 `scheduler` — el programador de tareas
- Corre `php artisan schedule:work`, que revisa el calendario de tareas de Laravel una y otra vez.
- La tarea registrada es `reservas:liberar`, programada con `everyTenSeconds()` (cada 10 segundos).
- Sin este contenedor las reservas **nunca vencerían**: nadie ejecutaría la liberación.

### 3.5 Laravel Echo y pusher-js (en el navegador)
- **pusher-js**: librería JavaScript que sabe hablar el protocolo Pusher/Reverb por WebSocket.
- **Laravel Echo**: capa encima de pusher-js que hace simple suscribirse a canales y escuchar eventos de Laravel (`echo.channel('evento.1').listen('AsientoActualizado', ...)`).
- Ambas se cargan desde un CDN con dos etiquetas `<script>` en la vista. No hay que instalar nada con npm.

### 3.6 Docker y `docker-compose.yml`
- **Dockerfile**: receta de la imagen: PHP 8.4 con las extensiones `pdo_pgsql` (hablar con PostgreSQL), `intl`, `zip`, `pcntl` y Composer.
- **docker-compose.yml**: define los 4 servicios, sus puertos, sus comandos y cómo se conectan.
- **Volumen** `.:/var/www/html`: la carpeta del proyecto se "monta" dentro de los contenedores, así que **editas en tu PC y el contenedor ve el cambio** sin reconstruir.
- **`depends_on`**: orden de arranque (`app` espera a `db`; `reverb` a `app`; `scheduler` a `db` y `reverb`).
- Los servicios se encuentran por su nombre (`db`, `reverb`): Docker les da un DNS interno.

### 3.7 Piezas de Laravel que usamos
- **Eloquent**: convierte tablas en clases (`Asiento::where(...)->get()`).
- **Migraciones y seeders**: crean la estructura y los datos iniciales.
- **Route model binding**: en la ruta `/asientos/{asiento}/reservar`, Laravel busca el `Asiento` con ese id y lo entrega al controlador. Si no existe, responde 404 solo.
- **Inyección de dependencias**: si un método pide `ReservaService $servicio`, Laravel lo crea y lo entrega automáticamente.
- **Facade `Schedule`**: registra tareas programadas.
- **`Artisan::command`**: define comandos de terminal propios.

---

## 4. Estructura de carpetas (solo lo nuestro)

```
boleteria/
├── app/
│   ├── Events/AsientoActualizado.php        ← el evento que viaja por WebSocket
│   ├── Http/Controllers/
│   │   ├── EventoController.php             ← muestra el mapa
│   │   └── AsientoController.php            ← recibe la reserva
│   ├── Models/
│   │   ├── Evento.php                       ← tabla eventos
│   │   └── Asiento.php                      ← tabla asientos
│   └── Services/ReservaService.php          ← lógica: reservar y liberar
├── config/boleteria.php                     ← lee HOLD_SEGUNDOS
├── database/
│   ├── migrations/…eventos / …asientos      ← crean las tablas
│   └── seeders/DatabaseSeeder.php           ← crea el evento y los 40 asientos
├── resources/views/mapa.blade.php           ← la única pantalla (HTML+CSS+JS)
├── routes/
│   ├── web.php                              ← las 3 rutas web
│   └── console.php                          ← comandos + tarea programada
├── .env                                     ← configuración (no se sube a git)
├── docker-compose.yml                       ← los 4 contenedores
└── Dockerfile                               ← imagen de PHP
```

---

## 5. Cómo levantar y usar la aplicación

### Primera vez
1. Abre **Docker Desktop** y espera a que esté en verde.
2. En una terminal:
```powershell
cd c:\Distribuidas\boleteria
docker compose up -d
docker compose exec app php artisan migrate --seed
```
- `up -d`: arranca los 4 contenedores en segundo plano.
- `migrate --seed`: crea las tablas y mete el evento con sus 40 asientos.
3. Abre **http://localhost:8000**.
4. Para ver el tiempo real, abre la misma URL en **dos pestañas** (o dos navegadores). Escribe un nombre en una, haz clic en un asiento verde y mira la otra.

### Uso diario
```powershell
docker compose start        # encender (si los contenedores ya existen)
# ... usar http://localhost:8000 ...
docker compose stop         # apagar SIN destruir los contenedores
```
- `docker compose up -d` crea y arranca los contenedores (úsalo la primera vez o si no existen).
- `docker compose stop` / `start` conservan los datos de la base.
- **Ojo con `docker compose down`:** elimina los contenedores y el `docker-compose.yml` **no declara un volumen con nombre** para PostgreSQL. Es probable que, tras un `down` y un nuevo `up`, la base quede vacía. En ese caso basta repetir `docker compose exec app php artisan migrate --seed`. (No lo he comprobado; si quieres datos persistentes seguros, se agrega un volumen con nombre al servicio `db`.)

### Si cambias el `.env`
```powershell
docker compose restart
```

### Cómo probar cada servicio
- **Servicio 2 (tiempo real):** dos pestañas abiertas; reserva en una y observa la otra. También: `docker compose exec app php artisan reservar 7 Maria` y mira cómo cambia el asiento B7 sin tocar el navegador.
- **Servicio 3 (liberación):** reserva un asiento y espera `HOLD_SEGUNDOS` (30 s) más hasta 10 s del scheduler: vuelve a verde solo.

---

## 6. Recorrido paso a paso de cada flujo

### 6.1 Abrir la página
1. El navegador pide `GET http://localhost:8000/`.
2. La ruta `/` en `web.php` busca el primer evento. Si no hay, responde 404 con un aviso. Si hay, **redirige** a `/eventos/1`.
3. El navegador pide `GET /eventos/1`. Laravel busca el `Evento` con id 1 (route model binding).
4. `EventoController::mapa` trae los asientos de ese evento ordenados por fila y número, y los agrupa por fila.
5. La vista `mapa.blade.php` recorre las filas y dibuja un botón por asiento, con su color según el estado (`libre` verde, `reservado` amarillo).
6. El navegador carga la página, ejecuta el JavaScript y **abre el WebSocket** a Reverb, suscrito al canal `evento.1`.

### 6.2 Reservar un asiento (hacer clic)
1. Haces clic en un asiento verde.
2. El JavaScript envía `POST /asientos/5/reservar` con el nombre en formato JSON y el token CSRF en la cabecera.
3. `AsientoController::reservar` recibe la petición. Laravel ya cargó el `Asiento` id 5.
4. Si el nombre está vacío → responde **422** `{"error":"Escribe tu nombre primero"}`.
5. Si no, llama a `ReservaService::reservar($asiento, $nombre)`:
   1. Si `estado` no es `libre` → lanza `RuntimeException` → el controlador responde **409** con el mensaje.
   2. Si está libre: pone `estado='reservado'`, `reservado_por=nombre`, `hold_hasta = ahora + HOLD_SEGUNDOS` y hace `save()` (un `UPDATE` en PostgreSQL).
   3. Llama a `notificar()`, que ejecuta `AsientoActualizado::dispatch(...)`.
6. Como el evento usa `ShouldBroadcastNow`, Laravel lo envía **de inmediato** a Reverb por HTTP.
7. Reverb lo reparte a todos los navegadores suscritos a `evento.1`.
8. En cada navegador, el listener de Echo recibe `{eventoId, asientoId, estado, reservadoPor}`, busca el botón `asiento-<id>`, le cambia la clase (color), lo deshabilita y actualiza el tooltip.
9. La respuesta `{ok:true}` al que hizo clic no se usa para pintar nada: **todos, incluido quien reservó, ven el cambio por el WebSocket**.

### 6.3 Liberación automática
1. El contenedor `scheduler` ejecuta `schedule:work` de forma continua.
2. Cada 10 segundos dispara el comando `reservas:liberar`.
3. El comando llama a `ReservaService::liberarVencidos()`:
   1. Consulta los asientos con `estado='reservado'` **y** `hold_hasta <= ahora`.
   2. Por cada uno: pone `estado='libre'`, `reservado_por=null`, `hold_hasta=null` y guarda.
   3. Por cada uno emite `AsientoActualizado` con estado `libre`.
4. Reverb lo reparte y todos los navegadores pintan el asiento de verde.
5. El comando imprime `Asientos liberados: N`.

Por eso el tiempo real total es: `HOLD_SEGUNDOS` + hasta 10 s de espera del scheduler.

---

## 7. El código explicado archivo por archivo

### 7.1 `database/migrations/2026_11_24_000001_create_eventos_table.php`
```php
Schema::create('eventos', function (Blueprint $table) {
    $table->id();                    // columna id autoincremental (clave primaria)
    $table->string('nombre');        // texto corto
    $table->string('lugar');
    $table->timestamp('fecha');      // fecha y hora del evento
    $table->timestamps();            // created_at y updated_at (los llena Laravel)
});
```
- `up()` crea la tabla; `down()` la borra (para revertir la migración).

### 7.2 `database/migrations/2026_11_24_000002_create_asientos_table.php`
```php
$table->foreignId('evento_id')->constrained()->cascadeOnDelete();
```
- Crea la columna `evento_id` y una **llave foránea** hacia `eventos.id`. `constrained()` deduce la tabla por el nombre. `cascadeOnDelete()` borra los asientos si se borra el evento.
```php
$table->string('fila', 2);
$table->unsignedSmallInteger('numero');
$table->string('estado')->default('libre');
$table->string('reservado_por')->nullable();   // nullable = puede estar vacío
$table->timestamp('hold_hasta')->nullable();   // cuándo vence la reserva
$table->unique(['evento_id', 'fila', 'numero']);
```
- El **índice único** impide dos asientos con la misma fila y número en el mismo evento: la propia base de datos lo garantiza.

### 7.3 `app/Models/Evento.php`
- `$fillable`: lista de columnas que se pueden asignar en masa (`create`, `updateOrCreate`). Protege contra asignar campos no previstos.
- `casts()`: `fecha` se convierte automáticamente en un objeto de fecha (por eso la vista puede hacer `->format('d/m/Y H:i')`).
- `asientos()`: relación "un evento tiene muchos asientos" (`hasMany`). Permite `$evento->asientos()`.

### 7.4 `app/Models/Asiento.php`
- `$table = 'asientos'`: se indica el nombre porque Laravel, por defecto, buscaría `asientos` pluralizando en inglés y podría equivocarse con palabras en español.
- `$fillable` y `casts()` (`hold_hasta` como fecha), igual que arriba.
- `evento()`: relación inversa (`belongsTo`).
- `codigo()`: devuelve fila + número, por ejemplo `A1`. Se usa en los mensajes de error y en la vista.

### 7.5 `app/Events/AsientoActualizado.php`
```php
class AsientoActualizado implements ShouldBroadcastNow
{
    use Dispatchable;
```
- `ShouldBroadcastNow`: le dice a Laravel "este evento se transmite por WebSocket y **ya mismo**" (sin pasar por una cola de trabajos).
- `Dispatchable`: da el método estático `AsientoActualizado::dispatch(...)`.
```php
public function __construct(
    public int $eventoId,
    public int $asientoId,
    public string $estado,
    public ?string $reservadoPor,
) {}
```
- Propiedades públicas: **Laravel las convierte en el JSON** que viaja al navegador (`{"eventoId":1,"asientoId":2,"estado":"reservado","reservadoPor":"Carla"}`).
```php
public function broadcastOn(): Channel
{
    return new Channel('evento.' . $this->eventoId);
}
```
- Define el canal. `Channel` es un canal **público** (no pide autenticación). Cada evento de boletería tiene su propio canal, así que quien mira el evento 1 no recibe cambios del evento 2.
- El nombre con el que llega el evento al navegador es la clase completa: `App\Events\AsientoActualizado`.

### 7.6 `app/Services/ReservaService.php`

**`reservar(Asiento $asiento, string $nombre): void`**
```php
if ($asiento->estado !== 'libre') {
    throw new RuntimeException("El asiento {$asiento->codigo()} no está disponible");
}
```
- Regla de negocio: solo se reserva lo que está libre. Si no, se lanza una excepción con el mensaje que verá el usuario.
```php
$asiento->estado = 'reservado';
$asiento->reservado_por = $nombre;
$asiento->hold_hasta = now()->addSeconds(config('boleteria.hold_segundos'));
$asiento->save();
```
- Cambia el estado en memoria y `save()` lo graba en PostgreSQL. `now()->addSeconds(...)` calcula el vencimiento con el valor de `config/boleteria.php`.
```php
$this->notificar($asiento);
```
- Avisa a todos.

**`liberarVencidos(): int`**
```php
$vencidos = Asiento::where('estado', 'reservado')
    ->where('hold_hasta', '<=', now())
    ->get();
```
- Busca los asientos reservados cuyo vencimiento ya pasó.
```php
foreach ($vencidos as $asiento) { ...estado libre, limpiar campos, save(), notificar... }
return $vencidos->count();
```
- Los libera uno por uno y devuelve cuántos fueron.

**`notificar(Asiento $asiento)`** (privado): emite `AsientoActualizado` con el evento, el asiento, el estado nuevo y quién lo tiene.

> **Por qué es un *service* y no código en el controlador:** el controlador (clic en la web) y los comandos (terminal y scheduler) hacen lo mismo. Con una sola clase, la lógica está en un solo lugar y se puede cambiar o endurecer sin tocar los demás archivos. Es la idea de "Service" del sílabo.

### 7.7 `app/Http/Controllers/AsientoController.php`
```php
public function reservar(Request $request, Asiento $asiento, ReservaService $servicio)
```
- `Request`: la petición (trae el nombre). `Asiento $asiento`: lo carga Laravel por el `{asiento}` de la URL. `ReservaService $servicio`: lo crea Laravel (inyección de dependencias).
- `trim(...)` quita espacios; si queda vacío responde 422.
- El `try/catch` convierte la `RuntimeException` del servicio en una respuesta JSON 409 (conflicto).
- Si todo sale bien responde `{"ok":true}`.

### 7.8 `app/Http/Controllers/EventoController.php`
- `$evento->asientos()->orderBy('fila')->orderBy('numero')->get()`: trae los asientos ordenados.
- `->groupBy('fila')`: agrupa en una colección tipo `{A: [A1..A8], B: [...]}`, que es justo lo que necesita la vista para dibujar fila por fila.
- `view('mapa', [...])`: renderiza `mapa.blade.php` pasándole `evento` y `filas`.

### 7.9 `routes/web.php`
| Ruta | Qué hace |
|---|---|
| `GET /` | Busca el primer evento y redirige a `/eventos/{id}`; 404 con aviso si no hay. |
| `GET /eventos/{evento}` | `EventoController@mapa`. |
| `POST /asientos/{asiento}/reservar` | `AsientoController@reservar`. |

### 7.10 `routes/console.php`
```php
Artisan::command('reservas:liberar', function (ReservaService $servicio) { ... });
```
- Define el comando `php artisan reservas:liberar`. El `ReservaService` del parámetro lo inyecta Laravel.
```php
Schedule::command('reservas:liberar')->everyTenSeconds();
```
- Lo registra en el programador: lo ejecutará el contenedor `scheduler` cada 10 segundos.
```php
Artisan::command('reservar {asiento} {nombre}', function (ReservaService $servicio, int $asiento, string $nombre) { ... });
```
- `{asiento}` y `{nombre}` son argumentos que se asignan a los parámetros del mismo nombre. Busca el asiento con `findOrFail` (error si no existe) y lo reserva. Sirve para probar el tiempo real desde la terminal.

### 7.11 `config/boleteria.php`
```php
'hold_segundos' => (int) env('HOLD_SEGUNDOS', 300),
```
- Lee `HOLD_SEGUNDOS` del `.env`; si no existe, usa 300 (5 minutos, el requisito del proyecto). `config('boleteria.hold_segundos')` lo lee desde el código.

### 7.12 `database/seeders/DatabaseSeeder.php`
- `Evento::updateOrCreate(['nombre' => ...], [...])`: crea el evento o lo actualiza si ya existe (por eso se puede ejecutar varias veces).
- Dos bucles (`A`–`E` y `1`–`8`) y `firstOrCreate` por asiento: crea cada uno si no existe. Resultado: 40 asientos, todos `libre`.

### 7.13 `resources/views/mapa.blade.php`

**Parte HTML (Blade)**
- `<meta name="csrf-token" content="{{ csrf_token() }}">`: guarda el token de seguridad CSRF. Laravel exige ese token en toda petición POST para evitar que otros sitios envíen formularios en tu nombre. El JavaScript lo lee de aquí.
- `{{ ... }}` imprime un valor **escapando** el HTML (seguro contra inyección de código).
- `@foreach ($filas as $fila => $asientos)` y el `@foreach` interno dibujan una fila y un botón por asiento.
- Cada botón lleva:
  - `class="asiento libre|reservado"`: define el color.
  - `id="asiento-<id>"`: para que el JavaScript lo encuentre al llegar un evento.
  - `data-id` y `data-codigo`: datos que usa el JavaScript.
  - `title`: tooltip con quién lo reservó.
  - `@disabled(...)`: deshabilitado si no está libre.

**Parte JavaScript**
```js
const echo = new Echo({
    broadcaster: 'reverb',
    key: '{{ config('broadcasting.connections.reverb.key') }}',
    wsHost: window.location.hostname,
    wsPort: 8080,
    forceTLS: false,
    enabledTransports: ['ws'],
});
```
- Crea la conexión a Reverb. `key`: la clave pública (`REVERB_APP_KEY`), que Blade escribe desde la configuración. `wsHost`: el mismo host de la página. `wsPort: 8080`: puerto de Reverb. `forceTLS: false` y `['ws']`: WebSocket sin cifrar (desarrollo local).
```js
echo.connector.pusher.connection.bind('state_change', (e) => {
    document.getElementById('estado').textContent = e.current;
});
```
- Escribe en el pie de la página el estado de la conexión (`connecting`, `connected`, `unavailable`…).
```js
echo.channel('evento.{{ $evento->id }}').listen('AsientoActualizado', (e) => { ... });
```
- Se suscribe al canal `evento.1` y, cada vez que llega un `AsientoActualizado`, busca el botón por `e.asientoId` y actualiza `className`, `disabled` y `title`. (Echo antepone solo el prefijo `App\Events\` al nombre.)
```js
document.querySelectorAll('.asiento').forEach((boton) => {
    boton.addEventListener('click', async () => { ... fetch(...) ... });
});
```
- Para cada botón, al hacer clic envía el `fetch` POST con el nombre y el token CSRF. Si la respuesta no es correcta (`!respuesta.ok`), muestra el error en el párrafo `#mensaje` (por ejemplo "El asiento A1 no está disponible"). No pinta nada por sí mismo: el cambio de color llega por el WebSocket.

**CSS**: clases `.libre` (verde `#2e9e4f`), `.reservado` (amarillo `#e0a100`), `.asiento` (tamaño 40×40), `.fila` (alineación) y `.escenario`.

### 7.14 `.env` — variables relevantes
| Variable | Para qué sirve |
|---|---|
| `DB_CONNECTION=pgsql`, `DB_HOST=db`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Conexión a PostgreSQL. |
| `BROADCAST_CONNECTION=reverb` | Usar Reverb para transmitir eventos. |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | Credenciales de Reverb. La clave se lee en la vista; el secreto firma los envíos. |
| `REVERB_HOST=reverb`, `REVERB_PORT=8080`, `REVERB_SCHEME=http` | Dónde Laravel encuentra a Reverb (dentro de Docker). |
| `HOLD_SEGUNDOS=30` | Duración del hold. Cambiar a 300 para 5 minutos. |
| `QUEUE_CONNECTION=database`, `SESSION_DRIVER=file`, `CACHE_STORE=file` | Cola, sesiones y caché. Hoy no usamos cola porque el evento se envía directo. |

---

## 8. Dónde modificar cada cosa

| Quiero cambiar... | Dónde | Después de cambiarlo |
|---|---|---|
| Duración de la reserva | `HOLD_SEGUNDOS` en `.env` (300 = 5 min) | `docker compose restart` |
| Cada cuánto se liberan los vencidos | `->everyTenSeconds()` en `routes/console.php` (p. ej. `->everyFiveSeconds()`) | Se aplica en la siguiente ejecución del scheduler; si no, `docker compose restart scheduler` |
| Cantidad de filas o asientos por fila | Los arreglos y el bucle de `DatabaseSeeder.php` | `docker compose exec app php artisan migrate:fresh --seed` |
| Nombre, lugar o fecha del evento | `DatabaseSeeder.php` | Igual que arriba |
| Colores, tamaños y textos | `<style>` y el HTML de `mapa.blade.php` | Recargar el navegador |
| Reglas de la reserva (p. ej. máximo de asientos por persona) | `reservar()` en `ReservaService.php` | Inmediato |
| Mensajes de error | `ReservaService.php` y `AsientoController.php` | Inmediato |
| Datos que viajan por WebSocket | Constructor de `AsientoActualizado.php` **y** el listener en `mapa.blade.php` | Recargar el navegador |
| Agregar una columna a una tabla | Migración nueva (`php artisan make:migration`) | `php artisan migrate` |
| Credenciales de la BD | `.env` **y** `docker-compose.yml` (variables `POSTGRES_*`) | PostgreSQL solo aplica usuario y clave al crear la base por primera vez: hay que recrearla con `docker compose down -v`, `docker compose up -d` y `migrate --seed` |
| Claves de Reverb | `.env` (la vista las lee sola) | `docker compose restart` |
| Puerto web | Puertos en `docker-compose.yml` (y `APP_URL` en `.env`) | `docker compose up -d` |

---

## 9. Comandos útiles

Todos se ejecutan desde `c:\Distribuidas\boleteria`.

| Qué quieres | Comando |
|---|---|
| Encender todo | `docker compose up -d` |
| Apagar conservando los datos | `docker compose stop` (y `docker compose start` para volver a encender) |
| Apagar y eliminar contenedores (la BD puede perderse, ver sección 5) | `docker compose down` |
| Apagar y borrar también los volúmenes | `docker compose down -v` |
| Reiniciar | `docker compose restart` |
| Ver qué corre | `docker compose ps` |
| Ver logs | `docker compose logs -f app` (también `reverb`, `scheduler`, `db`) |
| Crear tablas y datos | `docker compose exec app php artisan migrate --seed` |
| Resetear todo (borra y recrea) | `docker compose exec app php artisan migrate:fresh --seed` |
| Reservar desde terminal | `docker compose exec app php artisan reservar 7 Maria` |
| Liberar vencidos a mano | `docker compose exec app php artisan reservas:liberar` |
| Ver todas las rutas | `docker compose exec app php artisan route:list` |
| Ver los comandos programados | `docker compose exec app php artisan schedule:list` |
| Consultar la BD | `docker compose exec db psql -U boleteria -d boleteria -c "select * from asientos order by id"` |
| Entrar a la consola de la BD | `docker compose exec db psql -U boleteria -d boleteria` |

El id del asiento va de 1 a 40: A1 = 1, A8 = 8, B1 = 9, … E8 = 40.

---

## 10. Solución de problemas

| Síntoma | Causa probable | Qué hacer |
|---|---|---|
| `failed to connect to the docker API` | Docker Desktop no está abierto | Abrir Docker Desktop y esperar |
| La página dice "No hay eventos" (404) | No se ejecutó el seeder | `docker compose exec app php artisan migrate --seed` |
| Error de conexión a la BD al abrir la página | `db` aún arrancando | Esperar unos segundos y recargar; ver `docker compose logs db` |
| "Estado de la conexión" no dice `connected` | Reverb caído o puerto 8080 ocupado | `docker compose ps` y `docker compose logs reverb`; reiniciar con `docker compose restart reverb` |
| Reservo y la otra pestaña no cambia | WebSocket desconectado, o clave de Reverb distinta | Revisar el estado de conexión y que el `.env` no haya cambiado sin reiniciar |
| Error 419 al hacer clic | Token CSRF vencido (página abierta mucho tiempo) | Recargar la página |
| Los asientos no se liberan solos | Contenedor `scheduler` caído | `docker compose ps`; `docker compose logs scheduler` |
| `docker compose up --build` da error de "bake" | Problema del constructor de Docker en esta máquina | Usar `docker compose up -d`. Si las imágenes `boleteria-clase-*` no existen, se pueden crear con `docker tag subastas-clase-app boleteria-clase-app` (y lo mismo con `reverb` y `scheduler`), mientras exista la imagen de la guía |
| Cambié el `.env` y no pasa nada | Los contenedores leen el `.env` al arrancar | `docker compose restart` |

---

## 11. Limitaciones actuales y qué sigue

**Limitaciones conocidas**
- **Sin control de concurrencia (a propósito):** `reservar()` primero *lee* el estado y luego *guarda*. Si dos personas hacen clic exactamente a la vez en el mismo asiento, ambas pueden leer `libre` antes de que cualquiera guarde, y las dos reciben `ok`; gana la última en guardar y la otra persona cree que reservó.
  ```
  Ana : lee A1 = libre ─────────────► guarda "Ana"   ► responde OK
  Beto:        lee A1 = libre ────────────────► guarda "Beto" ► responde OK  ← error
  ```
  Es el fallo que arregla el **servicio 1**.
- No hay login: el nombre es texto libre y cualquiera puede escribir cualquier nombre.
- No hay estado `vendido`, ni pago, ni boletos, ni cancelación.
- No hay precios ni categorías de asiento.
- No hay panel de administración.
- Las sesiones y la caché usan archivos locales: con dos instancias de la app detrás de un balanceador habría que moverlas a Redis o a la base de datos (RNF-04, stateless).
- El servidor `artisan serve` es solo para desarrollo.
- La fecha se muestra en UTC (`config/app.php` tiene `'timezone' => 'UTC'`).
- El estado de los botones se carga una vez al abrir la página. Si se cae el WebSocket y vuelve, los cambios ocurridos mientras tanto no se recuperan hasta recargar.

**Siguientes pasos sugeridos**
1. **Servicio 1 — reserva segura:** envolver `reservar()` en `DB::transaction()` y usar `lockForUpdate()` para bloquear la fila del asiento mientras se decide. Más un script que lance 50 reservas simultáneas del mismo asiento y demuestre que solo una gana (RF-06, RNF-01, RNF-02).
2. Pago simulado y boleto con código único (RF-07, RF-10).
3. Autenticación con JWT y roles (RF-01, RNF-06).
4. Colas, Redis y balanceo con Nginx (UC3).
