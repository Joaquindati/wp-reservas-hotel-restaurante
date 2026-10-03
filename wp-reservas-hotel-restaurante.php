<?php
/**
 * Plugin Name:       Reservas para Hoteles y Restaurantes
 * Plugin URI:        https://github.com/Joaquindati/wp-reservas-hotel-restaurante
 * Description:       Sistema de reservas directas para WordPress: mesas por franja horaria con control de capacidad y habitaciones por noche con disponibilidad real. Emails automáticos, panel de gestión y calendario .ics para Google Calendar.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Joaquín Dati
 * Author URI:        https://joaquindati.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-reservas
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RHR_VERSION', '1.0.0');
define('RHR_FILE', __FILE__);
define('RHR_DIR', plugin_dir_path(__FILE__));
define('RHR_URL', plugin_dir_url(__FILE__));

require_once RHR_DIR . 'includes/class-settings.php';
require_once RHR_DIR . 'includes/class-store.php';
require_once RHR_DIR . 'includes/class-notifier.php';
require_once RHR_DIR . 'includes/class-form.php';
require_once RHR_DIR . 'includes/class-admin.php';

RHR_Settings::init();
RHR_Store::init();
RHR_Notifier::init();
RHR_Form::init();
RHR_Admin::init();

register_activation_hook(__FILE__, function () {
    RHR_Store::register_post_type();
    // token del calendario .ics: sin él, cualquiera podría leer las reservas
    if (!get_option('rhr_ics_token')) {
        update_option('rhr_ics_token', wp_generate_password(32, false));
    }
    if (!get_option(RHR_Settings::OPTION)) {
        update_option(RHR_Settings::OPTION, RHR_Settings::defaults());
    }
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
