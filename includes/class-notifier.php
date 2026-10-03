<?php
/**
 * Emails al cliente y al local, y calendario .ics (adjunto y feed para suscribirse).
 */

if (!defined('ABSPATH')) {
    exit;
}

class RHR_Notifier {

    public static function init() {
        add_action('rhr_reserva_creada', array(__CLASS__, 'nueva'));
        add_action('rhr_estado_cambiado', array(__CLASS__, 'estado'), 10, 2);
        add_action('init', array(__CLASS__, 'feed'));
    }

    /** Texto legible de la reserva, para emails y para el calendario. */
    public static function resumen(array $r) {
        $moneda = RHR_Settings::get('moneda');
        if ($r['tipo'] === 'hotel') {
            $habs = RHR_Settings::habitaciones();
            $hab = isset($habs[$r['habitacion']]) ? $habs[$r['habitacion']] : array('nombre' => $r['habitacion'], 'precio' => 0);
            $noches = RHR_Store::noches($r['checkin'], $r['checkout']);
            $lineas = array(
                sprintf(__('Habitación: %s', 'wp-reservas'), $hab['nombre']),
                sprintf(__('Llegada: %s', 'wp-reservas'), self::fecha($r['checkin'])),
                sprintf(__('Salida: %s (%d noches)', 'wp-reservas'), self::fecha($r['checkout']), $noches),
                sprintf(__('Huéspedes: %d', 'wp-reservas'), $r['personas']),
            );
            if ($hab['precio'] > 0) {
                $lineas[] = sprintf(__('Total estimado: %s%s', 'wp-reservas'), $moneda, number_format_i18n($hab['precio'] * $noches, 2));
            }
        } else {
            $lineas = array(
                sprintf(__('Fecha: %s', 'wp-reservas'), self::fecha($r['fecha'])),
                sprintf(__('Hora: %s', 'wp-reservas'), $r['hora']),
                sprintf(__('Personas: %d', 'wp-reservas'), $r['personas']),
            );
        }
        $lineas[] = sprintf(__('A nombre de: %s (%s, %s)', 'wp-reservas'), $r['nombre'], $r['telefono'], $r['email']);
        if ($r['notas'] !== '') {
            $lineas[] = sprintf(__('Comentarios: %s', 'wp-reservas'), $r['notas']);
        }
        $lineas[] = sprintf(__('Código: %s', 'wp-reservas'), $r['codigo']);
        return implode("\n", $lineas);
    }

    private static function fecha($ymd) {
        return $ymd ? wp_date(get_option('date_format'), strtotime($ymd . ' 12:00:00')) : '';
    }

    public static function nueva($id) {
        $r = RHR_Store::get($id);
        $sitio = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $resumen = self::resumen($r);

        // al cliente, con el .ics adjunto para que lo agende con un toque
        $ics = self::ics_tmp($r);
        wp_mail(
            $r['email'],
            sprintf(__('[%s] Recibimos tu reserva %s', 'wp-reservas'), $sitio, $r['codigo']),
            sprintf(__("Hola %s,\n\nRecibimos tu pedido de reserva. Te escribimos de nuevo cuando esté confirmada.\n\n%s\n\n%s", 'wp-reservas'), $r['nombre'], $resumen, $sitio),
            array('Reply-To: ' . RHR_Settings::get('email_admin')),
            $ics ? array($ics) : array()
        );
        if ($ics) {
            wp_delete_file($ics);
        }

        // al local, con el enlace directo para confirmarla
        wp_mail(
            RHR_Settings::get('email_admin'),
            sprintf(__('Nueva reserva %s: %s', 'wp-reservas'), $r['codigo'], $r['nombre']),
            $resumen . "\n\n" . sprintf(__('Gestionarla: %s', 'wp-reservas'), admin_url('post.php?post=' . (int) $id . '&action=edit')),
            array('Reply-To: ' . $r['email'])
        );
    }

    public static function estado($id, $estado) {
        $r = RHR_Store::get($id);
        $sitio = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        if ($estado === 'confirmada') {
            $asunto = sprintf(__('[%s] Reserva %s confirmada', 'wp-reservas'), $sitio, $r['codigo']);
            $cuerpo = __('¡Tu reserva está confirmada! Te esperamos.', 'wp-reservas');
        } elseif ($estado === 'cancelada') {
            $asunto = sprintf(__('[%s] Reserva %s cancelada', 'wp-reservas'), $sitio, $r['codigo']);
            $cuerpo = __('Tu reserva fue cancelada. Si es un error, responde este email.', 'wp-reservas');
        } else {
            return;
        }
        wp_mail($r['email'], $asunto, sprintf("%s\n\n%s\n\n%s", $cuerpo, self::resumen($r), $sitio), array('Reply-To: ' . RHR_Settings::get('email_admin')));
    }

