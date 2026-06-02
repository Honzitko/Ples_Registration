<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function pr_admin_events_page() {
    if ( ! current_user_can('manage_options') ) wp_die('Přístup odepřen.');
    global $wpdb;
    $msg = '';

    // Repair tables
    if ( isset($_GET['action']) && $_GET['action']==='repair' && check_admin_referer('pr_repair') ) {
        delete_transient('pr_schema_ok');
        pr_create_tables();
        $missing = pr_get_missing_tables();
        $repair_status = empty($missing) ? 'success' : 'failed';
        wp_redirect(admin_url('admin.php?page=pr-events&repaired=' . $repair_status));
        exit;
    }

    // Delete event
    if ( isset($_GET['action'],$_GET['id']) && $_GET['action']==='delete' && check_admin_referer('pr_delete_event') ) {
        $wpdb->delete(PR_EVENTS, ['id'=>(int)$_GET['id']], ['%d']);
        $msg = '<div class="notice notice-success"><p>Akce smazána.</p></div>';
    }

    // Delete ticket type
    if ( isset($_GET['action'],$_GET['tid']) && $_GET['action']==='del_type' && check_admin_referer('pr_del_type') ) {
        $wpdb->delete(PR_TICKET_TYPES, ['id'=>(int)$_GET['tid']], ['%d']);
        $msg = '<div class="notice notice-success"><p>Typ vstupenky odstraněn.</p></div>';
    }

    // Save event
    if ( isset($_POST['pr_event_nonce']) && wp_verify_nonce($_POST['pr_event_nonce'],'pr_save_event') ) {
        $id = (int)($_POST['event_id']??0);
        $d  = [
            'name'       => sanitize_text_field($_POST['name']),
            'description'=> sanitize_textarea_field($_POST['description']??''),
            'event_date' => sanitize_text_field($_POST['event_date']),
            'location'   => sanitize_text_field($_POST['location']??''),
            'status'     => sanitize_text_field($_POST['status']??'draft'),
        ];
        if ($id) {
            $wpdb->update(PR_EVENTS,$d,['id'=>$id],['%s','%s','%s','%s','%s'],['%d']);
            wp_redirect(admin_url('admin.php?page=pr-events&edit='.$id.'&saved=1'));
        } else {
            $wpdb->insert(PR_EVENTS,$d,['%s','%s','%s','%s','%s']);
            $new_id = $wpdb->insert_id;
            wp_redirect(admin_url('admin.php?page=pr-events&edit='.$new_id.'&saved=1'));
        }
        exit;
    }

    // Save ticket type
    if ( isset($_POST['pr_type_nonce']) && wp_verify_nonce($_POST['pr_type_nonce'],'pr_save_type') ) {
        $eid = (int)($_POST['event_id']??0);
        $tid = (int)($_POST['type_id']??0);
        $d   = [
            'event_id'   => $eid,
            'name'       => sanitize_text_field($_POST['type_name']),
            'description'=> sanitize_text_field($_POST['type_desc']??''),
            'price'      => (float)str_replace(',','.',$_POST['type_price']??0),
            'capacity'   => (int)($_POST['type_capacity']??100),
            'sort_order' => (int)($_POST['type_sort']??0),
            'active'     => isset($_POST['type_active'])?1:0,
            'template_id'=> (int)($_POST['type_template_id']??0),
        ];
        $f = ['%d','%s','%s','%f','%d','%d','%d','%d'];
        if ($tid) { $wpdb->update(PR_TICKET_TYPES,$d,['id'=>$tid],$f,['%d']); }
        else      { $wpdb->insert(PR_TICKET_TYPES,$d,$f); }
        $msg = '<div class="notice notice-success"><p>Typ vstupenky uložen.</p></div>';
        // Redirect back to event detail
        wp_redirect(admin_url('admin.php?page=pr-events&edit='.$eid.'&saved=1'));
        exit;
    }

    // ── Edit view ──
    $edit_id = (int)($_GET['edit']??0);
    if ( isset($_GET['new']) || $edit_id ) {
        $event = $edit_id ? pr_get_event($edit_id) : null;
        $types = $edit_id ? pr_get_ticket_types($edit_id) : [];
        pr_render_event_form($event, $types, $msg);
        return;
    }

    // ── List view ──
    // Simple query first — no subqueries on potentially missing tables
    $events = $wpdb->get_results("SELECT * FROM ".PR_EVENTS." ORDER BY event_date DESC");
    $db_error = $wpdb->last_error;

    // Enrich with counts only if tables exist
    $orders_exist = $wpdb->get_var("SHOW TABLES LIKE '".PR_ORDERS."'") === PR_ORDERS;
    $types_exist  = $wpdb->get_var("SHOW TABLES LIKE '".PR_TICKET_TYPES."'") === PR_TICKET_TYPES;

    foreach ( $events as $ev ) {
        $ev->type_count  = $types_exist  ? (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".PR_TICKET_TYPES." WHERE event_id=%d",$ev->id)) : 0;
        $ev->paid_orders = $orders_exist ? (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".PR_ORDERS." WHERE event_id=%d AND status='paid'",$ev->id)) : 0;
    }
    ?>
    <div class="wrap pr-admin">
        <h1>🎭 Akce <a href="<?php echo admin_url('admin.php?page=pr-events&new=1'); ?>" class="page-title-action">+ Nová akce</a></h1>
        <?php echo $msg; ?>

        <?php if($db_error): ?>
        <div class="notice notice-error">
            <p><strong>Chyba databáze:</strong> <?php echo esc_html($db_error); ?></p>
            <p>Tabulky pravděpodobně nebyly vytvořeny. Klikněte na tlačítko níže.</p>
        </div>
        <?php endif; ?>

        <?php if(isset($_GET['repaired'])): ?>
            <?php $repair_report = pr_get_last_table_repair_report(); ?>
            <?php if($_GET['repaired'] === 'success'): ?>
                <div class="notice notice-success"><p>✅ Databázové tabulky byly zkontrolovány/vytvořeny. Zkuste akci zopakovat.</p></div>
            <?php else: ?>
                <div class="notice notice-error">
                    <p><strong>❌ Databázové tabulky se nepodařilo vytvořit.</strong></p>
                    <?php if(!empty($repair_report['missing'])): ?>
                        <p>Stále chybí: <code><?php echo esc_html(implode(', ', $repair_report['missing'])); ?></code></p>
                    <?php endif; ?>
                    <p>Požádejte hosting o kontrolu, že databázový uživatel WordPressu má práva <code>CREATE TABLE</code>, <code>ALTER TABLE</code>, <code>SHOW TABLES</code> a <code>SHOW COLUMNS</code>.</p>
                    <?php if(!empty($repair_report['report'])): ?>
                        <details>
                            <summary>Technický detail pro podporu hostingu</summary>
                            <pre style="white-space:pre-wrap;background:#fff;border:1px solid #ccd0d4;padding:10px;max-height:280px;overflow:auto;"><?php echo esc_html(print_r($repair_report, true)); ?></pre>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <p style="margin-bottom:16px">
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=pr-events&action=repair'),'pr_repair'); ?>"
               class="button" onclick="return confirm('Znovu vytvořit DB tabulky?')">🔧 Opravit databázové tabulky</a>
            <span style="color:#888;font-size:12px;margin-left:8px">Spusťte pokud se akce nezobrazují nebo plugin nefunguje po instalaci.</span>
        </p>

        <?php if(empty($events)): ?>
        <p>Žádné akce. <a href="<?php echo admin_url('admin.php?page=pr-events&new=1'); ?>">Vytvořte první.</a></p>
        <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr><th>Název</th><th>Datum</th><th>Místo</th><th>Typy vstupenek</th><th>Zaplacené objednávky</th><th>Stav</th><th>Akce</th></tr></thead>
            <tbody>
            <?php foreach($events as $ev): ?>
            <tr>
                <td><strong><?php echo esc_html($ev->name); ?></strong></td>
                <td><?php echo esc_html(pr_format_date($ev->event_date)); ?></td>
                <td><?php echo esc_html($ev->location); ?></td>
                <td><?php echo (int)$ev->type_count; ?></td>
                <td><?php echo (int)$ev->paid_orders; ?></td>
                <td><span class="pr-badge pr-badge-<?php echo esc_attr($ev->status); ?>"><?php echo esc_html($ev->status); ?></span></td>
                <td>
                    <a href="<?php echo admin_url('admin.php?page=pr-events&edit='.$ev->id); ?>">Upravit</a> |
                    <a href="<?php echo admin_url('admin.php?page=pr-orders&event='.$ev->id); ?>">Objednávky</a> |
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=pr-events&action=delete&id='.$ev->id),'pr_delete_event'); ?>"
                       onclick="return confirm('Smazat tuto akci?');" style="color:red">Smazat</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
}

function pr_render_event_form($event, $types, $msg='') {
    $is_edit = !empty($event);
    ?>
    <div class="wrap pr-admin">
        <h1><?php echo $is_edit ? '✏️ Upravit akci' : '➕ Nová akce'; ?></h1>
        <a href="<?php echo admin_url('admin.php?page=pr-events'); ?>">← Zpět na seznam</a>
        <?php echo $msg; ?>
        <?php if(isset($_GET['saved'])): ?><div class="notice notice-success"><p>✅ Akce uložena.</p></div><?php endif; ?>

        <div class="pr-two-col">
        <!-- Event form -->
        <div class="pr-col">
            <h2>Základní informace</h2>
            <form method="post">
                <?php wp_nonce_field('pr_save_event','pr_event_nonce'); ?>
                <input type="hidden" name="event_id" value="<?php echo $is_edit?(int)$event->id:0; ?>">
                <table class="form-table">
                    <tr><th>Název *</th><td><input type="text" name="name" value="<?php echo esc_attr($event->name??''); ?>" class="regular-text" required></td></tr>
                    <tr><th>Popis</th><td><textarea name="description" rows="3" class="large-text"><?php echo esc_textarea($event->description??''); ?></textarea></td></tr>
                    <tr><th>Datum a čas *</th><td><input type="datetime-local" name="event_date" value="<?php echo $is_edit?date('Y-m-d\TH:i',strtotime($event->event_date)):''; ?>" required></td></tr>
                    <tr><th>Místo konání</th><td><input type="text" name="location" value="<?php echo esc_attr($event->location??''); ?>" class="regular-text"></td></tr>
                    <tr><th>Stav</th><td>
                        <select name="status">
                            <option value="draft"  <?php selected($event->status??'draft','draft'); ?>>Příprava (nepublikováno)</option>
                            <option value="active" <?php selected($event->status??'','active'); ?>>Aktivní (prodej spuštěn)</option>
                            <option value="closed" <?php selected($event->status??'','closed'); ?>>Uzavřeno</option>
                        </select>
                    </td></tr>
                </table>
                <?php submit_button($is_edit?'Uložit':'Vytvořit akci'); ?>
            </form>
        </div>

        <!-- Ticket types -->
        <?php if($is_edit): ?>
        <div class="pr-col">
            <h2>Typy vstupenek</h2>

            <?php if(!empty($types)): ?>
            <table class="wp-list-table widefat striped" style="margin-bottom:16px;">
                <thead><tr><th>Název</th><th>Cena</th><th>Kapacita</th><th>Prodáno</th><th>Aktivní</th><th></th></tr></thead>
                <tbody>
                <?php foreach($types as $t): ?>
                <tr>
                    <td><strong><?php echo esc_html($t->name); ?></strong><?php if($t->description): ?><br><small style="color:#888"><?php echo esc_html($t->description); ?></small><?php endif; ?></td>
                    <td><?php echo esc_html(pr_format_price($t->price)); ?></td>
                    <td><?php echo (int)$t->capacity; ?></td>
                    <td><?php echo (int)$t->sold; ?></td>
                    <td><?php echo $t->active ? '✅' : '⛔'; ?></td>
                    <td>
                        <a href="#" onclick="prShowTypeForm(<?php echo esc_js(json_encode($t)); ?>);return false;">Upravit</a> |
                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=pr-events&edit='.$event->id.'&action=del_type&tid='.$t->id),'pr_del_type'); ?>"
                           onclick="return confirm('Smazat?');" style="color:red">Smazat</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <!-- Add/edit type form -->
            <div id="pr-type-form-wrap" style="background:#f9faff;border:1px solid #d0d9ff;border-radius:8px;padding:16px;">
                <h3 id="pr-type-form-title">+ Přidat typ vstupenky</h3>
                <form method="post" id="pr-type-form">
                    <?php wp_nonce_field('pr_save_type','pr_type_nonce'); ?>
                    <input type="hidden" name="event_id" value="<?php echo (int)$event->id; ?>">
                    <input type="hidden" name="type_id" id="type_id" value="0">
                    <table class="form-table" style="margin:0">
                        <tr><th style="width:130px">Název *</th><td><input type="text" name="type_name" id="type_name" class="regular-text" required placeholder="Normální / VIP / Student"></td></tr>
                        <tr><th>Popis</th><td><input type="text" name="type_desc" id="type_desc" class="regular-text" placeholder="Volitelný popis"></td></tr>
                        <tr><th>Cena (Kč) *</th><td><input type="number" name="type_price" id="type_price" min="0" step="1" class="small-text" required> Kč</td></tr>
                        <tr><th>Kapacita *</th><td><input type="number" name="type_capacity" id="type_capacity" min="1" class="small-text" value="100" required></td></tr>
                        <tr><th>Šablona vstupenky</th><td>
                            <select name="type_template_id" id="type_template_id">
                                <option value="0">— Výchozí šablona —</option>
                                <?php foreach(pr_get_user_templates() as $t): ?>
                                <option value="<?php echo $t->id; ?>"><?php echo esc_html($t->name); ?><?php echo $t->oob_key?' ⭐':''; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <a href="<?php echo admin_url('admin.php?page=pr-templates'); ?>" target="_blank" style="font-size:12px;margin-left:8px">Spravovat šablony ↗</a>
                        </td></tr>
                        <tr><th>Pořadí</th><td><input type="number" name="type_sort" id="type_sort" min="0" class="small-text" value="0"></td></tr>
                        <tr><th>Aktivní</th><td><label><input type="checkbox" name="type_active" id="type_active" value="1" checked> Zobrazit ve formuláři</label></td></tr>
                    </table>
                    <?php submit_button('Uložit typ','primary','',false); ?>
                    <button type="button" class="button" onclick="prResetTypeForm()">Zrušit</button>
                </form>
            </div>

            <p style="margin-top:12px;font-size:13px;color:#666">
                💡 Shortcode: <code>[ples_registrace event="<?php echo (int)$event->id; ?>"]</code>
            </p>
        </div>
        <?php endif; ?>
        </div><!-- .pr-two-col -->
    </div>

    <script>
    function prShowTypeForm(t) {
        document.getElementById('pr-type-form-title').textContent = '✏️ Upravit typ';
        document.getElementById('type_id').value           = t.id;
        document.getElementById('type_name').value         = t.name;
        document.getElementById('type_desc').value         = t.description || '';
        document.getElementById('type_price').value        = t.price;
        document.getElementById('type_capacity').value     = t.capacity;
        document.getElementById('type_sort').value         = t.sort_order;
        document.getElementById('type_active').checked     = t.active == 1;
        document.getElementById('type_template_id').value  = t.template_id || 0;
        document.getElementById('pr-type-form-wrap').scrollIntoView({behavior:'smooth'});
    }
    function prResetTypeForm() {
        document.getElementById('pr-type-form-title').textContent = '+ Přidat typ vstupenky';
        document.getElementById('pr-type-form').reset();
        document.getElementById('type_id').value = '0';
    }
    </script>
    <?php
}
