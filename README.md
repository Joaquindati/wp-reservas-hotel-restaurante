# Plugin de reservas para hoteles y restaurantes (WordPress)

**Sistema de reservas online para WordPress**, sin intermediarios ni comisiones. Sirve para
**restaurantes, bares y cafés** (reserva de mesa por franja horaria, con control de
capacidad) y para **hoteles boutique, posadas y cabañas** (reserva de habitaciones por
noche, con disponibilidad real). Incluye emails automáticos, panel de gestión y calendario
`.ics` para ver todo en **Google Calendar**.

> 🇬🇧 **English:** WordPress booking plugin for restaurants (table reservations by time
> slot with capacity limits) and hotels (room reservations by night with real
> availability). Automatic emails, admin dashboard and an iCal feed for Google Calendar.

## Características

**Reserva de mesas (restaurante / bar)**
- Franjas horarias configurables (por ejemplo `12:00, 12:30, 20:00, 20:30`).
- **Capacidad por franja**: cuando se llena, la franja aparece como "completo".
- Máximo de personas por reserva.
- Las franjas de hoy que ya pasaron no se ofrecen.

**Reserva de habitaciones (hotel / posada)**
- Tipos de habitación con cantidad y precio por noche (`Doble|4|80000`).
- **Disponibilidad real por noche**: dos reservas se pisan solo si se superponen; una
  salida y una llegada el mismo día no se pisan.
- Total estimado de la estadía mientras se completa el formulario.

**Para los dos**
- Días de la semana habilitados, **fechas bloqueadas** (feriados, vacaciones, eventos) y
  anticipación máxima.
- **Disponibilidad en vivo**: el formulario consulta `GET /wp-json/reservas/v1/disponibilidad`
  y solo muestra horarios y habitaciones con lugar. Sin JavaScript, igual valida todo en el
  servidor.
- **Emails automáticos**:
  - al cliente cuando reserva, con un **archivo `.ics` adjunto** para agendarla;
  - al local, con un enlace directo a la reserva;
  - al cliente otra vez cuando se confirma o se cancela.
- **Panel de administración**: listado con fecha, detalle, contacto y estado, filtro por
  estado, **confirmar o cancelar con un clic** y edición completa de cada reserva.
- **Calendario `.ics` privado** para suscribirse desde Google Calendar, Apple Calendar u
  Outlook. Muestra las reservas pendientes como tentativas y las confirmadas como
  confirmadas.
- **Responsive**: campos de 44 px de alto como mínimo, pensados para el celular.
- **Seguridad**: nonce, campo trampa contra bots, todo sanitizado y escapado, y patrón
  Post/Redirect/Get para que recargar la página no duplique la reserva.

## Instalación

1. Clona el repo en `wp-content/plugins/` (o súbelo como ZIP) y actívalo:
   ```bash
   cd wp-content/plugins
   git clone https://github.com/Joaquindati/wp-reservas-hotel-restaurante.git
   ```
2. Configura franjas, capacidad, días, bloqueos y habitaciones en **Reservas → Ajustes**.
3. Pega el shortcode en una página:
   ```
   [reservas tipo="restaurante"]
   [reservas tipo="hotel"]
   ```
4. Para ver las reservas en Google Calendar: copia la URL de *Reservas → Ajustes →
   Calendario* y agrégala en *Otros calendarios → Desde URL*.

## Estructura

```
wp-reservas-hotel-restaurante.php   arranque y activación
includes/class-settings.php         ajustes (franjas, capacidad, días, bloqueos, habitaciones)
includes/class-store.php            tipo de contenido "reserva" y cálculo de disponibilidad
includes/class-form.php             shortcode, validación y API REST de disponibilidad
includes/class-notifier.php         emails y calendario .ics
includes/class-admin.php            columnas, filtros, confirmar/cancelar y edición
assets/                             JavaScript (disponibilidad en vivo) y estilos
```

## Extender

- `do_action('rhr_reserva_creada', $id)`: después de crear una reserva. Sirve, por
  ejemplo, para mandar un WhatsApp o crear un pedido de pago.
- `do_action('rhr_estado_cambiado', $id, $estado)`: al confirmarla o cancelarla.

## No incluye

- **Cobro online**: no cobra la reserva. Con los hooks de arriba se puede conectar a
  WooCommerce o a una pasarela de pago.
- **Escritura en Google Calendar**: la sincronización es de lectura, por suscripción al
  feed `.ics`. No escribe en la API de Google.

## Requisitos

WordPress 5.8+ · PHP 7.4+ (probado con PHP 8.3 y la última versión de WordPress).

## Autor

**Joaquín Dati**, desarrollador WordPress y full stack.
[joaquindati.com](https://joaquindati.com)

Licencia GPL v2 o posterior.
