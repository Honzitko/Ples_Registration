<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action('admin_menu','pr_admin_menu');
function pr_admin_menu() {
    add_menu_page('Ples Registration','Ples Registration','manage_options','pr-events','pr_admin_events_page','dashicons-tickets-alt',28);
    add_submenu_page('pr-events','Akce',      'Akce',      'manage_options','pr-events',    'pr_admin_events_page');
    add_submenu_page('pr-events','Objednávky','Objednávky','manage_options','pr-orders',    'pr_admin_orders_page');
    add_submenu_page('pr-events','Šablony',   'Šablony',   'manage_options','pr-templates', 'pr_admin_templates_page');
    add_submenu_page('pr-events','Nastavení', 'Nastavení', 'manage_options','pr-settings',  'pr_admin_settings_page');
}
