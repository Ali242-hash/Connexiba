<?php
/**
 * api/contact.php — Connexiba website contact form / lead-qualification
 * endpoint.
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
 * File Manager and never committed to git (the root .gitignore already
 * reserves /api/config.php for exactly this) — not implemented here
 * because it isn't needed for the simpler mail() approach this file
 * actually uses.
 *
 * DUPLICATE-SUBMISSION PROTECTION: if the same email address (normalized
 * and hashed — see isDuplicateSubmission()/recordSuccessfulSubmission()
 * below) successfully sent a message within the last DEDUPE_WINDOW_SECONDS,
 * a repeat submission is answered with {"ok":false,"error":"already_received"}
 * and no second email is sent — the visitor sees a reassuring "we've got
 * it" message, not an error. This works identically no matter which of
 * the six language pages the two submissions came from, because it's
 * enforced here, server-side, keyed only by the email address itself.
 * A submission is recorded as received ONLY after mail() actually
 * succeeds, and the record expires after the window — this is loop
 * protection, not a permanent block, so the same person/company can
 * always reach out again later.
 *
 * VISITOR CONFIRMATION EMAIL: after the internal notification above is
 * sent successfully (never before, never for a failed send, and never
 * for an already_received duplicate — see section 9), a short courtesy
 * email goes to the visitor's own submitted address, From/Reply-To
 * info@connexiba.com, in the same language they submitted the form in
 * (see the $lang whitelist in section 6 — read from a value the
 * frontend already had, never guessed from the email/IP/browser). A
 * failure sending this one is logged but never flips the API response
 * to an error — the visitor's actual enquiry already succeeded.
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
//    honeypot field and the email-based duplicate check below). Fails
//    OPEN — if the temp directory isn't writable for some reason on a
//    given host, the form still works, it just skips this extra check
//    rather than breaking submissions.
// ---------------------------------------------------------------------
function connexibaStorageDir($subfolder) {
    $dir = rtrim(sys_get_temp_dir(), '/\\') . '/' . $subfolder;
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return null;
        }
    }
    return $dir;
}

function isRateLimited($minIntervalSeconds = 20) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '') {
        return false;
    }
    $dir = connexibaStorageDir('connexiba_contact_throttle');
    if ($dir === null) {
        return false; // can't create it — don't block submissions over this
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
// 3. Duplicate-submission protection — server-side, language-independent.
//    Keyed by a SHA-256 hash of the normalized (trimmed, lowercased)
//    email address only: no raw email, name, or any other personal data
//    is stored, just a hash and a timestamp, in the same temp-directory
//    mechanism as the IP throttle above (outside the web document root,
//    so it's never directly downloadable — see the file-header comment
//    on why a flat file here, not SQLite, was chosen). One file per
//    submission is a good tradeoff at this volume; each file's own
//    mtime-independent stored timestamp is what expiry is judged
//    against, so a submission is never permanently blocked — only for
//    DEDUPE_WINDOW_SECONDS after its last successful send.
// ---------------------------------------------------------------------
// Production: enabled, keyed per form_type (see normalizedEmailHash)
// so Contact and Sponsorship submissions from the same email never
// block each other. Set to false to disable duplicate protection.
const DEDUPE_ENABLED = true;
const DEDUPE_WINDOW_SECONDS = 3600; // 1 hour

// Keyed on normalized email + form_type so a Contact submission and a
// Sponsorship submission from the same email don't block each other —
// each form still dedupes independently within the same window.
function normalizedEmailHash($email, $formType) {
    return hash('sha256', strtolower(trim($email)) . '|' . $formType);
}

function isDuplicateSubmission($email, $formType) {
    $dir = connexibaStorageDir('connexiba_contact_dedupe');
    if ($dir === null) {
        return false; // can't check — fail open, same policy as the throttle above
    }
    $file = $dir . '/' . normalizedEmailHash($email, $formType) . '.touch';
    if (!is_file($file)) {
        return false;
    }
    $last = (int) @file_get_contents($file);
    return $last > 0 && (time() - $last) < DEDUPE_WINDOW_SECONDS;
}

/** Called ONLY after mail() has actually returned success — a failed
 *  send must never be recorded, so the visitor can simply try again. */
function recordSuccessfulSubmission($email, $formType) {
    $dir = connexibaStorageDir('connexiba_contact_dedupe');
    if ($dir === null) {
        return; // best-effort — a missed record just means no dedupe next time
    }
    @file_put_contents($dir . '/' . normalizedEmailHash($email, $formType) . '.touch', (string) time());
}

// ---------------------------------------------------------------------
// 4. Input helpers — every submitted value passes through one of these
//    before it's used anywhere (email headers or the message body).
// ---------------------------------------------------------------------

