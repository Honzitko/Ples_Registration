<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Safely quote a database identifier for raw SQL fragments.
 */
function pr_quote_db_identifier($identifier) {
    return '`' . str_replace('`', '``', (string) $identifier) . '`';
}

/**
 * Check whether a database table exists.
 */
function pr_db_table_exists($table) {
    global $wpdb;

    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
}

/**
 * Check whether a table column exists.
 */
function pr_db_column_exists($table, $column) {
    global $wpdb;

    if (!pr_db_table_exists($table)) {
        return false;
    }

    $table_sql = pr_quote_db_identifier($table);
    return (bool) $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table_sql} LIKE %s", $column));
}

/**
 * Repair columns added after the initial orders table release.
 *
 * Some sites already have the orders table from an older plugin version. When
 * the address fields are missing, checkout inserts fail with an unknown-column
 * database error and users only see the generic “order could not be saved”
 * message. Running this lightweight repair before checkout keeps existing
 * installations compatible even if activation/dbDelta was skipped.
 */
function pr_repair_order_schema() {
    global $wpdb;

    $table_sql = pr_quote_db_identifier(PR_ORDERS);
    $columns = [
        'buyer_street'   => "ALTER TABLE {$table_sql} ADD COLUMN `buyer_street` VARCHAR(255) NOT NULL DEFAULT '' AFTER `buyer_phone`",
        'buyer_city'     => "ALTER TABLE {$table_sql} ADD COLUMN `buyer_city` VARCHAR(120) NOT NULL DEFAULT '' AFTER `buyer_street`",
        'buyer_postcode' => "ALTER TABLE {$table_sql} ADD COLUMN `buyer_postcode` VARCHAR(20) NOT NULL DEFAULT '' AFTER `buyer_city`",
        'email_status'   => "ALTER TABLE {$table_sql} ADD COLUMN `email_status` ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending' AFTER `status`",
        'email_sent_at'  => "ALTER TABLE {$table_sql} ADD COLUMN `email_sent_at` DATETIME NULL AFTER `email_status`",
    ];

    foreach ($columns as $column => $sql) {
        if (!pr_db_column_exists(PR_ORDERS, $column)) {
            $wpdb->query($sql);
        }
    }
}

function pr_create_tables() {
    global $wpdb;
    $c = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta("CREATE TABLE " . PR_EVENTS . " (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name        VARCHAR(255) NOT NULL,
        description TEXT,
        event_date  DATETIME NOT NULL,
        location    VARCHAR(255),
        status      ENUM('draft','active','closed') NOT NULL DEFAULT 'draft',
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $c;");

    dbDelta("CREATE TABLE " . PR_TICKET_TYPES . " (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id     INT UNSIGNED NOT NULL,
        name         VARCHAR(100) NOT NULL,
        description  VARCHAR(255),
        price        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        capacity     INT UNSIGNED NOT NULL DEFAULT 100,
        sold         INT UNSIGNED NOT NULL DEFAULT 0,
        sort_order   TINYINT UNSIGNED NOT NULL DEFAULT 0,
        active       TINYINT(1) NOT NULL DEFAULT 1,
        template_id  INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY event_id (event_id)
    ) $c;");

    dbDelta("CREATE TABLE " . PR_ORDERS . " (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id     INT UNSIGNED NOT NULL,
        order_ref    VARCHAR(64) NOT NULL UNIQUE,
        var_symbol   VARCHAR(10) NOT NULL UNIQUE,
        buyer_name   VARCHAR(255) NOT NULL,
        buyer_email  VARCHAR(255) NOT NULL,
        buyer_phone  VARCHAR(30),
        buyer_street VARCHAR(255) NOT NULL DEFAULT '',
        buyer_city   VARCHAR(120) NOT NULL DEFAULT '',
        buyer_postcode VARCHAR(20) NOT NULL DEFAULT '',
        total_price  DECIMAL(10,2) NOT NULL,
        status       ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
        email_status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
        email_sent_at DATETIME,
        payment_note VARCHAR(255),
        paid_at      DATETIME,
        created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY event_id (event_id),
        KEY status (status),
        KEY var_symbol (var_symbol)
    ) $c;");

    dbDelta("CREATE TABLE " . PR_ORDER_ITEMS . " (
        id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id   INT UNSIGNED NOT NULL,
        type_id    INT UNSIGNED NOT NULL,
        type_name  VARCHAR(100) NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        quantity   INT UNSIGNED NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        KEY order_id (order_id)
    ) $c;");

    dbDelta("CREATE TABLE " . PR_TICKETS . " (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id      INT UNSIGNED NOT NULL,
        order_item_id INT UNSIGNED NOT NULL,
        event_id      INT UNSIGNED NOT NULL,
        type_id       INT UNSIGNED NOT NULL,
        type_name     VARCHAR(100) NOT NULL,
        qr_token      VARCHAR(64) NOT NULL UNIQUE,
        seq_number    INT UNSIGNED NOT NULL,
        checked_in    TINYINT(1) NOT NULL DEFAULT 0,
        checked_in_at DATETIME,
        PRIMARY KEY (id),
        KEY order_id (order_id),
        KEY qr_token (qr_token),
        KEY event_id (event_id)
    ) $c;");

    dbDelta("CREATE TABLE " . PR_TEMPLATES . " (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name         VARCHAR(100) NOT NULL,
        description  VARCHAR(255),
        oob_key      VARCHAR(50) DEFAULT NULL,
        html         LONGTEXT NOT NULL,
        accent_color VARCHAR(7) NOT NULL DEFAULT '#1a1a2e',
        font_family  VARCHAR(50) NOT NULL DEFAULT 'Arial',
        preview_css  VARCHAR(255) DEFAULT NULL,
        created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY oob_key (oob_key)
    ) $c;");

    pr_repair_order_schema();
}

function pr_set_defaults() {
    $d = array(
        'pr_org_name'            => get_bloginfo('name'),
        'pr_org_address'         => '',
        'pr_org_ico'             => '',
        'pr_bank_account'        => '',
        'pr_bank_name'           => 'Raiffeisenbank',
        'pr_email_from'          => get_option('admin_email'),
        'pr_email_from_name'     => get_bloginfo('name'),
        'pr_email_footer'        => '',
        'pr_smtp_enabled'        => '0',
        'pr_smtp_host'           => '',
        'pr_smtp_port'           => '587',
        'pr_smtp_enc'            => 'tls',
        'pr_smtp_user'           => '',
        'pr_smtp_pass'           => '',
        'pr_checkin_pin'         => '1234',
        'pr_default_template_id' => '0',
        'pr_ticket_accent'       => '#1a1a2e',
        'pr_ticket_font'         => 'Arial',
        'pr_ticket_subtitle'     => '',
        'pr_ticket_dress_code'   => '',
        'pr_ticket_note'         => '',
        'pr_ticket_logo_id'      => '0',
        'pr_ticket_logo_zoom'    => '100',
        'pr_ticket_logo_align'   => 'left',
        'pr_ticket_logo_pad'     => '4',
    );
    foreach ( $d as $k => $v ) {
        if ( get_option($k) === false ) {
            add_option($k, $v);
        }
    }
}
