<?php

declare(strict_types=1);

/**
 * Send a price alert notification email.
 *
 * Uses SMTP credentials if configured, otherwise falls back to PHP mail().
 *
 * @return bool True if the email was sent successfully
 */
function sendNotification(
    string $toEmail,
    string $productName,
    float  $currentPrice,
    float  $targetPrice,
    string $productUrl,
    string $currency = 'SEK',
    ?int   $productId = null
): bool {
    $subject = "Prisbevakning: $productName har nått ditt mål!";

    $formattedCurrent = number_format($currentPrice, 2, ',', ' ') . " $currency";
    $formattedTarget = number_format($targetPrice, 2, ',', ' ') . " $currency";
    $pricerUrl = $productId !== null && defined('APP_URL') && APP_URL !== ''
        ? APP_URL . '/#/products/' . $productId
        : null;

    $html = buildEmailHtml($productName, $formattedCurrent, $formattedTarget, $productUrl, $pricerUrl);
    $plain = buildEmailPlain($productName, $formattedCurrent, $formattedTarget, $productUrl, $pricerUrl);

    $fromEmail = defined('NOTIFICATION_FROM_EMAIL') && NOTIFICATION_FROM_EMAIL !== '' ? NOTIFICATION_FROM_EMAIL : 'noreply@example.com';
    $fromName = 'Pricer';

    // Try SMTP first if credentials are configured
    if (defined('SMTP_HOST') && SMTP_HOST !== '') {
        return sendViaSMTP($toEmail, $subject, $html, $plain, $fromEmail, $fromName);
    }

    // Fallback to PHP mail()
    return sendViaPhpMail($toEmail, $subject, $html, $fromEmail, $fromName);
}

/**
 * Send email using PHP's built-in mail() function.
 */
function sendViaPhpMail(
    string $to,
    string $subject,
    string $htmlBody,
    string $fromEmail,
    string $fromName
): bool {
    $boundary = md5((string) time());

    $headers = implode("\r\n", [
        "From: $fromName <$fromEmail>",
        'MIME-Version: 1.0',
        "Content-Type: multipart/alternative; boundary=\"$boundary\"",
    ]);

    $body = "--$boundary\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . "$htmlBody\r\n"
        . "--$boundary--";

    return mail($to, $subject, $body, $headers);
}

/**
 * Send email via SMTP socket connection.
 * Supports STARTTLS and AUTH LOGIN.
 */
function sendViaSMTP(
    string $to,
    string $subject,
    string $htmlBody,
    string $plainBody,
    string $fromEmail,
    string $fromName
): bool {
    $host = SMTP_HOST;
    $port = defined('SMTP_PORT') ? (int) SMTP_PORT : 587;
    $user = defined('SMTP_USER') ? SMTP_USER : '';
    $pass = defined('SMTP_PASS') ? SMTP_PASS : '';

    $boundary = md5((string) time());

    $message = "From: $fromName <$fromEmail>\r\n"
        . "To: $to\r\n"
        . "Subject: $subject\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n"
        . "\r\n"
        . "--$boundary\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n"
        . "\r\n"
        . "$plainBody\r\n"
        . "--$boundary\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n"
        . "\r\n"
        . "$htmlBody\r\n"
        . "--$boundary--";

    try {
        $socket = @fsockopen($host, $port, $errno, $errstr, 10);
        if (!$socket) {
            error_log("SMTP connect failed: $errstr ($errno)");
            return false;
        }

        // Read greeting
        smtpRead($socket);

        // EHLO
        smtpWrite($socket, "EHLO " . gethostname());
        smtpRead($socket);

        // STARTTLS if port 587
        if ($port === 587) {
            smtpWrite($socket, "STARTTLS");
            smtpRead($socket);

            $crypto = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            if (!$crypto) {
                error_log("SMTP STARTTLS failed");
                fclose($socket);
                return false;
            }

            smtpWrite($socket, "EHLO " . gethostname());
            smtpRead($socket);
        }

        // AUTH LOGIN
        if ($user !== '' && $pass !== '') {
            smtpWrite($socket, "AUTH LOGIN");
            smtpRead($socket);

            smtpWrite($socket, base64_encode($user));
            smtpRead($socket);

            smtpWrite($socket, base64_encode($pass));
            $authReply = smtpRead($socket);
            if (!str_starts_with($authReply, '235')) {
                error_log("SMTP AUTH failed: $authReply");
                fclose($socket);
                return false;
            }
        }

        // MAIL FROM
        smtpWrite($socket, "MAIL FROM:<$fromEmail>");
        smtpRead($socket);

        // RCPT TO
        smtpWrite($socket, "RCPT TO:<$to>");
        smtpRead($socket);

        // DATA
        smtpWrite($socket, "DATA");
        smtpRead($socket);

        // Send message (dot-stuffing)
        $safeMessage = str_replace("\r\n.", "\r\n..", $message);
        smtpWrite($socket, "$safeMessage\r\n.");
        smtpRead($socket);

        // QUIT
        smtpWrite($socket, "QUIT");
        fclose($socket);

        return true;
    } catch (\Throwable $e) {
        error_log("SMTP error: " . $e->getMessage());
        return false;
    }
}

