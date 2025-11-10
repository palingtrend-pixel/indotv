<?php
// scrape.php
// Usage:
//  - visit: scrape.php         -> returns HTML page listing items
//  - visit: scrape.php?format=json  -> returns JSON array of items

// --- CONFIG ---
$target = 'https://www.808fubo11.com/'; // Ganti kalau perlu
$userAgent = 'Mozilla/5.0 (compatible; ScraperBot/1.0; +https://yourdomain.com/)';

// --- helper: absolute URL resolver ---
function rel2abs($rel, $base) {
    // if already absolute
    if (parse_url($rel, PHP_URL_SCHEME) != '') return $rel;
    // queries and anchors
    if ($rel[0] == '#' || $rel[0] == '?') return $base . $rel;
    // parse base
    $parts = parse_url($base);
    $scheme = $parts['scheme'];
    $host = $parts['host'];
    $path = isset($parts['path']) ? preg_replace('#/[^/]*$#', '/', $parts['path']) : '/';
    if ($rel[0] == '/') $path = '';
    $abs = "$host$path$rel";
    // normalize
    $re = array('#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#');
    for ($n=1; $n>0; $abs = preg_replace($re, '/', $abs, -1, $n)) {}
    return $scheme . '://' . $abs;
}

// --- fetch with cURL ---
function fetch_url($url, $ua) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, $ua);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    // optional: set header to mimic normal browser
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
    ]);
    $html = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($html === false) return null;
    return $html;
}

$html = fetch_url($target, $userAgent);
if (!$html) {
    header("Content-Type: text/plain; charset=utf-8");
    echo "Failed to fetch target URL: $target\n";
    exit;
}

// --- parse DOM ---
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
$xpath = new DOMXPath($dom);

// Prepare results array
$items = [];

// 1) Find <video> tags and their <source>
$videoNodes = $xpath->query('//video');
foreach ($videoNodes as $v) {
    $sources = $v->getElementsByTagName('source');
    $videoUrl = null;
    foreach ($sources as $s) {
        $src = $s->getAttribute('src');
        if ($src) { $videoUrl = rel2abs($src, $target); break; }
    }
    if (!$videoUrl) {
        $src = $v->getAttribute('src');
        if ($src) $videoUrl = rel2abs($src, $target);
    }
    // attempt to find nearby thumbnail or poster attribute
    $thumb = $v->getAttribute('poster');
    if ($thumb) $thumb = rel2abs($thumb, $target);
    $title = trim($v->getAttribute('title')) ?: 'Video';
    if ($videoUrl) {
        $items[] = ['type'=>'video', 'title'=>$title, 'video'=>$videoUrl, 'image'=>$thumb];
    }
}

// 2) Find <iframe> (youtube/embed/players) - include src
$iframes = $xpath->query('//iframe');
foreach ($iframes as $if) {
    $src = $if->getAttribute('src');
    if (!$src) continue;
    $abs = rel2abs($src, $target);
    // ignore common ads if needed by filtering domains
    $items[] = ['type'=>'iframe', 'title'=>'Embedded', 'video'=>$abs, 'image'=>null];
}

// 3) Find anchor tags that link to .mp4, .m3u8 or common video extensions
$aTags = $xpath->query('//a');
foreach ($aTags as $a) {
    $href = $a->getAttribute('href');
    if (!$href) continue;
    $hrefLow = strtolower($href);
    if (preg_match('/\.(mp4|m3u8|mkv|webm)(\?|$)/', $hrefLow)) {
        $abs = rel2abs($href, $target);
        $text = trim($a->textContent) ?: basename(parse_url($abs, PHP_URL_PATH));
        // try to find thumbnail in same parent
        $img = null;
        $imgs = $a->getElementsByTagName('img');
        if ($imgs->length>0) {
            $img = rel2abs($imgs->item(0)->getAttribute('src'), $target);
        }
        $items[] = ['type'=>'video', 'title'=>$text, 'video'=>$abs, 'image'=>$img];
    }
}

// 4) Find images (thumbnails) - include as items if they look like video thumbs (heuristic)
$imgTags = $xpath->query('//img');
foreach ($imgTags as $img) {
    $src = $img->getAttribute('src');
    if (!$src) continue;
    $abs = rel2abs($src, $target);
    $alt = trim($img->getAttribute('alt')) ?: '';
    // include image item (can be used as gallery or thumbnail)
    $items[] = ['type'=>'image', 'title'=>$alt ?: basename(parse_url($abs, PHP_URL_PATH)), 'image'=>$abs, 'video'=>null];
}

// Remove duplicates by video/image URL
$seen = [];
$uniq = [];
foreach ($items as $it) {
    $key = ($it['video'] ?? $it['image']) ?: json_encode($it);
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $uniq[] = $it;
}
$items = $uniq;

