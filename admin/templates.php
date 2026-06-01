<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── OOB definitions ───────────────────────────────────────────────────────────

function pr_oob_templates() {
    return [
        'klasicka'   => [ 'name'=>'Klasická',    'desc'=>'Čistý tmavý header, minimalistická. Vhodná pro většinu akcí.', 'accent'=>'#1a1a2e', 'font'=>'Arial',        'preview'=>'linear-gradient(135deg,#1a1a2e,#2d3a5e)' ],
        'elegantni'  => [ 'name'=>'Elegantní',   'desc'=>'Zlatý akcent, serif font. Ideální pro reprezentativní plesy.',  'accent'=>'#c8922a', 'font'=>'Georgia',      'preview'=>'linear-gradient(135deg,#1c1208,#c8922a)' ],
        'vip-black'  => [ 'name'=>'VIP Black',   'desc'=>'Černá s zlatými detaily. Luxusní pocit, pro VIP vstupenky.',    'accent'=>'#f59e0b', 'font'=>'Trebuchet MS', 'preview'=>'linear-gradient(135deg,#0a0a0a,#1a1a1a)' ],
        'festivalova'=> [ 'name'=>'Festivalová', 'desc'=>'Barevná, moderní, energická. Pro koncerty a festivaly.',        'accent'=>'#7c3aed', 'font'=>'Verdana',      'preview'=>'linear-gradient(90deg,#7c3aed,#f43f8f,#fb923c)' ],
        'minimal'    => [ 'name'=>'Minimál',     'desc'=>'Bílá, čistá, jen barevná linka. Maximálně přehledná.',          'accent'=>'#4f6ef7', 'font'=>'Trebuchet MS', 'preview'=>'linear-gradient(135deg,#f8f9ff,#e8eeff)' ],
    ];
}

function pr_oob_file( $key ) {
    return PR_DIR . 'templates/oob/' . $key . '.html';
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function pr_get_template( $id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare("SELECT * FROM ".PR_TEMPLATES." WHERE id=%d",$id) );
}

function pr_get_all_templates() {
    global $wpdb;
    return $wpdb->get_results("SELECT * FROM ".PR_TEMPLATES." ORDER BY oob_key IS NOT NULL DESC, name ASC");
}

function pr_get_user_templates() {
    global $wpdb;
    return $wpdb->get_results("SELECT * FROM ".PR_TEMPLATES." ORDER BY name ASC");
}

function pr_ensure_oob_templates() {
    global $wpdb;
    $oob = pr_oob_templates();
    foreach ( $oob as $key => $meta ) {
        $html   = file_exists(pr_oob_file($key)) ? file_get_contents(pr_oob_file($key)) : '';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM ".PR_TEMPLATES." WHERE oob_key=%s",$key));
        if (!$exists) {
            $wpdb->insert(PR_TEMPLATES,[
                'name'         => $meta['name'],
                'description'  => $meta['desc'],
                'oob_key'      => $key,
                'html'         => $html,
                'accent_color' => $meta['accent'],
                'font_family'  => $meta['font'],
                'preview_css'  => $meta['preview'],
            ],['%s','%s','%s','%s','%s','%s','%s']);
        } else {
            // Always resync HTML from file so fixes propagate automatically
            $wpdb->update(PR_TEMPLATES,
                ['html' => $html],
                ['oob_key' => $key],
                ['%s'], ['%s']
            );
        }
    }
}

// ── Main page ─────────────────────────────────────────────────────────────────

