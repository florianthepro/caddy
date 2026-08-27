<?php
declare(strict_types=1);

const WWW        = 'C:\\caddy\\www';
const TOKEN_FILE = 'C:\\caddy\\cf_token.txt';
const MAIN_CFG   = 'C:\\caddy\\caddyfile';
const SITES_CFG  = 'C:\\caddy\\sites.caddyfile';
const CSRF_FILE  = 'C:\\caddy\\data\\panel.csrf';
const CONFLICTS  = 'C:\\caddy\\logs\\dns-conflict.log';

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('local access only');
}

function csrf(): string
{
    if (!is_file(CSRF_FILE)) {
        @mkdir(dirname(CSRF_FILE), 0700, true);
        file_put_contents(CSRF_FILE, bin2hex(random_bytes(32)));
    }
    return trim((string)file_get_contents(CSRF_FILE));
}

function token(): ?string
{
    if (!is_file(TOKEN_FILE)) {
        return null;
    }
    $t = trim((string)file_get_contents(TOKEN_FILE));
    return strlen($t) > 20 ? $t : null;
}

function cf(string $path): ?array
{
    $t = token();
    if ($t === null) {
        return null;
    }
    $ch = curl_init('https://api.cloudflare.com/client/v4' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $t],
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    if (!is_string($raw)) {
        return null;
    }
    $j = json_decode($raw, true);
    return is_array($j) && ($j['success'] ?? false) ? $j : null;
}

function zones(): array
{
    $j = cf('/zones?per_page=50');
    return $j['result'] ?? [];
}

function records(string $zoneId): array
{
    $j = cf('/zones/' . urlencode($zoneId) . '/dns_records?per_page=200');
    return $j['result'] ?? [];
}

function folders(): array
{
    $out = [];
    foreach (glob(WWW . '\\*', GLOB_ONLYDIR) ?: [] as $d) {
        $n = strtolower(basename($d));
        if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $n)) {
            $out[] = $n;
        }
    }
    sort($out);
    return $out;
}

function configured(): array
{
    $out = [];
    foreach ([MAIN_CFG, SITES_CFG] as $f) {
        if (!is_file($f)) {
            continue;
        }
        $c = preg_replace('/^\s*#.*$/m', '', (string)file_get_contents($f));
        preg_match_all('/^([^\s#{}][^{}\n]*?)\s*\{/m', (string)$c, $m);
        foreach ($m[1] as $line) {
            foreach (preg_split('/[,\s]+/', trim($line)) ?: [] as $p) {
                $p = preg_replace('/^https?:\/\//', '', $p);
                $p = preg_replace('/:\d+$/', '', (string)$p);
                if ($p !== '' && str_contains($p, '.')) {
                    $out[] = strtolower($p);
                }
            }
        }
    }
    return array_unique($out);
}

$notice = null;
$error  = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) {
        $error = 'CSRF-Token ungültig. Seite neu laden.';
    } else {
        $sub  = strtolower(trim((string)($_POST['sub'] ?? '')));
        $zone = strtolower(trim((string)($_POST['zone'] ?? '')));
        $fqdn = ($sub === '' || $sub === '@') ? $zone : $sub . '.' . $zone;

        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $fqdn)) {
            $error = 'Ungültiger Name.';
        } elseif (!in_array($zone, array_column(zones(), 'name'), true)) {
            $error = 'Unbekannte Zone.';
        } elseif (is_dir(WWW . '\\' . $fqdn)) {
            $error = 'Ordner existiert bereits.';
        } else {
            $taken = null;
            $zid   = null;
            foreach (zones() as $z) {
                if ($z['name'] === $zone) {
                    $zid = $z['id'];
                }
            }
            foreach ($zid !== null ? records((string)$zid) : [] as $r) {
                if (strtolower((string)$r['name']) === $fqdn) {
                    $taken = $r;
                }
            }
            if ($taken !== null) {
                $error = 'Record belegt: ' . $taken['type'] . ' -> ' . $taken['content'];
            } elseif (@mkdir(WWW . '\\' . $fqdn, 0755, true)) {
                @file_put_contents(WWW . '\\' . $fqdn . '\\index.html', "<h1>$fqdn</h1>\n");
                $notice = $fqdn . ' angelegt. DNS-Record und Caddy-Reload folgen automatisch.';
            } else {
                $error = 'Ordner konnte nicht angelegt werden.';
            }
        }
    }
}

$zones      = zones();
$hasToken   = token() !== null;
$folders    = folders();
$configured = configured();
$sel        = strtolower(trim((string)($_GET['zone'] ?? ($zones[0]['name'] ?? ''))));
$selId      = null;
foreach ($zones as $z) {
    if ($z['name'] === $sel) {
        $selId = $z['id'];
    }
}
$recs = $selId !== null ? records((string)$selId) : [];