function smtpWrite(mixed $socket, string $data): void
{
    fwrite($socket, $data . "\r\n");
}

function smtpRead(mixed $socket): string
{
    $response = '';
    while ($line = fgets($socket, 512)) {
        $response .= $line;
        // Check if this is the last line (4th char is space, not dash)
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $response;
}

/**
 * Send a back-in-stock notification email.
 */
function sendBackInStockNotification(
    string $toEmail,
    string $productName,
    string $productUrl,
    ?float $currentPrice = null,
    string $currency = 'SEK',
    ?int   $productId = null
): bool {
    $subject = "Åter i lager: $productName";

    $priceStr = $currentPrice !== null
        ? number_format($currentPrice, 2, ',', ' ') . " $currency"
        : null;
    $pricerUrl = $productId !== null && defined('APP_URL') && APP_URL !== ''
        ? APP_URL . '/#/products/' . $productId
        : null;

    $html = buildBackInStockHtml($productName, $productUrl, $priceStr, $pricerUrl);
    $plain = buildBackInStockPlain($productName, $productUrl, $priceStr, $pricerUrl);

    $fromEmail = defined('NOTIFICATION_FROM_EMAIL') && NOTIFICATION_FROM_EMAIL !== '' ? NOTIFICATION_FROM_EMAIL : 'noreply@example.com';
    $fromName = 'Pricer';

    if (defined('SMTP_HOST') && SMTP_HOST !== '') {
        return sendViaSMTP($toEmail, $subject, $html, $plain, $fromEmail, $fromName);
    }

    return sendViaPhpMail($toEmail, $subject, $html, $fromEmail, $fromName);
}

// ─── Email templates ──────────────────────────────────────

function buildBackInStockHtml(string $productName, string $productUrl, ?string $price, ?string $pricerUrl = null): string
{
    $name = htmlspecialchars($productName, ENT_QUOTES, 'UTF-8');
    $url = htmlspecialchars($productUrl, ENT_QUOTES, 'UTF-8');
    $priceRow = $price !== null
        ? "<tr><td style=\"padding:8px 0;color:#666;\">Nuvarande pris</td><td style=\"padding:8px 0;text-align:right;font-size:20px;font-weight:bold;color:#2e7d32;\">$price</td></tr>"
        : '';
    $pricerLink = $pricerUrl !== null
        ? '<a href="' . htmlspecialchars($pricerUrl, ENT_QUOTES, 'UTF-8') . '" style="display:block;text-align:center;background:#666;color:#fff;padding:14px 24px;border-radius:8px;text-decoration:none;font-weight:bold;margin-top:12px;">Öppna i Pricer →</a>'
        : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="sv">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:Arial,sans-serif;background:#f4f4f4;">
  <div style="max-width:540px;margin:24px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.1);">
    <div style="background:#2e7d32;color:#fff;padding:24px;text-align:center;">
      <h1 style="margin:0;font-size:20px;">📦 Åter i lager!</h1>
    </div>
    <div style="padding:24px;">
      <p style="margin:0 0 16px;font-size:16px;color:#333;">
        <strong>{$name}</strong> finns åter i lager!
      </p>
      <table style="width:100%;border-collapse:collapse;margin:0 0 24px;">{$priceRow}</table>
      <a href="{$url}" style="display:block;text-align:center;background:#2e7d32;color:#fff;padding:14px 24px;border-radius:8px;text-decoration:none;font-weight:bold;">
        Gå till butiken →
      </a>
      {$pricerLink}
    </div>
    <div style="padding:16px 24px;background:#f9f9f9;text-align:center;font-size:12px;color:#999;">
      Skickat av Pricer
    </div>
  </div>
</body>
</html>
HTML;
}

function buildBackInStockPlain(string $productName, string $productUrl, ?string $price, ?string $pricerUrl = null): string
{
    $priceStr = $price !== null ? "\nNuvarande pris: $price\n" : '';
    $pricerStr = $pricerUrl !== null ? "\nÖppna i Pricer: $pricerUrl\n" : '';
    return <<<PLAIN
Åter i lager: {$productName}

{$productName} finns åter i lager!{$priceStr}

Gå till butiken: {$productUrl}{$pricerStr}

—
Pricer
PLAIN;
}

function buildEmailHtml(
    string $productName,
    string $currentPrice,
    string $targetPrice,
    string $productUrl,
    ?string $pricerUrl = null
): string {
    $name = htmlspecialchars($productName, ENT_QUOTES, 'UTF-8');
    $price = htmlspecialchars($currentPrice, ENT_QUOTES, 'UTF-8');
    $target = htmlspecialchars($targetPrice, ENT_QUOTES, 'UTF-8');
    $url = htmlspecialchars($productUrl, ENT_QUOTES, 'UTF-8');
    $pricerLink = $pricerUrl !== null
        ? '<a href="' . htmlspecialchars($pricerUrl, ENT_QUOTES, 'UTF-8') . '" style="display:block;text-align:center;background:#666;color:#fff;padding:14px 24px;border-radius:8px;text-decoration:none;font-weight:bold;margin-top:12px;">Öppna i Pricer →</a>'
        : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="sv">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:Arial,sans-serif;background:#f4f4f4;">
  <div style="max-width:540px;margin:24px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.1);">
    <div style="background:#1976d2;color:#fff;padding:24px;text-align:center;">
      <h1 style="margin:0;font-size:20px;">🏷️ Prisbevakning</h1>
    </div>
    <div style="padding:24px;">
      <p style="margin:0 0 16px;font-size:16px;color:#333;">
        <strong>{$name}</strong> har nått ditt prismål!
      </p>
      <table style="width:100%;border-collapse:collapse;margin:0 0 24px;">
        <tr>
          <td style="padding:8px 0;color:#666;">Nuvarande pris</td>
          <td style="padding:8px 0;text-align:right;font-size:20px;font-weight:bold;color:#2e7d32;">{$price}</td>
        </tr>
        <tr>
          <td style="padding:8px 0;color:#666;border-top:1px solid #eee;">Ditt mål</td>
          <td style="padding:8px 0;text-align:right;color:#666;border-top:1px solid #eee;">{$target}</td>
        </tr>
      </table>
      <a href="{$url}" style="display:block;text-align:center;background:#1976d2;color:#fff;padding:14px 24px;border-radius:8px;text-decoration:none;font-weight:bold;">
        Gå till butiken →
      </a>
      {$pricerLink}
    </div>
    <div style="padding:16px 24px;background:#f9f9f9;text-align:center;font-size:12px;color:#999;">
      Skickat av Pricer
    </div>
  </div>
</body>
</html>
HTML;
}

function buildEmailPlain(
    string $productName,
    string $currentPrice,
    string $targetPrice,
    string $productUrl,
    ?string $pricerUrl = null
): string {
    $pricerStr = $pricerUrl !== null ? "\nÖppna i Pricer: $pricerUrl\n" : '';
    return <<<PLAIN
Prisbevakning: {$productName}

Nuvarande pris: {$currentPrice}
Ditt mål: {$targetPrice}

Gå till butiken: {$productUrl}{$pricerStr}

—
Pricer
PLAIN;
}