function pr_admin_templates_page() {
    if (!current_user_can('manage_options')) wp_die('Přístup odepřen.');
    global $wpdb;

    // Ensure OOB templates exist in DB
    pr_ensure_oob_templates();

    $msg = '';
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    // ── Delete
    if ($action==='delete' && isset($_GET['id']) && check_admin_referer('pr_del_tpl')) {
        $tpl = pr_get_template((int)$_GET['id']);
        if ($tpl && !$tpl->oob_key) {
            $wpdb->delete(PR_TEMPLATES,['id'=>(int)$_GET['id']],['%d']);
            $msg = '<div class="notice notice-success"><p>Šablona smazána.</p></div>';
        } else {
            $msg = '<div class="notice notice-error"><p>Vestavěné šablony nelze smazat. Vytvořte kopii a upravte ji.</p></div>';
        }
    }

    // ── Duplicate
    if ($action==='duplicate' && isset($_GET['id']) && check_admin_referer('pr_dup_tpl')) {
        $tpl = pr_get_template((int)$_GET['id']);
        if ($tpl) {
            $new_id = $wpdb->insert(PR_TEMPLATES,[
                'name'         => 'Kopie — '.$tpl->name,
                'description'  => $tpl->description,
                'oob_key'      => null,
                'html'         => $tpl->html,
                'accent_color' => $tpl->accent_color,
                'font_family'  => $tpl->font_family,
                'preview_css'  => $tpl->preview_css,
            ],['%s','%s','%s','%s','%s','%s','%s']) ? $wpdb->insert_id : 0;
            if ($new_id) {
                wp_redirect(admin_url('admin.php?page=pr-templates&action=edit&id='.$new_id.'&duped=1'));
                exit;
            }
        }
    }

    // ── Save template
    if (isset($_POST['pr_tpl_save_nonce']) && wp_verify_nonce($_POST['pr_tpl_save_nonce'],'pr_tpl_save')) {
        $id   = (int)($_POST['tpl_id']??0);
        $html = wp_unslash($_POST['tpl_html']??'');
        $html = preg_replace('/<\?.*?\?>/s','',$html); // strip PHP
        $d = [
            'name'         => sanitize_text_field($_POST['tpl_name']??''),
            'description'  => sanitize_text_field($_POST['tpl_desc']??''),
            'html'         => $html,
            'accent_color' => sanitize_hex_color($_POST['tpl_accent']??'#1a1a2e') ?: '#1a1a2e',
            'font_family'  => sanitize_text_field($_POST['tpl_font']??'Arial'),
        ];
        $f = ['%s','%s','%s','%s','%s'];
        if ($id) {
            $tpl = pr_get_template($id);
            if ($tpl && $tpl->oob_key) {
                $msg = '<div class="notice notice-error"><p>Vestavěné šablony nelze editovat. Nejprve vytvořte kopii.</p></div>';
            } else {
                $wpdb->update(PR_TEMPLATES,$d,['id'=>$id],$f,['%d']);
                $msg = '<div class="notice notice-success"><p>✅ Šablona uložena.</p></div>';
            }
        } else {
            $wpdb->insert(PR_TEMPLATES,$d,$f);
            $id = $wpdb->insert_id;
            wp_redirect(admin_url('admin.php?page=pr-templates&action=edit&id='.$id.'&saved=1'));
            exit;
        }
    }

    // ── Route views
    if ($action==='edit' || $action==='new') {
        $tpl = ($action==='edit' && isset($_GET['id'])) ? pr_get_template((int)$_GET['id']) : null;
        pr_render_template_editor($tpl, $msg);
        return;
    }

    // ── Preview (raw HTML output)
    if ($action==='preview' && isset($_GET['id'])) {
        pr_render_template_preview((int)$_GET['id']);
        exit;
    }

    // ── List view
    pr_render_templates_list($msg);
}

// ── List view ─────────────────────────────────────────────────────────────────

