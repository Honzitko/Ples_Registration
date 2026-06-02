<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function pr_admin_orders_page() {
    if (!current_user_can('manage_options')) wp_die('Přístup odepřen.');
    global $wpdb;
    $msg = '';

    // Mark as paid
    if (isset($_POST['pr_mark_paid_nonce']) && wp_verify_nonce($_POST['pr_mark_paid_nonce'],'pr_mark_paid')) {
        $oid  = (int)($_POST['order_id']??0);
        $note = sanitize_text_field($_POST['payment_note']??'');
        if (pr_mark_paid($oid, $note)) {
            $msg = '<div class="notice notice-success"><p>✅ Objednávka označena jako zaplacená.</p></div>';
        } else {
            $msg = '<div class="notice notice-error"><p>Chyba – objednávka nenalezena nebo již zaplacena.</p></div>';
        }
    }

    // Cancel
    if (isset($_GET['action'],$_GET['id']) && $_GET['action']==='cancel' && check_admin_referer('pr_cancel')) {
        $oid   = (int)$_GET['id'];
        $order = pr_get_order($oid);
        if ($order && $order->status==='pending') {
            foreach (pr_get_order_items($oid) as $it) pr_release_type($it->type_id,$it->quantity);
            $wpdb->update(PR_ORDERS,['status'=>'cancelled'],['id'=>$oid],['%s'],['%d']);
            $msg = '<div class="notice notice-success"><p>Objednávka zrušena.</p></div>';
        }
    }

    // Resend email
    if (isset($_GET['action'],$_GET['id']) && $_GET['action']==='resend' && check_admin_referer('pr_resend')) {
        $oid   = (int)$_GET['id'];
        $order = pr_get_order($oid);
        if ($order) {
            $event = pr_get_event($order->event_id);
            $items = pr_get_order_items($oid);
            pr_send_order_email($order, $event, $items);
            $msg = '<div class="notice notice-success"><p>✉️ E-mail znovu odeslán na '.esc_html($order->buyer_email).'.</p></div>';
        }
    }

    // Export CSV
    if (isset($_GET['export']) && check_admin_referer('pr_export')) {
        pr_export_orders_csv(); exit;
    }

    $event_id = (int)($_GET['event']??0);
    $status   = sanitize_text_field($_GET['status']??'');
    $search   = sanitize_text_field($_GET['s']??'');

    $where = 'WHERE 1=1'; $args = [];
    if ($event_id) { $where .= ' AND o.event_id=%d'; $args[]=$event_id; }
    if ($status)   { $where .= ' AND o.status=%s';   $args[]=$status; }
    if ($search)   { $where .= ' AND (o.buyer_name LIKE %s OR o.buyer_email LIKE %s OR o.order_ref=%s OR o.var_symbol=%s)';
        $args[]="%$search%"; $args[]="%$search%"; $args[]=$search; $args[]=$search; }

    // Guard: create tables if missing
    $orders_exist = $wpdb->get_var("SHOW TABLES LIKE '".PR_ORDERS."'") === PR_ORDERS;
    if (!$orders_exist) {
        pr_create_tables();
        echo '<div class="notice notice-warning"><p>Tabulky byly právě vytvořeny. Obnovte stránku.</p></div>';
    }

    $sql = "SELECT o.*, e.name as event_name FROM ".PR_ORDERS." o
            JOIN ".PR_EVENTS." e ON e.id=o.event_id
            $where ORDER BY o.created_at DESC LIMIT 300";
    $orders = $args ? $wpdb->get_results($wpdb->prepare($sql,...$args)) : $wpdb->get_results($sql);

    $events = $wpdb->get_results("SELECT id,name FROM ".PR_EVENTS." ORDER BY event_date DESC");

    // Stats
    $eq = $event_id ? $wpdb->prepare(' AND event_id=%d',$event_id) : '';
    $stats = $wpdb->get_row("SELECT
        COUNT(*) as total,
        SUM(status='paid') as paid,
        SUM(status='pending') as pending,
        SUM(CASE WHEN status='paid' THEN total_price ELSE 0 END) as revenue
        FROM ".PR_ORDERS." WHERE 1=1".$eq);
    if (!$stats) $stats = (object)['total'=>0,'paid'=>0,'pending'=>0,'revenue'=>0];
    ?>
    <div class="wrap pr-admin">
        <h1>📦 Objednávky</h1>
        <?php echo $msg; ?>

        <!-- Stats -->
        <div class="pr-stats-bar">
            <div class="pr-stat"><span class="pr-stat-num"><?php echo (int)$stats->total; ?></span><span class="pr-stat-label">Celkem</span></div>
            <div class="pr-stat pr-stat-green"><span class="pr-stat-num"><?php echo (int)$stats->paid; ?></span><span class="pr-stat-label">Zaplaceno</span></div>
            <div class="pr-stat pr-stat-yellow"><span class="pr-stat-num"><?php echo (int)$stats->pending; ?></span><span class="pr-stat-label">Čeká na platbu</span></div>
            <div class="pr-stat pr-stat-blue"><span class="pr-stat-num"><?php echo esc_html(pr_format_price($stats->revenue??0)); ?></span><span class="pr-stat-label">Výnos</span></div>
        </div>

        <!-- Filters -->
        <form method="get" class="pr-filters">
            <input type="hidden" name="page" value="pr-orders">
            <select name="event">
                <option value="">– Všechny akce –</option>
                <?php foreach($events as $ev): ?>
                <option value="<?php echo $ev->id; ?>" <?php selected($event_id,$ev->id); ?>><?php echo esc_html($ev->name); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status">
                <option value="">– Všechny stavy –</option>
                <option value="pending"   <?php selected($status,'pending'); ?>>⏳ Čeká na platbu</option>
                <option value="paid"      <?php selected($status,'paid'); ?>>✅ Zaplaceno</option>
                <option value="cancelled" <?php selected($status,'cancelled'); ?>>❌ Zrušeno</option>
            </select>
            <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Jméno / e-mail / VS…" style="width:200px">
            <button type="submit" class="button">Filtrovat</button>
            <a href="<?php echo wp_nonce_url(add_query_arg(['export'=>1,'event'=>$event_id,'status'=>$status]),'pr_export'); ?>" class="button">⬇ CSV</a>
        </form>

        <table class="wp-list-table widefat fixed" id="pr-orders-table">
            <thead><tr>
                <th style="width:120px">Objednávka</th>
                <th>Akce / Vstupenky</th>
                <th>Kupující</th>
                <th style="width:85px">VS</th>
                <th style="width:85px">Částka</th>
                <th style="width:90px">Stav</th>
                <th style="width:90px">Zaplaceno</th>
                <th>Akce</th>
            </tr></thead>
            <tbody>
            <?php if(empty($orders)): ?>
                <tr><td colspan="8" style="padding:20px;color:#888">Žádné objednávky.</td></tr>
            <?php else: foreach($orders as $o):
                $items        = pr_get_order_items($o->id);
                $item_summary = implode(', ', array_map(function($i){ return $i->quantity.'× '.$i->type_name; }, $items));
            ?>
                <tr class="pr-order-row pr-row-<?php echo esc_attr($o->status); ?>" id="pr-order-<?php echo $o->id; ?>">
                    <td><code style="font-size:11px"><?php echo esc_html($o->order_ref); ?></code>
                        <br><small style="color:#aaa"><?php echo esc_html(date('j.n.Y',strtotime($o->created_at))); ?></small></td>
                    <td>
                        <strong><?php echo esc_html($o->event_name); ?></strong>
                        <br><small style="color:#666"><?php echo esc_html($item_summary); ?></small>
                    </td>
                    <td>
                        <?php echo esc_html($o->buyer_name); ?>
                        <br><small><?php echo esc_html($o->buyer_email); ?></small>
                        <?php if($o->buyer_phone): ?><br><small><?php echo esc_html($o->buyer_phone); ?></small><?php endif; ?>
                        <?php if($o->buyer_street || $o->buyer_city || $o->buyer_postcode): ?>
                            <br><small><?php echo esc_html(trim(($o->buyer_street ?? '') . ', ' . ($o->buyer_city ?? '') . ' ' . ($o->buyer_postcode ?? ''), ', ')); ?></small>
                        <?php endif; ?>
                    </td>
                    <td><code style="font-size:13px;font-weight:bold"><?php echo esc_html($o->var_symbol); ?></code></td>
                    <td><strong><?php echo esc_html(pr_format_price($o->total_price)); ?></strong></td>
                    <td>
                        <span class="pr-badge pr-badge-<?php echo esc_attr($o->status); ?>">
                            <?php echo $o->status==='paid'?'✅ Zaplaceno':($o->status==='pending'?'⏳ Čeká':'❌ Zrušeno'); ?>
                        </span>
                        <?php if($o->paid_at): ?>
                        <br><small style="color:#888"><?php echo esc_html(date('j.n.Y',strtotime($o->paid_at))); ?></small>
                        <?php endif; ?>
                        <?php if($o->payment_note): ?>
                        <br><small style="color:#888;font-style:italic"><?php echo esc_html($o->payment_note); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if($o->status==='pending'): ?>
                        <!-- Mark as paid inline form -->
                        <form method="post" class="pr-pay-form" onsubmit="return confirm('Označit jako zaplaceno?')">
                            <?php wp_nonce_field('pr_mark_paid','pr_mark_paid_nonce'); ?>
                            <input type="hidden" name="order_id" value="<?php echo $o->id; ?>">
                            <input type="text" name="payment_note" class="pr-pay-note" placeholder="Poznámka (volitelné)">
                            <button type="submit" class="button pr-btn-paid">✅ Zaplaceno</button>
                        </form>
                        <?php else: ?>
                        <span style="color:#aaa;font-size:12px">–</span>
                        <?php endif; ?>
                    </td>
                    <td class="pr-actions">
                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=pr-orders&action=resend&id='.$o->id),'pr_resend'); ?>"
                           class="button button-small" title="Znovu odeslat e-mail s vstupenkami">✉️</a>
                        <?php if($o->status==='pending'): ?>
                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=pr-orders&action=cancel&id='.$o->id),'pr_cancel'); ?>"
                           class="button button-small" onclick="return confirm('Zrušit objednávku?')" style="color:#c00" title="Zrušit objednávku">✕</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function pr_export_orders_csv() {
    global $wpdb;
    $orders = $wpdb->get_results("SELECT o.*,e.name as event_name FROM ".PR_ORDERS." o JOIN ".PR_EVENTS." e ON e.id=o.event_id ORDER BY o.created_at DESC");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="objednavky-'.date('Y-m-d').'.csv"');
    $f = fopen('php://output','w');
    fprintf($f,chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($f,['Objednávka','Akce','Jméno','E-mail','Telefon','Ulice a čp','Město','PSČ','VS','Částka','Stav','Poznámka','Datum'],';');
    foreach($orders as $o) {
        fputcsv($f,[$o->order_ref,$o->event_name,$o->buyer_name,$o->buyer_email,$o->buyer_phone??'',
                    $o->buyer_street??'',$o->buyer_city??'',$o->buyer_postcode??'',
                    $o->var_symbol,$o->total_price,$o->status,$o->payment_note??'',
                    date('j.n.Y H:i',strtotime($o->created_at))],';');
    }
    fclose($f);
}