    /** Un VEVENT por reserva, en hora local del sitio. */
    private static function vevent(array $r) {
        $tz = wp_timezone();
        if ($r['tipo'] === 'hotel') {
            // todo el día, de la llegada a la salida (DTEND exclusivo, como pide RFC 5545)
            $start = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $r['checkin']);
            $end = 'DTEND;VALUE=DATE:' . str_replace('-', '', $r['checkout']);
            $habs = RHR_Settings::habitaciones();
            $que = isset($habs[$r['habitacion']]) ? $habs[$r['habitacion']]['nombre'] : $r['habitacion'];
        } else {
            $ini = new DateTimeImmutable($r['fecha'] . ' ' . $r['hora'], $tz);
            $utc = new DateTimeZone('UTC');
            $start = 'DTSTART:' . $ini->setTimezone($utc)->format('Ymd\THis\Z');
            $end = 'DTEND:' . $ini->modify('+2 hours')->setTimezone($utc)->format('Ymd\THis\Z');
            $que = sprintf(_n('%d persona', '%d personas', (int) $r['personas'], 'wp-reservas'), $r['personas']);
        }
        $esc = function ($s) {
            return str_replace(array('\\', ';', ',', "\n"), array('\\\\', '\;', '\,', '\n'), $s);
        };
        return implode("\r\n", array(
            'BEGIN:VEVENT',
            'UID:rhr-' . (int) $r['id'] . '@' . wp_parse_url(home_url(), PHP_URL_HOST),
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            $start,
            $end,
            'SUMMARY:' . $esc(sprintf('%s · %s', $r['nombre'], $que)),
            'DESCRIPTION:' . $esc(self::resumen($r)),
            'STATUS:' . ($r['estado'] === 'confirmada' ? 'CONFIRMED' : 'TENTATIVE'),
            'END:VEVENT',
        ));
    }

    public static function calendario(array $reservas) {
        $out = array('BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//wp-reservas//ES', 'CALSCALE:GREGORIAN',
            'X-WR-CALNAME:' . sprintf(__('Reservas · %s', 'wp-reservas'), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)));
        foreach ($reservas as $r) {
            $out[] = self::vevent($r);
        }
        $out[] = 'END:VCALENDAR';
        $lineas = array();
        foreach (explode("\r\n", implode("\r\n", $out)) as $l) {
            $lineas[] = self::fold($l);
        }
        return implode("\r\n", $lineas) . "\r\n";
    }

    /** RFC 5545: líneas de 75 octetos como máximo; las siguientes empiezan con un espacio. */
    private static function fold($line) {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = array();
        $cur = '';
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            // sin cortar caracteres multibyte por la mitad
            if (strlen($cur) + strlen($ch) > ($out ? 74 : 75)) {
                $out[] = $cur;
                $cur = '';
            }
            $cur .= $ch;
        }
        $out[] = $cur;
        return implode("\r\n ", $out);
    }

    private static function ics_tmp(array $r) {
        // wp_tempnam() vive en wp-admin y la reserva se procesa en el frontend
        $ics = trailingslashit(get_temp_dir()) . 'reserva-' . sanitize_file_name($r['codigo']) . '-' . wp_generate_password(6, false) . '.ics';
        return file_put_contents($ics, self::calendario(array($r))) === false ? '' : $ics;
    }

    /** ?rhr_ics=TOKEN → calendario con las reservas vigentes, para suscribirse desde Google Calendar. */
    public static function feed() {
        if (!isset($_GET['rhr_ics'])) {
            return;
        }
        $token = (string) get_option('rhr_ics_token');
        if ($token === '' || !hash_equals($token, sanitize_text_field(wp_unslash($_GET['rhr_ics'])))) {
            status_header(403);
            exit;
        }
        $desde = wp_date('Y-m-d', strtotime('-30 days'));
        $ids = get_posts(array(
            'post_type'      => RHR_Store::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 500,
            'fields'         => 'ids',
            'meta_query'     => array(
                array('key' => '_rhr_estado', 'value' => 'cancelada', 'compare' => '!='),
            ),
        ));
        $reservas = array();
        foreach ($ids as $id) {
            $r = RHR_Store::get($id);
            if (($r['tipo'] === 'hotel' ? $r['checkout'] : $r['fecha']) >= $desde) {
                $reservas[] = $r;
            }
        }
        nocache_headers();
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="reservas.ics"');
        echo self::calendario($reservas); // phpcs:ignore WordPress.Security.EscapeOutput -- iCalendar, no HTML
        exit;
    }
}