function pr_render_templates_list($msg='') {
    $templates = pr_get_all_templates();
    $oob_data  = pr_oob_templates();
    ?>
    <div class="wrap pr-admin">
        <h1>🎨 Šablony vstupenek
            <a href="<?php echo admin_url('admin.php?page=pr-templates&action=new'); ?>" class="page-title-action">+ Nová šablona</a>
        </h1>
        <?php echo $msg; ?>
        <?php if(isset($_GET['duped'])): ?><div class="notice notice-success"><p>✅ Kopie vytvořena. Upravte ji dle potřeby.</p></div><?php endif; ?>
        <?php if(isset($_GET['saved'])): ?><div class="notice notice-success"><p>✅ Šablona uložena.</p></div><?php endif; ?>

        <p style="color:#555;margin-bottom:20px">Vestavěné šablony lze <strong>duplikovat</strong> a pak libovolně upravit. Vlastní šablony lze plně editovat a smazat.</p>

        <div class="pr-tpl-grid">
        <?php foreach($templates as $t):
            $is_oob   = !empty($t->oob_key);
            $preview  = $t->preview_css ?: ($is_oob && isset($oob_data[$t->oob_key]) ? $oob_data[$t->oob_key]['preview'] : 'linear-gradient(135deg,#e5e7eb,#d1d5db)');
            $used_by  = pr_template_used_by_count($t->id);
        ?>
        <div class="pr-tpl-card <?php echo $is_oob?'pr-tpl-oob':'pr-tpl-custom'; ?>">
            <div class="pr-tpl-preview" style="background:<?php echo esc_attr($preview); ?>">
                <?php if($is_oob): ?><span class="pr-tpl-oob-badge">Vestavěná</span><?php endif; ?>
                <div class="pr-tpl-preview-ticket">
                    <div class="pr-tpl-preview-hd" style="background:<?php echo esc_attr($t->accent_color); ?>"></div>
                    <div class="pr-tpl-preview-body">
                        <div class="pr-tpl-preview-lines">
                            <div class="pr-tpl-preview-line pr-l-wide"></div>
                            <div class="pr-tpl-preview-line pr-l-mid"></div>
                            <div class="pr-tpl-preview-line pr-l-short"></div>
                        </div>
                        <div class="pr-tpl-preview-qr"></div>
                    </div>
                </div>
            </div>
            <div class="pr-tpl-info">
                <div class="pr-tpl-name"><?php echo esc_html($t->name); ?></div>
                <div class="pr-tpl-desc"><?php echo esc_html($t->description); ?></div>
                <?php if($used_by): ?>
                <div class="pr-tpl-used">Používá <?php echo $used_by; ?> typ<?php echo $used_by>1?'y':''; ?> vstupenek</div>
                <?php endif; ?>
            </div>
            <div class="pr-tpl-actions">
                <a href="<?php echo admin_url('admin.php?page=pr-templates&action=preview&id='.$t->id); ?>"
                   target="_blank" class="button button-small">👁 Náhled</a>
                <?php if(!$is_oob): ?>
                <a href="<?php echo admin_url('admin.php?page=pr-templates&action=edit&id='.$t->id); ?>"
                   class="button button-small button-primary">✏️ Upravit</a>
                <?php else: ?>
                <span class="button button-small disabled" style="cursor:default;opacity:.5" title="Vestavěné šablony nelze editovat">✏️ Upravit</span>
                <?php endif; ?>
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=pr-templates&action=duplicate&id='.$t->id),'pr_dup_tpl'); ?>"
                   class="button button-small">⧉ Duplikovat</a>
                <?php if(!$is_oob): ?>
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=pr-templates&action=delete&id='.$t->id),'pr_del_tpl'); ?>"
                   class="button button-small" style="color:#c00"
                   onclick="return confirm('Smazat šablonu \'<?php echo esc_js($t->name); ?>\'?')">🗑 Smazat</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php
}

// ── Editor view ───────────────────────────────────────────────────────────────

