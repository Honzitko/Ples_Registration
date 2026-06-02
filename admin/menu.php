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


add_action('admin_init', 'pr_handle_admin_auto_repair');
function pr_handle_admin_auto_repair() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $page = sanitize_key($_GET['page'] ?? '');
    $action = sanitize_key($_GET['action'] ?? '');
    if ($page !== 'pr-settings' || $action !== 'auto_repair') {
        return;
    }

    delete_transient('pr_schema_ok');
    pr_create_tables();

    $missing = pr_get_missing_tables();
    $status = empty($missing) ? 'success' : 'failed';
    wp_safe_redirect(admin_url('admin.php?page=pr-settings&pr_auto_repaired=' . $status . '#pr-db-maintenance'));
    exit;
}

add_action('admin_notices', 'pr_admin_missing_tables_notice');
function pr_admin_missing_tables_notice() {
    if (!current_user_can('manage_options') || !pr_is_plugin_admin_page()) {
        return;
    }

    $missing = pr_get_missing_tables();
    if (empty($missing)) {
        return;
    }

    $repair_url = admin_url('admin.php?page=pr-settings&action=auto_repair#pr-db-maintenance');
    ?>
    <div class="notice notice-error">
        <p>
            <strong>⚠️ Ples Registration — chybí databázové tabulky:</strong>
            <code><?php echo esc_html(implode(', ', $missing)); ?></code>.
            <a href="<?php echo esc_url($repair_url); ?>">Klikněte zde pro opravu.</a>
        </p>
    </div>
    <?php
}

function pr_is_plugin_admin_page() {
    $page = sanitize_key($_GET['page'] ?? '');
    if (strpos($page, 'pr-') === 0) {
        return true;
    }

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen) {
        return false;
    }

    return strpos((string) $screen->id, 'pr-') !== false;
}
