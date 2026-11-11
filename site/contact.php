<?php
// Contact form for the Mortez Chateau site. Sends each message to the band by email.
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$TO = 'booking@mortezmusic.com';      // placeholder: set the band's real inbox
$FROM = 'noreply@mortezmusic.com';   // use an address on this domain so mail isn't flagged as spam

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false]); exit; }

// Honeypot: real people never fill the hidden "website" field
if (!empty($_POST['website'])) { echo json_encode(['ok' => true]); exit; }

$clean = function ($v, $max) { $v = trim(strip_tags((string)$v)); return mb_substr(str_replace(["\r", "\n"], ' ', $v), 0, $max); };
$name    = $clean($_POST['name'] ?? '', 120);
$email   = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$topics  = ['booking' => 'Booking', 'press' => 'Press', 'merch' => 'Merch', 'other' => 'Something else'];
$topic   = $topics[$_POST['topic'] ?? 'other'] ?? 'Something else';
$message = trim(strip_tags((string)($_POST['message'] ?? '')));

if ($name === '' || !$email || mb_strlen($message) < 2 || mb_strlen($message) > 5000) {
  http_response_code(400); echo json_encode(['ok' => false, 'error' => 'invalid']); exit;
}

// Light rate limit: one message per visitor every 30 seconds
session_start();
if (!empty($_SESSION['last_sent']) && time() - $_SESSION['last_sent'] < 30) { http_response_code(429); echo json_encode(['ok' => false, 'error' => 'slow down']); exit; }

$subject = "New message from your site: $topic";
$body = "Name: $name\nEmail: $email\nAbout: $topic\n\n$message\n\n-- Sent from the contact form on the Mortez Chateau site";
$headers = "From: Mortez Chateau <$FROM>\r\nReply-To: $name <$email>\r\nContent-Type: text/plain; charset=UTF-8\r\n";

$ok = mail($TO, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, "-f$FROM");
if ($ok) $_SESSION['last_sent'] = time();
echo json_encode(['ok' => (bool)$ok]);
