<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function pr_admin_settings_page() {
    if (!current_user_can('manage_options')) wp_die('Přístup odepřen.');
    $msg = '';

    if (isset($_POST['pr_settings_nonce']) && wp_verify_nonce($_POST['pr_settings_nonce'],'pr_save_settings')) {
        $fields = ['pr_org_name','pr_org_address','pr_org_ico','pr_bank_account','pr_bank_name',
                   'pr_email_from','pr_email_from_name','pr_email_footer',
                   'pr_smtp_host','pr_smtp_port','pr_smtp_enc','pr_smtp_user','pr_checkin_pin',
                   'pr_ticket_accent','pr_ticket_font','pr_ticket_subtitle','pr_ticket_dress_code',
                   'pr_ticket_note','pr_default_template_id'];
        foreach($fields as $f) update_option($f, sanitize_text_field($_POST[$f]??''));
        update_option('pr_smtp_enabled',      isset($_POST['pr_smtp_enabled'])?'1':'0');
        if (!empty($_POST['pr_smtp_pass'])) update_option('pr_smtp_pass', sanitize_text_field($_POST['pr_smtp_pass']));

        // ── Logo handling ────────────────────────────────────────────────────
        // 1. Remove if requested
        if (!empty($_POST['pr_logo_remove'])) {
            update_option('pr_ticket_logo_id', 0);
        }

        // 2. Upload new file if provided
        if (!empty($_FILES['pr_logo_file']['name']) && empty($_FILES['pr_logo_file']['error'])) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $upload = wp_handle_upload($_FILES['pr_logo_file'], ['test_form' => false]);

            if (!empty($upload['error'])) {
                $msg = '<div class="notice notice-error"><p>Chyba uploadu loga: ' . esc_html($upload['error']) . '</p></div>';
            } elseif (!empty($upload['file'])) {
                $attachment_id = wp_insert_attachment([
                    'post_mime_type' => $upload['type'],
                    'post_title'     => 'Logo organizace',
                    'post_content'   => '',
                    'post_status'    => 'inherit',
                    'guid'           => $upload['url'],
                ], $upload['file']);

                if (!is_wp_error($attachment_id) && $attachment_id) {
                    $meta = wp_generate_attachment_metadata($attachment_id, $upload['file']);
                    wp_update_attachment_metadata($attachment_id, $meta);
                    update_option('pr_ticket_logo_id', (int)$attachment_id);
                }
            }
        }

        if (!$msg) $msg = '<div class="notice notice-success"><p>Nastavení uloženo.</p></div>';
    }

    // Save custom template
    // Preview ticket
    if (isset($_GET['pr_preview_ticket']) && check_admin_referer('pr_preview')) {
        pr_preview_ticket_html();
        exit;
    }

    // Test email
    if (isset($_POST['pr_test_nonce']) && wp_verify_nonce($_POST['pr_test_nonce'],'pr_test_email')) {
        $to   = sanitize_email($_POST['pr_test_to']??get_option('admin_email'));
        $fake_order = (object)['id'=>0,'order_ref'=>'PR-TEST000','var_symbol'=>'12345678',
            'buyer_name'=>'Test Uživatel','buyer_email'=>$to,'buyer_phone'=>'+420 777 000 000',
            'total_price'=>1500,'status'=>'pending','event_id'=>0];
        $fake_event = (object)['name'=>'Testovací ples 2025','event_date'=>date('Y-m-d H:i:s',strtotime('+30 days')),'location'=>'Praha, Obecní dům'];
        $fake_items = [(object)['type_name'=>'Normální','quantity'=>2,'unit_price'=>500],
                       (object)['type_name'=>'VIP','quantity'=>1,'unit_price'=>500]];
        // Skip PDF for test
        $body = pr_order_email_html($fake_order,$fake_event,$fake_items);
        $sent = wp_mail($to,'Vstupenky – '.$fake_event->name,$body,pr_mail_headers());
        $msg = $sent
            ? '<div class="notice notice-success"><p>✅ Testovací e-mail odeslán na <strong>'.esc_html($to).'</strong>.</p></div>'
            : '<div class="notice notice-error"><p>❌ Odeslání selhalo. Zkontrolujte SMTP.</p></div>';
    }

    $o = function($k, $d='') { return get_option($k, $d); };
    ?>
    <div class="wrap pr-admin">
        <h1>⚙️ Nastavení</h1>
        <?php echo $msg; ?>

        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('pr_save_settings','pr_settings_nonce'); ?>

            <h2>🏢 Organizace</h2>
            <table class="form-table">
                <tr><th>Název</th><td><input type="text" name="pr_org_name" value="<?php echo esc_attr($o('pr_org_name')); ?>" class="regular-text"></td></tr>
                <tr><th>Adresa</th><td><input type="text" name="pr_org_address" value="<?php echo esc_attr($o('pr_org_address')); ?>" class="regular-text" placeholder="Ul. 1, 110 00 Praha"></td></tr>
                <tr><th>IČO</th><td><input type="text" name="pr_org_ico" value="<?php echo esc_attr($o('pr_org_ico')); ?>" class="small-text"></td></tr>
            </table>

            <h2>🏦 Bankovní účet</h2>
            <p>Číslo účtu se zobrazí v platebních instrukcích a použije se pro generování QR kódu platby.</p>
            <table class="form-table">
                <tr><th>Číslo účtu</th><td><input type="text" name="pr_bank_account" value="<?php echo esc_attr($o('pr_bank_account')); ?>" class="regular-text" placeholder="123456-7890123/0300 nebo IBAN">
                    <p class="description">Formát: <code>prefix-číslo/kód_banky</code> nebo přímo IBAN. Plugin automaticky převede na IBAN pro QR platbu.</p></td></tr>
                <tr><th>Název banky</th><td><input type="text" name="pr_bank_name" value="<?php echo esc_attr($o('pr_bank_name','Raiffeisenbank')); ?>" class="regular-text"></td></tr>
            </table>

            <h2>✉️ E-mail</h2>
            <table class="form-table">
                <tr><th>Jméno odesílatele</th><td><input type="text" name="pr_email_from_name" value="<?php echo esc_attr($o('pr_email_from_name')); ?>" class="regular-text"></td></tr>
                <tr><th>E-mail odesílatele</th><td><input type="email" name="pr_email_from" value="<?php echo esc_attr($o('pr_email_from')); ?>" class="regular-text"></td></tr>
                <tr><th>Patička</th><td><input type="text" name="pr_email_footer" value="<?php echo esc_attr($o('pr_email_footer')); ?>" class="regular-text" placeholder="Děkujeme za Vaši podporu!"></td></tr>
            </table>

            <h2>🔌 SMTP</h2>
            <table class="form-table">
                <tr><th>Použít SMTP</th><td><label><input type="checkbox" name="pr_smtp_enabled" value="1" id="smtp_tog" <?php checked($o('pr_smtp_enabled'),'1'); ?>> Zapnout</label></td></tr>
                <tr class="sr"><th>Host</th><td><input type="text" name="pr_smtp_host" value="<?php echo esc_attr($o('pr_smtp_host')); ?>" class="regular-text" placeholder="smtp.gmail.com">
                    <p class="description">Gmail: <code>smtp.gmail.com</code> · Seznam: <code>smtp.seznam.cz</code> · SendGrid: <code>smtp.sendgrid.net</code></p></td></tr>
                <tr class="sr"><th>Port</th><td><input type="number" name="pr_smtp_port" value="<?php echo esc_attr($o('pr_smtp_port',587)); ?>" class="small-text"> <span style="color:#888">TLS=587 · SSL=465</span></td></tr>
                <tr class="sr"><th>Šifrování</th><td><select name="pr_smtp_enc"><option value="tls" <?php selected($o('pr_smtp_enc','tls'),'tls'); ?>>TLS</option><option value="ssl" <?php selected($o('pr_smtp_enc'),'ssl'); ?>>SSL</option></select></td></tr>
                <tr class="sr"><th>Uživatel</th><td><input type="text" name="pr_smtp_user" value="<?php echo esc_attr($o('pr_smtp_user')); ?>" class="regular-text"></td></tr>
                <tr class="sr"><th>Heslo</th><td><input type="password" name="pr_smtp_pass" value="" class="regular-text" placeholder="Ponechte prázdné pro zachování">
                    <p class="description">Gmail: použijte <a href="https://myaccount.google.com/apppasswords" target="_blank">App Password</a></p></td></tr>
            </table>

            <h2>🎨 Výchozí šablona vstupenky</h2>
            <table class="form-table">
                <tr><th>Výchozí šablona</th><td>
                    <select name="pr_default_template_id">
                        <option value="0">— Použít soubor šablony (ticket.html) —</option>
                        <?php foreach(pr_get_user_templates() as $t): ?>
                        <option value="<?php echo $t->id; ?>" <?php selected(get_option('pr_default_template_id',0),$t->id); ?>>
                            <?php echo esc_html($t->name); ?><?php echo $t->oob_key?' ⭐':''; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <a href="<?php echo admin_url('admin.php?page=pr-templates'); ?>" style="margin-left:8px;font-size:12px">Spravovat šablony ↗</a>
                    <p class="description">Použije se pokud typ vstupenky nemá přiřazenou vlastní šablonu.</p>
                </td></tr>
            </table>

            <h2>📱 Check-in PIN</h2>
            <table class="form-table">
                <tr><th>PIN kód</th><td>
                    <input type="text" name="pr_checkin_pin" value="<?php echo esc_attr($o('pr_checkin_pin','1234')); ?>" class="small-text" maxlength="10">
                    <p class="description">Obsluha zadá PIN na <code><?php echo esc_html(home_url('/ples-checkin/')); ?></code></p>
                </td></tr>
            </table>

            <h2>📋 Shortcode</h2>
            <p>Vložte na stránku: <code>[ples_registrace event="ID_AKCE"]</code><br>
            ID akce najdete v seznamu akcí.</p>

            <h2>🎨 Vzhled vstupenky</h2>
            <table class="form-table">
                <tr>
                    <th>Logo organizace</th>
                    <td>
                        <?php
                        $logo_id  = (int)get_option('pr_ticket_logo_id', 0);
                        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'medium') : '';
                        ?>
                        <input type="hidden" name="pr_ticket_logo_id" id="pr_logo_id" value="<?php echo $logo_id; ?>">

                        <div id="pr-logo-preview" style="margin-bottom:8px;min-height:60px;">
                            <?php if($logo_url): ?>
                            <img src="<?php echo esc_url($logo_url); ?>"
                                 style="max-height:80px;max-width:240px;border:1px solid #ddd;padding:6px;border-radius:4px;background:#fff;">
                            <?php endif; ?>
                        </div>

                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                            <p style="margin:0">
                                <strong>Nahrát logo:</strong>
                                <input type="file" name="pr_logo_file" id="pr_logo_file" accept="image/png,image/jpeg,image/svg+xml,image/webp" style="margin-left:6px">
                            </p>
                            <?php if($logo_url): ?>
                            <label style="margin-left:12px;font-size:13px">
                                <input type="checkbox" name="pr_logo_remove" value="1"> Odstranit stávající logo
                            </label>
                            <?php endif; ?>
                        </div>
                        <p class="description">PNG, JPG, SVG nebo WebP. Doporučená výška: 100–200 px, průhledné pozadí.</p>
                    </td>
                </tr>
                <tr>
                    <th>Barva záhlaví</th>
                    <td>
                        <input type="color" name="pr_ticket_accent" value="<?php echo esc_attr(get_option('pr_ticket_accent','#1a1a2e')); ?>" id="pr_accent_color">
                        <span id="pr_accent_hex" style="font-family:monospace;margin-left:8px"><?php echo esc_html(get_option('pr_ticket_accent','#1a1a2e')); ?></span>
                        <div id="pr-color-preview" style="display:inline-block;width:120px;height:28px;border-radius:4px;margin-left:10px;vertical-align:middle;background:<?php echo esc_attr(get_option('pr_ticket_accent','#1a1a2e')); ?>"></div>
                    </td>
                </tr>
                <tr>
                    <th>Font</th>
                    <td>
                        <select name="pr_ticket_font" id="pr_ticket_font">
                            <?php
                            $fonts = ['Arial'=>'Arial (výchozí)','Georgia'=>'Georgia (serif)','Verdana'=>'Verdana','Trebuchet MS'=>'Trebuchet MS','Times New Roman'=>'Times New Roman'];
                            $cur   = get_option('pr_ticket_font','Arial');
                            foreach($fonts as $f=>$l): ?>
                            <option value="<?php echo esc_attr($f); ?>" <?php selected($cur,$f); ?> style="font-family:<?php echo esc_attr($f); ?>"><?php echo esc_html($l); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Podtitulek akce</th>
                    <td>
                        <input type="text" name="pr_ticket_subtitle" value="<?php echo esc_attr(get_option('pr_ticket_subtitle','')); ?>" class="regular-text" placeholder="např. 20. ročník · Benefiční ples">
                        <p class="description">Zobrazí se pod názvem akce v záhlaví.</p>
                    </td>
                </tr>
                <tr>
                    <th>Dress code</th>
                    <td>
                        <input type="text" name="pr_ticket_dress_code" value="<?php echo esc_attr(get_option('pr_ticket_dress_code','')); ?>" class="regular-text" placeholder="např. Black tie / Společenský oděv">
                    </td>
                </tr>
                <tr>
                    <th>Poznámka na vstupenku</th>
                    <td>
                        <input type="text" name="pr_ticket_note" value="<?php echo esc_attr(get_option('pr_ticket_note','')); ?>" class="regular-text" placeholder="např. Vstup od 19:00, raut od 20:30">
                    </td>
                </tr>
            </table>

            <p>
                <a href="<?php echo wp_nonce_url(add_query_arg(['pr_preview_ticket'=>1]),'pr_preview'); ?>"
                   target="_blank" class="button">👁 Náhled vstupenky</a>
                <span style="color:#888;font-size:12px;margin-left:8px">Otevře se v nové záložce.</span>
            </p>

            <?php submit_button('Uložit nastavení'); ?>
        </form>

        <hr style="margin:32px 0">

        <h2>🧪 Testovací e-mail</h2>
        <form method="post" style="max-width:460px">
            <?php wp_nonce_field('pr_test_email','pr_test_nonce'); ?>
            <table class="form-table">
                <tr><th>Odeslat na</th><td><input type="email" name="pr_test_to" value="<?php echo esc_attr(get_option('admin_email')); ?>" class="regular-text"></td></tr>
            </table>
            <p class="description" style="margin-bottom:10px">Odešle ukázkový e-mail se vstupenkami a platebními instrukcemi (bez PDF přílohy).</p>
            <?php submit_button('Odeslat testovací e-mail','secondary'); ?>
        </form>
    </div>
    <script>
    (function(){
        // ── SMTP toggle ──────────────────────────────────────────────────────
        var t = document.getElementById('smtp_tog');
        if(t){
            function togSmtp(){
                document.querySelectorAll('.sr').forEach(function(r){
                    r.style.opacity = t.checked ? 1 : .4;
                    r.querySelectorAll('input,select').forEach(function(i){ i.disabled = !t.checked; });
                });
            }
            t.addEventListener('change', togSmtp); togSmtp();
        }

        // ── Accent color preview ─────────────────────────────────────────────
        var cp = document.getElementById('pr_accent_color');
        if(cp){
            cp.addEventListener('input', function(){
                document.getElementById('pr_accent_hex').textContent = this.value;
                document.getElementById('pr-color-preview').style.background = this.value;
                // Update logo stage background live
                var stage = document.getElementById('pr-logo-stage');
                if(stage) stage.style.background = this.value;
            });
        }

        // ── Logo file preview (shows selected file before upload) ────────────
        var fileInput = document.getElementById('pr_logo_file');
        if(fileInput){
            fileInput.addEventListener('change', function(){
                var file = this.files && this.files[0];
                if(!file) return;
                if(!file.type.match(/^image\//)){
                    alert('Vyberte prosím obrázek (PNG, JPG, SVG nebo WebP).');
                    this.value = '';
                    return;
                }
                var reader = new FileReader();
                reader.onload = function(e){
                    var preview = document.getElementById('pr-logo-preview');
                    if(preview){
                        preview.innerHTML = '<img src="' + e.target.result + '" style="max-height:80px;max-width:240px;border:1px solid #4f6ef7;padding:6px;border-radius:4px;background:#fff;">' +
                                            '<p style="font-size:12px;color:#4f6ef7;margin:4px 0 0">Náhled — uloží se po kliknutí na „Uložit nastavení"</p>';
                    }
                };
                reader.readAsDataURL(file);
            });
        }

    })();
    </script>
    <?php
}
