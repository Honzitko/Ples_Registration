<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Single email sent immediately after order creation.
 * Contains: vstupenky (PDF příloha) + platební instrukce + QR platba.
 */
function pr_send_order_email($order, $event, $items) {
    $recipient = pr_order_recipient_email($order);
    if (!$recipient) {
        throw new InvalidArgumentException('Objednávka nemá platnou e-mailovou adresu příjemce.');
    }

    // Generate tickets first
    pr_generate_tickets($order->id);

    // Try to build a real PDF (requires mPDF). HTML fallback is NOT attached because
    // many mail servers reject .html attachments as phishing risk — tickets will be
    // embedded directly in the email body instead.
    $attachments = [];
    try {
        $pdf_path = pr_generate_ticket_pdf($order->id);
        if ($pdf_path && file_exists($pdf_path) && substr($pdf_path, -4) === '.pdf') {
            $attachments[] = $pdf_path;
        }
    } catch (Throwable $e) {
        error_log('PR PDF generation failed: ' . $e->getMessage());
    }

    // Always render tickets inline in email body (whether or not PDF was attached)
    $tickets_html = pr_render_tickets_for_email($order->id);

    $subject = 'Vstupenky a platební instrukce – ' . $event->name;
    $body    = pr_order_email_html($order, $event, $items, $tickets_html, !empty($attachments));

    return wp_mail([$recipient], $subject, $body, pr_mail_headers(), $attachments);
}

/**
 * Return a validated recipient address for an order email.
 */
function pr_order_recipient_email($order) {
    if (!$order || empty($order->buyer_email)) return '';

    $email = sanitize_email($order->buyer_email);
    return is_email($email) ? $email : '';
}

/**
 * Render simplified ticket HTML for inline use inside the email body.
 */
function pr_render_tickets_for_email($order_id) {
    $tickets = pr_get_order_tickets($order_id);
    $order   = pr_get_order($order_id);
    $event   = pr_get_event($order->event_id);
    $org     = get_option('pr_org_name', get_bloginfo('name'));

    $html = '';
    foreach ($tickets as $t) {
        $qr_url = pr_qr_img_url(pr_checkin_url($t->qr_token), 180);
        $accent = get_option('pr_ticket_accent', '#1a1a2e');
        $is_vip = stripos($t->type_name, 'vip') !== false;
        $hd_bg  = $is_vip ? 'linear-gradient(135deg,#6d28d9,'.$accent.')' : $accent;

        $html .= '<div style="border:2px solid '.esc_attr($accent).';border-radius:8px;overflow:hidden;margin-bottom:16px;font-family:Arial,sans-serif;background:#fff;">
            <div style="background:'.esc_attr($hd_bg).';color:#fff;padding:12px 16px;">
                <div style="font-size:10px;opacity:.7;text-transform:uppercase;letter-spacing:1px">'.esc_html($org).'</div>
                <div style="font-size:16px;font-weight:bold;margin-top:2px">'.esc_html($event->name).'</div>
                <div style="display:inline-block;background:rgba(255,255,255,.2);padding:3px 10px;border-radius:99px;font-size:11px;font-weight:bold;margin-top:6px">'.esc_html($t->type_name).' · vstupenka č. '.(int)$t->seq_number.'</div>
            </div>
            <table cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#fff">
                <tr>
                    <td style="padding:14px 16px;vertical-align:top">
                        <div style="font-size:12px;color:#666;line-height:1.8">📅 <strong>'.esc_html(pr_format_date($event->event_date)).'</strong>'.
                            ($event->location ? '<br>📍 '.esc_html($event->location) : '').'</div>
                        <div style="font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:1.5px;margin-top:10px">Držitel</div>
                        <div style="font-size:14px;font-weight:bold;color:#1a1a2e">'.esc_html($order->buyer_name).'</div>
                        <div style="font-size:10px;color:#9ca3af;font-family:monospace;margin-top:8px">'.esc_html($order->order_ref).'</div>
                    </td>
                    <td style="padding:14px;width:110px;text-align:center;background:#f9fafb;vertical-align:middle">
                        <img src="'.esc_url($qr_url).'" width="90" height="90" alt="QR" style="border:2px solid '.esc_attr($accent).';padding:3px;background:#fff;display:block;margin:0 auto"><br>
                        <span style="font-size:9px;color:#888">Předložte při vstupu</span>
                    </td>
                </tr>
            </table>
        </div>';
    }
    return $html;
}

