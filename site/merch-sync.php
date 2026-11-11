<?php
// Refreshes the shop from the band's Printful store: rewrites data/merch.js and saves product photos to media/merch/.
// Runs from cron on the hosting only (e.g. daily:  php /home/USER/public_html/merch-sync.php ). Never from the web.
// The Printful token lives OUTSIDE the public folder, in ../mortez-private/printful-token (one line, nothing else).
// Same output as scripts/printful_sync.py in the repo.
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

$root = __DIR__;
$tokenFile = dirname($root) . '/mortez-private/printful-token';
$token = is_file($tokenFile) ? trim(file_get_contents($tokenFile)) : getenv('PRINTFUL_TOKEN');
if (!$token) { fwrite(STDERR, "No Printful token at $tokenFile\n"); exit(1); }
$featuredHide = []; // Printful sync product ids to leave off the site

function pf($path, $token) {
  $ch = curl_init('https://api.printful.com' . $path);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ["Authorization: Bearer $token", 'User-Agent: mortez-sync']]);
  $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  if ($code !== 200) { fwrite(STDERR, "Printful $path answered $code\n"); exit(1); }
  return json_decode($body, true)['result'];
}
function fetch_image($url, $stem, $dir) {
  if (!$url) return null;
  foreach (glob("$dir/$stem.*") ?: [] as $f) return 'media/merch/' . basename($f);
  $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => true]);
  $data = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  if ($code !== 200 || !$data) return $url;
  if (!is_dir($dir)) mkdir($dir, 0755, true);
  $dest = "$dir/$stem.jpg";
  if (function_exists('imagecreatefromstring') && ($im = @imagecreatefromstring($data))) {
    $w = imagesx($im); $h = imagesy($im); $s = min(1, 900 / max($w, $h)); $nw = (int)round($w * $s); $nh = (int)round($h * $s);
    $out = imagecreatetruecolor($nw, $nh); imagefill($out, 0, 0, imagecolorallocate($out, 22, 20, 26));
    imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h); imagejpeg($out, $dest, 84);
  } else { $dest = "$dir/$stem" . (preg_match('/\.png($|\?)/i', $url) ? '.png' : '.jpg'); file_put_contents($dest, $data); }
  return 'media/merch/' . basename($dest);
}
$SIZES = ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL', '6XL', '7XL'];
function kind_of($n) {
  $n = strtolower($n);
  foreach (['candle' => ['candle'], 'case' => ['iphone', 'case'], 'jacket' => ['jacket'], 'hoodie' => ['hoodie'], 'sweater' => ['sweatshirt', 'crewneck', 'sweater'], 'tank' => ['tank'], 'hat' => ['beanie', 'cap'], 'skirt' => ['skirt'], 'joggers' => ['jogger'], 'tee' => ['t-shirt', 'tee', 'short sleeve', 'long sleeve']] as $k => $ws)
    foreach ($ws as $w) if (strpos($n, $w) !== false) return $k;
  return 'other';
}

$products = [];
foreach (pf('/sync/products?limit=100', $token) as $item) {
  if ($item['is_ignored'] || in_array($item['id'], $featuredHide)) continue;
  $d = pf('/sync/products/' . $item['id'], $token); $sp = $d['sync_product'];
  $variants = []; $colors = []; $sizes = []; $previews = [];
  foreach ($d['sync_variants'] as $v) {
    $color = $v['color'] ?? null; $size = $v['size'] ?? null;
    if (!$color && !$size) {
      $rest = strpos($v['name'], $sp['name']) === 0 ? substr($v['name'], strlen($sp['name'])) : $v['name'];
      $parts = array_values(array_filter(array_map('trim', explode('/', $rest)), 'strlen'));
      if (count($parts) === 1) { if (in_array($parts[0], $SIZES)) $size = $parts[0]; else $color = $parts[0]; }
      elseif (count($parts) > 1) { $color = $parts[count($parts) - 2]; $size = $parts[count($parts) - 1]; }
    }
    $variants[] = ['id' => $v['id'], 'size' => $size, 'color' => $color, 'price' => (float)$v['retail_price']];
    if ($color && !in_array($color, $colors)) $colors[] = $color;
    if ($size && !in_array($size, $sizes)) $sizes[] = $size;
    foreach ($v['files'] ?? [] as $f) if (($f['type'] ?? '') === 'preview' && !empty($f['preview_url']) && !in_array($f['preview_url'], $previews)) $previews[] = $f['preview_url'];
  }
  if (count($sizes) > 1 && !array_diff($sizes, $SIZES)) usort($sizes, fn($a, $b) => array_search($a, $SIZES) - array_search($b, $SIZES));
  if ($sizes === ['One size']) $sizes = [];
  $name = preg_replace('/\s+/', ' ', str_replace('®', '', preg_replace('/\bmortez\b/i', 'Mortez', preg_replace('/^\s*mortez\s*[-–]\s*/iu', '', $sp['name']))));
  $kind = kind_of($name); $prices = array_column($variants, 'price');
  if ($kind === 'candle') { $desc = $colors ? "9 oz soy candle. Scent: {$colors[0]}." : '9 oz soy candle.'; $sizes = []; }
  elseif ($kind === 'case') $desc = 'Clear case with the Mortez design, for ' . implode(', ', array_slice($sizes, 0, 3)) . (count($sizes) > 3 ? ' and more.' : '.');
  else {
    $cl = count($colors) > 1 ? implode(', ', array_slice($colors, 0, -1)) . ' and ' . end($colors) : ($colors[0] ?? '');
    $desc = trim(($cl ? "Comes in $cl. " : '') . (count($sizes) > 1 ? "Sizes {$sizes[0]} to " . end($sizes) . '.' : ''));
  }
  $stem = 'p' . $sp['id']; $images = [fetch_image($sp['thumbnail_url'] ?? null, $stem, "$root/media/merch")];
  foreach (array_slice($previews, 0, 3) as $i => $u) $images[] = fetch_image($u, "$stem-" . ($i + 1), "$root/media/merch");
  $products[] = ['id' => $stem, 'name' => $name, 'kind' => $kind, 'price' => min($prices), 'priceMax' => max($prices), 'sizes' => $sizes, 'colors' => $colors,
    'd' => $desc, 'images' => array_values(array_filter($images)), 'variants' => $variants, 'printful' => (bool)$item['synced']];
}
$js = "// Generated by merch-sync.php from the band's Printful store. Do not edit by hand.\nwindow.MORTEZ_MERCH = " . json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . ";\n";
file_put_contents("$root/data/merch.js.tmp", $js); rename("$root/data/merch.js.tmp", "$root/data/merch.js");
echo count($products) . " products synced\n";
