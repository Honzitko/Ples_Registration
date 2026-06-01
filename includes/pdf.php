<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PDF ticket generation.
 * Reads templates/ticket.html, substitutes {{variables}}, renders one page per ticket.
 * Uses mPDF if available (composer require mpdf/mpdf), otherwise outputs print-ready HTML.
 */

// ── Public API ────────────────────────────────────────────────────────────────

function pr_generate_ticket_pdf( $order_id ) {
    $order   = pr_get_order( $order_id );
    $event   = pr_get_event( $order->event_id );
    $tickets = pr_get_order_tickets( $order_id );

    $html = pr_build_ticket_document( $order, $event, $tickets );

    $dir = wp_upload_dir()['basedir'] . '/pr-tickets/';
    wp_mkdir_p( $dir );

    // Try mPDF
    $autoload = PR_DIR . 'vendor/autoload.php';
    if ( file_exists($autoload) ) {
        require_once $autoload;
        if ( class_exists('\Mpdf\Mpdf') ) {
            return pr_render_mpdf( $html, $dir, $order->order_ref );
        }
    }

    // Fallback: print-ready HTML
    $path = $dir . 'ticket-' . $order->order_ref . '.html';
    file_put_contents( $path, $html );
    return $path;
}

// ── Build full HTML document (all tickets concatenated) ───────────────────────

function pr_build_ticket_document( $order, $event, $tickets ) {
    $pages = [];
    foreach ( $tickets as $ticket ) {
        // Remove any previous overrides
        remove_all_filters('pr_ticket_accent_override');
        remove_all_filters('pr_ticket_font_override');
        $tpl    = pr_load_ticket_template( $ticket->type_id );
        $pages[] = pr_render_ticket_page( $tpl, $order, $event, $ticket );
    }
    return implode("\n", $pages);
}

function pr_render_mpdf( $combined_html, $dir, $order_ref ) {
    $mpdf = new \Mpdf\Mpdf([
        'mode'         => 'utf-8',
        'format'       => 'A4',
        'margin_top'   => 0,
        'margin_bottom'=> 0,
        'margin_left'  => 0,
        'margin_right' => 0,
    ]);
    $mpdf->WriteHTML( $combined_html );
    $path = $dir . 'ticket-' . $order_ref . '.pdf';
    $mpdf->Output( $path, 'F' );
    return $path;
}

// ── Template loader ───────────────────────────────────────────────────────────

function pr_load_ticket_template( $type_id = 0 ) {
    global $wpdb;

    // 1. Template assigned to this ticket type
    if ( $type_id ) {
        $type = pr_get_ticket_type( $type_id );
        if ( $type && !empty($type->template_id) ) {
            $tpl = pr_get_template( (int)$type->template_id );
            if ( $tpl && !empty($tpl->html) ) {
                $tpl_accent = $tpl->accent_color;
                $tpl_font   = $tpl->font_family;
                add_filter('pr_ticket_accent_override', function() use ($tpl_accent) { return $tpl_accent; });
                add_filter('pr_ticket_font_override',   function() use ($tpl_font)   { return $tpl_font; });
                return $tpl->html;
            }
        }
    }

    $default_id = (int)get_option('pr_default_template_id', 0);
    if ( $default_id ) {
        $tpl = pr_get_template( $default_id );
        if ( $tpl && !empty($tpl->html) ) {
            $tpl_accent = $tpl->accent_color;
            $tpl_font   = $tpl->font_family;
            add_filter('pr_ticket_accent_override', function() use ($tpl_accent) { return $tpl_accent; });
            add_filter('pr_ticket_font_override',   function() use ($tpl_font)   { return $tpl_font; });
            return $tpl->html;
        }
    }

    // 3. User-customized file in uploads
    $custom = wp_upload_dir()['basedir'] . '/pr-ticket-template/ticket.html';
    if ( file_exists($custom) ) return file_get_contents($custom);

    // 4. Plugin default file
    $default = PR_DIR . 'templates/ticket.html';
    if ( file_exists($default) ) return file_get_contents($default);

    return '<html><body><p>Šablona vstupenky nenalezena.</p></body></html>';
}

/**
 * Save a custom template to uploads (survives plugin updates).
 */
function pr_save_custom_template( $html ) {
    $dir = wp_upload_dir()['basedir'] . '/pr-ticket-template/';
    wp_mkdir_p( $dir );
    file_put_contents( $dir . 'ticket.html', $html );
}

function pr_delete_custom_template() {
    $path = wp_upload_dir()['basedir'] . '/pr-ticket-template/ticket.html';
    if ( file_exists($path) ) unlink($path);
}

