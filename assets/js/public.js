/* Ples Registration — Public JS */
jQuery(function($){

  // ── Quantity controls ──────────────────────────────────────────────────────

  $(document).on('click','.pr-qty-btn', function(){
    var $btn  = $(this);
    var tid   = $btn.data('type');
    var $inp  = $btn.closest('.pr-type-qty').find('.pr-qty-input[data-type="'+tid+'"]');
    var max   = parseInt($inp.attr('max')) || 10;
    var val   = parseInt($inp.val()) || 0;

    if ( $btn.hasClass('pr-qty-plus') )  val = Math.min(val+1, max);
    if ( $btn.hasClass('pr-qty-minus') ) val = Math.max(val-1, 0);

    $inp.val(val);
    $btn.closest('.pr-ticket-type').toggleClass('pr-has-qty', val > 0);
    updateSummary($inp.closest('.pr-order-form'));
  });

  // ── Update summary ─────────────────────────────────────────────────────────

  function updateSummary($form) {
    var eventId = $form.data('event');
    var items   = [];
    var hasQty  = false;

    $form.find('.pr-qty-input').each(function(){
      var qty = parseInt($(this).val())||0;
      if (qty > 0) {
        hasQty = true;
        items.push({ type_id: $(this).data('type'), qty: qty });
      }
    });

    var $summary = $('#pr-summary-'+eventId);
    var $buyer   = $('#pr-buyer-'+eventId);
    var $submit  = $('#pr-submit-'+eventId);

    if (!hasQty) {
      $summary.hide(); $buyer.hide(); $submit.hide(); return;
    }

    // Build summary locally (fast)
    var lines = '';
    var total = 0;
    $form.find('.pr-qty-input').each(function(){
      var qty = parseInt($(this).val())||0;
      if (!qty) return;
      var price = parseFloat($(this).data('price'))||0;
      var name  = $(this).data('name');
      var sub   = qty * price;
      total    += sub;
      lines    += '<div class="pr-summary-line"><span>'+escHtml(name)+' × '+qty+'</span><span>'+formatPrice(sub)+'</span></div>';
    });

    $('#pr-summary-lines-'+eventId).html(lines);
    $('#pr-total-'+eventId).text(formatPrice(total));
    $summary.show(); $buyer.show(); $submit.show();
  }

  // ── Form submit ────────────────────────────────────────────────────────────

  $(document).on('submit','.pr-order-form', function(e){
    e.preventDefault();
    var $form   = $(this);
    var eventId = $form.data('event');
    var $btn    = $form.find('.pr-btn-submit');
    var $msg    = $('#pr-msg-'+eventId);

    var name     = $form.find('[name="buyer_name"]').val().trim();
    var email    = $form.find('[name="buyer_email"]').val().trim();
    var phone    = $form.find('[name="buyer_phone"]').val().trim();
    var street   = $form.find('[name="buyer_street"]').val().trim();
    var city     = $form.find('[name="buyer_city"]').val().trim();
    var postcode = $form.find('[name="buyer_postcode"]').val().trim();
    var phonePattern = /^(?:\+\d{12}|\+\d{3} \d{3} \d{3} \d{3}|\d{9}|\d{3} \d{3} \d{3})$/;

    if (!name || !email) {
      showMsg($msg, 'Vyplňte prosím jméno a e-mail.', 'error'); return;
    }
    if (phone && !phonePattern.test(phone)) {
      showMsg($msg, 'Telefon musí být ve formátu +XXXXXXXXXXXX, +XXX XXX XXX XXX, XXXXXXXXX nebo XXX XXX XXX.', 'error'); return;
    }
    if (!street || !city || !postcode) {
      showMsg($msg, 'Vyplňte prosím ulici a čp, město a PSČ.', 'error'); return;
    }

    var data = { action:'pr_submit_order', nonce: PR.nonce, event_id: eventId,
                 buyer_name: name, buyer_email: email, buyer_phone: phone,
                 buyer_street: street, buyer_city: city, buyer_postcode: postcode, qty: {} };

    $form.find('.pr-qty-input').each(function(){
      var qty = parseInt($(this).val())||0;
      if (qty) data.qty[$(this).data('type')] = qty;
    });

    $btn.prop('disabled',true).text('Odesílám…');
    $msg.hide();

    $.post(PR.ajax_url, data, function(res){
      $btn.prop('disabled',false).text('Odeslat objednávku →');
      if (res && res.success) {
        $form.find('.pr-section,.pr-summary,.pr-submit-wrap').hide();
        $msg.removeClass('pr-msg-error').addClass('pr-msg-success').show();
        var emailWarning = '';
        if (res.data.email_error) {
          emailWarning = '<div style="margin-top:10px;padding:10px 14px;background:#fef3c7;border-left:3px solid #f59e0b;font-size:13px;color:#78350f;border-radius:4px">'+
                         '<strong>⚠️ Pouze pro administrátora — důvod selhání e-mailu:</strong><br>'+
                         escHtml(res.data.email_error)+'</div>';
        }
        $msg.html(
          '<strong>✅ '+escHtml(res.data.message)+'</strong>' +
          emailWarning +
          '<div class="pr-order-confirm">' +
          '<h3>Platební instrukce</h3>' +
          '<div class="pr-confirm-row"><span>Variabilní symbol</span><strong>'+escHtml(res.data.var_symbol)+'</strong></div>' +
          '<div class="pr-confirm-row"><span>Celková částka</span><strong>'+escHtml(res.data.total)+'</strong></div>' +
          '<div class="pr-confirm-row"><span>Číslo objednávky</span><span>'+escHtml(res.data.order_ref)+'</span></div>' +
          '</div>'
        );
      } else if (res && res.data && res.data.message) {
        showMsg($msg, res.data.message, 'error');
      } else {
        console.error('PR submit response:', res);
        showMsg($msg, 'Server vrátil neplatnou odpověď. Otevřete konzoli prohlížeče pro detail.', 'error');
      }
    }).fail(function(xhr){
      $btn.prop('disabled',false).text('Odeslat objednávku →');
      console.error('PR AJAX failed:', xhr.status, xhr.responseText);
      var msg = 'Chyba serveru (HTTP ' + xhr.status + ').';
      if (xhr.responseText && xhr.responseText.length < 500) {
        msg += ' Odpověď: ' + xhr.responseText.substring(0, 200);
      }
      showMsg($msg, msg, 'error');
    });
  });

  function showMsg($el, text, type) {
    $el.removeClass('pr-msg-success pr-msg-error')
       .addClass(type==='error'?'pr-msg-error':'pr-msg-success')
       .html(escHtml(text)).show();
  }

  function formatPrice(n) {
    return new Intl.NumberFormat('cs-CZ').format(Math.round(n)) + ' Kč';
  }

  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

});
