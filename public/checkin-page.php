<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action('template_redirect','pr_handle_checkin_page');
function pr_handle_checkin_page() {
    if ( ! get_query_var('pr_checkin') ) return;

    // Logout
    if ( isset($_GET['logout']) ) {
        pr_checkin_logout();
        wp_redirect(home_url('/ples-checkin/'));
        exit;
    }

    // PIN submit
    if ( isset($_POST['pr_pin']) ) {
        if ( pr_checkin_verify_pin( $_POST['pr_pin'] ) ) {
            pr_checkin_set_auth();
            wp_redirect(home_url('/ples-checkin/'));
        } else {
            pr_render_checkin_login('Nesprávný PIN.');
        }
        exit;
    }

    // Token scan via GET
    $token  = sanitize_text_field($_GET['token']??'');
    $result = null;
    if ( $token && pr_checkin_is_authed() ) {
        $result = pr_process_checkin($token);
    }

    if ( ! pr_checkin_is_authed() ) {
        pr_render_checkin_login();
        exit;
    }

    pr_render_checkin_screen($result, $token);
    exit;
}

function pr_render_checkin_login($error='') {
    pr_checkin_html_head('Check-in Přihlášení');
    ?>
    <div class="ci-wrap">
        <div class="ci-card">
            <div class="ci-logo">🎭</div>
            <h1>Check-in</h1>
            <p class="ci-subtitle">Zadejte PIN pro přístup</p>
            <?php if($error): ?><div class="ci-alert ci-alert-red"><?php echo esc_html($error); ?></div><?php endif; ?>
            <form method="post">
                <input type="password" name="pr_pin" class="ci-pin-input" placeholder="PIN" autofocus autocomplete="off">
                <button type="submit" class="ci-btn">Přihlásit →</button>
            </form>
        </div>
    </div>
    <?php pr_checkin_html_foot();
}

function pr_render_checkin_screen($result, $scanned_token='') {
    pr_checkin_html_head('Check-in');
    $color_map = ['ok'=>'green','duplicate'=>'orange','invalid'=>'orange','error'=>'red'];
    $color = $result ? ($color_map[$result['status']]??'red') : '';
    ?>
    <div class="ci-wrap">
        <div class="ci-header">
            <span>🎭 Check-in</span>
            <a href="<?php echo esc_url(home_url('/ples-checkin/?logout=1')); ?>" class="ci-logout">Odhlásit</a>
        </div>

        <?php if($result): ?>
        <div class="ci-result ci-result-<?php echo esc_attr($color); ?>">
            <div class="ci-result-msg"><?php echo esc_html($result['message']); ?></div>
            <?php if(!empty($result['ticket'])): $t=$result['ticket']; ?>
            <table class="ci-result-detail">
                <tr><th>Jméno</th><td><?php echo esc_html($t->buyer_name); ?></td></tr>
                <tr><th>Typ vstupenky</th><td><?php echo esc_html($t->type_name); ?></td></tr>
                <tr><th>Akce</th><td><?php echo esc_html($t->event_name); ?></td></tr>
                <tr><th>Objednávka</th><td><?php echo esc_html($t->order_ref); ?></td></tr>
                <?php if($t->checked_in && $t->checked_in_at): ?>
                <tr><th>Vstup</th><td><?php echo esc_html(pr_format_date($t->checked_in_at)); ?></td></tr>
                <?php endif; ?>
            </table>
            <?php endif; ?>
            <a href="<?php echo esc_url(home_url('/ples-checkin/')); ?>" class="ci-btn ci-btn-next">← Další skenování</a>
        </div>
        <?php else: ?>
        <div class="ci-idle">
            <div class="ci-idle-icon">📷</div>
            <p>Naskenujte QR kód vstupenky<br>nebo zadejte token ručně</p>
            <form method="get" action="<?php echo esc_url(home_url('/ples-checkin/')); ?>">
                <input type="text" name="token" class="ci-token-input" placeholder="Token vstupenky" autofocus autocomplete="off">
                <button type="submit" class="ci-btn">Ověřit</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php pr_checkin_html_foot();
}

function pr_checkin_html_head($title) {
    ?><!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<meta name="theme-color" content="#1a1a2e">
<title><?php echo esc_html($title); ?></title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:Arial,sans-serif;background:#1a1a2e;min-height:100vh;color:#fff;display:flex;flex-direction:column}
  .ci-header{background:rgba(255,255,255,.08);padding:14px 20px;display:flex;justify-content:space-between;align-items:center;font-size:16px;font-weight:600}
  .ci-logout{font-size:13px;color:#aaa;text-decoration:none;padding:6px 12px;border:1px solid #444;border-radius:6px}
  .ci-wrap{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px}
  .ci-card{background:#fff;color:#1a1a2e;border-radius:16px;padding:40px 32px;width:100%;max-width:380px;text-align:center;box-shadow:0 8px 40px rgba(0,0,0,.4)}
  .ci-logo{font-size:48px;margin-bottom:12px}
  .ci-card h1{font-size:24px;margin-bottom:6px}
  .ci-subtitle{font-size:14px;color:#666;margin-bottom:20px}
  .ci-alert{padding:10px 14px;border-radius:8px;font-size:14px;margin-bottom:16px}
  .ci-alert-red{background:#fee2e2;color:#991b1b}
  .ci-pin-input,.ci-token-input{width:100%;padding:14px;font-size:18px;border:2px solid #e5e7eb;border-radius:10px;text-align:center;margin-bottom:14px;letter-spacing:4px;outline:none}
  .ci-pin-input:focus,.ci-token-input:focus{border-color:#4f6ef7}
  .ci-btn{width:100%;padding:14px;background:#4f6ef7;color:#fff;border:none;border-radius:10px;font-size:16px;font-weight:700;cursor:pointer;text-decoration:none;display:block;text-align:center}
  .ci-btn:hover{background:#3b5ae0}
  .ci-idle{text-align:center;width:100%;max-width:400px}
  .ci-idle-icon{font-size:72px;margin-bottom:16px}
  .ci-idle p{color:#aaa;margin-bottom:24px;font-size:15px;line-height:1.6}
  .ci-token-input{background:#2a2a3e;color:#fff;border-color:#444;font-size:16px;letter-spacing:normal;max-width:380px}
  .ci-token-input::placeholder{color:#666}
  .ci-result{width:100%;max-width:480px;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.3)}
  .ci-result-green{background:#d1fae5;color:#065f46}
  .ci-result-orange{background:#fff8e1;color:#78350f}
  .ci-result-red{background:#fee2e2;color:#991b1b}
  .ci-result-msg{font-size:22px;font-weight:700;padding:24px 20px 16px;text-align:center}
  .ci-result-detail{width:100%;border-collapse:collapse;font-size:14px;background:rgba(255,255,255,.6);margin-bottom:0}
  .ci-result-detail th,.ci-result-detail td{padding:8px 16px;border-bottom:1px solid rgba(0,0,0,.08);text-align:left}
  .ci-result-detail th{width:110px;font-weight:600;color:rgba(0,0,0,.6)}
  .ci-btn-next{margin:16px;width:calc(100% - 32px);background:#1a1a2e;border-radius:8px;padding:12px}
  @media(max-width:480px){.ci-card{padding:28px 20px}}
</style></head><body>
<?php
}
function pr_checkin_html_foot() {
    echo '</body></html>';
}