function pr_order_email_html($order, $event, $items, $tickets_html = '', $has_pdf = false) {
    $org     = get_option('pr_org_name', get_bloginfo('name'));
    $footer  = get_option('pr_email_footer','') ?: $org . ' • Děkujeme za Vaši podporu!';
    $account = get_option('pr_bank_account','');
    $iban    = pr_account_to_iban($account);
    $qr_img  = $iban ? pr_banking_qr_img_url($iban, $order->total_price, $order->var_symbol, $event->name, 200) : '';
    ob_start(); ?>
<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8">
<style>
  body{font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:20px;color:#222}
  .w{max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)}
  .h{background:#1a1a2e;color:#fff;padding:24px 32px;text-align:center}
  .h h1{margin:0;font-size:20px}.h p{margin:5px 0 0;opacity:.75;font-size:13px}
  .b{padding:24px 32px}
  .ok{background:#d1fae5;border-left:4px solid #10b981;border-radius:4px;padding:12px 16px;margin-bottom:18px;font-size:13px;color:#065f46}
  .event-box{background:#f0f4ff;border-left:4px solid #4f6ef7;border-radius:4px;padding:14px 18px;margin-bottom:18px}
  .event-box h2{margin:0 0 6px;font-size:16px;color:#1a1a2e}.event-box p{margin:3px 0;font-size:13px;color:#444}
  .items{width:100%;border-collapse:collapse;margin-bottom:16px;font-size:13px}
  .items th{background:#f5f5f5;padding:7px 10px;text-align:left;border-bottom:2px solid #eee}
  .items td{padding:7px 10px;border-bottom:1px solid #eee}
  .items .total td{font-weight:bold;background:#f0f4ff;border-top:2px solid #4f6ef7}
  .pay-box{border:2px solid #4f6ef7;border-radius:8px;padding:16px 20px;margin-bottom:16px}
  .pay-box h3{margin:0 0 12px;font-size:14px;color:#1a1a2e}
  .pay-row{display:flex;justify-content:space-between;font-size:13px;padding:5px 0;border-bottom:1px solid #eee}
  .pay-row:last-child{border:none}
  .pay-row span:first-child{color:#555}
  .pay-row strong{font-family:monospace;font-size:14px}
  .qr-wrap{text-align:center;margin:14px 0 0}
  .qr-wrap img{border:2px solid #1a1a2e;padding:5px;background:#fff;border-radius:4px}
  .qr-label{font-size:11px;color:#888;margin-top:5px}
  .section-title{font-size:13px;text-transform:uppercase;letter-spacing:1.5px;color:#666;margin:24px 0 12px;border-bottom:1px solid #e5e7eb;padding-bottom:6px}
  .f{background:#f5f5f5;padding:14px 32px;font-size:11px;color:#888;text-align:center}
</style></head><body>
<div class="w">
  <div class="h"><h1>🎫 Vaše vstupenky</h1><p><?php echo esc_html($org); ?></p></div>
  <div class="b">
    <p>Dobrý den, <strong><?php echo esc_html($order->buyer_name); ?></strong>,</p>
    <p style="margin:8px 0 16px">Vaše objednávka byla přijata. <strong>Vstupenky najdete níže</strong><?php echo $has_pdf ? ' nebo jako PDF přílohu' : ''; ?> — vytiskněte je nebo mějte připravené v telefonu při vstupu.</p>

    <div class="ok">✅ Vstupenky jsou připravené<?php echo $has_pdf ? ' (PDF příloha + náhled v e-mailu)' : ''; ?>.</div>

    <div class="event-box">
      <h2><?php echo esc_html($event->name); ?></h2>
      <p>📅 <?php echo esc_html(pr_format_date($event->event_date)); ?></p>
      <?php if($event->location): ?><p>📍 <?php echo esc_html($event->location); ?></p><?php endif; ?>
    </div>

    <?php if($tickets_html): ?>
    <div class="section-title">🎫 Vaše vstupenky</div>
    <?php echo $tickets_html; ?>
    <?php endif; ?>

    <?php if(!empty($order->buyer_street) || !empty($order->buyer_city) || !empty($order->buyer_postcode)): ?>
    <div class="section-title">👤 Kontaktní údaje</div>
    <p style="font-size:13px;margin:0 0 16px">
      <?php echo esc_html(trim(($order->buyer_street ?? '').', '.($order->buyer_city ?? '').' '.($order->buyer_postcode ?? ''), ', ')); ?>
    </p>
    <?php endif; ?>

    <div class="section-title">📋 Souhrn objednávky</div>
    <table class="items">
      <tr><th>Typ vstupenky</th><th>Počet</th><th>Cena/ks</th><th>Celkem</th></tr>
      <?php foreach($items as $it): ?>
      <tr>
        <td><?php echo esc_html($it->type_name); ?></td>
        <td><?php echo (int)$it->quantity; ?></td>
        <td><?php echo esc_html(pr_format_price($it->unit_price)); ?></td>
        <td><?php echo esc_html(pr_format_price($it->unit_price*$it->quantity)); ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="total"><td colspan="3">Celkem k úhradě</td><td><?php echo esc_html(pr_format_price($order->total_price)); ?></td></tr>
    </table>

    <div class="section-title">💳 Platební instrukce</div>
    <div class="pay-box">
      <div class="pay-row"><span>Číslo účtu</span><strong><?php echo esc_html($account?:'–'); ?></strong></div>
      <div class="pay-row"><span>Variabilní symbol</span><strong><?php echo esc_html($order->var_symbol); ?></strong></div>
      <div class="pay-row"><span>Částka</span><strong><?php echo esc_html(pr_format_price($order->total_price)); ?></strong></div>
      <div class="pay-row"><span>Zpráva</span><strong><?php echo esc_html($event->name); ?></strong></div>
      <?php if($qr_img): ?>
      <div class="qr-wrap">
        <img src="<?php echo esc_url($qr_img); ?>" width="160" height="160" alt="QR platba">
        <div class="qr-label">Naskenujte pro rychlou platbu</div>
      </div>
      <?php endif; ?>
    </div>

    <p style="font-size:12px;color:#888">Číslo objednávky: <?php echo esc_html($order->order_ref); ?></p>
  </div>
  <div class="f"><?php echo esc_html($footer); ?></div>
</div>
</body></html>
    <?php return ob_get_clean();
}
function pr_account_to_iban($account_str) {
    if (empty($account_str)) return '';
    $clean = strtoupper(str_replace(' ','',$account_str));
    if (preg_match('/^CZ\d{22}$/',$clean)) return $clean;
    if (preg_match('/^(?:(\d+)-)?(\d+)\/(\d{4})$/',trim($account_str),$m)) {
        $prefix = str_pad($m[1]??'0',6,'0',STR_PAD_LEFT);
        $number = str_pad($m[2],10,'0',STR_PAD_LEFT);
        $bank   = $m[3];
        $bban   = $bank.$prefix.$number;
        $check  = str_pad(98 - pr_numeric_string_mod($bban.'123500','97'), 2, '0', STR_PAD_LEFT);
        return 'CZ'.$check.$bban;
    }
    return $account_str;
}

/**
 * Calculate a modulo for an arbitrarily long numeric string.
 *
 * Some hosting environments do not enable the bcmath extension. Calling bcmod()
 * directly while composing an order email can therefore throw a fatal error
 * before wp_mail() is reached. The iterative calculation keeps QR payment IBAN
 * generation dependency-free and lets checkout emails send reliably.
 */
function pr_numeric_string_mod($number, $mod) {
    $mod = (int) $mod;
    if ($mod <= 0) return 0;

    $remainder = 0;
    $digits = preg_replace('/\D/', '', (string) $number);
    $length = strlen($digits);

    for ($i = 0; $i < $length; $i++) {
        $remainder = ($remainder * 10 + (int) $digits[$i]) % $mod;
    }

    return $remainder;
}
