<?php
/**
 * Las reservas como entradas de un tipo propio, y el cálculo de disponibilidad.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RHR_Store {

    const CPT = 'rhr_reserva';
    const ESTADOS = array('pendiente', 'confirmada', 'cancelada');

    /** Campos guardados como meta, con su sanitizador. */
    const CAMPOS = array(
        'tipo'        => 'key',      // restaurante | hotel
        'estado'      => 'key',
        'fecha'       => 'date',     // restaurante: día de la reserva
        'hora'        => 'time',
        'personas'    => 'int',
        'checkin'     => 'date',     // hotel
        'checkout'    => 'date',
        'habitacion'  => 'key',
        'nombre'      => 'text',
        'email'       => 'email',
        'telefono'    => 'text',
        'notas'       => 'textarea',
        'codigo'      => 'text',
    );

    public static function init() {
        add_action('init', array(__CLASS__, 'register_post_type'));
    }

    public static function register_post_type() {
        register_post_type(self::CPT, array(
            'labels' => array(
                'name'          => __('Reservas', 'wp-reservas'),
                'singular_name' => __('Reserva', 'wp-reservas'),
                'add_new_item'  => __('Nueva reserva', 'wp-reservas'),
                'edit_item'     => __('Editar reserva', 'wp-reservas'),
                'search_items'  => __('Buscar reservas', 'wp-reservas'),
                'not_found'     => __('No hay reservas', 'wp-reservas'),
            ),
            'public'       => false,
            'show_ui'      => true,
            'menu_icon'    => 'dashicons-calendar-alt',
            'supports'     => array('title'),
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ));
    }

    public static function sanitize_field($tipo, $valor) {
        $valor = is_string($valor) ? wp_unslash($valor) : $valor;
        switch ($tipo) {
            case 'int':      return absint($valor);
            case 'email':    return sanitize_email($valor);
            case 'textarea': return sanitize_textarea_field($valor);
            case 'key':      return sanitize_key($valor);
            case 'date':     return self::valid_date($valor) ? $valor : '';
            case 'time':     return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $valor) ? $valor : '';
            default:         return sanitize_text_field($valor);
        }
    }

    public static function valid_date($d) {
        if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return false;
        }
        list($y, $m, $day) = array_map('intval', explode('-', $d));
        return checkdate($m, $day, $y);
    }

    public static function get($id) {
        $r = array('id' => (int) $id);
        foreach (array_keys(self::CAMPOS) as $k) {
            $r[$k] = get_post_meta($id, '_rhr_' . $k, true);
        }
        return $r;
    }

    public static function save_meta($id, array $data) {
        foreach (self::CAMPOS as $k => $tipo) {
            if (array_key_exists($k, $data)) {
                update_post_meta($id, '_rhr_' . $k, self::sanitize_field($tipo, $data[$k]));
            }
        }
    }

    public static function create(array $data) {
        $data['codigo'] = strtoupper(wp_generate_password(6, false));
        $data['estado'] = 'pendiente';
        $cuando = $data['tipo'] === 'hotel' ? $data['checkin'] : $data['fecha'] . ' ' . $data['hora'];
        $id = wp_insert_post(array(
            'post_type'   => self::CPT,
            'post_status' => 'publish',
            'post_title'  => sanitize_text_field($data['nombre']) . ' · ' . $cuando,
        ), true);
        if (is_wp_error($id)) {
            return $id;
        }
        self::save_meta($id, $data);
        return $id;
    }

    /** Reservas que ocupan lugar (todo menos las canceladas) que cumplen las condiciones. */
    private static function activas(array $meta_query) {
        $meta_query[] = array('key' => '_rhr_estado', 'value' => 'cancelada', 'compare' => '!=');
        return get_posts(array(
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => $meta_query,
        ));
    }

    /** ¿Se puede reservar ese día? (día de la semana habilitado, sin bloqueo, en rango) */
    public static function dia_habilitado($fecha) {
        if (!self::valid_date($fecha)) {
            return false;
        }
        $tz = wp_timezone();
        $dia = new DateTimeImmutable($fecha, $tz);
        $hoy = new DateTimeImmutable('today', $tz);
        if ($dia < $hoy || $dia > $hoy->modify('+' . (int) RHR_Settings::get('anticipacion') . ' days')) {
            return false;
        }
        if (in_array($fecha, RHR_Settings::bloqueos(), true)) {
            return false;
        }
        return in_array((int) $dia->format('w'), array_map('intval', (array) RHR_Settings::get('dias')), true);
    }

    /**
     * Lugares libres por franja para un día.
     *
     * @return array<string, int> hora => personas que todavía entran
     */
    public static function franjas_libres($fecha) {
        if (!self::dia_habilitado($fecha)) {
            return array();
        }
        $capacidad = (int) RHR_Settings::get('capacidad');
        $ocupado = array();
        $ids = self::activas(array(
            array('key' => '_rhr_tipo', 'value' => 'restaurante'),
            array('key' => '_rhr_fecha', 'value' => $fecha),
        ));
        foreach ($ids as $id) {
            $hora = get_post_meta($id, '_rhr_hora', true);
            $ocupado[$hora] = ($ocupado[$hora] ?? 0) + (int) get_post_meta($id, '_rhr_personas', true);
        }
        // hoy no se ofrecen las franjas que ya pasaron
        $ahora = new DateTimeImmutable('now', wp_timezone());
        $out = array();
        foreach (RHR_Settings::franjas() as $hora) {
            if ($fecha === $ahora->format('Y-m-d') && $hora <= $ahora->format('H:i')) {
                continue;
            }
            $out[$hora] = max(0, $capacidad - ($ocupado[$hora] ?? 0));
        }
        return $out;
    }

    /** Noches entre dos fechas, o 0 si el rango no es válido. */
    public static function noches($checkin, $checkout) {
        if (!self::valid_date($checkin) || !self::valid_date($checkout)) {
            return 0;
        }
        $n = (int) (new DateTimeImmutable($checkin))->diff(new DateTimeImmutable($checkout))->format('%r%a');
        return max(0, $n);
    }

    /**
     * Habitaciones libres entre checkin (incluido) y checkout (excluido).
     * Cada noche del rango tiene que estar habilitada.
     *
     * @return array<string, array{nombre:string, libres:int, total:float}>
     */
    public static function habitaciones_libres($checkin, $checkout) {
        $noches = self::noches($checkin, $checkout);
        if ($noches < 1 || $noches > 60) {
            return array();
        }
        $d = new DateTimeImmutable($checkin, wp_timezone());
        for ($i = 0; $i < $noches; $i++) {
            if (!self::dia_habilitado($d->modify("+$i days")->format('Y-m-d'))) {
                return array();
            }
        }
        $out = array();
        foreach (RHR_Settings::habitaciones() as $key => $h) {
            // se pisan si la existente entra antes de que salga la nueva y sale después de que entra
            $ids = self::activas(array(
                array('key' => '_rhr_tipo', 'value' => 'hotel'),
                array('key' => '_rhr_habitacion', 'value' => $key),
                array('key' => '_rhr_checkin', 'value' => $checkout, 'compare' => '<', 'type' => 'DATE'),
                array('key' => '_rhr_checkout', 'value' => $checkin, 'compare' => '>', 'type' => 'DATE'),
            ));
            $out[$key] = array(
                'nombre' => $h['nombre'],
                'libres' => max(0, $h['cantidad'] - count($ids)),
                'total'  => $h['precio'] * $noches,
            );
        }
        return $out;
    }
}