// Output JSON or HTML
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Simple HTML output
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Versi Bersih - <?= htmlspecialchars($target) ?></title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial; margin:0;padding:0;background:#f7f7f7;color:#222}
.container{max-width:1000px;margin:18px auto;padding:12px}
.card{background:#fff;border-radius:8px;padding:12px;margin-bottom:12px;box-shadow:0 2px 6px rgba(0,0,0,0.07);display:flex;gap:12px;align-items:flex-start}
.thumb{width:160px;height:90px;object-fit:cover;border-radius:6px;background:#ddd}
.info{flex:1}
.title{font-weight:600;margin-bottom:6px}
.meta{font-size:13px;color:#666}
.btn{display:inline-block;padding:8px 10px;border-radius:6px;background:#1976d2;color:#fff;text-decoration:none;font-size:14px}
.small{font-size:13px;color:#555}
</style>
</head>
<body>
<div class="container">
  <h2>Versi Bersih: <?= htmlspecialchars($target) ?></h2>
  <p class="small">Sumber: <?= htmlspecialchars($target) ?> · <?= count($items) ?> item ditemukan · <a href="?format=json">lihat JSON</a></p>

  <?php if (count($items)===0): ?>
    <div class="card"><div class="info">Tidak ditemukan video/gambar pada halaman ini.</div></div>
  <?php endif; ?>

  <?php foreach ($items as $it): ?>
    <div class="card">
      <?php if (!empty($it['image'])): ?>
        <img class="thumb" src="<?= htmlspecialchars($it['image']) ?>" loading="lazy" />
      <?php else: ?>
        <div style="width:160px;height:90px;background:#e3e3e3;border-radius:6px;display:flex;align-items:center;justify-content:center;color:#999">No Image</div>
      <?php endif; ?>
      <div class="info">
        <div class="title"><?= htmlspecialchars($it['title'] ?? ($it['video'] ?? 'Item')) ?></div>
        <div class="meta">Tipe: <?= htmlspecialchars($it['type']) ?></div>
        <div style="margin-top:8px;">
          <?php if (!empty($it['video'])): ?>
            <a class="btn" href="<?= htmlspecialchars($it['video']) ?>" target="_blank">Buka/Play</a>
            <a class="btn" href="player.php?src=<?= urlencode($it['video']) ?>" style="background:#333">Play in page</a>
          <?php endif; ?>
          <?php if ($it['type']==='image'): ?>
            <a class="btn" href="<?= htmlspecialchars($it['image']) ?>" target="_blank" style="background:#444">Lihat Gambar</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

</div>
</body>
</html><?php
// scrape.php
// Usage:
//  - visit: scrape.php         -> returns HTML page listing items
//  - visit: scrape.php?format=json  -> returns JSON array of items

// --- CONFIG ---
$target = 'https://www.808fubo11.com/'; // Ganti kalau perlu
$userAgent = 'Mozilla/5.0 (compatible; ScraperBot/1.0; +https://yourdomain.com/)';

// --- helper: absolute URL resolver ---
function rel2abs($rel, $base) {
    // if already absolute
    if (parse_url($rel, PHP_URL_SCHEME) != '') return $rel;
    // queries and anchors
    if ($rel[0] == '#' || $rel[0] == '?') return $base . $rel;
    // parse base
    $parts = parse_url($base);
    $scheme = $parts['scheme'];
    $host = $parts['host'];
    $path = isset($parts['path']) ? preg_replace('#/[^/]*$#', '/', $parts['path']) : '/';
    if ($rel[0] == '/') $path = '';
    $abs = "$host$path$rel";
    // normalize
    $re = array('#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#');
    for ($n=1; $n>0; $abs = preg_replace($re, '/', $abs, -1, $n)) {}
    return $scheme . '://' . $abs;
}

// --- fetch with cURL ---
function fetch_url($url, $ua) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, $ua);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    // optional: set header to mimic normal browser
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
    ]);
    $html = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($html === false) return null;
    return $html;
}

$html = fetch_url($target, $userAgent);
if (!$html) {
    header("Content-Type: text/plain; charset=utf-8");
    echo "Failed to fetch target URL: $target\n";
    exit;
}

// --- parse DOM ---
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
$xpath = new DOMXPath($dom);

// Prepare results array
$items = [];

// 1) Find <video> tags and their <source>
$videoNodes = $xpath->query('//video');
foreach ($videoNodes as $v) {
    $sources = $v->getElementsByTagName('source');
    $videoUrl = null;
    foreach ($sources as $s) {
        $src = $s->getAttribute('src');
        if ($src) { $videoUrl = rel2abs($src, $target); break; }
    }
    if (!$videoUrl) {
        $src = $v->getAttribute('src');
        if ($src) $videoUrl = rel2abs($src, $target);
    }
    // attempt to find nearby thumbnail or poster attribute
    $thumb = $v->getAttribute('poster');
    if ($thumb) $thumb = rel2abs($thumb, $target);
    $title = trim($v->getAttribute('title')) ?: 'Video';
    if ($videoUrl) {
        $items[] = ['type'=>'video', 'title'=>$title, 'video'=>$videoUrl, 'image'=>$thumb];
    }
}

// 2) Find <iframe> (youtube/embed/players) - include src
$iframes = $xpath->query('//iframe');
foreach ($iframes as $if) {
    $src = $if->getAttribute('src');
    if (!$src) continue;
    $abs = rel2abs($src, $target);
    // ignore common ads if needed by filtering domains
    $items[] = ['type'=>'iframe', 'title'=>'Embedded', 'video'=>$abs, 'image'=>null];
}

// 3) Find anchor tags that link to .mp4, .m3u8 or common video extensions
$aTags = $xpath->query('//a');
foreach ($aTags as $a) {
    $href = $a->getAttribute('href');
    if (!$href) continue;
    $hrefLow = strtolower($href);
    if (preg_match('/\.(mp4|m3u8|mkv|webm)(\?|$)/', $hrefLow)) {
        $abs = rel2abs($href, $target);
        $text = trim($a->textContent) ?: basename(parse_url($abs, PHP_URL_PATH));
        // try to find thumbnail in same parent
        $img = null;
        $imgs = $a->getElementsByTagName('img');
        if ($imgs->length>0) {
            $img = rel2abs($imgs->item(0)->getAttribute('src'), $target);
        }
        $items[] = ['type'=>'video', 'title'=>$text, 'video'=>$abs, 'image'=>$img];
    }
}

// 4) Find images (thumbnails) - include as items if they look like video thumbs (heuristic)
$imgTags = $xpath->query('//img');
foreach ($imgTags as $img) {
    $src = $img->getAttribute('src');
    if (!$src) continue;
    $abs = rel2abs($src, $target);
    $alt = trim($img->getAttribute('alt')) ?: '';
    // include image item (can be used as gallery or thumbnail)
    $items[] = ['type'=>'image', 'title'=>$alt ?: basename(parse_url($abs, PHP_URL_PATH)), 'image'=>$abs, 'video'=>null];
}

// Remove duplicates by video/image URL
$seen = [];
$uniq = [];
foreach ($items as $it) {
    $key = ($it['video'] ?? $it['image']) ?: json_encode($it);
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $uniq[] = $it;
}
$items = $uniq;

// Output JSON or HTML
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Simple HTML output
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Versi Bersih - <?= htmlspecialchars($target) ?></title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial; margin:0;padding:0;background:#f7f7f7;color:#222}
.container{max-width:1000px;margin:18px auto;padding:12px}
.card{background:#fff;border-radius:8px;padding:12px;margin-bottom:12px;box-shadow:0 2px 6px rgba(0,0,0,0.07);display:flex;gap:12px;align-items:flex-start}
.thumb{width:160px;height:90px;object-fit:cover;border-radius:6px;background:#ddd}
.info{flex:1}
.title{font-weight:600;margin-bottom:6px}
.meta{font-size:13px;color:#666}
.btn{display:inline-block;padding:8px 10px;border-radius:6px;background:#1976d2;color:#fff;text-decoration:none;font-size:14px}
.small{font-size:13px;color:#555}
</style>
</head>
<body>
<div class="container">
  <h2>Versi Bersih: <?= htmlspecialchars($target) ?></h2>
  <p class="small">Sumber: <?= htmlspecialchars($target) ?> · <?= count($items) ?> item ditemukan · <a href="?format=json">lihat JSON</a></p>

  <?php if (count($items)===0): ?>
    <div class="card"><div class="info">Tidak ditemukan video/gambar pada halaman ini.</div></div>
  <?php endif; ?>

  <?php foreach ($items as $it): ?>
    <div class="card">
      <?php if (!empty($it['image'])): ?>
        <img class="thumb" src="<?= htmlspecialchars($it['image']) ?>" loading="lazy" />
      <?php else: ?>
        <div style="width:160px;height:90px;background:#e3e3e3;border-radius:6px;display:flex;align-items:center;justify-content:center;color:#999">No Image</div>
      <?php endif; ?>
      <div class="info">
        <div class="title"><?= htmlspecialchars($it['title'] ?? ($it['video'] ?? 'Item')) ?></div>
        <div class="meta">Tipe: <?= htmlspecialchars($it['type']) ?></div>
        <div style="margin-top:8px;">
          <?php if (!empty($it['video'])): ?>
            <a class="btn" href="<?= htmlspecialchars($it['video']) ?>" target="_blank">Buka/Play</a>
            <a class="btn" href="player.php?src=<?= urlencode($it['video']) ?>" style="background:#333">Play in page</a>
          <?php endif; ?>
          <?php if ($it['type']==='image'): ?>
            <a class="btn" href="<?= htmlspecialchars($it['image']) ?>" target="_blank" style="background:#444">Lihat Gambar</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

</div>
</body>
</html>