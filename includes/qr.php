<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function pr_checkin_url( $token ) {
    return home_url( '/ples-checkin/?token=' . urlencode($token) );
}

function pr_qr_img_url( $data, $size = 200 ) {
    return 'https://chart.googleapis.com/chart?cht=qr&chs=' . $size . 'x' . $size
         . '&chl=' . rawurlencode($data) . '&choe=UTF-8';
}

/**
 * Czech banking QR payment string (ČNB standard)
 * Used on confirmation email and order detail.
 */
function pr_banking_qr_string( $iban, $amount, $var_symbol, $message = '' ) {
    // SPD format
    $qr  = 'SPD*1.0';
    $qr .= '*ACC:' . preg_replace('/\s+/', '', $iban);
    $qr .= '*AM:'  . number_format((float)$amount, 2, '.', '');
    $qr .= '*CC:CZK';
    $qr .= '*VS:'  . $var_symbol;
    if ( $message ) $qr .= '*MSG:' . substr(preg_replace('/[^a-zA-Z0-9 ]/', '', $message), 0, 60);
    return $qr;
}

function pr_banking_qr_img_url( $iban, $amount, $var_symbol, $message = '', $size = 200 ) {
    $data = pr_banking_qr_string( $iban, $amount, $var_symbol, $message );
    return pr_qr_img_url( $data, $size );
}
