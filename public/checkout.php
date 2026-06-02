<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action('wp_ajax_nopriv_pr_submit_order','pr_ajax_submit_order');
add_action('wp_ajax_pr_submit_order',       'pr_ajax_submit_order');

/**
 * Send error response while ensuring no buffered output corrupts JSON.
 */
function pr_send_error($message) {
    while (ob_get_level()) ob_end_clean();
    wp_send_json_error(['message' => $message]);
}

function pr_is_valid_phone($phone) {
    return (bool) preg_match('/^(?:\+\d{12}|\+\d{3} \d{3} \d{3} \d{3}|\d{9}|\d{3} \d{3} \d{3})$/', $phone);
}

function pr_db_error_is_unknown_column($error) {
    return stripos((string) $error, 'unknown column') !== false;
}

function pr_build_order_insert_payload($event_id, $order_ref, $var_sym, $name, $email, $phone, $street, $city, $postcode, $total) {
    $data = [
        'event_id'    => $event_id,
        'order_ref'   => $order_ref,
        'var_symbol'  => $var_sym,
        'buyer_name'  => $name,
        'buyer_email' => $email,
        'buyer_phone' => $phone,
        'total_price' => $total,
        'status'      => 'pending',
    ];
    $formats = ['%d', '%s', '%s', '%s', '%s', '%s', '%f', '%s'];

    if (pr_orders_have_address_columns()) {
        $data = array_merge(
            array_slice($data, 0, 6, true),
            [
                'buyer_street'   => $street,
                'buyer_city'     => $city,
                'buyer_postcode' => $postcode,
            ],
            array_slice($data, 6, null, true)
        );
        $formats = ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s'];
    }

    return [$data, $formats];
}

function pr_insert_order_with_schema_retry($event_id, $order_ref, $var_sym, $name, $email, $phone, $street, $city, $postcode, $total) {
    global $wpdb;

    list($data, $formats) = pr_build_order_insert_payload($event_id, $order_ref, $var_sym, $name, $email, $phone, $street, $city, $postcode, $total);
    $inserted = $wpdb->insert(PR_ORDERS, $data, $formats);

    if ($inserted !== false) {
        return [true, (int) $wpdb->insert_id, ''];
    }

    $first_error = $wpdb->last_error;
    if (!pr_db_error_is_unknown_column($first_error)) {
        return [false, 0, $first_error];
    }

    error_log('PR order insert hit unknown column, running table migration and retrying: ' . $first_error);
    pr_create_tables();
    pr_reset_orders_address_columns_cache();

    list($data, $formats) = pr_build_order_insert_payload($event_id, $order_ref, $var_sym, $name, $email, $phone, $street, $city, $postcode, $total);
    $inserted = $wpdb->insert(PR_ORDERS, $data, $formats);

    if ($inserted !== false) {
        return [true, (int) $wpdb->insert_id, ''];
    }

    return [false, 0, $wpdb->last_error ?: $first_error];
}

