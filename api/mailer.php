<?php
// mailer.php — HTML mailer za Dogovoreno.com
// ------------------------------------------
// KORISTI:  require_once __DIR__.'/mailer.php';
// PRIMJERI POZIVA su na dnu fajla (komentirani).

/* =========================
   OSNOVNE POSTAVKE
   ========================= */
function mailer_domain(): string {
  return $_SERVER['HTTP_HOST'] ?? 'dogovoreno.local';
}
function mailer_from_email(): string {
  return 'no-reply@'.mailer_domain();
}
function mailer_from_name(): string {
  return 'Dogovoreno';
}
function mailer_admin_email(): string {
  return 'info@'.mailer_domain();
}
function mailer_base_url(): string {
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  $scheme = $https ? 'https' : 'http';
  return $scheme.'://'.($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/* =========================
   SLANJE MAILA (mail())
   ========================= */
function mailer_send(string $to, string $subject, string $html, ?string $textPlain = null, array $opts = []): bool {
  // Encodaj subject (UTF-8 sigurno)
  $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8');

  // Granice za MIME
  $boundaryAlt = 'b_alt_'.bin2hex(random_bytes(8));

  // Polja
  $fromEmail = $opts['from_email'] ?? mailer_from_email();
  $fromName  = $opts['from_name']  ?? mailer_from_name();
  $replyTo   = $opts['reply_to']   ?? $fromEmail;
  $bcc       = $opts['bcc']        ?? null;
  $returnPath= $opts['return_path']?? $fromEmail;

  // Fallback za plain text (strip HTML)
  if ($textPlain === null) {
    $textPlain = html_entity_decode(
      trim(preg_replace('#<br\s*/?>#i', "\n", strip_tags($html))),
      ENT_QUOTES,
      'UTF-8'
    );
  }

  // Headeri
  $headers  = '';
  $headers .= 'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . " <{$fromEmail}>\r\n";
  $headers .= "Reply-To: {$replyTo}\r\n";
  if ($bcc) $headers .= "Bcc: {$bcc}\r\n";
  $headers .= "MIME-Version: 1.0\r\n";
  $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundaryAlt}\"\r\n";
  // (Napomena) Neki hostingi zahtijevaju return-path kroz 5. parametar:
  $params = "-f {$returnPath}";

  // Tijelo: multipart/alternative (PLAIN + HTML)
  $body  = '';
  // Plain
  $body .= "--{$boundaryAlt}\r\n";
  $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
  $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
  $body .= chunk_split(base64_encode($textPlain))."\r\n";

  // HTML
  $body .= "--{$boundaryAlt}\r\n";
  $body .= "Content-Type: text/html; charset=UTF-8\r\n";
  $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
  $body .= chunk_split(base64_encode($html))."\r\n";

  // Kraj
  $body .= "--{$boundaryAlt}--\r\n";

  return @mail($to, $encodedSubject, $body, $headers, $params);
}

/* =========================
   HTML TEMPLATE
   ========================= */
function mailer_wrap_html(string $title, string $contentHtml): string {
  $brand  = mailer_from_name();
  $domain = mailer_domain();

  return <<<HTML
<!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width">
<title>{$title}</title>
<style>
  body{margin:0;padding:0;background:#0f1115;color:#f7f8fa;font-family:Arial,Helvetica,sans-serif}
  .wrap{max-width:560px;margin:0 auto;padding:24px}
  .card{background:#11141a;border:1px solid rgba(255,255,255,.08);border-radius:14px;overflow:hidden}
  .head{padding:18px 20px;border-bottom:1px solid rgba(255,255,255,.07);display:flex;align-items:center;gap:10px}
  .dot{width:10px;height:10px;border-radius:50%;background:#2563eb;display:inline-block}
  h1{font-size:18px;margin:0}
  .body{padding:20px}
  p{margin:0 0 12px;line-height:1.55;color:#e5e7eb}
  a.btn{display:inline-block;background:#2563eb;color:#fff !important;text-decoration:none;padding:10px 14px;border-radius:10px;font-weight:700}
  .muted{color:#9aa3af;font-size:12px}
  .foot{padding:14px 20px;border-top:1px solid rgba(255,255,255,.07);font-size:12px;color:#9aa3af}
  .brand{font-weight:800;letter-spacing:.2px}
  .hr{height:1px;background:rgba(255,255,255,.08);margin:16px 0}
  pre{white-space:pre-wrap;background:#0b0d12;border:1px solid rgba(255,255,255,.08);padding:12px;border-radius:10px;color:#e5e7eb}
</style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="head">
        <span class="dot"></span>
        <h1>{$brand}</h1>
      </div>
      <div class="body">
        {$contentHtml}
        <div class="hr"></div>
        <p class="muted">Ako niste Vi inicirali ovu radnju, ignorirajte ovu poruku.</p>
      </div>
      <div class="foot">
        <span class="brand">{$brand}</span> • {$domain}
      </div>
    </div>
  </div>
</body>
</html>
HTML;
}

/* =========================
   GOTOVE PORUKE
   ========================= */

// 1) Verifikacija korisnika
function send_verification_email(string $to, string $link): bool {
  $title = 'Potvrdite registraciju — Dogovoreno';
  $safe  = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
  $html  = mailer_wrap_html($title, '
    <p>Hvala na registraciji! Potvrdite svoj e-mail klikom na gumb:</p>
    <p><a href="'.$safe.'" class="btn">Potvrdi e-mail</a></p>
    <p>Link: <a href="'.$safe.'">'.$safe.'</a></p>
  ');
  $text = "Hvala na registraciji!\n\nOtvorite link za potvrdu:\n{$link}\n";
  return mailer_send($to, $title, $html, $text);
}

// 2) Kontakt poruka majstoru (od klijenta)
function send_contact_email(string $to, string $subject, string $bodyText): bool {
  $title = $subject ?: 'Novi upit — Dogovoreno';
  $html  = mailer_wrap_html($title, '
    <p>Imate novi upit od klijenta:</p>
    <pre>'.htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8').'</pre>
    <p>Molimo odgovorite direktno klijentu ili kroz svoj profil.</p>
  ');
  return mailer_send($to, $title, $html, $bodyText);
}

// 3) Obavijest majstoru: novi posao u blizini
//    $data: ['desc'=>..., 'distance_km'=>float|null, 'city'=>string|null, 'cta_url'=>string]
function send_new_job_to_provider(string $to, array $data, ?string $bccAdmin = null): bool {
  $title = 'Novi posao u tvojoj blizini — Dogovoreno';
  $desc  = trim((string)($data['desc'] ?? 'Novi posao'));
  $dist  = isset($data['distance_km']) ? (float)$data['distance_km'] : null;
  $city  = trim((string)($data['city'] ?? ''));
  $cta   = $data['cta_url'] ?? mailer_base_url();

  $meta  = [];
  if ($city !== '') $meta[] = "Lokacija: {$city}";
  if ($dist !== null) $meta[] = "Udaljenost: {$dist} km";
  $metaHtml = $meta ? '<p class="muted">'.htmlspecialchars(implode(' • ', $meta), ENT_QUOTES, 'UTF-8').'</p>' : '';

  $html = mailer_wrap_html($title, '
    <p><strong>Stigao je novi posao.</strong></p>
    <p>'.nl2br(htmlspecialchars(mb_substr($desc,0,400), ENT_QUOTES, 'UTF-8')).'</p>'.
    $metaHtml.
    '<p><a href="'.htmlspecialchars($cta, ENT_QUOTES, 'UTF-8').'" class="btn">Otvori u Dogovoreno</a></p>
  ');
  $text = "Stigao je novi posao.\n\nOpis:\n{$desc}\n".
          ($city ? "Lokacija: {$city}\n" : '').
          ($dist!==null ? "Udaljenost: {$dist} km\n" : '').
          "Otvori: {$cta}\n";

  $opts = [];
  if ($bccAdmin) $opts['bcc'] = $bccAdmin;
  return mailer_send($to, $title, $html, $text, $opts);
}

// 4) Dobre vijesti klijentu: majstor je zainteresiran
//    $data: ['provider_name'=>..., 'provider_phone'=>..., 'job_desc'=>..., 'cta_url'=>string]
function send_good_news_to_client(string $to, array $data, ?string $bccAdmin = null): bool {
  $title = 'Imamo dobre vijesti — majstor je zainteresiran';
  $pname = trim((string)($data['provider_name'] ?? 'Majstor'));
  $pphone= trim((string)($data['provider_phone'] ?? ''));
  $desc  = trim((string)($data['job_desc'] ?? ''));
  $cta   = $data['cta_url'] ?? mailer_base_url();

  $phoneHtml = $pphone ? '<p>Telefon: <strong>'.htmlspecialchars($pphone, ENT_QUOTES, 'UTF-8').'</strong></p>' : '';
  $html = mailer_wrap_html($title, '
    <p><strong>Imamo dobre vijesti!</strong></p>
    <p>Pronašli smo vam majstora koji je spreman odraditi uslugu.</p>
    <p><strong>'.htmlspecialchars($pname, ENT_QUOTES, 'UTF-8').'</strong></p>'.
    $phoneHtml.
    '<p class="muted">Opis vašeg zahtjeva:</p>
    <pre>'.htmlspecialchars(mb_substr($desc,0,400), ENT_QUOTES, 'UTF-8').'</pre>
    <p><a href="'.htmlspecialchars($cta, ENT_QUOTES, 'UTF-8').'" class="btn">Otvori detalje</a></p>
  ');

  $text = "Imamo dobre vijesti!\n".
          "Pronašli smo vam majstora.\n\n".
          "{$pname}\n".
          ($pphone ? "Telefon: {$pphone}\n" : '').
          "Opis zahtjeva:\n{$desc}\n\n".
          "Detalji: {$cta}\n";

  $opts = [];
  if ($bccAdmin) $opts['bcc'] = $bccAdmin;
  return mailer_send($to, $title, $html, $text, $opts);
}

/* =========================
   PRIMJERI POZIVA (komentirano)
   =========================
   // 1) Verifikacija:
   // send_verification_email('user@example.com', mailer_base_url().'/api/auth.php?route=verify&token=XYZ');

   // 2) Kontakt majstoru:
   // send_contact_email('majstor@example.com','Novi upit — Dogovoreno',"Ime: Ana\nTelefon: 061 111 222\n...");

   // 3) Novi posao majstoru:
   // send_new_job_to_provider(
   //   'majstor@example.com',
   //   ['desc'=>'Postavljanje 15m² keramike u kupatilu','distance_km'=>3.2,'city'=>'Mostar','cta_url'=>mailer_base_url().'/moj-profil'],
   //   mailer_admin_email()
   // );

   // 4) Dobre vijesti klijentu:
   // send_good_news_to_client(
   //   'klijent@example.com',
   //   ['provider_name'=>'Marko Marković','provider_phone'=>'+387 61 123 456','job_desc'=>'Postavljanje parketa 25m²','cta_url'=>mailer_base_url().'/moji-zahtjevi'],
   //   mailer_admin_email()
   // );
*/
