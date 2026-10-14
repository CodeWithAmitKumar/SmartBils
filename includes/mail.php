<?php
// Minimal Gmail SMTP sender (STARTTLS + AUTH LOGIN).
// No composer, no PHPMailer. Pure sockets. ~130 lines.
// Credentials live in the SEPARATE `smartbils_mail` database (via mail_db()).
declare(strict_types=1);

/** Load the singleton mail-config row. Returns ['from_name','from_email','app_password']. */
function mail_config_get(): array {
    $empty = ['from_name' => '', 'from_email' => '', 'app_password' => ''];
    try {
        $r = mail_db()->query('SELECT from_name, from_email, app_password FROM mail_config WHERE id = 1')->fetch();
    } catch (Throwable $e) {
        return $empty;
    }
    return is_array($r) ? ($r + $empty) : $empty;
}

/** True when the mail DB + table is reachable. */
function mail_db_exists(): bool {
    try { mail_db()->query('SELECT 1 FROM mail_config LIMIT 1')->fetch(); return true; }
    catch (Throwable $e) { return false; }
}

/** Save/replace the singleton. Blank app_password keeps the existing one. */
function mail_config_save(string $fromName, string $fromEmail, string $appPassword): void {
    $cur = mail_config_get();
    $pw  = $appPassword !== '' ? $appPassword : ($cur['app_password'] ?? '');
    mail_db()->prepare('REPLACE INTO mail_config (id, from_name, from_email, app_password) VALUES (1, :n, :e, :p)')
        ->execute([':n' => $fromName, ':e' => $fromEmail, ':p' => $pw]);
}

/** True when name + email + app password are all configured. */
function mail_configured(): bool {
    $c = mail_config_get();
    return $c['from_name'] !== '' && $c['from_email'] !== '' && $c['app_password'] !== '';
}

/**
 * Send an email via Gmail SMTP.
 * Returns ['ok' => bool, 'error' => string|null].
 */
