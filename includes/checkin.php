<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function pr_checkin_verify_pin( $pin ) {
    $stored = get_option('pr_checkin_pin', '1234');
    return hash_equals( $stored, trim($pin) );
}

function pr_checkin_session_key() {
    return 'pr_checkin_auth_' . COOKIEHASH;
}

function pr_checkin_is_authed() {
    $key = pr_checkin_session_key();
    return ! empty($_COOKIE[$key]) && $_COOKIE[$key] === md5( get_option('pr_checkin_pin') . SECURE_AUTH_SALT );
}

function pr_checkin_set_auth() {
    $key   = pr_checkin_session_key();
    $value = md5( get_option('pr_checkin_pin') . SECURE_AUTH_SALT );
    setcookie( $key, $value, time() + 12 * HOUR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
}

function pr_checkin_logout() {
    $key = pr_checkin_session_key();
    setcookie( $key, '', time() - HOUR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );
}

function pr_process_checkin( $token ) {
    global $wpdb;
    $ticket = pr_get_ticket_by_token( $token );

    if ( ! $ticket ) {
        return ['status' => 'error', 'message' => 'Vstupenka nenalezena.', 'color' => 'red'];
    }
    if ( $ticket->order_status !== 'paid' ) {
        return ['status' => 'invalid', 'message' => 'Vstupenka není zaplacena. Stav objednávky: ' . $ticket->order_status, 'color' => 'orange', 'ticket' => $ticket];
    }
    if ( $ticket->checked_in ) {
        return ['status' => 'duplicate', 'message' => 'Vstupenka již byla použita dne ' . pr_format_date($ticket->checked_in_at) . '.', 'color' => 'orange', 'ticket' => $ticket];
    }

    $wpdb->update( PR_TICKETS,
        ['checked_in' => 1, 'checked_in_at' => current_time('mysql')],
        ['qr_token' => $token],
        ['%d','%s'], ['%s']
    );
    $ticket->checked_in    = 1;
    $ticket->checked_in_at = current_time('mysql');
    return ['status' => 'ok', 'message' => '✅ Vstup povolen!', 'color' => 'green', 'ticket' => $ticket];
}
