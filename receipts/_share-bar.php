<?php
// Expects: $msg (string). Optional: $recipientMobile (string|null).
$recipientMobile = $recipientMobile ?? ($row['mobile'] ?? ($sale['cust_mobile'] ?? ($pay['cust_mobile'] ?? '')));
$cleanMobile = preg_replace('/\D+/', '', (string)$recipientMobile);
if ($cleanMobile !== '' && strlen($cleanMobile) === 10) $cleanMobile = '91' . $cleanMobile; // default IN country code
?>
<div class="toolbar">
  <?php if ($cleanMobile): ?>
    <a class="btn-wa" target="_blank" rel="noopener"
       href="https://wa.me/<?= e($cleanMobile) ?>?text=<?= rawurlencode($msg) ?>">
      <i class="bi bi-whatsapp"></i> WhatsApp
    </a>
    <a class="btn-sms"
       href="sms:<?= e($cleanMobile) ?>?body=<?= rawurlencode($msg) ?>">
      <i class="bi bi-chat-left-text"></i> SMS
    </a>
  <?php endif; ?>
  <button type="button" onclick="sbShareText()"><i class="bi bi-share"></i> Share</button>
  <button type="button" onclick="sbCopyText()"><i class="bi bi-clipboard"></i> Copy</button>
</div>
<script>
  (() => {
    const text = <?= json_encode($msg, JSON_UNESCAPED_UNICODE) ?>;
    window.sbShareText = async () => {
      if (navigator.share) {
        try { await navigator.share({ text }); } catch (_) {}
      } else { sbCopyText(); }
    };
    window.sbCopyText = async () => {
      try { await navigator.clipboard.writeText(text); alert('Copied to clipboard.'); }
      catch { prompt('Copy the message:', text); }
    };
  })();
</script>