/**
 * For any value that may end up on its own header-ish line (name,
 * company, subject material, the email address itself, the new phone/
 * dropdown fields): strips control characters entirely, collapses any
 * residual CR/LF to a space so a value can never inject an extra header
 * line into the outgoing email, trims, and caps length.
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

/**
 * Dropdown fields (employees/interest/goal/source) submit a fixed,
 * never-translated English slug (see the <option value="..."> in
 * template.html) regardless of which language page the visitor used —
 * so the email the team receives always reads in clear English
 * regardless of submission language. $map translates known slugs to
 * their proper label; anything else (unexpected/tampered input) falls
 * back to a readable, hyphen-to-space humanization rather than being
 * silently dropped, since this endpoint never blindly trusts dropdown
 * values just because they came from a <select>.
 */
function humanizeOption($map, $slug) {
    if ($slug === '') {
        return '';
    }
    if (isset($map[$slug])) {
        return $map[$slug];
    }
    return ucwords(str_replace(['-', '_'], ' ', $slug));
}

// ---------------------------------------------------------------------
// 5. Honeypot — a field named hp_website that real visitors never see
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
// 6. Collect + validate the real fields. Name, email, company and
//    interest are required (client-side too, via the form's own
//    required attributes — this is the authoritative, server-side
//    copy of that same rule). Everything else is optional and handled
//    safely whether present or not.
// ---------------------------------------------------------------------
$name         = cleanLine($_POST['name'] ?? '', 200);
$email        = cleanLine($_POST['email'] ?? '', 254);
$company      = cleanLine($_POST['company'] ?? '', 200);
$phone        = cleanLine($_POST['phone'] ?? '', 40);
$employees    = cleanLine($_POST['employees'] ?? '', 40);
$interest     = cleanLine($_POST['interest'] ?? '', 60);
$interestOther = cleanLine($_POST['interest_other'] ?? '', 300);
$goal         = cleanLine($_POST['goal'] ?? '', 60);
$goalOther    = cleanLine($_POST['goal_other'] ?? '', 300);
$source       = cleanLine($_POST['source'] ?? '', 60);
$sourceOther  = cleanLine($_POST['source_other'] ?? '', 300);
$message      = cleanMultiline($_POST['message'] ?? '', 5000);

// Sponsorship Opportunities form fields (optional — absent/blank for
// every normal Contact form submission, which never sends form_type).
// form_type is the only thing that switches the subject/body below to
// the sponsorship-specific version; everything else in this file
// (required-field check, honeypot, rate limit, dedupe, mail() call,
// Reply-To, visitor confirmation email) is identical for both forms.
$sponsorshipType = cleanLine($_POST['sponsorship'] ?? '', 100);
$eventLocation   = cleanLine($_POST['event_location'] ?? '', 200);
$formType        = cleanLine($_POST['form_type'] ?? '', 20);
$isSponsorship   = ($formType === 'sponsorship');

// The visitor's page language, for the confirmation email only (see
// section 9) — never used for anything internal-facing. Read straight
// from a value the frontend already had on hand (document.documentElement.lang,
// itself set per language by build-i18n.js's own <html lang> swap — see
// template.html), not guessed from the email address, browser
// Accept-Language, or IP/country. Whitelisted against the six real
// site languages; anything missing or unrecognized safely falls back
// to English rather than failing the submission over it.
$SUPPORTED_LANGS = ['en', 'de', 'fr', 'es', 'hu', 'it'];
$lang = cleanLine($_POST['lang'] ?? '', 5);
if (!in_array($lang, $SUPPORTED_LANGS, true)) {
    $lang = 'en';
}