function pr_ajax_submit_order() {
    // Suppress PHP notice/deprecation output that would corrupt JSON response
    // (e.g. WP Featherlight and other plugins echo warnings)
    @ini_set('display_errors', '0');
    error_reporting(E_ERROR | E_PARSE);

    // Clear any previous output buffers to ensure clean JSON
    while (ob_get_level()) ob_end_clean();
    ob_start();

    check_ajax_referer('pr_ajax','nonce');
    global $wpdb;

    $event_id = (int)($_POST['event_id']??0);
    $name     = sanitize_text_field($_POST['buyer_name']??'');
    $email    = sanitize_email($_POST['buyer_email']??'');
    $phone    = sanitize_text_field($_POST['buyer_phone']??'');
    $street   = sanitize_text_field($_POST['buyer_street']??'');
    $city     = sanitize_text_field($_POST['buyer_city']??'');
    $postcode = sanitize_text_field($_POST['buyer_postcode']??'');

    $event = pr_get_event($event_id);
    if (!$event || $event->status !== 'active')
        pr_send_error('Akce není dostupná.');

    if (!$name || !is_email($email))
        pr_send_error('Vyplňte prosím jméno a platnou e-mailovou adresu.');

    if (!$street || !$city || !$postcode)
        pr_send_error('Vyplňte prosím kompletní adresu.');

    if ($phone && !pr_is_valid_phone($phone))
        pr_send_error('Telefon zadejte ve formátu +XXXXXXXXXXXX, +XXX XXX XXX XXX, XXXXXXXXX nebo XXX XXX XXX.');

    // Parse quantities
    $qtys = [];
    foreach (($_POST['qty']??[]) as $type_id => $qty) {
        $qty = (int)$qty;
        if ($qty > 0) $qtys[(int)$type_id] = min($qty,10);
    }
    if (empty($qtys)) pr_send_error('Vyberte alespoň jednu vstupenku.');

    // Validate & reserve
    $total = 0; $lines = [];
    foreach ($qtys as $type_id => $qty) {
        $type = pr_get_ticket_type($type_id);
        if (!$type || $type->event_id != $event_id || !$type->active)
            pr_send_error('Neplatný typ vstupenky.');
        if (!pr_reserve_type($type_id,$qty))
            pr_send_error('Vstupenka „'.esc_html($type->name).'" již není v požadovaném počtu dostupná.');
        $sub = $type->price * $qty; $total += $sub;
        $lines[] = ['type'=>$type,'qty'=>$qty,'subtotal'=>$sub];
    }

    // Create order
    $order_ref = pr_generate_order_ref();
    $var_sym   = pr_generate_var_symbol();

    list($inserted, $order_id, $db_error) = pr_insert_order_with_schema_retry(
        $event_id,
        $order_ref,
        $var_sym,
        $name,
        $email,
        $phone,
        $street,
        $city,
        $postcode,
        $total
    );

    if (!$inserted || !$order_id) {
        foreach ($lines as $l) {
            pr_release_type($l['type']->id, $l['qty']);
        }
        error_log('PR order insert failed: ' . $db_error);
        $message = 'Objednávku se nepodařilo uložit. Zkuste to prosím znovu.';
        if (current_user_can('manage_options') && $db_error) {
            $message .= ' Chyba databáze: ' . $db_error;
        }
        pr_send_error($message);
    }

    // Create order items
    foreach ($lines as $l) {
        $wpdb->insert(PR_ORDER_ITEMS,[
            'order_id'  => $order_id,
            'type_id'   => $l['type']->id,
            'type_name' => $l['type']->name,
            'unit_price'=> $l['type']->price,
            'quantity'  => $l['qty'],
        ],['%d','%d','%s','%f','%d']);
    }

    // Send email immediately — wrapped in try-catch so AJAX never fails on email problems.
    // Build the object from the just-validated form data instead of depending only
    // on an immediate read after write. Some hosts/cache layers can return a stale
    // row right after insert, which made the email recipient look empty even though
    // the order was saved with the correct address.
    $order = (object) [
        'id'             => $order_id,
        'event_id'       => $event_id,
        'order_ref'      => $order_ref,
        'var_symbol'     => $var_sym,
        'buyer_name'     => $name,
        'buyer_email'    => $email,
        'buyer_phone'    => $phone,
        'buyer_street'   => $street,
        'buyer_city'     => $city,
        'buyer_postcode' => $postcode,
        'total_price'    => $total,
        'status'         => 'pending',
    ];

    $stored_order = pr_get_order($order_id);
    if ($stored_order) {
        $order = (object) array_merge((array) $order, (array) $stored_order);
        // Keep the already validated submitted address for the actual recipient.
        $order->buyer_email = $email;
    }
    $items = pr_get_order_items($order_id);
    $email_sent  = false;
    $email_error = '';

    // Capture wp_mail failure reason
    $mail_error_handler = function($wp_error) use (&$email_error) {
        $email_error = $wp_error->get_error_message();
        error_log('PR wp_mail failed: ' . $email_error);
    };
    add_action('wp_mail_failed', $mail_error_handler);

    try {
        $email_sent = pr_send_order_email($order, $event, $items);
        if (!$email_sent && !$email_error) {
            $email_error = 'wp_mail() vrátil false bez konkrétní chyby (často: chybí SMTP / mail() blokován hostingem)';
            error_log('PR email failed: ' . $email_error);
        }
    } catch (Throwable $e) {
        $email_error = $e->getMessage();
        error_log('PR email exception: ' . $email_error);
    }
    remove_action('wp_mail_failed', $mail_error_handler);

    // Discard any output captured during processing (PHP notices, warnings from other plugins)
    while (ob_get_level()) ob_end_clean();

    $admin_can_see_error = current_user_can('manage_options');

    wp_send_json_success([
        'message'    => $email_sent
            ? 'Objednávka odeslána! Zkontrolujte e-mail — vstupenky a platební instrukce jsou již v příloze.'
            : 'Objednávka vytvořena. E-mail se nepodařilo odeslat, kontaktujte prosím pořadatele.',
        'order_ref'  => $order_ref,
        'var_symbol' => $var_sym,
        'total'      => pr_format_price($total),
        'email_sent' => $email_sent,
        // Show technical error only to admins
        'email_error'=> $admin_can_see_error ? $email_error : '',
    ]);
}
