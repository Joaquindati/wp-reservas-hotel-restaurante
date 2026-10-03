<?php
/**
 * Panel: listado con columnas y filtro por estado, edición y confirmar/cancelar en un clic.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RHR_Admin {

    public static function init() {
        $cpt = RHR_Store::CPT;
        add_filter("manage_{$cpt}_posts_columns", array(__CLASS__, 'columns'));
        add_action("manage_{$cpt}_posts_custom_column", array(__CLASS__, 'column'), 10, 2);
        add_filter("post_row_actions", array(__CLASS__, 'row_actions'), 10, 2);
        add_action('admin_post_rhr_estado', array(__CLASS__, 'cambiar_estado'));
        add_action('add_meta_boxes', array(__CLASS__, 'meta_box'));
        add_action("save_post_{$cpt}", array(__CLASS__, 'save'), 10, 2);
        add_action('restrict_manage_posts', array(__CLASS__, 'filtro'));
        add_action('pre_get_posts', array(__CLASS__, 'aplicar_filtro'));
        add_action('admin_head', array(__CLASS__, 'css'));
    }

    public static function columns($cols) {
        return array(
            'cb'       => $cols['cb'],
            'title'    => __('Reserva', 'wp-reservas'),
            'rhr_tipo' => __('Tipo', 'wp-reservas'),
            'rhr_cuando' => __('Cuándo', 'wp-reservas'),
            'rhr_detalle' => __('Detalle', 'wp-reservas'),
            'rhr_contacto' => __('Contacto', 'wp-reservas'),
            'rhr_estado' => __('Estado', 'wp-reservas'),
        );
    }

    public static function column($col, $id) {
        $r = RHR_Store::get($id);
        switch ($col) {
            case 'rhr_tipo':
                echo $r['tipo'] === 'hotel' ? esc_html__('Habitación', 'wp-reservas') : esc_html__('Mesa', 'wp-reservas');
                break;
            case 'rhr_cuando':
                echo esc_html($r['tipo'] === 'hotel' ? $r['checkin'] . ' → ' . $r['checkout'] : $r['fecha'] . ' ' . $r['hora']);
                break;
            case 'rhr_detalle':
                if ($r['tipo'] === 'hotel') {
                    $habs = RHR_Settings::habitaciones();
                    echo esc_html((isset($habs[$r['habitacion']]) ? $habs[$r['habitacion']]['nombre'] : $r['habitacion']) . ' · ' . $r['personas']);
                } else {
                    echo esc_html(sprintf(_n('%d persona', '%d personas', (int) $r['personas'], 'wp-reservas'), $r['personas']));
                }
                break;
            case 'rhr_contacto':
                printf('%s<br><a href="mailto:%s">%s</a>', esc_html($r['telefono']), esc_attr($r['email']), esc_html($r['email']));
                break;
            case 'rhr_estado':
                printf('<span class="rhr-badge rhr-%1$s">%1$s</span>', esc_html($r['estado']));
                break;
        }
    }

    public static function row_actions($actions, $post) {
        if ($post->post_type !== RHR_Store::CPT || !current_user_can('edit_post', $post->ID)) {
            return $actions;
        }
        $estado = get_post_meta($post->ID, '_rhr_estado', true);
        foreach (array('confirmada' => __('Confirmar', 'wp-reservas'), 'cancelada' => __('Cancelar', 'wp-reservas')) as $e => $label) {
            if ($estado !== $e) {
                $url = wp_nonce_url(admin_url('admin-post.php?action=rhr_estado&id=' . $post->ID . '&estado=' . $e), 'rhr_estado_' . $post->ID);
                $actions['rhr_' . $e] = '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
            }
        }
        unset($actions['inline hide-if-no-js']);
        return $actions;
    }

    public static function cambiar_estado() {
        $id = absint($_GET['id'] ?? 0);
        $estado = sanitize_key($_GET['estado'] ?? '');
        check_admin_referer('rhr_estado_' . $id);
        if (!current_user_can('edit_post', $id) || get_post_type($id) !== RHR_Store::CPT || !in_array($estado, RHR_Store::ESTADOS, true)) {
            wp_die(esc_html__('No puedes hacer eso.', 'wp-reservas'), 403);
        }
        self::set_estado($id, $estado);
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=' . RHR_Store::CPT));
        exit;
    }

    /** Cambia el estado y avisa al cliente sólo si de verdad cambió. */
    public static function set_estado($id, $estado) {
        $antes = get_post_meta($id, '_rhr_estado', true);
        if ($antes === $estado) {
            return;
        }
        update_post_meta($id, '_rhr_estado', $estado);
        do_action('rhr_estado_cambiado', $id, $estado);
    }

    public static function meta_box() {
        add_meta_box('rhr_datos', __('Datos de la reserva', 'wp-reservas'), array(__CLASS__, 'render_box'), RHR_Store::CPT, 'normal', 'high');
    }

    public static function render_box($post) {
        $r = RHR_Store::get($post->ID);
        wp_nonce_field('rhr_guardar', '_rhr_box');
        $campo = function ($k, $label, $type = 'text') use ($r) {
            printf('<p><label><strong>%s</strong><br><input type="%s" name="rhr[%s]" value="%s" class="widefat"></label></p>',
                esc_html($label), esc_attr($type), esc_attr($k), esc_attr($r[$k]));
        };
        echo '<p><label><strong>' . esc_html__('Estado', 'wp-reservas') . '</strong><br><select name="rhr[estado]">';
        foreach (RHR_Store::ESTADOS as $e) {
            printf('<option value="%1$s" %2$s>%1$s</option>', esc_attr($e), selected($r['estado'], $e, false));
        }
        echo '</select></label></p>';
        echo '<p><label><strong>' . esc_html__('Tipo', 'wp-reservas') . '</strong><br><select name="rhr[tipo]">';
        foreach (array('restaurante' => __('Mesa', 'wp-reservas'), 'hotel' => __('Habitación', 'wp-reservas')) as $k => $l) {
            printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($r['tipo'] ?: 'restaurante', $k, false), esc_html($l));
        }
        echo '</select></label></p>';
        echo '<div class="rhr-grid">';
        $campo('fecha', __('Fecha (mesa)', 'wp-reservas'), 'date');
        $campo('hora', __('Hora (mesa)', 'wp-reservas'), 'time');
        $campo('checkin', __('Llegada (habitación)', 'wp-reservas'), 'date');
        $campo('checkout', __('Salida (habitación)', 'wp-reservas'), 'date');
        echo '</div>';
        echo '<p><label><strong>' . esc_html__('Habitación', 'wp-reservas') . '</strong><br><select name="rhr[habitacion]"><option value="">—</option>';
        foreach (RHR_Settings::habitaciones() as $k => $h) {
            printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($r['habitacion'], $k, false), esc_html($h['nombre']));
        }
        echo '</select></label></p>';
        echo '<div class="rhr-grid">';
        $campo('personas', __('Personas', 'wp-reservas'), 'number');
        $campo('nombre', __('Nombre', 'wp-reservas'));
        $campo('email', __('Email', 'wp-reservas'), 'email');
        $campo('telefono', __('Teléfono', 'wp-reservas'), 'tel');
        echo '</div>';
        printf('<p><label><strong>%s</strong><br><textarea name="rhr[notas]" rows="3" class="widefat">%s</textarea></label></p>',
            esc_html__('Comentarios', 'wp-reservas'), esc_textarea($r['notas']));
        if ($r['codigo']) {
            printf('<p>%s <code>%s</code></p>', esc_html__('Código:', 'wp-reservas'), esc_html($r['codigo']));
        }
    }

    public static function save($id, $post) {
        if (!isset($_POST['_rhr_box']) || !wp_verify_nonce(sanitize_key($_POST['_rhr_box']), 'rhr_guardar')
            || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $id)) {
            return;
        }
        $in = isset($_POST['rhr']) && is_array($_POST['rhr']) ? $_POST['rhr'] : array();
        $estado = sanitize_key($in['estado'] ?? '');
        unset($in['estado'], $in['codigo']);
        RHR_Store::save_meta($id, $in);
        if (!get_post_meta($id, '_rhr_codigo', true)) {
            update_post_meta($id, '_rhr_codigo', strtoupper(wp_generate_password(6, false)));
        }
        if (in_array($estado, RHR_Store::ESTADOS, true)) {
            self::set_estado($id, $estado);
        }
    }

    public static function filtro($post_type) {
        if ($post_type !== RHR_Store::CPT) {
            return;
        }
        $actual = sanitize_key($_GET['rhr_estado'] ?? '');
        echo '<select name="rhr_estado"><option value="">' . esc_html__('Todos los estados', 'wp-reservas') . '</option>';
        foreach (RHR_Store::ESTADOS as $e) {
            printf('<option value="%1$s" %2$s>%1$s</option>', esc_attr($e), selected($actual, $e, false));
        }
        echo '</select>';
    }

    public static function aplicar_filtro($q) {
        if (!is_admin() || !$q->is_main_query() || $q->get('post_type') !== RHR_Store::CPT) {
            return;
        }
        $estado = sanitize_key($_GET['rhr_estado'] ?? '');
        if (in_array($estado, RHR_Store::ESTADOS, true)) {
            $q->set('meta_query', array(array('key' => '_rhr_estado', 'value' => $estado)));
        }
    }

    public static function css() {
        $s = get_current_screen();
        if (!$s || $s->post_type !== RHR_Store::CPT) {
            return;
        }
        echo '<style>.rhr-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;text-transform:capitalize}'
            . '.rhr-pendiente{background:#fcf0c3;color:#6b5200}.rhr-confirmada{background:#d1f2d9;color:#0f5a23}.rhr-cancelada{background:#f6d5d5;color:#7a1414}'
            . '.rhr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0 16px}</style>';
    }
}