function mail_send(string $to, string $subject, string $htmlBody, ?string $plainBody = null): array {
    if (!mail_configured()) return ['ok' => false, 'error' => 'Mail not configured (set sender name, email, app password).'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Invalid recipient address.'];

    $cfg       = mail_config_get();
    $fromName  = $cfg['from_name'];
    $fromEmail = $cfg['from_email'];
    $password  = $cfg['app_password'];

    try {
        _smtp_send(
            'smtp.gmail.com', 587,
            $fromEmail, (string)$password,
            $fromEmail, $fromName,
            $to, $subject, $htmlBody, $plainBody ?? strip_tags($htmlBody)
        );
        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        error_log('[smtp] ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/** Internal: SMTP conversation. Throws on failure. */
function _smtp_send(string $host, int $port, string $user, string $pass,
                    string $fromEmail, string $fromName,
                    string $to, string $subject, string $html, string $plain): void {
    $sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 15);
    if (!$sock) throw new RuntimeException("Connect failed: $errstr");
    stream_set_timeout($sock, 15);

    $read = function () use ($sock): string {
        $out = '';
        while (!feof($sock)) {
            $line = fgets($sock, 1024);
            if ($line === false) throw new RuntimeException('SMTP read failed.');
            $out .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $out;
    };
    $expect = function (string $resp, string $code, string $step) {
        if (strncmp(ltrim($resp), $code, 3) !== 0) {
            throw new RuntimeException("SMTP $step failed: " . trim($resp));
        }
    };
    $write = function (string $cmd) use ($sock) {
        fwrite($sock, $cmd . "\r\n");
    };

    $expect($read(), '220', 'greeting');
    $write('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    $expect($read(), '250', 'EHLO');

    $write('STARTTLS');
    $expect($read(), '220', 'STARTTLS');
    if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        throw new RuntimeException('TLS negotiation failed.');
    }
    $write('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    $expect($read(), '250', 'EHLO (TLS)');

    $write('AUTH LOGIN');
    $expect($read(), '334', 'AUTH LOGIN');
    $write(base64_encode($user));
    $expect($read(), '334', 'username');
    $write(base64_encode($pass));
    $resp = $read();
    if (strncmp(ltrim($resp), '235', 3) !== 0) {
        throw new RuntimeException('Login failed: ' . trim($resp) . ' — check email & app password.');
    }

    $write("MAIL FROM:<{$fromEmail}>");
    $expect($read(), '250', 'MAIL FROM');
    $write("RCPT TO:<{$to}>");
    $expect($read(), '250', 'RCPT TO');
    $write('DATA');
    $expect($read(), '354', 'DATA');

    // Build the message
    $boundary = 'sb_' . bin2hex(random_bytes(8));
    $encFrom  = mb_encode_mimeheader($fromName, 'UTF-8', 'B');
    $date     = date('r');
    $msgId    = '<' . bin2hex(random_bytes(10)) . '@' . ($_SERVER['SERVER_NAME'] ?? 'smartbilling') . '>';

    $headers = [
        "Date: $date",
        "Message-ID: $msgId",
        "From: $encFrom <$fromEmail>",
        "To: <$to>",
        "Subject: " . mb_encode_mimeheader($subject, 'UTF-8', 'B'),
        "MIME-Version: 1.0",
        "Content-Type: multipart/alternative; boundary=\"$boundary\"",
    ];

    $body  = "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $plain . "\r\n\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $html . "\r\n\r\n";
    $body .= "--$boundary--\r\n";

    // Dot-stuffing (lines starting with '.' must be doubled).
    $data = implode("\r\n", $headers) . "\r\n\r\n" . $body;
    $data = preg_replace('/^\./m', '..', $data);

    fwrite($sock, $data . "\r\n.\r\n");
    $expect($read(), '250', 'end-of-data');

    $write('QUIT');
    fclose($sock);
}

/** Password reset HTML template — polite, premium, one place to edit. */
function mail_reset_html(string $name, string $shopName, string $username, string $password, string $loginUrl): string {
    $brand = e(mail_config_get()['from_name'] ?: APP_NAME);
    $n = e($name); $s = e($shopName); $u = e($username); $p = e($password); $l = e($loginUrl);
    return <<<HTML
<!doctype html>
<html><body style="margin:0;padding:0;background:#f5f6fb;font-family:-apple-system,'Segoe UI',Roboto,sans-serif;color:#0b1220;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f6fb;padding:32px 16px;">
    <tr><td align="center">
      <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#fff;border-radius:18px;box-shadow:0 20px 50px rgba(79,70,229,.15);overflow:hidden;">
        <tr><td style="background:linear-gradient(135deg,#4B1D95 0%,#1E1649 100%);padding:40px 36px;text-align:center;color:#fff;">
          <div style="font-size:34px;line-height:1;">🔑</div>
          <h1 style="margin:14px 0 6px;font-size:24px;letter-spacing:-.02em;">Password reset</h1>
          <p style="margin:0;opacity:.9;font-size:14.5px;">A new temporary password for your {$brand} account</p>
        </td></tr>
        <tr><td style="padding:32px 36px;">
          <p style="margin:0 0 14px;font-size:15.5px;line-height:1.6;">Hi {$n},</p>
          <p style="margin:0 0 20px;font-size:14.5px;line-height:1.65;">
            We received a request to reset your password for <strong>{$s}</strong>. Use the temporary password below to sign in,
            then please change it immediately from <em>Profile → Change password</em>.
          </p>

          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FBF8EE;border:1px solid #E8DFC9;border-radius:14px;margin:18px 0;">
            <tr><td style="padding:18px 20px;">
              <div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.08em;">Username</div>
              <div style="font-size:16px;font-weight:600;margin:4px 0 14px;">{$u}</div>
              <div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.08em;">Temporary password</div>
              <div style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:18px;font-weight:700;color:#4B1D95;margin:4px 0 0;letter-spacing:.04em;">{$p}</div>
            </td></tr>
          </table>

          <div style="text-align:center;margin:28px 0 12px;">
            <a href="{$l}" style="display:inline-block;background:linear-gradient(135deg,#4B1D95 0%,#1E1649 100%);color:#fff;text-decoration:none;padding:14px 32px;border-radius:12px;font-weight:600;font-size:15px;box-shadow:0 10px 24px rgba(76,29,149,.4);">
              Sign in &rarr;
            </a>
          </div>

          <p style="margin:22px 0 0;font-size:13px;color:#64748b;line-height:1.6;">
            If you didn't request this, you can safely ignore this email — the previous password still works only until someone requests another reset.
          </p>
        </td></tr>
        <tr><td style="background:#FAFBFF;padding:16px 36px;border-top:1px solid #EEF0F7;text-align:center;color:#94A3B8;font-size:12.5px;">
          Sent by {$brand} · Please change your password after signing in.
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;
}

/** The welcome HTML template — one function, one place to edit the look. */
function mail_welcome_html(string $ownerName, string $shopName, string $username, string $password, string $loginUrl): string {
    $brand = e(mail_config_get()['from_name'] ?: APP_NAME);
    $o = e($ownerName); $s = e($shopName); $u = e($username); $p = e($password); $l = e($loginUrl);
    return <<<HTML
<!doctype html>
<html><body style="margin:0;padding:0;background:#f5f6fb;font-family:-apple-system,'Segoe UI',Roboto,sans-serif;color:#0b1220;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f6fb;padding:32px 16px;">
    <tr><td align="center">
      <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#fff;border-radius:18px;box-shadow:0 20px 50px rgba(79,70,229,.15);overflow:hidden;">
        <!-- Hero -->
        <tr><td style="background:linear-gradient(135deg,#6366F1 0%,#8B5CF6 100%);padding:40px 36px;text-align:center;color:#fff;">
          <div style="font-size:36px;line-height:1;">🧾</div>
          <h1 style="margin:14px 0 6px;font-size:26px;letter-spacing:-.02em;">Welcome to {$brand}</h1>
          <p style="margin:0;opacity:.9;font-size:15px;">Your shop workspace is ready, {$o}</p>
        </td></tr>
        <!-- Body -->
        <tr><td style="padding:32px 36px;">
          <p style="margin:0 0 16px;font-size:15.5px;line-height:1.6;">
            Hi {$o}, your <strong>{$s}</strong> workspace is live. Use the credentials below to sign in — please change your password right after.
          </p>

          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F7FB;border:1px solid #E5E7F0;border-radius:14px;margin:20px 0;">
            <tr><td style="padding:18px 20px;">
              <div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.08em;">Username</div>
              <div style="font-size:17px;font-weight:600;margin:4px 0 14px;">{$u}</div>
              <div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.08em;">Temporary password</div>
              <div style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:17px;font-weight:700;color:#4F46E5;margin:4px 0 0;letter-spacing:.02em;">{$p}</div>
            </td></tr>
          </table>

          <div style="text-align:center;margin:28px 0 12px;">
            <a href="{$l}" style="display:inline-block;background:linear-gradient(135deg,#6366F1 0%,#8B5CF6 100%);color:#fff;text-decoration:none;padding:14px 32px;border-radius:12px;font-weight:600;font-size:15px;box-shadow:0 10px 24px rgba(99,102,241,.4);">
              Sign in to your workspace →
            </a>
          </div>

          <p style="margin:24px 0 0;font-size:13.5px;color:#64748b;line-height:1.6;text-align:center;">
            If the button doesn't work, open this link in your browser:<br>
            <a href="{$l}" style="color:#4F46E5;word-break:break-all;">{$l}</a>
          </p>
        </td></tr>
        <!-- Footer -->
        <tr><td style="background:#FAFBFF;padding:20px 36px;border-top:1px solid #EEF0F7;text-align:center;color:#94A3B8;font-size:12.5px;">
          You received this because an account was created for you on {$brand}.
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;
}
