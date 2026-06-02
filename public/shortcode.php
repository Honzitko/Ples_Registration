<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode('ples_registrace','pr_shortcode');
function pr_shortcode($atts) {
    $atts = shortcode_atts(['event'=>0], $atts);
    $event_id = (int)$atts['event'];
    if (!$event_id) return '<p class="pr-error">Chybí parametr event="ID".</p>';

    $event = pr_get_event($event_id);
    if (!$event || $event->status !== 'active') return '<p class="pr-error">Akce není dostupná.</p>';

    $types = pr_get_ticket_types($event_id);
    if (empty($types)) return '<p class="pr-error">Nejsou definovány žádné typy vstupenek.</p>';

    wp_enqueue_style('pr-public');
    wp_enqueue_script('pr-public');

    ob_start();
    ?>
    <div class="pr-form-wrap" id="pr-form-<?php echo $event_id; ?>">
        <div class="pr-event-header">
            <h2 class="pr-event-title"><?php echo esc_html($event->name); ?></h2>
            <div class="pr-event-meta">
                <span>📅 <?php echo esc_html(pr_format_date($event->event_date)); ?></span>
                <?php if($event->location): ?><span>📍 <?php echo esc_html($event->location); ?></span><?php endif; ?>
            </div>
            <?php if($event->description): ?>
            <div class="pr-event-desc"><?php echo nl2br(esc_html($event->description)); ?></div>
            <?php endif; ?>
        </div>

        <form class="pr-order-form" data-event="<?php echo $event_id; ?>">
            <!-- Ticket selection -->
            <div class="pr-section">
                <h3 class="pr-section-title">🎫 Výběr vstupenek</h3>
                <div class="pr-ticket-types">
                    <?php foreach($types as $type):
                        $available = $type->capacity - $type->sold;
                        $sold_out  = $available <= 0;
                    ?>
                    <div class="pr-ticket-type <?php echo $sold_out?'pr-sold-out':''; ?>">
                        <div class="pr-type-info">
                            <div class="pr-type-name"><?php echo esc_html($type->name); ?></div>
                            <?php if($type->description): ?>
                            <div class="pr-type-desc"><?php echo esc_html($type->description); ?></div>
                            <?php endif; ?>
                            <div class="pr-type-price"><?php echo esc_html(pr_format_price($type->price)); ?></div>
                            <?php if(!$sold_out): ?>
                            <div class="pr-type-avail">Zbývá <?php echo $available; ?> míst</div>
                            <?php else: ?>
                            <div class="pr-type-avail pr-sold">Vyprodáno</div>
                            <?php endif; ?>
                        </div>
                        <div class="pr-type-qty">
                            <?php if(!$sold_out): ?>
                            <button type="button" class="pr-qty-btn pr-qty-minus" data-type="<?php echo $type->id; ?>">−</button>
                            <input type="number" class="pr-qty-input" name="qty[<?php echo $type->id; ?>]"
                                   data-type="<?php echo $type->id; ?>" data-price="<?php echo (float)$type->price; ?>"
                                   data-name="<?php echo esc_attr($type->name); ?>"
                                   value="0" min="0" max="<?php echo min(10,$available); ?>" readonly>
                            <button type="button" class="pr-qty-btn pr-qty-plus" data-type="<?php echo $type->id; ?>">+</button>
                            <?php else: ?>
                            <span class="pr-sold-badge">Vyprodáno</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Order summary (shown dynamically) -->
            <div class="pr-summary" id="pr-summary-<?php echo $event_id; ?>" style="display:none">
                <h3 class="pr-section-title">📋 Shrnutí objednávky</h3>
                <div class="pr-summary-lines" id="pr-summary-lines-<?php echo $event_id; ?>"></div>
                <div class="pr-summary-total">
                    Celkem: <strong id="pr-total-<?php echo $event_id; ?>">0 Kč</strong>
                </div>
            </div>

            <!-- Buyer details -->
            <div class="pr-section" id="pr-buyer-<?php echo $event_id; ?>" style="display:none">
                <h3 class="pr-section-title">👤 Kontaktní údaje</h3>
                <div class="pr-form-grid">
                    <div class="pr-field">
                        <label>Jméno a příjmení *</label>
                        <input type="text" name="buyer_name" required placeholder="Jan Novák">
                    </div>
                    <div class="pr-field">
                        <label>E-mailová adresa *</label>
                        <input type="email" name="buyer_email" required placeholder="jan@example.cz">
                    </div>
                    <div class="pr-field">
                        <label>Telefon</label>
                        <input type="tel" name="buyer_phone" placeholder="+420 123 456 789" pattern="(\+[0-9]{12}|\+[0-9]{3} [0-9]{3} [0-9]{3} [0-9]{3}|[0-9]{9}|[0-9]{3} [0-9]{3} [0-9]{3})" title="Zadejte telefon ve formátu +XXXXXXXXXXXX, +XXX XXX XXX XXX, XXXXXXXXX nebo XXX XXX XXX.">
                    </div>
                    <div class="pr-field">
                        <label>Ulice a čp *</label>
                        <input type="text" name="buyer_street" required placeholder="Dlouhá 123">
                    </div>
                    <div class="pr-field">
                        <label>Město *</label>
                        <input type="text" name="buyer_city" required placeholder="Praha">
                    </div>
                    <div class="pr-field">
                        <label>PSČ *</label>
                        <input type="text" name="buyer_postcode" required placeholder="110 00">
                    </div>
                </div>
            </div>

            <!-- Submit -->
            <div class="pr-submit-wrap" id="pr-submit-<?php echo $event_id; ?>" style="display:none">
                <button type="submit" class="pr-btn-submit">
                    Odeslat objednávku →
                </button>
                <p class="pr-submit-note">Po odeslání obdržíte e-mail s platebními instrukcemi a QR kódem pro rychlou platbu.</p>
            </div>

            <!-- Messages -->
            <div class="pr-form-msg" id="pr-msg-<?php echo $event_id; ?>" style="display:none"></div>
        </form>
    </div>
    <?php
    return ob_get_clean();
}
