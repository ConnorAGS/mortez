<?php
// Join the Coven: mailing-list sign-ups from the home page.
// Each address is appended to a private CSV outside the web root and the band gets a short email.
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$NOTIFY = 'booking@mortezmusic.com';               // placeholder: the band's real inbox
$FROM   = 'noreply@mortezmusic.com';
$LIST   = dirname(__DIR__) . '/coven-subscribers.csv'; // one level above public_html, so it can't be downloaded

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false]); exit; }
if (!empty($_POST['website'])) { echo json_encode(['ok' => true]); exit; } // honeypot

$email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
if (!$email || strlen($email) > 200) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'invalid']); exit; }

session_start();
if (!empty($_SESSION['coven']) && time() - $_SESSION['coven'] < 20) { http_response_code(429); echo json_encode(['ok' => false]); exit; }

$existing = is_file($LIST) ? file($LIST, FILE_IGNORE_NEW_LINES) : [];
foreach ($existing as $line) { if (strcasecmp(explode(',', $line)[0], $email) === 0) { echo json_encode(['ok' => true]); exit; } }

$ok = file_put_contents($LIST, $email . ',' . gmdate('c') . "\n", FILE_APPEND | LOCK_EX) !== false;
if ($ok) {
  $_SESSION['coven'] = time();
  @mail($NOTIFY, 'New coven member', "$email joined the mailing list on the Mortez site.", "From: Mortez <$FROM>\r\nContent-Type: text/plain; charset=UTF-8\r\n", "-f$FROM");
}
echo json_encode(['ok' => $ok]);
