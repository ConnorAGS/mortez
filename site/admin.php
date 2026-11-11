<?php
// Admin switches for the Mortez Chateau site.
// GET  /admin.php            -> the current settings as JSON (the site reads these on load)
// POST /admin.php            -> save settings. Body: {"password": "...", "settings": {...}}
// Set ADMIN_PASSWORD below (or as an environment variable) before going live. Settings are kept in settings.json next to this file.
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$ADMIN_PASSWORD = getenv('MORTEZ_ADMIN_PASSWORD') ?: 'change-me-before-launch';
$FILE = __DIR__ . '/settings.json';

// The switches the site understands, with their types and allowed values
$SCHEMA = [
  'hauntings' => 'bool', 'hauntMinutes' => [2, 3, 4, 6, 8, 12], 'relics' => 'bool', 'minimap' => 'bool',
  'piano' => 'bool', 'lightning' => 'bool', 'libraryScares' => 'bool', 'chapelCrowd' => 'bool',
  'lyricBox' => 'bool', 'lyrics3D' => 'bool', 'bridgeStrobe' => 'bool', 'lyricTimer' => 'bool', 'playTogether' => 'bool', 'lyricTimes' => 'times', 'charSelect' => 'bool', 'hauntedHall' => 'bool', 'announce' => 'text'
];

$read = function () use ($FILE) { if (!is_file($FILE)) return new stdClass(); $j = json_decode(file_get_contents($FILE), true); return is_array($j) ? $j : new stdClass(); };

if ($_SERVER['REQUEST_METHOD'] === 'GET') { echo json_encode($read()); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false]); exit; }

// Slow down guessing: one attempt per second per visitor
session_start();
if (!empty($_SESSION['admin_try']) && microtime(true) - $_SESSION['admin_try'] < 1) { http_response_code(429); echo json_encode(['ok' => false, 'error' => 'slow down']); exit; }
$_SESSION['admin_try'] = microtime(true);

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in) || !hash_equals($ADMIN_PASSWORD, (string)($in['password'] ?? ''))) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'wrong password']); exit; }
if ($ADMIN_PASSWORD === 'change-me-before-launch') { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'set a real admin password first']); exit; }

// keep what's already saved, so the lyric timer and the switches panel don't wipe each other
$out = (array)$read();
foreach ($SCHEMA as $k => $type) {
  if (!array_key_exists($k, $in['settings'] ?? [])) continue;
  $v = $in['settings'][$k];
  if ($type === 'bool') $out[$k] = (bool)$v;
  elseif ($type === 'text') $out[$k] = mb_substr(trim(strip_tags((string)$v)), 0, 240);
  elseif ($type === 'times' && is_array($v) && count($v) <= 80) { $ok = true; foreach ($v as $x) if (!is_numeric($x) || $x < 0 || $x > 900) $ok = false; if ($ok) $out[$k] = array_map(function ($x) { return round((float)$x, 2); }, array_values($v)); }
  elseif (is_array($type) && in_array((int)$v, $type, true)) $out[$k] = (int)$v;
}
if (file_put_contents($FILE, json_encode($out, JSON_PRETTY_PRINT), LOCK_EX) === false) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'could not save']); exit; }
echo json_encode(['ok' => true, 'settings' => $out]);
