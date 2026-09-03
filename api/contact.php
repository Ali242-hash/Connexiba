<?php
/**
 * api/contact.php — Connexiba website contact form endpoint.
 *
 * Receives the POST from #contactForm on every /xx/ language page (see
 * template.html's script block — it fetch()es this same path,
 * "/api/contact.php", root-absolute so it resolves identically from any
 * language subfolder without a build-time rewrite), validates and
 * sanitizes the submission, and emails it to info@connexiba.com with the
 * visitor's own address set as Reply-To — so replying to that email goes
 * straight back to the prospect.
 *
 * This file knows only about info@connexiba.com — it never references,
 * sends to, or exposes the private internal inbox that mailbox already
 * forwards to at the Namecheap/cPanel hosting level. That forwarding is
 * configured entirely outside this codebase and stays that way; this
 * repository should never contain that internal address, in this file
 * or anywhere else.
 *
 * No credentials of any kind live in this file. It sends mail via PHP's
 * built-in mail() through the server's local MTA — the standard,
 * zero-configuration way to send outbound mail from Namecheap shared/
 * cPanel hosting — so there is no SMTP password, API key, or other
 * secret to store, leak, or rotate. If mail() deliverability ever proves
 * insufficient (e.g. landing in spam), the documented next step is
 * authenticated SMTP via the existing info@connexiba.com mailbox
 * (connexiba.com:465, SSL) through a small library such as PHPMailer,
 * with its password kept OUTSIDE this repository entirely — e.g. a
 * config.php in this same api/ folder, uploaded by hand via the cPanel
 * File Manager and never committed to git (see api/.gitignore) — not
 * implemented here because it isn't needed for the simpler mail()
 * approach this file actually uses.
 *
 * DEPLOYMENT: this is the one PHP file the otherwise fully static site
 * depends on. Editing it locally does nothing for the live site — it
 * must be uploaded to the same host/folder as the rest of the site
 * (public_html/api/contact.php, alongside public_html/en/, /assets/,
 * etc.) for the form to actually send mail. It needs no separate cPanel
 * configuration beyond that upload: Namecheap shared hosting has PHP and
 * mail() available out of the box.
 */

// ---------------------------------------------------------------------
// 0. Baseline hardening: never let a PHP notice/warning leak into the
//    JSON response body the visitor's browser parses. Real errors still
//    go to the server's own PHP error log (Namecheap/cPanel exposes
//    this under "Errors" / the domain's error_log), never to the client.
// ---------------------------------------------------------------------
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function respond($httpStatus, $payload) {
    http_response_code($httpStatus);
    echo json_encode($payload);
    exit;
}

// ---------------------------------------------------------------------
// 1. Method guard — POST only.
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

// ---------------------------------------------------------------------
// 2. Lightweight per-IP throttle (defense in depth alongside the
//    honeypot field below). Fails OPEN — if the temp directory isn't
//    writable for some reason on a given host, the form still works,
//    it just skips this extra check rather than breaking submissions.
// ---------------------------------------------------------------------
function isRateLimited($minIntervalSeconds = 20) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '') {
        return false;
    }
    $dir = rtrim(sys_get_temp_dir(), '/\\') . '/connexiba_contact_throttle';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false; // can't create it — don't block submissions over this
        }
    }
    $file = $dir . '/' . md5($ip) . '.touch';
    $now = time();
    if (is_file($file)) {
        $last = (int) @file_get_contents($file);
        if ($last > 0 && ($now - $last) < $minIntervalSeconds) {
            return true;
        }
    }
    @file_put_contents($file, (string) $now);
    return false;
}

if (isRateLimited()) {
    respond(429, ['ok' => false, 'error' => 'rate_limited']);
}

// ---------------------------------------------------------------------
// 3. Input helpers — every submitted value passes through one of these
//    before it's used anywhere (email headers or the message body).
// ---------------------------------------------------------------------

/**
 * For any value that may end up on its own header-ish line (name,
 * company, subject material, the email address itself): strips control
 * characters entirely, collapses any residual CR/LF to a space so a
 * value can never inject an extra header line into the outgoing email,
 * trims, and caps length.
 */
function cleanLine($raw, $maxLen) {
    $v = is_string($raw) ? $raw : '';
    // Strip C0 controls and DEL, but not tab, in one pass.
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
    if ($v === null) {
        $v = ''; // preg_replace returns null on a regex engine error (e.g. invalid UTF-8) — fail safe to empty
    }
    $v = str_replace(["\r", "\n"], ' ', $v);
    $v = trim($v);
    if (function_exists('mb_substr')) {
        $v = mb_substr($v, 0, $maxLen);
    } else {
        $v = substr($v, 0, $maxLen);
    }
    return $v;
}