if ($name === '' || $email === '' || $company === '' || $interest === '') {
    respond(400, ['ok' => false, 'error' => 'missing_required_fields']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(400, ['ok' => false, 'error' => 'invalid_email']);
}

// ---------------------------------------------------------------------
// 7. Duplicate check — after validation (so we know we have a real
//    email to key on) but before sending anything.
// ---------------------------------------------------------------------
if (DEDUPE_ENABLED && isDuplicateSubmission($email, $formType)) {
    respond(200, ['ok' => false, 'error' => 'already_received']);
}

// ---------------------------------------------------------------------
// 8. Build and send the email.
// ---------------------------------------------------------------------
$employeesLabels = [
    '1-10' => '1–10',
    '11-50' => '11–50',
    '51-200' => '51–200',
    '201-500' => '201–500',
    '501-1000' => '501–1,000',
    '1001-5000' => '1,001–5,000',
    '5000-plus' => '5,000+',
    'prefer-not-to-say' => 'Prefer not to say',
];
$interestLabels = [
    'lead-generation' => 'Lead Generation',
    'b2b-sales' => 'B2B Sales',
    'b2c-sales' => 'B2C Sales',
    'appointment-setting' => 'Appointment Setting',
    'executive-connections' => 'Executive / Decision-Maker Connections',
    'event-attendee-acquisition' => 'Event Attendee / Delegate Acquisition',
    'event-sponsorship-partnerships' => 'Event Sponsorship & Commercial Partnerships',
    'business-intelligence-research' => 'Business Intelligence / Market Research',
    'sales-outsourcing' => 'Sales Outsourcing',
    'partnership-development' => 'Partnership Development',
    'other' => 'Other',
];
$goalLabels = [
    'generate-qualified-leads' => 'Generate more qualified leads',
    'book-more-meetings' => 'Book more meetings',
    'reach-decision-makers' => 'Reach decision makers',
    'enter-new-market' => 'Enter a new market',
    'increase-sales' => 'Increase sales',
    'build-strategic-partnerships' => 'Build strategic partnerships',
    'promote-event' => 'Promote an event',
    'find-sponsors-partners' => 'Find sponsors / partners',
    'other' => 'Other',
];
$sourceLabels = [
    'google' => 'Google',
    'linkedin' => 'LinkedIn',
    'event' => 'Conference / Event',
    'referral' => 'Referral',
    'email' => 'Email Newsletter',
    'social-media' => 'Social Media',
    'other' => 'Other',
];

$interestLine = humanizeOption($interestLabels, $interest);
if ($interest === 'other' && $interestOther !== '') {
    $interestLine .= ' (' . $interestOther . ')';
}
$goalLine = humanizeOption($goalLabels, $goal);
if ($goal === 'other' && $goalOther !== '') {
    $goalLine .= ' (' . $goalOther . ')';
}
$sourceLine = humanizeOption($sourceLabels, $source);
if ($source === 'other' && $sourceOther !== '') {
    $sourceLine .= ' (' . $sourceOther . ')';
}
$employeesLine = humanizeOption($employeesLabels, $employees);

$to = 'info@connexiba.com';
$subjectSource = $isSponsorship
    ? 'New Connexiba Sponsorship Opportunity Request — ' . ($company !== '' ? $company : $name)
    : 'New Connexiba Website Enquiry — ' . ($company !== '' ? $company : $name);
$subject = encodeHeaderValue($subjectSource);

// Only meaningful, filled-in fields are included — an optional field
// left blank by the visitor doesn't show up as an empty line.
$bodyLines = [$isSponsorship ? 'Connexiba Sponsorship Opportunity Request' : 'Connexiba Website Enquiry', ''];
$bodyLines[] = 'Name: ' . $name;
$bodyLines[] = 'Email: ' . $email;
$bodyLines[] = 'Company: ' . $company;
if ($isSponsorship) {
    if ($sponsorshipType !== '') {
        $bodyLines[] = 'Sponsorship Package: ' . $sponsorshipType;
    }
    if ($eventLocation !== '') {
        $bodyLines[] = 'Event / Location: ' . $eventLocation;
    }
}
if ($phone !== '') {
    $bodyLines[] = 'Phone: ' . $phone;
}
if ($employeesLine !== '') {
    $bodyLines[] = 'Employees: ' . $employeesLine;
}
$bodyLines[] = 'Interested In: ' . $interestLine;
if ($goalLine !== '') {
    $bodyLines[] = 'Business Goal: ' . $goalLine;
}
if ($sourceLine !== '') {
    $bodyLines[] = 'How They Heard About Connexiba: ' . $sourceLine;
}
if ($message !== '') {
    $bodyLines[] = '';
    $bodyLines[] = 'Additional Information:';
    $bodyLines[] = $message;
}
$bodyLines[] = '';
$bodyLines[] = '---';
$bodyLines[] = 'Submitted from: ' . cleanLine($_SERVER['HTTP_REFERER'] ?? 'connexiba.com', 300);
$bodyLines[] = 'IP: ' . cleanLine($_SERVER['REMOTE_ADDR'] ?? 'unknown', 64);
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

// Only record as "received" now that the email has actually gone out —
// a failed send above returns before this line, so it's never recorded
// and the visitor is free to try again immediately.
recordSuccessfulSubmission($email, $formType);

// ---------------------------------------------------------------------
// 9. Visitor confirmation email — sent only now that the internal
//    notification above has actually succeeded (never for a failed
//    send, and never reached at all for an already_received duplicate,
//    since that responds and exits back in section 7). Best-effort: a
//    problem here is logged but never changes the response below — the
//    visitor's enquiry already genuinely succeeded (the team has it),
//    so a hiccup in this secondary courtesy email shouldn't make the
//    submission look like it failed.
// ---------------------------------------------------------------------
function connexibaConfirmationTemplates() {
    // {{name}}/{{company}} are replaced with the sanitized, already
    // header-safe values collected above. One professional, natural
    // translation per supported language — not machine-translated
    // filler — matching the formality already used elsewhere on each
    // language's pages (Sie/vous/usted/formal Hungarian, informal tu
    // for Italian, matching that page's own existing precedent).
    return [
        'en' => [
            'subject' => "We've received your enquiry — Connexiba",
            'body' => "Hello {{name}},\n\nThank you for contacting Connexiba.\n\nWe've received your enquiry from {{company}} and our team will review your request and get back to you shortly.\n\nWe appreciate your interest in Connexiba and look forward to speaking with you.\n\nBest regards,\nThe Connexiba Team\n\ninfo@connexiba.com",
        ],
        'de' => [
            'subject' => 'Wir haben Ihre Anfrage erhalten — Connexiba',
            'body' => "Hallo {{name}},\n\nvielen Dank, dass Sie Connexiba kontaktiert haben.\n\nWir haben Ihre Anfrage von {{company}} erhalten. Unser Team wird Ihre Anfrage prüfen und sich in Kürze bei Ihnen melden.\n\nWir freuen uns über Ihr Interesse an Connexiba und auf das Gespräch mit Ihnen.\n\nMit freundlichen Grüßen\nIhr Connexiba-Team\n\ninfo@connexiba.com",
        ],
        'fr' => [
            'subject' => 'Nous avons bien reçu votre demande — Connexiba',
            'body' => "Bonjour {{name}},\n\nMerci d'avoir contacté Connexiba.\n\nNous avons bien reçu votre demande de la part de {{company}}. Notre équipe va l'examiner et reviendra vers vous très prochainement.\n\nNous vous remercions de l'intérêt que vous portez à Connexiba et nous réjouissons de pouvoir échanger avec vous.\n\nCordialement,\nL'équipe Connexiba\n\ninfo@connexiba.com",
        ],
        'es' => [
            'subject' => 'Hemos recibido su consulta — Connexiba',
            'body' => "Hola {{name}},\n\nGracias por contactar con Connexiba.\n\nHemos recibido su consulta de {{company}} y nuestro equipo revisará su solicitud y se pondrá en contacto con usted en breve.\n\nAgradecemos su interés en Connexiba y esperamos poder hablar con usted.\n\nSaludos cordiales,\nEl equipo de Connexiba\n\ninfo@connexiba.com",
        ],
        'hu' => [
            'subject' => 'Megkaptuk megkeresését — Connexiba',
            'body' => "Kedves {{name}}!\n\nKöszönjük, hogy felvette a kapcsolatot a Connexibával.\n\nMegkaptuk a(z) {{company}} cégtől érkezett megkeresését, csapatunk hamarosan átnézi kérését, és jelentkezik Önnél.\n\nKöszönjük érdeklődését a Connexiba iránt, és várjuk a személyes egyeztetést.\n\nÜdvözlettel,\nA Connexiba csapata\n\ninfo@connexiba.com",
        ],
        'it' => [
            'subject' => 'Abbiamo ricevuto la tua richiesta — Connexiba',
            'body' => "Ciao {{name}},\n\ngrazie per aver contattato Connexiba.\n\nAbbiamo ricevuto la tua richiesta da parte di {{company}}: il nostro team la esaminerà e ti risponderà a breve.\n\nGrazie per l'interesse dimostrato verso Connexiba: non vediamo l'ora di parlare con te.\n\nCordiali saluti,\nIl team Connexiba\n\ninfo@connexiba.com",
        ],
    ];
}

function sendConfirmationEmail($toEmail, $toName, $companyName, $lang) {
    $templates = connexibaConfirmationTemplates();
    $t = $templates[$lang] ?? $templates['en'];

    $subject = str_replace(['{{name}}', '{{company}}'], [$toName, $companyName], $t['subject']);
    $body = str_replace(['{{name}}', '{{company}}'], [$toName, $companyName], $t['body']);

    $fromDisplayName = encodeHeaderValue('Connexiba');
    $headers = [];
    $headers[] = 'From: ' . $fromDisplayName . ' <info@connexiba.com>';
    $headers[] = 'Reply-To: info@connexiba.com';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';
    $headers[] = 'X-Mailer: Connexiba-Contact-Form';

    $ok = @mail($toEmail, encodeHeaderValue($subject), $body, implode("\r\n", $headers));
    if (!$ok) {
        error_log('Connexiba contact form: visitor confirmation mail() returned false for ' . $toEmail);
    }
}

sendConfirmationEmail($email, $name, $company, $lang);

respond(200, ['ok' => true]);
