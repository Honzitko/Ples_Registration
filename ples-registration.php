<?php
/**
 * Plugin Name: Ples Registration
 * Plugin URI:  https://softemy.eu
 * Description: Správa registrací a vstupenek na plesy. Více typů vstupenek, PDF lístky, ruční párování plateb, check-in s PIN.
 * Version:     1.2.3
 * Author:      Softemy
 * Text Domain: ples-registration
 * Requires PHP: 7.2
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// PHP version check — must be before any other code
if ( version_compare( PHP_VERSION, '7.2', '<' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p><strong>Ples Registration:</strong> Vyžaduje PHP 7.2 nebo vyšší. Vaše verze: ' . PHP_VERSION . '</p></div>';
    });
    return;
}

define( 'PR_VERSION',      '1.2.3' );
define( 'PR_DIR',          plugin_dir_path( __FILE__ ) );
define( 'PR_URL',          plugin_dir_url( __FILE__ ) );
define( 'PR_EVENTS',       $GLOBALS['wpdb']->prefix . 'pr_events' );
define( 'PR_TICKET_TYPES', $GLOBALS['wpdb']->prefix . 'pr_ticket_types' );
define( 'PR_ORDERS',       $GLOBALS['wpdb']->prefix . 'pr_orders' );
define( 'PR_ORDER_ITEMS',  $GLOBALS['wpdb']->prefix . 'pr_order_items' );
define( 'PR_TICKETS',      $GLOBALS['wpdb']->prefix . 'pr_tickets' );
define( 'PR_TEMPLATES',    $GLOBALS['wpdb']->prefix . 'pr_templates' );

foreach ( [
    'includes/db.php',
    'includes/helpers.php',
    'includes/qr.php',
    'includes/pdf.php',
    'includes/smtp.php',
    'includes/email.php',
    'includes/checkin.php',
    'admin/menu.php',
    'admin/events.php',
    'admin/orders.php',
    'admin/templates.php',
    'admin/settings.php',
    'public/shortcode.php',
    'public/checkout.php',
    'public/checkin-page.php',
] as $file ) {
    require_once PR_DIR . $file;
}

register_activation_hook( __FILE__, 'pr_activate' );
function pr_activate() {
    pr_create_tables();
    pr_set_defaults();
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, function() { flush_rewrite_rules(); } );

// Auto-repair: create tables if missing (runs once per version)
add_action( 'admin_init', function() {
    if ( get_option('pr_db_version') !== PR_VERSION ) {
        pr_create_tables();
        pr_set_defaults();
        update_option('pr_db_version', PR_VERSION);
    }
});

add_action( 'init', 'pr_register_rewrites' );
function pr_register_rewrites() {
    add_rewrite_rule( '^ples-checkin/?$', 'index.php?pr_checkin=1', 'top' );
    add_rewrite_tag( '%pr_checkin%', '1' );
}

add_action( 'wp_enqueue_scripts', function() {
    wp_enqueue_style(  'pr-public', PR_URL . 'assets/css/public.css',  [], PR_VERSION );
    wp_enqueue_script( 'pr-public', PR_URL . 'assets/js/public.js', ['jquery'], PR_VERSION, true );
    wp_localize_script( 'pr-public', 'PR', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('pr_ajax'),
    ]);
} );

add_action( 'admin_enqueue_scripts', function($hook) {
    wp_enqueue_style(  'pr-admin', PR_URL . 'assets/css/admin.css',  [], PR_VERSION );
    wp_enqueue_script( 'pr-admin', PR_URL . 'assets/js/admin.js', ['jquery'], PR_VERSION, true );
} );

/**
 * Ticket preview — renders a sample ticket in browser (linked from settings).
 */
function pr_preview_ticket_html() {
    if ( ! current_user_can('manage_options') ) wp_die('Přístup odepřen.');

    $fake_order  = (object)['id'=>0,'order_ref'=>'PR-PREVIEW','buyer_name'=>'Jan Novák','event_id'=>0];
    $fake_event  = (object)[
        'name'       => get_option('pr_org_name','Vaše Organizace') . ' — Ukázková akce',
        'event_date' => date('Y-m-d H:i:s', strtotime('+30 days')),
        'location'   => 'Praha, Obecní dům',
    ];
    $fake_ticket = (object)['type_name'=>'VIP','qr_token'=>'preview-token-000','seq_number'=>1];

    $tpl  = pr_load_ticket_template();
    $html = pr_render_ticket_page($tpl, $fake_order, $fake_event, $fake_ticket);
    echo $html;
}
