<?php
/**
 * Formulario público: shortcode [reservas], procesamiento y API de disponibilidad.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RHR_Form {

    public static function init() {
        add_shortcode('reservas', array(__CLASS__, 'shortcode'));
        add_action('init', array(__CLASS__, 'handle_post'));
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'register_assets'));
    }

    public static function register_assets() {
        wp_register_style('rhr', RHR_URL . 'assets/reservas.css', array(), RHR_VERSION);
        wp_register_script('rhr', RHR_URL . 'assets/reservas.js', array(), RHR_VERSION, true);
    }

    /** GET /wp-json/reservas/v1/disponibilidad: lo que el formulario ofrece en vivo. */
    public static function routes() {
        register_rest_route('reservas/v1', '/disponibilidad', array(
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'args'                => array(
                'tipo' => array('required' => true, 'enum' => array('restaurante', 'hotel')),
            ),
            'callback'            => function (WP_REST_Request $req) {
                if ($req['tipo'] === 'hotel') {
                    return array(
                        'noches'       => RHR_Store::noches((string) $req['checkin'], (string) $req['checkout']),
                        'habitaciones' => RHR_Store::habitaciones_libres((string) $req['checkin'], (string) $req['checkout']),
                    );
                }
                return array('franjas' => RHR_Store::franjas_libres((string) $req['fecha']));
            },
        ));
    }

    /** Valida y crea la reserva. Devuelve el ID o la lista de errores. */
    public static function procesar(array $in) {
        $tipo = ($in['tipo'] ?? '') === 'hotel' ? 'hotel' : 'restaurante';
        $data = array('tipo' => $tipo);
        $err = array();

        foreach (array('nombre', 'telefono') as $k) {
            $data[$k] = sanitize_text_field(wp_unslash($in[$k] ?? ''));
        }
        $data['email'] = sanitize_email(wp_unslash($in['email'] ?? ''));
        $data['notas'] = sanitize_textarea_field(wp_unslash($in['notas'] ?? ''));
        if ($data['nombre'] === '') {
            $err[] = __('Ingresa tu nombre.', 'wp-reservas');
        }
        if (!is_email($data['email'])) {
            $err[] = __('Ingresa un email válido.', 'wp-reservas');
        }
        if ($data['telefono'] === '') {
            $err[] = __('Ingresa un teléfono de contacto.', 'wp-reservas');
        }

        if ($tipo === 'restaurante') {
            $data['fecha'] = (string) ($in['fecha'] ?? '');
            $data['hora'] = (string) ($in['hora'] ?? '');
            $data['personas'] = absint($in['personas'] ?? 0);
            $max = (int) RHR_Settings::get('max_personas');
            if ($data['personas'] < 1 || $data['personas'] > $max) {
                $err[] = sprintf(__('La reserva es para entre 1 y %d personas.', 'wp-reservas'), $max);
            }
            $libres = RHR_Store::franjas_libres($data['fecha']);
            if (!isset($libres[$data['hora']])) {
                $err[] = __('Ese día u horario no está disponible.', 'wp-reservas');
            } elseif ($libres[$data['hora']] < $data['personas']) {
                $err[] = sprintf(__('En ese horario quedan lugares para %d personas.', 'wp-reservas'), $libres[$data['hora']]);
            }
        } else {
            $data['checkin'] = (string) ($in['checkin'] ?? '');
            $data['checkout'] = (string) ($in['checkout'] ?? '');
            $data['habitacion'] = sanitize_key($in['habitacion'] ?? '');
            $data['personas'] = absint($in['personas'] ?? 1);
            $libres = RHR_Store::habitaciones_libres($data['checkin'], $data['checkout']);
            if (RHR_Store::noches($data['checkin'], $data['checkout']) < 1) {
                $err[] = __('La salida tiene que ser posterior a la llegada.', 'wp-reservas');
            } elseif (!$libres) {
                $err[] = __('Esas fechas no están disponibles.', 'wp-reservas');
            } elseif (empty($libres[$data['habitacion']]['libres'])) {
                $err[] = __('No quedan habitaciones de ese tipo para esas fechas.', 'wp-reservas');
            }
        }

        if ($err) {
            return $err;
        }
        $id = RHR_Store::create($data);
        if (is_wp_error($id)) {
            return array($id->get_error_message());
        }
        do_action('rhr_reserva_creada', $id);
        return $id;
    }

    /** Post/Redirect/Get: al recargar la página no se duplica la reserva. */
    public static function handle_post() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_POST['rhr_enviar'])) {
            return;
        }
        // vuelve a la página del formulario. No sirve wp_get_referer(): devuelve false
        // justamente cuando el formulario se envía a la misma página en la que está
        $back = isset($_POST['rhr_back']) ? esc_url_raw(wp_unslash($_POST['rhr_back'])) : '';
        $back = wp_validate_redirect($back, home_url('/'));
        $back = remove_query_arg(array('reserva', 'rhr_err'), $back);
        // trampa para bots: un humano no ve ni completa este campo
        if (!empty($_POST['rhr_web'])) {
            wp_safe_redirect($back);
            exit;
        }
        if (!isset($_POST['_rhr']) || !wp_verify_nonce(sanitize_key($_POST['_rhr']), 'rhr_reservar')) {
            $res = array(__('La sesión expiró. Vuelve a enviar el formulario.', 'wp-reservas'));
        } else {
            $res = self::procesar($_POST);
        }
        if (is_array($res)) {
            $token = wp_generate_password(12, false);
            set_transient('rhr_err_' . $token, array('errores' => $res, 'datos' => wp_unslash($_POST)), 10 * MINUTE_IN_SECONDS);
            wp_safe_redirect(add_query_arg('rhr_err', $token, $back) . '#reservas');
            exit;
        }
        wp_safe_redirect(add_query_arg('reserva', RHR_Store::get($res)['codigo'], $back) . '#reservas');
        exit;
    }

    public static function shortcode($atts) {
        $modo = RHR_Settings::get('modo');
        $atts = shortcode_atts(array('tipo' => $modo === 'hotel' ? 'hotel' : 'restaurante'), $atts, 'reservas');
        $tipo = $atts['tipo'] === 'hotel' ? 'hotel' : 'restaurante';

        wp_enqueue_style('rhr');
        wp_enqueue_script('rhr');
        wp_localize_script('rhr', 'RHR', array(
            'api'    => esc_url_raw(rest_url('reservas/v1/disponibilidad')),
            'moneda' => RHR_Settings::get('moneda'),
            'txt'    => array(
                'elige'    => __('Elige un horario', 'wp-reservas'),
                'sinLugar' => __('Sin lugar para ese día', 'wp-reservas'),
                'libres'   => __('lugares', 'wp-reservas'),
                'completo' => __('completo', 'wp-reservas'),
                'noches'   => __('noches', 'wp-reservas'),
                'eligeHab' => __('Elige una habitación', 'wp-reservas'),
                'sinHab'   => __('Sin habitaciones para esas fechas', 'wp-reservas'),
            ),
        ));

        $errores = array();
        $old = array();
        if (!empty($_GET['rhr_err'])) {
            $t = get_transient('rhr_err_' . sanitize_key($_GET['rhr_err']));
            if ($t) {
                $errores = $t['errores'];
                $old = $t['datos'];
            }
        }
        $v = function ($k, $def = '') use ($old) {
            return esc_attr(isset($old[$k]) && is_string($old[$k]) ? $old[$k] : $def);
        };
        $hoy = wp_date('Y-m-d');
        $hasta = wp_date('Y-m-d', strtotime('+' . (int) RHR_Settings::get('anticipacion') . ' days'));

        ob_start();
        ?>
        <div class="rhr" id="reservas" data-tipo="<?php echo esc_attr($tipo); ?>">
            <?php if (!empty($_GET['reserva'])) : ?>
                <div class="rhr-ok" role="status">
                    <strong><?php esc_html_e('¡Recibimos tu reserva!', 'wp-reservas'); ?></strong>
                    <?php printf(esc_html__('Código %s. Te enviamos el detalle por email y te avisamos cuando esté confirmada.', 'wp-reservas'), '<code>' . esc_html(sanitize_text_field(wp_unslash($_GET['reserva']))) . '</code>'); ?>
                </div>
            <?php endif; ?>
            <?php if ($errores) : ?>
                <div class="rhr-error" role="alert"><ul>
                    <?php foreach ($errores as $e) : ?><li><?php echo esc_html($e); ?></li><?php endforeach; ?>
                </ul></div>
            <?php endif; ?>

            <form method="post" class="rhr-form" novalidate>
                <?php wp_nonce_field('rhr_reservar', '_rhr'); ?>
                <input type="hidden" name="tipo" value="<?php echo esc_attr($tipo); ?>">
                <input type="hidden" name="rhr_enviar" value="1">
                <input type="hidden" name="rhr_back" value="<?php echo esc_url(get_permalink() ?: home_url('/')); ?>">
                <div class="rhr-hp" aria-hidden="true"><label>Web <input type="text" name="rhr_web" tabindex="-1" autocomplete="off"></label></div>

                <?php if ($tipo === 'restaurante') : ?>
                    <div class="rhr-row">
                        <label><?php esc_html_e('Fecha', 'wp-reservas'); ?>
                            <input type="date" name="fecha" required min="<?php echo esc_attr($hoy); ?>" max="<?php echo esc_attr($hasta); ?>" value="<?php echo $v('fecha'); ?>"></label>
                        <label><?php esc_html_e('Personas', 'wp-reservas'); ?>
                            <input type="number" name="personas" required min="1" max="<?php echo (int) RHR_Settings::get('max_personas'); ?>" value="<?php echo $v('personas', '2'); ?>"></label>
                    </div>
                    <label><?php esc_html_e('Horario', 'wp-reservas'); ?>
                        <select name="hora" required data-old="<?php echo $v('hora'); ?>">
                            <option value=""><?php esc_html_e('Primero elige la fecha', 'wp-reservas'); ?></option>
                        </select></label>
                <?php else : ?>
                    <div class="rhr-row">
                        <label><?php esc_html_e('Llegada', 'wp-reservas'); ?>
                            <input type="date" name="checkin" required min="<?php echo esc_attr($hoy); ?>" max="<?php echo esc_attr($hasta); ?>" value="<?php echo $v('checkin'); ?>"></label>
                        <label><?php esc_html_e('Salida', 'wp-reservas'); ?>
                            <input type="date" name="checkout" required min="<?php echo esc_attr($hoy); ?>" value="<?php echo $v('checkout'); ?>"></label>
                    </div>
                    <div class="rhr-row">
                        <label><?php esc_html_e('Habitación', 'wp-reservas'); ?>
                            <select name="habitacion" required data-old="<?php echo $v('habitacion'); ?>">
                                <option value=""><?php esc_html_e('Primero elige las fechas', 'wp-reservas'); ?></option>
                            </select></label>
                        <label><?php esc_html_e('Huéspedes', 'wp-reservas'); ?>
                            <input type="number" name="personas" min="1" max="12" value="<?php echo $v('personas', '2'); ?>"></label>
                    </div>
                    <p class="rhr-total" aria-live="polite"></p>
                <?php endif; ?>

                <div class="rhr-row">
                    <label><?php esc_html_e('Nombre', 'wp-reservas'); ?>
                        <input type="text" name="nombre" required autocomplete="name" value="<?php echo $v('nombre'); ?>"></label>
                    <label><?php esc_html_e('Teléfono', 'wp-reservas'); ?>
                        <input type="tel" name="telefono" required autocomplete="tel" value="<?php echo $v('telefono'); ?>"></label>
                </div>
                <label><?php esc_html_e('Email', 'wp-reservas'); ?>
                    <input type="email" name="email" required autocomplete="email" value="<?php echo $v('email'); ?>"></label>
                <label><?php esc_html_e('Comentarios (opcional)', 'wp-reservas'); ?>
                    <textarea name="notas" rows="3"><?php echo esc_textarea($old['notas'] ?? ''); ?></textarea></label>
                <button type="submit" class="rhr-btn"><?php esc_html_e('Reservar', 'wp-reservas'); ?></button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}
