<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function pr_generate_order_ref() {
    return 'PR-' . strtoupper( substr( md5( uniqid( mt_rand(), true ) ), 0, 10 ) );
}

function pr_generate_var_symbol() {
    global $wpdb;
    do {
        $vs = str_pad( mt_rand(10000000, 99999999), 8, '0', STR_PAD_LEFT );
        $exists = $wpdb->get_var( $wpdb->prepare("SELECT id FROM ".PR_ORDERS." WHERE var_symbol=%s",$vs) );
    } while ($exists);
    return $vs;
}

function pr_generate_qr_token() { return bin2hex( random_bytes(24) ); }

function pr_get_event($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM ".PR_EVENTS." WHERE id=%d",$id));
}
function pr_get_ticket_types($event_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM ".PR_TICKET_TYPES." WHERE event_id=%d AND active=1 ORDER BY sort_order,id",$event_id));
}
function pr_get_ticket_type($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM ".PR_TICKET_TYPES." WHERE id=%d",$id));
}
function pr_get_order($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM ".PR_ORDERS." WHERE id=%d",$id));
}
function pr_get_order_by_ref($ref) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM ".PR_ORDERS." WHERE order_ref=%s",$ref));
}
function pr_get_order_items($order_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM ".PR_ORDER_ITEMS." WHERE order_id=%d",$order_id));
}
function pr_get_order_tickets($order_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM ".PR_TICKETS." WHERE order_id=%d ORDER BY type_id,seq_number",$order_id));
}
function pr_get_ticket_by_token($token) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT t.*, e.name as event_name, e.event_date, e.location,
                o.buyer_name, o.buyer_email, o.order_ref, o.status as order_status
         FROM ".PR_TICKETS." t
         JOIN ".PR_ORDERS." o ON o.id=t.order_id
         JOIN ".PR_EVENTS." e ON e.id=t.event_id
         WHERE t.qr_token=%s",$token));
}

function pr_format_price($amount) {
    return number_format((float)$amount,0,',',' ').' Kč';
}
function pr_format_date($date) {
    return date_i18n('j. n. Y H:i',strtotime($date));
}

/**
 * Check whether the orders table currently has all address columns.
 *
 * The result is cached for the duration of the request to avoid repeated
 * information-schema lookups while rendering/saving orders. Pass true to
 * clear the cache after an in-request schema migration.
 */
function pr_orders_have_address_columns($reset_cache = false) {
    static $has_columns = null;

    if ($reset_cache) {
        $has_columns = null;
    }

    if ($has_columns !== null) {
        return $has_columns;
    }

    foreach (['buyer_street', 'buyer_city', 'buyer_postcode'] as $column) {
        if (!pr_db_column_exists(PR_ORDERS, $column)) {
            $has_columns = false;
            return $has_columns;
        }
    }

    $has_columns = true;
    return $has_columns;
}

/**
 * Clear the cached orders address column check.
 */
function pr_reset_orders_address_columns_cache() {
    pr_orders_have_address_columns(true);
}

function pr_reserve_type($type_id,$qty) {
    global $wpdb;
    return (bool)$wpdb->query($wpdb->prepare(
        "UPDATE ".PR_TICKET_TYPES." SET sold=sold+%d WHERE id=%d AND active=1 AND (capacity-sold)>=%d",
        $qty,$type_id,$qty));
}
function pr_release_type($type_id,$qty) {
    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "UPDATE ".PR_TICKET_TYPES." SET sold=GREATEST(0,sold-%d) WHERE id=%d",$qty,$type_id));
}

/**
 * Generate individual ticket rows in DB for an order.
 */
function pr_generate_tickets($order_id) {
    global $wpdb;
    $items = pr_get_order_items($order_id);
    $order = pr_get_order($order_id);
    foreach ($items as $item) {
        for ($i=1; $i<=$item->quantity; $i++) {
            $wpdb->insert(PR_TICKETS,[
                'order_id'     => $order_id,
                'order_item_id'=> $item->id,
                'event_id'     => $order->event_id,
                'type_id'      => $item->type_id,
                'type_name'    => $item->type_name,
                'qr_token'     => pr_generate_qr_token(),
                'seq_number'   => $i,
            ],['%d','%d','%d','%d','%s','%s','%d']);
        }
    }
}

/**
 * Mark order as paid (admin manual action).
 */
function pr_mark_paid($order_id, $note='') {
    global $wpdb;
    $order = pr_get_order($order_id);
    if (!$order || $order->status==='paid') return false;
    $wpdb->update(PR_ORDERS,
        ['status'=>'paid','paid_at'=>current_time('mysql'),'payment_note'=>$note],
        ['id'=>$order_id],['%s','%s','%s'],['%d']);
    return true;
}