/**
 * For the free-text message field only: keeps real newlines (it's going
 * into the email BODY, not a header) but still strips other control
 * characters and caps length.
 */
function cleanMultiline($raw, $maxLen) {
    $v = is_string($raw) ? $raw : '';
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
    if ($v === null) {
        $v = '';
    }
    $v = str_replace("\r\n", "\n", $v);
    $v = trim($v);
    if (function_exists('mb_substr')) {
        $v = mb_substr($v, 0, $maxLen);
    } else {
        $v = substr($v, 0, $maxLen);
    }
    return $v;
}

/** MIME-encodes a header value (subject, or a display name) only if it
 *  actually contains non-ASCII text, so a plain-ASCII value is left
 *  untouched and readable in every mail client either way. */
function encodeHeaderValue($value) {
    if ($value === '' || preg_match('/^[\x20-\x7E]*$/', $value)) {
        return $value;
    }
    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
    }
    // Extremely unlikely on cPanel PHP (mbstring is effectively always
    // present), but fail safe to a readable ASCII-only fallback rather
    // than sending a broken header.
    return preg_replace('/[^\x20-\x7E]/', '?', $value);
}

// ---------------------------------------------------------------------
// 4. Honeypot — a field named hp_website that real visitors never see
//    or fill in (see .hp-field in template.html). A non-empty value
//    means a bot filled every field it could find; accept the request
//    but silently drop it, exactly as if it had sent successfully, so
//    the bot has no signal that it was caught.
// ---------------------------------------------------------------------
$honeypot = cleanLine($_POST['hp_website'] ?? '', 200);
if ($honeypot !== '') {
    respond(200, ['ok' => true]);
}

// ---------------------------------------------------------------------
// 5. Collect + validate the real fields.
// ---------------------------------------------------------------------
$name      = cleanLine($_POST['name'] ?? '', 200);
$company   = cleanLine($_POST['company'] ?? '', 200);
$title     = cleanLine($_POST['title'] ?? '', 200);
$email     = cleanLine($_POST['email'] ?? '', 254);
$solution  = cleanLine($_POST['solution'] ?? '', 400);
$market    = cleanLine($_POST['market'] ?? '', 400);
$objective = cleanLine($_POST['objective'] ?? '', 200);
$message   = cleanMultiline($_POST['message'] ?? '', 5000);

if ($name === '' || $email === '') {
    respond(400, ['ok' => false, 'error' => 'missing_required_fields']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(400, ['ok' => false, 'error' => 'invalid_email']);
}

// ---------------------------------------------------------------------
// 6. Build and send the email.
// ---------------------------------------------------------------------
$to = 'info@connexiba.com';

$subjectSource = 'New Connexiba Website Enquiry — ' . ($company !== '' ? $company : $name);
$subject = encodeHeaderValue($subjectSource);

$bodyLines = [
    'Name: ' . $name,
    'Company: ' . ($company !== '' ? $company : '—'),
    'Job title: ' . ($title !== '' ? $title : '—'),
    'Email: ' . $email,
    'Solution: ' . ($solution !== '' ? $solution : '—'),
    'Target market/audience: ' . ($market !== '' ? $market : '—'),
    'Primary objective: ' . ($objective !== '' ? $objective : '—'),
    '',
    'Message:',
    ($message !== '' ? $message : '—'),
    '',
    '---',
    'Submitted from: ' . cleanLine($_SERVER['HTTP_REFERER'] ?? 'connexiba.com', 300),
    'IP: ' . cleanLine($_SERVER['REMOTE_ADDR'] ?? 'unknown', 64),
];
$body = implode("\n", $bodyLines);

$fromDisplayName = encodeHeaderValue('Connexiba Website');
$replyToDisplayName = encodeHeaderValue($name);

$headers = [];
$headers[] = 'From: ' . $fromDisplayName . ' <info@connexiba.com>';
$headers[] = 'Reply-To: ' . $replyToDisplayName . ' <' . $email . '>';
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';
$headers[] = 'Content-Transfer-Encoding: 8bit';
$headers[] = 'X-Mailer: Connexiba-Contact-Form';

$sent = @mail($to, $subject, $body, implode("\r\n", $headers));

if (!$sent) {
    error_log('Connexiba contact form: mail() returned false for submission from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    respond(502, ['ok' => false, 'error' => 'send_failed']);
}

respond(200, ['ok' => true]);