function pr_render_template_editor($tpl, $msg='') {
    $is_edit  = !empty($tpl);
    $is_oob   = $is_edit && !empty($tpl->oob_key);
    $fonts    = ['Arial'=>'Arial','Georgia'=>'Georgia (serif)','Verdana'=>'Verdana','Trebuchet MS'=>'Trebuchet MS','Times New Roman'=>'Times New Roman'];
    ?>
    <div class="wrap pr-admin">
        <h1><?php echo $is_edit ? '✏️ '.esc_html($tpl->name) : '➕ Nová šablona'; ?></h1>
        <a href="<?php echo admin_url('admin.php?page=pr-templates'); ?>">← Zpět na šablony</a>
        <?php echo $msg; ?>
        <?php if(isset($_GET['duped'])): ?><div class="notice notice-success"><p>✅ Kopie vytvořena — nyní ji upravte a uložte.</p></div><?php endif; ?>
        <?php if(isset($_GET['saved'])): ?><div class="notice notice-success"><p>✅ Uloženo.</p></div><?php endif; ?>

        <?php if($is_oob): ?>
        <div class="notice notice-warning" style="margin-top:12px">
            <p>⚠️ Toto je <strong>vestavěná šablona</strong> — nelze ji editovat ani smazat. Použijte tlačítko <strong>⧉ Duplikovat</strong> pro vytvoření vlastní kopie.</p>
        </div>
        <?php endif; ?>

        <div class="pr-tpl-editor-wrap">
            <!-- Left: settings -->
            <div class="pr-tpl-sidebar">
                <form method="post" id="pr-tpl-form">
                    <?php wp_nonce_field('pr_tpl_save','pr_tpl_save_nonce'); ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="tpl_id" value="<?php echo $is_edit?(int)$tpl->id:0; ?>">

                    <table class="form-table" style="margin:0">
                        <tr><th>Název *</th><td><input type="text" name="tpl_name" value="<?php echo esc_attr($tpl->name??''); ?>" class="regular-text" required <?php echo $is_oob?'readonly':''; ?>></td></tr>
                        <tr><th>Popis</th><td><input type="text" name="tpl_desc" value="<?php echo esc_attr($tpl->description??''); ?>" class="regular-text" <?php echo $is_oob?'readonly':''; ?>></td></tr>
                        <tr><th>Výchozí barva</th><td>
                            <input type="color" name="tpl_accent" value="<?php echo esc_attr($tpl->accent_color??'#1a1a2e'); ?>" id="tpl_accent" <?php echo $is_oob?'disabled':''; ?>>
                            <span style="font-family:monospace;margin-left:8px;font-size:12px" id="tpl_accent_hex"><?php echo esc_html($tpl->accent_color??'#1a1a2e'); ?></span>
                        </td></tr>
                        <tr><th>Font</th><td>
                            <select name="tpl_font" <?php echo $is_oob?'disabled':''; ?>>
                                <?php foreach($fonts as $f=>$l): ?>
                                <option value="<?php echo esc_attr($f); ?>" <?php selected($tpl->font_family??'Arial',$f); ?>><?php echo esc_html($l); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td></tr>
                    </table>

                    <?php if(!$is_oob): ?>
                    <div style="margin-top:16px;display:flex;gap:8px;align-items:center">
                        <?php submit_button('💾 Uložit','primary','',false); ?>
                        <?php if($is_edit): ?>
                        <a href="<?php echo admin_url('admin.php?page=pr-templates&action=preview&id='.$tpl->id); ?>"
                           target="_blank" class="button">👁 Náhled</a>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div style="margin-top:16px">
                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=pr-templates&action=duplicate&id='.$tpl->id),'pr_dup_tpl'); ?>"
                           class="button button-primary">⧉ Vytvořit editovatelnou kopii</a>
                    </div>
                    <?php endif; ?>

                    <hr style="margin:20px 0">
                    <h3 style="margin-bottom:8px;font-size:13px">Dostupné proměnné</h3>
                    <div class="pr-tpl-vars">
                        <?php foreach([
                            '{{org_name}}','{{event_name}}','{{event_date}}','{{event_location}}',
                            '{{event_subtitle}}','{{ticket_type}}','{{buyer_name}}','{{order_ref}}',
                            '{{seq_number}}','{{qr_img_url}}','{{logo_img}}','{{accent_color}}',
                            '{{accent_light}}','{{font_family}}','{{dress_code}}','{{ticket_note}}',
                            '{{is_vip_class}}','{{org_address}}',
                        ] as $v): ?>
                        <code class="pr-tpl-var" onclick="prInsertVar('<?php echo esc_js($v); ?>')" title="Kliknout pro vložení"><?php echo esc_html($v); ?></code>
                        <?php endforeach; ?>
                    </div>
                    <p style="font-size:11px;color:#888;margin-top:8px">Podmíněné bloky: <code>{{#if var}}...{{/if}}</code></p>
                </form>
            </div>

            <!-- Right: HTML editor -->
            <div class="pr-tpl-editor-col">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                    <strong style="font-size:13px">HTML šablona</strong>
                    <?php if($is_oob): ?>
                    <span style="font-size:12px;color:#888">Pouze pro čtení</span>
                    <?php endif; ?>
                </div>
                <textarea name="tpl_html" id="pr-tpl-html" form="pr-tpl-form"
                    style="width:100%;height:560px;font-family:monospace;font-size:12px;border:1px solid #ccd;border-radius:4px;padding:10px;tab-size:2;resize:vertical"
                    <?php echo $is_oob?'readonly':''; ?>><?php echo esc_textarea($tpl->html??pr_default_new_template_html()); ?></textarea>
            </div>
        </div>
    </div>

    <script>
    document.getElementById('tpl_accent')?.addEventListener('input',function(){
        document.getElementById('tpl_accent_hex').textContent = this.value;
    });
    function prInsertVar(v){
        var ta = document.getElementById('pr-tpl-html');
        if(!ta||ta.readOnly) return;
        var s=ta.selectionStart,e=ta.selectionEnd;
        ta.value=ta.value.substring(0,s)+v+ta.value.substring(e);
        ta.selectionStart=ta.selectionEnd=s+v.length;
        ta.focus();
    }
    </script>
    <?php
}

// ── Preview ───────────────────────────────────────────────────────────────────

function pr_render_template_preview($id) {
    if (!current_user_can('manage_options')) wp_die();
    $tpl = pr_get_template($id);
    if (!$tpl) wp_die('Šablona nenalezena.');

    $fake_order  = (object)['id'=>0,'order_ref'=>'PR-PREVIEW','buyer_name'=>'Jan Novák'];
    $fake_event  = (object)['name'=>'Ukázkový Ples 2025','event_date'=>date('Y-m-d H:i:s',strtotime('+30 days')),'location'=>'Praha, Obecní dům'];
    $fake_ticket = (object)['type_name'=>'VIP','qr_token'=>'preview000','seq_number'=>1];

    // Temporarily override accent/font from template
    $orig_accent = get_option('pr_ticket_accent');
    $orig_font   = get_option('pr_ticket_font');
    update_option('pr_ticket_accent', $tpl->accent_color);
    update_option('pr_ticket_font',   $tpl->font_family);

    $html = pr_render_ticket_page($tpl->html, $fake_order, $fake_event, $fake_ticket);

    update_option('pr_ticket_accent', $orig_accent);
    update_option('pr_ticket_font',   $orig_font);

    echo $html;
}

// ── Usage count helper ────────────────────────────────────────────────────────

function pr_template_used_by_count($template_id) {
    global $wpdb;
    $tbl_exists = $wpdb->get_var("SHOW TABLES LIKE '".PR_TICKET_TYPES."'") === PR_TICKET_TYPES;
    if (!$tbl_exists) return 0;
    return (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM ".PR_TICKET_TYPES." WHERE template_id=%d",$template_id));
}

// ── Default HTML for new blank template ──────────────────────────────────────

function pr_default_new_template_html() {
    $klasicka = PR_DIR . 'templates/oob/klasicka.html';
    return file_exists($klasicka) ? file_get_contents($klasicka) : '';
}