$dnsNames = [];
foreach ($zones as $z) {
    foreach (records((string)$z['id']) as $r) {
        $dnsNames[strtolower((string)$r['name'])] = (string)$r['type'];
    }
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Caddy Panel</title>
<style>
:root{--bg:#fbfbfa;--fg:#1a1a18;--mut:#6b6b66;--line:#e3e3df;--card:#fff;--ok:#2f7d3a;--warn:#9a6b00;--err:#a33;--accent:#2c5aa0}
@media(prefers-color-scheme:dark){:root{--bg:#17171a;--fg:#e8e8e4;--mut:#9a9a95;--line:#2e2e33;--card:#1e1e22;--ok:#6cc27a;--warn:#d9a441;--err:#e08383;--accent:#7aa5e8}}
*{box-sizing:border-box}
body{margin:0;padding:2rem 1.25rem;background:var(--bg);color:var(--fg);font:15px/1.5 ui-sans-serif,system-ui,"Segoe UI",sans-serif}
main{max-width:60rem;margin:0 auto}
h1{font-size:1.35rem;margin:0 0 .25rem}
h2{font-size:1rem;margin:2rem 0 .75rem;font-weight:600}
p.sub{color:var(--mut);margin:0 0 2rem}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:1rem 1.15rem;margin-bottom:1rem}
table{width:100%;border-collapse:collapse;font-size:.9rem}
th{text-align:left;font-weight:600;color:var(--mut);padding:.4rem .5rem;border-bottom:1px solid var(--line)}
td{padding:.45rem .5rem;border-bottom:1px solid var(--line)}
tr:last-child td{border-bottom:0}
code{font:.85em ui-monospace,Consolas,monospace;background:color-mix(in srgb,var(--fg) 7%,transparent);padding:.1em .35em;border-radius:4px}
.ok{color:var(--ok)}.warn{color:var(--warn)}.err{color:var(--err)}
form{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
select,input[type=text]{font:inherit;padding:.45rem .55rem;border:1px solid var(--line);border-radius:7px;background:var(--bg);color:var(--fg)}
input[type=text]{min-width:12rem}
button{font:inherit;font-weight:600;padding:.45rem 1rem;border:0;border-radius:7px;background:var(--accent);color:#fff;cursor:pointer}
.msg{padding:.7rem .9rem;border-radius:8px;margin-bottom:1rem;border:1px solid}
.msg.ok{border-color:var(--ok);color:var(--ok)}
.msg.err{border-color:var(--err);color:var(--err)}
.scroll{overflow-x:auto}
pre{margin:0;font:.8rem ui-monospace,Consolas,monospace;white-space:pre-wrap;color:var(--mut)}
</style>
<main>
<h1>Caddy Panel</h1>
<p class="sub">Ordner anlegen genügt — DNS-Record und Reload macht der Agent.</p>

<?php if ($notice !== null): ?><div class="msg ok"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error !== null): ?><div class="msg err"><?= e($error) ?></div><?php endif; ?>
<?php if (!$hasToken): ?><div class="msg err">Kein Cloudflare-Token hinterlegt. <code>caddy.bat</code> ausführen.</div><?php endif; ?>

<h2>Neue Site</h2>
<div class="card">
<form method="post">
<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
<input type="text" name="sub" placeholder="subdomain (leer = Hauptdomain)" autocomplete="off">
<select name="zone">
<?php foreach ($zones as $z): ?><option value="<?= e((string)$z['name']) ?>"><?= e((string)$z['name']) ?></option><?php endforeach; ?>
</select>
<button type="submit">Anlegen</button>
</form>
</div>

<h2>Sites</h2>
<div class="card scroll">
<table>
<tr><th>Domain</th><th>Ordner</th><th>DNS</th><th>Caddy</th><th>Status</th></tr>
<?php foreach ($folders as $f):
    $hasDns = isset($dnsNames[$f]);
    $hasCfg = in_array($f, $configured, true);
    if ($hasDns && $hasCfg) { $st = '<span class="ok">aktiv</span>'; }
    elseif (!$hasDns)       { $st = '<span class="warn">DNS folgt</span>'; }
    else                    { $st = '<span class="warn">Reload folgt</span>'; }
?>
<tr>
<td><code><?= e($f) ?></code></td>
<td class="ok">ja</td>
<td><?= $hasDns ? '<span class="ok">' . e($dnsNames[$f]) . '</span>' : '<span class="warn">fehlt</span>' ?></td>
<td><?= $hasCfg ? '<span class="ok">ja</span>' : '<span class="warn">fehlt</span>' ?></td>
<td><?= $st ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$folders): ?><tr><td colspan="5" class="warn">Noch keine Ordner in <code>C:\caddy\www</code>.</td></tr><?php endif; ?>
</table>
</div>

<h2>Belegte Records</h2>
<div class="card">
<form method="get">
<select name="zone" onchange="this.form.submit()">
<?php foreach ($zones as $z): ?><option value="<?= e((string)$z['name']) ?>"<?= $z['name'] === $sel ? ' selected' : '' ?>><?= e((string)$z['name']) ?></option><?php endforeach; ?>
</select>
<noscript><button type="submit">Zeigen</button></noscript>
</form>
</div>
<div class="card scroll">
<table>
<tr><th>Name</th><th>Typ</th><th>Ziel</th><th>Proxy</th></tr>
<?php foreach ($recs as $r): ?>
<tr>
<td><code><?= e((string)$r['name']) ?></code></td>
<td><?= e((string)$r['type']) ?></td>
<td><code><?= e((string)$r['content']) ?></code></td>
<td><?= !empty($r['proxied']) ? '<span class="ok">an</span>' : '<span class="mut">aus</span>' ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$recs): ?><tr><td colspan="4" class="warn">Keine Records geladen.</td></tr><?php endif; ?>
</table>
</div>

<?php if (is_file(CONFLICTS)):
    $tail = array_slice(array_filter(explode("\n", (string)file_get_contents(CONFLICTS))), -12);
    if ($tail): ?>
<h2>Konflikte</h2>
<div class="card"><pre><?= e(implode("\n", $tail)) ?></pre></div>
<?php endif; endif; ?>
</main>
