<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'phpmailer_init', 'pr_configure_smtp' );
function pr_configure_smtp( $phpmailer ) {
    if ( ! get_option('pr_smtp_enabled') ) return;
    $host = get_option('pr_smtp_host','');
    $user = get_option('pr_smtp_user','');
    $pass = get_option('pr_smtp_pass','');
    $port = (int) get_option('pr_smtp_port', 587);
    $enc  = get_option('pr_smtp_enc','tls');
    if ( !$host || !$user ) return;
    $phpmailer->isSMTP();
    $phpmailer->Host       = $host;
    $phpmailer->SMTPAuth   = true;
    $phpmailer->Username   = $user;
    $phpmailer->Password   = $pass;
    $phpmailer->Port       = $port;
    $phpmailer->SMTPSecure = $enc === 'ssl' ? 'ssl' : 'tls';
}

function pr_mail_headers() {
    $name  = get_option('pr_email_from_name', get_bloginfo('name'));
    $email = get_option('pr_email_from', get_option('admin_email'));
    return [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $name . ' <' . $email . '>',
    ];
}
