<?php
/**
 * bsfisazubi.de - Ausbildungsportal Fachinformatiker/-in Systemintegration
 *
 * Eine Datei, SQLite, keine Abhaengigkeiten. Jedes Konto steht fuer sich:
 * eigene Faecher, eigener Stundenplan, eigenes Berichtsheft. Keine Rollen,
 * keine Verwaltung, kein Admin.
 *
 * INSTALLATION
 *   1  index.php nach C:\caddy\www\bsfisazubi.de\ kopieren
 *   2  php.ini: extension=pdo_sqlite, sqlite3, mbstring, openssl, fileinfo
 *   3  Caddyfile-Block aus dem Repo uebernehmen, dann
 *      "C:\caddy\caddy.exe" reload --config "C:\caddy\caddyfile"
 *   4  Aufrufen. Das erste Konto legt sich ohne Code an. Fuer jedes weitere
 *      gilt der Code aus C:\caddy\www\bsfisazubi.de-data\REGISTRIERUNG.txt
 *      (oder REGISTRIER_CODE unten fest setzen).
 *
 * Daten liegen in <webroot>-data, also ausserhalb des ausgelieferten
 * Verzeichnisses. Genau dieser Ordner gehoert ins Backup.
 */

// --- Konfiguration ---------------------------------------------------------
const APP_NAME        = 'bsfisazubi';
const APP_DOMAIN      = 'bsfisazubi.de';
const APP_VERSION     = '2.0';
const REGISTRIER_CODE = '';      // fest setzen -> ersetzt die Datei REGISTRIERUNG.txt
const MAX_UPLOAD_MB   = 8;
const SESSION_IDLE    = 3600;
const SESSION_ABS     = 43200;
const LOGIN_MAX_IP    = 20;
const LOGIN_MAX_USER  = 6;
const LOGIN_LOCK_SEC  = 900;
const PW_MIN_LEN      = 10;
const TRUSTED_PROXIES = [];
const IMPORT_PRIVAT   = false;  // true erlaubt Importe aus dem lokalen Netz (eigener WebUntis-Server)

define('DATA_DIR', (function (): string {
    $env = getenv('BSFISI_DATA_DIR');
    if (is_string($env) && $env !== '') return rtrim($env, "/\\");
    $aussen = dirname(__DIR__) . DIRECTORY_SEPARATOR . basename(__DIR__) . '-data';
    if (is_dir($aussen) || @mkdir($aussen, 0700, true) || is_writable(dirname(__DIR__))) return $aussen;
    return __DIR__ . DIRECTORY_SEPARATOR . 'data';
})());

// --- Bootstrap -------------------------------------------------------------
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Berlin');

if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0700, true);
if (!is_dir(DATA_DIR)) { http_response_code(500); exit('Datenverzeichnis nicht anlegbar: ' . htmlspecialchars(DATA_DIR)); }
ini_set('error_log', DATA_DIR . '/php-error.log');
foreach ([['.htaccess', "Require all denied\nDeny from all\n"], ['index.html', ''],
          ['web.config', '<configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>']] as [$f, $c]) {
    if (!is_file(DATA_DIR . '/' . $f)) @file_put_contents(DATA_DIR . '/' . $f, $c);
}

set_exception_handler(function (Throwable $e): void {
    error_log('[' . date('c') . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/html; charset=utf-8'); }
    echo '<!doctype html><meta charset="utf-8"><title>Fehler</title><div style="font:15px system-ui;'
       . 'max-width:32rem;margin:14vh auto;padding:1.4rem;border:1px solid #ddd;border-radius:6px">'
       . '<b>Fehler</b><p>Die Aktion wurde nicht ausgefuehrt.</p>'
       . '<a href="' . htmlspecialchars(base_path()) . '">Zurueck</a></div>';
});

function client_ip(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (TRUSTED_PROXIES && in_array($remote, TRUSTED_PROXIES, true)) {
        $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($fwd !== '') {
            $parts = array_map('trim', explode(',', $fwd));
            $cand  = end($parts);
            if (filter_var($cand, FILTER_VALIDATE_IP)) return $cand;
        }
    }
    return $remote;
}
function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') return true;
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    return TRUSTED_PROXIES && in_array($remote, TRUSTED_PROXIES, true)
        && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}
function base_path(): string {
    $d = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
    return $d === '' ? '/' : $d . '/';
}

$GLOBALS['NONCE'] = base64_encode(random_bytes(16));
header_remove('X-Powered-By');
header("Content-Security-Policy: default-src 'none'; base-uri 'none'; script-src 'nonce-"
    . $GLOBALS['NONCE'] . "'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; "
    . "font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=(), usb=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');
if (is_https()) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

$sessDir = DATA_DIR . '/sessions';
if (!is_dir($sessDir)) @mkdir($sessDir, 0700, true);
if (is_dir($sessDir) && is_writable($sessDir)) session_save_path($sessDir);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', (string)SESSION_ABS);
session_name('fisi');
session_set_cookie_params(['lifetime' => 0, 'path' => base_path(), 'httponly' => true,
                           'secure' => is_https(), 'samesite' => 'Strict']);
session_start();

// --- Datenbank -------------------------------------------------------------
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (!extension_loaded('pdo_sqlite')) {
        http_response_code(500);
        exit('<h1>pdo_sqlite fehlt</h1><p>In der php.ini <code>extension=pdo_sqlite</code> aktivieren, PHP neu starten.</p>');
    }
    $marker = DATA_DIR . '/db.path';
    if (is_file($marker)) { $file = trim((string)file_get_contents($marker)); }
    else {
        $file = DATA_DIR . '/portal-' . bin2hex(random_bytes(8)) . '.sqlite';
        file_put_contents($marker, $file); @chmod($marker, 0600);
    }
    $fresh = !is_file($file);
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    if ($fresh) @chmod($file, 0600);
    foreach (['journal_mode = WAL', 'foreign_keys = ON', 'busy_timeout = 5000', 'synchronous = NORMAL'] as $p) {
        $pdo->exec('PRAGMA ' . $p);
    }
    schema($pdo);
    return $pdo;
}
function q(string $sql, array $a = []): PDOStatement { $st = db()->prepare($sql); $st->execute($a); return $st; }
function all(string $sql, array $a = []): array { return q($sql, $a)->fetchAll(); }
function one(string $sql, array $a = []): ?array { $r = q($sql, $a)->fetch(); return $r === false ? null : $r; }
function val(string $sql, array $a = [], $d = null) { $r = q($sql, $a)->fetch(PDO::FETCH_NUM); return $r === false ? $d : $r[0]; }
function ins(string $t, array $d): int {
    q('INSERT INTO ' . $t . ' (' . implode(',', array_keys($d)) . ') VALUES ('
      . implode(',', array_map(fn($c) => ':' . $c, array_keys($d))) . ')', $d);
    return (int)db()->lastInsertId();
}
function upd(string $t, array $d, string $w, array $a = []): int {
    $set = implode(',', array_map(fn($c) => "$c = :s_$c", array_keys($d)));
    $p = []; foreach ($d as $k => $v) $p['s_' . $k] = $v;
    return q("UPDATE $t SET $set WHERE $w", $p + $a)->rowCount();
}
function del(string $t, string $w, array $a = []): int { return q("DELETE FROM $t WHERE $w", $a)->rowCount(); }

function schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT NOT NULL)");
    $v = (int)($pdo->query("SELECT v FROM meta WHERE k='schema'")->fetchColumn() ?: 0);
    if ($v >= 2) {
        if ($v < 3) schema_v3($pdo);
        if ($v < 4) schema_v4($pdo);
        if ($v < 5) schema_v5($pdo);
        if ($v < 6) schema_v6($pdo);
        if ($v < 7) schema_v7($pdo);
        if ($v < 8) schema_v8($pdo);
        return;
    }
    $pdo->exec(<<<SQL
CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE COLLATE NOCASE,
  email TEXT UNIQUE COLLATE NOCASE,
  pass_hash TEXT NOT NULL,
  name TEXT NOT NULL DEFAULT '',
  beruf TEXT NOT NULL DEFAULT 'Fachinformatiker/-in Systemintegration',
  klasse TEXT NOT NULL DEFAULT '',
  zeitgruppe INTEGER NOT NULL DEFAULT 0,
  betrieb TEXT NOT NULL DEFAULT '',
  ausbilder TEXT NOT NULL DEFAULT '',
  abteilung TEXT NOT NULL DEFAULT '',
  start TEXT, ende TEXT,
  wochenstunden REAL NOT NULL DEFAULT 40,
  bh_art TEXT NOT NULL DEFAULT 'woche',
  ap1 TEXT, ap2 TEXT,
  theme TEXT NOT NULL DEFAULT 'auto',
  ics_token TEXT,
  totp_secret TEXT, totp_enabled INTEGER NOT NULL DEFAULT 0, recovery TEXT,
  failed INTEGER NOT NULL DEFAULT 0, locked_until INTEGER NOT NULL DEFAULT 0,
  last_login TEXT, last_ip TEXT, pw_changed TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE subjects (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  name TEXT NOT NULL, short TEXT NOT NULL DEFAULT '', lf_no INTEGER,
  color TEXT NOT NULL DEFAULT '#2563eb', lehrer TEXT NOT NULL DEFAULT '',
  sort INTEGER NOT NULL DEFAULT 0, archiv INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE lernfelder (nr INTEGER PRIMARY KEY, code TEXT NOT NULL, titel TEXT NOT NULL,
  jahr INTEGER NOT NULL, stunden INTEGER NOT NULL DEFAULT 80);
CREATE TABLE events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  subject_id INTEGER REFERENCES subjects(id) ON DELETE SET NULL,
  typ TEXT NOT NULL DEFAULT 'probe', titel TEXT NOT NULL,
  beschreibung TEXT NOT NULL DEFAULT '', datum TEXT NOT NULL,
  zeit_von TEXT NOT NULL DEFAULT '', zeit_bis TEXT NOT NULL DEFAULT '',
  raum TEXT NOT NULL DEFAULT '', lf_no INTEGER, stoff TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE notes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  subject_id INTEGER REFERENCES subjects(id) ON DELETE SET NULL,
  lf_no INTEGER, datum TEXT NOT NULL, titel TEXT NOT NULL DEFAULT '',
  body TEXT NOT NULL DEFAULT '', tags TEXT NOT NULL DEFAULT '',
  kind TEXT NOT NULL DEFAULT 'notiz', sprache TEXT NOT NULL DEFAULT '',
  pinned INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE grades (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  subject_id INTEGER REFERENCES subjects(id) ON DELETE SET NULL,
  fach_text TEXT NOT NULL DEFAULT '', art TEXT NOT NULL DEFAULT 'schulaufgabe',
  skala TEXT NOT NULL DEFAULT 'note', wert REAL NOT NULL, gewicht REAL NOT NULL DEFAULT 1,
  datum TEXT NOT NULL, titel TEXT NOT NULL DEFAULT '', halbjahr TEXT NOT NULL DEFAULT '',
  bemerkung TEXT NOT NULL DEFAULT ''
);
CREATE TABLE tasks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  subject_id INTEGER REFERENCES subjects(id) ON DELETE SET NULL,
  titel TEXT NOT NULL, beschreibung TEXT NOT NULL DEFAULT '', faellig TEXT,
  prio INTEGER NOT NULL DEFAULT 1, status TEXT NOT NULL DEFAULT 'offen',
  bereich TEXT NOT NULL DEFAULT 'schule', erledigt_am TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE,
  abschnitt TEXT NOT NULL DEFAULT 'A', pos_no TEXT NOT NULL DEFAULT '',
  farbe TEXT NOT NULL DEFAULT '#2563eb', sort INTEGER NOT NULL DEFAULT 0);
CREATE TABLE category_rules (id INTEGER PRIMARY KEY AUTOINCREMENT, keyword TEXT NOT NULL,
  category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
  prio INTEGER NOT NULL DEFAULT 10);
CREATE TABLE reports (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  art TEXT NOT NULL DEFAULT 'woche', periode TEXT NOT NULL,
  von TEXT NOT NULL, bis TEXT NOT NULL, jahr INTEGER NOT NULL DEFAULT 1,
  abteilung TEXT NOT NULL DEFAULT '', schule_text TEXT NOT NULL DEFAULT '',
  sonstiges TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'offen',
  fertig_am TEXT, updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  UNIQUE(user_id, art, periode)
);
CREATE TABLE report_entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  report_id INTEGER NOT NULL REFERENCES reports(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  datum TEXT NOT NULL, stunden REAL NOT NULL DEFAULT 0,
  category_id INTEGER REFERENCES categories(id) ON DELETE SET NULL,
  lf_no INTEGER, ort TEXT NOT NULL DEFAULT 'betrieb', text TEXT NOT NULL DEFAULT '',
  quelle TEXT NOT NULL DEFAULT 'manuell'
);
CREATE TABLE routines (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  name TEXT NOT NULL, intervall TEXT NOT NULL DEFAULT 'taeglich',
  category_id INTEGER REFERENCES categories(id) ON DELETE SET NULL,
  minuten INTEGER NOT NULL DEFAULT 10, bh INTEGER NOT NULL DEFAULT 1,
  aktiv INTEGER NOT NULL DEFAULT 1, sort INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE routine_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  routine_id INTEGER NOT NULL REFERENCES routines(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  datum TEXT NOT NULL, zeit TEXT NOT NULL DEFAULT '', minuten INTEGER NOT NULL DEFAULT 0,
  notiz TEXT NOT NULL DEFAULT ''
);
CREATE TABLE timetable (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  tag INTEGER NOT NULL, stunde INTEGER NOT NULL,
  subject_id INTEGER REFERENCES subjects(id) ON DELETE CASCADE,
  raum TEXT NOT NULL DEFAULT '', UNIQUE(user_id, tag, stunde)
);
CREATE TABLE blocks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  von TEXT NOT NULL, bis TEXT NOT NULL, art TEXT NOT NULL DEFAULT 'schule',
  label TEXT NOT NULL DEFAULT ''
);
CREATE TABLE absences (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  von TEXT NOT NULL, bis TEXT NOT NULL, art TEXT NOT NULL DEFAULT 'krank',
  grund TEXT NOT NULL DEFAULT '', schule INTEGER NOT NULL DEFAULT 0,
  entschuldigt INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE files (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  name TEXT NOT NULL, mime TEXT NOT NULL, groesse INTEGER NOT NULL,
  daten BLOB NOT NULL, scope TEXT NOT NULL DEFAULT 'note', scope_id INTEGER,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  sid_hash TEXT NOT NULL UNIQUE, ua TEXT NOT NULL DEFAULT '', ip TEXT NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL, last_seen INTEGER NOT NULL, revoked INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, ident TEXT NOT NULL,
  ip TEXT NOT NULL, ok INTEGER NOT NULL DEFAULT 0, ts INTEGER NOT NULL);
CREATE TABLE ratelimit (k TEXT PRIMARY KEY, cnt INTEGER NOT NULL DEFAULT 0, started INTEGER NOT NULL);
CREATE INDEX ix_ev ON events(user_id, datum);
CREATE INDEX ix_no ON notes(user_id, datum);
CREATE INDEX ix_gr ON grades(user_id, datum);
CREATE INDEX ix_ta ON tasks(user_id, status, faellig);
CREATE INDEX ix_re ON report_entries(report_id, datum);
CREATE INDEX ix_rl ON routine_logs(user_id, datum);
CREATE INDEX ix_la ON login_attempts(ts);
SQL);
    $pdo->exec("INSERT INTO meta (k,v) VALUES ('schema','2')");
    seed_global($pdo);
    schema_v3($pdo);
}

/** v3: externe Quellen, Verknuepfungen und Volltextindex. */
function schema_v3(PDO $pdo): void {
    foreach (["ALTER TABLE events ADD COLUMN quelle TEXT NOT NULL DEFAULT 'manuell'",
              "ALTER TABLE events ADD COLUMN extern_id TEXT",
              "ALTER TABLE events ADD COLUMN link TEXT NOT NULL DEFAULT ''",
              "ALTER TABLE notes ADD COLUMN link TEXT NOT NULL DEFAULT ''"] as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) { /* Spalte existiert schon */ }
    }
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS sources (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  typ TEXT NOT NULL DEFAULT 'ics',        -- ics | webuntis
  modus TEXT NOT NULL DEFAULT 'termine',  -- stundenplan | termine
  url TEXT NOT NULL DEFAULT '',
  server TEXT NOT NULL DEFAULT '',
  schule TEXT NOT NULL DEFAULT '',
  benutzer TEXT NOT NULL DEFAULT '',
  secret TEXT,
  aktiv INTEGER NOT NULL DEFAULT 1,
  intervall INTEGER NOT NULL DEFAULT 360,  -- Minuten
  letzter_sync INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT '',
  meldung TEXT NOT NULL DEFAULT '',
  anzahl INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE IF NOT EXISTS ziele (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  name TEXT NOT NULL, url TEXT NOT NULL, sort INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS ix_ev_ext ON events(user_id, extern_id);
SQL);
    $fts = 0;
    try {
        $pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS such USING fts5(
            art UNINDEXED, ref UNINDEXED, uid UNINDEXED, datum UNINDEXED, titel, text,
            tokenize='unicode61 remove_diacritics 2')");
        foreach (fts_trigger_sql() as $sql) $pdo->exec($sql);
        $pdo->exec("DELETE FROM such");
        $pdo->exec("INSERT INTO such(art,ref,uid,datum,titel,text)
            SELECT 'notiz',id,user_id,datum,titel,body||' '||tags FROM notes");
        $pdo->exec("INSERT INTO such(art,ref,uid,datum,titel,text)
            SELECT 'termin',id,user_id,datum,titel,beschreibung||' '||stoff FROM events");
        $pdo->exec("INSERT INTO such(art,ref,uid,datum,titel,text)
            SELECT 'aufgabe',id,user_id,COALESCE(faellig,''),titel,beschreibung FROM tasks");
        $pdo->exec("INSERT INTO such(art,ref,uid,datum,titel,text)
            SELECT 'bericht',id,user_id,datum,text,'' FROM report_entries");
        $pdo->exec("INSERT INTO such(art,ref,uid,datum,titel,text)
            SELECT 'routine',id,user_id,'',name,'' FROM routines");
        $fts = 1;
    } catch (Throwable $e) { $fts = 0; }
    $pdo->exec("INSERT INTO meta (k,v) VALUES ('fts','" . $fts . "')
                ON CONFLICT(k) DO UPDATE SET v='" . $fts . "'");
    $pdo->exec("INSERT INTO meta (k,v) VALUES ('schema','3') ON CONFLICT(k) DO UPDATE SET v='3'");
    schema_v4($pdo);
}
/** v4: Schule aus dem WebUntis-Verzeichnis am Konto merken. */
function schema_v4(PDO $pdo): void {
    foreach (["ALTER TABLE users ADD COLUMN schule TEXT NOT NULL DEFAULT ''",
              "ALTER TABLE users ADD COLUMN untis_server TEXT NOT NULL DEFAULT ''",
              "ALTER TABLE users ADD COLUMN untis_schule TEXT NOT NULL DEFAULT ''"] as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) { /* schon da */ }
    }
    $pdo->exec("INSERT INTO meta (k,v) VALUES ('schema','4') ON CONFLICT(k) DO UPDATE SET v='4'");
    schema_v5($pdo);
}
/** v5: Klasse in ihre Bestandteile zerlegt, dazu Links zum Teilen. */
function schema_v5(PDO $pdo): void {
    foreach (["ALTER TABLE users ADD COLUMN kl_kuerzel TEXT NOT NULL DEFAULT ''",
              "ALTER TABLE users ADD COLUMN kl_nr TEXT NOT NULL DEFAULT ''",
              "ALTER TABLE users ADD COLUMN verkuerzt INTEGER NOT NULL DEFAULT 0"] as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) { /* schon da */ }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS shares (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        token TEXT NOT NULL UNIQUE,
        art TEXT NOT NULL,            -- notiz | fach | bericht
        ref INTEGER NOT NULL,
        titel TEXT NOT NULL DEFAULT '',
        sichtbar TEXT NOT NULL DEFAULT 'link',   -- link = jeder mit Adresse, konten = nur angemeldet
        ablauf TEXT,
        aufrufe INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')))");
    // vorhandene Klassenbezeichnungen zerlegen
    foreach ($pdo->query("SELECT id, klasse FROM users WHERE klasse <> '' AND kl_kuerzel = ''") as $r) {
        if (preg_match('/^(\d|W)\s*([A-Za-z]{1,4})\s*(\d*?)(\d)$/', trim((string)$r['klasse']), $m)) {
            $st = $pdo->prepare("UPDATE users SET kl_kuerzel = ?, kl_nr = ?, zeitgruppe = ?, verkuerzt = ? WHERE id = ?");
            $st->execute([strtoupper($m[2]), $m[3], (int)$m[4], $m[1] === 'W' ? 1 : 0, (int)$r['id']]);
        }
    }
    $pdo->exec("INSERT INTO meta (k,v) VALUES ('schema','5') ON CONFLICT(k) DO UPDATE SET v='5'");
    schema_v6($pdo);
}

/** v6: Ansprechpartner, Einsaetze, Abschlussprojekt, Urlaub, Angaben fuer Ausdrucke. */
function schema_v6(PDO $pdo): void {
    foreach (["ALTER TABLE users ADD COLUMN dok_name TEXT NOT NULL DEFAULT ''",
              "ALTER TABLE users ADD COLUMN dok_geb TEXT NOT NULL DEFAULT ''",
              "ALTER TABLE users ADD COLUMN dok_merken INTEGER NOT NULL DEFAULT 0",
              "ALTER TABLE users ADD COLUMN urlaub_tage REAL NOT NULL DEFAULT 0",
              "ALTER TABLE absences ADD COLUMN tage REAL NOT NULL DEFAULT 0",
              "ALTER TABLE blocks ADD COLUMN quelle TEXT NOT NULL DEFAULT 'manuell'",
              "ALTER TABLE sources ADD COLUMN region TEXT NOT NULL DEFAULT ''",
              "ALTER TABLE shares ADD COLUMN wer TEXT NOT NULL DEFAULT ''"] as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) { /* schon da */ }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS kontakte (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        name TEXT NOT NULL,
        rolle TEXT NOT NULL DEFAULT '',
        bereich TEXT NOT NULL DEFAULT 'betrieb',   -- betrieb | schule | ihk | sonst
        telefon TEXT NOT NULL DEFAULT '',
        mail TEXT NOT NULL DEFAULT '',
        raum TEXT NOT NULL DEFAULT '',
        notiz TEXT NOT NULL DEFAULT '',
        subject_id INTEGER REFERENCES subjects(id) ON DELETE SET NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS einsaetze (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        abteilung TEXT NOT NULL,
        von TEXT NOT NULL, bis TEXT NOT NULL DEFAULT '',
        ansprech TEXT NOT NULL DEFAULT '',
        schwerpunkt TEXT NOT NULL DEFAULT '',
        notiz TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS projekt (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        titel TEXT NOT NULL DEFAULT '',
        beschreibung TEXT NOT NULL DEFAULT '',
        stunden REAL NOT NULL DEFAULT 80,
        antrag TEXT, genehmigt TEXT, von TEXT, bis TEXT,
        doku TEXT, praesentation TEXT,
        status TEXT NOT NULL DEFAULT 'idee',   -- idee | antrag | genehmigt | laeuft | doku | fertig
        notiz TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS projekt_phasen (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        projekt_id INTEGER NOT NULL REFERENCES projekt(id) ON DELETE CASCADE,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        name TEXT NOT NULL, stunden REAL NOT NULL DEFAULT 0,
        ist REAL NOT NULL DEFAULT 0, sort INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_ko ON kontakte(user_id, bereich)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_ei ON einsaetze(user_id, von)");
    $pdo->exec("INSERT INTO meta (k,v) VALUES ('schema','6') ON CONFLICT(k) DO UPDATE SET v='6'");
    schema_v7($pdo);
}

/** v7: merkt sich, welche Ziele jemand tatsaechlich oeffnet. */
function schema_v7(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ziel_nutzung (
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        ziel TEXT NOT NULL,
        anzahl INTEGER NOT NULL DEFAULT 0,
        letzt TEXT NOT NULL DEFAULT '',
        PRIMARY KEY (user_id, ziel))");
    $pdo->exec("INSERT INTO meta (k,v) VALUES ('schema','7') ON CONFLICT(k) DO UPDATE SET v='7'");
    schema_v8($pdo);
}
/**
 * v8: Klassenstufe und ihr Stichtag, damit sich der Ausbildungsbeginn aus der
 * Klasse ableiten laesst; Herkunft der Routinen, um die frueher vorangelegten
 * Beispiele zu erkennen; Namensfreigabe je geteiltem Link.
 */
function schema_v8(PDO $pdo): void {
    foreach (["ALTER TABLE users ADD COLUMN kl_stufe INTEGER NOT NULL DEFAULT 0",
              "ALTER TABLE users ADD COLUMN kl_stand TEXT NOT NULL DEFAULT ''",
              "ALTER TABLE routines ADD COLUMN herkunft TEXT NOT NULL DEFAULT 'eingabe'",
              "ALTER TABLE shares ADD COLUMN name_zeigen INTEGER NOT NULL DEFAULT 0"] as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) { /* Spalte gibt es schon */ }
    }
    // Stufe aus der bereits eingetragenen Klasse nachtragen, Stichtag ist der Kontobeginn
    $up = $pdo->prepare("UPDATE users SET kl_stufe = ?, kl_stand = ? WHERE id = ?");
    foreach ($pdo->query("SELECT id, klasse, created_at FROM users WHERE klasse <> '' AND kl_stufe = 0") as $r) {
        if (preg_match('/^(\d)/', trim((string)$r['klasse']), $m)) {
            $up->execute([(int)$m[1], substr((string)$r['created_at'], 0, 10) ?: date('Y-m-d'), (int)$r['id']]);
        }
    }
    // Die zehn frueher vorangelegten Routinen kennzeichnen - nur unberuehrte
    // sort trennt die Seed-Zeilen (0..90) von selbst angelegten (immer 500)
    $pdo->exec("UPDATE routines SET herkunft = 'beispiel'
        WHERE (name, intervall, minuten, sort) IN (
          ('Kaffeemaschine reinigen','taeglich',10,0),('Spuelmaschine ein-/ausraeumen','taeglich',10,10),
          ('Post holen und verteilen','taeglich',15,20),('Ticketqueue sichten','taeglich',30,30),
          ('Backup-Protokoll pruefen','taeglich',15,40),('Monitoring durchsehen','taeglich',15,50),
          ('Drucker: Papier und Toner','woechentlich',15,60),('Serverraum-Check','woechentlich',15,70),
          ('Patchstand pruefen','woechentlich',30,80),('Lager inventarisieren','monatlich',45,90))
          AND id NOT IN (SELECT routine_id FROM routine_logs)");
    $pdo->exec("INSERT INTO meta (k,v) VALUES ('schema','8') ON CONFLICT(k) DO UPDATE SET v='8'");
}
function fts_trigger_sql(): array {
    $t = [];
    foreach ([
        ['notiz',   'notes',          'datum',                'titel',       "body||' '||tags"],
        ['termin',  'events',         'datum',                'titel',       "beschreibung||' '||stoff"],
        ['aufgabe', 'tasks',          "COALESCE(faellig,'')", 'titel',       'beschreibung'],
        ['bericht', 'report_entries', 'datum',                'text',        "''"],
        ['routine', 'routines',       "''",                   'name',        "''"],
    ] as [$art, $tab, $dat, $tit, $txt]) {
        $vals = "'$art', new.id, new.user_id, new." . str_replace('COALESCE(faellig', 'COALESCE(new.faellig', $dat)
              . ', new.' . $tit . ', ' . preg_replace('/\b(body|tags|beschreibung|stoff)\b/', 'new.$1', $txt);
        $vals = str_replace("new.''", "''", $vals);
        $vals = str_replace("new.COALESCE(new.faellig,'')", "COALESCE(new.faellig,'')", $vals);
        $t[] = "CREATE TRIGGER IF NOT EXISTS fts_{$tab}_i AFTER INSERT ON $tab BEGIN
                  INSERT INTO such(art,ref,uid,datum,titel,text) VALUES ($vals); END";
        $t[] = "CREATE TRIGGER IF NOT EXISTS fts_{$tab}_d AFTER DELETE ON $tab BEGIN
                  DELETE FROM such WHERE art='$art' AND ref=old.id; END";
        $t[] = "CREATE TRIGGER IF NOT EXISTS fts_{$tab}_u AFTER UPDATE ON $tab BEGIN
                  DELETE FROM such WHERE art='$art' AND ref=old.id;
                  INSERT INTO such(art,ref,uid,datum,titel,text) VALUES ($vals); END";
    }
    return $t;
}
function hat_fts(): bool {
    static $f = null;
    if ($f === null) $f = (string)val("SELECT v FROM meta WHERE k='fts'", [], '0') === '1';
    return $f;
}

// --- Stammdaten (global, unveraenderlich) ----------------------------------
function seed_global(PDO $pdo): void {
    $st = $pdo->prepare("INSERT INTO lernfelder (nr,code,titel,jahr,stunden) VALUES (?,?,?,?,80)");
    foreach ([
        [1,'LF 1','Das Unternehmen und die eigene Rolle im Betrieb beschreiben',1],
        [2,'LF 2','Arbeitsplaetze nach Kundenwunsch ausstatten',1],
        [3,'LF 3','Clients in Netzwerke einbinden',1],
        [4,'LF 4','Schutzbedarfsanalyse im eigenen Arbeitsbereich durchfuehren',1],
        [5,'LF 5','Software zur Verwaltung von Daten anpassen',1],
        [6,'LF 6','Serviceanfragen bearbeiten',2],
        [7,'LF 7','Cyber-physische Systeme ergaenzen',2],
        [8,'LF 8','Daten systemuebergreifend bereitstellen',2],
        [9,'LF 9','Netzwerke und Dienste bereitstellen',2],
        [10,'LF 10b','Serverdienste bereitstellen und Administrationsaufgaben automatisieren',3],
        [11,'LF 11b','Betrieb und Sicherheit vernetzter Systeme gewaehrleisten',3],
        [12,'LF 12b','Kundenspezifische Systemintegration durchfuehren',3],
    ] as $r) $st->execute($r);

    // Berufsbildpositionen der FIAusbV 2020 (A gemeinsam, C Systemintegration,
    // B integrativ) plus X fuer organisatorische Zeiten.
    $st = $pdo->prepare("INSERT INTO categories (name,abschnitt,pos_no,farbe,sort) VALUES (?,?,?,?,?)");
    foreach ([
        ['Arbeitsaufgaben planen und durchfuehren','A','A 1','#2563eb',10],
        ['Kunden informieren und beraten','A','A 2','#0d9488',20],
        ['IT-Systeme und Loesungen beurteilen','A','A 3','#7c3aed',30],
        ['IT-Loesungen entwickeln und betreuen','A','A 4','#ea580c',40],
        ['Qualitaetssicherung und Dokumentation','A','A 5','#0891b2',50],
        ['IT-Sicherheit und Datenschutz','A','A 6','#dc2626',60],
        ['Leistungserbringung und Auftragsabschluss','A','A 7','#64748b',70],
        ['IT-Systeme betreiben','A','A 8','#2563eb',80],
        ['Speicherloesungen in Betrieb nehmen','A','A 9','#92400e',90],
        ['Softwareloesungen programmieren','A','A 10','#65a30d',100],
        ['IT-Systeme konzipieren und realisieren','C','C 1','#1d4ed8',110],
        ['Netzwerke installieren und konfigurieren','C','C 2','#0f766e',120],
        ['IT-Systeme administrieren','C','C 3','#6d28d9',130],
        ['Berufsbildung, Arbeits- und Tarifrecht','B','B 1','#78716c',140],
        ['Aufbau und Organisation des Betriebes','B','B 2','#78716c',150],
        ['Arbeitssicherheit und Gesundheitsschutz','B','B 3','#78716c',160],
        ['Umweltschutz','B','B 4','#78716c',170],
        ['Vernetztes Zusammenarbeiten','B','B 5','#78716c',180],
        ['Allgemeine Officetaetigkeiten','X','X 1','#94a3b8',190],
        ['Besprechungen','X','X 2','#94a3b8',200],
        ['Weiterbildung und Selbstlernphase','X','X 3','#94a3b8',210],
        ['Berufsschule','X','X 4','#3b82f6',220],
        ['Ueberbetrieblicher Lehrgang','X','X 5','#3b82f6',230],
        ['Urlaub','X','X 6','#cbd5e1',240],
        ['Krankheit','X','X 7','#cbd5e1',250],
        ['Feiertag','X','X 8','#cbd5e1',260],
    ] as $c) $st->execute($c);

    $id = function (string $n) use ($pdo): int {
        $s = $pdo->prepare("SELECT id FROM categories WHERE name = ?"); $s->execute([$n]);
        return (int)$s->fetchColumn();
    };
    // Stichwort -> Kategorie. Reihenfolge = Prioritaet (frueh schlaegt spaet).
    $regeln = [
        ['Allgemeine Officetaetigkeiten', ['kaffeemaschine','kaffee','spuelmaschine','kueche','teekueche',
            'post','ablage','scannen','kopier','aufraeum','muell','papier','toner','wasser holen','besorgung']],
        ['Leistungserbringung und Auftragsabschluss', ['ticket','helpdesk','servicedesk','stoerung',
            'first level','1st level','support','inventar','uebergabe','abnahme','rechnung']],
        ['Kunden informieren und beraten', ['anwender','einweisung','beratung','telefon','kundengespraech','hotline']],
        ['IT-Systeme und Loesungen beurteilen', ['angebot','bestellung','lieferant','vergleich','marktrecherche']],
        ['IT-Systeme konzipieren und realisieren', ['notebook','laptop','arbeitsplatz','client','hardware',
            'monitor','image','aufgesetzt','drucker','ssd','arbeitsspeicher','docking','ausgerollt']],
        ['Netzwerke installieren und konfigurieren', ['netzwerk','switch','router','vlan','patch','wlan',
            'accesspoint','access point','dhcp','dns','vpn','glasfaser','verkabelung','subnetz']],
        ['IT-Systeme administrieren', ['server','active directory','gpo','gruppenrichtlinie','hyper-v',
            'vmware','esxi','proxmox','virtualis','linux','exchange','benutzer angelegt','user angelegt',
            'postfach','freigabe','berechtigung']],
        ['IT-Systeme betreiben', ['update','patchday','monitoring','wartung','neustart','logfile','usv']],
        ['Speicherloesungen in Betrieb nehmen', ['backup','datensicherung','restore','veeam','nas','san','raid']],
        ['IT-Sicherheit und Datenschutz', ['firewall','sicherheit','virus','malware','phishing','dsgvo',
            'datenschutz','zertifikat','mfa','passwort','verschluessel','haertung']],
        ['Softwareloesungen programmieren', ['powershell','skript','script','bash','python','ansible',
            'automatis','sql','datenbank','api']],
        ['Qualitaetssicherung und Dokumentation', ['dokumentation','doku','wiki','handbuch','anleitung','test','protokoll']],
        ['Besprechungen', ['meeting','besprechung','daily','jour fixe','abstimmung','teamrunde']],
        ['Vernetztes Zusammenarbeiten', ['teams','sharepoint','confluence','ticketsystem gepflegt']],
        ['Arbeitsaufgaben planen und durchfuehren', ['projekt','planung','konzept','abstimmen mit']],
        ['Arbeitssicherheit und Gesundheitsschutz', ['arbeitssicherheit','unterweisung','ersthelfer','brandschutz']],
        ['Umweltschutz', ['entsorgung','altgeraet','recycling','energiespar']],
        ['Berufsbildung, Arbeits- und Tarifrecht', ['betriebsrat','tarif','ausbildungsplan','beurteilungsgespraech']],
        ['Berufsschule', ['berufsschule','unterricht','blockwoche','schule']],
        ['Ueberbetrieblicher Lehrgang', ['ueba','lehrgang']],
        ['Weiterbildung und Selbstlernphase', ['seminar','selbststudium','cisco','lernen','pruefungsvorbereitung','schulung']],
        ['Urlaub', ['urlaub']],
        ['Krankheit', ['krank']],
        ['Feiertag', ['feiertag']],
    ];
    $st = $pdo->prepare("INSERT INTO category_rules (keyword,category_id,prio) VALUES (?,?,?)");
    foreach ($regeln as $gi => [$kat, $worte]) {
        $cid = $id($kat);
        foreach ($worte as $w) if ($cid) $st->execute([$w, $cid, 100 - $gi]);
    }
}

/** Legt fuer ein neues Konto die Faecher der Stundentafel an - sonst nichts. */
function seed_faecher(int $uid): void {
    // Zwoelf Lernfelder, zwoelf Farben - sonst ist ein Fach an der Farbe nicht erkennbar
    $farben = ['#0071e3','#00a09a','#7c3aed','#ea580c','#0891b2','#dc2626',
               '#2d7a2d','#b8860b','#c026d3','#0f5fa8','#a2560b','#5856d6'];
    foreach (all("SELECT * FROM lernfelder ORDER BY nr") as $i => $l) {
        ins('subjects', ['user_id' => $uid, 'name' => $l['code'] . ' ' . $l['titel'],
            'short' => $l['code'], 'lf_no' => (int)$l['nr'], 'sort' => (int)$l['nr'] * 10,
            'color' => $farben[$i % count($farben)]]);
    }
    foreach (['Deutsch', 'Englisch', 'Politik und Gesellschaft', 'Religion / Ethik', 'Sport'] as $i => $n) {
        ins('subjects', ['user_id' => $uid, 'name' => $n, 'short' => mb_substr($n, 0, 2),
            'sort' => 200 + $i, 'color' => '#64748b']);
    }
}

// --- Helfer ----------------------------------------------------------------
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $p = '', array $q = []): string {
    $a = $p === '' ? [] : ['p' => $p];
    $a += $q;
    return base_path() . ($a ? '?' . http_build_query($a) : '');
}
function redirect(string $to): void { header('Location: ' . $to, true, 303); exit; }
/** Nur eigene Adressen als Rueckweg - sonst waere es eine offene Weiterleitung. */
function zurueck(string $z, string $sonst): string {
    return ($z !== '' && str_starts_with($z, base_path()) && !str_starts_with($z, '//')
            && !preg_match('/[\r\n]/', $z)) ? $z : $sonst;
}
function post(string $k, string $d = ''): string { return is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : $d; }
function postn(string $k) { $v = $_POST[$k] ?? null; return ($v === null || $v === '') ? null : $v; }
function get(string $k, string $d = ''): string { return is_string($_GET[$k] ?? null) ? trim($_GET[$k]) : $d; }
function inull($v): ?int { return ($v === null || $v === '' || $v === '0') ? null : (int)$v; }
function today(): string { return date('Y-m-d'); }
/** Ganze Tage von $von bis $bis - ueber den Kalender, damit die Sommerzeit nicht um einen Tag verschiebt. */
function tage(string $von, string $bis): int {
    $a = new DateTimeImmutable(substr($von, 0, 10)); $b = new DateTimeImmutable(substr($bis, 0, 10));
    $d = $a->diff($b); return $d->invert ? -$d->days : $d->days;
}
function isodate(string $s): bool {
    return (bool)preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}
function flash(string $m, string $t = 'ok'): void { $_SESSION['flash'][] = [$t, $m]; }
function take_flash(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }
function lines(string $s): array {
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $s) ?: []), fn($x) => $x !== ''));
}
function de_names(string $s): string {
    static $m = ['Monday'=>'Montag','Tuesday'=>'Dienstag','Wednesday'=>'Mittwoch','Thursday'=>'Donnerstag',
        'Friday'=>'Freitag','Saturday'=>'Samstag','Sunday'=>'Sonntag','Mon'=>'Mo','Tue'=>'Di','Wed'=>'Mi',
        'Thu'=>'Do','Fri'=>'Fr','Sat'=>'Sa','Sun'=>'So','January'=>'Januar','February'=>'Februar',
        'March'=>'Maerz','May'=>'Mai','June'=>'Juni','July'=>'Juli','October'=>'Oktober','December'=>'Dezember',
        'Mar'=>'Mrz','Oct'=>'Okt','Dec'=>'Dez'];
    return strtr($s, $m);
}
function dt(?string $iso, string $f = 'd.m.Y'): string {
    if (!$iso) return '';
    $t = strtotime($iso);
    return $t ? de_names(date($f, $t)) : $iso;
}
function wd(int $i): string { return ['','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag','Sonntag'][$i] ?? ''; }
function num(float $f, int $d = 2): string { return number_format($f, $d, ',', '.'); }
function abs_url(string $path): string {
    return (is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? APP_DOMAIN) . $path;
}

// --- Sicherheit ------------------------------------------------------------
function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . h(csrf()) . '">'; }
function csrf_check(): void {
    $soll = (string)($_SESSION['csrf'] ?? '');
    if ($soll === '' || !isset($_POST['_csrf']) || !is_string($_POST['_csrf'])
        || !hash_equals($soll, $_POST['_csrf'])) {
        http_response_code(419);
        exit('<!doctype html><meta charset="utf-8"><p style="font:15px system-ui;padding:2rem">'
           . 'Sitzung abgelaufen. Seite neu laden.</p>');
    }
}
function gc_maybe(): void {
    if (random_int(1, 50) !== 1) return;
    try {
        q("DELETE FROM login_attempts WHERE ts < ?", [time() - 7 * 86400]);
        q("DELETE FROM ratelimit WHERE started < ?", [time() - 86400]);
        q("DELETE FROM sessions WHERE last_seen < ?", [time() - 30 * 86400]);
    } catch (Throwable $e) {}
}
function rl(string $key, int $max, int $window): bool {
    $now = time();
    $r = one("SELECT cnt, started FROM ratelimit WHERE k = ?", [$key]);
    if (!$r || ($now - (int)$r['started']) > $window) {
        q("INSERT INTO ratelimit (k,cnt,started) VALUES (:k,1,:t)
           ON CONFLICT(k) DO UPDATE SET cnt = 1, started = :t2", ['k'=>$key,'t'=>$now,'t2'=>$now]);
        return true;
    }
    if ((int)$r['cnt'] >= $max) return false;
    q("UPDATE ratelimit SET cnt = cnt + 1 WHERE k = ?", [$key]);
    return true;
}
function pw_hash(string $pw): string {
    return defined('PASSWORD_ARGON2ID')
        ? password_hash($pw, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2])
        : password_hash($pw, PASSWORD_DEFAULT, ['cost' => 12]);
}
function pw_problems(string $pw, string $user = ''): array {
    $p = [];
    if (mb_strlen($pw) < PW_MIN_LEN) $p[] = 'mindestens ' . PW_MIN_LEN . ' Zeichen';
    if (mb_strlen($pw) > 200) $p[] = 'hoechstens 200 Zeichen';
    $k = (preg_match('/[a-z]/u', $pw) ? 1 : 0) + (preg_match('/[A-Z]/u', $pw) ? 1 : 0)
       + (preg_match('/\d/', $pw) ? 1 : 0) + (preg_match('/[^\p{L}\d]/u', $pw) ? 1 : 0);
    if ($k < 3) $p[] = 'drei von vier Zeichenarten';
    $low = mb_strtolower($pw);
    foreach (['passwort','password','123456','qwertz','qwerty','abc123','admin','azubi','schule',
              'fachinformatiker','systemintegration','willkommen','sommer','winter','fisi','bsfisi',
              'letmein','master','hallo123','test1234','1234567890','geheim'] as $b) {
        if (str_contains($low, $b)) { $p[] = 'kein Wort wie "' . $b . '"'; break; }
    }
    if ($user && mb_stripos($pw, $user) !== false) $p[] = 'nicht der Benutzername';
    return $p;
}
function rand_code(int $bytes = 8): string {
    return implode('-', str_split(strtr(strtoupper(bin2hex(random_bytes($bytes))), ['0'=>'W','1'=>'X']), 4));
}

// --- TOTP (RFC 6238), reines PHP ------------------------------------------
function b32_encode(string $bin): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = ''; $bits = 0; $acc = 0;
    for ($i = 0, $n = strlen($bin); $i < $n; $i++) {
        $acc = ($acc << 8) | ord($bin[$i]); $bits += 8;
        while ($bits >= 5) { $bits -= 5; $out .= $alpha[($acc >> $bits) & 31]; }
    }
    if ($bits > 0) $out .= $alpha[($acc << (5 - $bits)) & 31];
    return $out;
}
function b32_decode(string $s): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $s) ?? '');
    $out = ''; $bits = 0; $acc = 0;
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $v = strpos($alpha, $s[$i]); if ($v === false) continue;
        $acc = ($acc << 5) | $v; $bits += 5;
        if ($bits >= 8) { $bits -= 8; $out .= chr(($acc >> $bits) & 255); }
    }
    return $out;
}
function totp_code(string $secretB32, ?int $slice = null, int $digits = 6, int $period = 30): string {
    $key = b32_decode($secretB32);
    $slice ??= (int)floor(time() / $period);
    $hash = hash_hmac('sha1', pack('N*', 0, $slice), $key, true);
    $off  = ord($hash[19]) & 0x0f;
    $val  = ((ord($hash[$off]) & 0x7f) << 24) | (ord($hash[$off+1]) << 16)
          | (ord($hash[$off+2]) << 8) | ord($hash[$off+3]);
    return str_pad((string)($val % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}
function totp_verify(string $secretB32, string $code, int $window = 1): bool {
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== 6) return false;
    $now = (int)floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secretB32, $now + $i), $code)) return true;
    }
    return false;
}

// --- QR-Code (Byte-Modus, ECC M) - eigenstaendig, gegen zwei Referenz-
//     implementierungen geprueft (Version 1-19, alle 8 Masken).
final class QR
{
    /** Level M: [EC-Codewords je Block, [[Bloecke, Datencodewords], ...]] */
    private const ECM = [
        1=>[10,[[1,16]]],          2=>[16,[[1,28]]],          3=>[26,[[1,44]]],
        4=>[18,[[2,32]]],          5=>[24,[[2,43]]],          6=>[16,[[4,27]]],
        7=>[18,[[4,31]]],          8=>[22,[[2,38],[2,39]]],   9=>[22,[[3,36],[2,37]]],
        10=>[26,[[4,43],[1,44]]],  11=>[30,[[1,50],[4,51]]],  12=>[22,[[6,36],[2,37]]],
        13=>[22,[[8,37],[1,38]]],  14=>[24,[[4,40],[5,41]]],  15=>[24,[[5,41],[5,42]]],
        16=>[28,[[7,45],[3,46]]],  17=>[28,[[10,46],[1,47]]], 18=>[26,[[9,43],[4,44]]],
        19=>[26,[[3,44],[11,45]]], 20=>[26,[[3,41],[13,42]]],
    ];
    private const ALIGN = [
        1=>[], 2=>[6,18], 3=>[6,22], 4=>[6,26], 5=>[6,30], 6=>[6,34], 7=>[6,22,38],
        8=>[6,24,42], 9=>[6,26,46], 10=>[6,28,50], 11=>[6,30,54], 12=>[6,32,58],
        13=>[6,34,62], 14=>[6,26,46,66], 15=>[6,26,48,70], 16=>[6,26,50,74],
        17=>[6,30,54,78], 18=>[6,30,56,82], 19=>[6,30,58,86], 20=>[6,34,62,90],
    ];
    private static array $exp = [];
    private static array $log = [];

    private static function gf(): void {
        if (self::$exp) return;
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x; self::$log[$x] = $i;
            $x <<= 1; if ($x & 0x100) $x ^= 0x11d;
        }
        for ($i = 255; $i < 512; $i++) self::$exp[$i] = self::$exp[$i - 255];
    }
    private static function mul(int $a, int $b): int {
        if ($a === 0 || $b === 0) return 0;
        self::gf();
        return self::$exp[self::$log[$a] + self::$log[$b]];
    }
    private static function genPoly(int $n): array {
        self::gf();
        $g = [1];
        for ($i = 0; $i < $n; $i++) {
            $next = array_fill(0, count($g) + 1, 0);
            foreach ($g as $k => $c) {
                $next[$k]     ^= self::mul($c, 1);
                $next[$k + 1] ^= self::mul($c, self::$exp[$i]);
            }
            $g = $next;
        }
        return $g;
    }
    private static function ecc(array $data, int $n): array {
        $g   = self::genPoly($n);
        $res = array_merge($data, array_fill(0, $n, 0));
        for ($i = 0, $c = count($data); $i < $c; $i++) {
            $f = $res[$i];
            if ($f === 0) continue;
            foreach ($g as $k => $gc) $res[$i + $k] ^= self::mul($gc, $f);
        }
        return array_slice($res, count($data), $n);
    }
    private static function bch(int $data, int $poly, int $bits): int {
        $d = $data << $bits;
        $pl = strlen(decbin($poly)) - 1;
        while ((strlen(decbin($d)) - 1) >= $pl) {
            $d ^= $poly << ((strlen(decbin($d)) - 1) - $pl);
        }
        return $d;
    }

    /** @return array<int,array<int,int>> Matrix [y][x] mit 0/1 */
    public static function matrix(string $text): array
    {
        $len = strlen($text);
        $ver = 0;
        foreach (self::ECM as $v => [$ec, $blocks]) {
            $cap = 0; foreach ($blocks as [$nb, $dc]) $cap += $nb * $dc;
            $cc  = ($v <= 9) ? 8 : 16;
            if (intdiv(4 + $cc + 8 * $len + 7, 8) <= $cap) { $ver = $v; break; }
        }
        if (!$ver) throw new RuntimeException('QR: Text zu lang');
        [$ecCount, $blocks] = self::ECM[$ver];
        $totalData = 0; foreach ($blocks as [$nb, $dc]) $totalData += $nb * $dc;
        $ccBits = ($ver <= 9) ? 8 : 16;

        // --- Bitstrom ---------------------------------------------------
        $bits = '0100' . str_pad(decbin($len), $ccBits, '0', STR_PAD_LEFT);
        for ($i = 0; $i < $len; $i++) $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
        $cap = $totalData * 8;
        $bits .= str_repeat('0', min(4, $cap - strlen($bits)));
        if (strlen($bits) % 8) $bits .= str_repeat('0', 8 - strlen($bits) % 8);
        $pad = ['11101100', '00010001']; $i = 0;
        while (strlen($bits) < $cap) { $bits .= $pad[$i++ % 2]; }
        $cw = [];
        for ($i = 0; $i < $cap; $i += 8) $cw[] = bindec(substr($bits, $i, 8));

        // --- Bloecke + Fehlerkorrektur ----------------------------------
        $dataBlocks = []; $eccBlocks = []; $off = 0;
        foreach ($blocks as [$nb, $dc]) {
            for ($b = 0; $b < $nb; $b++) {
                $blk = array_slice($cw, $off, $dc); $off += $dc;
                $dataBlocks[] = $blk;
                $eccBlocks[]  = self::ecc($blk, $ecCount);
            }
        }
        $final = [];
        $maxD = max(array_map('count', $dataBlocks));
        for ($i = 0; $i < $maxD; $i++) foreach ($dataBlocks as $b) if (isset($b[$i])) $final[] = $b[$i];
        for ($i = 0; $i < $ecCount; $i++) foreach ($eccBlocks as $b) $final[] = $b[$i];

        // --- Matrix -----------------------------------------------------
        $size = 17 + 4 * $ver;
        $m    = array_fill(0, $size, array_fill(0, $size, 0));
        $fn   = array_fill(0, $size, array_fill(0, $size, false));
        $put  = function (int $y, int $x, int $v) use (&$m, &$fn, $size) {
            if ($y < 0 || $x < 0 || $y >= $size || $x >= $size) return;
            $m[$y][$x] = $v; $fn[$y][$x] = true;
        };
        $finder = function (int $oy, int $ox) use ($put) {
            for ($y = -1; $y <= 7; $y++) for ($x = -1; $x <= 7; $x++) {
                $on = ($y >= 0 && $y <= 6 && ($x === 0 || $x === 6))
                   || ($x >= 0 && $x <= 6 && ($y === 0 || $y === 6))
                   || ($y >= 2 && $y <= 4 && $x >= 2 && $x <= 4);
                $put($oy + $y, $ox + $x, $on ? 1 : 0);
            }
        };
        $finder(0, 0); $finder(0, $size - 7); $finder($size - 7, 0);
        for ($i = 8; $i < $size - 8; $i++) { $put(6, $i, $i % 2 === 0 ? 1 : 0); $put($i, 6, $i % 2 === 0 ? 1 : 0); }
        $ac = self::ALIGN[$ver];
        foreach ($ac as $ry) foreach ($ac as $cx) {
            if (($ry === 6 && $cx === 6) || ($ry === 6 && $cx === $size - 7) || ($ry === $size - 7 && $cx === 6)) continue;
            for ($y = -2; $y <= 2; $y++) for ($x = -2; $x <= 2; $x++) {
                $on = (abs($y) === 2 || abs($x) === 2 || ($y === 0 && $x === 0));
                $put($ry + $y, $cx + $x, $on ? 1 : 0);
            }
        }
        for ($i = 0; $i <= 8; $i++) { if ($i === 6) continue; $put(8, $i, 0); $put($i, 8, 0); }
        for ($i = 0; $i < 8; $i++) { $put($size - 1 - $i, 8, 0); $put(8, $size - 1 - $i, 0); }
        $put($size - 8, 8, 1);
        if ($ver >= 7) {
            $vi = ($ver << 12) | self::bch($ver, 0x1F25, 12);
            for ($i = 0; $i < 18; $i++) {
                $b = ($vi >> $i) & 1; $a = intdiv($i, 3); $c = $i % 3;
                $put($size - 11 + $c, $a, $b); $put($a, $size - 11 + $c, $b);
            }
        }

        // --- Daten einweben ---------------------------------------------
        $bitstr = ''; foreach ($final as $b) $bitstr .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        $pos = 0; $up = true; $n = strlen($bitstr);
        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) $col = 5;
            for ($i = 0; $i < $size; $i++) {
                $y = $up ? ($size - 1 - $i) : $i;
                foreach ([$col, $col - 1] as $x) {
                    if ($fn[$y][$x]) continue;
                    $m[$y][$x] = ($pos < $n && $bitstr[$pos] === '1') ? 1 : 0;
                    $pos++;
                }
            }
            $up = !$up;
        }

        // --- Maske waehlen ----------------------------------------------
        $best = null; $bestPen = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $t = $m;
            for ($y = 0; $y < $size; $y++) for ($x = 0; $x < $size; $x++) {
                if ($fn[$y][$x]) continue;
                if (self::maskBit($mask, $y, $x)) $t[$y][$x] ^= 1;
            }
            $fmt = self::bch(0b00 << 3 | $mask, 0x537, 10);
            $fmt = ((0b00 << 3 | $mask) << 10 | $fmt) ^ 0x5412;
            for ($i = 0; $i <= 5; $i++)  $t[$i][8]              = ($fmt >> $i) & 1;
            $t[7][8] = ($fmt >> 6) & 1;  $t[8][8] = ($fmt >> 7) & 1;  $t[8][7] = ($fmt >> 8) & 1;
            for ($i = 9; $i <= 14; $i++) $t[8][14 - $i]          = ($fmt >> $i) & 1;
            for ($i = 0; $i <= 7; $i++)  $t[8][$size - 1 - $i]   = ($fmt >> $i) & 1;
            for ($i = 8; $i <= 14; $i++) $t[$size - 15 + $i][8]  = ($fmt >> $i) & 1;
            $t[$size - 8][8] = 1;
            $p = self::penalty($t, $size);
            if ($p < $bestPen) { $bestPen = $p; $best = $t; }
        }
        return $best;
    }
    private static function maskBit(int $mask, int $i, int $j): bool {
        return match ($mask) {
            0 => ($i + $j) % 2 === 0,
            1 => $i % 2 === 0,
            2 => $j % 3 === 0,
            3 => ($i + $j) % 3 === 0,
            4 => (intdiv($i, 2) + intdiv($j, 3)) % 2 === 0,
            5 => (($i * $j) % 2) + (($i * $j) % 3) === 0,
            6 => (((($i * $j) % 2) + (($i * $j) % 3)) % 2) === 0,
            7 => (((($i + $j) % 2) + (($i * $j) % 3)) % 2) === 0,
        };
    }
    private static function penalty(array $m, int $size): int
    {
        $pen = 0;
        // Regel 1
        for ($d = 0; $d < 2; $d++) {
            for ($a = 0; $a < $size; $a++) {
                $run = 1;
                for ($b = 1; $b < $size; $b++) {
                    $cur = $d ? $m[$b][$a] : $m[$a][$b];
                    $prv = $d ? $m[$b-1][$a] : $m[$a][$b-1];
                    if ($cur === $prv) { $run++; }
                    else { if ($run >= 5) $pen += 3 + ($run - 5); $run = 1; }
                }
                if ($run >= 5) $pen += 3 + ($run - 5);
            }
        }
        // Regel 2
        for ($y = 0; $y < $size - 1; $y++) for ($x = 0; $x < $size - 1; $x++) {
            $v = $m[$y][$x];
            if ($v === $m[$y][$x+1] && $v === $m[$y+1][$x] && $v === $m[$y+1][$x+1]) $pen += 3;
        }
        // Regel 3
        $p1 = [1,0,1,1,1,0,1,0,0,0,0]; $p2 = [0,0,0,0,1,0,1,1,1,0,1];
        for ($a = 0; $a < $size; $a++) {
            for ($b = 0; $b <= $size - 11; $b++) {
                $rowSeg = []; $colSeg = [];
                for ($k = 0; $k < 11; $k++) { $rowSeg[] = $m[$a][$b+$k]; $colSeg[] = $m[$b+$k][$a]; }
                if ($rowSeg === $p1 || $rowSeg === $p2) $pen += 40;
                if ($colSeg === $p1 || $colSeg === $p2) $pen += 40;
            }
        }
        // Regel 4
        $dark = 0; foreach ($m as $row) $dark += array_sum($row);
        $ratio = $dark * 100 / ($size * $size);
        $pen += (int)(floor(abs($ratio - 50) / 5) * 10);
        return $pen;
    }
    public static function svg(string $text, int $scale = 5, int $quiet = 4): string
    {
        $m = self::matrix($text);
        $n = count($m);
        $dim = ($n + 2 * $quiet) * $scale;
        $path = '';
        for ($y = 0; $y < $n; $y++) {
            $x = 0;
            while ($x < $n) {
                if ($m[$y][$x] === 1) {
                    $s = $x; while ($x < $n && $m[$y][$x] === 1) $x++;
                    $path .= 'M' . (($s + $quiet) * $scale) . ' ' . (($y + $quiet) * $scale)
                           . 'h' . (($x - $s) * $scale) . 'v' . $scale . 'h-' . (($x - $s) * $scale) . 'z';
                } else { $x++; }
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $dim . '" height="' . $dim . '" '
             . 'viewBox="0 0 ' . $dim . ' ' . $dim . '" role="img" aria-label="QR-Code">'
             . '<rect width="' . $dim . '" height="' . $dim . '" fill="#ffffff"/>'
             . '<path d="' . $path . '" fill="#000000"/></svg>';
    }
}

// --- Anmeldung -------------------------------------------------------------
function me(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    $u = null;
    if (!empty($_SESSION['uid'])) $u = one("SELECT * FROM users WHERE id = ?", [(int)$_SESSION['uid']]);
    return $u;
}
function need_login(): array {
    $u = me();
    if (!$u) { $_SESSION['nach'] = $_SERVER['REQUEST_URI'] ?? ''; redirect(url('login')); }
    return $u;
}
function session_check(): void {
    if (empty($_SESSION['uid'])) return;
    $now = time();
    $r = one("SELECT * FROM sessions WHERE sid_hash = ?", [hash('sha256', session_id())]);
    if (!$r || (int)$r['revoked'] === 1) logout('Sitzung beendet.');
    if (($now - (int)$r['last_seen']) > SESSION_IDLE) logout('Abgemeldet wegen Inaktivitaet.');
    if (($now - (int)$r['created_at']) > SESSION_ABS) logout('Sitzungsdauer abgelaufen.');
    if (!hash_equals($r['ua'], substr(hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 32))) logout('Sitzung ungueltig.');
    q("UPDATE sessions SET last_seen = ?, ip = ? WHERE id = ?", [$now, client_ip(), $r['id']]);
}
function login_user(array $u): void {
    session_regenerate_id(true);
    $_SESSION = ['uid' => (int)$u['id'], 'csrf' => bin2hex(random_bytes(32))];
    $now = time();
    q("DELETE FROM sessions WHERE user_id = ? AND (last_seen < ? OR revoked = 1)", [(int)$u['id'], $now - SESSION_ABS]);
    ins('sessions', ['user_id' => (int)$u['id'], 'sid_hash' => hash('sha256', session_id()),
        'ua' => substr(hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 32),
        'ip' => client_ip(), 'created_at' => $now, 'last_seen' => $now]);
    upd('users', ['last_login' => date('Y-m-d H:i:s'), 'last_ip' => client_ip(),
        'failed' => 0, 'locked_until' => 0], 'id = :id', ['id' => (int)$u['id']]);
}
function logout(string $msg = ''): void {
    if (!empty($_SESSION['uid'])) q("UPDATE sessions SET revoked = 1 WHERE sid_hash = ?", [hash('sha256', session_id())]);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy(); session_start(); session_regenerate_id(true);
    if ($msg) flash($msg, 'warn');
    redirect(url('login'));
}
function reg_code(): string {
    if (REGISTRIER_CODE !== '') return REGISTRIER_CODE;
    $f = DATA_DIR . '/REGISTRIERUNG.txt';
    if (!is_file($f)) { file_put_contents($f, rand_code(8) . "\n"); @chmod($f, 0600); }
    return trim((string)file_get_contents($f));
}
function erstes_konto(): bool { return (int)val("SELECT COUNT(*) FROM users", [], 0) === 0; }

// --- Zeitraeume ------------------------------------------------------------
function iso_week(int $y, int $w): array {
    $mo = (new DateTimeImmutable())->setISODate($y, $w, 1)->setTime(0, 0);
    return [$mo->format('Y-m-d'), $mo->modify('+6 days')->format('Y-m-d')];
}
function periode_of(string $datum, string $art): string {
    $t = new DateTimeImmutable($datum);
    return $art === 'monat' ? $t->format('Y-m') : $t->format('o-\WW');
}
function periode_ok(string $p, string $art): bool {
    if ($art === 'monat') return (bool)preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $p);
    if (!preg_match('/^(\d{4})-W(\d{2})$/', $p, $m)) return false;
    // Der 28.12. liegt immer in der letzten ISO-Woche des Jahres
    return (int)$m[2] >= 1 && (int)$m[2] <= (int)date('W', strtotime($m[1] . '-12-28'));
}
function periode_range(string $p, string $art): array {
    if ($art === 'monat') {
        $f = new DateTimeImmutable($p . '-01');
        return [$f->format('Y-m-d'), $f->modify('last day of this month')->format('Y-m-d')];
    }
    [$y, $w] = explode('-W', $p);
    return iso_week((int)$y, (int)$w);
}
function periode_label(string $p, string $art): string {
    if ($art === 'monat') return dt($p . '-01', 'F Y');
    [, $w] = explode('-W', $p);
    [$von, $bis] = periode_range($p, $art);
    return 'KW ' . (int)$w . ' · ' . dt($von, 'd.m.') . '–' . dt($bis, 'd.m.Y');
}
function periode_shift(string $p, string $art, int $d): string {
    [$von] = periode_range($p, $art);
    return periode_of((new DateTimeImmutable($von))
        ->modify(($d >= 0 ? '+' : '-') . abs($d) . ($art === 'monat' ? ' month' : ' week'))->format('Y-m-d'), $art);
}
/**
 * Beginn des Schuljahres, das im Kalenderjahr $jahr anfaengt.
 * Bevorzugt der Tag nach den Sommerferien aus dem importierten Blockplan,
 * sonst der 15. September.
 */
function schuljahr_start(int $uid, int $jahr, bool $frisch = false): string {
    static $cache = [];
    if ($frisch) $cache = [];
    $k = $uid . ':' . $jahr;
    if (isset($cache[$k])) return $cache[$k];
    $b = one("SELECT bis FROM blocks WHERE user_id = ? AND art = 'ferien'
              AND label LIKE '%Sommerferien%' AND bis BETWEEN ? AND ?
              ORDER BY bis DESC LIMIT 1", [$uid, $jahr . '-07-01', $jahr . '-10-15']);
    $d = $b ? date('Y-m-d', strtotime($b['bis'] . ' +1 day')) : $jahr . '-09-15';
    return $cache[$k] = $d;
}
/**
 * Ausbildungsstand zum Stichtag.
 *  jahr   – Ausbildungsjahr nach dem Vertragsbeginn (zaehlt am Jahrestag hoch)
 *  stufe  – Klassenstufe, die mit dem Schuljahr hochgeht
 *  wechsel/stufe_neu – wann die Klasse das naechste Mal wechselt
 *  beginn – Vertragsbeginn, eingetragen oder aus der Klassenstufe abgeleitet
 * @return array{jahr:int,stufe:int,wechsel:?string,stufe_neu:?int,jahrestag:?string,ende:?string,beginn:string}
 */
function ausbildungsstand(array $u, string $datum = ''): array {
    $datum = $datum ?: today();
    $uid = (int)($u['id'] ?? 0);
    $leer = ['jahr' => 1, 'stufe' => 1, 'wechsel' => null, 'stufe_neu' => null,
             'jahrestag' => null, 'ende' => null, 'beginn' => ''];
    // Eingetragener Beginn schlaegt alles. Fehlt er, traegt die Klassenstufe:
    // eine 2 im Klassennamen heisst, das Schuljahr davor war das erste.
    $start = '';
    if (!empty($u['start']) && isodate(substr((string)$u['start'], 0, 10))) {
        $start = substr((string)$u['start'], 0, 10);
    } elseif ((int)($u['kl_stufe'] ?? 0) > 0) {
        // Feste Grenze 15.09., nicht schuljahr_start() - sonst schoebe ein importierter
        // Ferienblock vor dem 15.09. den Beginn um ein Jahr und das Ausbildungsjahr liefe rueckwaerts.
        $s0    = max(1, min(3, (int)$u['kl_stufe']));
        $stand = isodate((string)($u['kl_stand'] ?? '')) ? (string)$u['kl_stand'] : $datum;
        $y0    = (int)substr($stand, 0, 4);
        if ($stand < $y0 . '-09-15') $y0--;
        $start = ($y0 - ($s0 - 1)) . '-09-15';
    }
    if ($start === '') return $leer;
    // Regeldauer drei Jahre, bei eingetragenem Ende die tatsaechliche Dauer
    $max = 3;
    if (!empty($u['ende']) && isodate(substr((string)$u['ende'], 0, 10))) {
        $max = (int)max(1, min(4, ceil((strtotime((string)$u['ende']) - strtotime($start)) / 86400 / 365.25)));
    }

    // Ausbildungsjahr: Jahrestag des Vertragsbeginns
    $jahr = 1; $jahrestag = null;
    for ($k = 1; $k <= 5; $k++) {
        $tag = date('Y-m-d', strtotime($start . ' +' . $k . ' year'));
        if ($tag <= $datum) $jahr = $k + 1;
        elseif ($jahrestag === null && $k + 1 <= $max) $jahrestag = $tag;
    }
    $jahr = max(1, min($max, $jahr));

    // Klassenstufe: jeder Schuljahresbeginn nach dem ersten hebt die Stufe.
    // Der erste Beginn kurz nach Vertragsstart ist der Eintritt, kein Aufstieg.
    $stufe = 1; $wechsel = null; $neu = null; $erste = true;
    for ($y = (int)substr($start, 0, 4); $y <= (int)substr($datum, 0, 4) + 1; $y++) {
        $grenze = schuljahr_start($uid, $y);
        if ($grenze <= $start) continue;
        if ($erste) {
            $erste = false;
            if ((strtotime($grenze) - strtotime($start)) / 86400 <= 100) continue;
        }
        if ($grenze <= $datum) $stufe++;
        elseif ($wechsel === null && $stufe + 1 <= $max) { $wechsel = $grenze; $neu = $stufe + 1; }
    }
    $stufe = max(1, min($max, $stufe));
    return ['jahr' => $jahr, 'stufe' => $stufe, 'wechsel' => $wechsel, 'stufe_neu' => $neu,
            'jahrestag' => $jahrestag, 'ende' => $u['ende'] ?: null, 'beginn' => $start];
}
function lehrjahr(array $u, string $datum): int {
    return ausbildungsstand($u, $datum)['jahr'];
}
/** Eine Zeile, die den Stand zeigt, ohne dass jemand nachrechnen muss. */
function stand_text(array $u, string $datum = ''): string {
    $st = ausbildungsstand($u, $datum);
    $jetzt = klasse_name($u, $datum);
    $t = [];
    if ($jetzt !== '') $t[] = '<b>' . h($jetzt) . '</b>';
    $t[] = $st['jahr'] . '. Ausbildungsjahr';
    if ($st['wechsel'] !== null) {
        $spaeter = ($u['kl_kuerzel'] ?? '') !== ''
            ? klasse_name($u, $st['wechsel'])
            : (int)$st['stufe_neu'] . '. Klasse';
        $t[] = '<span class="mu2">ab ' . h(dt($st['wechsel'])) . ' ' . h($spaeter) . '</span>';
    } elseif ($st['jahrestag'] !== null) {
        $t[] = '<span class="mu2">ab ' . h(dt($st['jahrestag'])) . ' im ' . ($st['jahr'] + 1) . '. Jahr</span>';
    }
    return implode(' · ', $t);
}
/** Klassenbezeichnung aus Bestandteilen und abgeleiteter Klassenstufe. */
function klasse_name(array $u, string $datum = ''): string {
    if (($u['kl_kuerzel'] ?? '') === '') return (string)($u['klasse'] ?? '');
    if ((int)($u['verkuerzt'] ?? 0) === 1) return 'W' . $u['kl_kuerzel'] . $u['kl_nr'] . (int)$u['zeitgruppe'];
    $st = ausbildungsstand($u, $datum);
    return min(3, (int)$st['stufe']) . $u['kl_kuerzel'] . $u['kl_nr'] . (int)$u['zeitgruppe'];
}

// --- Berichtsheft ----------------------------------------------------------
function kategorie_zu(string $text): ?int {
    static $regeln = null;
    if ($regeln === null) $regeln = all("SELECT keyword, category_id, prio FROM category_rules");
    $hay = ' ' . mb_strtolower(strtr($text, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss'])) . ' ';
    $best = null; $score = -1;
    foreach ($regeln as $r) {
        $kw = $r['keyword'];
        if ($kw !== '' && str_contains($hay, $kw)) {
            $s = (int)$r['prio'] * 1000 + mb_strlen($kw);
            if ($s > $score) { $score = $s; $best = (int)$r['category_id']; }
        }
    }
    return $best;
}
function report_nr(int $uid, string $von): int {
    return max(1, (int)val("SELECT COUNT(*) FROM reports WHERE user_id = ? AND von <= ?", [$uid, $von], 0));
}
function report_get(int $uid, string $art, string $periode): array {
    $r = one("SELECT * FROM reports WHERE user_id = ? AND art = ? AND periode = ?", [$uid, $art, $periode]);
    if ($r) { $r['nr'] = report_nr($uid, $r['von']); return $r; }
    [$von, $bis] = periode_range($periode, $art);
    $u = one("SELECT * FROM users WHERE id = ?", [$uid]) ?? [];
    return ['id' => 0, 'user_id' => $uid, 'art' => $art, 'periode' => $periode, 'von' => $von, 'bis' => $bis,
        'nr' => report_nr($uid, $von), 'jahr' => lehrjahr($u, $von), 'abteilung' => $u['abteilung'] ?? '',
        'schule_text' => '', 'sonstiges' => '', 'status' => 'offen', 'fertig_am' => null];
}
/** Abteilung aus dem Einsatzplan zum Stichtag. */
function einsatz_am(int $uid, string $datum): string {
    $r = one("SELECT abteilung FROM einsaetze WHERE user_id = ? AND von <= ?
              AND (bis = '' OR bis >= ?) ORDER BY von DESC LIMIT 1", [$uid, $datum, $datum]);
    return $r ? (string)$r['abteilung'] : '';
}
function report_ensure(int $uid, string $art, string $periode): array {
    $r = one("SELECT * FROM reports WHERE user_id = ? AND art = ? AND periode = ?", [$uid, $art, $periode]);
    if ($r) return $r;
    [$von, $bis] = periode_range($periode, $art);
    $u = one("SELECT * FROM users WHERE id = ?", [$uid]) ?? [];
    $id = ins('reports', ['user_id' => $uid, 'art' => $art, 'periode' => $periode, 'von' => $von,
        'bis' => $bis, 'jahr' => lehrjahr($u, $von), 'abteilung' => einsatz_am($uid, $von) ?: ($u['abteilung'] ?? '')]);
    return one("SELECT * FROM reports WHERE id = ?", [$id]);
}
/**
 * Rechnet das Ausbildungsjahr aller Nachweise neu, nachdem sich der Beginn
 * geaendert hat. reports.jahr wird beim Anlegen eingefroren und gedruckt.
 */
function reports_jahr_nachziehen(array $u): int {
    $n = 0;
    foreach (all("SELECT id, von, jahr FROM reports WHERE user_id = ?", [(int)$u['id']]) as $r) {
        $neu = lehrjahr($u, (string)$r['von']);
        if ($neu !== (int)$r['jahr']) { upd('reports', ['jahr' => $neu], 'id = :id', ['id' => (int)$r['id']]); $n++; }
    }
    return $n;
}
/** Zieht Routinen, Notizen, Blockplan und Abwesenheiten in den Zeitraum. */
function report_fill(array $rep, array $u): int {
    $uid = (int)$rep['user_id']; $von = $rep['von']; $bis = $rep['bis']; $rid = (int)$rep['id'];
    $da = [];
    foreach (all("SELECT datum, text FROM report_entries WHERE report_id = ?", [$rid]) as $e) {
        $da[$e['datum'] . '|' . mb_strtolower(trim($e['text']))] = true;
    }
    $n = 0;
    $add = function (string $d, float $std, ?int $cat, string $ort, string $txt, string $src, ?int $lf = null)
        use (&$da, &$n, $rid, $uid) {
        $k = $d . '|' . mb_strtolower(trim($txt));
        if ($txt === '' || isset($da[$k])) return;
        $da[$k] = true; $n++;
        ins('report_entries', ['report_id' => $rid, 'user_id' => $uid, 'datum' => $d, 'stunden' => $std,
            'category_id' => $cat, 'lf_no' => $lf, 'ort' => $ort, 'text' => $txt, 'quelle' => $src]);
    };
    $grp = [];
    foreach (all("SELECT l.*, r.name, r.category_id, r.bh FROM routine_logs l JOIN routines r ON r.id = l.routine_id
                  WHERE l.user_id = ? AND l.datum BETWEEN ? AND ? ORDER BY l.datum, l.id", [$uid, $von, $bis]) as $l) {
        if (!(int)$l['bh']) continue;
        $cat = $l['category_id'] ? (int)$l['category_id'] : kategorie_zu($l['name']);
        $k = $l['datum'] . '|' . (string)$cat;
        $grp[$k]['d'] = $l['datum']; $grp[$k]['c'] = $cat;
        $grp[$k]['m'] = ($grp[$k]['m'] ?? 0) + (int)$l['minuten'];
        $grp[$k]['t'][$l['name']] = true;
        if (trim((string)$l['notiz']) !== '') $grp[$k]['t'][trim($l['notiz'])] = true;
    }
    foreach ($grp as $g) $add($g['d'], round($g['m'] / 60, 2), $g['c'], 'betrieb', implode('; ', array_keys($g['t'])), 'routine');

    foreach (all("SELECT * FROM notes WHERE user_id = ? AND datum BETWEEN ? AND ?", [$uid, $von, $bis]) as $no) {
        $txt = trim($no['titel'] !== '' ? $no['titel'] : mb_substr($no['body'], 0, 160));
        if ($txt === '') continue;
        $ort = $no['subject_id'] ? 'schule' : 'betrieb';
        $cat = kategorie_zu($txt . ' ' . $no['body'])
            ?: ($ort === 'schule' ? (int)val("SELECT id FROM categories WHERE name='Berufsschule'", [], 0) : null);
        $add($no['datum'], 0, $cat ?: null, $ort, $txt, 'notiz', $no['lf_no'] ? (int)$no['lf_no'] : null);
    }
    $catS = (int)val("SELECT id FROM categories WHERE name='Berufsschule'", [], 0) ?: null;
    foreach (all("SELECT * FROM blocks WHERE user_id = ? AND art = 'schule' AND NOT (bis < ? OR von > ?)",
                 [$uid, $von, $bis]) as $b) {
        $d = new DateTimeImmutable(max($b['von'], $von));
        $e = new DateTimeImmutable(min($b['bis'], $bis));
        while ($d <= $e) {
            $t = (int)$d->format('N');
            if ($t <= 5) {
                $f = array_values(array_filter(array_column(all(
                    "SELECT s.name AS f FROM timetable t JOIN subjects s ON s.id = t.subject_id
                     WHERE t.user_id = ? AND t.tag = ?", [$uid, $t]), 'f')));
                $add($d->format('Y-m-d'), 8, $catS, 'schule',
                     'Berufsschule' . ($f ? ': ' . implode(', ', array_unique($f)) : ''), 'block');
            }
            $d = $d->modify('+1 day');
        }
    }
    foreach (all("SELECT * FROM absences WHERE user_id = ? AND NOT (bis < ? OR von > ?)", [$uid, $von, $bis]) as $a) {
        $map = ['krank' => 'Krankheit', 'urlaub' => 'Urlaub', 'frei' => 'Feiertag', 'dienstreise' => 'Arbeitsaufgaben planen und durchfuehren'];
        $cat = (int)val("SELECT id FROM categories WHERE name = ?", [$map[$a['art']] ?? 'Urlaub'], 0) ?: null;
        $d = new DateTimeImmutable(max($a['von'], $von));
        $e = new DateTimeImmutable(min($a['bis'], $bis));
        while ($d <= $e) {
            if ((int)$d->format('N') <= 5) {
                $add($d->format('Y-m-d'), 0, $cat, $a['art'] === 'krank' ? 'krank' : 'urlaub',
                     ucfirst($a['art']) . ($a['grund'] ? ' - ' . $a['grund'] : ''), 'abwesenheit');
            }
            $d = $d->modify('+1 day');
        }
    }
    return $n;
}
function report_sum(int $rid): array {
    if (!$rid) return ['rows' => [], 'kat' => [], 'tag' => [], 'std' => 0.0];
    $rows = all("SELECT e.*, c.name AS kategorie, c.pos_no, c.farbe FROM report_entries e
                 LEFT JOIN categories c ON c.id = e.category_id
                 WHERE e.report_id = ? ORDER BY e.datum, e.id", [$rid]);
    $kat = []; $tag = []; $std = 0.0;
    foreach ($rows as $r) {
        $k = $r['kategorie'] ?: 'Ohne Zuordnung';
        $kat[$k]['name'] = $k; $kat[$k]['pos'] = $r['pos_no'] ?? '';
        $kat[$k]['farbe'] = $r['farbe'] ?? '#94a3b8';
        $kat[$k]['std'] = ($kat[$k]['std'] ?? 0) + (float)$r['stunden'];
        $kat[$k]['t'][] = $r['text'];
        $tag[$r['datum']][] = $r;
        $std += (float)$r['stunden'];
    }
    uasort($kat, fn($a, $b) => $b['std'] <=> $a['std']);
    return ['rows' => $rows, 'kat' => $kat, 'tag' => $tag, 'std' => $std];
}
function report_text(int $rid): string {
    $s = report_sum($rid); $out = [];
    foreach ($s['kat'] as $c) {
        $t = array_values(array_unique(array_filter(array_map('trim', $c['t']))));
        $out[] = ($c['pos'] ? '[' . $c['pos'] . '] ' : '') . $c['name']
               . ($c['std'] > 0 ? ' (' . num($c['std'], 1) . ' h)' : '') . ': ' . implode('; ', $t);
    }
    return implode("\n", $out);
}

// --- Noten -----------------------------------------------------------------
function to_note(float $w, string $skala): ?float {
    if ($skala === 'note') return max(1.0, min(6.0, $w));
    if ($skala === 'punkte') return round(1 + (15 - max(0.0, min(15.0, $w))) / 3, 2);
    if ($skala === 'ihk') {
        $p = max(0.0, min(100.0, $w));
        return $p >= 92 ? 1.0 : ($p >= 81 ? 2.0 : ($p >= 67 ? 3.0 : ($p >= 50 ? 4.0 : ($p >= 30 ? 5.0 : 6.0))));
    }
    return null;
}
function noten_stats(int $uid): array {
    $rows = all("SELECT g.*, COALESCE(s.name, g.fach_text) AS fach, s.color, s.short
                 FROM grades g LEFT JOIN subjects s ON s.id = g.subject_id
                 WHERE g.user_id = ? ORDER BY g.datum", [$uid]);
    $f = []; $sw = 0.0; $sv = 0.0; $vert = array_fill(1, 6, 0);
    foreach ($rows as $r) {
        $n = to_note((float)$r['wert'], $r['skala']);
        if ($n === null) continue;
        $g = max(0.0, (float)$r['gewicht']);
        $k = $r['fach'] ?: 'Ohne Fach';
        $f[$k]['name'] = $k; $f[$k]['short'] = $r['short'] ?: mb_substr($k, 0, 6);
        $f[$k]['color'] = $r['color'] ?: '#2563eb';
        $f[$k]['sw'] = ($f[$k]['sw'] ?? 0) + $g;
        $f[$k]['sv'] = ($f[$k]['sv'] ?? 0) + $g * $n;
        $f[$k]['n'][] = $n;
        $sw += $g; $sv += $g * $n;
        $vert[(int)round($n)] = ($vert[(int)round($n)] ?? 0) + 1;
    }
    foreach ($f as $k => $x) {
        $f[$k]['schnitt'] = $x['sw'] > 0 ? $x['sv'] / $x['sw'] : null;
        $f[$k]['anzahl']  = count($x['n']);
        $f[$k]['trend']   = null;
        if (count($x['n']) >= 2) {
            $half = (int)ceil(count($x['n']) / 2);
            $a = array_slice($x['n'], 0, $half); $b = array_slice($x['n'], $half);
            $f[$k]['trend'] = array_sum($a) / count($a) - array_sum($b) / count($b);
        }
    }
    ksort($f);
    return ['rows' => $rows, 'faecher' => $f, 'schnitt' => $sw > 0 ? $sv / $sw : null, 'vert' => $vert];
}
function ihk_bereiche(): array {
    return ['ap1' => ['Teil 1', 20], 'projekt' => ['Projektarbeit', 50],
            'kadis' => ['Konzeption und Administration', 10],
            'aevn' => ['Analyse und Entwicklung von Netzwerken', 10],
            'wiso' => ['Wirtschafts- und Sozialkunde', 10]];
}
function ihk_prognose(array $p): array {
    $sum = 0; $gew = 0;
    foreach (ihk_bereiche() as $k => [, $w]) {
        if (($p[$k] ?? '') === '' || $p[$k] === null) continue;
        $sum += ((float)$p[$k]) * $w; $gew += $w;
    }
    if (!$gew) return ['punkte' => null, 'note' => null, 'abdeckung' => 0];
    $x = $sum / $gew;
    return ['punkte' => $x, 'note' => to_note($x, 'ihk'), 'abdeckung' => $gew];
}
function ihk_probleme(array $p): array {
    $pr = ihk_prognose($p);
    if ($pr['punkte'] === null) return [];
    $out = [];
    if ($pr['punkte'] < 50) $out[] = 'Gesamt unter 50';
    if (($p['projekt'] ?? '') !== '' && (float)$p['projekt'] < 50) $out[] = 'Projektarbeit unter 50';
    $t2 = 0; $w2 = 0;
    foreach (['projekt' => 50, 'kadis' => 10, 'aevn' => 10, 'wiso' => 10] as $k => $w) {
        if (($p[$k] ?? '') !== '') { $t2 += (float)$p[$k] * $w; $w2 += $w; }
    }
    if ($w2 && $t2 / $w2 < 50) $out[] = 'Teil 2 unter 50';
    $u = 0;
    foreach (['kadis','aevn','wiso'] as $k) {
        if (($p[$k] ?? '') !== '') {
            if ((float)$p[$k] < 50) $u++;
            if ((float)$p[$k] <= 0) $out[] = 'Bereich mit 0 Punkten';
        }
    }
    if ($u > 1) $out[] = 'Mehr als ein Bereich unter 50';
    return $out;
}

// ===========================================================================
//  Externe Quellen: HTTP, Verschluesselung, iCal, WebUntis
// ===========================================================================

/** Blockt Ziele im lokalen Netz, damit die Import-Funktion kein Portscanner wird. */
/**
 * Loest den Host auf und prueft jede Adresse gegen private und reservierte Bereiche.
 * @return array{ips:string[],fehler:string}
 */
function host_ips(string $host): array {
    if (IMPORT_PRIVAT) return ['ips' => [], 'fehler' => ''];
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) { $ips[] = $host; }
    else {
        $a = @dns_get_record($host, DNS_A);
        foreach ($a ?: [] as $r) if (!empty($r['ip'])) $ips[] = $r['ip'];
        $aaaa = @dns_get_record($host, DNS_AAAA);
        foreach ($aaaa ?: [] as $r) if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
    }
    if (!$ips) return ['ips' => [], 'fehler' => 'Server nicht gefunden.'];
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            || preg_match('/^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./', $ip)) {   // CGNAT
            return ['ips' => [], 'fehler' => 'Ziel liegt im lokalen Netz.'];
        }
    }
    return ['ips' => $ips, 'fehler' => ''];
}
function host_erlaubt(string $host): bool { return host_ips($host)['fehler'] === ''; }
/** @return array{ok:bool,code:int,body:string,fehler:string,cookie:string} */
function http_ruf(string $url, array $o = []): array {
    $leer = ['ok' => false, 'code' => 0, 'body' => '', 'fehler' => '', 'cookie' => ''];
    if (!extension_loaded('curl')) return array_merge($leer, ['fehler' => 'PHP-Extension curl fehlt.']);
    // Jeder Sprung wird einzeln geprueft - curl wuerde sonst an der Netzpruefung vorbei umleiten.
    // Die geprueften Adressen werden festgenagelt, damit ein zweiter DNS-Blick nicht woandershin fuehrt.
    $pruefen = function (string $u) use ($leer): array {
        $t = parse_url($u);
        if (!$t || empty($t['host'])) return ['fehler' => array_merge($leer, ['fehler' => 'Adresse unvollstaendig.'])];
        $schema = strtolower($t['scheme'] ?? '');
        if ($schema !== 'https' && !(IMPORT_PRIVAT && $schema === 'http')) {
            return ['fehler' => array_merge($leer, ['fehler' => 'Nur https erlaubt.'])];
        }
        $hi = host_ips($t['host']);
        if ($hi['fehler'] !== '') return ['fehler' => array_merge($leer, ['fehler' => $hi['fehler']])];
        $port = (int)($t['port'] ?? ($schema === 'https' ? 443 : 80));
        return ['pin' => $hi['ips'] ? [$t['host'] . ':' . $port . ':' . implode(',', $hi['ips'])] : []];
    };
    $pr = $pruefen($url);
    if (isset($pr['fehler'])) return $pr['fehler'];

    $max     = (int)($o['max'] ?? 4 * 1024 * 1024);
    $aktuell = $url;
    $cookie  = '';
    for ($sprung = 0; ; $sprung++) {
        $ch = curl_init($aktuell);
        $kopf = $o['header'] ?? [];
        $kopfteil = ''; $body = ''; $zuGross = false;
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => (int)($o['timeout'] ?? 15),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS_STR  => IMPORT_PRIVAT ? 'https,http' : 'https',
            CURLOPT_USERAGENT      => APP_NAME . '/' . APP_VERSION,
            CURLOPT_ENCODING       => '',
            // Groesse nach dem Entpacken zaehlen - die Progress-Zahlen kennen nur die komprimierten Bytes
            CURLOPT_HEADERFUNCTION => function ($c, string $h) use (&$kopfteil): int { $kopfteil .= $h; return strlen($h); },
            CURLOPT_WRITEFUNCTION  => function ($c, string $d) use (&$body, &$zuGross, $max): int {
                $body .= $d;
                if (strlen($body) > $max) { $zuGross = true; return -1; }
                return strlen($d);
            },
        ]);
        if (!empty($pr['pin'])) curl_setopt($ch, CURLOPT_RESOLVE, $pr['pin']);
        if (isset($o['json'])) {
            $kopf[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($o['json']));
        }
        if (!empty($o['cookie'])) $kopf[] = 'Cookie: ' . $o['cookie'];
        if ($kopf) curl_setopt($ch, CURLOPT_HTTPHEADER, $kopf);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION,
            fn($r, $dlGesamt, $dlJetzt) => ($dlJetzt > $max || $dlGesamt > $max) ? 1 : 0);
        $ok = curl_exec($ch);
        if ($ok === false) {
            $fehler = curl_error($ch); curl_close($ch);
            if ($zuGross) return array_merge($leer, ['fehler' => 'Antwort zu gross.']);
            return array_merge($leer, ['fehler' => $fehler ?: 'Verbindung fehlgeschlagen.']);
        }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $kopfteil, $m)) $cookie = implode('; ', $m[1]);

        if ($code >= 300 && $code < 400 && $sprung < 3 && !isset($o['json'])
            && preg_match('/^Location:\s*([^\r\n]+)/mi', $kopfteil, $lm)) {
            $ziel = str_replace(' ', '%20', trim($lm[1]));
            $b = parse_url($aktuell);
            $wurzel = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . (int)$b['port'] : '');
            if (preg_match('#^//#', $ziel)) {                     // protokollrelativ: //host/pfad
                $ziel = $b['scheme'] . ':' . $ziel;
            } elseif (str_starts_with($ziel, '/')) {              // wurzelrelativ
                $ziel = $wurzel . $ziel;
            } elseif (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $ziel)) {  // relativ zum aktuellen Pfad
                $pfad = $b['path'] ?? '/';
                $ziel = $wurzel . substr($pfad, 0, strrpos($pfad, '/') + 1) . $ziel;
            }
            $pr = $pruefen($ziel);
            if (isset($pr['fehler'])) return $pr['fehler'];
            $aktuell = $ziel;
            continue;
        }
        return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'body' => $body,
                'fehler' => $code >= 400 ? 'HTTP ' . $code : ($code >= 300 ? 'Zu viele Umleitungen.' : ''),
                'cookie' => $cookie];
    }
}

/** Schluessel fuer gespeicherte Zugangsdaten, liegt im Datenverzeichnis. */
function app_key(): ?string {
    if (!function_exists('sodium_crypto_secretbox')) return null;
    $f = DATA_DIR . '/key.bin';
    if (!is_file($f)) { file_put_contents($f, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)); @chmod($f, 0600); }
    $k = (string)file_get_contents($f);
    return strlen($k) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? $k : null;
}
function verschluesseln(string $klar): ?string {
    $k = app_key(); if ($k === null) return null;
    $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($n . sodium_crypto_secretbox($klar, $n, $k));
}
function entschluesseln(?string $c): ?string {
    $k = app_key(); if ($k === null || !$c) return null;
    $roh = base64_decode($c, true);
    if ($roh === false || strlen($roh) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1) return null;
    $n = substr($roh, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $r = sodium_crypto_secretbox_open(substr($roh, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $n, $k);
    return $r === false ? null : $r;
}

/** Minimaler iCalendar-Parser: VEVENT ohne Wiederholungsregeln. */
function ics_parse(string $text): array {
    $text = preg_replace("/\r\n[ \t]/", '', str_replace("\n", "\r\n", str_replace("\r\n", "\n", $text)));
    $zeilen = preg_split("/\r\n/", (string)$text);
    $out = []; $ev = null; $alarm = 0;
    $entschaerf = fn($v) => str_replace(['\\n', '\\N', '\\,', '\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $v);
    $zeit = function (string $params, string $wert): array {
        $wert = trim($wert);
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $wert, $m)) return ["$m[1]-$m[2]-$m[3]", '', true];
        if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z?)$/', $wert, $m)) {
            if ($m[7] === 'Z') {
                $d = new DateTimeImmutable("$m[1]-$m[2]-$m[3]T$m[4]:$m[5]:$m[6]", new DateTimeZone('UTC'));
                $d = $d->setTimezone(new DateTimeZone(date_default_timezone_get()));
                return [$d->format('Y-m-d'), $d->format('H:i'), false];
            }
            return ["$m[1]-$m[2]-$m[3]", "$m[4]:$m[5]", false];
        }
        return ['', '', false];
    };
    foreach ($zeilen as $z) {
        // Eine Erinnerung traegt eine eigene SUMMARY - die gehoert nicht zum Termin.
        // Zaehlen statt schalten und an jeder VEVENT-Grenze raeumen: ein fehlendes
        // END:VALARM verdirbt so hoechstens seinen Termin, nicht den ganzen Kalender.
        if ($z === 'BEGIN:VALARM') { $alarm++; continue; }
        if ($z === 'END:VALARM')   { $alarm = max(0, $alarm - 1); continue; }
        if ($z === 'BEGIN:VEVENT') { $alarm = 0; $ev = ['uid'=>'','rid'=>'','titel'=>'','text'=>'','ort'=>'','datum'=>'','von'=>'','bis'=>'','ganz'=>false,'link'=>'']; continue; }
        if ($z === 'END:VEVENT') { $alarm = 0; }
        if ($alarm > 0) continue;
        if (count($out) >= 2000) break;
        if ($z === 'END:VEVENT') {
            if ($ev && $ev['datum'] !== '') {
                if ($ev['uid'] === '') $ev['uid'] = substr(hash('sha256', $ev['datum'] . $ev['von'] . $ev['titel']), 0, 32);
                $out[] = $ev;
            }
            $ev = null; continue;
        }
        if ($ev === null || !str_contains($z, ':')) continue;
        [$links, $wert] = explode(':', $z, 2);
        $teile  = explode(';', $links);
        $schl   = strtoupper($teile[0]);
        $params = implode(';', array_slice($teile, 1));
        switch ($schl) {
            case 'UID':         $ev['uid']   = substr(trim($wert), 0, 190); break;
            case 'RECURRENCE-ID': $ev['rid']  = substr(trim($wert), 0, 40); break;
            case 'URL':           $ev['link'] = str_starts_with(trim($wert), 'https://') ? mb_substr(trim($wert), 0, 400) : ''; break;
            case 'SUMMARY':     $ev['titel'] = mb_substr($entschaerf($wert), 0, 200); break;
            case 'DESCRIPTION': $ev['text']  = mb_substr($entschaerf($wert), 0, 2000); break;
            case 'LOCATION':    $ev['ort']   = mb_substr($entschaerf($wert), 0, 60); break;
            case 'DTSTART':
                [$d, $t, $g] = $zeit($params, $wert);
                $ev['datum'] = $d; $ev['von'] = $t; $ev['ganz'] = $g; break;
            case 'DTEND':
                [$d2, $t2, ] = $zeit($params, $wert);
                $ev['bis'] = $t2; break;
        }
    }
    return $out;
}

/** WebUntis JSON-RPC. Liefert Stundenplan und, wenn der Server es kann, Pruefungen. */
function untis_hole(array $src, string $von, string $bis, int $timeout = 20): array {
    $server = preg_replace('~^https?://~', '', trim($src['server']));
    $server = rtrim(explode('/', $server)[0], '/');
    if ($server === '' || $src['schule'] === '') return ['fehler' => 'Server oder Schule fehlt.', 'termine' => []];
    $pw = entschluesseln($src['secret']);
    if ($pw === null) return ['fehler' => 'Zugangsdaten nicht lesbar.', 'termine' => []];
    $url = 'https://' . $server . '/WebUntis/jsonrpc.do?school=' . rawurlencode($src['schule']);
    $nr = 0; $cookie = '';
    $rpc = function (string $methode, array $params = []) use ($url, &$nr, &$cookie) {
        $r = http_ruf($url, ['json' => ['id' => (string)(++$nr), 'method' => $methode,
            'params' => $params ?: new stdClass(), 'jsonrpc' => '2.0'], 'cookie' => $cookie, 'timeout' => $timeout]);
        if (!$r['ok']) return ['fehler' => $r['fehler'] ?: 'keine Antwort'];
        if ($r['cookie'] !== '') $cookie = $r['cookie'];
        $j = json_decode($r['body'], true);
        if (!is_array($j)) return ['fehler' => 'Antwort unlesbar'];
        if (isset($j['error'])) return ['fehler' => (string)($j['error']['message'] ?? 'Fehler')];
        return ['result' => $j['result'] ?? null];
    };
    $auth = $rpc('authenticate', ['user' => $src['benutzer'], 'password' => $pw, 'client' => APP_NAME]);
    if (isset($auth['fehler'])) return ['fehler' => 'Anmeldung: ' . $auth['fehler'], 'termine' => []];
    $sess = $auth['result'] ?? [];
    if (empty($sess['sessionId'])) return ['fehler' => 'Anmeldung abgelehnt.', 'termine' => []];
    if (!str_contains($cookie, 'JSESSIONID')) $cookie = 'JSESSIONID=' . $sess['sessionId'];
    $pid  = (int)($sess['personId'] ?? 0);
    $ptyp = (int)($sess['personType'] ?? 5);
    if (!$pid && !empty($sess['klasseId'])) { $pid = (int)$sess['klasseId']; $ptyp = 1; }

    $karte = function (string $m) use ($rpc): array {
        $r = $rpc($m); $k = [];
        foreach (($r['result'] ?? []) ?: [] as $x) {
            if (isset($x['id'])) $k[(int)$x['id']] = ['kurz' => (string)($x['name'] ?? ''), 'lang' => (string)($x['longName'] ?? '')];
        }
        return $k;
    };
    $faecher = $karte('getSubjects');
    $raeume  = $karte('getRooms');
    $vd = (int)str_replace('-', '', $von);
    $bd = (int)str_replace('-', '', $bis);
    $tt = $rpc('getTimetable', ['id' => $pid, 'type' => $ptyp, 'startDate' => $vd, 'endDate' => $bd]);
    if (isset($tt['fehler'])) { $rpc('logout'); return ['fehler' => 'Stundenplan: ' . $tt['fehler'], 'termine' => []]; }
    $zeitf = fn($z) => sprintf('%02d:%02d', intdiv((int)$z, 100), (int)$z % 100);
    $termine = [];
    foreach (($tt['result'] ?? []) ?: [] as $e) {
        if (($e['code'] ?? '') === 'cancelled') continue;
        $sid = (int)($e['su'][0]['id'] ?? 0);
        $rid = (int)($e['ro'][0]['id'] ?? 0);
        $name = $faecher[$sid]['kurz'] ?? ($e['su'][0]['name'] ?? 'Unterricht');
        $lang = $faecher[$sid]['lang'] ?? '';
        $termine[] = [
            'uid'   => 'tt' . (int)($e['id'] ?? 0),
            'titel' => trim($name . ($lang && $lang !== $name ? ' – ' . $lang : '')),
            'text'  => trim((string)($e['lstext'] ?? '')),
            'ort'   => $raeume[$rid]['kurz'] ?? (string)($e['ro'][0]['name'] ?? ''),
            'datum' => preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', (string)($e['date'] ?? '')),
            'von'   => $zeitf($e['startTime'] ?? 0), 'bis' => $zeitf($e['endTime'] ?? 0),
            'typ'   => 'unterricht',
        ];
    }
    $ex = $rpc('getExams', ['id' => $pid, 'type' => $ptyp, 'startDate' => $vd, 'endDate' => $bd]);
    foreach (($ex['result'] ?? []) ?: [] as $e) {   // nicht jede Installation kann das
        $termine[] = [
            'uid'   => 'ex' . (int)($e['id'] ?? 0),
            'titel' => trim((string)($e['name'] ?? 'Pruefung') . ' ' . (string)($e['subject'] ?? '')),
            'text'  => (string)($e['text'] ?? ''), 'ort' => (string)($e['rooms'][0] ?? ''),
            'datum' => preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', (string)($e['examDate'] ?? '')),
            'von'   => $zeitf($e['startTime'] ?? 0), 'bis' => $zeitf($e['endTime'] ?? 0),
            'typ'   => 'probe',
        ];
    }
    $rpc('logout');
    return ['fehler' => '', 'termine' => $termine];
}

/** Oeffentliches WebUntis-Schulverzeichnis. Kein Konto noetig. */
function untis_schulsuche(string $q): array {
    $q = trim($q);
    if (mb_strlen($q) < 3) return ['fehler' => 'Mindestens drei Zeichen.', 'schulen' => []];
    $r = http_ruf('https://mobile.webuntis.com/ms/schoolquery2', ['timeout' => 15, 'json' =>
        ['id' => '1', 'method' => 'searchSchool', 'params' => [['search' => mb_substr($q, 0, 80)]], 'jsonrpc' => '2.0']]);
    if (!$r['ok']) return ['fehler' => $r['fehler'] ?: 'Verzeichnis nicht erreichbar.', 'schulen' => []];
    $j = json_decode($r['body'], true);
    if (!is_array($j)) return ['fehler' => 'Antwort unlesbar.', 'schulen' => []];
    if (isset($j['error'])) {
        $m = (string)($j['error']['message'] ?? 'Fehler');
        return ['fehler' => $m === 'too many results' ? 'Zu viele Treffer, bitte genauer suchen.' : $m, 'schulen' => []];
    }
    $out = [];
    foreach (($j['result']['schools'] ?? []) ?: [] as $x) {
        if (empty($x['loginName']) || empty($x['server'])) continue;
        $out[] = ['name' => trim(rtrim((string)($x['displayName'] ?? ''), '/ ')),
            'ort' => (string)($x['address'] ?? ''),
            'server' => (string)$x['server'], 'schule' => (string)$x['loginName']];
    }
    return ['fehler' => '', 'schulen' => array_slice($out, 0, 25)];
}

/**
 * Zerlegt die aus Moodle kopierte Kalenderadresse. Moodles Voreinstellung
 * liefert nur die laufende Woche - wir holen alles Kommende.
 * @return array{anzeige:string,voll:string,fehler:string}
 */
function moodle_teile(string $roh): array {
    $leer = ['anzeige' => '', 'voll' => '', 'fehler' => ''];
    $roh = trim($roh);
    $t = parse_url($roh);
    if (!$t || ($t['scheme'] ?? '') !== 'https' || empty($t['host'])) {
        return array_merge($leer, ['fehler' => 'Adresse muss mit https:// beginnen.']);
    }
    if (!str_ends_with((string)($t['path'] ?? ''), '/calendar/export_execute.php')) {
        return array_merge($leer, ['fehler' => 'Erwartet wird die Kalender-URL aus Moodle (calendar/export_execute.php).']);
    }
    parse_str((string)($t['query'] ?? ''), $q);
    if (!is_string($q['authtoken'] ?? null) || !preg_match('/^[0-9a-f]{40}$/', $q['authtoken'])) {
        return array_merge($leer, ['fehler' => 'In der Adresse fehlt der authtoken.']);
    }
    if (empty($q['userid']) && empty($q['username'])) {
        return array_merge($leer, ['fehler' => 'In der Adresse fehlt die Benutzerkennung.']);
    }
    $q['preset_what'] = 'all';
    $q['preset_time'] = 'recentupcoming';
    $basis = 'https://' . $t['host'] . (isset($t['port']) ? ':' . (int)$t['port'] : '') . $t['path'];
    return ['anzeige' => $basis, 'voll' => $basis . '?' . http_build_query($q), 'fehler' => ''];
}

/** Klassenliste einer WebUntis-Quelle - braucht die hinterlegten Zugangsdaten. */
function untis_klassen(array $src): array {
    $server = rtrim(explode('/', preg_replace('~^https?://~', '', trim($src['server'])))[0], '/');
    $pw = entschluesseln($src['secret']);
    if ($server === '' || $src['schule'] === '' || $pw === null) return ['fehler' => 'Zugangsdaten unvollstaendig.', 'klassen' => []];
    $url = 'https://' . $server . '/WebUntis/jsonrpc.do?school=' . rawurlencode($src['schule']);
    $cookie = ''; $nr = 0;
    $rpc = function (string $m, array $p = []) use ($url, &$cookie, &$nr) {
        $r = http_ruf($url, ['timeout' => 20, 'cookie' => $cookie,
            'json' => ['id' => (string)(++$nr), 'method' => $m, 'params' => $p ?: new stdClass(), 'jsonrpc' => '2.0']]);
        if (!$r['ok']) return ['fehler' => $r['fehler'] ?: 'keine Antwort'];
        if ($r['cookie'] !== '') $cookie = $r['cookie'];
        $j = json_decode($r['body'], true);
        if (!is_array($j)) return ['fehler' => 'Antwort unlesbar'];
        if (isset($j['error'])) return ['fehler' => (string)($j['error']['message'] ?? 'Fehler')];
        return ['result' => $j['result'] ?? null];
    };
    $a = $rpc('authenticate', ['user' => $src['benutzer'], 'password' => $pw, 'client' => APP_NAME]);
    if (isset($a['fehler'])) return ['fehler' => 'Anmeldung: ' . $a['fehler'], 'klassen' => []];
    if (empty($a['result']['sessionId'])) return ['fehler' => 'Anmeldung abgelehnt.', 'klassen' => []];
    if (!str_contains($cookie, 'JSESSIONID')) $cookie = 'JSESSIONID=' . $a['result']['sessionId'];
    $k = $rpc('getKlassen');
    $rpc('logout');
    if (isset($k['fehler'])) return ['fehler' => 'Klassen: ' . $k['fehler'], 'klassen' => []];
    $out = [];
    foreach (($k['result'] ?? []) ?: [] as $x) {
        $n = trim((string)($x['name'] ?? ''));
        if ($n !== '') $out[] = ['name' => $n, 'lang' => trim((string)($x['longName'] ?? ''))];
    }
    usort($out, fn($p, $r2) => strnatcasecmp($p['name'], $r2['name']));
    return ['fehler' => '', 'klassen' => $out];
}

/**
 * Zerlegt eine Klassenbezeichnung wie 2FS152 in ihre Bestandteile.
 * Die fuehrende Ziffer ist das Ausbildungsjahr und wird nicht gespeichert -
 * sie ergibt sich aus dem Ausbildungsbeginn.
 */
/**
 * Zerlegt eine Klassenbezeichnung wie 2FS152: Stufe 2, Kuerzel FS,
 * laufende Nummer 15, Zeitgruppe 2. Die Stufe selbst wird spaeter berechnet.
 */
function klasse_teile(string $k): array {
    $k = strtoupper(trim($k));
    // Fuehrende Ziffer ist die Jahrgangsstufe, W steht fuer die Verkuerzerklasse
    if (preg_match('/^(\d|W)\s*([A-Z]{1,4})\s*(\d*?)(\d)$/', $k, $m)) {
        return ['kuerzel' => $m[2], 'nr' => $m[3], 'zeitgruppe' => (int)$m[4],
                'verkuerzt' => $m[1] === 'W' ? 1 : 0,
                'stufe' => $m[1] === 'W' ? null : (int)$m[1]];
    }
    $zg = preg_match('/(\d)\s*$/', $k, $m2) ? (int)$m2[1] : 0;
    return ['kuerzel' => '', 'nr' => '', 'zeitgruppe' => $zg, 'verkuerzt' => 0, 'stufe' => null];
}

/** Holt eine Quelle und schreibt die Termine ins Konto. */
/** Bundeslaender fuer Ferien und Feiertage. */
function laender(): array {
    return ['DE-BY'=>'Bayern','DE-BW'=>'Baden-Wuerttemberg','DE-BE'=>'Berlin','DE-BB'=>'Brandenburg',
        'DE-HB'=>'Bremen','DE-HH'=>'Hamburg','DE-HE'=>'Hessen','DE-MV'=>'Mecklenburg-Vorpommern',
        'DE-NI'=>'Niedersachsen','DE-NW'=>'Nordrhein-Westfalen','DE-RP'=>'Rheinland-Pfalz',
        'DE-SL'=>'Saarland','DE-SN'=>'Sachsen','DE-ST'=>'Sachsen-Anhalt','DE-SH'=>'Schleswig-Holstein',
        'DE-TH'=>'Thueringen'];
}
/**
 * Ferien und gesetzliche Feiertage aus dem offenen Verzeichnis openholidaysapi.org.
 * Schreibt in die Blocktabelle, damit Berichtsheft und Kalender sie kennen.
 */
function feiertage_sync(array $src, array $u, int $timeout = 20): array {
    $uid = (int)$u['id']; $sid = (int)$src['id'];
    $land = isset(laender()[$src['region']]) ? $src['region'] : 'DE-BY';
    $von = date('Y-m-d', strtotime('-6 months'));
    $bis = date('Y-m-d', strtotime('+18 months'));
    $n = 0; $neu = [];
    foreach ([['SchoolHolidays', 'ferien'], ['PublicHolidays', 'feiertag']] as [$pfad, $art]) {
        $url = 'https://openholidaysapi.org/' . $pfad . '?countryIsoCode=DE&subdivisionCode=' . $land
             . '&languageIsoCode=DE&validFrom=' . $von . '&validTo=' . $bis;
        $r = http_ruf($url, ['timeout' => $timeout]);
        if (!$r['ok']) { quelle_status($sid, 'fehler', $r['fehler'] ?: 'Abruf fehlgeschlagen'); return ['fehler' => $r['fehler'] ?: 'Abruf fehlgeschlagen', 'n' => 0]; }
        $j = json_decode($r['body'], true);
        if (!is_array($j)) { quelle_status($sid, 'fehler', 'Unerwartete Antwort.'); return ['fehler' => 'Unerwartete Antwort', 'n' => 0]; }
        foreach ($j as $e) {
            $a = (string)($e['startDate'] ?? ''); $b = (string)($e['endDate'] ?? $a);
            if (!isodate($a)) continue;
            $name = '';
            foreach ((array)($e['name'] ?? []) as $t) if (($t['language'] ?? '') === 'DE') $name = (string)($t['text'] ?? '');
            if ($name === '') $name = (string)(($e['name'][0]['text'] ?? '') ?: 'Frei');
            $neu[] = ['von' => $a, 'bis' => isodate($b) ? $b : $a, 'art' => $art,
                'label' => mb_substr($name, 0, 80), 'quelle' => 'q' . $sid];
            $n++;
        }
    }
    del('blocks', "user_id = ? AND quelle = ?", [$uid, 'q' . $sid]);
    foreach ($neu as $b) {
        if (one("SELECT id FROM blocks WHERE user_id = ? AND von = ? AND bis = ? AND art = ?",
                [$uid, $b['von'], $b['bis'], $b['art']])) continue;
        ins('blocks', $b + ['user_id' => $uid]);
    }
    quelle_status($sid, 'ok', $n . ' Ferien und Feiertage (' . laender()[$land] . ')', $n);
    schuljahr_start($uid, (int)date('Y'), true);   // gemerkte Schuljahresgrenzen verwerfen
    return ['fehler' => '', 'n' => $n];
}
function quelle_sync(array $src, array $u, int $timeout = 20): array {
    $uid = (int)$u['id']; $sid = (int)$src['id'];
    $von = date('Y-m-d', strtotime('-14 days'));
    $bis = date('Y-m-d', strtotime('+120 days'));
    q("UPDATE sources SET letzter_sync = ? WHERE id = ?", [time(), $sid]);   // Sperre gegen Doppellauf
    if ($src['typ'] === 'feiertage') return feiertage_sync($src, $u, $timeout);
    if ($src['typ'] === 'webuntis') {
        $r = untis_hole($src, $von, $bis, $timeout);
        if ($r['fehler'] !== '') { quelle_status($sid, 'fehler', $r['fehler']); return ['fehler' => $r['fehler'], 'n' => 0]; }
        $roh = $r['termine'];
    } else {
        $adr = (string)$src['url'];
        if ($src['typ'] === 'moodle') {
            // Der authtoken ist ein Dauergeheimnis und steht daher nur verschluesselt in der Zeile
            $adr = (string)entschluesseln($src['secret']);
            if ($adr === '') { quelle_status($sid, 'fehler', 'Adresse nicht lesbar.'); return ['fehler' => 'Adresse nicht lesbar.', 'n' => 0]; }
        }
        $r = http_ruf($adr, ['timeout' => $timeout]);
        if (!$r['ok']) { quelle_status($sid, 'fehler', $r['fehler'] ?: 'Abruf fehlgeschlagen'); return ['fehler' => $r['fehler'], 'n' => 0]; }
        if (!str_contains($r['body'], 'BEGIN:VEVENT') && !str_contains($r['body'], 'BEGIN:VCALENDAR')) {
            quelle_status($sid, 'fehler', 'Kein iCalendar unter dieser Adresse.');
            return ['fehler' => 'Kein iCalendar', 'n' => 0];
        }
        $roh = array_map(fn($e) => $e + ['typ' => null], ics_parse($r['body']));
    }
    $lehr = $src['modus'] === 'stundenplan';
    $faecher = [];
    foreach (all("SELECT id, short, name FROM subjects WHERE user_id = ? AND archiv = 0", [$uid]) as $f) {
        if ($f['short'] !== '') $faecher[mb_strtolower($f['short'])] = (int)$f['id'];
        $faecher[mb_strtolower($f['name'])] = (int)$f['id'];
    }
    $gesehen = []; $n = 0;
    foreach ($roh as $e) {
        if (!isodate((string)$e['datum']) || $e['datum'] < $von || $e['datum'] > $bis) continue;
        $typ = $e['typ'] ?? null;
        if ($typ === null) {
            $t = mb_strtolower($e['titel'] . ' ' . $e['text']);
            $typ = $lehr ? 'unterricht'
                 : (preg_match('/\b(abgabe|deadline|frist|einreich)/u', $t) ? 'abgabe'
                 : (preg_match('/\b(pruefung|prüfung|klausur|schulaufgabe|probe|test|exam)/u', $t) ? 'probe' : 'termin'));
        }
        // Mehrere Instanzen einer Serie teilen sich die UID - erst die RECURRENCE-ID trennt sie
        $ext = 'q' . $sid . ':' . $e['uid'] . (($e['rid'] ?? '') !== '' ? ':' . $e['rid'] : '');
        $gesehen[] = $ext;
        $fid = null;
        foreach ([mb_strtolower(explode(' ', $e['titel'])[0]), mb_strtolower($e['titel'])] as $kandidat) {
            if (isset($faecher[$kandidat])) { $fid = $faecher[$kandidat]; break; }
        }
        $daten = ['subject_id' => $fid, 'typ' => $typ, 'titel' => $e['titel'] ?: 'Termin',
            'beschreibung' => $e['text'], 'datum' => $e['datum'],
            'zeit_von' => $e['von'], 'zeit_bis' => $e['bis'], 'raum' => $e['ort'],
            'link' => (string)($e['link'] ?? ''),   // fuehrt zurueck in den Kurs
            'quelle' => 'q' . $sid];
        $vorhanden = (int)val("SELECT id FROM events WHERE user_id = ? AND extern_id = ?", [$uid, $ext], 0);
        if ($vorhanden) upd('events', $daten, 'id = :id', ['id' => $vorhanden]);
        else { $daten['user_id'] = $uid; $daten['extern_id'] = $ext; ins('events', $daten); }
        $n++;
    }
    // Was die Quelle nicht mehr liefert, verschwindet auch hier - aber nur, wenn
    // ueberhaupt etwas kam. Ein leeres oder abgeschnittenes Ergebnis loescht sonst alles.
    $weg = 0;
    if ($n > 0) {
        foreach (all("SELECT id, extern_id FROM events WHERE user_id = ? AND quelle = ? AND datum BETWEEN ? AND ?",
                     [$uid, 'q' . $sid, $von, $bis]) as $alt) {
            if (!in_array($alt['extern_id'], $gesehen, true)) { del('events', 'id = ?', [(int)$alt['id']]); $weg++; }
        }
    }
    quelle_status($sid, 'ok', $n . ' Termine' . ($weg ? ', ' . $weg . ' entfernt' : ''), $n);
    return ['fehler' => '', 'n' => $n];
}
function quelle_status(int $sid, string $status, string $meldung, int $anzahl = 0): void {
    q("UPDATE sources SET status = ?, meldung = ?, anzahl = ?, letzter_sync = ? WHERE id = ?",
      [$status, mb_substr($meldung, 0, 200), $anzahl, time(), $sid]);
}
/** Eine faellige Quelle im Hintergrund eines Seitenaufrufs nachziehen. */
function quellen_auto(array $u): void {
    $s = one("SELECT * FROM sources WHERE user_id = ? AND aktiv = 1
              AND letzter_sync + intervall * 60 < CAST(? AS INTEGER)
              ORDER BY letzter_sync LIMIT 1", [(int)$u['id'], time()]);
    if ($s) { try { quelle_sync($s, $u, 6); } catch (Throwable $e) { quelle_status((int)$s['id'], 'fehler', $e->getMessage()); } }
}

// ---------------------------------------------------------------------------
//  Blockplan der Schule (oeffentlich, ohne Zugangsdaten)
// ---------------------------------------------------------------------------

const BLOCKPLAN_SEITE = 'https://bsfisi.m-bildung.de/service/blockplaene';
// Belegt ueber die oeffentliche WebUntis-Schulsuche. Nur dieses eine Kuerzel,
// jede weitere Zuordnung waere geraten - dafuer gibt es die Suche im Profil.
const UNTIS_SERVER_FS = 'sbs-fachinformatik.webuntis.com';
const UNTIS_SCHULE_FS = 'sbs-fachinformatik';

/** Minimaler ZIP-Leser: braucht die zip-Extension nicht. */
function zip_lesen(string $bin): array {
    $dateien = [];
    $ende = strrpos($bin, "\x50\x4b\x05\x06");
    if ($ende === false) return $dateien;
    $anzahl = unpack('v', substr($bin, $ende + 10, 2))[1] ?? 0;
    $start  = unpack('V', substr($bin, $ende + 16, 4))[1] ?? 0;
    $p = $start;
    for ($i = 0; $i < $anzahl; $i++) {
        if (substr($bin, $p, 4) !== "\x50\x4b\x01\x02") break;
        $k = unpack('vmethode/vzeit/vdatum/Vcrc/Vkomp/Vroh/vnlen/vxlen/vclen/vd1/vd2/Vattr/Voffset',
                    substr($bin, $p + 10, 36));
        $name = substr($bin, $p + 46, $k['nlen']);
        $o = $k['offset'];
        if (substr($bin, $o, 4) === "\x50\x4b\x03\x04") {
            $l = unpack('vnlen/vxlen', substr($bin, $o + 26, 4));
            $daten = substr($bin, $o + 30 + $l['nlen'] + $l['xlen'], $k['komp']);
            if ($k['methode'] === 8)      $daten = @gzinflate($daten);
            elseif ($k['methode'] !== 0)  $daten = false;
            if ($daten !== false && $daten !== null) $dateien[$name] = $daten;
        }
        $p += 46 + $k['nlen'] + $k['xlen'] + $k['clen'];
    }
    return $dateien;
}

/** Liest die Blockplan-Seite der Schule und gibt die Archive je Schuljahr zurueck. */
function blockplan_archive(): array {
    $r = http_ruf(BLOCKPLAN_SEITE, ['timeout' => 15, 'max' => 2 * 1024 * 1024]);
    if (!$r['ok']) return [];
    $basis = 'https://' . parse_url(BLOCKPLAN_SEITE, PHP_URL_HOST);
    $out = [];
    if (preg_match_all('~href="([^"]+\.zip)"~i', $r['body'], $m)) {
        foreach ($m[1] as $href) {
            $u = str_starts_with($href, 'http') ? $href : $basis . '/' . ltrim(html_entity_decode($href), '/');
            $jahr = preg_match('~(\d{2})_(\d{2})~', $u, $j) ? '20' . $j[1] . '/' . $j[2] : basename($u);
            $out[$u] = $jahr;
        }
    }
    arsort($out);
    return $out;
}

/**
 * Uebernimmt Blockwochen, Ferien und Schultermine aus dem oeffentlichen Archiv.
 * Auswahl der Datei ueber Zeitgruppe und Jahrgangsstufe (10, 11, 12 oder W).
 */
function blockplan_import(array $u, string $zipUrl, int $zg, string $jgst): array {
    $uid = (int)$u['id'];
    $r = http_ruf($zipUrl, ['timeout' => 25, 'max' => 4 * 1024 * 1024]);
    if (!$r['ok']) return ['fehler' => $r['fehler'] ?: 'Archiv nicht erreichbar.', 'n' => 0];
    $dateien = zip_lesen($r['body']);
    if (!$dateien) return ['fehler' => 'Archiv nicht lesbar.', 'n' => 0];
    $finde = function (string $muster) use ($dateien): ?string {
        foreach ($dateien as $name => $inhalt) {
            if (preg_match($muster, basename($name))) return $inhalt;
        }
        return null;
    };
    $plan   = $finde('~^ZG' . $zg . '_' . preg_quote($jgst, '~') . '\.ics$~i');
    $ferien = $finde('~^Ferien\.ics$~i');
    $extern = $finde('~^externe?_?Schultermine\.ics$~i');
    if ($plan === null) {
        $vorhanden = array_map(fn($x) => basename($x), array_keys($dateien));
        return ['fehler' => 'ZG' . $zg . '_' . $jgst . ' nicht im Archiv (' . implode(', ', $vorhanden) . ').', 'n' => 0];
    }
    $n = 0;
    // Blockplan-iCal nutzt DTEND einschliesslich - deshalb direkt aus dem Rohtext lesen
    $spannen = function (string $ics): array {
        $out = [];
        if (preg_match_all('~BEGIN:VEVENT(.*?)END:VEVENT~s', $ics, $m)) {
            foreach ($m[1] as $blk) {
                if (!preg_match('~SUMMARY:([^\r\n]*)~', $blk, $t)) continue;
                if (!preg_match('~DTSTART[^:]*:(\d{4})(\d{2})(\d{2})~', $blk, $a)) continue;
                preg_match('~DTEND[^:]*:(\d{4})(\d{2})(\d{2})~', $blk, $b);
                $von = "$a[1]-$a[2]-$a[3]";
                $bis = $b ? "$b[1]-$b[2]-$b[3]" : $von;
                if ($bis < $von) $bis = $von;
                $out[] = ['von' => $von, 'bis' => $bis, 'titel' => trim($t[1])];
            }
        }
        return $out;
    };
    del('blocks', "user_id = ? AND label LIKE 'Blockplan%'", [$uid]);
    foreach ($spannen($plan) as $b) {
        ins('blocks', ['user_id' => $uid, 'von' => $b['von'], 'bis' => $b['bis'],
            'art' => 'schule', 'label' => 'Blockplan']);
        $n++;
    }
    if ($ferien !== null) {
        foreach ($spannen($ferien) as $b) {
            ins('blocks', ['user_id' => $uid, 'von' => $b['von'], 'bis' => $b['bis'],
                'art' => 'ferien', 'label' => 'Blockplan · ' . $b['titel']]);
            $n++;
        }
    }
    $naechste = ['ap1' => null, 'ap2' => null];
    if ($extern !== null) {
        foreach ($spannen($extern) as $b) {
            $ext = 'bp:' . md5($b['von'] . $b['titel']);
            if (val("SELECT 1 FROM events WHERE user_id = ? AND extern_id = ?", [$uid, $ext])) continue;
            $pruefung = (bool)preg_match('~pruefung|prüfung|ihk~iu', $b['titel']);
            ins('events', ['user_id' => $uid, 'typ' => $pruefung ? 'pruefung' : 'termin',
                'titel' => $b['titel'], 'datum' => $b['von'], 'quelle' => 'blockplan', 'extern_id' => $ext]);
            $n++;
            foreach ([1 => 'ap1', 2 => 'ap2'] as $teil => $feld) {   // jeweils der naechste Termin
                if (!preg_match('~teil\s*' . $teil . '~iu', $b['titel']) || $b['von'] < today()) continue;
                if ($naechste[$feld] === null || $b['von'] < $naechste[$feld]) $naechste[$feld] = $b['von'];
            }
        }
        foreach ($naechste as $feld => $wert) {
            if ($wert !== null && empty($u[$feld])) upd('users', [$feld => $wert], 'id = :id', ['id' => $uid]);
        }
    }
    schuljahr_start($uid, (int)date('Y'), true);   // gemerkte Schuljahresgrenzen verwerfen
    return ['fehler' => '', 'n' => $n];
}

/**
 * Holt den Blockplan ohne Rueckfrage: Schuljahr, Zeitgruppe und Jahrgangsstufe
 * stehen bereits in der Klasse.
 */
function blockplan_auto(array $u): array {
    if ((int)$u['zeitgruppe'] < 1) return ['fehler' => 'In der Klasse steht keine Zeitgruppe (letzte Ziffer, z.B. 2FS152).', 'n' => 0];
    $arch = blockplan_archive();
    if (!$arch) return ['fehler' => 'Blockplan-Seite nicht erreichbar.', 'n' => 0];
    $zg   = max(1, min(9, (int)$u['zeitgruppe']));
    $jgst = (int)($u['verkuerzt'] ?? 0) === 1
          ? 'W' : (string)(9 + max(1, min(3, ausbildungsstand($u)['stufe'])));
    return blockplan_import($u, (string)array_key_first($arch), $zg, $jgst);
}
/** Anzeigename eines Blocks - die Herkunftsmarke "Blockplan" bleibt intern. */
function block_label(array $b): string {
    $l = (string)($b['label'] ?? '');
    if ($l === 'Blockplan') return 'Schulblock';
    if (str_starts_with($l, 'Blockplan · ')) return substr($l, strlen('Blockplan · '));
    return $l !== '' ? $l : ucfirst((string)($b['art'] ?? ''));
}
/** Blockwochen und die Ferien des Bundeslandes zusammen holen - beides ohne Zugangsdaten. */
function blockplan_und_ferien(array $u): array {
    $uid = (int)$u['id'];
    $r = blockplan_auto($u); $r['ferien'] = '';
    if ($r['fehler'] === '' && !val("SELECT 1 FROM sources WHERE user_id = ? AND typ = 'feiertage'", [$uid])) {
        $sid = ins('sources', ['user_id' => $uid, 'name' => 'Ferien und Feiertage', 'typ' => 'feiertage',
            'modus' => 'termine', 'url' => '', 'region' => 'DE-BY', 'intervall' => 10080, 'aktiv' => 1]);
        $f = feiertage_sync(one("SELECT * FROM sources WHERE id = ?", [$sid]), $u);
        $r['ferien'] = $f['fehler'] === '' ? ', Ferien dazu' : ', Ferien: ' . $f['fehler'];
    }
    return $r;
}

// ===========================================================================
//  Volltextsuche
// ===========================================================================

/** Baut aus der Eingabe eine FTS5-Abfrage und liest die Filter heraus. */
function such_zerlegen(string $q): array {
    $f = ['lf' => null, 'typ' => null, 'fach' => null];
    $worte = [];
    foreach (preg_split('/\s+/', trim($q)) ?: [] as $t) {
        if ($t === '') continue;
        if (preg_match('/^lf:(\d{1,2})$/i', $t, $m))       { $f['lf'] = (int)$m[1]; continue; }
        if (preg_match('/^typ:([a-z]+)$/i', $t, $m))       { $f['typ'] = mb_strtolower($m[1]); continue; }
        if (preg_match('/^fach:(.+)$/i', $t, $m))          { $f['fach'] = $m[1]; continue; }
        $worte[] = $t;
    }
    return [$worte, $f];
}
function such_ausdruck(array $worte): string {
    $t = [];
    foreach ($worte as $w) {
        $w = preg_replace('/["*()]/', '', $w);
        if ($w === '') continue;
        $t[] = '"' . $w . '"*';
    }
    return implode(' ', $t);
}
/**
 * Rangzahl eines Treffers: wie gut passt der Titel, wie frisch ist er.
 * Grosse Zahl heisst weiter oben.
 */
function treffer_rang(array $t, array $worte): float {
    $titel = mb_strtolower((string)$t['titel']);
    $r = 0.0;
    foreach ($worte as $w) {
        $w = mb_strtolower($w);
        if ($w === '') continue;
        if ($titel === $w)                    $r += 60;
        elseif (str_starts_with($titel, $w))  $r += 40;
        elseif (preg_match('/\b' . preg_quote($w, '/') . '/u', $titel)) $r += 30;
        elseif (str_contains($titel, $w))     $r += 18;
        else                                  $r += 4;   // nur im Text gefunden
    }
    // Frische: was in den letzten zwei Jahren liegt, steigt sanft
    $d = (string)($t['datum'] ?? '');
    if (isodate($d)) {
        $tage = (strtotime(today()) - strtotime($d)) / 86400;
        $r += max(0, 22 - abs($tage) / 34);
    }
    return $r;
}
function suche(int $uid, string $q, int $limit = 40): array {
    [$worte, $f] = such_zerlegen($q);
    $ausdruck = such_ausdruck($worte);
    $treffer = [];
    if (hat_fts() && $ausdruck !== '') {
        try {
            // FTS5-Spalten haben keine Affinitaet: PDO bindet Zahlen als Text,
            // deshalb muss die Kontonummer ausdruecklich als Integer verglichen werden.
            $rows = all("SELECT art, ref, datum, titel,
                         snippet(such, 5, '', '', '…', 14) AS aus, bm25(such) AS rang
                         FROM such WHERE such MATCH ? AND uid = CAST(? AS INTEGER)
                         ORDER BY rang LIMIT " . (int)($limit * 3),
                        [$ausdruck, $uid]);
        } catch (Throwable $e) { $rows = []; }
        foreach ($rows as $r) $treffer[] = ['art' => $r['art'], 'ref' => (int)$r['ref'],
            'datum' => $r['datum'], 'titel' => $r['titel'], 'aus' => $r['aus']];
    } elseif ($worte) {
        $l = '%' . implode('%', $worte) . '%';
        foreach ([['notiz','notes','datum','titel','body'], ['termin','events','datum','titel','beschreibung'],
                  ['aufgabe','tasks',"COALESCE(faellig,'')",'titel','beschreibung'],
                  ['bericht','report_entries','datum','text','text'], ['routine','routines',"''",'name','name']] as [$art,$tab,$d,$ti,$tx]) {
            foreach (all("SELECT id, $d AS datum, $ti AS titel, $tx AS text FROM $tab
                          WHERE user_id = ? AND ($ti LIKE ? OR $tx LIKE ?) LIMIT 12", [$uid, $l, $l]) as $r) {
                $treffer[] = ['art' => $art, 'ref' => (int)$r['id'], 'datum' => $r['datum'],
                              'titel' => $r['titel'], 'aus' => mb_substr((string)$r['text'], 0, 120)];
            }
        }
    }
    // Was nicht im Volltextindex liegt, wird direkt gesucht
    // Ein Filter ohne Suchwort listet, was der Filter meint
    if (!$worte && ($f['lf'] !== null || $f['typ'] || $f['fach'])) {
        $treffer = such_filterliste($uid, $f);
    }
    // Unter drei Zeichen nur Wortanfaenge, sonst trifft "ur" auf "durchfuehren"
    if ($worte && mb_strlen(implode('', $worte)) >= 3) {
        $treffer = array_merge($treffer, such_direkt($uid, $worte));
    }
    // Deutsche Komposita: findet die Praefixsuche zu wenig, wird auch im Wort gesucht
    if ($worte && count($treffer) < 5 && mb_strlen(implode('', $worte)) >= 4) {
        $vorhanden = [];
        foreach ($treffer as $t) $vorhanden[$t['art'] . ':' . $t['ref']] = true;
        foreach (such_infix($uid, $worte) as $t) {
            if (!isset($vorhanden[$t['art'] . ':' . $t['ref']])) $treffer[] = $t;
        }
    }
    // Eigene Rangfolge: Titeltreffer vor Texttreffer, frisch vor alt
    usort($treffer, fn($a, $b) => treffer_rang($b, $worte) <=> treffer_rang($a, $worte));
    // Filter nachziehen
    if ($f['typ']) $treffer = array_values(array_filter($treffer, fn($t) => $t['art'] === $f['typ']));
    if ($f['lf'] !== null) {
        $ok = [];
        foreach (['notiz' => 'notes', 'termin' => 'events'] as $art => $tab) {
            foreach (all("SELECT id FROM $tab WHERE user_id = ? AND lf_no = ?", [$uid, $f['lf']]) as $r) {
                $ok[$art . ':' . (int)$r['id']] = true;
            }
        }
        $treffer = array_values(array_filter($treffer, fn($t) => isset($ok[$t['art'] . ':' . $t['ref']])));
    }
    if ($f['fach']) {
        $fid = fach_finden($uid, $f['fach']);
        if ($fid) {
            $ok = [];
            foreach (['notiz' => 'notes', 'termin' => 'events', 'aufgabe' => 'tasks'] as $art => $tab) {
                foreach (all("SELECT id FROM $tab WHERE user_id = ? AND subject_id = ?", [$uid, $fid]) as $r) {
                    $ok[$art . ':' . (int)$r['id']] = true;
                }
            }
            $treffer = array_values(array_filter($treffer, fn($t) => isset($ok[$t['art'] . ':' . $t['ref']])));
        } else $treffer = [];
    }
    return array_slice($treffer, 0, $limit);
}
/**
 * Faecher, Lernfelder, Noten, Projekt, Abwesenheiten, Bloecke, Dateien und
 * Berufsbildpositionen haben keinen Volltextindex - die kommen von hier.
 */
function such_direkt(int $uid, array $worte): array {
    $l = '%' . implode('%', $worte) . '%';
    $t = [];
    foreach (all("SELECT id, name, short, lehrer, lf_no FROM subjects
                  WHERE user_id = ? AND archiv = 0 AND (name LIKE ? OR short LIKE ? OR lehrer LIKE ?)
                  ORDER BY sort, name LIMIT 8", [$uid, $l, $l, $l]) as $r) {
        $t[] = ['art' => 'fach', 'ref' => (int)$r['id'], 'datum' => '', 'titel' => $r['name'],
                'aus' => trim(($r['short'] ?: '') . ' ' . ($r['lehrer'] ?: ''))];
    }
    foreach (all("SELECT nr, code, titel, jahr FROM lernfelder
                  WHERE code LIKE ? OR titel LIKE ? ORDER BY nr LIMIT 8", [$l, $l]) as $r) {
        $t[] = ['art' => 'lernfeld', 'ref' => (int)$r['nr'], 'datum' => '',
                'titel' => $r['code'] . ' ' . $r['titel'], 'aus' => (int)$r['jahr'] . '. Ausbildungsjahr'];
    }
    foreach (all("SELECT g.id, g.titel, g.datum, g.wert, g.skala, g.art, COALESCE(s.name, g.fach_text) AS fach
                  FROM grades g LEFT JOIN subjects s ON s.id = g.subject_id
                  WHERE g.user_id = ? AND (g.titel LIKE ? OR g.bemerkung LIKE ? OR g.fach_text LIKE ? OR s.name LIKE ?)
                  ORDER BY g.datum DESC LIMIT 8", [$uid, $l, $l, $l, $l]) as $r) {
        $n = to_note((float)$r['wert'], $r['skala']);
        $t[] = ['art' => 'note', 'ref' => (int)$r['id'], 'datum' => $r['datum'],
                'titel' => ($r['titel'] ?: $r['art']) . ' · ' . ($r['fach'] ?: 'ohne Fach'),
                'aus' => $n !== null ? 'Note ' . num($n, 1) : ''];
    }
    foreach (all("SELECT id, titel, status, doku, praesentation FROM projekt
                  WHERE user_id = ? AND (titel LIKE ? OR beschreibung LIKE ? OR notiz LIKE ?)
                  LIMIT 3", [$uid, $l, $l, $l]) as $r) {
        $t[] = ['art' => 'projekt', 'ref' => (int)$r['id'], 'datum' => $r['doku'] ?: $r['praesentation'],
                'titel' => $r['titel'] ?: 'Abschlussprojekt', 'aus' => (string)$r['status']];
    }
    foreach (all("SELECT id, name, rolle, bereich, mail, telefon FROM kontakte
                  WHERE user_id = ? AND (name LIKE ? OR rolle LIKE ? OR notiz LIKE ? OR mail LIKE ?)
                  LIMIT 8", [$uid, $l, $l, $l, $l]) as $r) {
        $t[] = ['art' => 'kontakt', 'ref' => (int)$r['id'], 'datum' => '', 'titel' => $r['name'],
                'aus' => trim($r['rolle'] . ' · ' . $r['bereich'] . ' · ' . $r['mail'], ' ·')];
    }
    foreach (all("SELECT id, abteilung, von, schwerpunkt FROM einsaetze
                  WHERE user_id = ? AND (abteilung LIKE ? OR schwerpunkt LIKE ? OR ansprech LIKE ? OR notiz LIKE ?)
                  LIMIT 8", [$uid, $l, $l, $l, $l]) as $r) {
        $t[] = ['art' => 'einsatz', 'ref' => (int)$r['id'], 'datum' => $r['von'],
                'titel' => $r['abteilung'], 'aus' => (string)$r['schwerpunkt']];
    }
    foreach (all("SELECT id, von, bis, art, grund FROM absences
                  WHERE user_id = ? AND (art LIKE ? OR grund LIKE ?)
                  ORDER BY von DESC LIMIT 6", [$uid, $l, $l]) as $r) {
        $t[] = ['art' => 'abwesend', 'ref' => (int)$r['id'], 'datum' => $r['von'],
                'titel' => ucfirst($r['art']) . ($r['grund'] ? ': ' . $r['grund'] : ''),
                'aus' => dt($r['von'], 'd.m.y') . ($r['bis'] !== $r['von'] ? ' bis ' . dt($r['bis'], 'd.m.y') : '')];
    }
    foreach (all("SELECT id, von, bis, art, label FROM blocks
                  WHERE user_id = ? AND (label LIKE ? OR art LIKE ?)
                  AND bis >= date('now','localtime','-90 day')
                  ORDER BY von LIMIT 6", [$uid, $l, $l]) as $r) {
        $t[] = ['art' => 'block', 'ref' => (int)$r['id'], 'datum' => $r['von'],
                'titel' => $r['label'] ?: ucfirst($r['art']),
                'aus' => dt($r['von'], 'd.m.y') . ' bis ' . dt($r['bis'], 'd.m.y')];
    }
    foreach (all("SELECT id, name, abschnitt, pos_no FROM categories
                  WHERE name LIKE ? OR pos_no LIKE ? ORDER BY sort LIMIT 6", [$l, $l]) as $r) {
        $t[] = ['art' => 'position', 'ref' => (int)$r['id'], 'datum' => '',
                'titel' => trim($r['pos_no'] . ' ' . $r['name']), 'aus' => 'Berufsbildposition'];
    }
    foreach (all("SELECT id, name, groesse FROM files WHERE user_id = ? AND name LIKE ? LIMIT 6", [$uid, $l]) as $r) {
        $t[] = ['art' => 'datei', 'ref' => (int)$r['id'], 'datum' => '', 'titel' => $r['name'],
                'aus' => num((float)$r['groesse'] / 1024, 0) . ' kB'];
    }
    return $t;
}
/**
 * Fertige Antworten auf die Fragen, die ein Azubi wirklich stellt.
 * Wer "urlaub" tippt, will die Zahl sehen, nicht erst die Seite oeffnen.
 */
function such_antwort(array $u, string $q): array {
    $uid = (int)$u['id'];
    $q = mb_strtolower(trim($q));
    if ($q === '' || mb_strlen($q) < 2) return [];
    $passt = function (array $woerter) use ($q): bool {
        foreach ($woerter as $w) if (str_starts_with($w, $q) || str_starts_with($q, $w)) return true;
        return false;
    };
    $a = [];

    if ($passt(['urlaub', 'resturlaub', 'frei'])) {
        $jahr = date('Y'); $genommen = 0;
        foreach (all("SELECT von, bis FROM absences WHERE user_id = ? AND art = 'urlaub'
                      AND von LIKE ?", [$uid, $jahr . '%']) as $r) $genommen += werktage($r['von'], $r['bis']);
        $anspruch = (float)$u['urlaub_tage'];
        $a[] = ['icon' => 'einsatz', 'label' => 'Resturlaub ' . $jahr,
            'wert' => $anspruch > 0 ? num(max(0, $anspruch - $genommen), 0) . ' von ' . num($anspruch, 0) . ' Tagen'
                                    : $genommen . ' Tage genommen, kein Anspruch hinterlegt',
            'url' => url('einsaetze', ['t' => 'zeiten'])];
    }
    if ($passt(['krank', 'krankheit', 'fehlzeit', 'fehlzeiten'])) {
        $jahr = date('Y'); $tage = 0;
        foreach (all("SELECT von, bis FROM absences WHERE user_id = ? AND art = 'krank'
                      AND von LIKE ?", [$uid, $jahr . '%']) as $r) $tage += werktage($r['von'], $r['bis']);
        $a[] = ['icon' => 'einsatz', 'label' => 'Krank ' . $jahr, 'wert' => $tage . ' Tage',
            'url' => url('einsaetze', ['t' => 'zeiten'])];
    }
    if ($passt(['berichtsheft', 'nachweis', 'bericht', 'heft'])) {
        $art = $u['bh_art']; $per = periode_of(today(), $art);
        $rep = report_get($uid, $art, $per);
        $sum = report_sum((int)$rep['id']);
        $a[] = ['icon' => 'bericht', 'label' => 'Nachweis ' . periode_label($per, $art),
            'wert' => num((float)$sum['std'], 1) . ' h · ' . ($rep['status'] === 'fertig' ? 'fertig' : 'offen'),
            'url' => url('berichtsheft')];
    }
    if ($passt(['note', 'noten', 'schnitt', 'durchschnitt'])) {
        $g = noten_stats($uid);
        if ($g['schnitt'] !== null) {
            $a[] = ['icon' => 'noten', 'label' => 'Notenschnitt',
                'wert' => num((float)$g['schnitt'], 2) . ' aus ' . count($g['rows']) . ' Noten',
                'url' => url('noten')];
        }
    }
    if ($passt(['block', 'blockwoche', 'schulblock', 'schule'])) {
        $b = one("SELECT * FROM blocks WHERE user_id = ? AND art = 'schule' AND bis >= date('now','localtime')
                  ORDER BY von LIMIT 1", [$uid]);
        if ($b) {
            $laeuft = $b['von'] <= today();
            $a[] = ['icon' => 'plan', 'label' => $laeuft ? 'Blockwoche laeuft' : 'Naechste Blockwoche',
                'wert' => dt($b['von'], 'd.m.') . ' bis ' . dt($b['bis'], 'd.m.Y'),
                'url' => url('plan', ['t' => 'block'])];
        }
    }
    if ($passt(['ferien', 'schulferien'])) {
        $b = one("SELECT * FROM blocks WHERE user_id = ? AND art = 'ferien' AND bis >= date('now','localtime')
                  ORDER BY von LIMIT 1", [$uid]);
        if ($b) {
            $a[] = ['icon' => 'plan', 'label' => $b['von'] <= today() ? ($b['label'] ?: 'Ferien') . ' laeuft' : ($b['label'] ?: 'Naechste Ferien'),
                'wert' => dt($b['von'], 'd.m.') . ' bis ' . dt($b['bis'], 'd.m.Y'),
                'url' => url('plan', ['t' => 'block'])];
        }
    }
    if ($passt(['probe', 'pruefung', 'test', 'schulaufgabe', 'termin'])) {
        $e = one("SELECT e.*, s.short FROM events e LEFT JOIN subjects s ON s.id = e.subject_id
                  WHERE e.user_id = ? AND e.typ IN ('probe','test','pruefung','abgabe','frist')
                  AND e.datum >= date('now','localtime') ORDER BY e.datum LIMIT 1", [$uid]);
        if ($e) {
            $tage = tage(today(), $e['datum']);
            $a[] = ['icon' => 'termin', 'label' => 'Naechster Termin',
                'wert' => $e['titel'] . ' · ' . dt($e['datum'], 'd.m.') . ' (' . ($tage === 0 ? 'heute' : 'in ' . $tage . ' Tagen') . ')',
                'url' => url('plan', ['id' => (int)$e['id']])];
        }
    }
    if ($passt(['projekt', 'abschlussprojekt', 'doku', 'dokumentation', 'antrag', 'praesentation'])) {
        $pj = one("SELECT * FROM projekt WHERE user_id = ? ORDER BY id LIMIT 1", [$uid]);
        if ($pj) {
            $naechst = null;
            foreach (projekt_termine() as $k => $lbl) {
                if ($pj[$k] && $pj[$k] >= today() && ($naechst === null || $pj[$k] < $naechst[0])) $naechst = [$pj[$k], $lbl];
            }
            $a[] = ['icon' => 'projekt', 'label' => $pj['titel'] ?: 'Abschlussprojekt',
                'wert' => $naechst ? $naechst[1] . ' am ' . dt($naechst[0]) : 'Status: ' . $pj['status'],
                'url' => url('pruefung', ['t' => 'projekt'])];
        }
    }
    if ($passt(['klasse', 'jahr', 'ausbildungsjahr', 'lehrjahr'])) {
        $st = ausbildungsstand($u);
        $a[] = ['icon' => 'heute', 'label' => 'Ausbildungsstand',
            'wert' => trim((klasse_name($u) ? klasse_name($u) . ' · ' : '') . $st['jahr'] . '. Ausbildungsjahr'
                      . ($st['wechsel'] ? ' · ab ' . dt($st['wechsel']) . ' ' . klasse_name($u, $st['wechsel']) : '')),
            'url' => url('einstellungen')];
    }
    if ($passt(['raum', 'stunde', 'stundenplan', 'unterricht', 'morgen', 'fach'])) {
        $st = naechste_stunde($uid);
        if ($st) {
            $a[] = ['icon' => 'raster', 'label' => 'Naechste Stunde',
                'wert' => $st['kurz'] . ($st['raum'] ? ' · Raum ' . $st['raum'] : '') . ' · ' . $st['wann'],
                'url' => url('plan', ['t' => 'stundenplan'])];
        }
    }
    if ($passt(['abteilung', 'einsatz'])) {
        $ab = einsatz_am($uid, today()) ?: (string)$u['abteilung'];
        if ($ab !== '') {
            $a[] = ['icon' => 'einsatz', 'label' => 'Aktuelle Abteilung', 'wert' => $ab,
                'url' => url('einsaetze')];
        }
    }
    return array_slice($a, 0, 2);
}
/** Fach ueber Kuerzel oder Name finden; "LF3" soll auch "LF 3" treffen. */
function fach_finden(int $uid, string $q): int {
    $eng = mb_strtolower(preg_replace('/\s+/', '', $q));
    foreach (all("SELECT id, name, short FROM subjects WHERE user_id = ?", [$uid]) as $r) {
        foreach ([$r['short'], $r['name']] as $kandidat) {
            $k = mb_strtolower(preg_replace('/\s+/', '', (string)$kandidat));
            if ($k !== '' && ($k === $eng || str_starts_with($k, $eng))) return (int)$r['id'];
        }
    }
    return (int)val("SELECT id FROM subjects WHERE user_id = ? AND name LIKE ? LIMIT 1",
                    [$uid, '%' . $q . '%'], 0);
}
/** lf:9, typ:notiz oder fach:LF9 ohne Suchwort - dann ist der Filter die Frage. */
function such_filterliste(int $uid, array $f): array {
    $t = [];
    $fid = null;
    if ($f['fach']) {
        $fid = fach_finden($uid, $f['fach']);
        if (!$fid) return [];
    }
    // hat_lf: nur notes und events fuehren eine Lernfeldnummer
    foreach ([['notiz', 'notes', 'datum', 'titel', 'body', true],
              ['termin', 'events', 'datum', 'titel', 'beschreibung', true],
              ['aufgabe', 'tasks', "COALESCE(faellig,'')", 'titel', 'beschreibung', false]] as [$art, $tab, $d, $ti, $tx, $hat_lf]) {
        if ($f['typ'] && $f['typ'] !== $art) continue;
        if ($f['lf'] !== null && !$hat_lf) continue;
        $w = ['user_id = ?']; $a = [$uid];
        if ($f['lf'] !== null) { $w[] = 'lf_no = ?';      $a[] = $f['lf']; }
        if ($fid)              { $w[] = 'subject_id = ?'; $a[] = $fid; }
        foreach (all("SELECT id, $d AS datum, $ti AS titel, $tx AS text FROM $tab
                      WHERE " . implode(' AND ', $w) . " ORDER BY $d DESC LIMIT 30", $a) as $r) {
            $t[] = ['art' => $art, 'ref' => (int)$r['id'], 'datum' => $r['datum'],
                    'titel' => mb_substr((string)$r['titel'], 0, 120),
                    'aus' => mb_substr((string)$r['text'], 0, 120)];
        }
    }
    if ((!$f['typ'] || $f['typ'] === 'bericht') && !$fid) {
        $w = ['user_id = ?']; $a = [$uid];
        if ($f['lf'] !== null) { $w[] = 'lf_no = ?'; $a[] = $f['lf']; }
        foreach (all("SELECT id, datum, text FROM report_entries
                      WHERE " . implode(' AND ', $w) . " ORDER BY datum DESC LIMIT 30", $a) as $r) {
            $t[] = ['art' => 'bericht', 'ref' => (int)$r['id'], 'datum' => $r['datum'],
                    'titel' => mb_substr((string)$r['text'], 0, 120), 'aus' => ''];
        }
    }
    if ((!$f['typ'] || $f['typ'] === 'routine') && $f['lf'] === null && !$fid) {
        foreach (all("SELECT id, name FROM routines WHERE user_id = ? AND aktiv = 1
                      ORDER BY sort, name LIMIT 30", [$uid]) as $r) {
            $t[] = ['art' => 'routine', 'ref' => (int)$r['id'], 'datum' => '',
                    'titel' => $r['name'], 'aus' => ''];
        }
    }
    return $t;
}
/** Suche mitten im Wort - noetig, weil Deutsch zusammensetzt ("Kaffeemaschine"). */
function such_infix(int $uid, array $worte): array {
    $l = '%' . implode('%', $worte) . '%';
    $t = [];
    foreach ([['notiz', 'notes', 'datum', 'titel', 'body'],
              ['termin', 'events', 'datum', 'titel', 'beschreibung'],
              ['aufgabe', 'tasks', "COALESCE(faellig,'')", 'titel', 'beschreibung'],
              ['bericht', 'report_entries', 'datum', 'text', 'text'],
              ['routine', 'routines', "''", 'name', 'name']] as [$art, $tab, $d, $ti, $tx]) {
        foreach (all("SELECT id, $d AS datum, $ti AS titel, $tx AS text FROM $tab
                      WHERE user_id = ? AND ($ti LIKE ? OR $tx LIKE ?)
                      ORDER BY $d DESC LIMIT 6", [$uid, $l, $l]) as $r) {
            $t[] = ['art' => $art, 'ref' => (int)$r['id'], 'datum' => $r['datum'],
                    'titel' => mb_substr((string)$r['titel'], 0, 120),
                    'aus' => mb_substr((string)$r['text'], 0, 120)];
        }
    }
    return $t;
}
function such_ziel(string $art, int $ref, string $datum, array $u): string {
    return match ($art) {
        'notiz'   => url('notizen', ['id' => $ref]),
        'termin'  => url('plan', ['id' => $ref]),
        'aufgabe' => url('plan', ['t' => 'aufgaben', 'id' => $ref]),
        'bericht' => url('berichtsheft', ['periode' => periode_of($datum ?: today(), $u['bh_art']), 'art' => $u['bh_art']]),
        'kontakt'  => url('kontakte', ['id' => $ref]),
        'einsatz'  => url('einsaetze', ['id' => $ref]),
        'fach'     => url('faecher', ['id' => $ref]),
        'lernfeld' => url('notizen', ['lf' => $ref]),
        'note'     => url('noten', ['id' => $ref]),
        'projekt'  => url('pruefung', ['t' => 'projekt']),
        'abwesend' => url('einsaetze', ['t' => 'zeiten']),
        'block'    => url('plan', ['t' => 'block']),
        'position' => url('berichtsheft', ['t' => 'plan']),
        'datei'    => url('datei', ['id' => $ref]),
        default    => url('berichtsheft', ['t' => 'routinen', 'id' => $ref]),
    };
}
/** Symbolname je Trefferart. */
function art_icon(string $a): string {
    return ['notiz'=>'notizen','termin'=>'termin','aufgabe'=>'aufgabe','bericht'=>'bericht',
            'routine'=>'routine','kontakt'=>'kontakt','einsatz'=>'einsatz','fach'=>'faecher',
            'lernfeld'=>'liste','note'=>'noten','projekt'=>'projekt','abwesend'=>'frei',
            'block'=>'plan','position'=>'haken','datei'=>'datei'][$a] ?? 'notizen';
}
function art_label(string $a): string {
    return ['notiz'=>'Notiz','termin'=>'Termin','aufgabe'=>'Aufgabe','bericht'=>'Bericht','routine'=>'Routine',
            'kontakt'=>'Ansprechpartner','einsatz'=>'Einsatz','fach'=>'Fach','lernfeld'=>'Lernfeld',
            'note'=>'Note','projekt'=>'Projekt','abwesend'=>'Fehlzeit','block'=>'Schulblock',
            'position'=>'Berufsbildposition','datei'=>'Datei'][$a] ?? $a;
}

// ===========================================================================
//  Oberflaeche
// ===========================================================================

/**
 * Symbolsatz, 24er-Raster, nur Striche, currentColor. Inline, weil die CSP
 * keine fremden Quellen zulaesst und ein Symbol nie nachladen soll.
 */
function icons(): array {
    return [
        'heute'   => '<rect x="3" y="3.5" width="18" height="17" rx="2.8"/><path d="M3 9.5h18M10 9.5v11"/>',
        'faecher' => '<path d="M12 6.9C10.4 5.3 8.3 4.8 4.5 4.8v12.4c3.8 0 5.9.5 7.5 2.1 1.6-1.6 3.7-2.1 7.5-2.1V4.8c-3.8 0-5.9.5-7.5 2.1z"/><path d="M12 6.9v12.4"/>',
        'notizen' => '<path d="M12.5 20H20"/><path d="M16.2 3.8a2.05 2.05 0 0 1 2.9 2.9L8.6 17.2l-3.9 1 1-3.9L16.2 3.8z"/>',
        'noten'   => '<path d="M5 20v-5.5M12 20V8M19 20v-9"/><path d="M3.5 20h17"/>',
        'plan'    => '<rect x="3" y="5" width="18" height="16" rx="2.5"/><path d="M3 10h18M8 3v4M16 3v4"/><path d="M7.5 14h2M11 14h2M14.5 14h2M7.5 17.5h2M11 17.5h2"/>',
        'bericht' => '<path d="M9 4H7a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2"/><rect x="9" y="2.5" width="6" height="3.5" rx="1"/><path d="M8.5 12.5l2 2 4-4"/>',
        'einsatz' => '<rect x="2.5" y="7.5" width="19" height="13" rx="2"/><path d="M8.5 7.5V5.5a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v2"/><path d="M2.5 12.5h19"/>',
        'kontakt' => '<circle cx="12" cy="8" r="3.75"/><path d="M4.5 20.5a7.5 7.5 0 0 1 15 0"/>',
        'pruefung'=> '<circle cx="12" cy="9" r="6"/><path d="M8.6 14.2L7.5 21.5l4.5-2.6 4.5 2.6-1.1-7.3"/>',
        'suche'   => '<circle cx="11" cy="11" r="7"/><path d="M16.2 16.2L21 21"/>',
        'zahnrad' => '<path d="M4 21v-6M4 11V3M12 21v-8M12 9V3M20 21v-4M20 13V3"/><path d="M1.5 15h5M9.5 9h5M17.5 13h5"/>',
        'teilen'  => '<path d="M9.5 13.5a4.4 4.4 0 0 0 6.6.5l2.6-2.6a4.4 4.4 0 0 0-6.2-6.2l-1.5 1.5"/><path d="M14.5 10.5a4.4 4.4 0 0 0-6.6-.5l-2.6 2.6a4.4 4.4 0 0 0 6.2 6.2l1.5-1.5"/>',
        'termin'  => '<circle cx="12" cy="12" r="8.5"/><path d="M12 6.8V12l3.4 2"/>',
        'routine' => '<path d="M16.5 2.5L20 6l-3.5 3.5"/><path d="M3.5 11V9.5A3.5 3.5 0 0 1 7 6h13"/><path d="M7.5 21.5L4 18l3.5-3.5"/><path d="M20.5 13v1.5a3.5 3.5 0 0 1-3.5 3.5H4"/>',
        'aufgabe' => '<rect x="3.5" y="3.5" width="17" height="17" rx="3"/><path d="M8 12.2l2.6 2.6L16 9.5"/>',
        'datei'   => '<path d="M13.5 3.5H7A2 2 0 0 0 5 5.5v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V9z"/><path d="M13.5 3.5V9H19"/>',
        'projekt' => '<path d="M5 21V4.5"/><path d="M5 5h11.5l-2 3.5 2 3.5H5"/>',
        'schloss' => '<rect x="4" y="10.5" width="16" height="10.5" rx="2.4"/><path d="M8 10.5V7.2a4 4 0 0 1 8 0v3.3"/>',
        'frei'    => '<circle cx="12" cy="12" r="4.2"/><path d="M12 2.5v2.6M12 18.9v2.6M4.2 12H1.6M22.4 12h-2.6M6.5 6.5L4.6 4.6M19.4 19.4l-1.9-1.9M17.5 6.5l1.9-1.9M4.6 19.4l1.9-1.9"/>',
        'import'  => '<path d="M12 3.5v11"/><path d="M8 11l4 4 4-4"/><path d="M4.5 17v2a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-2"/>',
        'raster'  => '<rect x="3" y="4" width="18" height="16" rx="2.4"/><path d="M3 9.5h18M9 9.5V20M15 9.5V20"/>',
        'liste'   => '<path d="M9 6.5h11M9 12h11M9 17.5h11"/><path d="M4.5 6.5h.01M4.5 12h.01M4.5 17.5h.01"/>',
        'haken'   => '<path d="M4 12.5l5 5L20 6.5"/>',
        'weiter'  => '<path d="M9 5.5L15.5 12 9 18.5"/>',
        'zurueck' => '<path d="M15 5.5L8.5 12l6.5 6.5"/>',
    ];
}
function ic(string $name, int $groesse = 20): string {
    $pfad = icons()[$name] ?? '';
    if ($pfad === '') return '';
    return '<svg class="ic" width="' . $groesse . '" height="' . $groesse . '" viewBox="0 0 24 24"'
         . ' fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"'
         . ' stroke-linejoin="round" aria-hidden="true">' . $pfad . '</svg>';
}

/**
 * Jedes Ziel der App mit den Woertern, unter denen jemand danach sucht.
 * Symbol, Beschriftung, Bereich, Suchwoerter, Adresse.
 */
function ziele_index(): array {
    static $z = null;
    if ($z !== null) return $z;
    // Symbol, Beschriftung, Bereich, Suchwoerter, Adresse, Farbe.
    // Die Farbe haengt am Ziel, nicht am Symbol - sonst sehen Termine,
    // Stundenplan und Blockplan identisch aus.
    return $z = [
        ['heute',   'Heute',              '',              'start uebersicht dashboard tag heute jetzt',                        url('start'),                                  '#ff3b30'],
        ['faecher', 'Faecher',            'Schule',        'fach faecher lernfeld lernfelder lf unterricht stoff material',     url('faecher'),                                '#0071e3'],
        ['notizen', 'Notizen',            'Schule',        'notiz notizen howto snippet code stoff merken zettel',              url('notizen'),                                '#ff9500'],
        ['noten',   'Noten',              'Schule',        'note noten schnitt durchschnitt zeugnis punkte bewertung',          url('noten'),                                  '#34c759'],
        ['termin',  'Termine',            'Plan',          'termin termine kalender probe schulaufgabe test abgabe frist',      url('plan'),                                   '#5856d6'],
        ['aufgabe', 'Aufgaben',           'Plan',          'aufgabe aufgaben hausaufgabe offen erledigen',                      url('plan', ['t' => 'aufgaben']),              '#30b0c7'],
        ['raster',  'Stundenplan',        'Plan',          'stundenplan stunde raum unterricht wann wo',                        url('plan', ['t' => 'stundenplan']),           '#7d5fff'],
        ['plan',    'Blockplan',          'Plan',          'block blockplan blockwoche schulwoche ferien schulblock',           url('plan', ['t' => 'block']),                 '#0f5fa8'],
        ['bericht', 'Berichtsheft',       'Betrieb',       'berichtsheft nachweis ausbildungsnachweis wochenbericht bericht ihk woche monat', url('berichtsheft'),               '#af52de'],
        ['liste',   'Alle Nachweise',     'Betrieb',       'alle nachweise berichte heft',                                      url('berichtsheft', ['t' => 'alle']),          '#c77dff'],
        ['haken',   'Ausbildungsplan',    'Betrieb',       'ausbildungsplan berufsbildposition fiausbv fortschritt abgedeckt',  url('berichtsheft', ['t' => 'plan']),          '#8b5cf6'],
        ['routine', 'Routinen',           'Betrieb',       'routine routinen wiederkehrend kaffeemaschine taeglich woechentlich', url('berichtsheft', ['t' => 'routinen']),    '#ffcc00'],
        ['einsatz', 'Einsaetze',          'Betrieb',       'einsatz einsaetze abteilung durchlauf versetzung wechsel',          url('einsaetze'),                              '#a2845e'],
        ['frei',    'Fehlzeiten & Urlaub','Betrieb',       'urlaub resturlaub fehlzeit fehlzeiten krank krankheit frei abwesend dienstreise schulung entschuldigung', url('einsaetze', ['t' => 'zeiten']), '#00b894'],
        ['kontakt', 'Kontakte',           'Betrieb',       'kontakt kontakte ansprechpartner ausbilder ausbilderin lehrer telefon mail ihk nummer', url('kontakte'),           '#00c7be'],
        ['pruefung','Pruefung',           'Abschluss',     'pruefung abschlusspruefung ap1 ap2 teil punkte prognose bestehen',  url('pruefung'),                               '#ff2d55'],
        ['projekt', 'Abschlussprojekt',   'Abschluss',     'projekt abschlussprojekt antrag genehmigung doku dokumentation praesentation frist stunden', url('pruefung', ['t' => 'projekt']), '#e11d48'],
        ['liste',   'Lernfelder',         'Abschluss',     'lernfeld lernfelder lf lehrplan rahmenlehrplan',                    url('pruefung', ['t' => 'lf']),                '#00a09a'],
        ['kontakt', 'Profil',             'Einstellungen', 'profil einstellungen konto klasse schule betrieb beruf beginn',     url('einstellungen'),                          '#8e8e93'],
        ['import',  'Einrichtung',        'Einstellungen', 'einrichtung einrichten setup verbinden apps app webuntis untis moodle mebis blockplan ferien stundenplan laden', url('einrichtung'), '#0071e3'],
        ['import',  'Quellen',            'Einstellungen', 'quelle quellen import ical kalender abonnement sync', url('einstellungen', ['t' => 'quellen']), '#5ac8fa'],
        ['schloss', 'Sicherheit',         'Einstellungen', 'sicherheit passwort kennwort zwei-faktor 2fa totp sitzung anmeldung', url('einstellungen', ['t' => 'sicherheit']), '#636366'],
        ['datei',   'Daten & Export',     'Einstellungen', 'daten export backup sicherung csv json kalenderadresse konto loeschen', url('einstellungen', ['t' => 'daten']),    '#48484a'],
        ['teilen',  'Geteilte Links',     'Einstellungen', 'geteilt teilen link freigabe share oeffentlich',                    url('geteilt'),                                '#98989d'],
    ];
}
/**
 * Zaehlt, welches Ziel gerade offen ist. Hoechstens ein Schreibvorgang je
 * Ziel und Viertelstunde, damit SQLite nicht unnoetig sperrt.
 */
function ziel_zaehlen(array $u, string $seite): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    $jetzt = $_GET; unset($jetzt['p']);
    $adresse = url($seite, array_intersect_key($jetzt, ['t' => 1]));
    $treffer = null;
    foreach (ziele_index() as $z) if ($z[4] === $adresse) { $treffer = $z[1]; break; }
    if ($treffer === null) return;
    $marke = $_SESSION['zz'][$treffer] ?? 0;
    if (time() - $marke < 900) return;
    $_SESSION['zz'][$treffer] = time();
    q("INSERT INTO ziel_nutzung (user_id, ziel, anzahl, letzt) VALUES (?, ?, 1, ?)
       ON CONFLICT(user_id, ziel) DO UPDATE SET anzahl = anzahl + 1, letzt = excluded.letzt",
      [(int)$u['id'], $treffer, today()]);
}
/** Wie oft wurde welches Ziel geoeffnet? */
function ziel_nutzung(int $uid): array {
    static $n = null;
    if ($n !== null) return $n;
    $n = [];
    foreach (all("SELECT ziel, anzahl, letzt FROM ziel_nutzung WHERE user_id = ?", [$uid]) as $r) {
        $n[$r['ziel']] = ['anzahl' => (int)$r['anzahl'], 'letzt' => (string)$r['letzt']];
    }
    return $n;
}
/** Wie gut passt ein Ziel zur Eingabe? 0 heisst: gar nicht. */
function ziel_rang(array $z, string $q): int {
    $l = mb_strtolower($z[1]);
    $q = mb_strtolower(trim($q));
    if ($q === '') return 10;
    if ($l === $q) return 1200;
    if (str_starts_with($l, $q)) return 1000;
    foreach (preg_split('/[\s&]+/', $l) as $w) if ($w !== '' && str_starts_with($w, $q)) return 900;
    if (str_contains($l, $q)) return 700;
    $woerter = explode(' ', $z[3]);
    foreach ($woerter as $w) {
        if ($w === $q) return 650;
        if ($w !== '' && str_starts_with($w, $q)) return 550;
    }
    if (mb_strlen($q) >= 4) foreach ($woerter as $w) if ($w !== '' && str_contains($w, $q)) return 350;
    return 0;
}
/**
 * Passende Ziele, bestes zuerst. Was jemand oft oeffnet, steigt - aber
 * hoechstens um 26 Punkte. Die Rangklassen liegen 100 Punkte auseinander,
 * ein genauer Treffer wird also nie verdraengt.
 */
function ziele_suchen(string $q, int $limit = 6): array {
    $u = me();
    $nutzung = $u ? ziel_nutzung((int)$u['id']) : [];
    $t = [];
    foreach (ziele_index() as $z) {
        $r = ziel_rang($z, $q);
        if ($r <= 0) continue;
        $bonus = 0.0;
        if (isset($nutzung[$z[1]])) {
            $bonus = min(20.0, 5.0 * sqrt((float)$nutzung[$z[1]]['anzahl']));
            if ($nutzung[$z[1]]['letzt'] >= date('Y-m-d', strtotime('-7 days'))) $bonus += 6;
        }
        $t[] = ['rang' => $r + $bonus, 'icon' => $z[0], 'label' => $z[1], 'bereich' => $z[2],
                'url' => $z[4], 'farbe' => $z[5] ?? '#8e8e93'];
    }
    // Selbst verknuepfte Schul-Apps liegen in derselben Rangfolge wie die eigenen Seiten
    if ($u) {
        foreach (all("SELECT name, url FROM ziele WHERE user_id = ? ORDER BY sort, id", [(int)$u['id']]) as $zl) {
            $hatQ = str_contains((string)$zl['url'], '%s');
            if ($hatQ && trim($q) === '') continue;
            $adr = $hatQ ? str_replace('%s', rawurlencode(trim($q)), (string)$zl['url']) : (string)$zl['url'];
            $z = ['import', (string)$zl['name'], 'Verknuepft', mb_strtolower((string)$zl['name']), $adr, '#5ac8fa'];
            $r = ziel_rang($z, $q);
            if ($r <= 0) continue;
            $t[] = ['rang' => $r, 'icon' => $z[0], 'label' => $z[1], 'bereich' => $z[2],
                    'url' => $z[4], 'farbe' => $z[5]];
        }
    }
    usort($t, fn($a, $b) => [$b['rang'], mb_strlen($a['label'])] <=> [$a['rang'], mb_strlen($b['label'])]);
    return array_slice($t, 0, $limit);
}

// ===========================================================================

/** Seitenleiste in drei Bloecken: alles liegt dort, wo man es sucht. */
function nav_gruppen(): array {
    static $g = null;
    if ($g !== null) return $g;
    // [Schluessel, Beschriftung, Symbol, [[Beschriftung, Symbol, Adresse], ...]]
    return $g = [
        ['heute', 'Heute', 'heute', []],
        ['schule', 'Schule', 'faecher', [
            ['Faecher', 'faecher', url('faecher')],
            ['Notizen', 'notizen', url('notizen')],
            ['Noten',   'noten',   url('noten')],
        ]],
        ['plan', 'Plan', 'plan', [
            ['Termine',     'termin',  url('plan')],
            ['Aufgaben',    'aufgabe', url('plan', ['t' => 'aufgaben'])],
            ['Stundenplan', 'raster',  url('plan', ['t' => 'stundenplan'])],
            ['Blockplan',   'plan',    url('plan', ['t' => 'block'])],
        ]],
        ['betrieb', 'Betrieb', 'bericht', [
            ['Berichtsheft', 'bericht', url('berichtsheft')],
            ['Einsaetze',    'einsatz', url('einsaetze')],
            ['Kontakte',     'kontakt', url('kontakte')],
        ]],
        ['abschluss', 'Abschluss', 'pruefung', [
            ['Pruefung',   'pruefung', url('pruefung')],
            ['Projekt',    'projekt',  url('pruefung', ['t' => 'projekt'])],
            ['Lernfelder', 'liste',    url('pruefung', ['t' => 'lf'])],
        ]],
        ['mehr', 'Mehr', 'zahnrad', [
            ['Alles',       'liste',   url('mehr')],
            ['Einrichtung', 'import',  url('einrichtung')],
            ['Profil',      'kontakt', url('einstellungen')],
            ['Quellen',    'import',  url('einstellungen', ['t' => 'quellen'])],
            ['Sicherheit', 'schloss', url('einstellungen', ['t' => 'sicherheit'])],
            ['Daten',      'datei',   url('einstellungen', ['t' => 'daten'])],
        ]],
    ];
}
/** Wohin fuehrt eine Gruppe? Auf ihren ersten Unterpunkt, sonst auf sich selbst. */
function gruppe_url(array $g): string {
    return $g[3] ? $g[3][0][2] : url('start');
}
/** Welche Gruppe ist offen? */
function gruppe_aktiv(string $p): string {
    return match ($p) {
        'start' => 'heute',
        'faecher', 'notizen', 'noten' => 'schule',
        'plan' => 'plan',
        'berichtsheft', 'einsaetze', 'kontakte' => 'betrieb',
        'pruefung' => 'abschluss',
        'mehr', 'einrichtung', 'einstellungen', 'geteilt' => 'mehr',
        default => '',
    };
}
/** Die aktuelle Adresse ohne alles, was nicht zur Navigation gehoert. */
function nav_adresse(string $p): string {
    $t = is_string($_GET['t'] ?? null) ? $_GET['t'] : '';
    return url($p, $t !== '' ? ['t' => $t] : []);
}
/** Alle Unterpunkte flach - fuer Tastenkuerzel und Sprungliste. */
function nav(): array {
    $o = [[url('start'), 'Heute', 'heute']];
    foreach (nav_gruppen() as $g) foreach ($g[3] as [$lbl, $sym, $adr]) $o[] = [$adr, $lbl, $sym];
    return $o;
}
/**
 * Die eine Frage, die die Uhr gerade stellt. Streng nach Zeitnaehe geordnet;
 * jede Karte fuehrt auf die Seite, auf der man es selbst nachsieht.
 * @return array{icon:string,farbe:string,titel:string,kontext:string,url:string}|null
 */
function jetzt_karte(array $u): ?array {
    $uid = (int)$u['id'];
    $heute = today();
    $jetzt = date('H:i');

    // Laeuft gerade Unterricht? Braucht Uhrzeiten aus einer Quelle.
    $e = one("SELECT e.*, s.short, s.name AS fach FROM events e LEFT JOIN subjects s ON s.id = e.subject_id
              WHERE e.user_id = ? AND e.typ = 'unterricht' AND e.datum = ?
              AND e.zeit_von <> '' AND e.zeit_von <= ? AND (e.zeit_bis = '' OR e.zeit_bis >= ?)
              ORDER BY e.zeit_von LIMIT 1", [$uid, $heute, $jetzt, $jetzt]);
    if ($e) {
        $folgt = one("SELECT e.*, s.short FROM events e LEFT JOIN subjects s ON s.id = e.subject_id
                      WHERE e.user_id = ? AND e.typ = 'unterricht' AND e.datum = ? AND e.zeit_von > ?
                      ORDER BY e.zeit_von LIMIT 1", [$uid, $heute, $e['zeit_von']]);
        return ['icon' => 'raster', 'farbe' => '#7d5fff',
            'titel' => trim(($e['short'] ?: $e['titel']) . ($e['raum'] ? ' · Raum ' . $e['raum'] : '')),
            'kontext' => 'bis ' . substr((string)$e['zeit_bis'], 0, 5)
                       . ($folgt ? ', danach ' . ($folgt['short'] ?: $folgt['titel']) : ''),
            'url' => url('plan', ['t' => 'stundenplan'])];
    }

    // Termin heute
    $e = one("SELECT e.*, s.short FROM events e LEFT JOIN subjects s ON s.id = e.subject_id
              WHERE e.user_id = ? AND e.typ <> 'unterricht' AND e.datum = ?
              ORDER BY e.zeit_von LIMIT 1", [$uid, $heute]);
    if ($e) {
        return ['icon' => 'termin', 'farbe' => '#5856d6', 'titel' => $e['titel'] . ' heute',
            'kontext' => trim(typ_label($e['typ']) . ($e['zeit_von'] ? ' · ' . substr((string)$e['zeit_von'], 0, 5) : '')
                         . ($e['short'] ? ' · ' . $e['short'] : '')),
            'url' => url('plan', ['id' => (int)$e['id']])];
    }

    // Ueberfaellige Aufgabe
    $t = one("SELECT * FROM tasks WHERE user_id = ? AND status = 'offen'
              AND faellig IS NOT NULL AND faellig < ? ORDER BY faellig LIMIT 1", [$uid, $heute]);
    if ($t) {
        $tage = tage($t['faellig'], $heute);
        return ['icon' => 'aufgabe', 'farbe' => '#ff3b30', 'titel' => $t['titel'],
            'kontext' => $tage . ' ' . ($tage === 1 ? 'Tag' : 'Tage') . ' ueberfaellig',
            'url' => url('plan', ['t' => 'aufgaben', 'id' => (int)$t['id']])];
    }

    // Termin morgen
    $morgen = date('Y-m-d', strtotime('+1 day'));
    $e = one("SELECT e.*, s.short FROM events e LEFT JOIN subjects s ON s.id = e.subject_id
              WHERE e.user_id = ? AND e.typ <> 'unterricht' AND e.datum = ?
              ORDER BY e.zeit_von LIMIT 1", [$uid, $morgen]);
    if ($e) {
        return ['icon' => 'termin', 'farbe' => '#ff9500', 'titel' => $e['titel'] . ' morgen',
            'kontext' => trim(typ_label($e['typ']) . ($e['stoff'] ? ' · ' . mb_substr(str_replace("\n", ', ', $e['stoff']), 0, 60) : '')),
            'url' => url('plan', ['id' => (int)$e['id']])];
    }

    // Projektfrist in Sicht
    $pj = one("SELECT * FROM projekt WHERE user_id = ? ORDER BY id LIMIT 1", [$uid]);
    if ($pj) {
        // Als Frist gelesen braucht es die Handlung, nicht den Zustand
        $tun = ['antrag' => 'Antrag einreichen', 'genehmigt' => 'Genehmigung erwartet',
                'von' => 'Projekt beginnt', 'bis' => 'Projekt abschliessen',
                'doku' => 'Dokumentation abgeben', 'praesentation' => 'Praesentation'];
        foreach (projekt_termine() as $k => $lbl) {
            if (!$pj[$k] || $pj[$k] < $heute) continue;
            $tage = tage($heute, $pj[$k]);
            if ($tage > 14) break;
            return ['icon' => 'projekt', 'farbe' => '#e11d48',
                'titel' => ($tun[$k] ?? $lbl) . ' in ' . $tage . ' ' . ($tage === 1 ? 'Tag' : 'Tagen'),
                'kontext' => $pj['titel'] ?: 'Abschlussprojekt',
                'url' => url('pruefung', ['t' => 'projekt'])];
        }
    }

    // Gegen Ende der Woche: steht der Nachweis?
    $wtag = (int)date('N');
    $art = $u['bh_art']; $per = periode_of($heute, $art);
    $rep = report_get($uid, $art, $per);
    if ($wtag >= 4 && $rep['status'] !== 'fertig') {
        $sum = report_sum((int)$rep['id']);
        $leer = 0;
        $d = new DateTimeImmutable($rep['von']); $ende = new DateTimeImmutable(min($rep['bis'], $heute));
        while ($d <= $ende) {
            if ((int)$d->format('N') <= 5 && empty($sum['tag'][$d->format('Y-m-d')])) $leer++;
            $d = $d->modify('+1 day');
        }
        return ['icon' => 'bericht', 'farbe' => '#af52de',
            'titel' => 'Nachweis ' . periode_label($per, $art) . ' offen',
            'kontext' => num((float)$sum['std'], 1) . ' h erfasst'
                       . ($leer ? ' · ' . $leer . ' ' . ($leer === 1 ? 'Tag' : 'Tage') . ' ohne Eintrag' : ''),
            'url' => url('berichtsheft')];
    }

    // Laufender Block oder Ferien
    $b = one("SELECT * FROM blocks WHERE user_id = ? AND ? BETWEEN von AND bis
              ORDER BY (art = 'schule') DESC LIMIT 1", [$uid, $heute]);
    if ($b) {
        $rest = tage($heute, $b['bis']);
        return ['icon' => $b['art'] === 'schule' ? 'plan' : 'frei',
            'farbe' => $b['art'] === 'schule' ? '#0f5fa8' : '#00b894',
            'titel' => ($b['label'] ?: ucfirst((string)$b['art'])) . ' bis ' . dt($b['bis'], 'd.m.'),
            'kontext' => $rest > 0 ? 'noch ' . $rest . ' ' . ($rest === 1 ? 'Tag' : 'Tage') : 'letzter Tag',
            'url' => url('plan', ['t' => 'block'])];
    }

    // Sonst: der naechste Termin, wenn er in Sicht ist
    $e = one("SELECT e.*, s.short FROM events e LEFT JOIN subjects s ON s.id = e.subject_id
              WHERE e.user_id = ? AND e.typ <> 'unterricht' AND e.datum > ?
              ORDER BY e.datum, e.zeit_von LIMIT 1", [$uid, $heute]);
    if ($e) {
        $tage = tage($heute, $e['datum']);
        if ($tage <= 21) {
            return ['icon' => 'termin', 'farbe' => '#5856d6',
                'titel' => $e['titel'], 'kontext' => 'in ' . $tage . ' Tagen · ' . dt($e['datum'], 'D d.m.'),
                'url' => url('plan', ['id' => (int)$e['id']])];
        }
    }
    return null;
}
/**
 * Die naechste Unterrichtsstunde: heute nach der aktuellen Uhrzeit, sonst der
 * naechste Werktag aus dem Stundenplan.
 * @return array{kurz:string,raum:string,wann:string}|null
 */
function naechste_stunde(int $uid): ?array {
    $e = one("SELECT e.*, s.short, s.name FROM events e LEFT JOIN subjects s ON s.id = e.subject_id
              WHERE e.user_id = ? AND e.typ = 'unterricht' AND e.datum = ? AND e.zeit_von > ?
              ORDER BY e.zeit_von LIMIT 1", [$uid, today(), date('H:i')]);
    if ($e) {
        return ['kurz' => (string)($e['short'] ?: $e['titel']), 'raum' => (string)$e['raum'],
                'wann' => 'heute ' . substr((string)$e['zeit_von'], 0, 5)];
    }
    for ($i = 1; $i <= 7; $i++) {
        $tag = (int)date('N', strtotime('+' . $i . ' day'));
        if ($tag > 5) continue;
        $r = one("SELECT t.raum, s.short, s.name FROM timetable t
                  LEFT JOIN subjects s ON s.id = t.subject_id
                  WHERE t.user_id = ? AND t.tag = ? AND t.subject_id IS NOT NULL
                  ORDER BY t.stunde LIMIT 1", [$uid, $tag]);
        if ($r) {
            return ['kurz' => (string)($r['short'] ?: $r['name']), 'raum' => (string)$r['raum'],
                    'wann' => $i === 1 ? 'morgen' : wd($tag)];
        }
    }
    return null;
}
/** Aktueller Wert je Ziel - die Zielliste ist zugleich die Antwortliste. */
function ziel_werte(array $u): array {
    $uid = (int)$u['id'];
    $w = [];
    $n = fn(string $sql, array $a = []) => (int)val($sql, $a, 0);

    $g = noten_stats($uid);
    if ($g['schnitt'] !== null) $w['Noten'] = num((float)$g['schnitt'], 2);

    $e = one("SELECT datum, titel FROM events WHERE user_id = ? AND typ <> 'unterricht'
              AND datum >= date('now','localtime') ORDER BY datum LIMIT 1", [$uid]);
    if ($e) $w['Termine'] = dt($e['datum'], 'd.m.');
    $anz = $n("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status = 'offen'", [$uid]);
    if ($anz) $w['Aufgaben'] = $anz . ' offen';

    $b = one("SELECT von, bis FROM blocks WHERE user_id = ? AND art = 'schule'
              AND bis >= date('now','localtime') ORDER BY von LIMIT 1", [$uid]);
    if ($b) $w['Blockplan'] = ($b['von'] <= today() ? 'bis ' . dt($b['bis'], 'd.m.') : 'ab ' . dt($b['von'], 'd.m.'));

    $art = $u['bh_art']; $rep = report_get($uid, $art, periode_of(today(), $art));
    $sum = report_sum((int)$rep['id']);
    $w['Berichtsheft'] = $rep['status'] === 'fertig' ? 'fertig' : num((float)$sum['std'], 1) . ' h';
    $belegt = $n("SELECT COUNT(DISTINCT e.category_id) FROM report_entries e
                  JOIN categories c ON c.id = e.category_id
                  WHERE e.user_id = ? AND c.abschnitt <> 'X'", [$uid]);
    $ges = $n("SELECT COUNT(*) FROM categories WHERE abschnitt <> 'X'");
    if ($ges) $w['Ausbildungsplan'] = $belegt . ' von ' . $ges;

    $ab = einsatz_am($uid, today()) ?: (string)$u['abteilung'];
    if ($ab !== '') $w['Einsaetze'] = mb_substr($ab, 0, 22);
    $jahr = date('Y'); $genommen = 0;
    foreach (all("SELECT von, bis FROM absences WHERE user_id = ? AND art = 'urlaub' AND von LIKE ?",
                 [$uid, $jahr . '%']) as $r) $genommen += werktage($r['von'], $r['bis']);
    $ansp = (float)$u['urlaub_tage'];
    if ($ansp > 0) $w['Fehlzeiten & Urlaub'] = num(max(0, $ansp - $genommen), 0) . ' T frei';

    $cd = fn(?string $d) => $d ? tage(today(), $d) : null;
    $t1 = $cd($u['ap1']); $t2 = $cd($u['ap2']);
    if ($t2 !== null && $t2 > 0) $w['Pruefung'] = 'in ' . $t2 . ' T';
    elseif ($t1 !== null && $t1 > 0) $w['Pruefung'] = 'Teil 1 in ' . $t1 . ' T';
    $pj = one("SELECT * FROM projekt WHERE user_id = ? ORDER BY id LIMIT 1", [$uid]);
    if ($pj) {
        $naechst = null;
        foreach (array_keys(projekt_termine()) as $k) {
            if ($pj[$k] && $pj[$k] >= today() && ($naechst === null || $pj[$k] < $naechst)) $naechst = $pj[$k];
        }
        $w['Abschlussprojekt'] = $naechst ? dt($naechst, 'd.m.') : (string)$pj['status'];
    }
    $st = naechste_stunde($uid);
    if ($st) $w['Stundenplan'] = $st['kurz'] . ($st['raum'] ? ' · ' . $st['raum'] : '');
    if ((int)$u['totp_enabled'] === 1) $w['Sicherheit'] = 'Zwei-Faktor an';
    $w['Profil'] = klasse_name($u) ?: (string)$u['username'];
    return $w;
}
/** Alles auf einen Blick, nach Bereichen gruppiert - der Weg zu jedem Ziel. */
function p_mehr(): void {
    $u = need_login();
    $werte = ziel_werte($u);
    $gruppen = [];
    foreach (ziele_index() as [$sym, $label, $bereich, , $adresse, $farbe]) {
        $gruppen[$bereich ?: 'Start'][] = [$sym, $label, $adresse, $farbe];
    }
    foreach (all("SELECT name, url FROM ziele WHERE user_id = ? ORDER BY sort, id", [(int)$u['id']]) as $zl) {
        if (str_contains((string)$zl['url'], '%s')) continue;   // ohne Suchwort ins Leere
        $gruppen['Verknuepft'][] = ['import', (string)$zl['name'], (string)$zl['url'], '#5ac8fa'];
    }
    ob_start(); ?>
    <?php foreach ($gruppen as $bereich => $eintraege): ?>
      <div class="c"><?php if ($bereich !== 'Start'): ?><div class="hd"><h2><?= h($bereich) ?></h2></div><?php endif; ?>
        <ul class="li rows">
          <?php foreach ($eintraege as [$sym, $label, $adresse, $farbe]): ?>
            <li><a href="<?= h($adresse) ?>">
              <span class="tile" style="background:<?= h($farbe) ?>"><?= ic($sym, 17) ?></span>
              <span class="tx"><b><?= h($label) ?></b></span>
              <?php if (!empty($werte[$label])): ?>
                <span class="sm mu" style="flex:none"><?= h($werte[$label]) ?></span>
              <?php endif; ?>
              <?= ic('weiter', 17) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>
    <div class="c"><div class="bo">
      <div class="rw" style="justify-content:space-between">
        <div><b><?= h($u['username']) ?></b><div class="sm mu2"><?= stand_text($u) ?></div></div>
        <form method="post" action="<?= url('logout') ?>"><?= csrf_field() ?>
          <button class="s" type="submit">Abmelden</button></form>
      </div>
    </div></div>
    <?php
    page('Mehr', ob_get_clean());
}
function nav_zahl(string $key): string {
    $u = me(); if (!$u) return '';
    static $c = null;
    if ($c === null) {
        $uid = (int)$u['id'];
        $c = [
            'plan' => (int)val("SELECT COUNT(*) FROM events WHERE user_id = ? AND typ <> 'unterricht'
                                AND datum BETWEEN date('now','localtime') AND date('now','localtime','+7 day')", [$uid], 0)
                    + (int)val("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status='offen'
                                AND faellig IS NOT NULL AND faellig <= date('now','localtime','+2 day')", [$uid], 0),
        ];
    }
    return ($c[$key] ?? 0) > 0 ? (string)$c[$key] : '';
}

function page(string $titel, string $inhalt, array $o = []): void {
    $u = me(); $n = $GLOBALS['NONCE']; $p = is_string($_GET['p'] ?? null) ? $_GET['p'] : 'heute';
    if ($u && empty($o['bare'])) ziel_zaehlen($u, $p);
    $theme = $u['theme'] ?? 'auto';
    $flash = take_flash();
    $bare = !empty($o['bare']);
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html lang="de" data-t="<?= h($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<title><?= h($titel) ?> – <?= h(APP_NAME) ?></title>
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="6" fill="#2563eb"/><path d="M9 9h14M9 16h10M9 23h6" stroke="#fff" stroke-width="2.6" stroke-linecap="round"/></svg>') ?>">
<style>
:root{
 --bg:#f5f5f7; --sb:#ececee; --pa:#ffffff; --pa2:#f5f5f7; --pa3:#ebebed;
 --fg:#1d1d1f; --fg2:#6e6e73; --fg3:#737378;
 --li:rgba(0,0,0,.09); --li2:rgba(0,0,0,.16);
 --ac:#0071e3; --acb:rgba(0,113,227,.10); --acf:#fff;
 --ok:#1d8a4a; --okb:rgba(29,138,74,.12);
 --wa:#9a6400; --wab:rgba(200,140,0,.14);
 --er:#c8102e; --erb:rgba(200,16,46,.10);
 --r:10px; --r2:7px;
 --sh:0 1px 2px rgba(0,0,0,.05),0 0 0 .5px rgba(0,0,0,.05);
 --sh2:0 12px 40px rgba(0,0,0,.18),0 0 0 .5px rgba(0,0,0,.1);
 --mo:ui-monospace,"SF Mono","Cascadia Mono","Segoe UI Mono",Menlo,Consolas,monospace;
}
@media(prefers-color-scheme:dark){:root:not([data-t=hell]){
 --bg:#1c1c1e; --sb:#232325; --pa:#2c2c2e; --pa2:#242426; --pa3:#3a3a3c;
 --fg:#f5f5f7; --fg2:#a1a1a6; --fg3:#98989d;
 --li:rgba(255,255,255,.10); --li2:rgba(255,255,255,.18);
 --ac:#0a84ff; --acb:rgba(10,132,255,.18); --acf:#fff;
 --ok:#32d74b; --okb:rgba(50,215,75,.16);
 --wa:#ffd60a; --wab:rgba(255,214,10,.14);
 --er:#ff453a; --erb:rgba(255,69,58,.16);
 --sh:0 1px 2px rgba(0,0,0,.4),0 0 0 .5px rgba(255,255,255,.06);
 --sh2:0 12px 40px rgba(0,0,0,.6),0 0 0 .5px rgba(255,255,255,.1);
}}
:root[data-t=dunkel]{
 --bg:#1c1c1e; --sb:#232325; --pa:#2c2c2e; --pa2:#242426; --pa3:#3a3a3c;
 --fg:#f5f5f7; --fg2:#a1a1a6; --fg3:#98989d;
 --li:rgba(255,255,255,.10); --li2:rgba(255,255,255,.18);
 --ac:#0a84ff; --acb:rgba(10,132,255,.18); --acf:#fff;
 --ok:#32d74b; --okb:rgba(50,215,75,.16); --wa:#ffd60a; --wab:rgba(255,214,10,.14);
 --er:#ff453a; --erb:rgba(255,69,58,.16);
 --sh:0 1px 2px rgba(0,0,0,.4),0 0 0 .5px rgba(255,255,255,.06);
 --sh2:0 12px 40px rgba(0,0,0,.6),0 0 0 .5px rgba(255,255,255,.1);
}
*,*::before,*::after{box-sizing:border-box}
html,body{margin:0;padding:0}
body{background:var(--bg);color:var(--fg);font:15px/1.47 -apple-system,BlinkMacSystemFont,"SF Pro Text",
"Segoe UI Variable Text","Segoe UI",Inter,system-ui,sans-serif;font-variant-numeric:tabular-nums;
-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;letter-spacing:-.005em}
a{color:var(--ac);text-decoration:none}
@media(hover:hover){a:hover{text-decoration:underline}}
h1,h2,h3{margin:0;font-weight:600;line-height:1.25;letter-spacing:-.015em}
h1{font-size:17px}
/* Abschnittsueberschrift muss groesser sein als das, was sie ueberschreibt */
h2{font-size:17px;color:var(--fg);letter-spacing:-.02em;font-weight:600}
h3{font-size:15px}
p{margin:0 0 8px}
code,pre,.mo{font-family:var(--mo);font-size:12.5px}
pre{background:var(--pa2);border-radius:var(--r2);padding:10px 12px;overflow:auto;margin:6px 0;line-height:1.55}
kbd{font:11px var(--mo);background:var(--pa3);border-radius:4px;padding:1px 5px;color:var(--fg2)}
@media(max-width:880px){kbd{display:none}}
hr{border:0;border-top:1px solid var(--li);margin:14px 0}
.mu{color:var(--fg2)}.mu2{color:var(--fg3)}.sm{font-size:13px}
/* Rahmen */
.app{display:grid;grid-template-columns:minmax(0,1fr);min-height:100vh}
/* Kopfleiste: Gruppen oben, Unterpunkte darunter - gleich auf jedem Geraet */
.kopf{position:sticky;top:0;z-index:30;padding-top:env(safe-area-inset-top);
background:color-mix(in srgb,var(--bg) 84%,transparent);
backdrop-filter:saturate(180%) blur(20px);-webkit-backdrop-filter:saturate(180%) blur(20px);
border-bottom:1px solid var(--li)}
.bd{display:flex;align-items:center;gap:8px;font-weight:600;font-size:14.5px;color:var(--fg);
flex:none;letter-spacing:-.015em}
.bd i{width:21px;height:21px;border-radius:6px;background:linear-gradient(160deg,#3aa0ff,#0055c9);
display:block;flex:none}
@media(hover:hover){.bd:hover{text-decoration:none}}
.gn{display:flex;gap:2px;min-width:0;overflow-x:auto;overscroll-behavior-x:contain;
scrollbar-width:none;-ms-overflow-style:none}
.gn::-webkit-scrollbar{display:none}
.gn{-webkit-mask-image:linear-gradient(90deg,#000 0,#000 calc(100% - 14px),transparent 100%);
mask-image:linear-gradient(90deg,#000 0,#000 calc(100% - 14px),transparent 100%)}
.gn a{display:flex;align-items:center;gap:6px;padding:6px 12px;border-radius:8px;font-size:14px;
font-weight:500;color:var(--fg2);white-space:nowrap}
@media(hover:hover){.gn a:hover{background:var(--pa2);color:var(--fg);text-decoration:none}}
.gn a.on{background:var(--pa);color:var(--fg);font-weight:600;box-shadow:var(--sh)}
.gn .ic{display:none}
.gn .b{background:var(--ac);color:#fff;border-radius:9px;font-size:11px;font-weight:600;
padding:0 5px;min-width:17px;text-align:center;line-height:17px}
.sn{display:flex;gap:3px;padding:0 18px 8px;min-width:0;overflow-x:auto;overscroll-behavior-x:contain;
width:100%;max-width:1220px;margin:0 auto;scrollbar-width:none;-ms-overflow-style:none}
.sn::-webkit-scrollbar{display:none}
.sn{-webkit-mask-image:linear-gradient(90deg,#000 0,#000 calc(100% - 14px),transparent 100%);
mask-image:linear-gradient(90deg,#000 0,#000 calc(100% - 14px),transparent 100%)}
.sn.sl{-webkit-mask-image:linear-gradient(90deg,transparent 0,#000 14px,#000 calc(100% - 14px),transparent 100%);
mask-image:linear-gradient(90deg,transparent 0,#000 14px,#000 calc(100% - 14px),transparent 100%)}
.sn a{display:flex;align-items:center;gap:6px;padding:5px 11px;border-radius:7px;font-size:13.5px;
color:var(--fg2);white-space:nowrap}
.sn a .ic{color:var(--fg3)}
@media(hover:hover){.sn a:hover{background:var(--pa2);color:var(--fg);text-decoration:none}}
.sn a.on{background:var(--acb);color:var(--ac);font-weight:590}
.sn a.on .ic{color:var(--ac)}
.tb .su{display:none}
.mn{min-width:0;display:flex;flex-direction:column;background:var(--bg)}
.tb{display:flex;align-items:center;gap:10px;height:52px;min-width:0;
width:100%;max-width:1220px;margin:0 auto;
padding-left:max(18px,env(safe-area-inset-left));padding-right:max(18px,env(safe-area-inset-right))}
.tb .sp{flex:1}
.sf1{display:flex;align-items:center;gap:7px;background:var(--pa);border:.5px solid var(--li2);
border-radius:99px;padding:0 12px;height:31px;width:290px;max-width:100%;cursor:text;color:var(--fg3);
box-shadow:0 1px 1px rgba(0,0,0,.03) inset}
.sf1:focus-within{border-color:var(--ac);outline:3px solid var(--acb)}
.tb .bk{display:none}
@media(max-width:880px){.tb .bk{display:inline-flex;flex:none;padding:0 8px 0 2px;gap:2px}}
.sf1 input[type=search]{border:0;background:transparent;box-shadow:none;height:28px;padding:0;
font-size:13.5px;min-width:0;flex:1}
.sf1 input:focus{outline:none}
.sf1 kbd{font:11px var(--mo);color:var(--fg3);background:transparent;padding:0}
.ct{padding:18px;max-width:1220px;width:100%;margin:0 auto}
.jetzt{display:flex;align-items:center;gap:13px;padding:14px 15px;color:var(--fg)}
.jetzt:hover{text-decoration:none}
.jetzt .tile{width:38px;height:38px;border-radius:9px}
.jetzt .tx{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.jetzt .tx b{font-size:19px;font-weight:640;letter-spacing:-.02em;line-height:1.22}
.jetzt>.ic{color:var(--fg3)}
.ph{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:2px 0 16px}
.ph .pt{min-width:0}
.ph .pt{max-width:100%}
.ph h1{font-size:28px;font-weight:700;letter-spacing:-.03em;line-height:1.14}
.ph h1.lang{font-size:21px;letter-spacing:-.022em}
.ph .ps{font-size:13px;color:var(--fg2);margin-top:3px}
.ph .sp{flex:1}
.lb{font-size:12px;letter-spacing:0;color:var(--fg3);font-weight:500;margin-bottom:5px}
@media(max-width:520px){.g3{gap:10px}.g3 .c>.bo{padding:11px 12px}}
.ck{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:var(--fg2);font-weight:400;margin:0}
.ck input{margin:0}
.kv{display:grid;grid-template-columns:auto 1fr;gap:4px 14px;font-size:13px}
.kv dt{color:var(--fg3);font-size:12px}
.kv dd{margin:0}
@media(max-width:880px){
 .ct{padding:12px}
 .tb{padding-left:max(12px,env(safe-area-inset-left));padding-right:max(12px,env(safe-area-inset-right));
  height:48px;gap:8px}
 /* Erste Reihe traegt die Gruppen, die Suche wird ein Knopf */
 .bd{display:none}
 .sf1{display:none}
 .tb .sp{display:none}
 .tb .su{display:inline-flex;flex:none;padding:0 8px}
 .gn{flex:1}
 .gn a{padding:6px 10px;font-size:13.5px}
 .sn{padding-left:max(12px,env(safe-area-inset-left));padding-right:max(12px,env(safe-area-inset-right))}
 /* 16px verhindert, dass iOS beim Antippen in die Seite zoomt */
 input,select,textarea,.sf1 input[type=search]{font-size:16px}
 /* iOS-Untergrenze fuer Tippflaechen */
 button,.bt{min-height:38px;padding:0 14px}
 button.s,.bt.s{min-height:32px;padding:0 11px;font-size:13px}
 input,select{height:38px}
 .li.rows li>a:not([class]){padding:11px 14px;min-height:46px}
 .seg a{padding:6px 12px}
 td,th{padding:9px 12px}
}
/* Handy: die Gruppen wandern in den Daumenbereich, die Suche fuellt die Kopfreihe.
   Kein backdrop-filter auf .kopf, sonst waere sie der Bezug fuer die feste Leiste. */
@media(max-width:760px){
 .kopf{background:var(--bg);backdrop-filter:none;-webkit-backdrop-filter:none}
 .tb .su{display:none}
 .sf1{display:flex;flex:1;width:auto;height:34px}
 .sf1 input[type=search]{height:32px}
 .gn{position:fixed;left:0;right:0;bottom:0;z-index:40;flex:none;gap:0;overflow:visible;
  padding:5px max(4px,env(safe-area-inset-right)) calc(4px + env(safe-area-inset-bottom))
   max(4px,env(safe-area-inset-left));
  border-top:1px solid var(--li);background:color-mix(in srgb,var(--bg) 86%,transparent);
  backdrop-filter:saturate(180%) blur(20px);-webkit-backdrop-filter:saturate(180%) blur(20px);
  -webkit-mask-image:none;mask-image:none}
 .gn a{flex:1;min-width:0;flex-direction:column;justify-content:center;gap:2px;position:relative;
  padding:5px 2px 3px;border-radius:10px;font-size:10.5px;font-weight:510;letter-spacing:-.01em;
  color:var(--fg3)}
 .gn .ic{display:block}
 .gn .nl{max-width:100%;overflow:hidden;text-overflow:ellipsis}
 .gn a.on{background:none;box-shadow:none;color:var(--ac);font-weight:590}
 .gn .b{position:absolute;top:1px;left:50%;margin-left:4px;font-size:10px;
  min-width:15px;line-height:15px;padding:0 4px}
 .mn{padding-bottom:calc(56px + env(safe-area-inset-bottom))}
}
@media(max-width:880px){.ph h1{font-size:22px}}
/* Bausteine */
.c{background:var(--pa);border-radius:var(--r);box-shadow:var(--sh);margin-bottom:20px;min-width:0;max-width:100%}
.c>.hd{display:flex;align-items:center;gap:9px;padding:12px 15px 8px;flex-wrap:wrap}
.c>.hd+.bo,.c>.hd+.tw{padding-top:0}
.c>.hd h2,.c>.hd h3{margin:0}.c>.hd .sp{flex:1}
.c>.bo{padding:14px}
.c>.bo.p0{padding:0}
.g{display:grid;gap:14px}.g>*{min-width:0}
.g2{grid-template-columns:repeat(auto-fit,minmax(290px,1fr))}
.g3{grid-template-columns:repeat(auto-fit,minmax(148px,1fr))}
.sp2{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:14px;align-items:start}
.sp2>*{min-width:0}
@media(max-width:1040px){
 .sp2{grid-template-columns:1fr}
 /* Ein geoeffnetes Detail gehoert ueber die Liste, nicht darunter */
 .sp2.det{display:flex;flex-direction:column;align-items:stretch}
 .sp2.det>*:nth-child(2){order:-1}
}
.rw{display:flex;gap:7px;align-items:center;flex-wrap:wrap}
.line{display:flex;gap:7px;flex-wrap:wrap;align-items:center}
.line>*{min-width:0}
.line>input[name=text]{flex:1 1 100%}
@media(min-width:760px){.line{flex-wrap:nowrap}.line>input[name=text]{flex:1 1 auto}}
.st{display:flex;flex-direction:column;gap:7px}
button,.bt{display:inline-flex;align-items:center;justify-content:center;gap:5px;height:30px;padding:0 12px;
font:inherit;font-size:14px;font-weight:500;background:var(--pa);color:var(--fg);border:.5px solid var(--li2);
border-radius:var(--r2);cursor:pointer;white-space:nowrap;box-shadow:0 1px 1px rgba(0,0,0,.04);transition:background .12s}
@media(hover:hover){.bt:hover,button:hover{background:var(--pa2);text-decoration:none}}
.bt:active,button:active{background:var(--pa3)}
.bt.p,button.p{background:var(--ac);border-color:transparent;color:var(--acf);box-shadow:0 1px 2px rgba(0,0,0,.1)}
@media(hover:hover){.bt.p:hover,button.p:hover{filter:brightness(1.07)}}
.bt.d,button.d{color:var(--er)}
@media(hover:hover){.bt.d:hover,button.d:hover{background:var(--erb)}}
.bt.s,button.s{height:26px;padding:0 10px;font-size:13px}
.bt.g,button.g{border-color:transparent;background:transparent;box-shadow:none}
@media(hover:hover){.bt.g:hover,button.g:hover{background:var(--pa2)}}
button[disabled]{opacity:.4;cursor:not-allowed}
label{display:block;font-size:13px;font-weight:500;color:var(--fg2);margin-bottom:5px}
input,select,textarea{width:100%;height:30px;background:var(--pa);color:var(--fg);border:.5px solid var(--li2);
border-radius:var(--r2);padding:0 10px;font:inherit;font-size:14.5px;box-shadow:0 1px 1px rgba(0,0,0,.03) inset}
textarea{height:auto;min-height:80px;padding:7px 9px;line-height:1.55;resize:vertical}
input:focus,select:focus,textarea:focus{outline:3px solid var(--acb);outline-offset:0;border-color:var(--ac)}
input:disabled,textarea:disabled,select:disabled{background:var(--pa2);color:var(--fg3);cursor:not-allowed}
input[type=checkbox]{width:auto;height:auto;accent-color:var(--ac);box-shadow:none}
::placeholder{color:var(--fg3);opacity:1}
input[type=color]{padding:2px}
/* 16px verhindert den iOS-Zoom, 38px ist die Tippflaeche - muss nach der Basisregel stehen */
@media(max-width:880px){input,select,textarea,.sf1 input[type=search]{font-size:16px}input,select{height:38px}}
.f{margin-bottom:9px}
.fg{display:grid;gap:9px;grid-template-columns:repeat(auto-fit,minmax(150px,1fr))}
/* Segmentierte Steuerung */
.seg{display:inline-flex;background:var(--pa3);border-radius:8px;padding:2px;gap:2px;flex-wrap:wrap;max-width:100%}
.seg a{padding:4px 12px;border-radius:6px;font-size:13.5px;color:var(--fg);font-weight:500}
@media(hover:hover){.seg a:hover{text-decoration:none}}
.seg a.on{background:var(--pa);box-shadow:0 1px 2px rgba(0,0,0,.12);font-weight:590}
table{width:100%;border-collapse:collapse;font-size:15px}
th,td{text-align:left;padding:9px 15px;border-bottom:1px solid var(--li);vertical-align:top}
th{font-size:12px;letter-spacing:0;color:var(--fg3);font-weight:500;white-space:nowrap;border-bottom-color:var(--li)}
tbody tr:last-child td{border-bottom:0}
@media(hover:hover){tbody tr:hover{background:var(--pa2)}}
td.n,th.n{text-align:right;font-family:var(--mo);font-size:12.5px}
.tw{overflow-x:auto;max-width:100%}
@media(max-width:700px){
 .bhz thead{display:none}
 .bhz,.bhz tbody,.bhz tr,.bhz td{display:block;width:auto!important}
 .bhz tr{padding:10px 14px 11px;border-bottom:1px solid var(--li);position:relative}
 .bhz tr:last-child{border-bottom:0}
 .bhz td{border:0;padding:0}
 .bhz td:nth-child(1),.bhz td:nth-child(2),.bhz td:nth-child(3){display:inline-block;vertical-align:middle}
 .bhz td:nth-child(2){margin-left:10px;text-align:left}
 .bhz td:nth-child(2):not(:empty):not([colspan])::after{content:' h';color:var(--fg3);font-size:12px}
 .bhz td:nth-child(3){margin-left:8px}
 .bhz td:nth-child(4){margin:5px 34px 8px 0}
 .bhz td:nth-child(5){margin:0 34px 0 0}
 .bhz td:nth-child(6){position:absolute;top:6px;right:8px}
 .bhz td[colspan]{display:inline-block;margin-left:10px}
 /* Breite Tabellen als gestapelte Zeilen statt seitlich scrollend.
    data-l ist ein Datenattribut, kein Handler - die CSP bleibt unberuehrt. */
 .stk thead{display:none}
 .stk,.stk tbody,.stk tr,.stk td{display:block;width:auto!important}
 .stk tr{padding:9px 14px;border-bottom:1px solid var(--li);position:relative}
 .stk tr:last-child{border-bottom:0}
 .stk td{border:0;padding:1px 0;text-align:left}
 .stk td:first-child{font-weight:590}
 .stk td:empty{display:none}
 .stk td[data-l]:not(:empty)::before{content:attr(data-l) " ";color:var(--fg3);font-size:12px}
 .stk td[data-eck]{position:absolute;top:6px;right:8px;padding:0}
 .stk td[data-eck]+td{margin-right:62px}
 /* Kein Scrollfenster im Scrollfenster */
 #bt{max-height:none!important}
}
.tg{display:inline-block;background:var(--pa3);border-radius:5px;padding:0 7px;font-size:12px;
font-weight:500;color:var(--fg2);line-height:19px;white-space:nowrap}
.tg.a{background:var(--acb);color:var(--ac)}
.tg.o{background:var(--okb);color:var(--ok)}
.tg.w{background:var(--wab);color:var(--wa)}
.tg.e{background:var(--erb);color:var(--er)}
.ms{border-radius:var(--r2);padding:9px 12px;margin-bottom:12px;font-size:13px}
.ms.ok{background:var(--okb);color:var(--ok)}
.ms.warn{background:var(--wab);color:var(--wa)}
.ms.err{background:var(--erb);color:var(--er)}
.ms.info{background:var(--acb);color:var(--ac)}
/* Symbole */
.ic{flex:none;display:block}
.tile{width:27px;height:27px;border-radius:7px;display:grid;place-items:center;color:#fff;flex:none}
.t-heute{background:#ff3b30}.t-faecher{background:#0071e3}.t-notizen{background:#ff9500}
.t-noten{background:#34c759}.t-plan{background:#0f5fa8}.t-bericht{background:#af52de}
.t-einsatz{background:#a2845e}.t-kontakt{background:#00c7be}.t-pruefung{background:#ff2d55}
.t-termin{background:#5856d6}.t-aufgabe{background:#30b0c7}.t-routine{background:#ffcc00}
.t-projekt{background:#e11d48}.t-datei{background:#48484a}.t-zahnrad{background:#636366}
.t-haken{background:#8b5cf6}
.t-teilen{background:#98989d}.t-suche{background:#8e8e93}
.t-schloss{background:#636366}.t-frei{background:#00b894}.t-import{background:#5ac8fa}.t-raster{background:#5856d6}
.t-liste{background:#00a09a}
/* Listenzeilen mit Symbol, Text, Chevron */
.li.rows li{padding:0}
.li.rows li>a:not([class]){display:flex;gap:11px;align-items:center;width:100%;padding:9px 14px;
color:var(--fg);min-width:0}
@media(hover:hover){.li.rows li>a:not([class]):hover{text-decoration:none}}
.li.rows .tx{flex:1;min-width:0;display:flex;flex-direction:column;gap:1px}
.li.rows .tx b{font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
@media(max-width:700px){.li.rows .tx b{white-space:normal;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;line-height:1.3}}
.li.rows.antw .tx{gap:0}
.li.rows.antw .tx b{font-size:17px;font-weight:600;letter-spacing:-.015em;white-space:normal}
.li.rows.antw li>a:not([class]){padding:12px 15px}
.li.rows .tx span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.li.rows .ic{color:var(--fg3)}
.li.rows .tile .ic{color:#fff}
.li{list-style:none;margin:0;padding:0}
.li li{display:flex;gap:9px;align-items:baseline;padding:9px 15px;border-bottom:1px solid var(--li)}
.li li:last-child{border-bottom:0}
@media(hover:hover){.li li:hover{background:var(--pa2)}}
.em{padding:26px 14px;color:var(--fg3);font-size:14px;text-align:center}
.br{height:6px;background:var(--pa3);border-radius:99px;overflow:hidden}
.br>i{display:block;height:100%;background:var(--ac);border-radius:99px}
.dot{width:7px;height:7px;border-radius:99px;display:inline-block;flex:none}
.nt{display:inline-block;min-width:28px;text-align:center;border-radius:6px;padding:2px 6px;
font-family:var(--mo);font-size:12px;font-weight:600;color:#fff}
.ch{display:flex;gap:6px;flex-wrap:wrap}
.ch a{border-radius:99px;padding:3px 11px;font-size:12.5px;color:var(--fg2);background:var(--pa3)}
@media(hover:hover){.ch a:hover{text-decoration:none;filter:brightness(.97)}}
.ch a.on{background:var(--ac);color:#fff;font-weight:500}
/* Fachkacheln */
details.add{border-top:1px solid var(--li)}
details.add>summary{cursor:pointer;list-style:none;padding:9px 14px;font-size:13px;color:var(--ac);font-weight:500}
details.add>summary::-webkit-details-marker{display:none}
details.add>summary::before{content:"+ "}
details.add[open]>summary::before{content:"– "}
details.add>div{padding:0 14px 14px}
.kg{display:grid;gap:10px;grid-template-columns:repeat(auto-fill,minmax(178px,1fr))}
.ka{display:block;background:var(--pa);border-radius:var(--r);box-shadow:var(--sh);padding:11px 13px;color:var(--fg)}
@media(hover:hover){.ka:hover{text-decoration:none;box-shadow:0 4px 14px rgba(0,0,0,.09),0 0 0 .5px rgba(0,0,0,.06)}}
.ka .kk{display:flex;align-items:center;gap:7px;margin-bottom:6px}
.ka .kn{font-weight:590;font-size:13.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ka .kz{font-size:12px;color:var(--fg3);display:flex;gap:9px;flex-wrap:wrap}
/* Stundenplan */
.tt{display:grid;grid-template-columns:24px repeat(5,minmax(0,1fr));gap:3px;font-size:12px}
.tt .h{font-size:11px;color:var(--fg3);text-transform:uppercase;text-align:center;font-weight:600}
.tt .s{color:var(--fg3);text-align:right;padding-right:3px;font-size:11px;line-height:28px}
.tt .c2{background:var(--pa2);border-radius:6px;padding:4px 6px;min-height:28px;border-left:2.5px solid transparent;min-width:0;overflow-wrap:anywhere}
/* Kalender */
.cal{display:grid;grid-template-columns:repeat(7,1fr);gap:3px}
.cal .h{font-size:11px;color:var(--fg3);text-align:center;font-weight:600;text-transform:uppercase;padding:2px}
.cal .d{background:var(--pa2);border-radius:7px;min-height:60px;padding:4px 5px;font-size:11.5px;border:1.5px solid transparent}
.cal .d.o{opacity:.32}.cal .d.t{border-color:var(--ac)}
.cal .d b{font-weight:590;color:var(--fg2);font-size:11px}
.cal .e{display:block;border-radius:4px;padding:0 4px;margin-top:2px;font-size:10.5px;color:#fff;
overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
/* Palette */
.pl{position:fixed;inset:0;background:rgba(0,0,0,.28);backdrop-filter:blur(3px);z-index:100;display:none;padding-top:13vh}
@media(max-width:880px){
 .pl{padding:0}
 .pl .bx{max-width:none;height:100%;border-radius:0;display:flex;flex-direction:column;
  padding-top:env(safe-area-inset-top)}
 .pl ul{max-height:none;flex:1;padding:6px 6px calc(14px + env(safe-area-inset-bottom))}
 .pl li{padding:10px 12px;min-height:46px}
}
.pl.on{display:block}
.pl .bx{max-width:560px;margin:0 auto;background:color-mix(in srgb,var(--pa) 94%,transparent);
backdrop-filter:saturate(180%) blur(30px);border-radius:14px;box-shadow:var(--sh2);overflow:hidden}
.pl .pf{display:flex;align-items:center;gap:9px;padding:0 14px;border-bottom:1px solid var(--li);color:var(--fg3)}
.pl input{height:48px;border:0;border-radius:0;font-size:16px;padding:0;box-shadow:none;background:transparent;flex:1;min-width:0}
.pl input:focus{outline:none}
.pl .pf button{display:none;flex:none}
@media(max-width:880px){.pl .pf button{display:inline-flex}}
.pl ul{list-style:none;margin:0;padding:5px;max-height:46vh;max-height:46dvh;overflow:auto;
-webkit-overflow-scrolling:touch}
.pl li{padding:7px 11px;border-radius:7px;cursor:pointer;font-size:13.5px;display:flex;gap:10px;align-items:center}
.pl li b{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pl li .col{display:flex;flex-direction:column;min-width:0;flex:1}
.pl li.aw b{font-size:16px;font-weight:600;white-space:normal}
.pl li.on .tile{box-shadow:0 0 0 1.5px rgba(255,255,255,.5)}
body.modal{overflow:hidden}
.pl li.on{background:var(--ac);color:#fff}
.pl li.on .tg{background:rgba(255,255,255,.22);color:#fff}
.pl li.on .mu2{color:rgba(255,255,255,.78)}
.pl li b{font-weight:500}
.pl .gr{padding:7px 12px 3px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--fg3);font-weight:600}
@media(max-width:700px){.cal .e{min-height:20px;line-height:20px;font-size:11px}}
@media print{.kopf,.np,.pl{display:none!important}.app{display:block}.ct{padding:0;max-width:none}
.tw{overflow:visible}.c{box-shadow:none;margin:0 0 10px;background:none}.c>.hd{padding:0 0 4px}.c>.bo{padding:0}
body{background:#fff;color:#000;font-size:10.5pt}th,td{border-color:#bbb;padding:5px 8px}a{color:#000}@page{margin:16mm}}
</style>
</head>
<body>
<?php if ($bare): ?>
<div style="max-width:<?= !empty($o['weit']) ? '900' : (!empty($o['breit']) ? '580' : '340') ?>px;margin:0 auto;padding:<?= !empty($o['breit']) || !empty($o['weit']) ? '5' : '9' ?>vh 16px"><?= $inhalt ?></div>
<?php else: ?>
<div class="app">
 <div class="mn">
  <div class="kopf np">
   <header class="tb">
   <?php if (!empty($o['zurueck'])): ?>
    <a class="bt g s bk" href="<?= h($o['zurueck']) ?>"><?= ic('zurueck', 18) ?><span><?= h($o['zurueck_t'] ?? 'Zurueck') ?></span></a>
   <?php endif; ?>
   <a class="bd" href="<?= url('start') ?>" title="<?= h(APP_NAME) ?>"><i></i><span><?= h(APP_NAME) ?></span></a>
   <?php $ga = gruppe_aktiv($p); ?>
   <nav class="gn">
    <?php foreach (nav_gruppen() as $g): $b = nav_zahl($g[0]); ?>
     <a href="<?= h(gruppe_url($g)) ?>"<?= $ga === $g[0] ? ' class="on"' : '' ?>><?= ic($g[2], 22) ?><span class="nl"><?= h($g[1]) ?></span><?php
       if ($b !== ''): ?><span class="b"><?= h($b) ?></span><?php endif; ?></a>
    <?php endforeach; ?>
   </nav>
   <span class="sp"></span>
   <form method="get" action="<?= h(base_path()) ?>" class="sf1" data-palette>
    <input type="hidden" name="p" value="suche">
    <?= ic('suche', 16) ?>
    <input type="search" name="q" id="sq" placeholder="Suchen" value="<?= h(get('q')) ?>"
           autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"
           enterkeyhint="search"<?= $p === 'suche' && get('q') === '' ? ' autofocus' : '' ?>>
    <kbd>&#8984;K</kbd>
   </form>
   <a class="bt g s su" href="<?= url('suche') ?>" data-palette aria-label="Suchen"><?= ic('suche', 19) ?></a>
   <form method="post" action="<?= url('theme') ?>"><?= csrf_field() ?>
    <input type="hidden" name="theme" value="<?= $theme === 'dunkel' ? 'hell' : ($theme === 'hell' ? 'auto' : 'dunkel') ?>">
    <button class="g s" type="submit" title="Design"><?= $theme === 'dunkel' ? '&#9788;' : ($theme === 'hell' ? '&#9789;' : '&#9681;') ?></button>
   </form>
   </header>
   <?php
   $gr = null;
   foreach (nav_gruppen() as $g) if ($g[0] === $ga) { $gr = $g; break; }
   if ($gr && $gr[3]): $hier = nav_adresse($p); ?>
    <nav class="sn">
     <?php $exakt = in_array($hier, array_column($gr[3], 2), true);
     foreach ($gr[3] as [$lbl, $sym, $adr]):
       // Ohne exakten Treffer zaehlt die Seite: Berichtsheft&t=plan liegt unter Berichtsheft
       $an = $exakt ? $adr === $hier : (preg_match('/[?&]p=([a-z]+)/', $adr, $pm) && $pm[1] === $p && !str_contains($adr, '&t=')); ?>
      <a href="<?= h($adr) ?>"<?= $an ? ' class="on"' : '' ?>><?= ic($sym, 15) ?><span><?= h($lbl) ?></span></a>
     <?php endforeach; ?>
    </nav>
   <?php endif; ?>
  </div>
  <div class="ct">
   <div class="ph np">
    <div class="pt">
     <?php $pt = (string)($o['titel'] ?? $titel); ?>
     <h1<?= mb_strlen($pt) > 30 ? ' class="lang"' : '' ?>><?= h($pt) ?></h1>
     <?php if (!empty($o['unter'])): ?><div class="ps"><?= $o['unter'] ?></div><?php endif; ?>
    </div>
    <?php if (!empty($o['tabs'])): ?>
     <div class="seg">
      <?php foreach ($o['tabs'] as $tk => [$tl, $tu]): ?>
       <a class="<?= ($o['aktiv'] ?? '') === $tk ? 'on' : '' ?>" href="<?= h($tu) ?>"><?= h($tl) ?></a>
      <?php endforeach; ?>
     </div>
    <?php endif; ?>
    <span class="sp"></span>
    <?= $o['aktion'] ?? '' ?>
   </div>
   <?php foreach ($flash as [$t, $m]): ?><div class="ms <?= h($t) ?>"><?= h($m) ?></div><?php endforeach; ?>
   <?= $inhalt ?>
  </div>
 </div>
</div>
<div class="pl" id="pl"><div class="bx">
 <div class="pf"><?= ic('suche', 18) ?><input type="search" id="pq" placeholder="Springen oder suchen" autocomplete="off"
   autocapitalize="off" autocorrect="off" spellcheck="false" enterkeyhint="go"><button class="g s" type="button" data-schliessen>Fertig</button></div>
 <ul id="pu"></ul></div></div>
<?php endif; ?>
<script nonce="<?= h($n) ?>">
(function(){
var B=<?= json_encode(base_path()) ?>;
var N=<?= json_encode(array_map(fn($x) => ['t' => $x[1], 'u' => $x[0]], nav()), JSON_UNESCAPED_UNICODE) ?>;
// Derselbe Zielindex wie auf dem Server, damit Palette und Suchseite gleich ranken
<?php $zn = $u ? ziel_nutzung((int)$u['id']) : []; $zfrisch = date('Y-m-d', strtotime('-7 days')); ?>
var Z=<?= json_encode(array_map(fn($z) => ['t' => $z[1], 'g' => $z[2] ?: 'Start',
        'w' => $z[3], 'u' => $z[4], 'i' => $z[0], 'c' => $z[5] ?? '#8e8e93',
        'n' => (int)($zn[$z[1]]['anzahl'] ?? 0),
        'z' => (int)(($zn[$z[1]]['letzt'] ?? '') >= $zfrisch)], ziele_index()), JSON_UNESCAPED_UNICODE) ?>;
var SYM=<?= json_encode(icons(), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
var EX=<?= json_encode($u ? array_map(fn($z) => ['n' => $z['name'], 'u' => $z['url']],
        all("SELECT name, url FROM ziele WHERE user_id = ? ORDER BY sort, id", [(int)$u['id']])) : [], JSON_UNESCAPED_UNICODE) ?>;
document.addEventListener('click',function(e){
 var c=e.target.closest('[data-copy]');
 if(c){var el=document.getElementById(c.dataset.copy);
  if(el&&navigator.clipboard)navigator.clipboard.writeText(el.innerText).then(function(){
   var o=c.textContent;c.textContent='kopiert';setTimeout(function(){c.textContent=o;},1100);});}
 var v=e.target.closest('[data-copy-val]');
 if(v&&navigator.clipboard){navigator.clipboard.writeText(v.dataset.copyVal).then(function(){
   var o=v.textContent;v.textContent='kopiert';setTimeout(function(){v.textContent=o;},1100);});}
});
document.addEventListener('submit',function(e){var b=e.submitter,m=(b&&b.getAttribute('data-q'))||e.target.getAttribute('data-q');if(m&&!confirm(m))e.preventDefault();});
// Die offene Gruppe muss sichtbar sein, auch wenn die Reihe gescrollt ist
[['.gn a.on','.gn'],['.sn a.on','.sn']].forEach(function(paar){
 var a=document.querySelector(paar[0]); if(!a)return;
 var w=a.closest(paar[1]); if(!w||w.scrollWidth<=w.clientWidth)return;
 var l=a.offsetLeft-12,r=a.offsetLeft+a.offsetWidth+12;
 if(l<w.scrollLeft)w.scrollLeft=Math.max(0,l);else if(r>w.scrollLeft+w.clientWidth)w.scrollLeft=r-w.clientWidth;
 function mk(){w.classList.toggle('sl',w.scrollLeft>2);}mk();w.addEventListener('scroll',mk,{passive:true});
});
document.querySelectorAll('[data-print]').forEach(function(b){b.addEventListener('click',function(){window.print();});});
document.addEventListener('change',function(e){if(e.target.matches('[data-autosubmit]'))e.target.form.submit();});
document.addEventListener('click',function(e){
 if(e.target.closest('[data-schliessen]')){close_();return;}
 var t=e.target.closest('[data-palette]');
 if(t&&pl){e.preventDefault();open_(sq&&sq.value?sq.value:'');}});
document.addEventListener('focus',function(e){
 if(e.target.matches('[data-sel]')){e.target.select();return;}
 // Tastatur und Maus fuehren zur selben Sofortsuche
 if(e.target===sq&&pl&&!pl.classList.contains('on')&&!/[?&]p=suche/.test(location.search)){
  open_(sq.value||'');sq.blur();}},true);
var pl=document.getElementById('pl'),pq=document.getElementById('pq'),pu=document.getElementById('pu'),
    sq=document.getElementById('sq'),sel=0,cur=[];
var tref=[],aref=[],tid=0,lauf='';
function esc(t){return String(t).replace(/[<>&"']/g,function(c){
 return {'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&#39;'}[c];});}
function rang(x,q){var l=x.t.toLowerCase(),r=0;
 if(!q)r=10;
 else if(l===q)r=1200;
 else if(l.indexOf(q)===0)r=1000;
 else{var w=l.split(/[\s&]+/),tr=0;
  for(var i=0;i<w.length;i++)if(w[i].indexOf(q)===0){tr=900;break;}
  if(!tr&&l.indexOf(q)>=0)tr=700;
  if(!tr){var k=(x.w||'').split(' ');
   for(var j=0;j<k.length;j++){if(k[j]===q){tr=650;break;}if(k[j]&&k[j].indexOf(q)===0){tr=550;break;}}
   if(!tr&&q.length>=4)for(var m=0;m<k.length;m++)if(k[m]&&k[m].indexOf(q)>=0){tr=350;break;}}
  r=tr;}
 if(!r)return 0;
 // dieselbe gedeckelte Gewichtung wie auf dem Server
 if(x.n)r+=Math.min(20,5*Math.sqrt(x.n));
 if(x.z)r+=6;
 return r;}
function kachel(x){
 var n=x.i||'suche';
 var stil=x.c?' style="background:'+esc(x.c)+'"':'';
 return '<span class="tile t-'+esc(n)+'"'+stil+'>'+sym(n,16)+'</span>';}
function sym(n,g){var d=SYM[n]||SYM.notizen;
 return '<svg class="ic" width="'+g+'" height="'+g+'" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
  +' stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'+d+'</svg>';}
function draw(){var q=(pq.value||'').toLowerCase().trim();
 var nav=Z.map(function(x){return {x:x,r:rang(x,q)};}).filter(function(o){return o.r>0;})
   .sort(function(a,b){return b.r-a.r||a.x.t.length-b.x.t.length;})
   .slice(0,q?6:Z.length).map(function(o){return o.x;});
 cur=aref.concat(nav,tref);
 if(q){cur=cur.concat([{t:'Alles durchsuchen: '+pq.value,u:B+'?p=suche&q='+encodeURIComponent(pq.value),g:'Suchen'}]);
  EX.forEach(function(z){cur=cur.concat([{t:z.n+': '+pq.value,g:'Suchen',
   u:z.u.indexOf('%s')>=0?z.u.replace('%s',encodeURIComponent(pq.value)):z.u}]);});}
 if(sel>=cur.length)sel=Math.max(0,cur.length-1);
 var html='',letzt='';
 cur.forEach(function(x,i){
  var gr=x.g==='Treffer'?'Treffer':(x.g==='Suchen'?'Suchen':(x.g==='Antwort'?'Antwort':'Springen'));
  if(gr!==letzt){html+='<li class="gr" style="pointer-events:none">'+esc(gr)+'</li>';letzt=gr;}
  var kl=(i===sel?'on ':'')+(x.l?'aw':'');
  html+='<li'+(kl.trim()?' class="'+kl.trim()+'"':'')+' data-u="'+esc(x.u)+'">'
      +kachel(x)
      +(x.l?'<span class="col"><span class="mu2 sm">'+esc(x.l)+'</span><b>'+esc(x.t)+'</b></span>':'<b>'+esc(x.t)+'</b>')
      +(x.s?'<span class="mu2 sm" style="margin-left:auto">'+esc(x.s)+'</span>':'')+'</li>';});
 pu.innerHTML=html;
 pu.querySelectorAll('li.gr').forEach(function(l){l.removeAttribute('data-u');});}
function hole(){var q=pq.value.trim();
 if(q.length<2){tref=[];aref=[];draw();return;}
 if(q===lauf)return; lauf=q;
 fetch(B+'?p=api&a=s&q='+encodeURIComponent(q),{headers:{'Accept':'application/json'}})
  .then(function(r){return r.json();})
  .then(function(j){ if(pq.value.trim()!==q)return;
    aref=(j.antwort||[]).map(function(x){return {t:x.wert,u:x.url,s:'',g:'Antwort',i:x.icon,l:x.label};});
    tref=(j.treffer||[]).map(function(t){return {t:t.titel||'(ohne Titel)',u:t.u||t.url,
      s:[t.art,t.datum].filter(Boolean).join(' · '),g:'Treffer',i:t.icon||'notizen'};});
    draw();})
  .catch(function(){lauf='';});}
function open_(v){pl.classList.add('on');document.body.classList.add('modal');
 pq.value=v||'';sel=0;tref=[];aref=[];lauf='';draw();pq.focus();if(v)hole();}
function close_(){pl.classList.remove('on');document.body.classList.remove('modal');}
if(pl){pq.addEventListener('input',function(){sel=0;draw();clearTimeout(tid);tid=setTimeout(hole,120);});
 pu.addEventListener('click',function(e){var l=e.target.closest('li');if(l&&l.dataset.u)location.href=l.dataset.u;});
 pl.addEventListener('click',function(e){if(e.target===pl)close_();});}
function typing(e){var n=e.target.nodeName;return n==='INPUT'||n==='TEXTAREA'||n==='SELECT'||e.target.isContentEditable;}
document.addEventListener('keydown',function(e){
 if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();open_();return;}
 if(pl&&pl.classList.contains('on')){
  if(e.key==='Escape')close_();
  else if(e.key==='ArrowDown'){e.preventDefault();sel=Math.min(sel+1,cur.length-1);draw();}
  else if(e.key==='ArrowUp'){e.preventDefault();sel=Math.max(sel-1,0);draw();}
  else if(e.key==='Enter'){e.preventDefault();if(cur[sel])location.href=cur[sel].u;}
  return;}
 if(e.ctrlKey||e.metaKey||e.altKey)return;
 if(typing(e)){if(e.key==='Escape')e.target.blur();return;}
 if(e.key==='/'){e.preventDefault();var s=document.getElementById('sq');if(s)s.focus();return;}
 if(e.key>='1'&&e.key<='9'){var x=N[+e.key-1];if(x){e.preventDefault();location.href=x.u;}return;}
 if(e.key==='n'){var a=document.querySelector('[data-new]');if(a){e.preventDefault();
  if(a.nodeName==='A')location.href=a.href;else a.focus();}}
});
// Klassen aus WebUntis holen
document.querySelectorAll('[data-klassen]').forEach(function(btn){
 btn.addEventListener('click',function(){
  var ziel=document.querySelector(btn.dataset.klassen); if(!ziel)return;
  ziel.textContent='lade ...';
  fetch(B+'?p=api&a=klassen&id='+encodeURIComponent(btn.dataset.id))
   .then(function(r){return r.json();})
   .then(function(j){
     if(j.fehler){ziel.textContent=j.fehler;return;}
     if(!j.klassen||!j.klassen.length){ziel.textContent='Keine Klassen erhalten.';return;}
     ziel.innerHTML='<div class="ch" style="margin-top:6px">'+j.klassen.map(function(x){
      return '<a href="#" data-k="'+esc(x.name)+'" title="'+esc(x.lang)+'">'+esc(x.name)+'</a>';}).join('')+'</div>';
     ziel.querySelectorAll('a').forEach(function(a){a.addEventListener('click',function(e){
      e.preventDefault();
      var f=document.querySelector('input[name=klasse]');
      if(f){f.value=a.dataset.k;f.dispatchEvent(new Event('input',{bubbles:true}));
       ziel.innerHTML='<span class="tg o">'+esc(a.dataset.k)+' uebernommen – Profil speichern</span>';}});});
   }).catch(function(){ziel.textContent='Abruf fehlgeschlagen.';});});
});
document.querySelectorAll('form select[name=typ]').forEach(function(sel){
 var f=sel.form,o=f.querySelectorAll('[data-only]');
 function up(){o.forEach(function(el){el.style.display=el.dataset.only===sel.value?'':'none';});}
 sel.addEventListener('change',up);up();});
document.querySelectorAll('[data-fl]').forEach(function(i){i.addEventListener('input',function(){
 var q=this.value.toLowerCase(),t=document.querySelector(this.dataset.fl);if(!t)return;
 t.querySelectorAll('tbody tr').forEach(function(r){r.style.display=!q||r.innerText.toLowerCase().indexOf(q)>=0?'':'none';});});});
document.querySelectorAll('textarea[data-d]').forEach(function(t){var k='fisi:'+t.dataset.d;
 try{var v=localStorage.getItem(k);if(v&&!t.value)t.value=v;}catch(x){}
 t.addEventListener('input',function(){try{localStorage.setItem(k,t.value);}catch(x){}});
 if(t.form)t.form.addEventListener('submit',function(){try{localStorage.removeItem(k);}catch(x){}});});
})();
</script>
</body></html><?php
}

// --- kleine Bausteine ------------------------------------------------------
function em(string $t): string { return '<div class="em">' . h($t) . '</div>'; }
function nfarbe(?float $n): string {
    if ($n === null) return '#94a3b8';
    return $n <= 1.5 ? '#15803d' : ($n <= 2.5 ? '#65a30d' : ($n <= 3.5 ? '#ca8a04'
         : ($n <= 4.5 ? '#ea580c' : ($n <= 5.5 ? '#dc2626' : '#991b1b'))));
}
function npill(?float $n): string {
    return $n === null ? '<span class="mu2">–</span>'
        : '<span class="nt" style="background:' . h(nfarbe($n)) . '">' . num($n, 1) . '</span>';
}
function bars(array $z, string $einheit = 'h'): string {
    if (!$z) return '<div class="em">–</div>';
    $max = max(0.001, max(array_map(fn($x) => (float)$x[1], $z)));
    $o = '<div class="st">';
    foreach ($z as $x) {
        $txt = $x[3] ?? (num((float)$x[1], 1) . ($einheit !== '' ? ' ' . $einheit : ''));
        $o .= '<div><div class="rw" style="justify-content:space-between;gap:6px;flex-wrap:nowrap">'
            . '<span class="sm" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . h($x[0])
            . '</span><span class="sm mu mo" style="flex:none">' . h($txt) . '</span></div>'
            . '<div class="br"><i style="width:' . round(max(2, (float)$x[1] / $max * 100), 1) . '%;background:'
            . h($x[2] ?: 'var(--ac)') . '"></i></div></div>';
    }
    return $o . '</div>';
}
function spark(array $w, int $b = 90, int $hh = 22): string {
    $n = count($w); if ($n < 2) return '';
    $pts = [];
    foreach (array_values($w) as $i => $v) {
        $x = $i * ($b - 3) / ($n - 1) + 1.5;
        $y = 1.5 + (($v - 1) / 5) * ($hh - 3);
        $pts[] = round($x, 1) . ',' . round($y, 1);
    }
    return '<svg width="' . $b . '" height="' . $hh . '" viewBox="0 0 ' . $b . ' ' . $hh . '">'
         . '<polyline fill="none" stroke="' . h(nfarbe(array_sum($w) / $n)) . '" stroke-width="1.6" '
         . 'stroke-linejoin="round" stroke-linecap="round" points="' . implode(' ', $pts) . '"/></svg>';
}
function opts(array $rows, $sel, string $leer = '', string $ik = 'id', string $lk = 'name'): string {
    $o = $leer !== '' ? '<option value="">' . h($leer) . '</option>' : '';
    foreach ($rows as $r) {
        $i = is_array($r) ? $r[$ik] : $r; $l = is_array($r) ? $r[$lk] : $r;
        $o .= '<option value="' . h($i) . '"' . ((string)$i === (string)$sel ? ' selected' : '') . '>' . h($l) . '</option>';
    }
    return $o;
}
function optm(array $m, $sel): string {
    $o = '';
    foreach ($m as $k => $v) $o .= '<option value="' . h($k) . '"' . ((string)$k === (string)$sel ? ' selected' : '') . '>' . h($v) . '</option>';
    return $o;
}
function fach_opts(int $uid, $sel, string $leer = 'Fach'): string {
    return opts(all("SELECT id, COALESCE(NULLIF(short,''), name) || ' – ' || name AS name FROM subjects
                     WHERE user_id = ? AND archiv = 0 ORDER BY sort, name", [$uid]), $sel, $leer);
}
function lf_opts($sel, string $leer = 'Lernfeld'): string {
    return opts(all("SELECT nr AS id, code || ' ' || titel AS name FROM lernfelder ORDER BY nr"), $sel, $leer);
}
function kat_opts($sel, string $leer = 'automatisch'): string {
    return opts(all("SELECT id, CASE WHEN pos_no<>'' THEN pos_no || '  ' ELSE '' END || name AS name
                     FROM categories ORDER BY sort"), $sel, $leer);
}
function typ_label(string $t): string {
    return ['probe'=>'Probe','test'=>'Test','abgabe'=>'Abgabe','pruefung'=>'Pruefung',
            'termin'=>'Termin','projekt'=>'Projekt','frist'=>'Frist','frei'=>'Frei'][$t] ?? $t;
}
function md(string $s): string {
    $out = []; $code = false; $buf = [];
    $flush = function () use (&$buf, &$out) { if ($buf) { $out[] = '<pre>' . implode("\n", $buf) . '</pre>'; $buf = []; } };
    foreach (preg_split('/\R/', $s) as $line) {
        if (preg_match('/^```/', $line)) { if ($code) $flush(); $code = !$code; continue; }
        if ($code) { $buf[] = h($line); continue; }
        $roh = $line; $vor = ''; $nach = '';
        if (preg_match('/^\s*[-*]\s+(.*)$/', $line, $m))          { $roh = $m[1]; $vor = '&bull; '; }
        elseif (preg_match('/^\s*#{1,3}\s+(.*)$/', $line, $m))    { $roh = $m[1]; $vor = '<b>'; $nach = '</b>'; }
        $l = h($roh);
        $l = preg_replace('/`([^`]+)`/', '<code>$1</code>', $l);
        $l = preg_replace('/\*\*([^*]+)\*\*/', '<b>$1</b>', $l);
        $l = preg_replace_callback('~https?://[^\s<]+~', fn($m2) => '<a href="' . $m2[0] . '" rel="noopener nofollow">' . $m2[0] . '</a>', $l);
        $out[] = $vor . $l . $nach;
    }
    $flush();
    return implode("<br>\n", $out);
}

/** Schul- und Klassenfeld mit Suche im WebUntis-Verzeichnis. */
function schul_felder(array $v = []): string {
    ob_start(); ?>
    <div class="f">
      <label for="sch">Schule</label>
      <input id="sch" name="schule" value="<?= h($v['schule'] ?? '') ?>" placeholder="Name oder Strasse" autocomplete="off">
      <input type="hidden" name="untis_server" value="<?= h($v['untis_server'] ?? '') ?>">
      <input type="hidden" name="untis_schule" value="<?= h($v['untis_schule'] ?? '') ?>">
      <?php if (!empty($v['untis_schule'])): ?>
        <div class="sm mu2" style="margin-top:5px">WebUntis: <?= h($v['untis_schule']) ?></div>
      <?php endif; ?>
    </div>
    <div class="f"><label for="kl">Klasse</label>
      <input id="kl" name="klasse" value="<?= h($v['klasse'] ?? '') ?>" placeholder="z.B. 2FS152"
             autocapitalize="characters" autocomplete="off"></div>
    <?php return ob_get_clean();
}

// --- Anmelden / Konto anlegen ---------------------------------------------
function p_login(): void {
    if (me()) redirect(url('start'));
    $err = '';
    if (!empty($_SESSION['2fa']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code'])) {
        csrf_check();
        $uid = (int)$_SESSION['2fa'];
        if (!rl('2fa:' . $uid, 10, 600)) $err = 'Zu viele Versuche.';
        else {
            $u = one("SELECT * FROM users WHERE id = ?", [$uid]);
            $code = preg_replace('/\s+/', '', post('code'));
            $ok = $u && totp_verify((string)$u['totp_secret'], $code);
            if (!$ok && $u) {
                $rc = json_decode((string)$u['recovery'], true) ?: [];
                foreach ($rc as $i => $hash) {
                    if (password_verify(strtoupper($code), $hash)) {
                        unset($rc[$i]);
                        upd('users', ['recovery' => json_encode(array_values($rc))], 'id = :id', ['id' => $uid]);
                        $ok = true; break;
                    }
                }
            }
            if ($ok) {
                unset($_SESSION['2fa']);
                login_user($u);
                $to = $_SESSION['nach'] ?? ''; unset($_SESSION['nach']);
                redirect($to ?: url('start'));
            }
            $err = 'Code stimmt nicht.';
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $ident = post('ident'); $pw = (string)($_POST['pw'] ?? ''); $ip = client_ip();
        if (post('web') !== '') { usleep(400000); $err = 'Anmeldung fehlgeschlagen.'; }
        elseif (!rl('ip:' . $ip, LOGIN_MAX_IP, 900)) $err = 'Zu viele Versuche von dieser Adresse. 15 Minuten warten.';
        else {
            $u = one("SELECT * FROM users WHERE username = ?", [$ident]);
            $aid = ins('login_attempts', ['ident' => mb_substr($ident, 0, 80), 'ip' => $ip, 'ok' => 0, 'ts' => time()]);
            if ($u && (int)$u['locked_until'] > time()) {
                $err = 'Konto gesperrt, noch ' . (int)ceil(((int)$u['locked_until'] - time()) / 60) . ' Minuten.';
            } elseif ($u && password_verify($pw, $u['pass_hash'])) {
                if (password_needs_rehash($u['pass_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
                    upd('users', ['pass_hash' => pw_hash($pw)], 'id = :id', ['id' => (int)$u['id']]);
                }
                q("UPDATE login_attempts SET ok = 1 WHERE id = ?", [$aid]);
                if ((int)$u['totp_enabled'] === 1) {
                    session_regenerate_id(true);
                    $_SESSION['2fa'] = (int)$u['id'];
                    $_SESSION['csrf'] = bin2hex(random_bytes(32));
                } else {
                    login_user($u);
                    $to = $_SESSION['nach'] ?? ''; unset($_SESSION['nach']);
                    redirect($to ?: url('start'));
                }
            } else {
                password_verify($pw, '$2y$12$usesomesillystringforsalt0000000000000000000000000000000000');
                if ($u) {
                    $f = (int)$u['failed'] + 1;
                    $d = ['failed' => $f];
                    if ($f >= LOGIN_MAX_USER) { $d['locked_until'] = time() + LOGIN_LOCK_SEC; $d['failed'] = 0; }
                    upd('users', $d, 'id = :id', ['id' => (int)$u['id']]);
                }
                $err = 'Benutzername oder Passwort falsch.';
            }
        }
    }
    $zwei = !empty($_SESSION['2fa']);
    ob_start(); ?>
    <div class="c"><div class="bo">
      <div class="rw" style="margin-bottom:14px"><span style="width:22px;height:22px;border-radius:5px;background:var(--ac);display:block"></span>
        <b><?= h(APP_NAME) ?></b></div>
      <?php foreach (take_flash() as [$t, $m]): ?><div class="ms <?= h($t) ?>"><?= h($m) ?></div><?php endforeach; ?>
      <?php if ($err): ?><div class="ms err"><?= h($err) ?></div><?php endif; ?>
      <?php if ($zwei): ?>
        <form method="post" autocomplete="off">
          <?= csrf_field() ?>
          <div class="f"><label for="c">Code aus der App</label>
            <input id="c" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus
                   class="mo" style="font-size:17px;letter-spacing:.16em;text-align:center;height:38px"></div>
          <button class="p" type="submit" style="width:100%;justify-content:center;height:34px">Weiter</button>
        </form>
        <p class="sm" style="margin-top:10px"><a href="<?= url('abbruch') ?>">Abbrechen</a></p>
      <?php else: ?>
        <form method="post">
          <?= csrf_field() ?>
          <div class="f"><label for="i">Benutzername</label>
            <input id="i" name="ident" required autofocus autocomplete="username" value="<?= h(post('ident')) ?>"></div>
          <div class="f"><label for="p">Passwort</label>
            <input id="p" name="pw" type="password" required autocomplete="current-password"></div>
          <div style="position:absolute;left:-9999px" aria-hidden="true"><input name="web" tabindex="-1" autocomplete="off"></div>
          <button class="p" type="submit" style="width:100%;justify-content:center;height:34px">Anmelden</button>
        </form>
        <p class="sm mu" style="margin:12px 0 0"><a href="<?= url('konto') ?>">Konto anlegen</a></p>
      <?php endif; ?>
    </div></div>
    <?php
    page('Anmelden', ob_get_clean(), ['bare' => true]);
}

function p_konto(): void {
    if (me()) redirect(url('start'));
    $erst = erstes_konto();
    reg_code();   // legt REGISTRIERUNG.txt an, falls noch nicht vorhanden
    $err = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (!rl('reg:' . client_ip(), 10, 3600)) $err[] = 'Zu viele Versuche.';
        if (!$erst && !hash_equals(reg_code(), strtoupper(preg_replace('/\s+/', '', post('code'))))) {
            $err[] = 'Code stimmt nicht.';
        }
        $user = mb_substr(preg_replace('/[^A-Za-z0-9._-]/', '', post('username')), 0, 32);
        $pw   = (string)($_POST['pw'] ?? '');
        if (mb_strlen($user) < 3) $err[] = 'Benutzername: mindestens 3 Zeichen.';
        if (val("SELECT 1 FROM users WHERE username = ?", [$user])) $err[] = 'Benutzername vergeben.';
        if ($pw !== (string)($_POST['pw2'] ?? '')) $err[] = 'Passwoerter stimmen nicht ueberein.';
        foreach (pw_problems($pw, $user) as $p) $err[] = 'Passwort: ' . $p;
        $teile = klasse_teile(post('klasse'));
        if ($teile['kuerzel'] === '' || ($teile['stufe'] !== null && ($teile['stufe'] < 1 || $teile['stufe'] > 3))) {
            $err[] = 'Klasse zum Beispiel 2FS152.';
        }
        if (!$err) {
            // Alles Weitere leitet sich aus der Klasse ab oder wird gefragt, wenn es gebraucht wird.
            $uid = ins('users', ['username' => $user, 'pass_hash' => pw_hash($pw),
                'klasse' => mb_substr(strtoupper(preg_replace('/\s+/', '', post('klasse'))), 0, 20),
                'kl_kuerzel' => $teile['kuerzel'], 'kl_nr' => $teile['nr'],
                'zeitgruppe' => $teile['zeitgruppe'], 'verkuerzt' => $teile['verkuerzt'],
                'kl_stufe' => (int)($teile['stufe'] ?? 0), 'kl_stand' => today(),
                'ics_token' => bin2hex(random_bytes(16)), 'pw_changed' => date('Y-m-d H:i:s')]);
            seed_faecher($uid);
            flash('Konto angelegt.');
            redirect(url('login'));
        }
    }
    ob_start(); ?>
    <div class="c"><div class="bo">
      <h3 style="margin-bottom:12px">Konto anlegen</h3>
      <?php foreach ($err as $e): ?><div class="ms err"><?= h($e) ?></div><?php endforeach; ?>
      <form method="post">
        <?= csrf_field() ?>
        <?php if (!$erst): ?>
          <div class="f"><label for="cd">Code</label>
            <input id="cd" name="code" required autocomplete="off" class="mo" value="<?= h(post('code')) ?>"></div>
        <?php endif; ?>
        <div class="f"><label for="us">Benutzername</label>
          <input id="us" name="username" required autocomplete="username" value="<?= h(post('username')) ?>"></div>
        <div class="f"><label for="kl">Klasse</label>
          <input id="kl" name="klasse" required placeholder="z.B. 2FS152" autocomplete="off"
                 autocapitalize="characters" value="<?= h(post('klasse')) ?>"></div>
        <div class="fg">
          <div class="f"><label for="pw">Passwort</label><input id="pw" name="pw" type="password" required autocomplete="new-password"></div>
          <div class="f"><label for="p2">Wiederholen</label><input id="p2" name="pw2" type="password" required autocomplete="new-password"></div>
        </div>
        <div class="rw"><button class="p" type="submit">Anlegen</button>
          <a class="bt g" href="<?= url('login') ?>">Zurueck</a></div>
      </form>
    </div></div>
    <?php
    page('Konto', ob_get_clean(), ['bare' => true, 'breit' => true]);
}

// --- Schnellerfassung ------------------------------------------------------
/** $voll blendet Datum und Routinenwahl ein - am Handy waeren sie nur Ballast. */
function quick(array $u, string $typ = 'bericht', bool $voll = false): string {
    $uid = (int)$u['id'];
    $rt = $voll ? all("SELECT id, name FROM routines WHERE user_id = ? AND aktiv = 1 ORDER BY sort, name", [$uid]) : [];
    $arten = ['bericht'=>'Bericht','notiz'=>'Notiz','aufgabe'=>'Aufgabe','termin'=>'Termin','abwesend'=>'Abwesend'];
    if ($rt) $arten = array_slice($arten, 0, 4, true) + ['routine'=>'Routine'] + array_slice($arten, 4, null, true);
    ob_start(); ?>
    <form method="post" action="<?= url('neu') ?>" class="c np"><div class="bo" style="padding:8px">
      <?= csrf_field() ?>
      <input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
      <div class="line">
        <select name="typ" style="width:104px;flex:none" aria-label="Art"><?= optm($arten, $typ) ?></select>
        <input name="text" required autocomplete="off" data-new placeholder="Kaffeemaschine geleert 0,5h">
        <?php if ($voll): ?>
          <input type="date" name="datum" value="<?= h(today()) ?>" style="width:140px;flex:none" aria-label="Datum">
        <?php endif; ?>
        <?php if ($rt): ?>
          <select name="rid" style="width:130px;flex:none" aria-label="Routine" data-only="routine"><?= opts($rt, null, 'Routine …') ?></select>
        <?php endif; ?>
        <button class="p" type="submit" style="flex:none">Speichern</button>
      </div>
    </div></form>
    <?php return ob_get_clean();
}
function a_neu(): void {
    $u = need_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(url('start'));
    csrf_check();
    $uid = (int)$u['id'];
    if (!rl('n:' . $uid, 200, 3600)) { flash('Zu viele Eintraege.', 'err'); redirect(url('start')); }
    $typ = post('typ', 'bericht'); $text = post('text');
    $datum = isodate(post('datum')) ? post('datum') : today();
    $rid = inull(postn('rid'));
    if ($text === '') redirect(zurueck(post('back'), url('start')));
    $std = 0.0;
    if (preg_match('/(?:^|[\s\-,;(])(\d+(?:[.,]\d+)?)\s*(h|std|stunden|min)\b\.?\s*\)?\s*$/iu', $text, $m)) {
        $w = (float)str_replace(',', '.', $m[1]);
        $std = strtolower($m[2]) === 'min' ? round($w / 60, 2) : $w;
        $text = trim(preg_replace('/(?:^|[\s\-,;(])(\d+(?:[.,]\d+)?)\s*(h|std|stunden|min)\b\.?\s*\)?\s*$/iu', '', $text), " -,;\t");
    }
    if ($text === '') { flash('Was wurde gemacht? Nur eine Zeit reicht nicht.', 'err'); redirect(zurueck(post('back'), url('start'))); }
    switch ($typ) {
        case 'notiz':
            ins('notes', ['user_id' => $uid, 'datum' => $datum, 'titel' => mb_substr($text, 0, 160),
                'body' => $text, 'kind' => 'notiz']);
            flash('Notiz gespeichert.'); break;
        case 'aufgabe':
            ins('tasks', ['user_id' => $uid, 'titel' => mb_substr($text, 0, 200), 'faellig' => $datum]);
            flash('Aufgabe angelegt.'); break;
        case 'termin':
            ins('events', ['user_id' => $uid, 'typ' => 'termin', 'titel' => mb_substr($text, 0, 200), 'datum' => $datum]);
            flash('Termin eingetragen.'); break;
        case 'routine':
            if ($rid) {
                $r = one("SELECT * FROM routines WHERE id = ? AND user_id = ?", [$rid, $uid]);
                if ($r) {
                    ins('routine_logs', ['routine_id' => $rid, 'user_id' => $uid, 'datum' => $datum,
                        'zeit' => date('H:i'), 'minuten' => (int)round($std > 0 ? $std * 60 : (int)$r['minuten']),
                        'notiz' => $text]);
                    flash($r['name'] . ' protokolliert.');
                }
            } else flash('Keine Routine gewaehlt.', 'warn');
            break;
        case 'abwesend':
            $art = 'krank';
            foreach (['urlaub' => 'urlaub', 'frei' => 'frei', 'dienstreise' => 'dienstreise'] as $w2 => $a2) {
                if (mb_stripos($text, $w2) !== false) $art = $a2;
            }
            ins('absences', ['user_id' => $uid, 'von' => $datum, 'bis' => $datum, 'art' => $art,
                'grund' => mb_substr($text, 0, 200)]);
            flash('Abwesenheit erfasst.'); break;
        default:
            $rep = report_ensure($uid, $u['bh_art'], periode_of($datum, $u['bh_art']));
            ins('report_entries', ['report_id' => (int)$rep['id'], 'user_id' => $uid, 'datum' => $datum,
                'stunden' => $std, 'category_id' => kategorie_zu($text), 'ort' => 'betrieb', 'text' => $text]);
            flash('Ins Berichtsheft uebernommen.');
    }
    redirect(zurueck(post('back'), url('start')));
}

// --- Faecher: alles zu einem Fach an einem Ort ------------------------------
function fach_zahlen(int $uid): array {
    $z = [];
    foreach (all("SELECT id FROM subjects WHERE user_id = ?", [$uid]) as $f) {
        $z[(int)$f['id']] = ['noten' => 0, 'notizen' => 0, 'termine' => 0, 'schnitt' => null, 'naechste' => null];
    }
    foreach (all("SELECT subject_id, skala, wert, gewicht FROM grades WHERE user_id = ? AND subject_id IS NOT NULL", [$uid]) as $g) {
        $i = (int)$g['subject_id']; if (!isset($z[$i])) continue;
        $n = to_note((float)$g['wert'], $g['skala']); if ($n === null) continue;
        $w = max(0.0, (float)$g['gewicht']);
        $z[$i]['sw'] = ($z[$i]['sw'] ?? 0) + $w; $z[$i]['sv'] = ($z[$i]['sv'] ?? 0) + $w * $n;
        $z[$i]['noten']++;
    }
    foreach (all("SELECT subject_id, COUNT(*) c FROM notes WHERE user_id = ? AND subject_id IS NOT NULL GROUP BY subject_id", [$uid]) as $r) {
        if (isset($z[(int)$r['subject_id']])) $z[(int)$r['subject_id']]['notizen'] = (int)$r['c'];
    }
    foreach (all("SELECT subject_id, MIN(datum) d, COUNT(*) c FROM events
                  WHERE user_id = ? AND subject_id IS NOT NULL AND typ IN ('probe','test','abgabe','pruefung')
                  AND datum >= date('now','localtime') GROUP BY subject_id", [$uid]) as $r) {
        $i = (int)$r['subject_id']; if (!isset($z[$i])) continue;
        $z[$i]['termine'] = (int)$r['c']; $z[$i]['naechste'] = $r['d'];
    }
    foreach ($z as $i => $x) $z[$i]['schnitt'] = !empty($x['sw']) ? $x['sv'] / $x['sw'] : null;
    return $z;
}
function fach_kacheln(int $uid, array $faecher, array $z): string {
    ob_start(); ?>
    <div class="kg">
      <?php foreach ($faecher as $f): $x = $z[(int)$f['id']] ?? []; ?>
        <a class="ka" href="<?= url('faecher', ['id' => $f['id']]) ?>">
          <div class="kk"><span class="dot" style="background:<?= h($f['color']) ?>"></span>
            <span class="kn"><?= h($f['short'] ?: $f['name']) ?></span>
            <span style="margin-left:auto"><?= npill($x['schnitt'] ?? null) ?></span></div>
          <div class="kz">
            <?php if (!empty($x['notizen'])): ?><span><?= (int)$x['notizen'] ?> <?= $x['notizen'] == 1 ? 'Notiz' : 'Notizen' ?></span><?php endif; ?>
            <?php if (!empty($x['noten'])): ?><span><?= (int)$x['noten'] ?> <?= $x['noten'] == 1 ? 'Note' : 'Noten' ?></span><?php endif; ?>
            <?php if (!empty($x['naechste'])): ?><span style="color:var(--ac)"><?= h(dt($x['naechste'], 'd.m.')) ?></span><?php endif; ?>
            <?php if (empty($x['notizen']) && empty($x['noten']) && empty($x['naechste'])): ?><span>&nbsp;</span><?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
    <?php return ob_get_clean();
}

function p_faecher(): void {
    $u = need_login(); $uid = (int)$u['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $a = post('a'); $id = (int)post('id', '0');
        if ($a === 'fach') {
            $d = ['name' => mb_substr(post('name'), 0, 80), 'short' => mb_substr(post('short'), 0, 10),
                'lf_no' => inull(postn('lf_no')), 'lehrer' => mb_substr(post('lehrer'), 0, 60),
                'color' => preg_match('/^#[0-9a-f]{6}$/i', post('color')) ? post('color') : '#0071e3',
                'sort' => (int)post('sort', '0'), 'archiv' => post('archiv') ? 1 : 0];
            if ($d['name'] === '') flash('Name fehlt.', 'err');
            elseif ($id) { upd('subjects', $d, 'id = :id AND user_id = :u', ['id' => $id, 'u' => $uid]); flash('Gespeichert.'); }
            else { $d['user_id'] = $uid; $id = ins('subjects', $d); flash('Fach angelegt.'); }
            redirect(url('faecher', $id ? ['id' => $id] : []));
        }
        if ($a === 'fachdel' && $id) {
            del('subjects', 'id = ? AND user_id = ?', [$id, $uid]); flash('Fach geloescht.');
            redirect(url('faecher'));
        }
        if ($a === 'notiz' && $id) {   // Schnellnotiz im Fachkontext
            $txt = post('text');
            if ($txt !== '') {
                $z = lines($txt);
                ins('notes', ['user_id' => $uid, 'subject_id' => $id,
                    'lf_no' => inull(postn('lf_no')),
                    'datum' => isodate(post('datum')) ? post('datum') : today(),
                    'titel' => mb_substr($z[0] ?? $txt, 0, 160), 'body' => $txt,
                    'kind' => in_array(post('kind'), ['notiz','stoff','howto','snippet','link'], true) ? post('kind') : 'notiz']);
                flash('Notiz gespeichert.');
            }
            redirect(url('faecher', ['id' => $id]));
        }
        if ($a === 'note' && $id) {    // Note im Fachkontext
            if (!is_numeric(str_replace(',', '.', post('wert')))) { flash('Wert muss eine Zahl sein.', 'err'); redirect(url('faecher', ['id' => $id])); }
            ins('grades', ['user_id' => $uid, 'subject_id' => $id,
                'art' => in_array(post('art'), ['schulaufgabe','kurzarbeit','test','muendlich','projekt','referat','ihk'], true) ? post('art') : 'test',
                'skala' => in_array(post('skala'), ['note','punkte','ihk'], true) ? post('skala') : 'note',
                'wert' => (float)str_replace(',', '.', post('wert', '0')),
                'gewicht' => max(0, (float)str_replace(',', '.', post('gewicht', '1'))),
                'datum' => isodate(post('datum')) ? post('datum') : today(),
                'titel' => mb_substr(post('titel'), 0, 150)]);
            flash('Note eingetragen.');
            redirect(url('faecher', ['id' => $id]));
        }
        if ($a === 'termin' && $id) {  // Probe im Fachkontext
            $t = post('titel');
            if ($t !== '') {
                ins('events', ['user_id' => $uid, 'subject_id' => $id,
                    'typ' => in_array(post('typ'), ['probe','test','abgabe','pruefung','termin'], true) ? post('typ') : 'probe',
                    'titel' => mb_substr($t, 0, 200),
                    'datum' => isodate(post('datum')) ? post('datum') : today(),
                    'lf_no' => inull(postn('lf_no')), 'stoff' => post('stoff')]);
                flash('Termin eingetragen.');
            }
            redirect(url('faecher', ['id' => $id]));
        }
        redirect(url('faecher'));
    }

    $id = (int)get('id');
    $fach = $id ? one("SELECT * FROM subjects WHERE id = ? AND user_id = ?", [$id, $uid]) : null;
    if ($fach) { fach_detail($u, $fach); return; }

    $zeigeArchiv = get('archiv') !== '';
    $faecher = all("SELECT * FROM subjects WHERE user_id = ?" . ($zeigeArchiv ? '' : ' AND archiv = 0')
                 . " ORDER BY archiv, sort, name", [$uid]);
    $z = fach_zahlen($uid);
    $neu = get('neu') !== '';
    ob_start(); ?>
    <div class="c"><div class="bo">
      <?php if (!$faecher): ?><?= em('Noch keine Faecher.') ?><?php else: ?><?= fach_kacheln($uid, $faecher, $z) ?><?php endif; ?>
    </div></div>
    <?php if ($neu): ?>
      <div class="c" style="max-width:520px"><div class="hd"><h2>Neues Fach</h2></div><div class="bo">
        <?= fach_form($uid, null) ?>
      </div></div>
    <?php endif; ?>
    <?php
    page('Faecher', ob_get_clean(), ['unter' => count($faecher) . ' Faecher',
        'aktion' => '<a class="bt s g" href="' . h(url('faecher', $zeigeArchiv ? [] : ['archiv' => 1])) . '">'
            . ($zeigeArchiv ? 'Archiv aus' : 'Archiv') . '</a>'
            . '<a class="bt p s" data-new href="' . h(url('faecher', ['neu' => 1])) . '">Neues Fach <kbd>n</kbd></a>']);
}

function fach_form(int $uid, ?array $e): string {
    ob_start(); ?>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="a" value="fach">
      <input type="hidden" name="id" value="<?= (int)($e['id'] ?? 0) ?>">
      <div class="f"><label for="fn">Name</label><input id="fn" name="name" required value="<?= h($e['name'] ?? '') ?>"<?= $e ? '' : ' autofocus' ?>></div>
      <div class="fg">
        <div class="f"><label for="fk">Kuerzel</label><input id="fk" name="short" value="<?= h($e['short'] ?? '') ?>"></div>
        <div class="f"><label for="fc">Farbe</label><input id="fc" name="color" type="color" value="<?= h($e['color'] ?? '#0071e3') ?>"></div>
        <div class="f"><label for="fs">Sort</label><input id="fs" name="sort" type="number" value="<?= (int)($e['sort'] ?? 0) ?>"></div>
      </div>
      <div class="fg">
        <div class="f"><label for="fl">Lernfeld</label><select id="fl" name="lf_no"><?= lf_opts($e['lf_no'] ?? null) ?></select></div>
        <div class="f"><label for="fa">Archiv</label><select id="fa" name="archiv"><?= optm([0=>'nein',1=>'ja'], $e['archiv'] ?? 0) ?></select></div>
      </div>
      <div class="f"><label for="fe">Lehrkraft</label><input id="fe" name="lehrer" value="<?= h($e['lehrer'] ?? '') ?>"></div>
      <div class="rw"><button class="p" type="submit">Speichern</button>
        <a class="bt g" href="<?= url('faecher') ?>">Abbrechen</a></div>
    </form>
    <?php return ob_get_clean();
}

function fach_detail(array $u, array $f): void {
    $uid = (int)$u['id']; $id = (int)$f['id'];
    $bearbeiten = get('e') !== '';
    $noten = all("SELECT * FROM grades WHERE user_id = ? AND subject_id = ? ORDER BY datum DESC", [$uid, $id]);
    $werte = [];
    foreach (array_reverse($noten) as $g) { $n = to_note((float)$g['wert'], $g['skala']); if ($n !== null) $werte[] = $n; }
    $sw = 0; $sv = 0;
    foreach ($noten as $g) { $n = to_note((float)$g['wert'], $g['skala']); if ($n === null) continue;
        $w = max(0.0, (float)$g['gewicht']); $sw += $w; $sv += $w * $n; }
    $schnitt = $sw > 0 ? $sv / $sw : null;
    $notizen = all("SELECT * FROM notes WHERE user_id = ? AND subject_id = ? ORDER BY pinned DESC, datum DESC, id DESC", [$uid, $id]);
    $gruppen = [];
    foreach ($notizen as $n) $gruppen[$n['kind']][] = $n;
    $termine = all("SELECT * FROM events WHERE user_id = ? AND subject_id = ? AND typ <> 'unterricht'
                    ORDER BY (datum < date('now','localtime')), datum LIMIT 20", [$uid, $id]);
    $aufgaben = all("SELECT * FROM tasks WHERE user_id = ? AND subject_id = ? AND status = 'offen' ORDER BY faellig", [$uid, $id]);
    $dateien = all("SELECT fi.* FROM files fi JOIN notes n ON n.id = fi.scope_id
                    WHERE fi.user_id = ? AND fi.scope = 'note' AND n.subject_id = ?", [$uid, $id]);
    $lf = $f['lf_no'] ? one("SELECT * FROM lernfelder WHERE nr = ?", [(int)$f['lf_no']]) : null;
    $artName = ['notiz'=>'Notizen','stoff'=>'Stoff','howto'=>'How-To','snippet'=>'Snippets','link'=>'Links'];

    ob_start(); ?>
    <div class="g g3" style="margin-bottom:14px">
      <div class="c"><div class="bo"><div class="lb">Schnitt</div>
        <div class="rw" style="justify-content:space-between">
          <div style="font-size:26px;font-weight:640;letter-spacing:-.03em;color:<?= h(nfarbe($schnitt)) ?>"><?= $schnitt !== null ? num($schnitt, 2) : '–' ?></div>
          <?php if (count($werte) >= 2): ?><?= spark($werte, 110, 30) ?><?php endif; ?>
        </div></div></div>
      <div class="c"><div class="bo"><div class="lb">Festgehalten</div>
        <div style="font-size:26px;font-weight:640;letter-spacing:-.03em"><?= count($notizen) ?>
          <span class="sm mu2" style="font-weight:400">Notizen</span></div></div></div>
      <div class="c"><div class="bo"><div class="lb">Anstehend</div>
        <div style="font-size:26px;font-weight:640;letter-spacing:-.03em">
          <?= count(array_filter($termine, fn($e) => $e['datum'] >= today())) ?>
          <span class="sm mu2" style="font-weight:400">Termine</span></div></div></div>
    </div>

    <?php if ($bearbeiten): ?>
      <div class="c" style="max-width:520px"><div class="hd"><h2>Fach bearbeiten</h2></div><div class="bo">
        <?= fach_form($uid, $f) ?>
        <hr><div class="lb">Teilen</div>
        <?= share_box($uid, 'fach', $id, (string)$f['name'], url('faecher', ['id' => $id, 'e' => 1])) ?>
        <hr>
        <form method="post" data-q="Fach loeschen? Notizen und Noten bleiben, verlieren aber die Zuordnung.">
          <?= csrf_field() ?><input type="hidden" name="a" value="fachdel"><input type="hidden" name="id" value="<?= $id ?>">
          <button class="d s" type="submit">Loeschen</button></form>
      </div></div>
    <?php endif; ?>

    <div class="sp2">
      <div>
        <div class="c"><div class="hd"><h2>Notiz zu diesem Fach</h2></div><div class="bo">
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="a" value="notiz"><input type="hidden" name="id" value="<?= $id ?>">
            <div class="f"><textarea name="text" required data-new data-d="fach<?= $id ?>" style="min-height:70px"
              placeholder="Erste Zeile wird der Titel"></textarea></div>
            <div class="line">
              <select name="kind" style="width:120px;flex:none"><?= optm(
                ['notiz'=>'Notiz','stoff'=>'Stoff','howto'=>'How-To','snippet'=>'Snippet','link'=>'Link'], 'notiz') ?></select>
              <select name="lf_no" style="width:150px;flex:none"><?= lf_opts($f['lf_no'] ?? null) ?></select>
              <input type="date" name="datum" value="<?= h(today()) ?>" style="width:140px;flex:none">
              <span style="flex:1"></span>
              <button class="p" type="submit">Speichern</button>
            </div>
          </form>
        </div></div>

        <?php foreach ($gruppen as $art => $items): ?>
          <div class="c"><div class="hd"><h2><?= h($artName[$art] ?? $art) ?></h2><span class="sp"></span>
            <span class="sm mu2"><?= count($items) ?></span></div>
            <div class="tw"><table><tbody>
              <?php foreach ($items as $n): ?>
                <tr><td class="mo sm mu2" style="width:82px;white-space:nowrap"><?= h(dt($n['datum'], 'd.m.y')) ?></td>
                  <td><?= $n['pinned'] ? '<span class="tg w">fix</span> ' : '' ?>
                    <a href="<?= url('notizen', ['id' => $n['id']]) ?>"><?= h($n['titel'] ?: mb_substr($n['body'], 0, 70)) ?></a>
                    <?php if ($n['tags']): ?><span class="sm mu2"><?= h($n['tags']) ?></span><?php endif; ?></td>
                  <td style="width:60px"><?= $n['lf_no'] ? '<span class="tg">LF' . (int)$n['lf_no'] . '</span>' : '' ?></td></tr>
              <?php endforeach; ?>
            </tbody></table></div>
          </div>
        <?php endforeach; ?>
        <?php if (!$notizen): ?><div class="c"><?= em('Noch nichts festgehalten.') ?></div><?php endif; ?>

        <?php if ($dateien): ?>
          <div class="c"><div class="hd"><h2>Material</h2></div>
            <ul class="li"><?php foreach ($dateien as $d): ?>
              <li><a href="<?= url('datei', ['id' => $d['id']]) ?>"><?= h($d['name']) ?></a>
                <span class="sm mu2 mo"><?= num($d['groesse'] / 1024, 0) ?> kB</span></li>
            <?php endforeach; ?></ul></div>
        <?php endif; ?>
      </div>

      <div>
        <div class="c"><div class="hd"><h2>Termine</h2></div>
          <?php if ($termine): ?>
          <div class="tw"><table><tbody>
            <?php foreach ($termine as $e): $alt = $e['datum'] < today(); ?>
              <tr<?= $alt ? ' style="opacity:.45"' : '' ?>>
                <td class="mo sm" style="width:74px;white-space:nowrap"><?= h(dt($e['datum'], 'd.m.y')) ?></td>
                <td><a href="<?= url('plan', ['id' => $e['id']]) ?>"><?= h($e['titel']) ?></a>
                  <?php $st = lines($e['stoff']); if ($st): ?><div class="sm mu2"><?= count($st) ?> Stoffpunkte</div><?php endif; ?></td>
                <td style="width:56px"><span class="tg<?= in_array($e['typ'], ['probe','pruefung'], true) ? ' e' : '' ?>"><?= h(typ_label($e['typ'])) ?></span></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <?php endif; ?>
          <details class="add"><summary>Termin</summary><div>
            <form method="post">
              <?= csrf_field() ?><input type="hidden" name="a" value="termin"><input type="hidden" name="id" value="<?= $id ?>">
              <div class="f"><input name="titel" required placeholder="z.B. 2. Schulaufgabe"></div>
              <div class="fg">
                <div class="f"><select name="typ"><?= optm(['probe'=>'Probe','test'=>'Test','abgabe'=>'Abgabe','pruefung'=>'Pruefung','termin'=>'Termin'], 'probe') ?></select></div>
                <div class="f"><input type="date" name="datum" value="<?= h(today()) ?>"></div>
              </div>
              <div class="f"><textarea name="stoff" placeholder="Stoff, eine Zeile je Punkt" style="min-height:56px"></textarea></div>
              <button class="p" type="submit">Termin anlegen</button>
            </form>
          </div></details>
        </div>

        <div class="c"><div class="hd"><h2>Noten</h2><span class="sp"></span>
          <span class="sm mu2"><?= count($noten) ?></span></div>
          <?php if ($noten): ?>
          <div class="tw"><table><tbody>
            <?php foreach ($noten as $g): ?>
              <tr><td class="mo sm" style="width:74px;white-space:nowrap"><?= h(dt($g['datum'], 'd.m.y')) ?></td>
                <td><a href="<?= url('noten', ['id' => $g['id']]) ?>"><?= h($g['titel'] ?: $g['art']) ?></a></td>
                <td class="n" style="width:60px"><?= npill(to_note((float)$g['wert'], $g['skala'])) ?></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <?php endif; ?>
          <details class="add"><summary>Note</summary><div>
            <form method="post">
              <?= csrf_field() ?><input type="hidden" name="a" value="note"><input type="hidden" name="id" value="<?= $id ?>">
              <div class="fg">
                <div class="f"><label for="nw">Wert</label><input id="nw" name="wert" required inputmode="decimal"></div>
                <div class="f"><label for="ns">Skala</label><select id="ns" name="skala"><?= optm(['note'=>'1–6','punkte'=>'0–15','ihk'=>'0–100'], 'note') ?></select></div>
                <div class="f"><label for="ng2">Gew.</label><input id="ng2" name="gewicht" inputmode="decimal" value="1"></div>
              </div>
              <div class="fg">
                <div class="f"><label for="na">Art</label><select id="na" name="art"><?= optm(['schulaufgabe'=>'Schulaufgabe','kurzarbeit'=>'Kurzarbeit','test'=>'Test','muendlich'=>'Muendlich','projekt'=>'Projekt','referat'=>'Referat'], 'schulaufgabe') ?></select></div>
                <div class="f"><label for="nd2">Datum</label><input id="nd2" name="datum" type="date" value="<?= h(today()) ?>"></div>
              </div>
              <div class="f"><input name="titel" placeholder="Titel"></div>
              <button class="p" type="submit">Note eintragen</button>
            </form>
          </div></details>
        </div>

        <?php if ($aufgaben): ?>
        <div class="c"><div class="hd"><h2>Offene Aufgaben</h2></div>
          <ul class="li"><?php foreach ($aufgaben as $t): ?>
            <li><a href="<?= url('plan', ['t' => 'aufgaben', 'id' => $t['id']]) ?>"><?= h($t['titel']) ?></a>
              <span class="sm mu2 mo" style="margin-left:auto"><?= $t['faellig'] ? h(dt($t['faellig'], 'd.m.')) : '' ?></span></li>
          <?php endforeach; ?></ul></div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    $unter = '<span class="dot" style="width:9px;height:9px;background:' . h($f['color']) . '"></span> ';
    $unter .= $lf ? h($lf['code']) . ' · ' . (int)$lf['jahr'] . '. Jahr' : 'ohne Lernfeld';
    if ($f['lehrer']) $unter .= ' · ' . h($f['lehrer']);
    page($f['name'], ob_get_clean(), ['unter' => $unter,
        'zurueck' => url('faecher'), 'zurueck_t' => 'Faecher',
        'aktion' => '<a class="bt s" href="' . h(url('faecher', ['id' => $id, 'e' => 1])) . '">Bearbeiten</a>'
            . '<a class="bt s g" href="' . h(url('faecher')) . '">Alle Faecher</a>']);
}

// --- Uebersicht -------------------------------------------------------------
function p_start(): void {
    $u = need_login(); $uid = (int)$u['id'];
    quellen_auto($u);
    $art = $u['bh_art']; $per = periode_of(today(), $art);
    $rep = report_get($uid, $art, $per);
    $sum = report_sum((int)$rep['id']);
    $mo  = date('Y-m-d', strtotime('monday this week'));
    $faecher = all("SELECT * FROM subjects WHERE user_id = ? AND archiv = 0 ORDER BY sort, name", [$uid]);
    $z = fach_zahlen($uid);
    $ev = all("SELECT e.*, s.short FROM events e LEFT JOIN subjects s ON s.id = e.subject_id
               WHERE e.user_id = ? AND e.typ IN ('probe','test','abgabe','pruefung','projekt','frist')
               AND e.datum >= date('now','localtime')
               ORDER BY e.datum, e.zeit_von LIMIT 10", [$uid]);
    $tk = all("SELECT t.*, s.short FROM tasks t LEFT JOIN subjects s ON s.id = t.subject_id
               WHERE t.user_id = ? AND t.status='offen' ORDER BY (t.faellig IS NULL), t.faellig LIMIT 10", [$uid]);
    $an = [];
    foreach ($ev as $e) $an[] = ['d' => $e['datum'], 'k' => 'termin', 'typ' => typ_label($e['typ']),
        't' => $e['titel'], 'u' => url('plan', ['id' => $e['id']]), 'f' => $e['short'], 'z' => $e['zeit_von']];
    foreach ($tk as $t) $an[] = ['d' => $t['faellig'] ?: '9999-12-31', 'k' => 'aufgabe', 'typ' => 'Aufgabe',
        't' => $t['titel'], 'u' => url('plan', ['t' => 'aufgaben', 'id' => $t['id']]), 'f' => $t['short'], 'z' => ''];
    usort($an, fn($a, $b) => [$a['d'], $a['t']] <=> [$b['d'], $b['t']]);
    $an = array_slice($an, 0, 8);
    $block = one("SELECT * FROM blocks WHERE user_id = ? AND date('now','localtime') BETWEEN von AND bis
                  ORDER BY (art='schule') DESC LIMIT 1", [$uid]);
    $tag = (int)date('N');
    $heuteUnterricht = [];
    if ($block && $block['art'] === 'schule' && $tag <= 5) {
        $heuteUnterricht = all("SELECT e.*, s.short, s.color FROM events e LEFT JOIN subjects s ON s.id = e.subject_id
            WHERE e.user_id = ? AND e.typ = 'unterricht' AND e.datum = ? ORDER BY e.zeit_von", [$uid, today()]);
        if (!$heuteUnterricht) {
            $heuteUnterricht = all("SELECT t.stunde, t.raum, s.short, s.name, s.color FROM timetable t
                LEFT JOIN subjects s ON s.id = t.subject_id WHERE t.user_id = ? AND t.tag = ? ORDER BY t.stunde", [$uid, $tag]);
        }
    }
    $rout = all("SELECT r.*, (SELECT MAX(datum) FROM routine_logs l WHERE l.routine_id = r.id) AS letzte
                 FROM routines r WHERE r.user_id = ? AND r.aktiv = 1 ORDER BY r.sort, r.name", [$uid]);
    $offen = array_values(array_filter($rout, fn($r) => match ($r['intervall']) {
        'taeglich' => $r['letzte'] !== today(),
        'woechentlich' => !$r['letzte'] || $r['letzte'] < $mo,
        'monatlich' => !$r['letzte'] || substr((string)$r['letzte'], 0, 7) !== date('Y-m'),
        default => false }));
    $marks = [];
    foreach (all("SELECT datum FROM events WHERE user_id = ? AND typ <> 'unterricht' AND datum BETWEEN ? AND date(?,'+6 day')", [$uid, $mo, $mo]) as $r) $marks[$r['datum']]['e'] = true;
    foreach (all("SELECT faellig FROM tasks WHERE user_id = ? AND status='offen' AND faellig BETWEEN ? AND date(?,'+6 day')", [$uid, $mo, $mo]) as $r) $marks[$r['faellig']]['t'] = true;

    $frisch = !val("SELECT 1 FROM sources WHERE user_id = ?", [$uid])
              && !val("SELECT 1 FROM blocks WHERE user_id = ?", [$uid]);
    $jetzt = jetzt_karte($u);
    ob_start(); ?>
    <?php if ($frisch): ?>
      <a class="c jetzt" href="<?= url('einrichtung') ?>">
        <span class="tile" style="background:linear-gradient(160deg,#3aa0ff,#0055c9)"><?= ic('import', 21) ?></span>
        <span class="tx"><b>Apps verbinden</b>
          <span class="sm mu">Stundenplan, Blockwochen und Fristen laden - dann steht dein Interface.</span></span>
        <?= ic('weiter', 18) ?>
      </a>
    <?php endif; ?>
    <?php if ($jetzt): ?>
      <a class="c jetzt" href="<?= h($jetzt['url']) ?>">
        <span class="tile" style="background:<?= h($jetzt['farbe']) ?>"><?= ic($jetzt['icon'], 21) ?></span>
        <span class="tx"><b><?= h($jetzt['titel']) ?></b>
          <span class="sm mu"><?= h($jetzt['kontext']) ?></span></span>
        <?= ic('weiter', 18) ?>
      </a>
    <?php endif; ?>
    <div class="c"><div class="bo" style="padding:9px 12px">
      <div class="rw" style="gap:3px;flex-wrap:nowrap">
        <?php $d = new DateTimeImmutable($mo); for ($i = 0; $i < 7; $i++):
          $ds = $d->format('Y-m-d'); $ist = $ds === today(); ?>
          <a href="<?= url('plan', ['von' => $ds, 'bis' => $ds]) ?>" style="flex:1 1 0;min-width:34px;text-align:center;
             padding:3px 2px 4px;border-radius:8px;text-decoration:none;<?= $ist ? 'background:var(--ac);color:#fff;font-weight:600' : 'color:var(--fg2)' ?>">
            <div class="sm" style="opacity:.75"><?= h(mb_substr(wd((int)$d->format('N')), 0, 2)) ?></div>
            <div style="font-size:16px;line-height:1.2"><?= $d->format('j') ?></div>
            <div style="height:6px;line-height:6px"><?php if (isset($marks[$ds])): ?><span class="dot" style="width:5px;height:5px;background:<?= $ist ? '#fff' : (isset($marks[$ds]['e']) ? 'var(--ac)' : 'var(--wa)') ?>"></span><?php endif; ?></div>
          </a>
        <?php $d = $d->modify('+1 day'); endfor; ?>
      </div>
      <div class="rw sm mu" style="margin-top:8px;gap:8px">
        <span class="tg"><?= h(klasse_name($u) ?: 'ohne Klasse') ?></span>
        <?php if (!val("SELECT 1 FROM blocks WHERE user_id = ?", [$uid])): ?>
          <a href="<?= url('einrichtung') ?>"><span class="tg w">Blockplan holen</span></a>
        <?php endif; ?>
        <?php if ($block): ?><span class="tg a"><?= h(ucfirst($block['art'])) ?> bis <?= h(dt($block['bis'], 'd.m.')) ?></span><?php endif; ?>
        <a href="<?= url('berichtsheft') ?>"><span class="tg <?= $rep['status'] === 'fertig' ? 'o' : 'w' ?>">Berichtsheft <?= num($sum['std'], 1) ?> h</span></a>
      </div>
    </div></div>
    <?= quick($u) ?>

    <?php if ($faecher):
      // Nur Faecher, in denen etwas steht - leere Kacheln sind auf dem Handy nur Weg
      $mitInhalt = array_values(array_filter($faecher, function ($f) use ($z) {
          $g = $z[(int)$f['id']] ?? [];
          return !empty($g['notizen']) || !empty($g['noten']) || !empty($g['naechste']);
      }));
      usort($mitInhalt, function ($a, $b) use ($z) {
          $ga = $z[(int)$a['id']] ?? []; $gb = $z[(int)$b['id']] ?? [];
          $va = (int)!empty($ga['naechste']) * 2 + (int)!empty($ga['notizen']) + (int)!empty($ga['noten']);
          $vb = (int)!empty($gb['naechste']) * 2 + (int)!empty($gb['notizen']) + (int)!empty($gb['noten']);
          return [$vb, (int)$a['sort']] <=> [$va, (int)$b['sort']];
      });
      if ($mitInhalt): ?>
      <div class="c"><div class="hd"><h2>Faecher</h2><span class="sp"></span>
        <a class="bt s g" href="<?= url('faecher') ?>">alle <?= count($faecher) ?></a></div><div class="bo">
        <?= fach_kacheln($uid, array_slice($mitInhalt, 0, 6), $z) ?>
      </div></div>
      <?php endif; ?>
    <?php endif; ?>

    <div class="sp2<?= ($heuteUnterricht || $offen) ? ' det' : '' ?>">
      <div class="c">
        <div class="hd"><h2>Anstehend</h2><span class="sp"></span>
          <a class="bt s g" href="<?= url('plan') ?>">Plan</a></div>
        <?php if (!$an): ?><?= em('Nichts offen.') ?><?php else: ?>
        <ul class="li rows">
          <?php foreach ($an as $x):
            $tage = $x['d'] === '9999-12-31' ? null : tage(today(), $x['d']);
            $wann = $tage === null ? 'ohne Termin'
                  : ($tage < 0 ? abs($tage) . ' Tage ueberfaellig'
                  : ($tage === 0 ? 'heute' : ($tage === 1 ? 'morgen' : dt($x['d'], 'D d.m.'))));
            $neben = array_filter([$x['typ'], $wann, $x['f'] ?: '', $x['z'] ? substr($x['z'], 0, 5) : '']); ?>
            <li><a href="<?= h($x['u']) ?>">
              <span class="tile t-<?= $x['k'] === 'aufgabe' ? 'aufgabe' : 'termin' ?>">
                <?= ic($x['k'] === 'aufgabe' ? 'aufgabe' : 'termin', 17) ?></span>
              <span class="tx"><b><?= h($x['t']) ?></b>
                <span class="sm mu2"><?= h(implode(' · ', $neben)) ?></span></span>
              <?php if ($tage !== null && $tage <= 1): ?>
                <span class="tg <?= $tage < 0 ? 'e' : 'a' ?>"><?= h($wann) ?></span>
              <?php endif; ?>
              <?= ic('weiter', 17) ?></a></li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
      <div>
        <?php if ($heuteUnterricht): ?>
        <div class="c"><div class="hd"><h2>Unterricht heute</h2></div>
          <ul class="li">
            <?php foreach ($heuteUnterricht as $s2): ?>
              <li><span class="mu2 mo" style="width:34px"><?= h(isset($s2['stunde']) ? (int)$s2['stunde'] . '.' : substr((string)$s2['zeit_von'], 0, 5)) ?></span>
                <span style="flex:1"><?= h($s2['short'] ?: ($s2['titel'] ?? $s2['name'] ?? '–')) ?></span>
                <span class="sm mu2"><?= h($s2['raum'] ?? '') ?></span></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
        <?php if ($offen): ?>
        <div class="c"><div class="hd"><h2>Routinen offen</h2><span class="sp"></span>
          <a class="bt s g" href="<?= url('berichtsheft', ['t' => 'routinen']) ?>">alle</a></div>
          <ul class="li">
            <?php foreach (array_slice($offen, 0, 8) as $r): ?>
              <li style="padding:5px 14px">
                <form method="post" action="<?= url('berichtsheft', ['t' => 'routinen']) ?>" style="display:flex;gap:9px;align-items:center;width:100%">
                  <?= csrf_field() ?><input type="hidden" name="a" value="log">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
                  <button class="s" type="submit" title="erledigt">&check;</button>
                  <span style="flex:1"><?= h($r['name']) ?></span>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    // Kurz halten: Datum, Klasse und Stand stehen im Wochenstreifen darunter
    page('Heute', ob_get_clean(), []);
}

// --- Termine ---------------------------------------------------------------
// --- Plan: Termine, Aufgaben, Stundenplan, Blockplan ------------------------
function p_plan(): void {
    $u = need_login();
    $t = get('t') ?: 'termine';
    if ($t === 'aufgaben')         plan_aufgaben($u);
    elseif ($t === 'stundenplan')  plan_stundenplan($u);
    elseif ($t === 'block')        plan_block($u);
    else                           plan_termine($u);
}

function plan_stundenplan(array $u): void {
    $uid = (int)$u['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        for ($tg = 1; $tg <= 5; $tg++) for ($st = 1; $st <= 11; $st++) {
            $sid = inull(postn("c{$tg}_{$st}")); $raum = post("r{$tg}_{$st}");
            if ($sid === null && $raum === '') { del('timetable', 'user_id = ? AND tag = ? AND stunde = ?', [$uid, $tg, $st]); continue; }
            q("INSERT INTO timetable (user_id,tag,stunde,subject_id,raum) VALUES (:u,:t,:s,:i,:r)
               ON CONFLICT(user_id,tag,stunde) DO UPDATE SET subject_id = :i2, raum = :r2",
              ['u' => $uid, 't' => $tg, 's' => $st, 'i' => $sid, 'r' => $raum, 'i2' => $sid, 'r2' => $raum]);
        }
        flash('Stundenplan gespeichert.');
        redirect(url('plan', ['t' => 'stundenplan']));
    }
    $tt = [];
    foreach (all("SELECT * FROM timetable WHERE user_id = ?", [$uid]) as $r) $tt[(int)$r['tag']][(int)$r['stunde']] = $r;
    $import = all("SELECT e.*, s.short, s.color FROM events e LEFT JOIN subjects s ON s.id = e.subject_id
                   WHERE e.user_id = ? AND e.typ = 'unterricht' AND e.datum BETWEEN date('now','localtime')
                   AND date('now','localtime','+6 day') ORDER BY e.datum, e.zeit_von", [$uid]);
    ob_start(); ?>
    <?php if ($import): ?>
      <div class="c"><div class="hd"><h2>Aus WebUntis diese Woche</h2><span class="sp"></span>
        <a class="bt s g" href="<?= url('einstellungen', ['t' => 'quellen']) ?>">Quellen</a></div>
        <div class="tw"><table><tbody>
          <?php $tag = ''; foreach ($import as $e): ?>
            <tr><td class="mo sm" style="width:92px;white-space:nowrap"><?= $tag === $e['datum'] ? '' : h(dt($e['datum'], 'D d.m.')) ?><?php $tag = $e['datum']; ?></td>
              <td class="mo sm mu2" style="width:80px"><?= h(substr($e['zeit_von'], 0, 5)) ?>–<?= h(substr($e['zeit_bis'], 0, 5)) ?></td>
              <td><?= h($e['titel']) ?></td><td class="sm mu2" style="width:70px"><?= h($e['raum']) ?></td></tr>
          <?php endforeach; ?>
        </tbody></table></div></div>
    <?php endif; ?>
    <?php
    $letzte = 0; foreach ($tt as $sp) foreach ($sp as $st2 => $c) $letzte = max($letzte, $st2);
    $fmap = [];
    foreach (all("SELECT id, short, color FROM subjects WHERE user_id = ?", [$uid]) as $f2) $fmap[(int)$f2['id']] = $f2;
    ?>
    <div class="c"><div class="hd"><h2>Fester Stundenplan</h2></div><div class="bo">
      <?php if ($letzte): ?>
        <div class="tt" style="margin-bottom:12px">
          <span></span>
          <?php for ($tg = 1; $tg <= 5; $tg++): ?><span class="h"><?= h(mb_substr(wd($tg), 0, 2)) ?></span><?php endfor; ?>
          <?php for ($st = 1; $st <= $letzte; $st++): ?>
            <span class="s"><?= $st ?></span>
            <?php for ($tg = 1; $tg <= 5; $tg++): $c = $tt[$tg][$st] ?? null;
              $f = $c ? ($fmap[(int)$c['subject_id']] ?? null) : null; ?>
              <span class="c2"<?= $f ? ' style="border-left-color:' . h($f['color']) . '"' : '' ?>><?php
                if ($f) echo h($f['short']);
                if ($c && $c['raum'] !== '') echo ' <span class="mu2">' . h($c['raum']) . '</span>';
              ?></span>
            <?php endfor; ?>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
      <?php if (!$letzte && !$import): ?>
        <div class="em" style="padding-bottom:6px">Noch kein Stundenplan.
          <a href="<?= url('einrichtung') ?>">Aus WebUntis laden</a> oder unten eintragen.</div>
      <?php endif; ?>
      <details class="add"><summary>Bearbeiten</summary>
      <form method="post">
        <?= csrf_field() ?>
        <div class="tw"><table><thead><tr><th></th>
          <?php for ($tg = 1; $tg <= 5; $tg++): ?><th><?= h(mb_substr(wd($tg), 0, 2)) ?></th><?php endfor; ?></tr></thead><tbody>
          <?php for ($st = 1; $st <= 11; $st++): ?>
            <tr><td class="mu2 mo sm" style="width:20px;padding:2px 6px"><?= $st ?></td>
              <?php for ($tg = 1; $tg <= 5; $tg++): $c = $tt[$tg][$st] ?? null; ?>
                <td style="padding:2px">
                  <select name="c<?= $tg ?>_<?= $st ?>"><?= fach_opts($uid, $c['subject_id'] ?? null, '–') ?></select>
                  <input name="r<?= $tg ?>_<?= $st ?>" value="<?= h($c['raum'] ?? '') ?>" placeholder="Raum" style="margin-top:2px"></td>
              <?php endfor; ?></tr>
          <?php endfor; ?>
        </tbody></table></div>
        <button class="p" style="margin-top:10px" type="submit">Speichern</button>
      </form>
      </details>
    </div></div>
    <?php
    page('Stundenplan', ob_get_clean(), []);
}

function plan_block(array $u): void {
    $uid = (int)$u['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $a = post('a');
        if ($a === 'add') {
            $von = isodate(post('von')) ? post('von') : today();
            ins('blocks', ['user_id' => $uid, 'von' => $von,
                'bis' => isodate(post('bis')) && post('bis') >= $von ? post('bis') : $von,
                'art' => in_array(post('art'), ['schule','betrieb','ferien','ueba','pruefung'], true) ? post('art') : 'schule',
                'label' => mb_substr(post('label'), 0, 60)]);
        } elseif ($a === 'del') {
            del('blocks', 'id = ? AND user_id = ?', [(int)post('id', '0'), $uid]);
        } elseif ($a === 'auto') {
            if (!rl('bp:' . $uid, 5, 3600)) flash('Zu viele Versuche.', 'err');
            else {
                $r = blockplan_und_ferien($u);
                flash($r['fehler'] !== '' ? $r['fehler'] : $r['n'] . ' Eintraege uebernommen' . $r['ferien'] . '.',
                      $r['fehler'] !== '' ? 'err' : 'ok');
            }
        } elseif ($a === 'import') {
            $zg   = max(1, min(9, (int)post('zg', (string)(int)$u['zeitgruppe'])));
            $jgst = in_array(post('jgst'), ['10','11','12','W'], true) ? post('jgst') : '10';
            $zip  = post('zip');
            if (!filter_var($zip, FILTER_VALIDATE_URL)) flash('Kein gueltiges Archiv gewaehlt.', 'err');
            else {
                $r = blockplan_import($u, $zip, $zg, $jgst);
                flash($r['fehler'] !== '' ? $r['fehler'] : $r['n'] . ' Eintraege uebernommen.',
                      $r['fehler'] !== '' ? 'err' : 'ok');
            }
        }
        redirect(url('plan', ['t' => 'block']));
    }
    // Kommende Bloecke zuerst - die Frage ist fast immer "wann als naechstes"
    $bl = all("SELECT * FROM blocks WHERE user_id = ?
               ORDER BY (bis < date('now','localtime')), von LIMIT 80", [$uid]);
    $archive = get('laden') !== '' ? blockplan_archive() : [];
    $jahr = lehrjahr($u, today());
    ob_start(); ?>
    <div class="sp2">
      <div class="c"><div class="hd"><h2>Blockwochen</h2><span class="sp"></span>
        <span class="sm mu2"><?= count($bl) ?></span></div>
        <?php if (!$bl): ?><?= em('Noch nichts eingetragen.') ?><?php else: ?>
        <ul class="li rows">
          <?php $naechster = null;
          foreach ($bl as $b2) if ($b2['art'] === 'schule' && $b2['von'] > today()) { $naechster = (int)$b2['id']; break; }
          foreach ($bl as $b): $jetzt = today() >= $b['von'] && today() <= $b['bis'];
            $zeit = dt($b['von'], 'd.m.y') . ($b['bis'] !== $b['von'] ? ' – ' . dt($b['bis'], 'd.m.y') : ''); ?>
            <li<?= $b['bis'] < today() ? ' style="opacity:.5"' : '' ?>>
              <span class="tile" style="background:<?= $b['art'] === 'schule' ? '#0f5fa8' : ($b['art'] === 'ferien' ? '#00b894' : '#ff9500') ?>">
                <?= ic($b['art'] === 'ferien' ? 'frei' : 'plan', 17) ?></span>
              <span class="tx"><b><?= h(block_label($b)) ?></b>
                <span class="sm mu2"><?= h($zeit) ?></span></span>
              <?= $jetzt ? '<span class="tg a">jetzt</span>' : ((int)$b['id'] === $naechster ? '<span class="tg o">naechste</span>' : '') ?>
              <form method="post" style="flex:none"><?= csrf_field() ?>
                <input type="hidden" name="a" value="del"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <button class="g s d" type="submit" data-q="Eintrag loeschen?">&times;</button></form>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
      <div>
        <div class="c"><div class="hd"><h2>Blockplan der Schule</h2></div><div class="bo">
          <?php if (!$archive): ?>
            <form method="post" style="margin-bottom:8px"><?= csrf_field() ?>
              <input type="hidden" name="a" value="auto">
              <button class="p" type="submit">Blockplan holen</button></form>
            <div class="sm mu2"><?= h(klasse_name($u)) ?> &middot; Zeitgruppe <?= (int)($u['zeitgruppe'] ?: 1) ?>
              &middot; Quelle bsfisi.m-bildung.de</div>
            <a class="bt g s" style="margin-top:9px" href="<?= url('plan', ['t' => 'block', 'laden' => 1]) ?>">Anderes Schuljahr</a>
          <?php else: ?>
            <form method="post">
              <?= csrf_field() ?><input type="hidden" name="a" value="import">
              <div class="f"><label for="zp">Schuljahr</label>
                <select id="zp" name="zip"><?php foreach ($archive as $u2 => $lbl): ?>
                  <option value="<?= h($u2) ?>"><?= h($lbl) ?></option><?php endforeach; ?></select></div>
              <div class="fg">
                <div class="f"><label for="zg">Zeitgruppe</label>
                  <input id="zg" name="zg" type="number" min="1" max="9" value="<?= (int)($u['zeitgruppe'] ?: 1) ?>"></div>
                <div class="f"><label for="jg">Jahrgangsstufe</label>
                  <select id="jg" name="jgst"><?= optm(['10'=>'10 (1. Jahr)','11'=>'11 (2. Jahr)','12'=>'12 (3. Jahr)','W'=>'W (Verkuerzer)'],
                    (string)(9 + max(1, min(3, $jahr)))) ?></select></div>
              </div>
              <button class="p" type="submit">Uebernehmen</button>
            </form>
            <div class="sm mu2" style="margin-top:8px">Ersetzt fruehere Blockplan-Eintraege, eigene bleiben.</div>
          <?php endif; ?>
        </div></div>
        <div class="c"><div class="hd"><h2>Eigener Eintrag</h2></div><div class="bo">
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="a" value="add">
            <div class="fg">
              <div class="f"><label for="bv">Von</label><input id="bv" name="von" type="date" required data-new></div>
              <div class="f"><label for="bb">Bis</label><input id="bb" name="bis" type="date"></div>
            </div>
            <div class="fg">
              <div class="f"><label for="ba">Art</label><select id="ba" name="art"><?= optm(
                ['schule'=>'Schulblock','betrieb'=>'Betrieb','ferien'=>'Ferien','ueba'=>'UEBA','pruefung'=>'Pruefung'], 'schule') ?></select></div>
              <div class="f"><label for="bl2">Label</label><input id="bl2" name="label"></div>
            </div>
            <button type="submit">Eintragen</button>
          </form>
        </div></div>
      </div>
    </div>
    <?php
    page('Blockplan', ob_get_clean(), []);
}

function plan_termine(array $u): void {
    $uid = (int)$u['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $a = post('a'); $id = (int)post('id', '0');
        if ($a === 'save') {
            $d = ['subject_id' => inull(postn('subject_id')),
                'typ' => in_array(post('typ'), ['probe','test','abgabe','pruefung','termin','projekt','frist','frei'], true) ? post('typ') : 'termin',
                'titel' => mb_substr(post('titel'), 0, 200), 'beschreibung' => post('beschreibung'),
                'datum' => isodate(post('datum')) ? post('datum') : today(),
                'zeit_von' => post('zeit_von'), 'zeit_bis' => post('zeit_bis'),
                'raum' => mb_substr(post('raum'), 0, 40), 'lf_no' => inull(postn('lf_no')), 'stoff' => post('stoff')];
            if ($d['titel'] === '') flash('Titel fehlt.', 'err');
            elseif ($id) { upd('events', $d, 'id = :id AND user_id = :u', ['id' => $id, 'u' => $uid]); flash('Gespeichert.'); }
            else { $d['user_id'] = $uid; $id = ins('events', $d); flash('Angelegt.'); }
            redirect(url('plan', ['id' => $id]));
        }
        if ($a === 'del') { del('events', 'id = ? AND user_id = ?', [$id, $uid]); flash('Geloescht.'); redirect(url('plan')); }
    }
    $edit = get('id') !== '' ? one("SELECT * FROM events WHERE id = ? AND user_id = ?", [(int)get('id'), $uid]) : null;
    if (get('neu') !== '') $edit = ['id' => 0, 'datum' => isodate(get('von')) ? get('von') : today()];

    $von = get('von') ?: today();
    $bis = get('bis') ?: date('Y-m-d', strtotime('+180 days'));
    if (!isodate($von)) $von = today();
    if (!isodate($bis)) $bis = date('Y-m-d', strtotime('+180 days'));
    $typ = get('typ');
    $rows = all("SELECT e.*, s.short, s.color FROM events e LEFT JOIN subjects s ON s.id = e.subject_id
                 WHERE e.user_id = ? AND e.datum BETWEEN ? AND ?" . ($typ ? " AND e.typ = ?" : "")
               . " ORDER BY e.datum, e.zeit_von", $typ ? [$uid, $von, $bis, $typ] : [$uid, $von, $bis]);
    $m = get('m') ?: date('Y-m');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) $m = date('Y-m');
    $first = new DateTimeImmutable($m . '-01');
    $start = $first->modify('monday this week');
    if ($start > $first) $start = $start->modify('-7 days');
    $cal = [];
    foreach (all("SELECT e.*, s.color FROM events e LEFT JOIN subjects s ON s.id = e.subject_id
                  WHERE e.user_id = ? AND e.datum BETWEEN ? AND ?",
                 [$uid, $start->format('Y-m-d'), $start->modify('+41 days')->format('Y-m-d')]) as $e) $cal[$e['datum']][] = $e;

    ob_start(); ?>
    <div class="sp2<?= $edit !== null ? ' det' : '' ?>">
      <div>
        <div class="c">
          <div class="hd">
            <form method="get" class="rw" style="flex:1">
              <input type="hidden" name="p" value="plan">
              <input type="date" name="von" value="<?= h($von) ?>" style="width:135px">
              <input type="date" name="bis" value="<?= h($bis) ?>" style="width:135px">
              <select name="typ" style="width:110px"><?= optm(['' => 'Alle', 'probe' => 'Probe', 'test' => 'Test',
                'abgabe' => 'Abgabe', 'pruefung' => 'Pruefung', 'termin' => 'Termin', 'projekt' => 'Projekt',
                'frist' => 'Frist'], $typ) ?></select>
              <button class="s" type="submit">Filter</button>
            </form>
            <a class="bt p s" data-new href="<?= url('plan', ['neu' => 1]) ?>">Neu <kbd>n</kbd></a>
          </div>
          <?php if (!$rows): ?><?= em('Keine Termine im Zeitraum.') ?><?php else: ?>
          <div class="tw"><table class="stk"><thead><tr><th>Datum</th><th>Art</th><th>Titel</th><th>Fach</th><th>Raum</th></tr></thead><tbody>
            <?php foreach ($rows as $e): $alt = $e['datum'] < today(); ?>
              <tr<?= $alt ? ' style="opacity:.5"' : '' ?>>
                <td style="white-space:nowrap" class="mo"><?= h(dt($e['datum'], 'D d.m.y')) ?>
                  <?= $e['zeit_von'] ? '<span class="mu2">' . h(substr($e['zeit_von'], 0, 5)) . '</span>' : '' ?></td>
                <td data-eck><span class="tg<?= in_array($e['typ'], ['probe','pruefung'], true) ? ' e' : ($e['typ'] === 'test' ? ' w' : '') ?>"><?= h(typ_label($e['typ'])) ?></span></td>
                <td><a href="<?= url('plan', ['id' => $e['id']]) ?>"><?= h($e['titel']) ?></a>
                  <?php if ($e['stoff']): ?><span class="sm mu2"><?= count(lines($e['stoff'])) ?> Punkte</span><?php endif; ?></td>
                <td class="sm"><?= h($e['short'] ?: '') ?><?= $e['lf_no'] ? ' <span class="tg">LF' . (int)$e['lf_no'] . '</span>' : '' ?></td>
                <td class="sm" data-l="Raum"><?= h($e['raum']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <?php endif; ?>
        </div>
        <div class="c np">
          <div class="hd"><h2><?= h(dt($m . '-01', 'F Y')) ?></h2><span class="sp"></span>
            <a class="bt s g" href="<?= url('plan', ['m' => $first->modify('-1 month')->format('Y-m')]) ?>">&larr;</a>
            <a class="bt s g" href="<?= url('plan', ['m' => date('Y-m')]) ?>">heute</a>
            <a class="bt s g" href="<?= url('plan', ['m' => $first->modify('+1 month')->format('Y-m')]) ?>">&rarr;</a></div>
          <div class="bo">
            <div class="cal">
              <?php foreach (['Mo','Di','Mi','Do','Fr','Sa','So'] as $x): ?><div class="h"><?= $x ?></div><?php endforeach; ?>
              <?php $d = $start; for ($i = 0; $i < 42; $i++): $ds = $d->format('Y-m-d'); ?>
                <div class="d <?= $d->format('Y-m') !== $m ? 'o' : '' ?> <?= $ds === today() ? 't' : '' ?>">
                  <b><?= $d->format('j') ?></b>
                  <?php foreach (array_slice($cal[$ds] ?? [], 0, 2) as $e): ?>
                    <a class="e" style="background:<?= h($e['color'] ?: '#2563eb') ?>" href="<?= url('plan', ['id' => $e['id']]) ?>"><?= h(mb_substr($e['titel'], 0, 16)) ?></a>
                  <?php endforeach; ?>
                  <?php if (count($cal[$ds] ?? []) > 2): ?><div class="mu2" style="font-size:10px">+<?= count($cal[$ds]) - 2 ?></div><?php endif; ?>
                </div>
              <?php $d = $d->modify('+1 day'); endfor; ?>
            </div>
          </div>
        </div>
      </div>
      <div>
        <?php if ($edit !== null): $neu = empty($edit['id']); ?>
        <div class="c"><div class="hd"><h2><?= $neu ? 'Neuer Termin' : 'Bearbeiten' ?></h2></div><div class="bo">
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="a" value="save">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="f"><label for="t">Titel</label><input id="t" name="titel" required value="<?= h($edit['titel'] ?? '') ?>"<?= $neu ? ' autofocus' : '' ?>></div>
            <div class="fg">
              <div class="f"><label for="ty">Art</label><select id="ty" name="typ"><?= optm(
                ['probe'=>'Probe','test'=>'Test','abgabe'=>'Abgabe','pruefung'=>'Pruefung','termin'=>'Termin',
                 'projekt'=>'Projekt','frist'=>'Frist','frei'=>'Frei'], $edit['typ'] ?? 'probe') ?></select></div>
              <div class="f"><label for="da">Datum</label><input id="da" name="datum" type="date" required value="<?= h($edit['datum'] ?? today()) ?>"></div>
            </div>
            <div class="fg">
              <div class="f"><label for="zv">von</label><input id="zv" name="zeit_von" type="time" value="<?= h($edit['zeit_von'] ?? '') ?>"></div>
              <div class="f"><label for="zb">bis</label><input id="zb" name="zeit_bis" type="time" value="<?= h($edit['zeit_bis'] ?? '') ?>"></div>
              <div class="f"><label for="ra">Raum</label><input id="ra" name="raum" value="<?= h($edit['raum'] ?? '') ?>"></div>
            </div>
            <div class="fg">
              <div class="f"><label for="fa">Fach</label><select id="fa" name="subject_id"><?= fach_opts($uid, $edit['subject_id'] ?? null) ?></select></div>
              <div class="f"><label for="lf">Lernfeld</label><select id="lf" name="lf_no"><?= lf_opts($edit['lf_no'] ?? null) ?></select></div>
            </div>
            <div class="f"><label for="st">Stoff</label>
              <textarea id="st" name="stoff" data-d="ev<?= (int)($edit['id'] ?? 0) ?>"><?= h($edit['stoff'] ?? '') ?></textarea></div>
            <div class="f"><label for="be">Notiz</label><textarea id="be" name="beschreibung" style="min-height:52px"><?= h($edit['beschreibung'] ?? '') ?></textarea></div>
            <div class="rw"><button class="p" type="submit">Speichern</button>
              <a class="bt g" href="<?= url('plan') ?>">Schliessen</a></div>
          </form>
          <?php if (!$neu): $stoff = lines($edit['stoff'] ?? ''); if ($stoff): ?>
            <hr><ul class="li" style="margin:0">
              <?php foreach ($stoff as $s): ?><li style="padding:3px 0">&#9633; <?= h($s) ?></li><?php endforeach; ?></ul>
          <?php endif; ?>
            <hr>
            <form method="post" data-q="Termin loeschen?"><?= csrf_field() ?>
              <input type="hidden" name="a" value="del"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
              <button class="d s" type="submit">Loeschen</button></form>
          <?php endif; ?>
        </div></div>
        <?php else: ?>
        <div class="c"><div class="hd"><h2>Kalender-Abo</h2></div><div class="bo">
          <pre id="ics" style="white-space:pre-wrap;word-break:break-all;font-size:11px"><?= h(abs_url(url('ics', ['t' => $u['ics_token'] ?: '-']))) ?></pre>
          <button class="s" data-copy="ics" type="button">Kopieren</button>
        </div></div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    page('Termine', ob_get_clean(), []);
}

// --- Aufgaben --------------------------------------------------------------
function plan_aufgaben(array $u): void {
    $uid = (int)$u['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $a = post('a'); $id = (int)post('id', '0');
        if ($a === 'ok' && $id) {
            $t = one("SELECT * FROM tasks WHERE id = ? AND user_id = ?", [$id, $uid]);
            if ($t) {
                $n = $t['status'] === 'offen' ? 'erledigt' : 'offen';
                upd('tasks', ['status' => $n, 'erledigt_am' => $n === 'erledigt' ? date('Y-m-d H:i:s') : null],
                    'id = :id', ['id' => $id]);
            }
        } elseif ($a === 'save') {
            $d = ['titel' => mb_substr(post('titel'), 0, 200), 'beschreibung' => post('beschreibung'),
                'faellig' => isodate(post('faellig')) ? post('faellig') : null,
                'prio' => max(0, min(2, (int)post('prio', '1'))), 'subject_id' => inull(postn('subject_id')),
                'bereich' => in_array(post('bereich'), ['schule','betrieb','privat'], true) ? post('bereich') : 'schule'];
            if ($d['titel'] === '') flash('Titel fehlt.', 'err');
            elseif ($id) { upd('tasks', $d, 'id = :id AND user_id = :u', ['id' => $id, 'u' => $uid]); flash('Gespeichert.'); }
            else { $d['user_id'] = $uid; ins('tasks', $d); flash('Angelegt.'); }
        } elseif ($a === 'del' && $id) { del('tasks', 'id = ? AND user_id = ?', [$id, $uid]); flash('Geloescht.'); }
        redirect(zurueck(post('back'), url('plan', ['t' => 'aufgaben'])));
    }
    $st = get('status') ?: 'offen';
    $be = get('bereich');
    $sql = "SELECT t.*, s.short FROM tasks t LEFT JOIN subjects s ON s.id = t.subject_id WHERE t.user_id = ?"
         . ($st !== 'alle' ? " AND t.status = ?" : "") . ($be ? " AND t.bereich = ?" : "")
         . " ORDER BY t.status, (t.faellig IS NULL), t.faellig, t.prio DESC";
    $ar = [$uid]; if ($st !== 'alle') $ar[] = $st; if ($be) $ar[] = $be;
    $rows = all($sql, $ar);
    $edit = get('id') !== '' ? one("SELECT * FROM tasks WHERE id = ? AND user_id = ?", [(int)get('id'), $uid]) : null;
    ob_start(); ?>
    <div class="sp2<?= $edit !== null ? ' det' : '' ?>">
      <div class="c">
        <div class="hd">
          <form method="get" class="rw" style="flex:1"><input type="hidden" name="p" value="plan">
            <input type="hidden" name="t" value="aufgaben">
            <select name="status" style="width:100px"><?= optm(['offen'=>'Offen','erledigt'=>'Erledigt','alle'=>'Alle'], $st) ?></select>
            <select name="bereich" style="width:110px"><?= optm(['' => 'Alle Bereiche','schule'=>'Schule','betrieb'=>'Betrieb','privat'=>'Privat'], $be) ?></select>
            <button class="s" type="submit">Filter</button>
            <input placeholder="Filtern" data-fl="#tt" style="width:130px">
          </form>
        </div>
        <?php if (!$rows): ?><?= em('Keine Aufgaben.') ?><?php else: ?>
        <div class="tw"><table id="tt" class="stk"><tbody>
          <?php foreach ($rows as $t): $ue = $t['status'] === 'offen' && $t['faellig'] && $t['faellig'] < today(); ?>
            <tr>
              <td style="width:34px" data-eck>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="ok">
                  <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                  <input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
                  <button class="g s" type="submit"><?= $t['status'] === 'offen' ? '&#9633;' : '&#9635;' ?></button></form>
              </td>
              <td><a href="<?= url('aufgaben', ['id' => $t['id']]) ?>"<?= $t['status'] === 'erledigt' ? ' style="text-decoration:line-through;color:var(--fg3)"' : '' ?>><?= h($t['titel']) ?></a></td>
              <td class="mo sm" style="width:88px;white-space:nowrap" data-l="faellig"><?= $t['faellig'] ? h(dt($t['faellig'], 'D d.m.')) : '' ?></td>
              <td style="width:70px"><span class="tg"><?= h($t['bereich']) ?></span></td>
              <td class="sm" style="width:60px"><?= h($t['short'] ?: '') ?></td>
              <td style="width:80px"><?php if ((int)$t['prio'] === 2): ?><span class="tg w">hoch</span><?php endif;
                if ($ue): ?><span class="tg e">ueber</span><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
      </div>
      <div class="c"><div class="hd"><h2><?= $edit ? 'Bearbeiten' : 'Neue Aufgabe' ?></h2></div><div class="bo">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="a" value="save">
          <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
          <div class="f"><label for="ti">Titel</label><input id="ti" name="titel" required data-new value="<?= h($edit['titel'] ?? '') ?>"></div>
          <div class="f"><label for="de">Notiz</label><textarea id="de" name="beschreibung" style="min-height:52px"><?= h($edit['beschreibung'] ?? '') ?></textarea></div>
          <div class="fg">
            <div class="f"><label for="fl">Faellig</label><input id="fl" name="faellig" type="date" value="<?= h($edit['faellig'] ?? '') ?>"></div>
            <div class="f"><label for="pr">Prio</label><select id="pr" name="prio"><?= optm([0=>'niedrig',1=>'normal',2=>'hoch'], $edit['prio'] ?? 1) ?></select></div>
          </div>
          <div class="fg">
            <div class="f"><label for="br">Bereich</label><select id="br" name="bereich"><?= optm(['schule'=>'Schule','betrieb'=>'Betrieb','privat'=>'Privat'], $edit['bereich'] ?? 'schule') ?></select></div>
            <div class="f"><label for="fc">Fach</label><select id="fc" name="subject_id"><?= fach_opts($uid, $edit['subject_id'] ?? null) ?></select></div>
          </div>
          <div class="rw"><button class="p" type="submit">Speichern</button>
            <?php if ($edit): ?><a class="bt g" href="<?= url('plan', ['t' => 'aufgaben']) ?>">Neu</a><?php endif; ?></div>
        </form>
        <?php if ($edit): ?>
          <hr><form method="post" data-q="Loeschen?"><?= csrf_field() ?>
            <input type="hidden" name="a" value="del"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
            <button class="d s" type="submit">Loeschen</button></form>
        <?php endif; ?>
      </div></div>
    </div>
    <?php
    page('Aufgaben', ob_get_clean(), []);
}

// --- Notizen ---------------------------------------------------------------
function p_notizen(): void {
    $u = need_login(); $uid = (int)$u['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $a = post('a'); $id = (int)post('id', '0');
        if ($a === 'save') {
            $d = ['subject_id' => inull(postn('subject_id')), 'lf_no' => inull(postn('lf_no')),
                'datum' => isodate(post('datum')) ? post('datum') : today(),
                'titel' => mb_substr(post('titel'), 0, 200), 'body' => post('body'),
                'tags' => mb_substr(post('tags'), 0, 200),
                'kind' => in_array(post('kind'), ['notiz','stoff','howto','snippet','link'], true) ? post('kind') : 'notiz',
                'sprache' => mb_substr(post('sprache'), 0, 24), 'pinned' => post('pinned') ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s')];
            if ($d['titel'] === '' && $d['body'] === '') flash('Leer.', 'warn');
            elseif ($id) { upd('notes', $d, 'id = :id AND user_id = :u', ['id' => $id, 'u' => $uid]); flash('Gespeichert.'); }
            else { $d['user_id'] = $uid; $id = ins('notes', $d); flash('Angelegt.'); }
            if (!empty($_FILES['datei']['name'])) {
                $e = upload($_FILES['datei'], $uid, 'note', $id);
                if ($e) flash($e, 'err');
            }
            redirect(url('notizen', ['id' => $id]));
        }
        if ($a === 'del' && $id) {
            del('files', "scope='note' AND scope_id = ? AND user_id = ?", [$id, $uid]);
            del('notes', 'id = ? AND user_id = ?', [$id, $uid]);
            flash('Geloescht.'); redirect(url('notizen'));
        }
        if ($a === 'delf') {
            del('files', 'id = ? AND user_id = ?', [(int)post('fid', '0'), $uid]);
            redirect(url('notizen', ['id' => $id]));
        }
    }
    $edit = get('id') !== '' ? one("SELECT * FROM notes WHERE id = ? AND user_id = ?", [(int)get('id'), $uid]) : null;
    if (get('neu') !== '') $edit = ['id' => 0, 'datum' => today()];
    $qs = get('q'); $kind = get('kind'); $lf = get('lf');
    $sql = "SELECT n.*, s.short FROM notes n LEFT JOIN subjects s ON s.id = n.subject_id WHERE n.user_id = ?"
         . ($kind ? " AND n.kind = ?" : "") . ($lf ? " AND n.lf_no = ?" : "")
         . ($qs ? " AND (n.titel LIKE ? OR n.body LIKE ? OR n.tags LIKE ?)" : "")
         . " ORDER BY n.pinned DESC, n.datum DESC, n.id DESC LIMIT 300";
    $ar = [$uid]; if ($kind) $ar[] = $kind; if ($lf) $ar[] = (int)$lf;
    if ($qs) { $l = '%' . $qs . '%'; array_push($ar, $l, $l, $l); }
    $rows = all($sql, $ar);
    $files = $edit && !empty($edit['id']) ? all("SELECT * FROM files WHERE scope='note' AND scope_id = ? AND user_id = ?", [(int)$edit['id'], $uid]) : [];
    ob_start(); ?>
    <div class="sp2<?= $edit !== null ? ' det' : '' ?>">
      <div class="c">
        <div class="hd">
          <form method="get" class="rw" style="flex:1"><input type="hidden" name="p" value="notizen">
            <input name="q" value="<?= h($qs) ?>" placeholder="Suchen" style="width:150px">
            <select name="kind" style="width:110px"><?= optm(['' => 'Alle Arten','notiz'=>'Notiz','stoff'=>'Stoff',
              'howto'=>'How-To','snippet'=>'Snippet','link'=>'Link'], $kind) ?></select>
            <select name="lf" style="width:100px"><?= opts(all("SELECT nr AS id, code AS name FROM lernfelder ORDER BY nr"), $lf, 'Alle LF') ?></select>
            <button class="s" type="submit">Filter</button>
          </form>
        </div>
        <?php if (!$rows): ?><?= em('Nichts gefunden.') ?><?php else: ?>
        <ul class="li rows">
          <?php foreach ($rows as $n):
            $neben = array_filter([$n['kind'], dt($n['datum'], 'd.m.y'), $n['short'] ?: '',
                                   $n['lf_no'] ? 'LF' . (int)$n['lf_no'] : '']); ?>
            <li><a href="<?= url('notizen', ['id' => $n['id']]) ?>">
              <span class="tile t-notizen"><?= ic('notizen', 17) ?></span>
              <span class="tx"><b><?= $n['pinned'] ? '<span class="tg w">fix</span> ' : '' ?><?= h($n['titel'] ?: mb_substr($n['body'], 0, 70)) ?></b>
                <span class="sm mu2"><?= h(implode(' · ', $neben)) ?></span></span>
              <?= ic('weiter', 17) ?></a></li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
      <div>
        <?php if ($edit !== null): $neu = empty($edit['id']); ?>
        <?php if (!$neu && trim((string)$edit['body']) !== ''): ?>
          <div class="c"><div class="hd"><h2><?= h($edit['titel'] ?: 'Notiz') ?></h2><span class="sp"></span>
            <?php if (($edit['kind'] ?? '') === 'snippet'): ?>
              <button class="s" data-copy="snip" type="button">Code kopieren</button><?php endif; ?>
            <a class="bt s g" href="<?= url('notizen') ?>">Schliessen</a></div><div class="bo">
            <div class="sm mu2" style="margin-bottom:9px">
              <?= h(dt($edit['datum'])) ?> · <?= h($edit['kind']) ?>
              <?= $edit['lf_no'] ? ' · LF' . (int)$edit['lf_no'] : '' ?>
              <?= $edit['tags'] ? ' · ' . h($edit['tags']) : '' ?></div>
            <?php if (($edit['kind'] ?? '') === 'snippet'): ?><pre id="snip"><?= h($edit['body']) ?></pre>
            <?php else: ?><div><?= md($edit['body']) ?></div><?php endif; ?>
          </div></div>
        <?php endif; ?>
        <div class="c"><div class="hd"><h2><?= $neu ? 'Neue Notiz' : 'Bearbeiten' ?></h2></div><div class="bo">
          <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="a" value="save">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="f"><label for="nt">Titel</label><input id="nt" name="titel" value="<?= h($edit['titel'] ?? '') ?>"<?= $neu ? ' autofocus' : '' ?>></div>
            <div class="fg">
              <div class="f"><label for="nd">Datum</label><input id="nd" name="datum" type="date" value="<?= h($edit['datum'] ?? today()) ?>"></div>
              <div class="f"><label for="nk">Art</label><select id="nk" name="kind"><?= optm(
                ['notiz'=>'Notiz','stoff'=>'Stoff','howto'=>'How-To','snippet'=>'Snippet','link'=>'Link'], $edit['kind'] ?? 'notiz') ?></select></div>
            </div>
            <div class="fg">
              <div class="f"><label for="nf">Fach</label><select id="nf" name="subject_id"><?= fach_opts($uid, $edit['subject_id'] ?? null) ?></select></div>
              <div class="f"><label for="nl">Lernfeld</label><select id="nl" name="lf_no"><?= lf_opts($edit['lf_no'] ?? null) ?></select></div>
            </div>
            <div class="f"><label for="nb">Inhalt</label>
              <textarea id="nb" name="body" style="min-height:190px" data-d="n<?= (int)($edit['id'] ?? 0) ?>"><?= h($edit['body'] ?? '') ?></textarea></div>
            <div class="fg">
              <div class="f"><label for="ng">Tags</label><input id="ng" name="tags" value="<?= h($edit['tags'] ?? '') ?>"></div>
              <div class="f"><label for="np">Anheften</label><select id="np" name="pinned"><?= optm([0=>'nein',1=>'ja'], $edit['pinned'] ?? 0) ?></select></div>
            </div>
            <div class="f"><label for="nu">Datei</label><input id="nu" name="datei" type="file"></div>
            <div class="rw"><button class="p" type="submit">Speichern</button>
              <a class="bt g" href="<?= url('notizen') ?>">Schliessen</a></div>
          </form>
          <?php if ($files): ?>
            <hr><ul class="li">
              <?php foreach ($files as $f): ?>
                <li style="padding:4px 0"><a href="<?= url('datei', ['id' => $f['id']]) ?>"><?= h($f['name']) ?></a>
                  <span class="sm mu2 mo"><?= num($f['groesse'] / 1024, 0) ?> kB</span>
                  <form method="post" style="margin-left:auto"><?= csrf_field() ?>
                    <input type="hidden" name="a" value="delf"><input type="hidden" name="fid" value="<?= (int)$f['id'] ?>">
                    <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
                    <button class="g s d" type="submit">&times;</button></form></li>
              <?php endforeach; ?></ul>
          <?php endif; ?>
          <?php if (!$neu): ?>
            <hr><div class="lb">Teilen</div>
            <?= share_box($uid, 'notiz', (int)$edit['id'], (string)($edit['titel'] ?: 'Notiz'), url('notizen', ['id' => (int)$edit['id']])) ?>
            <hr><form method="post" data-q="Notiz loeschen?"><?= csrf_field() ?>
              <input type="hidden" name="a" value="del"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
              <button class="d s" type="submit">Loeschen</button></form>
          <?php endif; ?>
        </div></div>
        <?php else: ?>
        <div class="c"><div class="hd"><h2>Lernfelder</h2></div><div class="bo">
          <div class="ch">
            <?php foreach (all("SELECT nr, code FROM lernfelder ORDER BY nr") as $l): ?>
              <a class="<?= (string)$l['nr'] === $lf ? 'on' : '' ?>" href="<?= url('notizen', ['lf' => $l['nr']]) ?>"><?= h($l['code']) ?></a>
            <?php endforeach; ?>
          </div>
        </div></div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    page('Notizen', ob_get_clean(), ['unter' => count($rows) . ' Eintraege']
        + ($edit !== null ? ['zurueck' => url('notizen'), 'zurueck_t' => 'Notizen'] : [])
        + ['aktion' => '<a class="bt p s" data-new href="' . h(url('notizen', ['neu' => 1])) . '">Neue Notiz <kbd>n</kbd></a>']);
}

// --- Noten -----------------------------------------------------------------
function p_noten(): void {
    $u = need_login(); $uid = (int)$u['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $a = post('a'); $id = (int)post('id', '0');
        if ($a === 'save') {
            if (!is_numeric(str_replace(',', '.', post('wert')))) { flash('Wert muss eine Zahl sein.', 'err'); redirect(url('noten')); }
            $d = ['subject_id' => inull(postn('subject_id')), 'fach_text' => mb_substr(post('fach_text'), 0, 60),
                'art' => in_array(post('art'), ['schulaufgabe','kurzarbeit','test','muendlich','projekt','referat','ihk'], true) ? post('art') : 'test',
                'skala' => in_array(post('skala'), ['note','punkte','ihk'], true) ? post('skala') : 'note',
                'wert' => (float)str_replace(',', '.', post('wert', '0')),
                'gewicht' => max(0, (float)str_replace(',', '.', post('gewicht', '1'))),
                'datum' => isodate(post('datum')) ? post('datum') : today(),
                'titel' => mb_substr(post('titel'), 0, 150), 'halbjahr' => mb_substr(post('halbjahr'), 0, 16),
                'bemerkung' => mb_substr(post('bemerkung'), 0, 200)];
            if ($id) { upd('grades', $d, 'id = :id AND user_id = :u', ['id' => $id, 'u' => $uid]); flash('Gespeichert.'); }
            else { $d['user_id'] = $uid; ins('grades', $d); flash('Eingetragen.'); }
        } elseif ($a === 'del' && $id) { del('grades', 'id = ? AND user_id = ?', [$id, $uid]); flash('Geloescht.'); }
        redirect(url('noten'));
    }
    $g = noten_stats($uid);
    $edit = get('id') !== '' ? one("SELECT * FROM grades WHERE id = ? AND user_id = ?", [(int)get('id'), $uid]) : null;
    $maxv = max(1, max($g['vert']));
    $alle = array_values(array_filter(array_map(fn($r) => to_note((float)$r['wert'], $r['skala']), $g['rows']), fn($v) => $v !== null));
    ob_start(); ?>
    <div class="sp2">
      <div>
        <div class="c"><div class="bo">
          <div class="rw" style="gap:22px">
            <div><div class="sm mu2">Schnitt</div>
              <div style="font-size:24px;font-weight:600;color:<?= h(nfarbe($g['schnitt'])) ?>">
                <?= $g['schnitt'] !== null ? num($g['schnitt'], 2) : '–' ?></div></div>
            <div><div class="sm mu2">Noten</div><div style="font-size:24px;font-weight:600"><?= count($alle) ?></div></div>
            <div><div class="sm mu2">Beste</div><div style="font-size:24px;font-weight:600"><?= $alle ? num(min($alle), 1) : '–' ?></div></div>
            <div style="flex:1;min-width:150px">
              <div class="rw" style="align-items:flex-end;height:56px;gap:4px;flex-wrap:nowrap">
                <?php foreach ($g['vert'] as $n => $c): ?>
                  <div style="flex:1;text-align:center">
                    <div style="height:<?= (int)round($c / $maxv * 34) ?>px;background:<?= h(nfarbe((float)$n)) ?>;border-radius:2px 2px 0 0;min-height:2px"></div>
                    <div class="sm mu2" style="font-size:10px"><?= $n ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div></div>
        <div class="c">
          <div class="hd"><h2>Noten</h2><span class="sp"></span>
            <a class="bt s g" href="<?= url('export', ['w' => 'noten']) ?>">CSV</a></div>
          <?php if (!$g['rows']): ?><?= em('Keine Noten.') ?><?php else: ?>
          <ul class="li rows">
            <?php foreach (array_reverse($g['rows']) as $r): $n = to_note((float)$r['wert'], $r['skala']);
              $neben = array_filter([$r['short'] ?: ($r['fach'] ?: ''), $r['art'], dt($r['datum'], 'd.m.y'),
                                     (float)$r['gewicht'] != 1.0 ? 'Gewicht ' . num((float)$r['gewicht'], 1) : '']); ?>
              <li><a href="<?= url('noten', ['id' => $r['id']]) ?>">
                <span class="tile t-noten"><?= ic('noten', 17) ?></span>
                <span class="tx"><b><?= h($r['titel'] ?: ($r['art'] ?: 'Note')) ?></b>
                  <span class="sm mu2"><?= h(implode(' · ', $neben)) ?></span></span>
                <?= npill($n) ?></a></li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </div>
        <?php if ($g['faecher']): ?>
        <div class="c">
          <div class="hd"><h2>Faecher</h2></div>
          <ul class="li rows">
            <?php foreach ($g['faecher'] as $f): if ($f['schnitt'] === null) continue;
              $tend = $f['trend'] === null ? '' : ($f['trend'] > 0.15 ? 'wird besser'
                    : ($f['trend'] < -0.15 ? 'wird schlechter' : 'stabil')); ?>
              <li>
                <span class="tile" style="background:<?= h($f['color']) ?>"><?= ic('faecher', 17) ?></span>
                <span class="tx"><b><?= h($f['name']) ?></b>
                  <span class="sm mu2"><?= (int)$f['anzahl'] ?> <?= (int)$f['anzahl'] === 1 ? 'Note' : 'Noten' ?><?= $tend ? ' · ' . h($tend) : '' ?></span></span>
                <?= count($f['n']) >= 2 ? spark($f['n'], 74, 20) : '' ?>
                <?= npill($f['schnitt']) ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
      </div>
      <div class="c"><div class="hd"><h2><?= $edit ? 'Bearbeiten' : 'Neue Note' ?></h2></div><div class="bo">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="a" value="save">
          <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
          <div class="f"><label for="gf">Fach</label><select id="gf" name="subject_id" data-new><?= fach_opts($uid, $edit['subject_id'] ?? null) ?></select></div>
          <div class="f"><label for="gt2">oder frei</label><input id="gt2" name="fach_text" value="<?= h($edit['fach_text'] ?? '') ?>"></div>
          <div class="fg">
            <div class="f"><label for="ga">Art</label><select id="ga" name="art"><?= optm(['schulaufgabe'=>'Schulaufgabe',
              'kurzarbeit'=>'Kurzarbeit','test'=>'Test','muendlich'=>'Muendlich','projekt'=>'Projekt','referat'=>'Referat','ihk'=>'IHK'], $edit['art'] ?? 'schulaufgabe') ?></select></div>
            <div class="f"><label for="gs">Skala</label><select id="gs" name="skala"><?= optm(['note'=>'Note 1–6','punkte'=>'Punkte 0–15','ihk'=>'IHK 0–100'], $edit['skala'] ?? 'note') ?></select></div>
          </div>
          <div class="fg">
            <div class="f"><label for="gw">Wert</label><input id="gw" name="wert" required inputmode="decimal" value="<?= h($edit['wert'] ?? '') ?>"></div>
            <div class="f"><label for="gg">Gewicht</label><input id="gg" name="gewicht" inputmode="decimal" value="<?= h($edit['gewicht'] ?? '1') ?>"></div>
          </div>
          <div class="fg">
            <div class="f"><label for="gd">Datum</label><input id="gd" name="datum" type="date" value="<?= h($edit['datum'] ?? today()) ?>"></div>
            <div class="f"><label for="gh">Halbjahr</label><input id="gh" name="halbjahr" value="<?= h($edit['halbjahr'] ?? '') ?>"></div>
          </div>
          <div class="f"><label for="gti">Titel</label><input id="gti" name="titel" value="<?= h($edit['titel'] ?? '') ?>"></div>
          <div class="rw"><button class="p" type="submit">Speichern</button>
            <?php if ($edit): ?><a class="bt g" href="<?= url('noten') ?>">Neu</a><?php endif; ?></div>
        </form>
        <?php if ($edit): ?>
          <hr><form method="post" data-q="Loeschen?"><?= csrf_field() ?>
            <input type="hidden" name="a" value="del"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
            <button class="d s" type="submit">Loeschen</button></form>
        <?php endif; ?>
      </div></div>
    </div>
    <?php
    page('Noten', ob_get_clean(), []);
}

// --- Berichtsheft ----------------------------------------------------------
function bh_tabs(): array {
    $t = [];
    foreach (['woche' => 'Nachweis', 'alle' => 'Alle', 'plan' => 'Plan', 'routinen' => 'Routinen'] as $k => $l) {
        $t[$k] = [$l, url('berichtsheft', $k === 'woche' ? [] : ['t' => $k])];
    }
    return $t;
}

function p_berichtsheft(): void {
    $u = need_login(); $uid = (int)$u['id'];
    $art = get('art') ?: $u['bh_art'];
    if (!in_array($art, ['woche', 'monat'], true)) $art = 'woche';
    $per = get('periode') ?: periode_of(today(), $art);
    if (!periode_ok($per, $art)) $per = periode_of(today(), $art);
    $tab = get('t');

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($tab === 'routinen' || in_array(post('a'), ['log','unlog'], true))) {
        bh_routinen($u); return;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('a') === 'dok') {
        $rep = report_get($uid, $art, $per);
        bh_druck($u, $rep, report_sum((int)$rep['id'])); return;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $a = post('a');
        $pp = post('periode') ?: $per;
        if (!periode_ok($pp, $art)) $pp = $per;
        $rep = report_ensure($uid, $art, $pp);
        $rid = (int)$rep['id'];
        $zu  = $rep['status'] === 'fertig';
        if ($a === 'add' && !$zu) {
            $t = post('text');
            if ($t !== '') {
                ins('report_entries', ['report_id' => $rid, 'user_id' => $uid,
                    'datum' => isodate(post('datum')) ? post('datum') : $rep['von'],
                    'stunden' => max(0, (float)str_replace(',', '.', post('stunden', '0'))),
                    'category_id' => inull(postn('cat')) ?: kategorie_zu($t),
                    'lf_no' => inull(postn('lf_no')),
                    'ort' => in_array(post('ort'), ['betrieb','schule','ueba','urlaub','krank','feiertag'], true) ? post('ort') : 'betrieb',
                    'text' => $t]);
            }
        } elseif ($a === 'del' && !$zu) {
            del('report_entries', 'id = ? AND user_id = ?', [(int)post('eid', '0'), $uid]);
        } elseif ($a === 'kat' && !$zu) {
            upd('report_entries', ['category_id' => inull(postn('cat'))],
                'id = :id AND user_id = :u', ['id' => (int)post('eid', '0'), 'u' => $uid]);
        } elseif ($a === 'fill' && !$zu) {
            $n = report_fill($rep, $u);
            flash($n > 0 ? $n . ' Eintraege ergaenzt.' : 'Nichts Neues.', $n > 0 ? 'ok' : 'info');
        } elseif ($a === 'meta' && !$zu) {
            upd('reports', ['schule_text' => post('schule_text'), 'sonstiges' => post('sonstiges'),
                'abteilung' => mb_substr(post('abteilung'), 0, 80), 'updated_at' => date('Y-m-d H:i:s')],
                'id = :id AND user_id = :u', ['id' => $rid, 'u' => $uid]);
            flash('Gespeichert.');
        } elseif ($a === 'fertig') {
            if ((int)val("SELECT COUNT(*) FROM report_entries WHERE report_id = ?", [$rid], 0) === 0) {
                flash('Keine Eintraege.', 'warn');
            } else {
                upd('reports', ['status' => 'fertig', 'fertig_am' => date('Y-m-d H:i:s')], 'id = :id AND user_id = :u', ['id' => $rid, 'u' => $uid]);
            }
        } elseif ($a === 'offen') {
            upd('reports', ['status' => 'offen', 'fertig_am' => null], 'id = :id AND user_id = :u', ['id' => $rid, 'u' => $uid]);
        }
        redirect(url('berichtsheft', ['periode' => $rep['periode'], 'art' => $art]));
    }

    if ($tab === 'alle')     { bh_liste($u); return; }
    if ($tab === 'plan')     { bh_ausbildungsplan($u); return; }
    if ($tab === 'routinen') { bh_routinen($u); return; }

    $rep = report_get($uid, $art, $per);
    $s = report_sum((int)$rep['id']);
    $zu = $rep['status'] === 'fertig';
    if (get('druck') !== '') { bh_druck($u, $rep, $s); return; }
    $tage = [];
    $d = new DateTimeImmutable($rep['von']); $e = new DateTimeImmutable($rep['bis']);
    while ($d <= $e) { $tage[$d->format('Y-m-d')] = $d; $d = $d->modify('+1 day'); }

    ob_start(); ?>
    <div class="c np"><div class="bo" style="padding:7px 10px">
      <div class="rw" style="justify-content:space-between">
        <div class="rw">
          <a class="bt s g" href="<?= url('berichtsheft', ['periode' => periode_shift($per, $art, -1), 'art' => $art]) ?>" title="zurueck">&larr;</a>
          <b style="min-width:180px;text-align:center"><?= h(periode_label($per, $art)) ?></b>
          <a class="bt s g" href="<?= url('berichtsheft', ['periode' => periode_shift($per, $art, 1), 'art' => $art]) ?>" title="weiter">&rarr;</a>
          <a class="bt s g" href="<?= url('berichtsheft', ['art' => $art]) ?>">heute</a>
          <span class="tg <?= $zu ? 'o' : 'w' ?>"><?= $zu ? 'fertig' : 'offen' ?></span>
          <span class="sm mu mo">Nr. <?= (int)$rep['nr'] ?></span>
        </div>
        <div class="seg">
          <a class="<?= $art === 'woche' ? 'on' : '' ?>" href="<?= url('berichtsheft', ['art' => 'woche']) ?>">Woche</a>
          <a class="<?= $art === 'monat' ? 'on' : '' ?>" href="<?= url('berichtsheft', ['art' => 'monat']) ?>">Monat</a>
        </div>
      </div>
    </div></div>

    <div class="sp2">
      <div>
        <div class="c">
          <div class="hd"><h2>Taetigkeiten</h2><span class="sp"></span>
            <?php if (!$zu): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="fill">
              <input type="hidden" name="periode" value="<?= h($per) ?>">
              <button class="s" type="submit">Aus <?= val("SELECT 1 FROM routines WHERE user_id = ?", [$uid]) ? 'Routinen &amp; Notizen' : 'Notizen' ?> fuellen</button></form>
            <?php endif; ?>
          </div>
          <?php if (!$s['rows']): ?><?= em('Noch nichts eingetragen.') ?><?php else: ?>
          <div class="tw"><table class="bhz"><thead><tr><th>Tag</th><th class="n">Std</th><th>Ort</th><th>Taetigkeit</th><th>Zuordnung</th><th></th></tr></thead><tbody>
            <?php foreach ($tage as $ds => $dd):
              $ee = $s['tag'][$ds] ?? [];
              if (!$ee && (int)$dd->format('N') > 5) continue;
              if (!$ee): ?>
                <tr><td class="mo sm" style="white-space:nowrap"><?= h(dt($ds, 'D d.m.')) ?></td>
                  <td colspan="5" class="mu2 sm">–</td></tr>
              <?php else: foreach ($ee as $i => $r): ?>
                <tr>
                  <td class="mo sm" style="white-space:nowrap"><?= $i === 0 ? h(dt($ds, 'D d.m.')) : '' ?></td>
                  <td class="n"><?= $r['stunden'] > 0 ? num((float)$r['stunden'], 2) : '' ?></td>
                  <td><span class="tg<?= in_array($r['ort'], ['krank','urlaub'], true) ? ' w' : ($r['ort'] === 'schule' ? ' a' : '') ?>"><?= h($r['ort']) ?></span></td>
                  <td><?= h($r['text']) ?><?= $r['quelle'] !== 'manuell' ? ' <span class="sm mu2">' . h($r['quelle']) . '</span>' : '' ?></td>
                  <td style="width:190px">
                    <?php if ($zu): ?><span class="sm"><?= h(($r['pos_no'] ? $r['pos_no'] . ' ' : '') . ($r['kategorie'] ?: '–')) ?></span>
                    <?php else: ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="kat">
                      <input type="hidden" name="periode" value="<?= h($per) ?>">
                      <input type="hidden" name="eid" value="<?= (int)$r['id'] ?>">
                      <select name="cat" data-autosubmit><?= kat_opts($r['category_id'], 'ohne') ?></select>
                      <noscript><button class="s" type="submit">ok</button></noscript></form>
                    <?php endif; ?>
                  </td>
                  <td style="width:30px"><?php if (!$zu): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="del">
                      <input type="hidden" name="periode" value="<?= h($per) ?>">
                      <input type="hidden" name="eid" value="<?= (int)$r['id'] ?>">
                      <button class="g s d" type="submit">&times;</button></form><?php endif; ?></td>
                </tr>
              <?php endforeach; endif; ?>
            <?php endforeach; ?>
          </tbody></table></div>
          <?php endif; ?>
          <?php if (!$zu): ?>
          <div class="bo" style="border-top:1px solid var(--li)">
            <form method="post">
              <?= csrf_field() ?><input type="hidden" name="a" value="add">
              <input type="hidden" name="periode" value="<?= h($per) ?>">
              <div class="line">
                <input type="date" name="datum" min="<?= h($rep['von']) ?>" max="<?= h($rep['bis']) ?>"
                       value="<?= h(max($rep['von'], min($rep['bis'], today()))) ?>" style="width:140px;flex:none">
                <input name="stunden" placeholder="Std" style="width:64px;flex:none" inputmode="decimal">
                <select name="ort" style="width:104px;flex:none"><?= optm(['betrieb'=>'Betrieb','schule'=>'Schule',
                  'ueba'=>'UEBA','urlaub'=>'Urlaub','krank'=>'Krank','feiertag'=>'Feiertag'], 'betrieb') ?></select>
                <input name="text" required placeholder="Taetigkeit" style="flex:1;min-width:0" data-new>
                <select name="cat" style="width:130px;flex:none"><?= kat_opts(null) ?></select>
                <button class="p" type="submit" style="flex:none">+</button>
              </div>
            </form>
          </div>
          <?php endif; ?>
        </div>

        <div class="c"><div class="hd"><h2>Berufsschule &amp; Sonstiges</h2></div><div class="bo">
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="a" value="meta">
            <input type="hidden" name="periode" value="<?= h($per) ?>">
            <div class="f"><label for="ab">Abteilung</label><input id="ab" name="abteilung" value="<?= h($rep['abteilung']) ?>"<?= $zu ? ' disabled' : '' ?>></div>
            <div class="f"><label for="sx">Themen im Unterricht</label>
              <textarea id="sx" name="schule_text"<?= $zu ? ' disabled' : '' ?> data-d="bh<?= (int)$rep['id'] ?>"><?= h($rep['schule_text']) ?></textarea></div>
            <div class="f"><label for="so">Sonstiges</label>
              <textarea id="so" name="sonstiges" style="min-height:50px"<?= $zu ? ' disabled' : '' ?>><?= h($rep['sonstiges']) ?></textarea></div>
            <?php if (!$zu): ?><button type="submit">Speichern</button><?php endif; ?>
          </form>
        </div></div>
      </div>

      <div>
        <div class="c"><div class="hd"><h2>Verteilung</h2></div><div class="bo">
          <?= bars(array_map(fn($c) => [$c['name'], $c['std'], $c['farbe']], array_values($s['kat']))) ?>
        </div></div>
        <?php if ($s['rows']): ?>
        <div class="c"><div class="hd"><h2>Zusammenfassung</h2><span class="sp"></span>
          <button class="s" data-copy="bt" type="button">Kopieren</button></div><div class="bo">
          <pre id="bt" style="white-space:pre-wrap;max-height:220px;overflow:auto;font-size:11.5px"><?= h(report_text((int)$rep['id'])) ?></pre>
        </div></div>
        <?php endif; ?>
        <div class="c"><div class="hd"><h2>Teilen</h2></div><div class="bo">
          <?= share_box($uid, 'bericht', (int)$rep['id'],
              'Nachweis ' . periode_label($rep['periode'], $rep['art']),
              url('berichtsheft', ['periode' => $per, 'art' => $art])) ?>
        </div></div>
        <div class="c"><div class="bo">
          <?php if (!$zu): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="fertig">
              <input type="hidden" name="periode" value="<?= h($per) ?>">
              <button class="p" type="submit" style="width:100%;justify-content:center">Als fertig markieren</button></form>
          <?php else: ?>
            <div class="rw" style="justify-content:space-between">
              <span class="sm mu">fertig <?= h(dt($rep['fertig_am'], 'd.m.Y')) ?></span>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="offen">
                <input type="hidden" name="periode" value="<?= h($per) ?>">
                <button class="s" type="submit">Wieder oeffnen</button></form>
            </div>
          <?php endif; ?>
        </div></div>
      </div>
    </div>
    <?php
    page('Berichtsheft', ob_get_clean(), ['tabs' => bh_tabs(), 'aktiv' => 'woche',
        'aktion' => '<a class="bt s g" href="' . h(url('berichtsheft', ['periode' => $per, 'art' => $art, 'druck' => 1])) . '">Drucken</a>']);
}

function bh_liste(array $u): void {
    $uid = (int)$u['id'];
    $rows = all("SELECT r.*, (SELECT COUNT(*) FROM report_entries e WHERE e.report_id = r.id) AS anz,
                 (SELECT COALESCE(SUM(stunden),0) FROM report_entries e WHERE e.report_id = r.id) AS std
                 FROM reports r WHERE r.user_id = ?
                 AND (r.status <> 'offen' OR EXISTS (SELECT 1 FROM report_entries e WHERE e.report_id = r.id)
                      OR r.schule_text <> '') ORDER BY r.von DESC", [$uid]);
    ob_start(); ?>
    <div class="c">
      <div class="hd"><h2>Alle Nachweise</h2><span class="sp"></span>
        <a class="bt s g" href="<?= url('berichtsheft') ?>">Aktuelle Woche</a>
        <a class="bt s g" href="<?= url('export', ['w' => 'bh']) ?>">CSV</a></div>
      <?php if (!$rows): ?><?= em('Noch keine Nachweise.') ?><?php else: ?>
      <ul class="li rows">
        <?php foreach ($rows as $r):
          $neben = ['Nr. ' . report_nr($uid, $r['von']), num((float)$r['std'], 1) . ' h']; ?>
          <li>
            <span class="tile t-bericht"><?= ic('bericht', 17) ?></span>
            <span class="tx"><b><a href="<?= url('berichtsheft', ['periode' => $r['periode'], 'art' => $r['art']]) ?>"><?= h(periode_label($r['periode'], $r['art'])) ?></a></b>
              <span class="sm mu2"><?= h(implode(' · ', $neben)) ?></span></span>
            <span class="tg <?= $r['status'] === 'fertig' ? 'o' : 'w' ?>"><?= $r['status'] === 'fertig' ? 'fertig' : 'offen' ?></span>
            <a class="bt s g" style="flex:none" href="<?= url('berichtsheft', ['periode' => $r['periode'], 'art' => $r['art'], 'druck' => 1]) ?>">Druck</a>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
    <?php
    page('Alle Nachweise', ob_get_clean(), ['tabs' => bh_tabs(), 'aktiv' => 'alle']);
}

/**
 * Vollstaendiger Name steht nicht im Konto. Vor einem Ausdruck, der ihn braucht,
 * wird er einmal abgefragt - auf Wunsch gespeichert, sonst nur fuer diese Sitzung.
 */
/**
 * Fragt vor einem Ausdruck nur das ab, was fehlt, und behaelt es danach.
 * Der volle Name bleibt freiwillig: ohne Haken lebt er nur in dieser Sitzung.
 */
function angaben_holen(array $u, string $zurueck, string $weiter): ?array {
    $uid = (int)$u['id'];
    $erzwingen = get('dok') !== '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('a') === 'dok') {
        csrf_check();
        $name = mb_substr(post('dok_name'), 0, 80);
        if ($name === '') { flash('Name fehlt.', 'err'); redirect($weiter . '&dok=1'); }
        // Was die Maske gerade verlangt, muss auch ankommen - sonst kaeme sie stumm wieder
        if ((string)$u['betrieb'] === '' && post('betrieb') === '') { flash('Betrieb fehlt.', 'err'); redirect($weiter . '&dok=1'); }
        if (empty($u['start']) && !isodate(post('start'))) { flash('Ausbildungsbeginn fehlt.', 'err'); redirect($weiter . '&dok=1'); }
        $d = ['name' => $name, 'geb' => isodate(post('dok_geb')) ? post('dok_geb') : ''];
        $_SESSION['dok'] = $d;
        if (post('merken') !== '') {
            upd('users', ['dok_name' => $d['name'], 'dok_geb' => $d['geb'], 'dok_merken' => 1], 'id = :id', ['id' => $uid]);
        } elseif ((int)$u['dok_merken'] === 1) {
            upd('users', ['dok_name' => '', 'dok_geb' => '', 'dok_merken' => 0], 'id = :id', ['id' => $uid]);
        }
        // Betrieb und Beruf stehen auf jedem Nachweis und sind keine Personenangaben
        $rest = [];
        if ((string)$u['betrieb'] === '' && post('betrieb') !== '') $rest['betrieb'] = mb_substr(post('betrieb'), 0, 120);
        if ((string)$u['beruf'] === '' && post('beruf') !== '') $rest['beruf'] = mb_substr(post('beruf'), 0, 100);
        if (empty($u['start']) && isodate(post('start'))) $rest['start'] = post('start');
        if ($rest) {
            upd('users', $rest, 'id = :id', ['id' => $uid]);
            if (isset($rest['start'])) {
                $n = reports_jahr_nachziehen(one("SELECT * FROM users WHERE id = ?", [$uid]));
                if ($n) flash($n . ' Nachweise nachgerechnet.');
            }
        }
        redirect($weiter);
    }
    $name = '';
    if (!empty($_SESSION['dok']['name'])) $name = (string)$_SESSION['dok']['name'];
    elseif ((int)$u['dok_merken'] === 1 && $u['dok_name'] !== '') $name = (string)$u['dok_name'];
    $fehlt = $name === '' || (string)$u['betrieb'] === '' || (string)$u['beruf'] === '' || empty($u['start']);
    if (!$fehlt && !$erzwingen) {
        return ['name' => $name, 'geb' => (string)($_SESSION['dok']['geb'] ?? $u['dok_geb'])];
    }
    $vor = $name !== '' ? $name : (string)$u['dok_name'];
    $stand = ausbildungsstand($u);
    ob_start(); ?>
    <div class="c" style="max-width:440px"><div class="bo">
      <h2>Angaben fuer den Ausdruck</h2>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="a" value="dok">
        <div class="f"><label for="dkn">Vor- und Nachname</label>
          <input id="dkn" name="dok_name" required autofocus value="<?= h($vor) ?>"></div>
        <div class="f"><label for="dkg">Geburtsdatum <span class="mu sm">optional</span></label>
          <input id="dkg" name="dok_geb" type="date" value="<?= h($_SESSION['dok']['geb'] ?? $u['dok_geb']) ?>"></div>
        <label class="ck"><input type="checkbox" name="merken" value="1"<?= (int)$u['dok_merken'] || $vor === '' ? ' checked' : '' ?>>
          im Konto merken</label>
        <?php if ((string)$u['betrieb'] === '' || (string)$u['beruf'] === '' || empty($u['start'])): ?>
          <hr>
          <?php if ((string)$u['betrieb'] === ''): ?>
            <div class="f"><label for="dkb">Betrieb</label><input id="dkb" name="betrieb" required></div>
          <?php endif; ?>
          <?php if ((string)$u['beruf'] === ''): ?>
            <div class="f"><label for="dkr">Beruf</label><input id="dkr" name="beruf" value="Fachinformatiker/-in Systemintegration"></div>
          <?php endif; ?>
          <?php if (empty($u['start'])): ?>
            <div class="f"><label for="dks">Ausbildungsbeginn</label>
              <input id="dks" name="start" type="date" required value="<?= h($stand['beginn'] ?? '') ?>">
              <?php if (($stand['beginn'] ?? '') !== ''): ?><div class="sm mu2" style="margin-top:4px">aus der Klasse abgeleitet, bitte pruefen</div><?php endif; ?></div>
          <?php endif; ?>
          <div class="sm mu2" style="margin-bottom:9px">Wird im Konto behalten und nicht erneut gefragt.</div>
        <?php endif; ?>
        <div class="rw" style="margin-top:12px">
          <button class="p" type="submit">Weiter</button>
          <a class="bt g" href="<?= h($zurueck) ?>">Abbrechen</a>
        </div>
      </form>
    </div></div>
    <?php
    page('Angaben', ob_get_clean());
    return null;
}

function bh_druck(array $u, array $rep, array $s): void {
    $zurueck = url('berichtsheft', ['periode' => $rep['periode'], 'art' => $rep['art']]);
    $weiter  = url('berichtsheft', ['periode' => $rep['periode'], 'art' => $rep['art'], 'druck' => 1]);
    $dok = angaben_holen($u, $zurueck, $weiter);
    if ($dok === null) return;
    $u = one("SELECT * FROM users WHERE id = ?", [(int)$u['id']]) ?? $u;
    $betrieb = array_filter($s['rows'], fn($r) => $r['ort'] !== 'schule');
    $schule  = array_filter($s['rows'], fn($r) => $r['ort'] === 'schule');
    $sb = array_sum(array_map(fn($r) => (float)$r['stunden'], $betrieb));
    $ss = array_sum(array_map(fn($r) => (float)$r['stunden'], $schule));
    ob_start(); ?>
    <div class="rw np" style="justify-content:flex-end;margin-bottom:10px">
      <button class="p s" type="button" data-print><?= ic('datei', 15) ?> Drucken</button>
      <a class="bt s g" href="<?= h($weiter) ?>&amp;dok=1">Angaben aendern</a>
      <a class="bt s g" href="<?= h($zurueck) ?>">&larr; zurueck</a></div>
    <div class="c"><div class="bo">
      <h1 style="font-size:19px;margin-bottom:2px">Ausbildungsnachweis Nr. <?= (int)$rep['nr'] ?></h1>
      <p class="mu sm"><?= $rep['art'] === 'monat' ? 'Monatsnachweis' : 'Wochennachweis' ?> ·
        <?= h(periode_label($rep['periode'], $rep['art'])) ?></p>
      <div class="tw"><table style="margin-bottom:12px">
        <tr><th style="width:20%">Auszubildende/-r</th><td><?= h($dok['name']) ?><?= $dok['geb'] ? ' <span class="sm mu">* ' . h(dt($dok['geb'])) . '</span>' : '' ?></td>
            <th style="width:16%">Ausbildungsjahr</th><td><?= (int)$rep['jahr'] ?></td></tr>
        <tr><th>Beruf</th><td><?= h($u['beruf']) ?></td>
            <th>Zeitraum</th><td><?= h(dt($rep['von'])) ?> – <?= h(dt($rep['bis'])) ?></td></tr>
        <tr><th>Betrieb</th><td><?= h($u['betrieb']) ?></td>
            <th>Abteilung</th><td><?= h($rep['abteilung']) ?></td></tr>
      </table></div>
      <h3>Betriebliche Taetigkeiten</h3>
      <div class="tw"><table><thead><tr><th style="width:13%">Tag</th><th style="width:8%" class="n">Std</th><th>Taetigkeit</th>
        <th style="width:27%">Ausbildungsinhalt</th></tr></thead><tbody>
        <?php foreach ($betrieb as $r): ?>
          <tr><td><?= h(dt($r['datum'], 'D d.m.')) ?></td><td class="n"><?= $r['stunden'] > 0 ? num((float)$r['stunden'], 2) : '' ?></td>
            <td><?= h($r['text']) ?></td>
            <td class="sm"><?= h(($r['pos_no'] ? $r['pos_no'] . '  ' : '') . ($r['kategorie'] ?: '')) ?></td></tr>
        <?php endforeach; ?>
        <tr><th>Summe</th><th class="n"><?= num($sb, 2) ?></th>
            <th colspan="2" style="font-weight:400">zzgl. <?= num($ss, 2) ?> h Berufsschule</th></tr>
      </tbody></table></div>
      <h3 style="margin-top:12px">Berufsschule</h3>
      <?php if ($rep['schule_text'] !== ''): ?><p><?= nl2br(h($rep['schule_text'])) ?></p><?php endif; ?>
      <?php if ($schule): ?><div class="tw"><table><tbody>
        <?php foreach ($schule as $r): ?><tr><td style="width:13%"><?= h(dt($r['datum'], 'D d.m.')) ?></td><td><?= h($r['text']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
      <?php if ($rep['sonstiges'] !== ''): ?><h3 style="margin-top:12px">Sonstiges</h3><p><?= nl2br(h($rep['sonstiges'])) ?></p><?php endif; ?>
      <table style="margin-top:34px;border-top:1px solid #999">
        <tr><td style="padding-top:30px;border:0">______________________________<br>
            <span class="sm">Datum, Unterschrift Auszubildende/-r</span></td>
          <td style="padding-top:30px;border:0">______________________________<br>
            <span class="sm">Datum, Unterschrift Ausbilder/-in</span></td></tr>
      </table>
    </div></div>
    <?php
    page('Ausbildungsnachweis', ob_get_clean());
}

// --- Routinen --------------------------------------------------------------
function bh_routinen(array $u): void {
    $uid = (int)$u['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $a = post('a'); $id = (int)post('id', '0');
        if ($a === 'log' && $id) {
            $r = one("SELECT * FROM routines WHERE id = ? AND user_id = ?", [$id, $uid]);
            if ($r) {
                ins('routine_logs', ['routine_id' => $id, 'user_id' => $uid,
                    'datum' => isodate(post('datum')) ? post('datum') : today(),
                    'zeit' => post('zeit') ?: date('H:i'),
                    'minuten' => max(0, (int)post('minuten', (string)(int)$r['minuten'])),
                    'notiz' => mb_substr(post('notiz'), 0, 200)]);
                // Wer eine vorangelegte Routine benutzt, hat sie uebernommen
                if ($r['herkunft'] === 'beispiel') upd('routines', ['herkunft' => 'eingabe'], 'id = :id', ['id' => $id]);
            }
        } elseif ($a === 'unlog') {
            del('routine_logs', 'id = ? AND user_id = ?', [(int)post('lid', '0'), $uid]);
        } elseif ($a === 'save') {
            $d = ['name' => mb_substr(post('name'), 0, 100),
                'intervall' => in_array(post('intervall'), ['taeglich','woechentlich','monatlich','bedarf'], true) ? post('intervall') : 'bedarf',
                'category_id' => inull(postn('cat')), 'minuten' => max(0, (int)post('minuten', '10')),
                'bh' => post('bh') === '0' ? 0 : 1, 'aktiv' => post('aktiv') === '0' ? 0 : 1,
                'herkunft' => 'eingabe'];
            if ($d['name'] === '') flash('Name fehlt.', 'err');
            elseif ($id) { upd('routines', $d, 'id = :id AND user_id = :u', ['id' => $id, 'u' => $uid]); flash('Gespeichert.'); }
            else { $d['user_id'] = $uid; $d['sort'] = 500; ins('routines', $d); flash('Angelegt.'); }
        } elseif ($a === 'del' && $id) { del('routines', 'id = ? AND user_id = ?', [$id, $uid]); flash('Geloescht.'); }
        elseif ($a === 'beispiel_weg') {
            $n = del('routines', "user_id = ? AND herkunft = 'beispiel'
                     AND id NOT IN (SELECT routine_id FROM routine_logs)", [$uid]);
            flash($n . ' entfernt.');
        }
        redirect(zurueck(post('back'), url('berichtsheft', ['t' => 'routinen'])));
    }
    $mo = date('Y-m-d', strtotime('monday this week'));
    $rt = all("SELECT r.*, c.name AS kat, (SELECT MAX(datum) FROM routine_logs l WHERE l.routine_id = r.id) AS letzte,
               (SELECT COUNT(*) FROM routine_logs l WHERE l.routine_id = r.id) AS anz
               FROM routines r LEFT JOIN categories c ON c.id = r.category_id
               WHERE r.user_id = ? ORDER BY r.aktiv DESC, r.sort, r.name", [$uid]);
    $logs = all("SELECT l.*, r.name FROM routine_logs l JOIN routines r ON r.id = l.routine_id
                 WHERE l.user_id = ? ORDER BY l.datum DESC, l.id DESC LIMIT 120", [$uid]);
    $edit = get('id') !== '' ? one("SELECT * FROM routines WHERE id = ? AND user_id = ?", [(int)get('id'), $uid]) : (get('neu') !== '' ? ['id' => 0] : null);
    ob_start(); ?>
    <?= quick($u, $rt ? 'routine' : 'bericht', true) ?>
    <div class="sp2">
      <div>
        <div class="c">
          <div class="hd"><h2>Routinen</h2><span class="sp"></span>
            <a class="bt p s" data-new href="<?= url('berichtsheft', ['t' => 'routinen', 'neu' => 1]) ?>">Neu <kbd>n</kbd></a></div>
          <?php $bsp = count(array_filter($rt, fn($r) => $r['herkunft'] === 'beispiel'));
          if ($bsp): ?>
            <div class="bo rw" style="padding:8px 14px">
              <span class="sm mu2"><?= $bsp ?> aus einer frueheren Fassung vorangelegt</span><span class="sp"></span>
              <form method="post" data-q="Alle Beispielroutinen entfernen?"><?= csrf_field() ?>
                <input type="hidden" name="a" value="beispiel_weg">
                <button class="g s d" type="submit">Entfernen</button></form>
            </div>
          <?php endif; ?>
          <?php if (!$rt): ?><?= em('Noch keine Routine. Was regelmaessig anfaellt, traegst du hier ein.') ?><?php else: ?>
          <ul class="li rows">
            <?php foreach ($rt as $r):
              $f = match ($r['intervall']) {
                  'taeglich' => $r['letzte'] !== today(),
                  'woechentlich' => !$r['letzte'] || $r['letzte'] < $mo,
                  'monatlich' => !$r['letzte'] || substr((string)$r['letzte'], 0, 7) !== date('Y-m'),
                  default => false };
              $neben = array_filter([$r['intervall'],
                  $r['letzte'] ? 'zuletzt ' . dt($r['letzte'], 'd.m.y') : 'nie',
                  (int)$r['anz'] . '&times;', $r['kat'] ?: '']); ?>
              <li<?= (int)$r['aktiv'] ? '' : ' style="opacity:.45"' ?>>
                <form method="post" style="flex:none"><?= csrf_field() ?><input type="hidden" name="a" value="log">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
                  <button class="<?= $f ? 'p' : 'g' ?> s" type="submit" title="erledigt">&check;</button></form>
                <span class="tx"><b><a href="<?= url('berichtsheft', ['t' => 'routinen', 'id' => $r['id']]) ?>"><?= h($r['name']) ?></a></b>
                  <span class="sm mu2"><?= implode(' · ', $neben) ?></span></span>
                <?= $r['herkunft'] === 'beispiel' ? '<span class="tg">Beispiel</span>' : '' ?>
                <?= $f ? '<span class="tg w">offen</span>' : '' ?>
              </li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </div>
        <div class="c">
          <div class="hd"><h2>Protokoll</h2><span class="sp"></span>
            <input placeholder="Filtern" data-fl="#lg" style="width:130px">
            <a class="bt s g" href="<?= url('export', ['w' => 'routinen']) ?>">CSV</a></div>
          <?php if (!$logs): ?><?= em('Noch nichts protokolliert.') ?><?php else: ?>
          <div class="tw"><table id="lg" class="stk"><tbody>
            <?php foreach ($logs as $l): ?>
              <tr><td class="mo sm" style="width:96px;white-space:nowrap"><?= h(dt($l['datum'], 'D d.m.y')) ?></td>
                <td class="mo sm mu2" style="width:44px"><?= h($l['zeit']) ?></td>
                <td><?= h($l['name']) ?><?= $l['notiz'] ? ' <span class="sm mu2">' . h($l['notiz']) . '</span>' : '' ?></td>
                <td class="n sm" style="width:56px"><?= (int)$l['minuten'] ?> min</td>
                <td style="width:30px"><form method="post"><?= csrf_field() ?>
                  <input type="hidden" name="a" value="unlog"><input type="hidden" name="lid" value="<?= (int)$l['id'] ?>">
                  <button class="g s d" type="submit">&times;</button></form></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <?php endif; ?>
        </div>
      </div>
      <div>
        <?php if ($edit !== null): $neu = empty($edit['id']); ?>
        <div class="c"><div class="hd"><h2><?= $neu ? 'Neue Routine' : 'Bearbeiten' ?></h2></div><div class="bo">
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="a" value="save">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="f"><label for="rn">Name</label><input id="rn" name="name" required value="<?= h($edit['name'] ?? '') ?>"<?= $neu ? ' autofocus' : '' ?>></div>
            <div class="fg">
              <div class="f"><label for="ri">Rhythmus</label><select id="ri" name="intervall"><?= optm(
                ['taeglich'=>'taeglich','woechentlich'=>'woechentlich','monatlich'=>'monatlich','bedarf'=>'bei Bedarf'],
                $edit['intervall'] ?? 'taeglich') ?></select></div>
              <div class="f"><label for="rm">Minuten</label><input id="rm" name="minuten" type="number" min="0" value="<?= (int)($edit['minuten'] ?? 10) ?>"></div>
            </div>
            <div class="f"><label for="rc">Zuordnung</label><select id="rc" name="cat"><?= kat_opts($edit['category_id'] ?? null, 'automatisch') ?></select></div>
            <div class="fg">
              <div class="f"><label for="rb">Ins Berichtsheft</label><select id="rb" name="bh"><?= optm([1=>'ja',0=>'nein'], $edit['bh'] ?? 1) ?></select></div>
              <div class="f"><label for="ra2">Aktiv</label><select id="ra2" name="aktiv"><?= optm([1=>'ja',0=>'nein'], $edit['aktiv'] ?? 1) ?></select></div>
            </div>
            <div class="rw"><button class="p" type="submit">Speichern</button>
              <a class="bt g" href="<?= url('berichtsheft', ['t' => 'routinen']) ?>">Schliessen</a></div>
          </form>
          <?php if (!$neu): ?>
            <hr><form method="post" data-q="Routine und Protokoll loeschen?"><?= csrf_field() ?>
              <input type="hidden" name="a" value="del"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
              <button class="d s" type="submit">Loeschen</button></form>
          <?php endif; ?>
        </div></div>
        <?php elseif (array_filter($rt, fn($r) => (int)$r['aktiv'] === 1)): ?>
        <div class="c"><div class="hd"><h2>Nachtragen</h2></div><div class="bo">
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="a" value="log">
            <div class="f"><label for="lr">Routine</label><select id="lr" name="id" required>
              <?= opts(array_filter($rt, fn($r) => (int)$r['aktiv'] === 1), null, '–') ?></select></div>
            <div class="fg">
              <div class="f"><label for="ld">Datum</label><input id="ld" name="datum" type="date" value="<?= h(today()) ?>"></div>
              <div class="f"><label for="lz">Zeit</label><input id="lz" name="zeit" type="time" value="<?= h(date('H:i')) ?>"></div>
              <div class="f"><label for="lm">Min</label><input id="lm" name="minuten" type="number" min="0" value="10"></div>
            </div>
            <div class="f"><label for="ln">Notiz</label><input id="ln" name="notiz"></div>
            <button class="p" type="submit">Eintragen</button>
          </form>
        </div></div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    page('Routinen', ob_get_clean(), ['tabs' => bh_tabs(), 'aktiv' => 'routinen']);
}

/**
 * Ausbildungsplan: welche Berufsbildposition der FIAusbV schon durch Nachweise
 * belegt ist - und welche noch offen steht.
 */
function bh_ausbildungsplan(array $u): void {
    $uid = (int)$u['id'];
    $cats = all("SELECT * FROM categories ORDER BY abschnitt, sort, id");
    $zahlen = [];
    foreach (all("SELECT category_id, COUNT(*) AS n, SUM(stunden) AS std, MAX(datum) AS letzt
                  FROM report_entries WHERE user_id = ? AND category_id IS NOT NULL
                  GROUP BY category_id", [$uid]) as $r) {
        $zahlen[(int)$r['category_id']] = $r;
    }
    $ohne = (int)val("SELECT COUNT(*) FROM report_entries WHERE user_id = ? AND category_id IS NULL", [$uid], 0);
    $abschnitt = ['A' => 'Abschnitt A – berufsprofilgebende Fertigkeiten',
                  'C' => 'Abschnitt C – Fachrichtung Systemintegration',
                  'B' => 'Abschnitt B – integrative Inhalte',
                  'X' => 'Sonstiges'];
    $grp = [];
    foreach ($cats as $c) $grp[$c['abschnitt']][] = $c;
    // X-Positionen sind Organisatorisches und zaehlen nicht zum Ausbildungsplan
    $plan = array_values(array_filter($cats, fn($c) => $c['abschnitt'] !== 'X'));
    $belegt = count(array_filter($plan, fn($c) => isset($zahlen[(int)$c['id']])));
    $maxStd = max(1.0, max(array_map(fn($r) => (float)$r['std'], $zahlen ?: [['std' => 1]])));

    $lf = all("SELECT l.nr, l.code, l.titel, l.jahr,
                 (SELECT COUNT(*) FROM notes n WHERE n.user_id = ? AND n.lf_no = l.nr) AS notizen,
                 (SELECT COUNT(*) FROM report_entries e WHERE e.user_id = ? AND e.lf_no = l.nr) AS eintraege
               FROM lernfelder l ORDER BY l.nr", [$uid, $uid]);
    ob_start(); ?>
    <div class="g g3" style="margin-bottom:14px">
      <div class="c"><div class="bo"><div class="lb">Positionen belegt</div>
        <div style="font-size:26px;font-weight:640;letter-spacing:-.03em"><?= $belegt ?>
          <span class="sm mu2" style="font-weight:400">von <?= count($plan) ?></span></div>
        <div class="br" style="margin-top:8px"><i style="width:<?= round($belegt / max(1, count($plan)) * 100) ?>%"></i></div>
      </div></div>
      <div class="c"><div class="bo"><div class="lb">Ohne Zuordnung</div>
        <div style="font-size:26px;font-weight:640;letter-spacing:-.03em"><?= $ohne ?>
          <span class="sm mu2" style="font-weight:400">Eintraege</span></div></div></div>
      <div class="c"><div class="bo"><div class="lb">Lernfelder mit Material</div>
        <div style="font-size:26px;font-weight:640;letter-spacing:-.03em">
          <?= count(array_filter($lf, fn($l) => (int)$l['notizen'] + (int)$l['eintraege'] > 0)) ?>
          <span class="sm mu2" style="font-weight:400">von <?= count($lf) ?></span></div></div></div>
    </div>

    <?php foreach ($abschnitt as $ab => $lbl): if (empty($grp[$ab])) continue; ?>
      <div class="c"><div class="hd"><h2><?= h($lbl) ?></h2></div>
        <div class="tw"><table class="stk"><thead><tr><th style="width:56px">Pos.</th><th>Inhalt</th>
          <th class="n" style="width:64px">Eintr.</th><th class="n" style="width:64px">Std</th>
          <th style="width:96px">zuletzt</th><th style="width:120px"></th></tr></thead><tbody>
          <?php foreach ($grp[$ab] as $c): $z = $zahlen[(int)$c['id']] ?? null; ?>
            <tr<?= $z ? '' : ' style="opacity:.55"' ?>>
              <td class="mo sm" data-eck><?= h($c['pos_no']) ?></td>
              <td><?= h($c['name']) ?></td>
              <td class="n" data-l="Eintraege"><?= $z ? (int)$z['n'] : '' ?></td>
              <td class="n" data-l="Stunden"><?= $z && (float)$z['std'] > 0 ? num((float)$z['std'], 1) : '' ?></td>
              <td class="mo sm mu2" data-l="zuletzt"><?= $z ? h(dt($z['letzt'], 'd.m.y')) : '' ?></td>
              <td><div class="br"><i style="width:<?= $z ? max(4, round((float)$z['std'] / $maxStd * 100)) : 0 ?>%;background:<?= h($c['farbe']) ?>"></i></div></td>
            </tr>
          <?php endforeach; ?>
        </tbody></table></div>
      </div>
    <?php endforeach; ?>

    <div class="c"><div class="hd"><h2>Lernfelder</h2><span class="sp"></span>
      <span class="sm mu2">Notizen und Nachweise je Lernfeld</span></div>
      <div class="tw"><table class="stk"><thead><tr><th style="width:78px">LF</th><th>Titel</th>
        <th style="width:56px">Jahr</th><th class="n" style="width:74px">Notizen</th>
        <th class="n" style="width:74px">Nachweis</th></tr></thead><tbody>
        <?php foreach ($lf as $l): $leer = (int)$l['notizen'] + (int)$l['eintraege'] === 0; ?>
          <tr<?= $leer ? ' style="opacity:.55"' : '' ?>>
            <td class="mo sm" style="white-space:nowrap" data-eck><a href="<?= url('notizen', ['lf' => $l['nr']]) ?>"><?= h($l['code']) ?></a></td>
            <td><?= h($l['titel']) ?></td>
            <td class="sm mu2" data-l="Jahr"><?= (int)$l['jahr'] ?>.</td>
            <td class="n" data-l="Notizen"><?= (int)$l['notizen'] ?: '' ?></td>
            <td class="n" data-l="Nachweise"><?= (int)$l['eintraege'] ?: '' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody></table></div>
    </div>
    <?php
    page('Ausbildungsplan', ob_get_clean(), ['tabs' => bh_tabs(), 'aktiv' => 'plan']);
}

// --- Einsaetze, Fehlzeiten, Urlaub -----------------------------------------
function einsatz_tabs(): array {
    return ['abteilungen' => ['Abteilungen', url('einsaetze')],
            'zeiten'      => ['Fehlzeiten & Urlaub', url('einsaetze', ['t' => 'zeiten'])]];
}
/** Arbeitstage eines Zeitraums (Mo-Fr). */
function werktage(string $von, string $bis): int {
    $d = new DateTimeImmutable($von); $e = new DateTimeImmutable($bis); $n = 0;
    while ($d <= $e) { if ((int)$d->format('N') <= 5) $n++; $d = $d->modify('+1 day'); }
    return $n;
}
function p_einsaetze(): void {
    $u = need_login(); $uid = (int)$u['id'];
    $t = get('t') === 'zeiten' ? 'zeiten' : 'abteilungen';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $a = post('a'); $id = (int)post('id', '0');
        if ($a === 'save') {
            $von = isodate(post('von')) ? post('von') : today();
            $bis = isodate(post('bis')) && post('bis') >= $von ? post('bis') : '';
            $d = ['abteilung' => mb_substr(post('abteilung'), 0, 80), 'von' => $von, 'bis' => $bis,
                'ansprech' => mb_substr(post('ansprech'), 0, 80),
                'schwerpunkt' => mb_substr(post('schwerpunkt'), 0, 200),
                'notiz' => mb_substr(post('notiz'), 0, 500)];
            if ($d['abteilung'] === '') flash('Abteilung fehlt.', 'err');
            elseif ($id) { upd('einsaetze', $d, 'id = :id AND user_id = :u', ['id' => $id, 'u' => $uid]); flash('Gespeichert.'); }
            else { $d['user_id'] = $uid; ins('einsaetze', $d); flash('Einsatz angelegt.'); }
        } elseif ($a === 'del') {
            del('einsaetze', 'id = ? AND user_id = ?', [$id, $uid]);
        } elseif ($a === 'jetzt') {
            $e = one("SELECT * FROM einsaetze WHERE id = ? AND user_id = ?", [$id, $uid]);
            if ($e) { upd('users', ['abteilung' => $e['abteilung']], 'id = :id', ['id' => $uid]); flash('Als aktuelle Abteilung gesetzt.'); }
        } elseif ($a === 'abw') {
            $von = isodate(post('von')) ? post('von') : today();
            $bis = isodate(post('bis')) && post('bis') >= $von ? post('bis') : $von;
            ins('absences', ['user_id' => $uid, 'von' => $von, 'bis' => $bis,
                'art' => in_array(post('art'), ['krank','urlaub','frei','dienstreise','schulung'], true) ? post('art') : 'krank',
                'grund' => mb_substr(post('grund'), 0, 200), 'schule' => post('schule') ? 1 : 0,
                'entschuldigt' => post('entschuldigt') ? 1 : 0, 'tage' => werktage($von, $bis)]);
            flash('Erfasst.');
        } elseif ($a === 'abwdel') {
            del('absences', 'id = ? AND user_id = ?', [$id, $uid]);
        } elseif ($a === 'urlaub') {
            upd('users', ['urlaub_tage' => max(0, min(60, (float)str_replace(',', '.', post('urlaub_tage', '0'))))],
                'id = :id', ['id' => $uid]);
            flash('Gespeichert.');
        }
        redirect(url('einsaetze', $t === 'zeiten' ? ['t' => 'zeiten'] : []));
    }

    if ($t === 'zeiten') {
        $rows = all("SELECT * FROM absences WHERE user_id = ? ORDER BY von DESC LIMIT 300", [$uid]);
        $jahr = date('Y');
        $tage = function (string $art) use ($rows, $jahr) {
            $n = 0;
            foreach ($rows as $r) if ($r['art'] === $art && substr($r['von'], 0, 4) === $jahr) $n += werktage($r['von'], $r['bis']);
            return $n;
        };
        $genommen = $tage('urlaub');
        $rest = max(0, (float)$u['urlaub_tage'] - $genommen);
        ob_start(); ?>
        <div class="g g3" style="margin-bottom:14px">
          <div class="c"><div class="bo"><div class="lb">Urlaub <?= h($jahr) ?></div>
            <div style="font-size:26px;font-weight:640;letter-spacing:-.03em"><?= num($rest, 0) ?>
              <span class="sm mu2" style="font-weight:400">von <?= num((float)$u['urlaub_tage'], 0) ?> offen</span></div>
            <div class="br" style="margin-top:8px"><i style="width:<?= (float)$u['urlaub_tage'] > 0 ? round($rest / (float)$u['urlaub_tage'] * 100) : 0 ?>%;background:var(--ok)"></i></div>
            <div class="sm mu2" style="margin-top:4px"><?= num($genommen, 0) ?> Tage genommen</div>
          </div></div>
          <div class="c"><div class="bo"><div class="lb">Krank <?= h($jahr) ?></div>
            <div style="font-size:26px;font-weight:640;letter-spacing:-.03em"><?= $tage('krank') ?>
              <span class="sm mu2" style="font-weight:400">Tage</span></div></div></div>
          <div class="c"><div class="bo"><div class="lb">Unentschuldigt Schule</div>
            <div style="font-size:26px;font-weight:640;letter-spacing:-.03em">
              <?= count(array_filter($rows, fn($r) => (int)$r['schule'] === 1 && (int)$r['entschuldigt'] === 0)) ?>
              <span class="sm mu2" style="font-weight:400">offen</span></div></div></div>
        </div>
        <div class="sp2">
          <div class="c">
            <div class="hd"><h2>Fehlzeiten</h2><span class="sp"></span><span class="sm mu2"><?= count($rows) ?></span></div>
            <?php if (!$rows): ?><?= em('Nichts erfasst.') ?><?php else: ?>
            <ul class="li rows">
              <?php foreach ($rows as $r):
                $tage = werktage($r['von'], $r['bis']);
                $zeit = dt($r['von'], 'd.m.y') . ($r['bis'] !== $r['von'] ? ' bis ' . dt($r['bis'], 'd.m.y') : '');
                $neben = array_filter([$zeit, $tage . ' ' . ($tage === 1 ? 'Tag' : 'Tage'), $r['grund'] ?: '']); ?>
                <li>
                  <span class="tile" style="background:<?= $r['art'] === 'krank' ? '#ff3b30' : ($r['art'] === 'urlaub' ? '#00b894' : '#8e8e93') ?>">
                    <?= ic($r['art'] === 'urlaub' ? 'frei' : 'einsatz', 17) ?></span>
                  <span class="tx"><b><?= h(ucfirst($r['art'])) ?></b>
                    <span class="sm mu2"><?= h(implode(' · ', $neben)) ?></span></span>
                  <?php if ((int)$r['schule']): ?>
                    <span class="tg <?= (int)$r['entschuldigt'] ? 'o' : 'e' ?>"><?= (int)$r['entschuldigt'] ? 'entsch.' : 'offen' ?></span>
                  <?php endif; ?>
                  <form method="post" data-q="Loeschen?" style="flex:none">
                    <?= csrf_field() ?><input type="hidden" name="a" value="abwdel">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="g s d" type="submit">&times;</button></form>
                </li>
              <?php endforeach; ?>
            </ul>
            <?php endif; ?>
          </div>
          <div>
            <div class="c"><div class="hd"><h2>Eintragen</h2></div><div class="bo">
              <form method="post">
                <?= csrf_field() ?><input type="hidden" name="a" value="abw">
                <div class="fg">
                  <div class="f"><label for="v">Von</label><input id="v" name="von" type="date" required value="<?= h(today()) ?>" data-new></div>
                  <div class="f"><label for="b">Bis</label><input id="b" name="bis" type="date" value="<?= h(today()) ?>"></div>
                </div>
                <div class="f"><label for="ar">Art</label><select id="ar" name="art"><?= optm(
                  ['krank'=>'Krank','urlaub'=>'Urlaub','frei'=>'Frei','dienstreise'=>'Dienstreise','schulung'=>'Schulung'], 'krank') ?></select></div>
                <div class="f"><label for="gr">Grund</label><input id="gr" name="grund"></div>
                <div class="rw" style="margin-bottom:10px">
                  <label class="ck"><input type="checkbox" name="schule" value="1"> Berufsschule</label>
                  <label class="ck"><input type="checkbox" name="entschuldigt" value="1"> entschuldigt</label>
                </div>
                <button class="p" type="submit">Speichern</button>
              </form>
            </div></div>
            <div class="c"><div class="hd"><h2>Urlaubsanspruch</h2></div><div class="bo">
              <form method="post" class="rw">
                <?= csrf_field() ?><input type="hidden" name="a" value="urlaub">
                <input name="urlaub_tage" inputmode="decimal" style="width:80px;flex:none"
                       value="<?= h(num((float)$u['urlaub_tage'], 0)) ?>">
                <span class="sm mu">Tage im Jahr</span>
                <button class="s" type="submit">Sichern</button>
              </form>
            </div></div>
          </div>
        </div>
        <?php
        page('Fehlzeiten & Urlaub', ob_get_clean(), ['tabs' => einsatz_tabs(), 'aktiv' => 'zeiten']);
        return;
    }

    $edit = get('id') !== '' ? one("SELECT * FROM einsaetze WHERE id = ? AND user_id = ?", [(int)get('id'), $uid]) : null;
    if (get('neu') !== '') $edit = ['id' => 0, 'von' => today()];
    $rows = all("SELECT * FROM einsaetze WHERE user_id = ? ORDER BY von DESC", [$uid]);
    ob_start(); ?>
    <div class="sp2<?= $edit !== null ? ' det' : '' ?>">
      <div class="c">
        <div class="hd"><h2>Abteilungsdurchlauf</h2><span class="sp"></span>
          <a class="bt p s" data-new href="<?= url('einsaetze', ['neu' => 1]) ?>">Neu <kbd>n</kbd></a></div>
        <?php if (!$rows): ?><?= em('Noch kein Einsatz erfasst. Der Nachweis uebernimmt die Abteilung dann automatisch.') ?>
        <?php else: ?>
        <ul class="li rows">
          <?php foreach ($rows as $r): $laeuft = $r['von'] <= today() && ($r['bis'] === '' || $r['bis'] >= today());
            $zeit = dt($r['von'], 'd.m.y') . ' – ' . ($r['bis'] ? dt($r['bis'], 'd.m.y') : 'offen');
            $neben = array_filter([$zeit, $r['schwerpunkt'] ?: '', $r['ansprech'] ?: '']); ?>
            <li>
              <span class="tile" style="background:#a2845e"><?= ic('einsatz', 17) ?></span>
              <span class="tx"><b><a href="<?= url('einsaetze', ['id' => $r['id']]) ?>"><?= h($r['abteilung']) ?></a></b>
                <span class="sm mu2"><?= h(implode(' · ', $neben)) ?></span></span>
              <?= $laeuft ? '<span class="tg a">aktuell</span>' : '' ?>
              <form method="post" data-q="Einsatz loeschen?" style="flex:none"><?= csrf_field() ?>
                <input type="hidden" name="a" value="del"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="g s d" type="submit">&times;</button></form>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
      <div>
        <?php if ($edit !== null): $neu = empty($edit['id']); ?>
        <div class="c"><div class="hd"><h2><?= $neu ? 'Neuer Einsatz' : 'Einsatz' ?></h2></div><div class="bo">
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="a" value="save">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="f"><label for="ea">Abteilung</label>
              <input id="ea" name="abteilung" required value="<?= h($edit['abteilung'] ?? '') ?>"<?= $neu ? ' autofocus' : '' ?>></div>
            <div class="fg">
              <div class="f"><label for="ev">Von</label><input id="ev" name="von" type="date" required value="<?= h($edit['von'] ?? today()) ?>"></div>
              <div class="f"><label for="eb">Bis</label><input id="eb" name="bis" type="date" value="<?= h($edit['bis'] ?? '') ?>"></div>
            </div>
            <div class="f"><label for="es">Schwerpunkt</label><input id="es" name="schwerpunkt" value="<?= h($edit['schwerpunkt'] ?? '') ?>"></div>
            <div class="f"><label for="ep">Ansprechpartner</label><input id="ep" name="ansprech" value="<?= h($edit['ansprech'] ?? '') ?>"></div>
            <div class="f"><label for="en">Notiz</label><textarea id="en" name="notiz" style="min-height:60px"><?= h($edit['notiz'] ?? '') ?></textarea></div>
            <div class="rw"><button class="p" type="submit">Speichern</button>
              <a class="bt g" href="<?= url('einsaetze') ?>">Schliessen</a></div>
          </form>
          <?php if (!$neu): ?>
            <hr><form method="post"><?= csrf_field() ?>
              <input type="hidden" name="a" value="jetzt"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
              <button class="s" type="submit">Als aktuelle Abteilung setzen</button></form>
          <?php endif; ?>
        </div></div>
        <?php else: ?>
        <div class="c"><div class="hd"><h2>Aktuell</h2></div><div class="bo">
          <dl class="kv">
            <dt>Abteilung</dt><dd><?= h(einsatz_am($uid, today()) ?: ($u['abteilung'] ?: '–')) ?></dd>
            <dt>Betrieb</dt><dd><?= h($u['betrieb'] ?: '–') ?></dd>
            <dt>Ausbilder/-in</dt><dd><?= h($u['ausbilder'] ?: '–') ?></dd>
          </dl>
        </div></div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    page('Einsaetze', ob_get_clean(), ['tabs' => einsatz_tabs(), 'aktiv' => 'abteilungen']);
}

// --- Ansprechpartner -------------------------------------------------------
function p_kontakte(): void {
    $u = need_login(); $uid = (int)$u['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $a = post('a'); $id = (int)post('id', '0');
        if ($a === 'save') {
            $d = ['name' => mb_substr(post('name'), 0, 80), 'rolle' => mb_substr(post('rolle'), 0, 80),
                'bereich' => in_array(post('bereich'), ['betrieb','schule','ihk','sonst'], true) ? post('bereich') : 'betrieb',
                'telefon' => mb_substr(post('telefon'), 0, 40), 'mail' => mb_substr(post('mail'), 0, 120),
                'raum' => mb_substr(post('raum'), 0, 40), 'notiz' => mb_substr(post('notiz'), 0, 400),
                'subject_id' => inull(postn('subject_id'))];
            if ($d['name'] === '') flash('Name fehlt.', 'err');
            elseif ($id) { upd('kontakte', $d, 'id = :id AND user_id = :u', ['id' => $id, 'u' => $uid]); flash('Gespeichert.'); }
            else { $d['user_id'] = $uid; ins('kontakte', $d); flash('Angelegt.'); }
        } elseif ($a === 'del') {
            del('kontakte', 'id = ? AND user_id = ?', [$id, $uid]);
        }
        redirect(url('kontakte'));
    }
    $edit = get('id') !== '' ? one("SELECT * FROM kontakte WHERE id = ? AND user_id = ?", [(int)get('id'), $uid]) : null;
    if (get('neu') !== '') $edit = ['id' => 0];
    $qs = get('q');
    $wo = 'k.user_id = ?'; $ar = [$uid];
    if ($qs !== '') {
        $l = '%' . $qs . '%';
        $wo .= ' AND (k.name LIKE ? OR k.rolle LIKE ? OR k.bereich LIKE ? OR k.telefon LIKE ? OR k.notiz LIKE ?)';
        array_push($ar, $l, $l, $l, $l, $l);
    }
    $rows = all("SELECT k.*, s.short FROM kontakte k LEFT JOIN subjects s ON s.id = k.subject_id
                 WHERE $wo ORDER BY k.bereich, k.rolle, k.name", $ar);
    $grp = [];
    foreach ($rows as $r) $grp[$r['bereich']][] = $r;
    $titel = ['betrieb' => 'Betrieb', 'schule' => 'Schule', 'ihk' => 'IHK', 'sonst' => 'Sonstige'];
    ob_start(); ?>
    <div class="sp2<?= $edit !== null ? ' det' : '' ?>">
      <div>
        <div class="c np"><div class="bo" style="padding:9px 12px">
          <form method="get" class="rw"><input type="hidden" name="p" value="kontakte">
            <input name="q" value="<?= h($qs) ?>" placeholder="Filtern" style="flex:1">
            <?php if ($qs !== ''): ?><a class="bt s g" href="<?= url('kontakte') ?>">zurueck</a><?php endif; ?>
          </form>
        </div></div>
        <?php if (!$rows): ?><div class="c"><?= em($qs !== '' ? 'Nichts gefunden.' : 'Noch niemand hinterlegt.') ?></div><?php endif; ?>
        <?php foreach ($titel as $b => $lbl): if (empty($grp[$b])) continue; ?>
          <div class="c"><div class="hd"><h2><?= h($lbl) ?></h2><span class="sp"></span>
            <span class="sm mu2"><?= count($grp[$b]) ?></span></div>
            <ul class="li rows">
              <?php foreach ($grp[$b] as $k):
                $neben = array_filter([$k['rolle'] ?: '', $k['short'] ?: '', $k['raum'] ?: '']); ?>
                <li>
                  <span class="tile t-kontakt"><?= ic('kontakt', 17) ?></span>
                  <span class="tx"><b><a href="<?= url('kontakte', ['id' => $k['id']]) ?>"><?= h($k['name']) ?></a></b>
                    <span class="sm mu2"><?= h(implode(' · ', $neben)) ?></span></span>
                  <?php if ($k['telefon']): ?>
                    <a class="sm mo" style="flex:none" href="tel:<?= h(preg_replace('/[^\d+]/', '', $k['telefon'])) ?>"><?= h($k['telefon']) ?></a>
                  <?php endif; ?>
                  <form method="post" data-q="Loeschen?" style="flex:none"><?= csrf_field() ?>
                    <input type="hidden" name="a" value="del"><input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                    <button class="g s d" type="submit">&times;</button></form>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="c"><div class="hd"><h2><?= $edit && !empty($edit['id']) ? 'Bearbeiten' : 'Neu' ?></h2></div><div class="bo">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="a" value="save">
          <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
          <div class="f"><label for="kn">Name</label><input id="kn" name="name" required data-new value="<?= h($edit['name'] ?? '') ?>"></div>
          <div class="fg">
            <div class="f"><label for="kb">Bereich</label><select id="kb" name="bereich"><?= optm(
              ['betrieb'=>'Betrieb','schule'=>'Schule','ihk'=>'IHK','sonst'=>'Sonstige'], $edit['bereich'] ?? 'betrieb') ?></select></div>
            <div class="f"><label for="kr">Rolle</label><input id="kr" name="rolle" value="<?= h($edit['rolle'] ?? '') ?>"></div>
          </div>
          <div class="fg">
            <div class="f"><label for="kt">Telefon</label><input id="kt" name="telefon" value="<?= h($edit['telefon'] ?? '') ?>"></div>
            <div class="f"><label for="km">E-Mail</label><input id="km" name="mail" value="<?= h($edit['mail'] ?? '') ?>"></div>
          </div>
          <div class="fg">
            <div class="f"><label for="kz">Raum</label><input id="kz" name="raum" value="<?= h($edit['raum'] ?? '') ?>"></div>
            <div class="f"><label for="kf">Fach</label><select id="kf" name="subject_id"><?= fach_opts($uid, $edit['subject_id'] ?? null) ?></select></div>
          </div>
          <div class="f"><label for="kx">Notiz</label><textarea id="kx" name="notiz" style="min-height:56px"><?= h($edit['notiz'] ?? '') ?></textarea></div>
          <div class="rw"><button class="p" type="submit">Speichern</button>
            <?php if ($edit && !empty($edit['id'])): ?><a class="bt g" href="<?= url('kontakte') ?>">Neu</a><?php endif; ?></div>
        </form>
      </div></div>
    </div>
    <?php
    page('Kontakte', ob_get_clean(), ['unter' => count($rows) . ' Ansprechpartner']);
}

// --- Pruefung --------------------------------------------------------------
/** Ein Abschlussprojekt je Konto, bei Bedarf aus der alten Notiz uebernommen. */
function projekt_get(int $uid): array {
    $p = one("SELECT * FROM projekt WHERE user_id = ? ORDER BY id LIMIT 1", [$uid]);
    if ($p) return $p;
    $alt = json_decode((string)val("SELECT v FROM meta WHERE k = ?", ['prj' . $uid], '{}'), true) ?: [];
    $id = ins('projekt', ['user_id' => $uid, 'titel' => mb_substr((string)($alt['titel'] ?? ''), 0, 200),
        'beschreibung' => (string)($alt['antrag'] ?? ''),
        'stunden' => (float)($alt['stunden'] ?? 80) ?: 80,
        'status' => in_array($alt['status'] ?? '', ['idee','antrag','genehmigt','laeuft','doku','fertig'], true)
            ? $alt['status'] : 'idee']);
    return one("SELECT * FROM projekt WHERE id = ?", [$id]);
}
function projekt_termine(): array {
    return ['antrag' => 'Antrag eingereicht', 'genehmigt' => 'Antrag genehmigt', 'von' => 'Durchfuehrung ab',
            'bis' => 'Durchfuehrung bis', 'doku' => 'Dokumentation abgeben', 'praesentation' => 'Praesentation'];
}
function pruef_projekt(array $u): void {
    $uid = (int)$u['id'];
    $p = projekt_get($uid);
    $ph = all("SELECT * FROM projekt_phasen WHERE projekt_id = ? ORDER BY sort, id", [(int)$p['id']]);
    $soll = array_sum(array_map(fn($x) => (float)$x['stunden'], $ph));
    $ist  = array_sum(array_map(fn($x) => (float)$x['ist'], $ph));
    $budget = (float)$p['stunden'] ?: 80;
    $naechst = null;
    foreach (projekt_termine() as $k => $lbl) {
        if ($p[$k] && $p[$k] >= today() && ($naechst === null || $p[$k] < $naechst[0])) $naechst = [$p[$k], $lbl];
    }
    ob_start(); ?>
    <div class="g g3" style="margin-bottom:14px">
      <div class="c"><div class="bo"><div class="lb">Naechster Schritt</div>
        <div style="font-size:20px;font-weight:640;letter-spacing:-.02em"><?= $naechst ? h(dt($naechst[0])) : '–' ?></div>
        <div class="sm mu2"><?= $naechst ? h($naechst[1]) : 'kein Termin gesetzt' ?></div></div></div>
      <div class="c"><div class="bo"><div class="lb">Stunden geplant</div>
        <div style="font-size:20px;font-weight:640;letter-spacing:-.02em"><?= num($soll, 1) ?>
          <span class="sm mu2" style="font-weight:400">von <?= num($budget, 0) ?></span></div>
        <div class="br" style="margin-top:8px"><i style="width:<?= min(100, round($soll / max(1, $budget) * 100)) ?>%;background:<?= $soll > $budget ? 'var(--er)' : 'var(--ac)' ?>"></i></div></div></div>
      <div class="c"><div class="bo"><div class="lb">Stunden geleistet</div>
        <div style="font-size:20px;font-weight:640;letter-spacing:-.02em"><?= num($ist, 1) ?>
          <span class="sm mu2" style="font-weight:400">h</span></div>
        <div class="br" style="margin-top:8px"><i style="width:<?= min(100, round($ist / max(1, $budget) * 100)) ?>%"></i></div></div></div>
    </div>

    <div class="sp2">
      <div>
        <div class="c"><div class="hd"><h2>Projekt</h2><span class="sp"></span>
          <span class="tg <?= $p['status'] === 'fertig' ? 'o' : 'a' ?>"><?= h($p['status']) ?></span></div><div class="bo">
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="a" value="projekt">
            <div class="f"><label for="pt">Titel</label><input id="pt" name="titel" value="<?= h($p['titel']) ?>"></div>
            <div class="fg">
              <div class="f"><label for="px">Status</label><select id="px" name="status"><?= optm(
                ['idee'=>'Idee','antrag'=>'Antrag gestellt','genehmigt'=>'genehmigt','laeuft'=>'in Umsetzung',
                 'doku'=>'Dokumentation','fertig'=>'abgeschlossen'], $p['status']) ?></select></div>
              <div class="f"><label for="ps">Stundenbudget</label><input id="ps" name="stunden" inputmode="decimal" value="<?= h(num($budget, 0)) ?>"></div>
            </div>
            <div class="fg">
              <?php foreach (projekt_termine() as $k => $lbl): ?>
                <div class="f"><label for="pd<?= h($k) ?>"><?= h($lbl) ?></label>
                  <input id="pd<?= h($k) ?>" name="<?= h($k) ?>" type="date" value="<?= h($p[$k] ?? '') ?>"></div>
              <?php endforeach; ?>
            </div>
            <div class="f"><label for="pb">Antrag / Beschreibung</label>
              <textarea id="pb" name="beschreibung" style="min-height:190px" data-d="prj<?= (int)$p['id'] ?>"><?= h($p['beschreibung']) ?></textarea></div>
            <div class="f"><label for="pn">Notiz</label><textarea id="pn" name="notiz" style="min-height:56px"><?= h($p['notiz']) ?></textarea></div>
            <div class="rw"><button class="p" type="submit">Speichern</button>
              <button class="s" name="a" value="prjtermine" type="submit">Termine in den Plan</button></div>
          </form>
        </div></div>
      </div>
      <div>
        <div class="c"><div class="hd"><h2>Phasen</h2><span class="sp"></span>
          <span class="sm mu2"><?= num($soll, 1) ?> h</span></div>
          <?php if ($ph): ?>
          <div class="tw"><table><tbody>
            <?php foreach ($ph as $x): ?>
              <tr>
                <td><?= h($x['name']) ?></td>
                <td style="width:120px">
                  <form method="post" class="rw" style="gap:4px;flex-wrap:nowrap"><?= csrf_field() ?>
                    <input type="hidden" name="a" value="phist"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
                    <input name="ist" inputmode="decimal" value="<?= h(num((float)$x['ist'], 1)) ?>" style="height:24px;width:56px">
                    <span class="sm mu2">/ <?= num((float)$x['stunden'], 1) ?></span>
                    <button class="s g" type="submit">ok</button>
                  </form>
                </td>
                <td style="width:30px"><form method="post"><?= csrf_field() ?>
                  <input type="hidden" name="a" value="phdel"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
                  <button class="g s d" type="submit">&times;</button></form></td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <?php else: ?><?= em('Noch keine Phasen.') ?><?php endif; ?>
          <div class="bo" style="border-top:1px solid var(--li)">
            <form method="post" class="rw" style="flex-wrap:nowrap">
              <?= csrf_field() ?><input type="hidden" name="a" value="phneu">
              <input name="name" required placeholder="Phase" style="flex:1;min-width:0">
              <input name="stunden" inputmode="decimal" placeholder="h" style="width:60px;flex:none">
              <button class="p" type="submit" style="flex:none">+</button>
            </form>
          </div>
        </div>
        <div class="c"><div class="hd"><h2>Ablauf</h2></div>
          <ul class="li">
            <?php foreach (projekt_termine() as $k => $lbl): ?>
              <li><span style="flex:1"><?= h($lbl) ?></span>
                <span class="mo sm <?= $p[$k] ? '' : 'mu2' ?>"><?= $p[$k] ? h(dt($p[$k])) : '–' ?></span></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>
    <?php
    page('Abschlussprojekt', ob_get_clean(), ['unter' => h((string)$p['titel'])]);
}

function p_pruefung(): void {
    $u = need_login(); $uid = (int)$u['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (post('a') === 'punkte') {
            $p = [];
            foreach (array_keys(ihk_bereiche()) as $k) $p[$k] = post('p_' . $k);
            upd('users', ['ap1' => post('ap1') ?: null, 'ap2' => post('ap2') ?: null], 'id = :id', ['id' => $uid]);
            q("INSERT INTO meta (k,v) VALUES (:k,:v) ON CONFLICT(k) DO UPDATE SET v = :v2",
              ['k' => 'ihk' . $uid, 'v' => json_encode($p), 'v2' => json_encode($p)]);
            flash('Gespeichert.');
        } elseif (post('a') === 'projekt' || post('a') === 'prjtermine') {
            $pj = projekt_get($uid);
            $d = ['titel' => mb_substr(post('titel'), 0, 200),
                  'beschreibung' => mb_substr(post('beschreibung'), 0, 20000),
                  'notiz' => mb_substr(post('notiz'), 0, 2000),
                  'stunden' => max(0, min(400, (float)str_replace(',', '.', post('stunden', '80')))),
                  'status' => in_array(post('status'), ['idee','antrag','genehmigt','laeuft','doku','fertig'], true)
                      ? post('status') : 'idee',
                  'updated_at' => date('Y-m-d H:i:s')];
            foreach (array_keys(projekt_termine()) as $k) $d[$k] = isodate(post($k)) ? post($k) : null;
            upd('projekt', $d, 'id = :id AND user_id = :u', ['id' => (int)$pj['id'], 'u' => $uid]);
            if (post('a') === 'prjtermine') {
                $n = 0;
                foreach (projekt_termine() as $k => $lbl) {
                    if (!$d[$k]) continue;
                    $t = 'Projekt: ' . $lbl;
                    if (one("SELECT id FROM events WHERE user_id = ? AND datum = ? AND titel = ?", [$uid, $d[$k], $t])) continue;
                    ins('events', ['user_id' => $uid, 'typ' => 'frist', 'titel' => $t, 'datum' => $d[$k],
                        'beschreibung' => $d['titel'], 'quelle' => 'projekt']);
                    $n++;
                }
                flash($n . ' Termine im Plan.');
            } else flash('Gespeichert.');
        } elseif (post('a') === 'phneu') {
            $pj = projekt_get($uid);
            if (post('name') !== '') {
                ins('projekt_phasen', ['projekt_id' => (int)$pj['id'], 'user_id' => $uid,
                    'name' => mb_substr(post('name'), 0, 80),
                    'stunden' => max(0, (float)str_replace(',', '.', post('stunden', '0'))),
                    'sort' => (int)val("SELECT COALESCE(MAX(sort),0)+10 FROM projekt_phasen WHERE projekt_id = ?", [(int)$pj['id']], 10)]);
            }
        } elseif (post('a') === 'phist') {
            upd('projekt_phasen', ['ist' => max(0, (float)str_replace(',', '.', post('ist', '0')))],
                'id = :id AND user_id = :u', ['id' => (int)post('id', '0'), 'u' => $uid]);
        } elseif (post('a') === 'phdel') {
            del('projekt_phasen', 'id = ? AND user_id = ?', [(int)post('id', '0'), $uid]);
        }
        redirect(url('pruefung', get('t') ? ['t' => get('t')] : []));
    }
    if (get('t') === 'projekt') { pruef_projekt($u); return; }
    $ihk = json_decode((string)val("SELECT v FROM meta WHERE k = ?", ['ihk' . $uid], '{}'), true) ?: [];
    $pg  = ihk_prognose($ihk);
    $pb  = ihk_probleme($ihk);
    $cd  = function (?string $d): ?int { return $d ? tage(today(), $d) : null; };
    $a1 = $cd($u['ap1']); $a2 = $cd($u['ap2']);
    $tab = get('t');
    ob_start(); ?>
    <?php if ($tab === 'lf'): ?>
      <div class="c"><div class="hd"><h2>Lernfelder</h2></div>
        <ul class="li rows">
          <?php foreach (all("SELECT l.*, (SELECT COUNT(*) FROM notes n WHERE n.user_id = ? AND n.lf_no = l.nr) AS anz
                              FROM lernfelder l ORDER BY l.nr", [$uid]) as $l):
            $neben = [$l['code'], (int)$l['jahr'] . '. Jahr', (int)$l['stunden'] . ' Std',
                      (int)$l['anz'] . ' ' . ((int)$l['anz'] === 1 ? 'Notiz' : 'Notizen')]; ?>
            <li<?= (int)$l['anz'] ? '' : ' style="opacity:.6"' ?>>
              <span class="tile t-liste"><?= ic('liste', 17) ?></span>
              <span class="tx"><b><a href="<?= url('notizen', ['lf' => $l['nr']]) ?>"><?= h($l['titel']) ?></a></b>
                <span class="sm mu2"><?= h(implode(' · ', $neben)) ?></span></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php else: ?>
    <div class="sp2">
      <div>
        <div class="c"><div class="bo">
          <div class="rw" style="gap:26px">
            <div><div class="sm mu2">Teil 1</div><div style="font-size:22px;font-weight:600">
              <?= $a1 === null ? '–' : ($a1 > 0 ? $a1 . '<span class="sm mu"> T</span>' : 'vorbei') ?></div>
              <div class="sm mu2"><?= $u['ap1'] ? h(dt($u['ap1'])) : 'kein Termin' ?></div></div>
            <div><div class="sm mu2">Teil 2</div><div style="font-size:22px;font-weight:600">
              <?= $a2 === null ? '–' : ($a2 > 0 ? $a2 . '<span class="sm mu"> T</span>' : 'vorbei') ?></div>
              <div class="sm mu2"><?= $u['ap2'] ? h(dt($u['ap2'])) : 'kein Termin' ?></div></div>
            <div><div class="sm mu2">Prognose</div><div style="font-size:22px;font-weight:600;color:<?= h(nfarbe($pg['note'])) ?>">
              <?= $pg['punkte'] !== null ? num($pg['punkte'], 0) . '<span class="sm mu"> P</span>' : '–' ?></div>
              <div class="sm mu2"><?= $pg['note'] !== null ? 'Note ' . num((float)$pg['note'], 1) . ' · ' . $pg['abdeckung'] . ' % erfasst' : '' ?></div></div>
            <div style="flex:1;min-width:140px">
              <?php if ($pg['punkte'] !== null): ?>
                <div class="br" style="height:8px"><i style="width:<?= (int)$pg['punkte'] ?>%;background:<?= $pg['punkte'] >= 50 ? 'var(--ok)' : 'var(--er)' ?>"></i></div>
                <?php foreach ($pb as $x): ?><div class="sm" style="color:var(--wa);margin-top:3px"><?= h($x) ?></div><?php endforeach; ?>
                <?php if (!$pb): ?><div class="sm" style="color:var(--ok);margin-top:3px">Bestehensregeln erfuellt</div><?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        </div></div>
        <div class="c"><div class="hd"><h2>Pruefungsbereiche</h2></div><div class="bo">
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="a" value="punkte">
            <div class="tw"><table><thead><tr><th>Bereich</th><th class="n">Gewicht</th><th style="width:110px">Punkte</th></tr></thead><tbody>
              <?php foreach (ihk_bereiche() as $k => [$lbl, $w]): ?>
                <tr><td><?= h($lbl) ?></td><td class="n"><?= $w ?> %</td>
                  <td><input name="p_<?= $k ?>" inputmode="decimal" style="height:26px" value="<?= h((string)($ihk[$k] ?? '')) ?>"></td></tr>
              <?php endforeach; ?>
            </tbody></table></div>
            <div class="fg" style="margin-top:10px">
              <div class="f"><label for="a1">Termin Teil 1</label><input id="a1" name="ap1" type="date" value="<?= h($u['ap1']) ?>"></div>
              <div class="f"><label for="a2">Termin Teil 2</label><input id="a2" name="ap2" type="date" value="<?= h($u['ap2']) ?>"></div>
            </div>
            <button class="p" type="submit">Speichern</button>
          </form>
        </div></div>
      </div>
      <div>
        <div class="c"><div class="hd"><h2>Abschlussprojekt</h2></div><div class="bo">
          <?php $pj = projekt_get($uid); ?>
          <div style="font-size:15px;font-weight:590"><?= h($pj['titel'] ?: 'noch kein Titel') ?></div>
          <div class="sm mu2" style="margin-bottom:10px"><?= h($pj['status']) ?></div>
          <a class="bt s" href="<?= url('pruefung', ['t' => 'projekt']) ?>">Projekt oeffnen</a>
        </div></div>
      </div>
    </div>
    <?php endif; ?>
    <?php
    page('Pruefung', ob_get_clean(), []);
}

// --- Suche -----------------------------------------------------------------
function p_suche(): void {
    $u = need_login(); $uid = (int)$u['id']; $qs = get('q');
    $antwort = such_antwort($u, $qs);
    $ziele   = ziele_suchen($qs, $qs === '' ? 10 : 5);   // leer: die Ziele, die man am haeufigsten braucht
    $treffer = mb_strlen($qs) >= 2 ? suche($uid, $qs, 60) : [];
    ob_start(); ?>

    <?php if ($antwort): ?>
      <div class="c"><ul class="li rows antw">
        <?php foreach ($antwort as $x): ?>
          <li><a href="<?= h($x['url']) ?>">
            <span class="tile t-<?= h($x['icon']) ?>"><?= ic($x['icon'], 17) ?></span>
            <span class="tx"><span class="sm mu2"><?= h($x['label']) ?></span><b><?= h($x['wert']) ?></b></span>
            <?= ic('weiter', 17) ?></a></li>
        <?php endforeach; ?>
      </ul></div>
    <?php endif; ?>
    <?php if ($ziele): ?>
      <div class="c"><div class="hd"><h2>Springen</h2></div>
        <ul class="li rows">
          <?php foreach ($ziele as $z): ?>
            <li><a href="<?= h($z['url']) ?>">
              <span class="tile" style="background:<?= h($z['farbe']) ?>"><?= ic($z['icon'], 17) ?></span>
              <span class="tx"><b><?= h($z['label']) ?></b>
                <?= $z['bereich'] ? '<span class="sm mu2">' . h($z['bereich']) . '</span>' : '' ?></span>
              <?= ic('weiter', 17) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if ($qs !== '' && !$treffer && !$ziele && !$antwort): ?>
      <div class="c"><?= em('Nichts gefunden.') ?></div>
    <?php endif; ?>
    <?php if (mb_strlen($qs) === 1): ?>
      <div class="sm mu2" style="padding:0 4px">Ab zwei Zeichen wird auch der Inhalt durchsucht.</div>
    <?php endif; ?>
    <?php if (mb_strlen($qs) >= 2): ?>
      <?php if ($treffer): ?>
        <div class="c"><div class="hd"><h2>Treffer</h2><span class="sp"></span>
          <span class="sm mu2"><?= count($treffer) ?></span></div>
          <ul class="li rows">
            <?php foreach ($treffer as $t): $sym = art_icon($t['art']); ?>
              <li><a href="<?= h(such_ziel($t['art'], $t['ref'], (string)$t['datum'], $u)) ?>">
                <span class="tile t-<?= h($sym) ?>"><?= ic($sym, 17) ?></span>
                <span class="tx"><b><?= h(mb_substr((string)($t['titel'] ?: '(ohne Titel)'), 0, 110)) ?></b>
                  <?php
                  $teile = [art_label($t['art'])];
                  if ($t['datum']) $teile[] = dt($t['datum'], 'd.m.y');
                  $aus = trim((string)$t['aus']);
                  if ($aus !== '' && mb_stripos((string)$t['titel'], $aus) === false
                      && !in_array($aus, $teile, true)) $teile[] = mb_substr($aus, 0, 90); ?>
                  <span class="sm mu2"><?= h(implode(' · ', $teile)) ?></span></span>
                <?= ic('weiter', 17) ?></a></li>
            <?php endforeach; ?>
          </ul></div>
      <?php endif; ?>
      <div class="sm mu2" style="padding:0 4px">Filter: <code>lf:9</code> <code>fach:LF9</code> <code>typ:notiz</code>
        <?php if (!hat_fts()): ?> · <span class="tg w">Volltextindex nicht verfuegbar</span><?php endif; ?></div>
    <?php endif; ?>
    <?php
    page('Suche', ob_get_clean(), ['unter' => mb_strlen($qs) >= 2
        ? (count($antwort) + count($ziele) + count($treffer)) . ' Treffer fuer &bdquo;' . h($qs) . '&ldquo;' : '']);
}

// --- Einstellungen ---------------------------------------------------------
/**
 * Einrichtung: alle Schul-Apps an einer Stelle verbinden. Verbinden heisst
 * laden - jede Quelle wird sofort abgerufen, damit das Interface befuellt ist.
 */
function p_einrichtung(): void {
    $u = need_login(); $uid = (int)$u['id'];
    $fs = strtoupper((string)$u['kl_kuerzel']) === 'FS';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $a = post('a');
        $merken = function (array $w) { $_SESSION['einr'] = $w; };   // Eingaben ueber den Fehler hinweg behalten
        if ($a === 'untis') {
            if (!rl('untis:' . $uid, 8, 600)) { flash('Zu viele Versuche - kurz warten.', 'err'); redirect(url('einrichtung')); }
            $alt = one("SELECT * FROM sources WHERE user_id = ? AND typ = 'webuntis' ORDER BY aktiv DESC, id DESC", [$uid]);
            // Server: Schema und Pfad abschneiden, dann ein schlichter Hostname
            $srv = strtolower(trim(preg_replace('~^https?://~i', '', post('server'))));
            $srv = explode('/', $srv)[0];
            if ($srv === '') $srv = $fs ? UNTIS_SERVER_FS : (string)($alt['server'] ?? '');
            if (!preg_match('~^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$~', $srv)) $srv = '';
            $sch = mb_substr(preg_replace('/[^A-Za-z0-9._-]/', '', post('schule')), 0, 60);
            if ($sch === '') $sch = $fs ? UNTIS_SCHULE_FS : (string)($alt['schule'] ?? '');
            $ben = mb_substr(post('benutzer'), 0, 80) ?: (string)($alt['benutzer'] ?? '');
            $pw  = (string)($_POST['pw'] ?? '');
            $fehlt = [];
            if (!$fs && $srv === '') $fehlt[] = 'Server';
            if (!$fs && $sch === '') $fehlt[] = 'Schule';
            if ($ben === '') $fehlt[] = 'Benutzer';
            if ($pw === '' && empty($alt['secret'])) $fehlt[] = 'Passwort';
            if ($fehlt) { $merken(['benutzer' => $ben, 'server' => $srv, 'schule' => $sch]); flash(implode(', ', $fehlt) . ' fehlt.', 'err'); redirect(url('einrichtung')); }
            $d = ['name' => 'WebUntis', 'typ' => 'webuntis', 'modus' => 'stundenplan',
                  'server' => $srv, 'schule' => $sch, 'benutzer' => $ben, 'intervall' => 360, 'aktiv' => 1];
            if ($pw !== '') {
                $c = verschluesseln($pw);
                if ($c === null) { flash('Ohne die PHP-Extension sodium kann kein Passwort gespeichert werden.', 'err'); redirect(url('einrichtung')); }
                $d['secret'] = $c;
            }
            if ($alt) { upd('sources', $d, 'id = :id AND user_id = :u', ['id' => (int)$alt['id'], 'u' => $uid]); $sid = (int)$alt['id']; }
            else { $d['user_id'] = $uid; $sid = ins('sources', $d); }
            $r = einr_sync($sid, $u, 15);
            if ($r['fehler'] !== '') {
                $m = str_contains($r['fehler'], 'bad credentials') ? 'Benutzername oder Passwort falsch.' : $r['fehler'];
                $merken(['benutzer' => $ben, 'server' => $srv, 'schule' => $sch]);
                flash('WebUntis: ' . $m, 'err');
            } else flash('WebUntis verbunden, ' . $r['n'] . ' Stunden geladen.');
        } elseif ($a === 'block') {
            if (!rl('bp:' . $uid, 5, 3600)) { flash('Zu viele Versuche - kurz warten.', 'err'); redirect(url('einrichtung')); }
            $r = blockplan_und_ferien($u);
            flash($r['fehler'] !== '' ? $r['fehler'] : $r['n'] . ' Eintraege geladen' . $r['ferien'] . '.',
                  $r['fehler'] !== '' ? 'err' : 'ok');
        } elseif ($a === 'moodle') {
            if (!rl('untis:' . $uid, 8, 600)) { flash('Zu viele Versuche - kurz warten.', 'err'); redirect(url('einrichtung')); }
            $alt = one("SELECT * FROM sources WHERE user_id = ? AND typ = 'moodle' ORDER BY aktiv DESC, id DESC", [$uid]);
            $roh = trim(post('url'));
            if ($roh === '' && $alt && !empty($alt['secret'])) {
                // Nur neu laden, Adresse und Geheimnis bleiben
                upd('sources', ['aktiv' => 1], 'id = :id', ['id' => (int)$alt['id']]);
                $r = einr_sync((int)$alt['id'], $u, 15);
                flash($r['fehler'] !== '' ? 'Moodle: ' . $r['fehler'] : 'Moodle: ' . $r['n'] . ' Termine geladen.', $r['fehler'] !== '' ? 'err' : 'ok');
                redirect(url('einrichtung'));
            }
            $m = moodle_teile($roh);
            if ($m['fehler'] !== '') { $merken(['url' => $roh]); flash($m['fehler'], 'err'); redirect(url('einrichtung')); }
            $c = verschluesseln($m['voll']);
            if ($c === null) { flash('Ohne die PHP-Extension sodium wird die Adresse nicht gespeichert.', 'err'); redirect(url('einrichtung')); }
            $d = ['name' => 'Moodle', 'typ' => 'moodle', 'modus' => 'termine',
                  'url' => $m['anzeige'], 'secret' => $c, 'intervall' => 360, 'aktiv' => 1];
            if ($alt) { upd('sources', $d, 'id = :id AND user_id = :u', ['id' => (int)$alt['id'], 'u' => $uid]); $sid = (int)$alt['id']; }
            else { $d['user_id'] = $uid; $sid = ins('sources', $d); }
            $r = einr_sync($sid, $u, 15);
            if ($r['fehler'] !== '') { $merken(['url' => $roh]); flash('Moodle: ' . $r['fehler'], 'err'); }
            else flash('Moodle verbunden, ' . $r['n'] . ' Termine geladen.');
        } elseif ($a === 'alles') {
            if (!rl('alles:' . $uid, 6, 600)) { flash('Zu viele Versuche - kurz warten.', 'err'); redirect(url('einrichtung')); }
            $teile = []; $fehl = false;
            if ($fs && (int)$u['zeitgruppe'] > 0) {   // Blockplan gehoert immer dazu, auch zum Aktualisieren
                $r = blockplan_und_ferien($u);
                if ($r['fehler'] === '') $teile[] = 'Blockplan ' . $r['n'];
                else { $teile[] = 'Blockplan: ' . $r['fehler']; $fehl = true; }
            }
            foreach (all("SELECT * FROM sources WHERE user_id = ? AND aktiv = 1 AND typ <> 'feiertage' ORDER BY id", [$uid]) as $src) {
                $r = einr_sync((int)$src['id'], $u, 12);
                $einheit = $src['typ'] === 'webuntis' && $src['modus'] === 'stundenplan' ? ' Stunden' : ' Termine';
                if ($r['fehler'] === '') $teile[] = $src['name'] . ' ' . $r['n'] . $einheit;
                else { $teile[] = $src['name'] . ': ' . $r['fehler']; $fehl = true; }
            }
            flash($teile ? implode(' · ', $teile) : 'Noch nichts verbunden.', $fehl ? 'err' : 'ok');
        } elseif ($a === 'trennen') {
            $id = (int)post('id', '0');
            $n = del('sources', 'id = ? AND user_id = ?', [$id, $uid]);
            if ($n) {
                del('events', 'user_id = ? AND quelle = ?', [$uid, 'q' . $id]);
                del('blocks', 'user_id = ? AND quelle = ?', [$uid, 'q' . $id]);
                flash('Getrennt.', 'warn');
            } else flash('Nichts zu trennen.', 'err');
        }
        redirect(url('einrichtung'));
    }

    $alt = $_SESSION['einr'] ?? []; unset($_SESSION['einr']);
    $untis  = one("SELECT * FROM sources WHERE user_id = ? AND typ = 'webuntis' AND aktiv = 1 ORDER BY id DESC", [$uid]);
    $moodle = one("SELECT * FROM sources WHERE user_id = ? AND typ = 'moodle' AND aktiv = 1 ORDER BY id DESC", [$uid]);
    $blocks = (int)val("SELECT COUNT(*) FROM blocks WHERE user_id = ? AND label LIKE 'Blockplan%'", [$uid], 0);
    $ferien = (bool)val("SELECT 1 FROM sources WHERE user_id = ? AND typ = 'feiertage'", [$uid]);
    $gut = fn(?array $src) => $src && $src['status'] !== 'fehler';
    $verbunden = ($gut($untis) ? 1 : 0) + ($gut($moodle) ? 1 : 0) + ($blocks ? 1 : 0);
    $anzahl = fn(?array $src) => $src ? (int)$src['anzahl'] : 0;
    $stand = fn(?array $src) => $src && $src['letzter_sync'] ? date('d.m. H:i', (int)$src['letzter_sync']) : '';
    // Eine Zeile Zustand je Karte: verbunden mit Stand und Zahl, oder der Fehler in Rot
    $zeile = function (?array $src, string $einheit, string $sonst) use ($gut, $anzahl, $stand): string {
        if (!$src) return h($sonst);
        if (!$gut($src)) return '<span style="color:var(--er)">' . h($src['meldung'] ?: 'Abruf fehlgeschlagen.') . '</span>';
        return 'verbunden' . ($stand($src) ? ' · ' . h($stand($src)) : '') . ' · ' . $anzahl($src) . ' ' . $einheit;
    };
    ob_start(); ?>
    <?php if ($verbunden || $untis || $moodle): ?>
      <form method="post" class="c np"><div class="bo rw" style="padding:11px 15px">
        <?= csrf_field() ?><input type="hidden" name="a" value="alles">
        <div><b>Verbunden</b><div class="sm mu2"><?= $verbunden ?> von 3<?= $blocks ? ' · ' . $blocks . ' Blockeintraege' : '' ?><?= $gut($untis) ? ' · ' . $anzahl($untis) . ' Stunden' : '' ?><?= $gut($moodle) ? ' · ' . $anzahl($moodle) . ' Termine' : '' ?><?= (($untis && !$gut($untis)) || ($moodle && !$gut($moodle))) ? ' · <span style="color:var(--er)">eine Quelle meldet einen Fehler</span>' : '' ?></div></div>
        <span class="sp"></span>
        <button class="p" type="submit">Alles aktualisieren</button>
      </div></form>
    <?php else: ?>
      <div class="c"><div class="bo"><b>Verbinde deine Schul-Apps.</b>
        <div class="sm mu2" style="margin-top:2px">Einmal verbunden, steht dein Plan, dein Stundenplan und deine Fristen von selbst.</div></div></div>
    <?php endif; ?>

    <div class="g g2">
      <?php /* Blockplan + Ferien - oeffentlich, ein Klick */ ?>
      <div class="c"><div class="bo">
        <div class="rw" style="gap:10px;align-items:flex-start">
          <span class="tile" style="background:#0f5fa8"><?= ic('plan', 18) ?></span>
          <div style="flex:1;min-width:0"><b>Blockwochen &amp; Ferien</b>
            <div class="sm mu2"><?php if ($blocks): ?><?= $blocks ?> Eintraege<?= $ferien ? ', Ferien geladen' : '' ?><?php else: ?>Aus dem oeffentlichen Plan der Schule - ohne Zugangsdaten.<?php endif; ?></div></div>
        </div>
        <form method="post" style="margin-top:11px"><?= csrf_field() ?><input type="hidden" name="a" value="block">
          <button class="<?= $blocks ? 'g s' : 'p' ?>" type="submit"<?= (int)$u['zeitgruppe'] < 1 ? ' disabled' : '' ?>><?= $blocks ? 'Aktualisieren' : 'Holen' ?></button>
          <?php if ((int)$u['zeitgruppe'] < 1): ?><span class="sm" style="margin-left:8px;color:var(--er)">Klasse ohne Zeitgruppe - im Profil ergaenzen.</span>
          <?php elseif (!$fs): ?><span class="sm mu2" style="margin-left:8px">Blockplan der BS FiSi Muenchen</span><?php endif; ?>
        </form>
      </div></div>

      <?php /* WebUntis - Stundenplan, braucht Zugangsdaten */ ?>
      <div class="c"><div class="bo">
        <div class="rw" style="gap:10px;align-items:flex-start">
          <span class="tile" style="background:#e8500e"><?= ic('raster', 18) ?></span>
          <div style="flex:1;min-width:0"><b>Stundenplan (WebUntis)</b>
            <div class="sm mu2"><?= $zeile($untis, 'Stunden', 'Dein Stundenplan direkt aus WebUntis.') ?></div></div>
        </div>
        <form method="post" style="margin-top:11px"><?= csrf_field() ?><input type="hidden" name="a" value="untis">
          <?php if (!$fs): ?>
            <div class="fg">
              <div class="f"><label for="us">Server</label><input id="us" name="server" value="<?= h($alt['server'] ?? $untis['server'] ?? '') ?>" placeholder="mese.webuntis.com"></div>
              <div class="f"><label for="uc">Schule</label><input id="uc" name="schule" value="<?= h($alt['schule'] ?? $untis['schule'] ?? '') ?>"></div>
            </div>
          <?php endif; ?>
          <div class="fg">
            <div class="f"><label for="ub">Benutzer</label><input id="ub" name="benutzer" value="<?= h($alt['benutzer'] ?? $untis['benutzer'] ?? '') ?>" autocomplete="off"></div>
            <div class="f"><label for="up">Passwort</label><input id="up" name="pw" type="password" autocomplete="new-password" placeholder="<?= !empty($untis['secret']) ? 'gespeichert - leer lassen zum Behalten' : '' ?>"></div>
          </div>
          <div class="rw"><button class="<?= $gut($untis) ? 'g s' : 'p' ?>" type="submit"><?= $untis ? 'Aktualisieren' : 'Verbinden' ?></button>
            <?php if ($untis): ?><input type="hidden" name="id" value="<?= (int)$untis['id'] ?>"><button class="g s d" name="a" value="trennen" type="submit" data-q="WebUntis trennen? Geladene Stunden verschwinden." formnovalidate>Trennen</button><?php endif; ?></div>
        </form>
      </div></div>

      <?php /* Moodle / mebis - Fristen und Termine */ ?>
      <div class="c"><div class="bo">
        <div class="rw" style="gap:10px;align-items:flex-start">
          <span class="tile" style="background:#f7931e"><?= ic('import', 18) ?></span>
          <div style="flex:1;min-width:0"><b>Moodle / mebis</b>
            <div class="sm mu2"><?= $zeile($moodle, 'Termine', 'Abgabefristen und Termine aus dem Kurskalender.') ?></div></div>
        </div>
        <form method="post" style="margin-top:11px"><?= csrf_field() ?><input type="hidden" name="a" value="moodle">
          <div class="f"><label for="mu">Kalender-Adresse</label>
            <input id="mu" name="url" value="<?= h($alt['url'] ?? '') ?>" placeholder="<?= $moodle ? 'leer lassen zum Behalten, neue Adresse zum Wechseln' : 'https://.../calendar/export_execute.php?...' ?>">
            <div class="sm mu2" style="margin-top:4px">In Moodle: Kalender &rsaquo; Kalender exportieren &rsaquo; Kalender-URL abfragen.</div></div>
          <div class="rw"><button class="<?= $gut($moodle) ? 'g s' : 'p' ?>" type="submit"><?= $moodle ? 'Aktualisieren' : 'Verbinden' ?></button>
            <?php if ($moodle): ?><input type="hidden" name="id" value="<?= (int)$moodle['id'] ?>"><button class="g s d" name="a" value="trennen" type="submit" data-q="Moodle trennen? Geladene Termine verschwinden." formnovalidate>Trennen</button><?php endif; ?></div>
        </form>
      </div></div>

      <?php /* weitere Kalender */ ?>
      <div class="c"><div class="bo">
        <div class="rw" style="gap:10px;align-items:flex-start">
          <span class="tile" style="background:#8e8e93"><?= ic('termin', 18) ?></span>
          <div style="flex:1;min-width:0"><b>Weiterer Kalender</b>
            <div class="sm mu2">Jede andere App mit iCal-Adresse - unter Quellen.</div></div>
        </div>
        <a class="bt g s" style="margin-top:11px" href="<?= url('einstellungen', ['t' => 'quellen']) ?>">Quellen oeffnen</a>
      </div></div>
    </div>
    <?php
    page('Einrichtung', ob_get_clean(), []);
}

/** Eine Quelle sofort abrufen und den Status setzen. */
function einr_sync(int $sid, array $u, int $timeout = 20): array {
    $src = one("SELECT * FROM sources WHERE id = ? AND user_id = ?", [$sid, (int)$u['id']]);
    if (!$src) return ['fehler' => 'Quelle nicht gefunden.', 'n' => 0];
    try { return quelle_sync($src, $u, $timeout); }
    catch (Throwable $ex) { quelle_status($sid, 'fehler', $ex->getMessage()); return ['fehler' => $ex->getMessage(), 'n' => 0]; }
}

function p_einstellungen(): void {
    $u = need_login(); $uid = (int)$u['id'];
    $t = get('t') ?: 'profil';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $a = post('a');
        if ($a === 'profil_schule') {
            $kl = mb_substr(strtoupper(preg_replace('/\s+/', '', post('klasse'))), 0, 20);
            $teile = klasse_teile($kl);
            $srv = preg_match('~^[a-z0-9.-]+\.webuntis\.com$~i', post('untis_server')) ? post('untis_server') : '';
            $d = ['schule' => mb_substr(post('schule'), 0, 120), 'untis_server' => $srv,
                'untis_schule' => mb_substr(preg_replace('/[^A-Za-z0-9._-]/', '', post('untis_schule')), 0, 60),
                'kl_kuerzel' => $teile['kuerzel'], 'kl_nr' => $teile['nr'], 'verkuerzt' => $teile['verkuerzt'],
                'zeitgruppe' => max(0, min(9, (int)$teile['zeitgruppe'])), 'klasse' => $kl,
                'beruf' => mb_substr(post('beruf'), 0, 100),
                'start' => isodate(post('start')) ? post('start') : null,
                'ende'  => isodate(post('ende'))  ? post('ende')  : null];
            // Die abgeleitete Stufe altert nur mit, wenn die Klasse gleich bleibt
            if ($kl !== (string)$u['klasse'] && $teile['stufe'] !== null) {
                $d['kl_stufe'] = max(1, min(3, (int)$teile['stufe']));
                $d['kl_stand'] = today();
            }
            $vorher = ausbildungsstand($u)['beginn'];
            upd('users', $d, 'id = :id', ['id' => $uid]);
            flash('Gespeichert.');
            $frisch = one("SELECT * FROM users WHERE id = ?", [$uid]);
            if (ausbildungsstand($frisch)['beginn'] !== $vorher) {
                $n = reports_jahr_nachziehen($frisch);
                if ($n) flash($n . ' Nachweise nachgerechnet.');
            }
        } elseif ($a === 'profil_betrieb') {
            upd('users', ['betrieb' => mb_substr(post('betrieb'), 0, 120),
                'abteilung' => mb_substr(post('abteilung'), 0, 80),
                'ausbilder' => mb_substr(post('ausbilder'), 0, 80),
                'bh_art' => post('bh_art') === 'monat' ? 'monat' : 'woche'], 'id = :id', ['id' => $uid]);
            flash('Gespeichert.');
        } elseif ($a === 'profil_druck') {
            $nm = mb_substr(post('dok_name'), 0, 80);
            upd('users', ['dok_name' => $nm,
                'dok_geb' => $nm !== '' && isodate(post('dok_geb')) ? post('dok_geb') : '',
                'dok_merken' => $nm !== '' ? 1 : 0], 'id = :id', ['id' => $uid]);
            unset($_SESSION['dok']);
            flash($nm === '' ? 'Name entfernt.' : 'Gespeichert.');
        } elseif ($a === 'qsave') {
            $id = (int)post('id', '0');
            $d = ['name' => mb_substr(post('name'), 0, 60) ?: 'Quelle',
                'typ' => in_array(post('typ'), ['webuntis','feiertage','moodle'], true) ? post('typ') : 'ics',
                'region' => isset(laender()[post('region')]) ? post('region') : 'DE-BY',
                'modus' => post('modus') === 'stundenplan' ? 'stundenplan' : 'termine',
                'url' => mb_substr(post('url'), 0, 500),
                'server' => mb_substr(post('server'), 0, 120), 'schule' => mb_substr(post('schule'), 0, 80),
                'benutzer' => mb_substr(post('benutzer'), 0, 80),
                'intervall' => max(30, min(10080, (int)post('intervall', '360'))),
                'aktiv' => post('aktiv') === '0' ? 0 : 1];
            $pw = (string)($_POST['pw'] ?? '');
            if ($pw !== '') {
                $c = verschluesseln($pw);
                if ($c === null) flash('Passwort nicht speicherbar: sodium fehlt.', 'err');
                else $d['secret'] = $c;
            }
            if ($d['typ'] === 'moodle') {
                $alt = $id ? one("SELECT url, secret FROM sources WHERE id = ? AND user_id = ?", [$id, $uid]) : null;
                // Steht im Feld nur die gekuerzte Anzeige-Adresse (ohne Token), bleibt die gespeicherte unveraendert
                $unveraendert = $alt && !empty($alt['secret']) && ($d['url'] === '' || $d['url'] === (string)$alt['url']);
                if ($unveraendert) {
                    $d['url'] = (string)$alt['url']; $d['modus'] = 'termine';
                    unset($d['secret']);   // vorhandenes Geheimnis nicht ueberschreiben
                } else {
                    $m = moodle_teile($d['url'] !== '' ? $d['url'] : (string)($_POST['url'] ?? ''));
                    if ($m['fehler'] !== '') { flash($m['fehler'], 'err'); redirect(url('einstellungen', ['t' => 'quellen'] + ($id ? ['id' => $id] : ['neu' => 1]))); }
                    $c = verschluesseln($m['voll']);
                    if ($c === null) { flash('Ohne die PHP-Extension sodium wird die Adresse nicht gespeichert.', 'err'); redirect(url('einstellungen', ['t' => 'quellen'])); }
                    $d['url'] = $m['anzeige'];   // ohne Token, damit er nirgends im Klartext steht
                    $d['secret'] = $c;
                    $d['modus'] = 'termine';
                }
            }
            if ($d['typ'] === 'ics' && !str_starts_with($d['url'], 'https://')) {
                flash('iCal-Adresse muss mit https:// beginnen.', 'err');
                redirect(url('einstellungen', ['t' => 'quellen'] + ($id ? ['id' => $id] : ['neu' => 1])));
            }
            if ($id) { upd('sources', $d, 'id = :id AND user_id = :u', ['id' => $id, 'u' => $uid]); flash('Gespeichert.'); }
            else { $d['user_id'] = $uid; $id = ins('sources', $d); flash('Quelle angelegt.'); }
            // Verbinden heisst laden: die frische Quelle gleich abrufen
            if ((int)($d['aktiv'] ?? 1) === 1 && in_array($d['typ'], ['webuntis','moodle','ics'], true)) {
                $r = einr_sync($id, $u, 15);
                if ($r['fehler'] !== '') flash($r['fehler'], 'err');
                elseif ($r['n'] > 0) flash($r['n'] . ' geladen.');
            }
            redirect(url('einstellungen', ['t' => 'quellen', 'id' => $id]));
        } elseif ($a === 'qsync') {
            $id = (int)post('id', '0');
            $src = one("SELECT * FROM sources WHERE id = ? AND user_id = ?", [$id, $uid]);
            if ($src) {
                try { $r = quelle_sync($src, $u); }
                catch (Throwable $ex) { $r = ['fehler' => $ex->getMessage(), 'n' => 0]; quelle_status($id, 'fehler', $ex->getMessage()); }
                flash($r['fehler'] !== '' ? $r['fehler'] : $r['n'] . ' Termine uebernommen.', $r['fehler'] !== '' ? 'err' : 'ok');
            }
        } elseif ($a === 'ferien') {
            $land = isset(laender()[post('region')]) ? post('region') : 'DE-BY';
            $sid = ins('sources', ['user_id' => $uid, 'name' => 'Ferien und Feiertage', 'typ' => 'feiertage',
                'modus' => 'termine', 'url' => '', 'region' => $land, 'intervall' => 10080, 'aktiv' => 1]);
            $r = feiertage_sync(one("SELECT * FROM sources WHERE id = ?", [$sid]), $u);
            flash($r['fehler'] !== '' ? $r['fehler'] : $r['n'] . ' Ferien und Feiertage uebernommen.',
                  $r['fehler'] !== '' ? 'err' : 'ok');
        } elseif ($a === 'qdel') {
            $id = (int)post('id', '0');
            del('events', 'user_id = ? AND quelle = ?', [$uid, 'q' . $id]);
            del('blocks', 'user_id = ? AND quelle = ?', [$uid, 'q' . $id]);
            del('sources', 'id = ? AND user_id = ?', [$id, $uid]);
            flash('Quelle geloescht.');
        } elseif ($a === 'ziel') {
            $url2 = mb_substr(post('url'), 0, 400);
            if (str_starts_with($url2, 'https://') && filter_var(str_replace('%s', 'x', $url2), FILTER_VALIDATE_URL)) {
                ins('ziele', ['user_id' => $uid, 'name' => mb_substr(post('name'), 0, 40) ?: 'App', 'url' => $url2,
                    'sort' => (int)val("SELECT COALESCE(MAX(sort),0)+10 FROM ziele WHERE user_id = ?", [$uid], 10)]);
            } else flash('Adresse muss mit https:// beginnen.', 'err');
        } elseif ($a === 'zieldel') {
            del('ziele', 'id = ? AND user_id = ?', [(int)post('id', '0'), $uid]);
        } elseif ($a === 'pw') {
            $alt = (string)($_POST['alt'] ?? ''); $neu = (string)($_POST['neu'] ?? '');
            if (!rl('pw:' . $uid, 10, 900)) flash('Zu viele Versuche.', 'err');
            elseif (!password_verify($alt, $u['pass_hash'])) flash('Aktuelles Passwort falsch.', 'err');
            elseif ($neu !== (string)($_POST['neu2'] ?? '')) flash('Passwoerter ungleich.', 'err');
            elseif ($p = pw_problems($neu, $u['username'])) flash('Passwort: ' . implode(', ', $p), 'err');
            else {
                upd('users', ['pass_hash' => pw_hash($neu), 'pw_changed' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $uid]);
                q("UPDATE sessions SET revoked = 1 WHERE user_id = ? AND sid_hash <> ?", [$uid, hash('sha256', session_id())]);
                flash('Passwort geaendert.');
            }
        } elseif ($a === '2fa_start') { $_SESSION['tfa'] = b32_encode(random_bytes(20)); }
        elseif ($a === '2fa_on') {
            $sec = (string)($_SESSION['tfa'] ?? '');
            if ($sec && totp_verify($sec, post('code'))) {
                $hash = []; $klar = [];
                for ($i = 0; $i < 8; $i++) { $c = rand_code(5); $klar[] = $c; $hash[] = password_hash($c, PASSWORD_DEFAULT); }
                upd('users', ['totp_secret' => $sec, 'totp_enabled' => 1, 'recovery' => json_encode($hash)], 'id = :id', ['id' => $uid]);
                unset($_SESSION['tfa']);
                $_SESSION['rc'] = $klar;
                flash('Zwei-Faktor aktiv.');
            } else flash('Code stimmt nicht.', 'err');
        } elseif ($a === '2fa_off') {
            if (password_verify((string)($_POST['pw'] ?? ''), $u['pass_hash'])) {
                upd('users', ['totp_enabled' => 0, 'totp_secret' => null, 'recovery' => null], 'id = :id', ['id' => $uid]);
                flash('Zwei-Faktor aus.', 'warn');
            } else flash('Passwort falsch.', 'err');
        } elseif ($a === 'sess') {
            q("UPDATE sessions SET revoked = 1 WHERE user_id = ? AND sid_hash <> ?", [$uid, hash('sha256', session_id())]);
            flash('Andere Sitzungen beendet.');
        } elseif ($a === 'ics') {
            upd('users', ['ics_token' => bin2hex(random_bytes(16))], 'id = :id', ['id' => $uid]);
            flash('Neue Kalenderadresse.');
        } elseif ($a === 'konto_weg') {
            if (password_verify((string)($_POST['pw'] ?? ''), $u['pass_hash']) && post('sicher') === 'LOESCHEN') {
                del('users', 'id = ?', [$uid]);
                logout('Konto geloescht.');
            } else flash('Passwort oder Bestaetigung falsch.', 'err');
        }
        redirect(url('einstellungen', ['t' => post('t') ?: $t]));
    }
    $such = mb_substr(get('such'), 0, 80);
    $rc = $_SESSION['rc'] ?? null; unset($_SESSION['rc']);
    $sec = $_SESSION['tfa'] ?? null;
    ob_start(); ?>
    <?php if ($t === 'profil'):
      $sfehler = ''; $treffer = null;
      if ($such !== '') {
          if (!rl('schulsuche:' . client_ip(), 40, 600)) $sfehler = 'Zu viele Anfragen.';
          else { $sr = untis_schulsuche($such); $sfehler = $sr['fehler']; $treffer = $sr['schulen']; }
      } ?>
      <div class="g g2">
        <div class="c"><div class="hd"><h2>Schule</h2></div><div class="bo">
          <form method="get" class="line" style="margin-bottom:10px">
            <input type="hidden" name="p" value="einstellungen"><input type="hidden" name="t" value="profil">
            <input name="such" value="<?= h($such) ?>" placeholder="Schule suchen" autocomplete="off" style="flex:1;min-width:0">
            <button type="submit" style="flex:none">Suchen</button>
          </form>
          <?php if ($sfehler !== ''): ?><div class="ms err"><?= h($sfehler) ?></div><?php endif; ?>
          <?php if ($treffer !== null): ?>
            <?php if (!$treffer): ?><div class="sm mu2" style="margin-bottom:10px">Nichts gefunden.</div>
            <?php else: ?>
              <ul class="li rows" style="margin-bottom:10px">
                <?php foreach (array_slice($treffer, 0, 8) as $sc): ?>
                  <li><a href="<?= h(url('einstellungen', ['t' => 'profil', 'us' => $sc['server'], 'uk' => $sc['schule']])) ?>">
                    <b><?= h($sc['name']) ?></b> <span class="sm mu2"><?= h($sc['ort']) ?></span></a></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          <?php endif; ?>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="a" value="profil_schule"><input type="hidden" name="t" value="profil">
            <?= schul_felder(['schule' => $u['schule'], 'klasse' => $u['klasse'],
                'untis_server' => get('us') ?: $u['untis_server'],
                'untis_schule' => get('uk') ?: $u['untis_schule']]) ?>
            <div class="fg">
              <div class="f"><label for="beg">Ausbildungsbeginn</label>
                <input id="beg" name="start" type="date" value="<?= h((string)$u['start']) ?>">
                <?php if (empty($u['start'])): ?><div class="sm mu2" style="margin-top:4px">wird sonst aus der Klasse abgeleitet</div><?php endif; ?></div>
              <div class="f"><label for="en">Ausbildungsende</label><input id="en" name="ende" type="date" value="<?= h((string)$u['ende']) ?>"></div>
            </div>
            <div class="f"><label for="br">Beruf</label><input id="br" name="beruf" value="<?= h($u['beruf']) ?>"></div>
            <button class="p" type="submit">Speichern</button>
          </form>
        </div></div>

        <div>
          <div class="c"><div class="hd"><h2>Betrieb</h2></div><div class="bo">
            <form method="post">
              <?= csrf_field() ?><input type="hidden" name="a" value="profil_betrieb"><input type="hidden" name="t" value="profil">
              <div class="f"><label for="bt">Betrieb</label><input id="bt" name="betrieb" value="<?= h($u['betrieb']) ?>"></div>
              <div class="fg">
                <div class="f"><label for="ab">Abteilung</label><input id="ab" name="abteilung" value="<?= h($u['abteilung']) ?>"
                  placeholder="<?= h(einsatz_am($uid, today())) ?>"></div>
                <div class="f"><label for="au">Ausbilder/-in</label><input id="au" name="ausbilder" value="<?= h($u['ausbilder']) ?>"></div>
              </div>
              <div class="f"><label for="bh">Nachweis</label><select id="bh" name="bh_art"><?= optm(['woche'=>'woechentlich','monat'=>'monatlich'], $u['bh_art']) ?></select></div>
              <button class="p" type="submit">Speichern</button>
            </form>
          </div></div>

          <div class="c"><div class="hd"><h2>Fuer Ausdrucke</h2></div><div class="bo">
            <form method="post">
              <?= csrf_field() ?><input type="hidden" name="a" value="profil_druck"><input type="hidden" name="t" value="profil">
              <div class="fg">
                <div class="f"><label for="dn">Vor- und Nachname</label>
                  <input id="dn" name="dok_name" value="<?= h($u['dok_name']) ?>" autocomplete="off"></div>
                <div class="f"><label for="dg">Geburtsdatum</label>
                  <input id="dg" name="dok_geb" type="date" value="<?= h($u['dok_geb']) ?>"></div>
              </div>
              <div class="sm mu2" style="margin-bottom:9px">Leer lassen, dann fragt der Ausdruck danach und behaelt nichts.</div>
              <button class="p" type="submit">Speichern</button>
            </form>
          </div></div>
        </div>
      </div>

    <?php elseif ($t === 'quellen'):
      $quellen = all("SELECT * FROM sources WHERE user_id = ? ORDER BY id", [$uid]);
      $e = get('id') !== '' ? one("SELECT * FROM sources WHERE id = ? AND user_id = ?", [(int)get('id'), $uid]) : null;
      $neuQ = get('neu') !== '';
      $kann2 = app_key() !== null; ?>
      <div class="sp2">
        <div>
          <div class="c"><div class="hd"><h2>Kalender und Stundenplan</h2><span class="sp"></span>
            <?php if (!array_filter($quellen, fn($x) => $x['typ'] === 'feiertage')): ?>
              <form method="post" style="display:inline"><?= csrf_field() ?>
                <input type="hidden" name="a" value="ferien"><input type="hidden" name="t" value="quellen">
                <select name="region" style="height:24px;font-size:12.5px;width:150px"><?= optm(laender(), 'DE-BY') ?></select>
                <button class="s" type="submit">Ferien holen</button></form>
            <?php endif; ?>
            <a class="bt p s" data-new href="<?= url('einstellungen', ['t' => 'quellen', 'neu' => 1]) ?>">Neu</a></div>
            <?php if (!$quellen): ?><?= em('Keine Quelle eingerichtet.') ?><?php else: ?>
            <div class="tw"><table><tbody>
              <?php foreach ($quellen as $s2): ?>
                <tr><td style="width:14px"><span class="dot" style="background:<?= $s2['status'] === 'fehler' ? 'var(--er)' : ($s2['status'] === 'ok' ? 'var(--ok)' : 'var(--fg3)') ?>"></span></td>
                  <td><a href="<?= url('einstellungen', ['t' => 'quellen', 'id' => $s2['id']]) ?>"><?= h($s2['name']) ?></a>
                    <div class="sm mu2"><?= h(['webuntis'=>'WebUntis','feiertage'=>'Ferien und Feiertage','moodle'=>'Moodle / mebis'][$s2['typ']] ?? 'iCal') ?>
                      <?= $s2['typ'] === 'feiertage' ? '· ' . h(laender()[$s2['region']] ?? '') : '· ' . h($s2['modus'] === 'stundenplan' ? 'Stundenplan' : 'Termine') ?>
                      <?= $s2['meldung'] ? ' · ' . h($s2['meldung']) : '' ?></div></td>
                  <td class="sm mu2 mo" style="width:110px"><?= $s2['letzter_sync'] ? h(date('d.m.y H:i', (int)$s2['letzter_sync'])) : 'nie' ?></td>
                  <td style="width:96px">
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="qsync">
                      <input type="hidden" name="t" value="quellen"><input type="hidden" name="id" value="<?= (int)$s2['id'] ?>">
                      <button class="s" type="submit">Abrufen</button></form></td></tr>
              <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
          </div>
          <div class="c"><div class="hd"><h2>Schul-Apps</h2></div>
            <?php $ziele = all("SELECT * FROM ziele WHERE user_id = ? ORDER BY sort, id", [$uid]);
            if ($ziele): ?>
            <div class="tw"><table><tbody>
              <?php foreach ($ziele as $zl): ?>
                <tr><td style="width:120px"><?= h($zl['name']) ?></td>
                  <td class="sm mu2 mo" style="word-break:break-all"><?= h($zl['url']) ?></td>
                  <td style="width:30px"><form method="post"><?= csrf_field() ?>
                    <input type="hidden" name="a" value="zieldel"><input type="hidden" name="t" value="quellen">
                    <input type="hidden" name="id" value="<?= (int)$zl['id'] ?>">
                    <button class="g s d" type="submit">&times;</button></form></td></tr>
              <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
            <div class="bo">
              <form method="post" class="line">
                <?= csrf_field() ?><input type="hidden" name="a" value="ziel"><input type="hidden" name="t" value="quellen">
                <input name="name" placeholder="Moodle" style="width:150px;flex:none" required>
                <input name="url" placeholder="https://lernplattform.mebis.bycs.de/" style="flex:1;min-width:0" required>
                <button type="submit">Hinzufuegen</button>
              </form>
              <div class="sm mu2" style="margin-top:6px">Steht ein <code>%s</code> darin, wird es durch den Suchbegriff ersetzt.</div>
            </div>
          </div>
        </div>
        <div class="c"><div class="hd"><h2><?= $e ? 'Quelle' : 'Neue Quelle' ?></h2></div><div class="bo">
          <?php if ($e || $neuQ): ?>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="a" value="qsave"><input type="hidden" name="t" value="quellen">
            <input type="hidden" name="id" value="<?= (int)($e['id'] ?? 0) ?>">
            <div class="f"><label for="qn">Name</label><input id="qn" name="name" required value="<?= h($e['name'] ?? '') ?>"></div>
            <div class="fg">
              <div class="f"><label for="qt">Art</label><select id="qt" name="typ"><?= optm(
                ['ics'=>'iCal-Adresse','moodle'=>'Moodle / mebis','webuntis'=>'WebUntis','feiertage'=>'Ferien und Feiertage'],
                $e['typ'] ?? 'ics') ?></select></div>
              <div class="f"><label for="qm">Ziel</label><select id="qm" name="modus"><?= optm(['termine'=>'Termine','stundenplan'=>'Stundenplan'], $e['modus'] ?? 'termine') ?></select></div>
            </div>
            <div class="f"><label for="qu">iCal-Adresse</label>
              <input id="qu" name="url" value="<?= h($e['url'] ?? '') ?>" placeholder="https://.../Ical.do?school=...&amp;id=...&amp;token=...">
              <div class="sm mu2" style="margin-top:4px">Moodle: Kalender &rsaquo; Kalender exportieren &rsaquo; Kalender-URL abfragen.</div></div>
            <div class="f"><label for="qr">Bundesland <span class="mu sm">fuer Ferien und Feiertage</span></label>
              <select id="qr" name="region"><?= optm(laender(), $e['region'] ?? 'DE-BY') ?></select></div>
            <fieldset style="border:1px solid var(--li);border-radius:var(--r2);padding:10px;margin:0 0 9px">
              <legend class="sm mu2" style="padding:0 4px">WebUntis</legend>
              <div class="fg">
                <?php $fs = strtoupper((string)$u['kl_kuerzel']) === 'FS'; ?>
                <div class="f"><label for="qs">Server</label><input id="qs" name="server"
                  value="<?= h($e['server'] ?? ($u['untis_server'] ?: ($fs ? UNTIS_SERVER_FS : ''))) ?>" placeholder="mese.webuntis.com"></div>
                <div class="f"><label for="qc">Schule</label><input id="qc" name="schule"
                  value="<?= h($e['schule'] ?? ($u['untis_schule'] ?: ($fs ? UNTIS_SCHULE_FS : ''))) ?>"></div>
              </div>
              <div class="fg">
                <div class="f"><label for="qb">Benutzer</label><input id="qb" name="benutzer" value="<?= h($e['benutzer'] ?? '') ?>" autocomplete="off"></div>
                <div class="f"><label for="qp">Passwort</label><input id="qp" name="pw" type="password" autocomplete="new-password" placeholder="<?= !empty($e['secret']) ? 'gespeichert' : '' ?>"></div>
              </div>
              <?php if (!$kann2): ?><div class="ms warn">Ohne die PHP-Extension <code>sodium</code> wird kein Passwort gespeichert.</div><?php endif; ?>
            </fieldset>
            <div class="fg">
              <div class="f"><label for="qi">Abrufen alle (Min.)</label><input id="qi" name="intervall" type="number" min="30" max="10080" value="<?= (int)($e['intervall'] ?? 360) ?>"></div>
              <div class="f"><label for="qa">Aktiv</label><select id="qa" name="aktiv"><?= optm([1=>'ja',0=>'nein'], $e['aktiv'] ?? 1) ?></select></div>
            </div>
            <div class="rw"><button class="p" type="submit">Speichern</button>
              <a class="bt g" href="<?= url('einstellungen', ['t' => 'quellen']) ?>">Schliessen</a></div>
          </form>
          <?php if ($e): ?>
            <hr>
            <?php if ($e['meldung']): ?><div class="ms <?= $e['status'] === 'fehler' ? 'err' : 'ok' ?>"><?= h($e['meldung']) ?></div><?php endif; ?>
            <?php if ($e['typ'] === 'webuntis' && !empty($e['secret'])): ?>
              <button type="button" class="s" data-klassen="#kls" data-id="<?= (int)$e['id'] ?>">Klassen abrufen</button>
              <div id="kls" class="sm mu2" style="margin-top:6px"></div>
              <hr>
            <?php endif; ?>
            <form method="post" data-q="Quelle und ihre importierten Termine loeschen?">
              <?= csrf_field() ?><input type="hidden" name="a" value="qdel"><input type="hidden" name="t" value="quellen">
              <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
              <button class="d s" type="submit">Loeschen</button></form>
          <?php endif; ?>
          <?php else: ?>
            <?php if ($u['untis_schule'] !== ''): ?>
              <div class="ms info">Aus dem Profil bekannt: <b><?= h($u['untis_schule']) ?></b> auf <?= h($u['untis_server']) ?>.
                Eine neue WebUntis-Quelle wird damit vorbelegt.</div>
            <?php endif; ?>
            <div class="sm mu">WebUntis, Moodle und mebis liefern jeweils eine persoenliche iCal-Adresse.
              Der Blockplan der Schule laeuft ueber <a href="<?= url('plan', ['t' => 'block']) ?>">Plan &rsaquo; Blockplan</a>.</div>
          <?php endif; ?>
        </div></div>
      </div>

    <?php elseif ($t === 'sicherheit'):
      $ss = all("SELECT * FROM sessions WHERE user_id = ? AND revoked = 0 ORDER BY last_seen DESC", [$uid]);
      $la = all("SELECT * FROM login_attempts WHERE ident = ? ORDER BY ts DESC LIMIT 10", [$u['username']]); ?>
      <?php if ($rc): ?>
        <div class="c"><div class="hd"><h2>Wiederherstellungscodes</h2><span class="sp"></span>
          <button class="s" data-copy="rc" type="button">Kopieren</button></div><div class="bo">
          <pre id="rc"><?= h(implode("\n", $rc)) ?></pre>
          <div class="sm mu">Jeder Code funktioniert einmal. Wird nur jetzt angezeigt.</div>
        </div></div>
      <?php endif; ?>
      <div class="g g2">
        <div class="c"><div class="hd"><h2>Passwort</h2></div><div class="bo">
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="a" value="pw"><input type="hidden" name="t" value="sicherheit">
            <div class="f"><label for="pa2">Aktuell</label><input id="pa2" name="alt" type="password" required autocomplete="current-password"></div>
            <div class="f"><label for="pn">Neu</label><input id="pn" name="neu" type="password" required autocomplete="new-password"></div>
            <div class="f"><label for="pn2">Wiederholen</label><input id="pn2" name="neu2" type="password" required autocomplete="new-password"></div>
            <button class="p" type="submit">Aendern</button>
          </form>
        </div></div>
        <div class="c"><div class="hd"><h2>Zwei-Faktor</h2></div><div class="bo">
          <?php if ((int)$u['totp_enabled'] === 1): ?>
            <p><span class="tg o">aktiv</span> <span class="sm mu"><?= count(json_decode((string)$u['recovery'], true) ?: []) ?> Codes uebrig</span></p>
            <form method="post" data-q="Zwei-Faktor abschalten?">
              <?= csrf_field() ?><input type="hidden" name="a" value="2fa_off"><input type="hidden" name="t" value="sicherheit">
              <div class="f"><label for="op">Passwort</label><input id="op" name="pw" type="password" required></div>
              <button class="d" type="submit">Deaktivieren</button></form>
          <?php elseif ($sec):
            $uri = 'otpauth://totp/' . rawurlencode(APP_NAME . ':' . $u['username']) . '?secret=' . $sec
                 . '&issuer=' . rawurlencode(APP_NAME) . '&algorithm=SHA1&digits=6&period=30'; ?>
            <div class="rw" style="align-items:flex-start;gap:12px">
              <div style="background:#fff;padding:6px;border-radius:5px"><?= QR::svg($uri, 3) ?></div>
              <div style="flex:1;min-width:170px">
                <pre id="sc2" style="word-break:break-all;white-space:pre-wrap;font-size:11px"><?= h(implode(' ', str_split($sec, 4))) ?></pre>
                <button class="s" data-copy="sc2" type="button">Kopieren</button>
                <form method="post" style="margin-top:8px">
                  <?= csrf_field() ?><input type="hidden" name="a" value="2fa_on"><input type="hidden" name="t" value="sicherheit">
                  <div class="f"><label for="cd2">Code</label><input id="cd2" name="code" class="mo" inputmode="numeric" required></div>
                  <button class="p" type="submit">Aktivieren</button></form>
              </div>
            </div>
          <?php else: ?>
            <form method="post"><?= csrf_field() ?>
              <input type="hidden" name="a" value="2fa_start"><input type="hidden" name="t" value="sicherheit">
              <button class="p" type="submit">Einrichten</button></form>
          <?php endif; ?>
        </div></div>
        <div class="c"><div class="hd"><h2>Sitzungen</h2></div><div class="bo p0">
          <div class="tw"><table><tbody>
            <?php foreach ($ss as $s2): $ich = hash_equals($s2['sid_hash'], hash('sha256', session_id())); ?>
              <tr><td class="mo sm"><?= h(date('d.m.y H:i', (int)$s2['created_at'])) ?></td>
                <td class="mo sm mu2"><?= h($s2['ip']) ?></td>
                <td class="sm"><?= $ich ? '<span class="tg o">diese</span>' : '' ?></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <div class="bo"><form method="post" data-q="Andere Sitzungen beenden?"><?= csrf_field() ?>
            <input type="hidden" name="a" value="sess"><input type="hidden" name="t" value="sicherheit">
            <button type="submit">Andere abmelden</button></form></div>
        </div></div>
        <div class="c"><div class="hd"><h2>Letzte Anmeldungen</h2></div><div class="bo p0">
          <div class="tw"><table><tbody>
            <?php foreach ($la as $l): ?>
              <tr><td class="mo sm"><?= h(date('d.m.y H:i', (int)$l['ts'])) ?></td>
                <td class="mo sm mu2"><?= h($l['ip']) ?></td>
                <td><span class="tg <?= (int)$l['ok'] ? 'o' : 'e' ?>"><?= (int)$l['ok'] ? 'ok' : 'fehl' ?></span></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </div></div>
      </div>

    <?php else: ?>
      <div class="g g2">
        <div class="c"><div class="hd"><h2>Export</h2></div><div class="bo p0">
          <ul class="li">
            <li><a href="<?= url('export', ['w' => 'alles']) ?>">Alles (JSON)</a></li>
            <li><a href="<?= url('export', ['w' => 'bh']) ?>">Berichtsheft (CSV)</a></li>
            <li><a href="<?= url('export', ['w' => 'noten']) ?>">Noten (CSV)</a></li>
            <?php if (val("SELECT 1 FROM routine_logs WHERE user_id = ?", [$uid])): ?>
              <li><a href="<?= url('export', ['w' => 'routinen']) ?>">Routine-Protokoll (CSV)</a></li>
            <?php endif; ?>
            <li><a href="<?= url('export', ['w' => 'notizen']) ?>">Notizen (CSV)</a></li>
          </ul>
        </div></div>
        <div class="c"><div class="hd"><h2>Geteilte Links</h2><span class="sp"></span>
          <span class="sm mu2"><?= (int)val("SELECT COUNT(*) FROM shares WHERE user_id = ?", [$uid], 0) ?> aktiv</span></div><div class="bo">
          <p class="sm mu">Notizen, Faecher und Nachweise, die ueber eine eigene Adresse erreichbar sind.</p>
          <a class="bt s" href="<?= url('geteilt') ?>">Verwalten</a>
        </div></div>
        <div class="c"><div class="hd"><h2>Kalender</h2></div><div class="bo">
          <pre id="ic" style="white-space:pre-wrap;word-break:break-all;font-size:11px"><?= h(abs_url(url('ics', ['t' => $u['ics_token'] ?: '-']))) ?></pre>
          <div class="rw"><button class="s" data-copy="ic" type="button">Kopieren</button>
            <form method="post" data-q="Neue Adresse? Alte Abos brechen ab."><?= csrf_field() ?>
              <input type="hidden" name="a" value="ics"><input type="hidden" name="t" value="daten">
              <button class="s" type="submit">Erneuern</button></form></div>
        </div></div>
        <div class="c"><div class="hd"><h2>System</h2></div><div class="bo p0">
          <div class="tw"><table><tbody>
            <tr><th>PHP</th><td class="mo sm"><?= h(PHP_VERSION) ?></td></tr>
            <tr><th>SQLite</th><td class="mo sm"><?= h((string)db()->getAttribute(PDO::ATTR_SERVER_VERSION)) ?></td></tr>
            <tr><th>Hash</th><td class="mo sm"><?= defined('PASSWORD_ARGON2ID') ? 'Argon2id' : 'bcrypt' ?></td></tr>
            <tr><th>HTTPS</th><td><?= is_https() ? '<span class="tg o">ja</span>' : '<span class="tg e">nein</span>' ?></td></tr>
            <tr><th>Daten</th><td class="mo sm"><?= h(DATA_DIR) ?></td></tr>
          </tbody></table></div>
        </div></div>
        <div class="c"><div class="hd"><h2>Konto loeschen</h2></div><div class="bo">
          <form method="post" data-q="Konto und alle Daten unwiderruflich loeschen?">
            <?= csrf_field() ?><input type="hidden" name="a" value="konto_weg"><input type="hidden" name="t" value="daten">
            <div class="f"><label for="kp">Passwort</label><input id="kp" name="pw" type="password" required></div>
            <div class="f"><label for="ks">LOESCHEN eintippen</label><input id="ks" name="sicher" required></div>
            <button class="d" type="submit">Endgueltig loeschen</button>
          </form>
        </div></div>
      </div>
    <?php endif; ?>
    <?php
    page('Einstellungen', ob_get_clean(), ['unter' => h($u['username']) . ' · ' . stand_text($u)]);
}

// --- Geteilte Links --------------------------------------------------------
// Der einzige Weg, auf dem Inhalte dieses Kontos jemand anderen erreichen.
function share_arten(): array {
    return ['notiz' => 'Notiz', 'fach' => 'Fach', 'bericht' => 'Ausbildungsnachweis'];
}
function share_url(string $token): string { return abs_url(url('geteilt', ['t' => $token])); }
function share_of(int $uid, string $art, int $ref): ?array {
    return one("SELECT * FROM shares WHERE user_id = ? AND art = ? AND ref = ?", [$uid, $art, $ref]);
}
/** Knopf zum Teilen, ueberall gleich. */
function share_box(int $uid, string $art, int $ref, string $titel, string $zurueck): string {
    $sh = share_of($uid, $art, $ref);
    ob_start(); ?>
    <form method="post" action="<?= url('geteilt') ?>" class="rw np" style="gap:8px;flex-wrap:wrap">
      <?= csrf_field() ?>
      <input type="hidden" name="art" value="<?= h($art) ?>"><input type="hidden" name="ref" value="<?= (int)$ref ?>">
      <input type="hidden" name="titel" value="<?= h($titel) ?>"><input type="hidden" name="zu" value="<?= h($zurueck) ?>">
      <?php if ($sh): ?>
        <input class="mo sm" readonly value="<?= h(share_url($sh['token'])) ?>" style="flex:1;min-width:180px"
               data-sel>
        <button class="s" type="button" data-copy-val="<?= h(share_url($sh['token'])) ?>">Kopieren</button>
        <button class="s g d" name="a" value="weg" type="submit">Link aufheben</button>
        <span class="sm mu2"><?= $sh['sichtbar'] === 'konten' ? h($sh['wer'] ?: 'nur angemeldete') : 'jeder mit Link' ?>
          · <?= (int)$sh['aufrufe'] ?>&times; geoeffnet</span>
      <?php else: ?>
        <button class="s" name="a" value="neu" type="submit">Link erstellen</button>
        <input name="wer" placeholder="nur diese Benutzernamen, mit Komma" style="flex:1;min-width:150px">
        <?php if ($art === 'bericht'): ?>
          <label class="ck"><input type="checkbox" name="name_zeigen" value="1"> Name darauf</label>
        <?php endif; ?>
      <?php endif; ?>
    </form>
    <?php
    return ob_get_clean();
}
/** Verwaltung und oeffentliche Ansicht liegen auf derselben Adresse. */
function p_geteilt(): void {
    if (get('t') !== '') { share_zeigen(get('t')); return; }
    $u = need_login(); $uid = (int)$u['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $art = post('art'); $ref = (int)post('ref', '0'); $a = post('a');
        $zu  = post('zu');
        $zurueck = str_starts_with($zu, base_path()) && !str_contains($zu, "\n") ? $zu : url('geteilt');
        if (!isset(share_arten()[$art]) || $ref <= 0) { redirect(url('geteilt')); }
        if ($a === 'weg') {
            del('shares', 'user_id = ? AND art = ? AND ref = ?', [$uid, $art, $ref]);
            flash('Link aufgehoben.', 'warn');
        } elseif ($a === 'neu' && !share_of($uid, $art, $ref)) {
            $wer = [];
            foreach (preg_split('/[\s,;]+/', post('wer')) as $w) {
                $w = preg_replace('/[^A-Za-z0-9._-]/', '', $w);
                if ($w !== '') $wer[] = mb_strtolower($w);
            }
            $wer = array_slice(array_unique($wer), 0, 20);
            ins('shares', ['user_id' => $uid, 'token' => bin2hex(random_bytes(16)), 'art' => $art, 'ref' => $ref,
                'titel' => mb_substr(post('titel'), 0, 160),
                'wer' => implode(',', $wer),
                'name_zeigen' => post('name_zeigen') !== '' ? 1 : 0,
                'sichtbar' => $wer ? 'konten' : 'link']);
            flash($wer ? 'Link fuer ' . count($wer) . ' Konten erstellt.' : 'Link erstellt.');
        }
        redirect($zurueck);
    }
    $rows = all("SELECT * FROM shares WHERE user_id = ? ORDER BY id DESC", [$uid]);
    ob_start(); ?>
    <div class="c">
      <div class="hd"><h2>Geteilte Links</h2><span class="sp"></span>
        <span class="sm mu2"><?= count($rows) ?> aktiv</span></div>
      <?php if (!$rows): ?><div class="bo"><?= em('Noch nichts geteilt. Der Knopf dazu steht bei der Notiz, beim Fach und beim Nachweis.') ?></div>
      <?php else: ?>
      <ul class="li rows">
        <?php foreach ($rows as $r):
          $sym = ['notiz' => 'notizen', 'fach' => 'faecher', 'bericht' => 'bericht'][$r['art']] ?? 'teilen';
          $wer = $r['sichtbar'] === 'konten' ? ($r['wer'] ?: 'nur angemeldete') : 'jeder mit Link';
          $neben = [share_arten()[$r['art']] ?? $r['art'], $wer, (int)$r['aufrufe'] . '&times; geoeffnet']; ?>
          <li style="flex-wrap:wrap">
            <span class="tile t-<?= h($sym) ?>"><?= ic($sym, 17) ?></span>
            <span class="tx"><b><a href="<?= h(share_url($r['token'])) ?>"><?= h($r['titel'] ?: '(ohne Titel)') ?></a></b>
              <span class="sm mu2"><?= implode(' · ', array_map('h', array_slice($neben, 0, 2))) ?> · <?= $neben[2] ?></span></span>
            <span class="rw" style="flex:none;gap:6px">
              <button class="s" type="button" data-copy-val="<?= h(share_url($r['token'])) ?>">Kopieren</button>
              <form method="post"><?= csrf_field() ?>
                <input type="hidden" name="art" value="<?= h($r['art']) ?>"><input type="hidden" name="ref" value="<?= (int)$r['ref'] ?>">
                <button class="s g d" name="a" value="weg" type="submit">Aufheben</button></form>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
    <?php
    page('Geteilt', ob_get_clean(), ['unter' => count($rows) . ' ' . (count($rows) === 1 ? 'Link' : 'Links')]);
}
function share_404(): void {
    http_response_code(404);
    page('Nicht gefunden', '<div class="bo">Diesen Link gibt es nicht mehr.</div>', ['bare' => true]);
    exit;
}
function share_zeigen(string $token): void {
    header('X-Robots-Tag: noindex, nofollow');
    if (!preg_match('/^[0-9a-f]{32}$/', $token) || !rl('share:' . client_ip(), 120, 600)) share_404();
    $sh = one("SELECT * FROM shares WHERE token = ?", [$token]);
    if (!$sh) share_404();
    if ($sh['ablauf'] && $sh['ablauf'] < today()) share_404();
    if ($sh['sichtbar'] === 'konten') {
        $ich = me();
        if (!$ich) {
            $_SESSION['nach'] = url('geteilt', ['t' => $token]);
            page('Anmeldung noetig', '<div class="bo"><h2>Nur fuer bestimmte Konten</h2>'
                . '<p class="mu sm">Dieser Link ist nicht oeffentlich.</p>'
                . '<a class="bt p" href="' . url('login') . '">Anmelden</a></div>', ['bare' => true]);
            exit;
        }
        $erlaubt = array_filter(explode(',', (string)($sh['wer'] ?? '')));
        if ($erlaubt && (int)$ich['id'] !== (int)$sh['user_id']
            && !in_array(mb_strtolower($ich['username']), $erlaubt, true)) share_404();
    }
    $uid = (int)$sh['user_id'];
    $besitzer = one("SELECT * FROM users WHERE id = ?", [$uid]);
    if (!$besitzer) share_404();
    if (get('f') !== '') { share_datei($sh, (int)get('f')); }
    q("UPDATE shares SET aufrufe = aufrufe + 1 WHERE id = ?", [(int)$sh['id']]);

    ob_start();
    if ($sh['art'] === 'notiz')        share_notiz($sh, $uid);
    elseif ($sh['art'] === 'fach')     share_fach($sh, $uid);
    elseif ($sh['art'] === 'bericht')  share_bericht($sh, $uid, $besitzer);
    else share_404();
    $inhalt = ob_get_clean();
    if ($inhalt === '') share_404();
    ob_start(); ?>
    <div class="c">
      <div class="hd"><span class="tg"><?= h(share_arten()[$sh['art']] ?? '') ?></span>
        <span class="sp"></span>
        <span class="sm mu2">geteilt von <?= h($besitzer['username']) ?> · <?= h(dt($sh['created_at'])) ?></span></div>
      <div class="bo"><?= $inhalt ?></div>
    </div>
    <p class="sm mu2" style="text-align:center;margin-top:14px">Nur-Lesen-Ansicht aus <?= h(APP_NAME) ?>.</p>
    <?php
    page($sh['titel'] ?: 'Geteilt', ob_get_clean(), ['bare' => true, 'weit' => true]);
    exit;
}
function share_datei(array $sh, int $fid): void {
    $ok = false;
    if ($sh['art'] === 'notiz') $ok = (bool)one("SELECT id FROM files WHERE id = ? AND user_id = ? AND scope='note' AND scope_id = ?",
        [$fid, (int)$sh['user_id'], (int)$sh['ref']]);
    if ($sh['art'] === 'fach') $ok = (bool)one("SELECT fi.id FROM files fi JOIN notes n ON n.id = fi.scope_id
        WHERE fi.id = ? AND fi.user_id = ? AND fi.scope = 'note' AND n.subject_id = ?",
        [$fid, (int)$sh['user_id'], (int)$sh['ref']]);
    if (!$ok) share_404();
    $f = one("SELECT * FROM files WHERE id = ?", [$fid]);
    header('Content-Type: ' . $f['mime']);
    header('Content-Length: ' . strlen($f['daten']));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^\x20-\x7e]/', '_', $f['name']) . '"');
    echo $f['daten'];
    exit;
}
function share_notiz(array $sh, int $uid): void {
    $n = one("SELECT n.*, s.name AS fach FROM notes n LEFT JOIN subjects s ON s.id = n.subject_id
              WHERE n.id = ? AND n.user_id = ?", [(int)$sh['ref'], $uid]);
    if (!$n) return;
    ?>
    <h1 style="font-size:20px;margin-bottom:4px"><?= h($n['titel'] ?: 'Notiz') ?></h1>
    <p class="sm mu2"><?= h(dt($n['datum'])) ?><?= $n['fach'] ? ' · ' . h($n['fach']) : '' ?>
      <?= $n['lf_no'] ? ' · LF' . (int)$n['lf_no'] : '' ?><?= $n['tags'] ? ' · ' . h($n['tags']) : '' ?></p>
    <?php if ($n['kind'] === 'snippet'): ?>
      <pre id="snip"><?= h($n['body']) ?></pre>
      <button class="s" type="button" data-copy="snip">Code kopieren</button>
    <?php else: ?>
      <div><?= md($n['body']) ?></div>
    <?php endif;
    $files = all("SELECT id, name, groesse FROM files WHERE scope='note' AND scope_id = ? AND user_id = ?", [(int)$n['id'], $uid]);
    if ($files): ?>
      <hr><ul class="li"><?php foreach ($files as $f): ?>
        <li style="padding:4px 0"><a href="<?= url('geteilt', ['t' => $sh['token'], 'f' => $f['id']]) ?>"><?= h($f['name']) ?></a>
          <span class="sm mu2 mo"><?= num($f['groesse'] / 1024, 0) ?> kB</span></li>
      <?php endforeach; ?></ul>
    <?php endif;
}
function share_fach(array $sh, int $uid): void {
    $f = one("SELECT * FROM subjects WHERE id = ? AND user_id = ?", [(int)$sh['ref'], $uid]);
    if (!$f) return;
    $notes = all("SELECT * FROM notes WHERE user_id = ? AND subject_id = ? ORDER BY pinned DESC, datum DESC LIMIT 200", [$uid, (int)$f['id']]);
    $files = all("SELECT fi.id, fi.name, fi.groesse FROM files fi JOIN notes n ON n.id = fi.scope_id
                  WHERE fi.user_id = ? AND fi.scope = 'note' AND n.subject_id = ?", [$uid, (int)$f['id']]);
    ?>
    <h1 style="font-size:20px;margin-bottom:4px"><?= h($f['name']) ?></h1>
    <p class="sm mu2"><?= $f['lf_no'] ? 'LF' . (int)$f['lf_no'] . ' · ' : '' ?><?= count($notes) ?> Eintraege</p>
    <?php if ($files): ?><hr><ul class="li"><?php foreach ($files as $x): ?>
      <li style="padding:4px 0"><a href="<?= url('geteilt', ['t' => $sh['token'], 'f' => $x['id']]) ?>"><?= h($x['name']) ?></a>
        <span class="sm mu2 mo"><?= num($x['groesse'] / 1024, 0) ?> kB</span></li>
    <?php endforeach; ?></ul><?php endif; ?>
    <?php foreach ($notes as $n): ?>
      <hr>
      <h3 style="margin-bottom:2px"><?= h($n['titel'] ?: dt($n['datum'])) ?></h3>
      <p class="sm mu2"><?= h(dt($n['datum'])) ?> · <?= h($n['kind']) ?></p>
      <?php if ($n['kind'] === 'snippet'): ?><pre><?= h($n['body']) ?></pre>
      <?php else: ?><div class="sm"><?= md($n['body']) ?></div><?php endif; ?>
    <?php endforeach;
}
function share_bericht(array $sh, int $uid, array $besitzer): void {
    $rep = one("SELECT * FROM reports WHERE id = ? AND user_id = ?", [(int)$sh['ref'], $uid]);
    if (!$rep) return;
    $s = report_sum((int)$rep['id']);
    $betrieb = array_filter($s['rows'], fn($r) => $r['ort'] !== 'schule');
    $schule  = array_filter($s['rows'], fn($r) => $r['ort'] === 'schule');
    // Der Name steht nur auf einem geteilten Nachweis, wenn er dafuer freigegeben wurde
    $wer = ((int)($sh['name_zeigen'] ?? 0) === 1 && (int)$besitzer['dok_merken'] === 1 && $besitzer['dok_name'] !== '')
         ? $besitzer['dok_name'] : klasse_name($besitzer);
    ?>
    <h1 style="font-size:19px;margin-bottom:2px">Ausbildungsnachweis Nr. <?= report_nr($uid, (string)$rep['von']) ?></h1>
    <p class="mu sm"><?= $rep['art'] === 'monat' ? 'Monatsnachweis' : 'Wochennachweis' ?> ·
      <?= h(periode_label($rep['periode'], $rep['art'])) ?></p>
    <div class="tw"><table style="margin-bottom:12px">
      <tr><th style="width:20%">Auszubildende/-r</th><td><?= h($wer) ?></td>
          <th style="width:16%">Ausbildungsjahr</th><td><?= (int)$rep['jahr'] ?></td></tr>
      <tr><th>Beruf</th><td><?= h($besitzer['beruf']) ?></td>
          <th>Zeitraum</th><td><?= h(dt($rep['von'])) ?> – <?= h(dt($rep['bis'])) ?></td></tr>
      <tr><th>Betrieb</th><td><?= h($besitzer['betrieb']) ?></td>
          <th>Abteilung</th><td><?= h($rep['abteilung']) ?></td></tr>
    </table></div>
    <h3>Betriebliche Taetigkeiten</h3>
    <div class="tw"><table><thead><tr><th style="width:13%">Tag</th><th style="width:8%" class="n">Std</th><th>Taetigkeit</th>
      <th style="width:27%">Ausbildungsinhalt</th></tr></thead><tbody>
      <?php foreach ($betrieb as $r): ?>
        <tr><td><?= h(dt($r['datum'], 'D d.m.')) ?></td><td class="n"><?= $r['stunden'] > 0 ? num((float)$r['stunden'], 2) : '' ?></td>
          <td><?= h($r['text']) ?></td>
          <td class="sm"><?= h(($r['pos_no'] ? $r['pos_no'] . '  ' : '') . ($r['kategorie'] ?: '')) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php if ($rep['schule_text'] !== '' || $schule): ?>
      <h3 style="margin-top:12px">Berufsschule</h3>
      <?php if ($rep['schule_text'] !== ''): ?><p><?= nl2br(h($rep['schule_text'])) ?></p><?php endif; ?>
      <?php if ($schule): ?><div class="tw"><table><tbody>
        <?php foreach ($schule as $r): ?><tr><td style="width:13%"><?= h(dt($r['datum'], 'D d.m.')) ?></td><td><?= h($r['text']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif;
    endif;
    if ($rep['sonstiges'] !== ''): ?><h3 style="margin-top:12px">Sonstiges</h3><p><?= nl2br(h($rep['sonstiges'])) ?></p><?php endif;
}

// --- Dateien, Export, Kalender --------------------------------------------
const MIME_OK = ['application/pdf'=>1,'image/png'=>1,'image/jpeg'=>1,'image/gif'=>1,'image/webp'=>1,
    'text/plain'=>1,'text/csv'=>1,'application/zip'=>1,'application/json'=>1,
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>1,
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>1,
    'application/vnd.openxmlformats-officedocument.presentationml.presentation'=>1];
function upload(array $f, int $uid, string $scope, ?int $sid): ?string {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK) return 'Upload fehlgeschlagen.';
    if ($f['size'] > MAX_UPLOAD_MB * 1024 * 1024) return 'Groesser als ' . MAX_UPLOAD_MB . ' MB.';
    if (!is_uploaded_file($f['tmp_name'])) return 'Ungueltiger Upload.';
    $mime = class_exists('finfo') ? (string)(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) : 'application/octet-stream';
    if (!isset(MIME_OK[$mime])) return 'Dateityp nicht erlaubt (' . $mime . ').';
    ins('files', ['user_id' => $uid, 'name' => mb_substr(preg_replace('/[^\p{L}\p{N} ._-]+/u', '_', (string)$f['name']), 0, 120),
        'mime' => $mime, 'groesse' => (int)$f['size'], 'daten' => (string)file_get_contents($f['tmp_name']),
        'scope' => $scope, 'scope_id' => $sid]);
    return null;
}
function a_datei(): void {
    $u = need_login();
    $f = one("SELECT * FROM files WHERE id = ? AND user_id = ?", [(int)get('id'), (int)$u['id']]);
    if (!$f) { http_response_code(404); exit('Nicht gefunden.'); }
    header_remove('Content-Security-Policy');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^\x20-\x7e]/', '_', $f['name']) . '"');
    header('Content-Length: ' . strlen($f['daten']));
    header('Cache-Control: private, no-store');
    echo $f['daten']; exit;
}
function csv_out(string $name, array $head, array $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, $head, ';', '"', '\\');
    foreach ($rows as $r) fputcsv($o, $r, ';', '"', '\\');
    fclose($o); exit;
}
function a_export(): void {
    $u = need_login(); $uid = (int)$u['id']; $w = get('w', 'alles');
    if ($w === 'noten') {
        csv_out('noten', ['Datum','Fach','Art','Titel','Skala','Wert','Note','Gewicht','Halbjahr'],
            array_map(fn($g) => [$g['datum'], $g['fach'] ?: $g['fach_text'], $g['art'], $g['titel'], $g['skala'],
                $g['wert'], num((float)to_note((float)$g['wert'], $g['skala']), 2), $g['gewicht'], $g['halbjahr']],
                all("SELECT g.*, s.name AS fach FROM grades g LEFT JOIN subjects s ON s.id = g.subject_id
                     WHERE g.user_id = ? ORDER BY g.datum", [$uid])));
    }
    if ($w === 'bh') {
        csv_out('berichtsheft', ['Periode','Datum','Stunden','Ort','Taetigkeit','Position','Kategorie','Lernfeld','Status'],
            array_map(fn($e) => [$e['periode'], $e['datum'], $e['stunden'], $e['ort'], $e['text'],
                $e['pos_no'], $e['kategorie'], $e['lf_no'], $e['status']],
                all("SELECT e.*, r.periode, r.status, c.name AS kategorie, c.pos_no
                     FROM report_entries e JOIN reports r ON r.id = e.report_id
                     LEFT JOIN categories c ON c.id = e.category_id
                     WHERE e.user_id = ? ORDER BY e.datum", [$uid])));
    }
    if ($w === 'routinen') {
        csv_out('routinen', ['Datum','Zeit','Aufgabe','Minuten','Notiz'],
            array_map(fn($l) => [$l['datum'], $l['zeit'], $l['name'], $l['minuten'], $l['notiz']],
                all("SELECT l.*, r.name FROM routine_logs l JOIN routines r ON r.id = l.routine_id
                     WHERE l.user_id = ? ORDER BY l.datum DESC", [$uid])));
    }
    if ($w === 'notizen') {
        csv_out('notizen', ['Datum','Art','Titel','Fach','Lernfeld','Tags','Inhalt'],
            array_map(fn($n) => [$n['datum'], $n['kind'], $n['titel'], $n['fach'], $n['lf_no'], $n['tags'], $n['body']],
                all("SELECT n.*, s.name AS fach FROM notes n LEFT JOIN subjects s ON s.id = n.subject_id
                     WHERE n.user_id = ? ORDER BY n.datum DESC", [$uid])));
    }
    // Zugangsdaten und Betriebsspuren gehoeren nicht in eine Datei, die weitergereicht wird
    $d = ['profil' => array_diff_key($u, array_flip(['pass_hash','totp_secret','recovery',
        'ics_token','last_ip','last_login','failed','locked_until','pw_changed','email']))];
    foreach (['subjects','events','notes','grades','tasks','reports','report_entries','routines',
              'routine_logs','timetable','blocks','absences'] as $t) {
        $d[$t] = all("SELECT * FROM $t WHERE user_id = ?", [$uid]);
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="bsfisazubi-' . date('Ymd') . '.json"');
    echo json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function a_ics(): void {
    $t = get('t');
    if (strlen($t) < 20 || !rl('ics:' . client_ip(), 120, 3600)) { http_response_code(404); exit('Nicht gefunden.'); }
    $u = one("SELECT * FROM users WHERE ics_token = ?", [$t]);
    if (!$u) { http_response_code(404); exit('Nicht gefunden.'); }
    $esc = fn($s) => addcslashes(str_replace(["\r\n", "\n"], '\\n', (string)$s), ",;\\");
    $ics = ["BEGIN:VCALENDAR","VERSION:2.0","PRODID:-//" . APP_DOMAIN . "//DE","CALSCALE:GREGORIAN",
            "METHOD:PUBLISH","X-WR-CALNAME:" . $esc(APP_NAME)];
    foreach (all("SELECT * FROM events WHERE user_id = ? AND datum >= date('now','localtime','-180 day')", [(int)$u['id']]) as $e) {
        $d = str_replace('-', '', $e['datum']);
        $ics[] = "BEGIN:VEVENT";
        $ics[] = "UID:e" . (int)$e['id'] . "@" . APP_DOMAIN;
        $ics[] = "DTSTAMP:" . gmdate('Ymd\THis\Z');
        if ($e['zeit_von']) {
            $ics[] = "DTSTART;TZID=Europe/Berlin:" . $d . 'T' . str_replace(':', '', $e['zeit_von']) . '00';
            $ics[] = "DTEND;TZID=Europe/Berlin:" . $d . 'T' . str_replace(':', '', $e['zeit_bis'] ?: $e['zeit_von']) . '00';
        } else {
            $ics[] = "DTSTART;VALUE=DATE:" . $d;
            $ics[] = "DTEND;VALUE=DATE:" . date('Ymd', strtotime($e['datum'] . ' +1 day'));
        }
        $ics[] = "SUMMARY:" . $esc('[' . typ_label($e['typ']) . '] ' . $e['titel']);
        $b = trim($e['beschreibung'] . ($e['stoff'] ? "\n" . $e['stoff'] : ''));
        if ($b) $ics[] = "DESCRIPTION:" . $esc($b);
        if ($e['raum']) $ics[] = "LOCATION:" . $esc($e['raum']);
        $ics[] = "END:VEVENT";
    }
    $ics[] = "END:VCALENDAR";
    header_remove('Content-Security-Policy');
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="termine.ics"');
    echo implode("\r\n", $ics) . "\r\n";
    exit;
}

// --- API: Sofortsuche fuer die Palette --------------------------------------
function a_api(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    $a = get('a');
    if (!me()) { http_response_code(401); echo '{"fehler":"Nicht angemeldet."}'; exit; }
    $u = me();
    if ($a === 'klassen') {
        if (!rl('klassen:' . (int)$u['id'], 20, 600)) { http_response_code(429); echo '{"fehler":"Zu viele Anfragen.","klassen":[]}'; exit; }
        $src = one("SELECT * FROM sources WHERE id = ? AND user_id = ? AND typ = 'webuntis'",
                   [(int)get('id'), (int)$u['id']]);
        echo json_encode($src ? untis_klassen($src) : ['fehler' => 'Quelle nicht gefunden.', 'klassen' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!rl('api:' . (int)$u['id'], 240, 60)) { http_response_code(429); echo '{"treffer":[]}'; exit; }
    $q = get('q');
    $out = [];
    $antw = array_map(fn($x) => ['label' => $x['label'], 'wert' => $x['wert'],
        'icon' => $x['icon'], 'url' => $x['url']], such_antwort($u, $q));
    if (mb_strlen($q) >= 2) {
        foreach (suche((int)$u['id'], $q, 14) as $t) {
            $out[] = ['art' => art_label($t['art']), 'icon' => art_icon($t['art']),
                'titel' => mb_substr((string)$t['titel'], 0, 90),
                'aus' => mb_substr(trim((string)$t['aus']), 0, 90),
                'datum' => $t['datum'] ? dt($t['datum'], 'd.m.y') : '',
                'url' => such_ziel($t['art'], $t['ref'], (string)$t['datum'], $u)];
        }
    }
    echo json_encode(['antwort' => $antw, 'treffer' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Router ----------------------------------------------------------------
$p = is_string($_GET['p'] ?? null) ? $_GET['p'] : 'start';
if (!is_string($p)) $p = 'start';
if ($p === 'ics') a_ics();
session_check();
gc_maybe();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !rl('post:' . client_ip(), 400, 600)) {
    http_response_code(429); exit('Zu viele Anfragen.');
}
switch ($p) {
    case 'login':         p_login(); break;
    case 'konto':         p_konto(); break;
    case 'abbruch':       unset($_SESSION['2fa']); redirect(url('login'));
    case 'logout':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(url('start'));   // kein Abmelden per Link
        csrf_check();
        logout();
    case 'theme':
        need_login(); csrf_check();
        upd('users', ['theme' => in_array(post('theme'), ['auto','hell','dunkel'], true) ? post('theme') : 'auto'],
            'id = :id', ['id' => (int)me()['id']]);
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        redirect(str_starts_with($ref, abs_url('/')) ? $ref : url('start'));
    case 'neu':           a_neu(); break;
    case 'datei':         a_datei(); break;
    case 'export':        a_export(); break;
    case 'api':           a_api(); break;
    case 'start':         p_start(); break;
    case 'faecher':       p_faecher(); break;
    case 'plan':          p_plan(); break;
    case 'notizen':       p_notizen(); break;
    case 'noten':         p_noten(); break;
    case 'berichtsheft':  p_berichtsheft(); break;
    case 'pruefung':      p_pruefung(); break;
    // alte Adressen
    case 'heute':         redirect(url('start'));
    case 'termine':       redirect(url('plan', get('id') !== '' ? ['id' => (int)get('id')] : []));
    case 'aufgaben':      redirect(url('plan', ['t' => 'aufgaben'] + (get('id') !== '' ? ['id' => (int)get('id')] : [])));
    case 'routinen':      redirect(url('berichtsheft', ['t' => 'routinen']));
    case 'abwesend':      redirect(url('einsaetze', ['t' => 'zeiten']));
    case 'einrichtung':   p_einrichtung(); break;
    case 'einstellungen': p_einstellungen(); break;
    case 'suche':         p_suche(); break;
    case 'geteilt':       p_geteilt(); break;
    case 'einsaetze':     p_einsaetze(); break;
    case 'kontakte':      p_kontakte(); break;
    case 'mehr':          p_mehr(); break;
    default:
        http_response_code(404);
        need_login();
        page('Nicht gefunden', '<div class="c"><div class="bo">Diese Seite gibt es nicht. '
            . '<a href="' . url('start') . '">Zur Startseite</a></div></div>');
}
