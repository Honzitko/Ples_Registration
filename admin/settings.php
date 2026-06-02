<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function pr_admin_settings_page() {
    if (!current_user_can('manage_options')) wp_die('Přístup odepřen.');
    $msg = '';
    $bcc_admin_email_error = '';
    $bcc_admin_email_value = '';

    if (isset($_POST['pr_settings_nonce']) && wp_verify_nonce($_POST['pr_settings_nonce'],'pr_save_settings')) {
        $fields = ['pr_org_name','pr_org_address','pr_org_ico','pr_bank_account','pr_bank_name',
                   'pr_email_from','pr_email_from_name','pr_email_footer',
                   'pr_smtp_host','pr_smtp_port','pr_smtp_enc','pr_smtp_user','pr_checkin_pin',
                   'pr_ticket_accent','pr_ticket_font','pr_ticket_subtitle','pr_ticket_dress_code',
                   'pr_ticket_note','pr_default_template_id'];
        foreach($fields as $f) update_option($f, sanitize_text_field($_POST[$f]??''));

        $bcc_admin_email_raw = trim(wp_unslash($_POST['pr_bcc_admin_email']??''));
        $bcc_admin_email_value = sanitize_email($bcc_admin_email_raw);
        if ($bcc_admin_email_raw !== '' && (!$bcc_admin_email_value || !is_email($bcc_admin_email_value))) {
            $bcc_admin_email_value = sanitize_text_field($bcc_admin_email_raw);
            $bcc_admin_email_error = 'Zadejte platnou e-mailovou adresu pro skrytou kopii, nebo pole ponechte prázdné.';
            $msg = '<div class="notice notice-error"><p>Nastavení bylo uloženo kromě e-mailu pro skrytou kopii. Zkontrolujte zvýrazněné pole.</p></div>';
        } else {
            update_option('pr_bcc_admin_email', $bcc_admin_email_value);
        }

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

    if (isset($_POST['pr_db_maintenance_nonce']) && wp_verify_nonce($_POST['pr_db_maintenance_nonce'], 'pr_db_maintenance')) {
        $db_action = sanitize_key($_POST['pr_db_action'] ?? '');
        $missing_helpers_msg = '<div class="notice notice-error"><p>❌ Nástroje databázové údržby nejsou kompletně nahrané. Nahrajte prosím znovu všechny soubory pluginu, hlavně <code>includes/db.php</code>.</p></div>';

        if ($db_action === 'repair_create') {
            if (!function_exists('pr_create_tables') || !function_exists('pr_get_missing_tables')) {
                $msg = $missing_helpers_msg;
            } else {
                pr_create_tables();
                $missing = pr_get_missing_tables();
                $msg = empty($missing)
                    ? '<div class="notice notice-success"><p>✅ Databázové tabulky byly opraveny / vytvořeny.</p></div>'
                    : '<div class="notice notice-error"><p>❌ Oprava databáze selhala. Stále chybí: <code>' . esc_html(implode(', ', $missing)) . '</code></p></div>';
            }
        } elseif ($db_action === 'clean_orders') {
            $confirm = sanitize_text_field($_POST['pr_db_confirm'] ?? '');
            if ($confirm !== 'CLEAN') {
                $msg = '<div class="notice notice-error"><p>Pro vyčištění objednávek napište potvrzení <code>CLEAN</code>.</p></div>';
            } elseif (!function_exists('pr_clean_checkout_data')) {
                $msg = $missing_helpers_msg;
            } else {
                $report = pr_clean_checkout_data();
                $msg = empty($report['errors'])
                    ? '<div class="notice notice-success"><p>✅ Objednávky, položky objednávek a vstupenky byly smazány. Počty prodaných vstupenek byly vynulovány.</p></div>'
                    : '<div class="notice notice-error"><p>❌ Čištění objednávek skončilo s chybou: <code>' . esc_html(implode('; ', $report['errors'])) . '</code></p></div>';
            }
        } elseif ($db_action === 'reset_database') {
            $confirm = sanitize_text_field($_POST['pr_db_confirm'] ?? '');
            if ($confirm !== 'DELETE') {
                $msg = '<div class="notice notice-error"><p>Pro kompletní reset databáze napište potvrzení <code>DELETE</code>.</p></div>';
            } elseif (!function_exists('pr_reset_plugin_database')) {
                $msg = $missing_helpers_msg;
            } else {
                $report = pr_reset_plugin_database();
                $msg = empty($report['missing'])
                    ? '<div class="notice notice-success"><p>✅ Databázové tabulky pluginu byly smazány a znovu vytvořeny prázdné.</p></div>'
                    : '<div class="notice notice-error"><p>❌ Reset databáze selhal. Stále chybí: <code>' . esc_html(implode(', ', $report['missing'])) . '</code></p></div>';
            }
        }
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
    if (!$bcc_admin_email_value) {
        $bcc_admin_email_value = $o('pr_bcc_admin_email');
    }
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
                <tr><th>BCC admin email</th><td>
                    <input type="email" name="pr_bcc_admin_email" value="<?php echo esc_attr($bcc_admin_email_value); ?>" class="regular-text" aria-invalid="<?php echo $bcc_admin_email_error ? 'true' : 'false'; ?>">
                    <p class="description">Volitelné. Pokud vyplníte platnou adresu, administrátor obdrží skrytou kopii každého potvrzovacího e-mailu odeslaného kupujícímu.</p>
                    <?php if ($bcc_admin_email_error): ?>
                    <p style="color:#b32d2e;margin-top:4px"><strong><?php echo esc_html($bcc_admin_email_error); ?></strong></p>
                    <?php endif; ?>
                </td></tr>
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

        <hr style="margin:32px 0">

        <h2>🛠️ Databáze pluginu</h2>
        <p>Tyto nástroje slouží k opravě chybějících tabulek nebo k vyčištění testovacích dat. Destruktivní akce vyžadují ruční potvrzení.</p>

        <?php
        $plugin_tables = function_exists('pr_get_plugin_tables')
            ? pr_get_plugin_tables()
            : array_filter([PR_EVENTS, PR_TICKET_TYPES, PR_ORDERS, PR_ORDER_ITEMS, PR_TICKETS, PR_TEMPLATES]);
        $missing_tables = function_exists('pr_get_missing_tables') ? pr_get_missing_tables() : [];
        $last_maintenance_report = function_exists('pr_get_last_database_maintenance_report') ? pr_get_last_database_maintenance_report() : [];
        $last_repair_report = function_exists('pr_get_last_table_repair_report') ? pr_get_last_table_repair_report() : [];
        $maintenance_helpers_missing = !function_exists('pr_get_last_database_maintenance_report')
            || !function_exists('pr_clean_checkout_data')
            || !function_exists('pr_reset_plugin_database');
        ?>
        <?php if($maintenance_helpers_missing): ?>
            <div class="notice notice-error inline"><p>Část databázových nástrojů není dostupná. Nahrajte prosím znovu všechny soubory pluginu, hlavně <code>includes/db.php</code>. Tato stránka se kvůli tomu už nezastaví fatální chybou.</p></div>
        <?php endif; ?>
        <table class="widefat striped" style="max-width:900px;margin:12px 0 18px">
            <thead><tr><th>Tabulka</th><th>Stav</th></tr></thead>
            <tbody>
                <?php foreach($plugin_tables as $table): ?>
                <tr>
                    <td><code><?php echo esc_html($table); ?></code></td>
                    <td><?php echo function_exists('pr_db_table_exists') && pr_db_table_exists($table) ? '✅ Existuje' : '❌ Chybí / nelze ověřit'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if(!empty($missing_tables)): ?>
            <div class="notice notice-warning inline"><p>Chybějící tabulky: <code><?php echo esc_html(implode(', ', $missing_tables)); ?></code></p></div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;max-width:980px">
            <div style="border:1px solid #c3c4c7;background:#fff;padding:16px;border-radius:6px">
                <h3 style="margin-top:0">✅ Opravit / vytvořit tabulky</h3>
                <p>Bezpečná akce. Vytvoří chybějící tabulky a doplní chybějící sloupce bez mazání dat.</p>
                <form method="post">
                    <?php wp_nonce_field('pr_db_maintenance','pr_db_maintenance_nonce'); ?>
                    <input type="hidden" name="pr_db_action" value="repair_create">
                    <?php submit_button('Opravit / vytvořit tabulky', 'primary', 'submit', false); ?>
                </form>
            </div>

            <div style="border:1px solid #dba617;background:#fff;padding:16px;border-radius:6px">
                <h3 style="margin-top:0">🧹 Vyčistit objednávky</h3>
                <p>Smaže pouze objednávky, položky objednávek a vygenerované vstupenky. Akce a typy vstupenek zůstanou zachované, počty prodaných vstupenek se vynulují.</p>
                <form method="post" onsubmit="return confirm('Opravdu smazat všechny objednávky a vstupenky? Akce a typy vstupenek zůstanou.');">
                    <?php wp_nonce_field('pr_db_maintenance','pr_db_maintenance_nonce'); ?>
                    <input type="hidden" name="pr_db_action" value="clean_orders">
                    <p><label>Napište <code>CLEAN</code>: <input type="text" name="pr_db_confirm" class="regular-text" autocomplete="off"></label></p>
                    <?php submit_button('Vyčistit objednávky', 'secondary', 'submit', false); ?>
                </form>
            </div>

            <div style="border:1px solid #d63638;background:#fff;padding:16px;border-radius:6px">
                <h3 style="margin-top:0;color:#b32d2e">⚠️ Kompletní reset databáze</h3>
                <p><strong>Smaže všechny tabulky pluginu a znovu je vytvoří prázdné.</strong> Přijdete o akce, typy vstupenek, objednávky, vstupenky a šablony uložené v databázi.</p>
                <form method="post" onsubmit="return confirm('Opravdu smazat a znovu vytvořit VŠECHNY databázové tabulky pluginu? Tato akce je nevratná.');">
                    <?php wp_nonce_field('pr_db_maintenance','pr_db_maintenance_nonce'); ?>
                    <input type="hidden" name="pr_db_action" value="reset_database">
                    <p><label>Napište <code>DELETE</code>: <input type="text" name="pr_db_confirm" class="regular-text" autocomplete="off"></label></p>
                    <?php submit_button('Smazat a znovu vytvořit databázi pluginu', 'delete', 'submit', false); ?>
                </form>
            </div>
        </div>

        <?php if(!empty($last_maintenance_report) || !empty($last_repair_report)): ?>
            <details style="max-width:980px;margin-top:18px">
                <summary>Technický detail poslední databázové údržby</summary>
                <pre style="white-space:pre-wrap;background:#fff;border:1px solid #ccd0d4;padding:10px;max-height:320px;overflow:auto;"><?php echo esc_html(print_r(!empty($last_maintenance_report) ? $last_maintenance_report : $last_repair_report, true)); ?></pre>
            </details>
        <?php endif; ?>
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