function pr_has_custom_template() {
    return file_exists( wp_upload_dir()['basedir'] . '/pr-ticket-template/ticket.html' );
}

// ── Per-ticket rendering ──────────────────────────────────────────────────────

function pr_render_ticket_page( $tpl, $order, $event, $ticket ) {
    $vars = pr_ticket_vars( $order, $event, $ticket );

    // Process {{#if var}}...{{/if}} blocks
    $html = preg_replace_callback(
        '/\{\{#if (\w+)\}\}(.*?)\{\{\/if\}\}/s',
        function($m) use ($vars) {
            return !empty($vars[$m[1]]) ? $m[2] : '';
        },
        $tpl
    );

    // Process {{#if_any var1 var2}}...{{/if_any}} blocks
    $html = preg_replace_callback(
        '/\{\{#if_any ([^\}]+)\}\}(.*?)\{\{\/if_any\}\}/s',
        function($m) use ($vars) {
            $keys = explode(' ', trim($m[1]));
            foreach ($keys as $k) {
                if (!empty($vars[trim($k)])) return $m[2];
            }
            return '';
        },
        $html
    );

    // Replace all {{variable}} placeholders
    foreach ( $vars as $key => $value ) {
        $html = str_replace( '{{' . $key . '}}', $value, $html );
    }

    // Clean up any unreplaced tags
    $html = preg_replace('/\{\{[^}]+\}\}/', '', $html);

    return $html;
}

// ── Variable map ──────────────────────────────────────────────────────────────

function pr_ticket_vars( $order, $event, $ticket ) {
    // Design — respect template-level overrides
    $accent       = apply_filters('pr_ticket_accent_override', get_option('pr_ticket_accent','#1a1a2e'));
    $font         = apply_filters('pr_ticket_font_override',   get_option('pr_ticket_font','Arial'));
    $accent_light = pr_lighten_hex($accent, 96);
    $subtitle    = get_option('pr_ticket_subtitle', '');
    $dress_code  = get_option('pr_ticket_dress_code', '');
    $note        = get_option('pr_ticket_note', '');
    $logo_id     = (int)get_option('pr_ticket_logo_id', 0);

    // Logo
    $logo_img = '';
    if ($logo_id) {
        $logo_url = wp_get_attachment_image_url($logo_id, 'large');
        if ($logo_url) {
            $zoom  = (int)get_option('pr_ticket_logo_zoom', 100);
            $pad   = (int)get_option('pr_ticket_logo_pad', 4);
            $h     = round(44 * $zoom / 100);
            $logo_img = '<img src="' . esc_url($logo_url) . '" '
                . 'style="max-height:' . $h . 'px;width:auto;height:auto;padding:' . $pad . 'px;'
                . 'display:block;max-width:180px;object-fit:contain;flex-shrink:0" alt="Logo">';
        }
    }

    $is_vip = stripos($ticket->type_name, 'vip') !== false;

    return [
        // Org
        'org_name'       => get_option('pr_org_name', get_bloginfo('name')),
        'org_address'    => get_option('pr_org_address', ''),
        // Event
        'event_name'     => $event->name,
        'event_date'     => pr_format_date($event->event_date),
        'event_location' => $event->location ?? '',
        'event_subtitle' => $subtitle,
        // Ticket
        'ticket_type'    => $ticket->type_name,
        'seq_number'     => (string)(int)$ticket->seq_number,
        'is_vip_class'   => $is_vip ? 'ticket-vip' : '',
        // Buyer
        'buyer_name'     => $order->buyer_name,
        'order_ref'      => $order->order_ref,
        // QR
        'qr_img_url'     => pr_qr_img_url(pr_checkin_url($ticket->qr_token), 200),
        // Design
        'accent_color'   => $accent,
        'accent_light'   => $accent_light,
        'font_family'    => $font,
        'logo_img'       => $logo_img,
        // Extras
        'dress_code'     => $dress_code,
        'ticket_note'    => $note,
    ];
}

// ── Colour helper ─────────────────────────────────────────────────────────────

/**
 * Mix a hex colour toward white by $percent (0=original, 100=white).
 */
function pr_lighten_hex($hex, $percent = 90) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    $r = hexdec(substr($hex,0,2));
    $g = hexdec(substr($hex,2,2));
    $b = hexdec(substr($hex,4,2));
    $r = (int)round($r + (255 - $r) * $percent / 100);
    $g = (int)round($g + (255 - $g) * $percent / 100);
    $b = (int)round($b + (255 - $b) * $percent / 100);
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}
