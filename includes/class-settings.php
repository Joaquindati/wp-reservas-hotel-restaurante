<?php
/**
 * Ajustes: franjas horarias, capacidad, días habilitados, bloqueos y habitaciones.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RHR_Settings {

    const OPTION = 'rhr_settings';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', array(__CLASS__, 'register'));
    }

    public static function defaults() {
        return array(
            'modo'          => 'ambos',
            'franjas'       => '12:00, 12:30, 13:00, 13:30, 20:00, 20:30, 21:00, 21:30',
            'capacidad'     => 40,
            'max_personas'  => 10,
            'dias'          => array(0, 1, 2, 3, 4, 5, 6),
            'bloqueos'      => '',
            'habitaciones'  => "Doble|4|80000\nSuite|2|150000",
            'anticipacion'  => 90,
            'email_admin'   => get_option('admin_email'),
            'moneda'        => '$',
        );
    }

    public static function get($key = null) {
        $opts = wp_parse_args(get_option(self::OPTION, array()), self::defaults());
        return $key === null ? $opts : (isset($opts[$key]) ? $opts[$key] : null);
    }

    /** Franjas como lista ordenada de "HH:MM" válidas. */
    public static function franjas() {
        $out = array();
        foreach (preg_split('/[\s,;]+/', (string) self::get('franjas')) as $f) {
            if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $f, $m)) {
                $out[] = sprintf('%02d:%02d', $m[1], $m[2]);
            }
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    /** Fechas bloqueadas como lista de "YYYY-MM-DD". */
    public static function bloqueos() {
        preg_match_all('/\d{4}-\d{2}-\d{2}/', (string) self::get('bloqueos'), $m);
        return $m[0];
    }

    /**
     * Habitaciones: una por línea, "Nombre|cantidad|precio por noche" (el precio es opcional).
     *
     * @return array<string, array{nombre:string, cantidad:int, precio:float}>
     */
    public static function habitaciones() {
        $out = array();
        foreach (preg_split('/\r?\n/', (string) self::get('habitaciones')) as $line) {
            $p = array_map('trim', explode('|', $line));
            if ($p[0] === '' || empty($p[1]) || (int) $p[1] < 1) {
                continue;
            }
            $out[sanitize_title($p[0])] = array(
                'nombre'   => $p[0],
                'cantidad' => (int) $p[1],
                'precio'   => isset($p[2]) ? (float) str_replace(',', '.', $p[2]) : 0,
            );
        }
        return $out;
    }

    public static function menu() {
        add_submenu_page(
            'edit.php?post_type=' . RHR_Store::CPT,
            __('Ajustes de reservas', 'wp-reservas'),
            __('Ajustes', 'wp-reservas'),
            'manage_options',
            'rhr-ajustes',
            array(__CLASS__, 'render')
        );
    }

    public static function register() {
        register_setting('rhr', self::OPTION, array('sanitize_callback' => array(__CLASS__, 'sanitize')));
    }

    public static function sanitize($in) {
        $d = self::defaults();
        $out = array();
        $out['modo'] = in_array($in['modo'] ?? '', array('restaurante', 'hotel', 'ambos'), true) ? $in['modo'] : $d['modo'];
        $out['franjas'] = sanitize_text_field($in['franjas'] ?? '');
        $out['capacidad'] = max(1, absint($in['capacidad'] ?? $d['capacidad']));
        $out['max_personas'] = max(1, absint($in['max_personas'] ?? $d['max_personas']));
        $out['dias'] = array_values(array_intersect(array_map('intval', (array) ($in['dias'] ?? array())), range(0, 6)));
        $out['bloqueos'] = sanitize_textarea_field($in['bloqueos'] ?? '');
        $out['habitaciones'] = sanitize_textarea_field($in['habitaciones'] ?? '');
        $out['anticipacion'] = max(1, absint($in['anticipacion'] ?? $d['anticipacion']));
        $out['email_admin'] = sanitize_email($in['email_admin'] ?? '') ?: $d['email_admin'];
        $out['moneda'] = sanitize_text_field($in['moneda'] ?? '$');
        return $out;
    }

    public static function render() {
        $o = self::get();
        $n = self::OPTION;
        $dias = array(__('Domingo', 'wp-reservas'), __('Lunes', 'wp-reservas'), __('Martes', 'wp-reservas'), __('Miércoles', 'wp-reservas'), __('Jueves', 'wp-reservas'), __('Viernes', 'wp-reservas'), __('Sábado', 'wp-reservas'));
        $ics = add_query_arg('rhr_ics', get_option('rhr_ics_token'), home_url('/'));
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Ajustes de reservas', 'wp-reservas'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('rhr'); ?>
                <table class="form-table" role="presentation">
                    <tr><th><?php esc_html_e('Tipo de negocio', 'wp-reservas'); ?></th><td>
                        <select name="<?php echo esc_attr($n); ?>[modo]">
                            <option value="restaurante" <?php selected($o['modo'], 'restaurante'); ?>><?php esc_html_e('Restaurante / bar (mesas)', 'wp-reservas'); ?></option>
                            <option value="hotel" <?php selected($o['modo'], 'hotel'); ?>><?php esc_html_e('Hotel / posada (habitaciones)', 'wp-reservas'); ?></option>
                            <option value="ambos" <?php selected($o['modo'], 'ambos'); ?>><?php esc_html_e('Ambos', 'wp-reservas'); ?></option>
                        </select></td></tr>
                    <tr><th><?php esc_html_e('Franjas horarias', 'wp-reservas'); ?></th><td>
                        <input type="text" class="large-text" name="<?php echo esc_attr($n); ?>[franjas]" value="<?php echo esc_attr($o['franjas']); ?>">
                        <p class="description"><?php esc_html_e('Horarios de mesa separados por coma, por ejemplo: 12:00, 12:30, 20:00', 'wp-reservas'); ?></p></td></tr>
                    <tr><th><?php esc_html_e('Capacidad por franja', 'wp-reservas'); ?></th><td>
                        <input type="number" min="1" name="<?php echo esc_attr($n); ?>[capacidad]" value="<?php echo esc_attr($o['capacidad']); ?>"> <?php esc_html_e('personas', 'wp-reservas'); ?></td></tr>
                    <tr><th><?php esc_html_e('Máximo por reserva', 'wp-reservas'); ?></th><td>
                        <input type="number" min="1" name="<?php echo esc_attr($n); ?>[max_personas]" value="<?php echo esc_attr($o['max_personas']); ?>"> <?php esc_html_e('personas', 'wp-reservas'); ?></td></tr>
                    <tr><th><?php esc_html_e('Días habilitados', 'wp-reservas'); ?></th><td>
                        <?php foreach ($dias as $i => $dia) : ?>
                            <label style="margin-right:12px"><input type="checkbox" name="<?php echo esc_attr($n); ?>[dias][]" value="<?php echo (int) $i; ?>" <?php checked(in_array($i, (array) $o['dias'], true)); ?>> <?php echo esc_html($dia); ?></label>
                        <?php endforeach; ?></td></tr>
                    <tr><th><?php esc_html_e('Fechas bloqueadas', 'wp-reservas'); ?></th><td>
                        <textarea rows="3" class="large-text code" name="<?php echo esc_attr($n); ?>[bloqueos]"><?php echo esc_textarea($o['bloqueos']); ?></textarea>
                        <p class="description"><?php esc_html_e('Una por línea, en formato AAAA-MM-DD (feriados, vacaciones, eventos privados).', 'wp-reservas'); ?></p></td></tr>
                    <tr><th><?php esc_html_e('Habitaciones', 'wp-reservas'); ?></th><td>
                        <textarea rows="4" class="large-text code" name="<?php echo esc_attr($n); ?>[habitaciones]"><?php echo esc_textarea($o['habitaciones']); ?></textarea>
                        <p class="description"><?php esc_html_e('Una por línea: Nombre|cantidad|precio por noche. El precio es opcional.', 'wp-reservas'); ?></p></td></tr>
                    <tr><th><?php esc_html_e('Anticipación máxima', 'wp-reservas'); ?></th><td>
                        <input type="number" min="1" name="<?php echo esc_attr($n); ?>[anticipacion]" value="<?php echo esc_attr($o['anticipacion']); ?>"> <?php esc_html_e('días', 'wp-reservas'); ?></td></tr>
                    <tr><th><?php esc_html_e('Email para avisos', 'wp-reservas'); ?></th><td>
                        <input type="email" class="regular-text" name="<?php echo esc_attr($n); ?>[email_admin]" value="<?php echo esc_attr($o['email_admin']); ?>"></td></tr>
                    <tr><th><?php esc_html_e('Moneda', 'wp-reservas'); ?></th><td>
                        <input type="text" class="small-text" name="<?php echo esc_attr($n); ?>[moneda]" value="<?php echo esc_attr($o['moneda']); ?>"></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <h2><?php esc_html_e('Calendario (.ics)', 'wp-reservas'); ?></h2>
            <p><?php esc_html_e('Suscríbete a esta URL desde Google Calendar (Otros calendarios → Desde URL), Apple Calendar u Outlook para ver las reservas confirmadas y pendientes. Es privada: no la compartas.', 'wp-reservas'); ?></p>
            <p><input type="text" class="large-text code" readonly value="<?php echo esc_url($ics); ?>" onclick="this.select()"></p>
            <h2><?php esc_html_e('Shortcodes', 'wp-reservas'); ?></h2>
            <p><code>[reservas tipo="restaurante"]</code> &nbsp; <code>[reservas tipo="hotel"]</code></p>
        </div>
        <?php
    }
}
