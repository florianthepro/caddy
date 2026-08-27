<?php
/**
 * ============================================================================
 *  bsfisazubi.de  -  Azubi-Portal fuer Fachinformatiker/-in Systemintegration
 * ============================================================================
 *
 *  Eine einzige Datei. Keine Composer-Abhaengigkeiten. Keine CDNs.
 *  Datenbank: SQLite (wird beim ersten Aufruf automatisch angelegt).
 *
 *  ---------------------------------------------------------------------------
 *  INSTALLATION (Windows + Caddy + PHP-FastCGI, so wie in diesem Repo)
 *  ---------------------------------------------------------------------------
 *   1) Datei ablegen:      C:\caddy\www\bsfisazubi.de\index.php
 *   2) In C:\php\php.ini folgende Extensions aktivieren (Semikolon entfernen):
 *          extension=pdo_sqlite
 *          extension=sqlite3
 *          extension=mbstring
 *          extension=openssl
 *          extension=fileinfo
 *      Optional (nur fuer Bild-Thumbnails):  extension=gd
 *   3) Caddyfile-Block ergaenzen:
 *
 *          bsfisazubi.de {
 *              root * C:/caddy/www/bsfisazubi.de
 *              encode gzip zstd
 *              # Datenverzeichnis niemals ausliefern:
 *              @data path /data /data/*
 *              respond @data 404
 *              php_fastcgi 127.0.0.1:9000
 *              file_server
 *              header {
 *                  Strict-Transport-Security "max-age=31536000; includeSubDomains"
 *                  -Server
 *              }
 *          }
 *
 *   4) Reload:  "C:\caddy\caddy.exe" reload --config "C:\caddy\caddyfile"
 *   5) Seite aufrufen -> Ersteinrichtung. Der Setup-Token steht in
 *      <Datenverzeichnis>\SETUP-TOKEN.txt  (vom Server-Desktop / localhost aus
 *      geht es auch ohne Token). Danach wird die Datei automatisch geloescht.
 *
 *  Das Datenverzeichnis liegt standardmaessig NEBEN dem Webroot, hier also
 *  C:\caddy\www\bsfisazubi.de-data  -  damit ist es ueber den Webserver
 *  ueberhaupt nicht erreichbar. Genau dieses Verzeichnis gehoert ins Backup.
 *  Mit der Umgebungsvariablen BSFISI_DATA_DIR laesst sich der Ort aendern.
 *
 *  ---------------------------------------------------------------------------
 *  SICHERHEIT (Kurzuebersicht)
 *  ---------------------------------------------------------------------------
 *   - Registrierung ausschliesslich per Einladungscode (kein offener Signup)
 *   - Argon2id/bcrypt-Hashes, Passwort-Policy, Passwort-Blacklist
 *   - Optionale 2FA (TOTP, RFC 6238) inkl. Recovery-Codes und QR-Code
 *   - CSRF-Token auf jeder schreibenden Aktion (hash_equals)
 *   - Session-Haertung: HttpOnly, SameSite=Strict, Secure, Regeneration,
 *     Idle-/Absolut-Timeout, Bindung an User-Agent, serverseitige Sitzungsliste
 *   - Brute-Force-Schutz: Rate-Limit pro IP + pro Konto, Sperrzeiten, Honeypot
 *   - Content-Security-Policy mit Script-Nonce, X-Frame-Options, nosniff, HSTS
 *   - Ausschliesslich Prepared Statements, konsequentes Output-Escaping
 *   - Uploads als BLOB in der DB (nichts Ausfuehrbares im Webroot), MIME-Whitelist
 *   - Audit-Log fuer sicherheitsrelevante Aktionen
 *   - Datenverzeichnis ausserhalb des Webroots, DB-Name mit Zufallssuffix
 *   - Vier Rollen: Auszubildende, Ausbilder, Lehrkraft, Administration
 *
 *  Lizenz: MIT. Ohne Gewaehr - vor produktivem Einsatz eigenes Review machen.
 */

// ===========================================================================
// 1. KONFIGURATION  (hier darfst du schrauben)
// ===========================================================================

const APP_NAME        = 'BS FiSi Azubi-Portal';
const APP_SHORT       = 'FiSi';
const APP_DOMAIN      = 'bsfisazubi.de';
const APP_VERSION     = '1.0.0';

// Datenverzeichnis: standardmaessig NEBEN dem Webroot, damit weder Datenbank
// noch Anhaenge jemals ueber den Webserver erreichbar sind. Faellt auf
// ./data zurueck, wenn das uebergeordnete Verzeichnis nicht beschreibbar ist
// (dann unbedingt den Caddyfile-Block aus dem Kopf dieser Datei verwenden).
define('DATA_DIR', (function (): string {
    $env = getenv('BSFISI_DATA_DIR');
    if (is_string($env) && $env !== '') return rtrim($env, "/\\");
    $aussen = dirname(__DIR__) . DIRECTORY_SEPARATOR . basename(__DIR__) . '-data';
    if (is_dir($aussen) || @mkdir($aussen, 0700, true) || is_writable(dirname(__DIR__))) return $aussen;
    return __DIR__ . DIRECTORY_SEPARATOR . 'data';
})());
const MAX_UPLOAD_MB   = 8;                      // pro Datei
const SESSION_IDLE    = 3600;                   // 60 min ohne Aktivitaet -> Logout
const SESSION_ABS     = 43200;                  // 12 h absolut -> Logout
const LOGIN_MAX_IP    = 20;                     // Fehlversuche pro IP / 15 min
const LOGIN_MAX_USER  = 6;                      // Fehlversuche pro Konto -> Sperre
const LOGIN_LOCK_SEC  = 900;                    // 15 min Kontosperre
const PW_MIN_LEN      = 10;
const TRUSTED_PROXIES = [];                     // z.B. ['127.0.0.1'] wenn hinter Proxy

// ===========================================================================
// 2. BOOTSTRAP
// ===========================================================================

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Berlin');
setlocale(LC_TIME, 'de_DE.UTF-8', 'de_DE', 'German');

if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0700, true); }
if (!is_dir(DATA_DIR)) {
    http_response_code(500);
    exit('Datenverzeichnis konnte nicht angelegt werden: ' . htmlspecialchars(DATA_DIR));
}
ini_set('error_log', DATA_DIR . '/php-error.log');
// Verzeichnisschutz (greift bei Apache/IIS; bei Caddy siehe Caddyfile oben)
if (!is_file(DATA_DIR . '/.htaccess')) {
    @file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\nDeny from all\n");
}
if (!is_file(DATA_DIR . '/index.html')) {
    @file_put_contents(DATA_DIR . '/index.html', '');
}
// Koeder-Datei: die Verwaltung kann damit pruefen, ob das Datenverzeichnis
// versehentlich vom Webserver ausgeliefert wird.
if (!is_file(DATA_DIR . '/canary.txt')) {
    @file_put_contents(DATA_DIR . '/canary.txt', 'DATENVERZEICHNIS-OEFFENTLICH-ERREICHBAR');
}
if (!is_file(DATA_DIR . '/web.config')) {
    @file_put_contents(DATA_DIR . '/web.config',
        "<configuration><system.webServer><authorization>".
        "<deny users=\"*\" /></authorization></system.webServer></configuration>");
}

set_exception_handler(function (Throwable $e): void {
    error_log('[' . date('c') . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/html; charset=utf-8'); }
    echo '<!doctype html><meta charset="utf-8"><title>Fehler</title>'
       . '<div style="font:16px system-ui;max-width:36rem;margin:12vh auto;padding:1.5rem;'
       . 'border:1px solid #ddd;border-radius:12px">'
       . '<h1 style="font-size:1.2rem">Da ist etwas schiefgelaufen</h1>'
       . '<p>Die Aktion konnte nicht ausgefuehrt werden. Der Vorfall wurde protokolliert.</p>'
       . '<p><a href="' . htmlspecialchars(base_path()) . '">Zur Startseite</a></p></div>';
});
function client_ip(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (TRUSTED_PROXIES && in_array($remote, TRUSTED_PROXIES, true)) {
        $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($fwd !== '') {
            $parts = array_map('trim', explode(',', $fwd));
            $cand  = end($parts);
            if (filter_var($cand, FILTER_VALIDATE_IP)) { return $cand; }
        }
    }
    return $remote;
}
function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') return true;
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (TRUSTED_PROXIES && in_array($remote, TRUSTED_PROXIES, true)
        && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
    return false;
}
function base_path(): string {
    $s = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $d = rtrim(str_replace('\\', '/', dirname($s)), '/');
    return $d === '' ? '/' : $d . '/';
}

$GLOBALS['CSP_NONCE'] = base64_encode(random_bytes(16));

function send_security_headers(): void {
    $n = $GLOBALS['CSP_NONCE'];
    header_remove('X-Powered-By');
    // Hinweis: script-src bleibt streng (nur Nonce). style-src erlaubt zusaetzlich
    // Inline-Styles, weil style-Attribute von einer Nonce nicht abgedeckt werden.
    header("Content-Security-Policy: default-src 'none'; base-uri 'none'; "
        . "script-src 'nonce-$n'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; "
        . "font-src 'self' data:; connect-src 'self'; form-action 'self'; "
        . "frame-ancestors 'none'; object-src 'none'; manifest-src 'self'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow');
    if (is_https()) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
send_security_headers();

// ---------------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------------
$sessDir = DATA_DIR . '/sessions';
if (!is_dir($sessDir)) @mkdir($sessDir, 0700, true);
if (is_dir($sessDir) && is_writable($sessDir)) session_save_path($sessDir);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.gc_maxlifetime', (string)SESSION_ABS);
session_name('fisi_sid');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => base_path(),
    'httponly' => true,
    'secure'   => is_https(),
    'samesite' => 'Strict',
]);
session_start();

// ===========================================================================
// 3. DATENBANK
// ===========================================================================

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    if (!extension_loaded('pdo_sqlite')) {
        http_response_code(500);
        exit('<h1>PHP-Extension fehlt</h1><p>Bitte in der php.ini <code>extension=pdo_sqlite</code> aktivieren und PHP neu starten.</p>');
    }
    $marker = DATA_DIR . '/db.path';
    if (is_file($marker)) {
        $file = trim((string)file_get_contents($marker));
    } else {
        $file = DATA_DIR . '/portal-' . bin2hex(random_bytes(8)) . '.sqlite';
        file_put_contents($marker, $file);
        @chmod($marker, 0600);
    }
    $fresh = !is_file($file);
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    if ($fresh) @chmod($file, 0600);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    schema_migrate($pdo);
    return $pdo;
}

function q(string $sql, array $args = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
}
function all(string $sql, array $args = []): array { return q($sql, $args)->fetchAll(); }
function one(string $sql, array $args = []): ?array { $r = q($sql, $args)->fetch(); return $r === false ? null : $r; }
function val(string $sql, array $args = [], $default = null) { $r = q($sql, $args)->fetch(PDO::FETCH_NUM); return $r === false ? $default : $r[0]; }
function ins(string $table, array $data): int {
    $cols = array_keys($data);
    $sql  = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES ('
          . implode(',', array_map(fn($c) => ':' . $c, $cols)) . ')';
    q($sql, $data);
    return (int)db()->lastInsertId();
}
function upd(string $table, array $data, string $where, array $args = []): int {
    $set = implode(',', array_map(fn($c) => "$c = :s_$c", array_keys($data)));
    $p = [];
    foreach ($data as $k => $v) $p['s_' . $k] = $v;
    return q("UPDATE $table SET $set WHERE $where", $p + $args)->rowCount();
}
function del(string $table, string $where, array $args = []): int {
    return q("DELETE FROM $table WHERE $where", $args)->rowCount();
}

function schema_migrate(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT NOT NULL)");
    $cur = (int)($pdo->query("SELECT v FROM meta WHERE k='schema'")->fetchColumn() ?: 0);
    if ($cur >= 1) return;

    $pdo->exec(<<<SQL
CREATE TABLE users (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    username          TEXT NOT NULL UNIQUE COLLATE NOCASE,
    email             TEXT UNIQUE COLLATE NOCASE,
    pass_hash         TEXT NOT NULL,
    role              TEXT NOT NULL DEFAULT 'azubi',   -- admin|lehrer|ausbilder|azubi
    display_name      TEXT NOT NULL DEFAULT '',
    class_id          INTEGER REFERENCES classes(id) ON DELETE SET NULL,
    beruf             TEXT NOT NULL DEFAULT 'Fachinformatiker/-in Systemintegration',
    ausbildung_start  TEXT,
    ausbildung_ende   TEXT,
    betrieb           TEXT NOT NULL DEFAULT '',
    ausbilder_name    TEXT NOT NULL DEFAULT '',
    wochenstunden     REAL NOT NULL DEFAULT 40,
    active            INTEGER NOT NULL DEFAULT 1,
    must_change_pw    INTEGER NOT NULL DEFAULT 0,
    totp_secret       TEXT,
    totp_enabled      INTEGER NOT NULL DEFAULT 0,
    recovery_codes    TEXT,
    theme             TEXT NOT NULL DEFAULT 'auto',
    ics_token         TEXT,
    failed_logins     INTEGER NOT NULL DEFAULT 0,
    locked_until      INTEGER NOT NULL DEFAULT 0,
    last_login_at     TEXT,
    last_login_ip     TEXT,
    pw_changed_at     TEXT,
    created_at        TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE classes (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT NOT NULL UNIQUE,        -- z.B. 1FS152
    ausbildungsjahr INTEGER NOT NULL DEFAULT 1,
    zeitgruppe    INTEGER NOT NULL DEFAULT 1,  -- letzte Ziffer der Klasse = Blockgruppe
    schuljahr     TEXT NOT NULL DEFAULT '',
    klassenleitung TEXT NOT NULL DEFAULT '',
    raum          TEXT NOT NULL DEFAULT '',
    note          TEXT NOT NULL DEFAULT '',
    archived      INTEGER NOT NULL DEFAULT 0,
    created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE class_members (
    class_id INTEGER NOT NULL REFERENCES classes(id) ON DELETE CASCADE,
    user_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    PRIMARY KEY (class_id, user_id)
);
CREATE TABLE subjects (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    name      TEXT NOT NULL,
    short     TEXT NOT NULL DEFAULT '',
    lf_no     INTEGER,                          -- Lernfeld-Nummer falls zutreffend
    color     TEXT NOT NULL DEFAULT '#4f7cff',
    lehrer    TEXT NOT NULL DEFAULT '',
    class_id  INTEGER REFERENCES classes(id) ON DELETE CASCADE,
    sort      INTEGER NOT NULL DEFAULT 0,
    archived  INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE lernfelder (
    nr        INTEGER PRIMARY KEY,
    code      TEXT NOT NULL,
    titel     TEXT NOT NULL,
    jahr      INTEGER NOT NULL,
    stunden   INTEGER NOT NULL DEFAULT 80,
    beschreibung TEXT NOT NULL DEFAULT ''
);
CREATE TABLE events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    class_id    INTEGER REFERENCES classes(id) ON DELETE CASCADE,
    subject_id  INTEGER REFERENCES subjects(id) ON DELETE SET NULL,
    typ         TEXT NOT NULL DEFAULT 'probe',  -- probe|test|abgabe|pruefung|termin|projekt|ferien
    titel       TEXT NOT NULL,
    beschreibung TEXT NOT NULL DEFAULT '',
    datum       TEXT NOT NULL,
    zeit_von    TEXT NOT NULL DEFAULT '',
    zeit_bis    TEXT NOT NULL DEFAULT '',
    raum        TEXT NOT NULL DEFAULT '',
    lf_no       INTEGER,
    stoff       TEXT NOT NULL DEFAULT '',       -- Lernstoff, eine Zeile pro Punkt
    visibility  TEXT NOT NULL DEFAULT 'class',  -- class|private
    wichtig     INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE notes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    class_id    INTEGER REFERENCES classes(id) ON DELETE SET NULL,
    subject_id  INTEGER REFERENCES subjects(id) ON DELETE SET NULL,
    lf_no       INTEGER,
    datum       TEXT NOT NULL,
    titel       TEXT NOT NULL DEFAULT '',
    body        TEXT NOT NULL DEFAULT '',
    tags        TEXT NOT NULL DEFAULT '',
    kind        TEXT NOT NULL DEFAULT 'notiz',  -- notiz|randnotiz|howto|snippet|link|zusammenfassung
    sprache     TEXT NOT NULL DEFAULT '',       -- fuer Snippets: powershell, bash, ...
    visibility  TEXT NOT NULL DEFAULT 'private',-- private|class|all
    pinned      INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE grades (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    subject_id  INTEGER REFERENCES subjects(id) ON DELETE SET NULL,
    fach_text   TEXT NOT NULL DEFAULT '',
    art         TEXT NOT NULL DEFAULT 'schulaufgabe', -- schulaufgabe|kurzarbeit|test|muendlich|projekt|referat|ihk
    skala       TEXT NOT NULL DEFAULT 'note',   -- note (1-6) | punkte (0-100) | ihk (0-100)
    wert        REAL NOT NULL,
    gewicht     REAL NOT NULL DEFAULT 1,
    datum       TEXT NOT NULL,
    titel       TEXT NOT NULL DEFAULT '',
    bemerkung   TEXT NOT NULL DEFAULT '',
    halbjahr    TEXT NOT NULL DEFAULT '',
    created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE tasks (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    class_id    INTEGER REFERENCES classes(id) ON DELETE CASCADE,
    subject_id  INTEGER REFERENCES subjects(id) ON DELETE SET NULL,
    titel       TEXT NOT NULL,
    beschreibung TEXT NOT NULL DEFAULT '',
    faellig     TEXT,
    prio        INTEGER NOT NULL DEFAULT 1,     -- 0 niedrig, 1 normal, 2 hoch
    status      TEXT NOT NULL DEFAULT 'offen',  -- offen|erledigt
    bereich     TEXT NOT NULL DEFAULT 'schule', -- schule|betrieb|privat
    visibility  TEXT NOT NULL DEFAULT 'private',
    erledigt_am TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE categories (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    name      TEXT NOT NULL UNIQUE,
    abschnitt TEXT NOT NULL DEFAULT 'A',        -- A|B|C|X (X = organisatorisch)
    pos_no    TEXT NOT NULL DEFAULT '',         -- z.B. "A 4"
    farbe     TEXT NOT NULL DEFAULT '#4f7cff',
    sort      INTEGER NOT NULL DEFAULT 0,
    aktiv     INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE category_rules (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    keyword     TEXT NOT NULL,
    category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
    prio        INTEGER NOT NULL DEFAULT 10,
    ersetzung   TEXT NOT NULL DEFAULT ''        -- optionaler "schoener" Standardtext
);
CREATE TABLE reports (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    art           TEXT NOT NULL DEFAULT 'woche',  -- woche|monat
    periode       TEXT NOT NULL,                  -- 2026-W12 bzw. 2026-03
    von           TEXT NOT NULL,
    bis           TEXT NOT NULL,
    nr            INTEGER NOT NULL DEFAULT 0,     -- laufende Nummer des Nachweises
    ausbildungsjahr INTEGER NOT NULL DEFAULT 1,
    abteilung     TEXT NOT NULL DEFAULT '',
    schule_text   TEXT NOT NULL DEFAULT '',
    sonstiges     TEXT NOT NULL DEFAULT '',
    status        TEXT NOT NULL DEFAULT 'entwurf',-- entwurf|eingereicht|geprueft|abgelehnt
    eingereicht_am TEXT,
    geprueft_von  INTEGER REFERENCES users(id) ON DELETE SET NULL,
    geprueft_am   TEXT,
    pruef_notiz   TEXT NOT NULL DEFAULT '',
    sign_azubi    TEXT NOT NULL DEFAULT '',
    sign_ausbilder TEXT NOT NULL DEFAULT '',
    created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at    TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    UNIQUE(user_id, art, periode)
);
CREATE TABLE report_entries (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    report_id   INTEGER NOT NULL REFERENCES reports(id) ON DELETE CASCADE,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    datum       TEXT NOT NULL,
    stunden     REAL NOT NULL DEFAULT 0,
    category_id INTEGER REFERENCES categories(id) ON DELETE SET NULL,
    lf_no       INTEGER,
    ort         TEXT NOT NULL DEFAULT 'betrieb', -- betrieb|schule|ueba|urlaub|krank|feiertag|frei
    text        TEXT NOT NULL DEFAULT '',
    quelle      TEXT NOT NULL DEFAULT 'manuell', -- manuell|routine|notiz|import
    sort        INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE routines (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL,
    beschreibung TEXT NOT NULL DEFAULT '',
    intervall   TEXT NOT NULL DEFAULT 'taeglich', -- taeglich|woechentlich|monatlich|bedarf
    category_id INTEGER REFERENCES categories(id) ON DELETE SET NULL,
    standard_min INTEGER NOT NULL DEFAULT 10,
    scope       TEXT NOT NULL DEFAULT 'betrieb',  -- betrieb|schule|privat
    owner_id    INTEGER REFERENCES users(id) ON DELETE CASCADE,
    geteilt     INTEGER NOT NULL DEFAULT 1,       -- 1 = im Betrieb fuer alle sichtbar
    berichtsheft INTEGER NOT NULL DEFAULT 1,      -- automatisch ins Berichtsheft
    aktiv       INTEGER NOT NULL DEFAULT 1,
    sort        INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE routine_logs (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    routine_id INTEGER NOT NULL REFERENCES routines(id) ON DELETE CASCADE,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    datum      TEXT NOT NULL,
    zeit       TEXT NOT NULL DEFAULT '',
    minuten    INTEGER NOT NULL DEFAULT 0,
    notiz      TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE timetable (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    class_id   INTEGER NOT NULL REFERENCES classes(id) ON DELETE CASCADE,
    wochentag  INTEGER NOT NULL,               -- 1 = Montag ... 5 = Freitag
    stunde     INTEGER NOT NULL,               -- 1..10
    subject_id INTEGER REFERENCES subjects(id) ON DELETE CASCADE,
    fach_text  TEXT NOT NULL DEFAULT '',
    raum       TEXT NOT NULL DEFAULT '',
    lehrer     TEXT NOT NULL DEFAULT '',
    UNIQUE(class_id, wochentag, stunde)
);
CREATE TABLE blockweeks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    zeitgruppe INTEGER NOT NULL DEFAULT 1,
    class_id   INTEGER REFERENCES classes(id) ON DELETE CASCADE,
    von        TEXT NOT NULL,
    bis        TEXT NOT NULL,
    art        TEXT NOT NULL DEFAULT 'schule',  -- schule|betrieb|ferien|ueba|pruefung
    label      TEXT NOT NULL DEFAULT ''
);
CREATE TABLE absences (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    von      TEXT NOT NULL,
    bis      TEXT NOT NULL,
    art      TEXT NOT NULL DEFAULT 'krank',     -- krank|urlaub|frei|beurlaubt|dienstreise
    grund    TEXT NOT NULL DEFAULT '',
    entschuldigt INTEGER NOT NULL DEFAULT 0,
    schule   INTEGER NOT NULL DEFAULT 0,        -- betraf Berufsschule
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE files (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name       TEXT NOT NULL,
    mime       TEXT NOT NULL,
    groesse    INTEGER NOT NULL,
    sha256     TEXT NOT NULL,
    daten      BLOB NOT NULL,
    scope      TEXT NOT NULL DEFAULT 'note',    -- note|event|report|material
    scope_id   INTEGER,
    visibility TEXT NOT NULL DEFAULT 'private',
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE invites (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    code_hash   TEXT NOT NULL UNIQUE,
    hint        TEXT NOT NULL DEFAULT '',
    role        TEXT NOT NULL DEFAULT 'azubi',
    class_id    INTEGER REFERENCES classes(id) ON DELETE SET NULL,
    created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
    max_uses    INTEGER NOT NULL DEFAULT 1,
    uses        INTEGER NOT NULL DEFAULT 0,
    expires_at  TEXT,
    notiz       TEXT NOT NULL DEFAULT '',
    created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE sessions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    sid_hash   TEXT NOT NULL UNIQUE,
    ua         TEXT NOT NULL DEFAULT '',
    ip         TEXT NOT NULL DEFAULT '',
    created_at INTEGER NOT NULL,
    last_seen  INTEGER NOT NULL,
    revoked    INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE login_attempts (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    ident   TEXT NOT NULL,
    ip      TEXT NOT NULL,
    ok      INTEGER NOT NULL DEFAULT 0,
    ts      INTEGER NOT NULL
);
CREATE TABLE ratelimit (
    k       TEXT PRIMARY KEY,
    cnt     INTEGER NOT NULL DEFAULT 0,
    started INTEGER NOT NULL
);
CREATE TABLE audit_log (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    actor   TEXT NOT NULL DEFAULT '',
    ip      TEXT NOT NULL DEFAULT '',
    aktion  TEXT NOT NULL,
    ziel    TEXT NOT NULL DEFAULT '',
    detail  TEXT NOT NULL DEFAULT '',
    ts      TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE settings (
    k TEXT PRIMARY KEY,
    v TEXT NOT NULL
);
CREATE INDEX idx_events_datum   ON events(datum);
CREATE INDEX idx_events_class   ON events(class_id, datum);
CREATE INDEX idx_notes_user     ON notes(user_id, datum);
CREATE INDEX idx_notes_class    ON notes(class_id, visibility);
CREATE INDEX idx_grades_user    ON grades(user_id, datum);
CREATE INDEX idx_tasks_user     ON tasks(user_id, status, faellig);
CREATE INDEX idx_rentries_rep   ON report_entries(report_id, datum);
CREATE INDEX idx_rentries_user  ON report_entries(user_id, datum);
CREATE INDEX idx_rlogs          ON routine_logs(routine_id, datum);
CREATE INDEX idx_audit_ts       ON audit_log(ts);
CREATE INDEX idx_la             ON login_attempts(ts);
SQL);
    $pdo->exec("INSERT INTO meta (k,v) VALUES ('schema','1')");
    seed_stammdaten($pdo);
}

// ---------------------------------------------------------------------------
// Stammdaten: Lernfelder (KMK-Rahmenlehrplan IT-Berufe 2020, FR Systemintegration)
// und Berichtsheft-Kategorien (= Berufsbildpositionen der FIAusbV 2020)
// ---------------------------------------------------------------------------
function seed_stammdaten(PDO $pdo): void {
    $lf = [
        [1,'LF 1','Das Unternehmen und die eigene Rolle im Betrieb beschreiben',1,80],
        [2,'LF 2','Arbeitsplaetze nach Kundenwunsch ausstatten',1,80],
        [3,'LF 3','Clients in Netzwerke einbinden',1,80],
        [4,'LF 4','Schutzbedarfsanalyse im eigenen Arbeitsbereich durchfuehren',1,80],
        [5,'LF 5','Software zur Verwaltung von Daten anpassen',1,80],
        [6,'LF 6','Serviceanfragen bearbeiten',2,80],
        [7,'LF 7','Cyber-physische Systeme ergaenzen',2,80],
        [8,'LF 8','Daten systemuebergreifend bereitstellen',2,80],
        [9,'LF 9','Netzwerke und Dienste bereitstellen',2,80],
        [10,'LF 10b','Serverdienste bereitstellen und Administrationsaufgaben automatisieren',3,80],
        [11,'LF 11b','Betrieb und Sicherheit vernetzter Systeme gewaehrleisten',3,80],
        [12,'LF 12b','Kundenspezifische Systemintegration durchfuehren',3,80],
    ];
    $st = $pdo->prepare("INSERT INTO lernfelder (nr,code,titel,jahr,stunden) VALUES (?,?,?,?,?)");
    foreach ($lf as $r) $st->execute($r);

    // Berufsbildpositionen laut FIAusbV 2020 (Abschnitt A = gemeinsam,
    // C = Fachrichtung Systemintegration, B = integrativ) + Praxis-Kategorien (X)
    $cats = [
        ['Planen, Vorbereiten und Durchfuehren von Arbeitsaufgaben','A','A 1','#4f7cff',10],
        ['Informieren und Beraten von Kunden und Kundinnen','A','A 2','#22a06b',20],
        ['Beurteilen marktgaengiger IT-Systeme und kundenspezifischer Loesungen','A','A 3','#a05eea',30],
        ['Entwickeln, Erstellen und Betreuen von IT-Loesungen','A','A 4','#e0742a',40],
        ['Durchfuehren und Dokumentieren von qualitaetssichernden Massnahmen','A','A 5','#0b8fa8',50],
        ['Umsetzen von Massnahmen zur IT-Sicherheit und zum Datenschutz','A','A 6','#d43f5c',60],
        ['Erbringen der Leistungen und Auftragsabschluss','A','A 7','#7a8b99',70],
        ['Betreiben von IT-Systemen','A','A 8','#2f6fdb',80],
        ['Inbetriebnehmen von Speicherloesungen','A','A 9','#8a6d3b',90],
        ['Programmieren von Softwareloesungen / Automatisierung','A','A 10','#5b8f22',100],
        ['Konzipieren und Realisieren von IT-Systemen','C','C 1','#1f7ae0',110],
        ['Installieren und Konfigurieren von Netzwerken','C','C 2','#00897b',120],
        ['Administrieren von IT-Systemen','C','C 3','#6a4fd8',130],
        ['Berufsbildung sowie Arbeits- und Tarifrecht','B','B 1','#8d99ae',140],
        ['Aufbau und Organisation des Ausbildungsbetriebes','B','B 2','#8d99ae',150],
        ['Sicherheit und Gesundheitsschutz bei der Arbeit','B','B 3','#8d99ae',160],
        ['Umweltschutz und Nachhaltigkeit','B','B 4','#8d99ae',170],
        ['Vernetztes Zusammenarbeiten unter Nutzung digitaler Medien','B','B 5','#8d99ae',180],
        ['Allgemeine Officetaetigkeiten','X','X 1','#9aa4b2',190],
        ['Besprechungen und Teamabstimmung','X','X 2','#9aa4b2',200],
        ['Weiterbildung, Zertifizierung und Selbstlernphase','X','X 3','#9aa4b2',210],
        ['Berufsschule (Blockunterricht)','X','X 4','#3d5afe',220],
        ['Ueberbetriebliche Lehrgaenge / Seminare','X','X 5','#3d5afe',230],
        ['Urlaub','X','X 6','#b0bec5',240],
        ['Krankheit','X','X 7','#b0bec5',250],
        ['Feiertag / arbeitsfrei','X','X 8','#b0bec5',260],
    ];
    $st = $pdo->prepare("INSERT INTO categories (name,abschnitt,pos_no,farbe,sort) VALUES (?,?,?,?,?)");
    foreach ($cats as $c) $st->execute($c);

    $id = function (string $name) use ($pdo): int {
        $s = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
        $s->execute([$name]);
        return (int)$s->fetchColumn();
    };
    // Stichwort -> Kategorie (+ optionaler "schoener" Berichtsheft-Text)
    $rules = [
        // --- Office / Betriebsalltag (genau der Kaffeemaschinen-Fall) -------
        ['kaffeemaschine','Allgemeine Officetaetigkeiten','Allgemeine Officetaetigkeiten'],
        ['kaffee','Allgemeine Officetaetigkeiten','Allgemeine Officetaetigkeiten'],
        ['spuelmaschine','Allgemeine Officetaetigkeiten','Allgemeine Officetaetigkeiten'],
        ['kueche','Allgemeine Officetaetigkeiten','Allgemeine Officetaetigkeiten'],
        ['post','Allgemeine Officetaetigkeiten','Allgemeine Officetaetigkeiten'],
        ['ablage','Allgemeine Officetaetigkeiten','Allgemeine Officetaetigkeiten'],
        ['scannen','Allgemeine Officetaetigkeiten',''],
        ['kopier','Allgemeine Officetaetigkeiten',''],
        ['aufraeum','Allgemeine Officetaetigkeiten',''],
        ['muell','Allgemeine Officetaetigkeiten',''],
        ['papier','Allgemeine Officetaetigkeiten',''],
        ['toner','Allgemeine Officetaetigkeiten',''],
        ['telefon','Informieren und Beraten von Kunden und Kundinnen',''],
        // --- Service / Ticket ----------------------------------------------
        ['ticket','Erbringen der Leistungen und Auftragsabschluss','Bearbeitung von Serviceanfragen im Ticketsystem'],
        ['helpdesk','Erbringen der Leistungen und Auftragsabschluss',''],
        ['servicedesk','Erbringen der Leistungen und Auftragsabschluss',''],
        ['stoerung','Erbringen der Leistungen und Auftragsabschluss',''],
        ['first level','Erbringen der Leistungen und Auftragsabschluss',''],
        ['1st level','Erbringen der Leistungen und Auftragsabschluss',''],
        ['support','Erbringen der Leistungen und Auftragsabschluss',''],
        ['anwender','Informieren und Beraten von Kunden und Kundinnen',''],
        ['einweisung','Informieren und Beraten von Kunden und Kundinnen',''],
        ['schulung fuer','Informieren und Beraten von Kunden und Kundinnen',''],
        ['beratung','Informieren und Beraten von Kunden und Kundinnen',''],
        ['angebot','Beurteilen marktgaengiger IT-Systeme und kundenspezifischer Loesungen',''],
        ['bestellung','Beurteilen marktgaengiger IT-Systeme und kundenspezifischer Loesungen',''],
        ['lieferant','Beurteilen marktgaengiger IT-Systeme und kundenspezifischer Loesungen',''],
        ['inventar','Erbringen der Leistungen und Auftragsabschluss',''],
        ['uebergabe','Erbringen der Leistungen und Auftragsabschluss',''],
        // --- Client / Hardware ---------------------------------------------
        ['notebook','Konzipieren und Realisieren von IT-Systemen',''],
        ['laptop','Konzipieren und Realisieren von IT-Systemen',''],
        ['arbeitsplatz','Konzipieren und Realisieren von IT-Systemen',''],
        ['client','Konzipieren und Realisieren von IT-Systemen',''],
        ['pc ','Konzipieren und Realisieren von IT-Systemen',''],
        ['hardware','Konzipieren und Realisieren von IT-Systemen',''],
        ['monitor','Konzipieren und Realisieren von IT-Systemen',''],
        ['image','Konzipieren und Realisieren von IT-Systemen',''],
        ['aufgesetzt','Konzipieren und Realisieren von IT-Systemen',''],
        ['drucker','Konzipieren und Realisieren von IT-Systemen',''],
        ['ssd','Konzipieren und Realisieren von IT-Systemen',''],
        ['ram','Konzipieren und Realisieren von IT-Systemen',''],
        // --- Netzwerk -------------------------------------------------------
        ['netzwerk','Installieren und Konfigurieren von Netzwerken',''],
        ['switch','Installieren und Konfigurieren von Netzwerken',''],
        ['router','Installieren und Konfigurieren von Netzwerken',''],
        ['vlan','Installieren und Konfigurieren von Netzwerken',''],
        ['patch','Installieren und Konfigurieren von Netzwerken',''],
        ['wlan','Installieren und Konfigurieren von Netzwerken',''],
        ['accesspoint','Installieren und Konfigurieren von Netzwerken',''],
        ['access point','Installieren und Konfigurieren von Netzwerken',''],
        ['lan','Installieren und Konfigurieren von Netzwerken',''],
        ['dhcp','Installieren und Konfigurieren von Netzwerken',''],
        ['dns','Installieren und Konfigurieren von Netzwerken',''],
        ['vpn','Installieren und Konfigurieren von Netzwerken',''],
        ['glasfaser','Installieren und Konfigurieren von Netzwerken',''],
        // --- Server / Administration ---------------------------------------
        ['server','Administrieren von IT-Systemen',''],
        ['active directory','Administrieren von IT-Systemen',''],
        [' ad ','Administrieren von IT-Systemen',''],
        ['gpo','Administrieren von IT-Systemen',''],
        ['gruppenrichtlinie','Administrieren von IT-Systemen',''],
        ['hyper-v','Administrieren von IT-Systemen',''],
        ['vmware','Administrieren von IT-Systemen',''],
        ['esxi','Administrieren von IT-Systemen',''],
        ['proxmox','Administrieren von IT-Systemen',''],
        ['virtualis','Administrieren von IT-Systemen',''],
        ['linux','Administrieren von IT-Systemen',''],
        ['exchange','Administrieren von IT-Systemen',''],
        ['benutzer angelegt','Administrieren von IT-Systemen',''],
        ['user angelegt','Administrieren von IT-Systemen',''],
        ['update','Betreiben von IT-Systemen',''],
        ['patchday','Betreiben von IT-Systemen',''],
        ['monitoring','Betreiben von IT-Systemen',''],
        ['wartung','Betreiben von IT-Systemen',''],
        // --- Storage / Backup -----------------------------------------------
        ['backup','Inbetriebnehmen von Speicherloesungen',''],
        ['datensicherung','Inbetriebnehmen von Speicherloesungen',''],
        ['restore','Inbetriebnehmen von Speicherloesungen',''],
        ['veeam','Inbetriebnehmen von Speicherloesungen',''],
        ['nas','Inbetriebnehmen von Speicherloesungen',''],
        ['san','Inbetriebnehmen von Speicherloesungen',''],
        ['raid','Inbetriebnehmen von Speicherloesungen',''],
        // --- Security / Datenschutz -----------------------------------------
        ['firewall','Umsetzen von Massnahmen zur IT-Sicherheit und zum Datenschutz',''],
        ['sicherheit','Umsetzen von Massnahmen zur IT-Sicherheit und zum Datenschutz',''],
        ['virus','Umsetzen von Massnahmen zur IT-Sicherheit und zum Datenschutz',''],
        ['phishing','Umsetzen von Massnahmen zur IT-Sicherheit und zum Datenschutz',''],
        ['dsgvo','Umsetzen von Massnahmen zur IT-Sicherheit und zum Datenschutz',''],
        ['datenschutz','Umsetzen von Massnahmen zur IT-Sicherheit und zum Datenschutz',''],
        ['berechtigung','Umsetzen von Massnahmen zur IT-Sicherheit und zum Datenschutz',''],
        ['zertifikat','Umsetzen von Massnahmen zur IT-Sicherheit und zum Datenschutz',''],
        ['mfa','Umsetzen von Massnahmen zur IT-Sicherheit und zum Datenschutz',''],
        ['passwort','Umsetzen von Massnahmen zur IT-Sicherheit und zum Datenschutz',''],
        // --- Skripting -------------------------------------------------------
        ['powershell','Programmieren von Softwareloesungen / Automatisierung',''],
        ['skript','Programmieren von Softwareloesungen / Automatisierung',''],
        ['script','Programmieren von Softwareloesungen / Automatisierung',''],
        ['bash','Programmieren von Softwareloesungen / Automatisierung',''],
        ['python','Programmieren von Softwareloesungen / Automatisierung',''],
        ['ansible','Programmieren von Softwareloesungen / Automatisierung',''],
        ['automatis','Programmieren von Softwareloesungen / Automatisierung',''],
        ['datenbank','Entwickeln, Erstellen und Betreuen von IT-Loesungen',''],
        ['sql','Entwickeln, Erstellen und Betreuen von IT-Loesungen',''],
        // --- Doku / QS --------------------------------------------------------
        ['dokumentation','Durchfuehren und Dokumentieren von qualitaetssichernden Massnahmen',''],
        ['doku','Durchfuehren und Dokumentieren von qualitaetssichernden Massnahmen',''],
        ['wiki','Durchfuehren und Dokumentieren von qualitaetssichernden Massnahmen',''],
        ['handbuch','Durchfuehren und Dokumentieren von qualitaetssichernden Massnahmen',''],
        ['anleitung','Durchfuehren und Dokumentieren von qualitaetssichernden Massnahmen',''],
        ['test','Durchfuehren und Dokumentieren von qualitaetssichernden Massnahmen',''],
        // --- Organisation ------------------------------------------------------
        ['meeting','Besprechungen und Teamabstimmung',''],
        ['besprechung','Besprechungen und Teamabstimmung',''],
        ['daily','Besprechungen und Teamabstimmung',''],
        ['jour fixe','Besprechungen und Teamabstimmung',''],
        ['teams-','Vernetztes Zusammenarbeiten unter Nutzung digitaler Medien',''],
        ['sharepoint','Vernetztes Zusammenarbeiten unter Nutzung digitaler Medien',''],
        ['projekt','Planen, Vorbereiten und Durchfuehren von Arbeitsaufgaben',''],
        ['planung','Planen, Vorbereiten und Durchfuehren von Arbeitsaufgaben',''],
        ['arbeitssicherheit','Sicherheit und Gesundheitsschutz bei der Arbeit',''],
        ['unterweisung','Sicherheit und Gesundheitsschutz bei der Arbeit',''],
        ['ersthelfer','Sicherheit und Gesundheitsschutz bei der Arbeit',''],
        ['entsorgung','Umweltschutz und Nachhaltigkeit',''],
        ['altgeraet','Umweltschutz und Nachhaltigkeit',''],
        ['recycling','Umweltschutz und Nachhaltigkeit',''],
        ['betriebsrat','Berufsbildung sowie Arbeits- und Tarifrecht',''],
        ['tarif','Berufsbildung sowie Arbeits- und Tarifrecht',''],
        ['ausbildungsplan','Berufsbildung sowie Arbeits- und Tarifrecht',''],
        // --- Lernen / Schule ----------------------------------------------------
        ['berufsschule','Berufsschule (Blockunterricht)',''],
        ['unterricht','Berufsschule (Blockunterricht)',''],
        ['blockwoche','Berufsschule (Blockunterricht)',''],
        ['schule','Berufsschule (Blockunterricht)',''],
        ['ueba','Ueberbetriebliche Lehrgaenge / Seminare',''],
        ['lehrgang','Ueberbetriebliche Lehrgaenge / Seminare',''],
        ['seminar','Weiterbildung, Zertifizierung und Selbstlernphase',''],
        ['selbststudium','Weiterbildung, Zertifizierung und Selbstlernphase',''],
        ['cisco','Weiterbildung, Zertifizierung und Selbstlernphase',''],
        ['lernen','Weiterbildung, Zertifizierung und Selbstlernphase',''],
        ['pruefungsvorbereitung','Weiterbildung, Zertifizierung und Selbstlernphase',''],
        ['urlaub','Urlaub',''],
        ['krank','Krankheit',''],
        ['feiertag','Feiertag / arbeitsfrei',''],
    ];
    $st = $pdo->prepare("INSERT INTO category_rules (keyword,category_id,prio,ersetzung) VALUES (?,?,?,?)");
    foreach ($rules as $i => $r) {
        $cid = $id($r[1]);
        if ($cid) $st->execute([$r[0], $cid, 100 - min(90, (int)floor($i / 5)), $r[2]]);
    }

    // Standard-Routinen im Betrieb (klassische Azubi-Aufgaben)
    $routines = [
        ['Kaffeemaschine reinigen / entkalken','Bruehgruppe, Milchsystem und Tresterbehaelter','taeglich','Allgemeine Officetaetigkeiten',10],
        ['Kaffeemaschine auffuellen (Bohnen/Wasser)','','taeglich','Allgemeine Officetaetigkeiten',5],
        ['Spuelmaschine ein-/ausraeumen','','taeglich','Allgemeine Officetaetigkeiten',10],
        ['Drucker: Papier und Toner pruefen','Alle Etagen','woechentlich','Allgemeine Officetaetigkeiten',15],
        ['Post holen und verteilen','','taeglich','Allgemeine Officetaetigkeiten',15],
        ['Backup-Protokoll kontrollieren','Sicherungsjobs auf Fehler pruefen','taeglich','Inbetriebnehmen von Speicherloesungen',15],
        ['Monitoring-Alarme durchsehen','','taeglich','Betreiben von IT-Systemen',15],
        ['Ticketqueue sichten','Offene Tickets priorisieren','taeglich','Erbringen der Leistungen und Auftragsabschluss',30],
        ['Serverraum-Check (Temperatur, LEDs, USV)','','woechentlich','Betreiben von IT-Systemen',15],
        ['Windows-Updates / Patchstand pruefen','','woechentlich','Betreiben von IT-Systemen',30],
        ['Leihgeraete und Lager inventarisieren','','monatlich','Erbringen der Leistungen und Auftragsabschluss',45],
        ['Altgeraete datenschutzkonform entsorgen','','monatlich','Umweltschutz und Nachhaltigkeit',30],
        ['Berichtsheft-Woche abschliessen','Woche pruefen, ergaenzen und einreichen','woechentlich','Berufsbildung sowie Arbeits- und Tarifrecht',20],
    ];
    $st = $pdo->prepare("INSERT INTO routines (name,beschreibung,intervall,category_id,standard_min,scope,geteilt,sort)
                         VALUES (?,?,?,?,?, 'betrieb', 1, ?)");
    foreach ($routines as $i => $r) $st->execute([$r[0], $r[1], $r[2], $id($r[3]) ?: null, $r[4], $i * 10]);

    $st = $pdo->prepare("INSERT INTO settings (k,v) VALUES (?,?)");
    foreach ([
        ['schule',           'Staedtische Berufsschule fuer Fachinformatik Systemintegration'],
        ['schule_kurz',      'BS FiSi'],
        ['registrierung',    'invite'],     // invite | zu
        ['berichtsheft_art', 'woche'],
        ['ap1_datum',        ''],
        ['ap2_datum',        ''],
        ['impressum',        ''],
    ] as $s) $st->execute($s);
}

// ===========================================================================
// 4. HELFER
// ===========================================================================

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function e($s): string { return h($s); }
function url(string $p = '', array $params = []): string {
    $q = $p === '' ? [] : ['p' => $p];
    $q += $params;
    return base_path() . ($q ? '?' . http_build_query($q) : '');
}
function redirect(string $to): void { header('Location: ' . $to, true, 303); exit; }
function post(string $k, $d = ''): string { return is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : $d; }
function postn(string $k, $d = null) { $v = $_POST[$k] ?? null; return ($v === null || $v === '') ? $d : $v; }
function get(string $k, $d = ''): string { return is_string($_GET[$k] ?? null) ? trim($_GET[$k]) : $d; }
function int_or_null($v): ?int { return ($v === null || $v === '' || $v === '0') ? null : (int)$v; }
function today(): string { return date('Y-m-d'); }
function setting(string $k, $d = '') { static $c = null; if ($c === null) { $c = []; foreach (all("SELECT k,v FROM settings") as $r) $c[$r['k']] = $r['v']; } return $c[$k] ?? $d; }
function setting_set(string $k, string $v): void {
    q("INSERT INTO settings (k,v) VALUES (:k,:v) ON CONFLICT(k) DO UPDATE SET v = :v2", ['k'=>$k,'v'=>$v,'v2'=>$v]);
}
function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'][] = ['t' => $type, 'm' => $msg]; }
function take_flash(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }
function nl2list(string $s): array {
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $s) ?: []), fn($x) => $x !== ''));
}
/** Deutsche Wochentags- und Monatsnamen (unabhaengig von der Server-Locale). */
function de_names(string $s): string {
    static $map = [
        'Monday' => 'Montag', 'Tuesday' => 'Dienstag', 'Wednesday' => 'Mittwoch',
        'Thursday' => 'Donnerstag', 'Friday' => 'Freitag', 'Saturday' => 'Samstag', 'Sunday' => 'Sonntag',
        'Mon' => 'Mo', 'Tue' => 'Di', 'Wed' => 'Mi', 'Thu' => 'Do', 'Fri' => 'Fr', 'Sat' => 'Sa', 'Sun' => 'So',
        'January' => 'Januar', 'February' => 'Februar', 'March' => 'Maerz', 'May' => 'Mai',
        'June' => 'Juni', 'July' => 'Juli', 'October' => 'Oktober', 'December' => 'Dezember',
        'Mar' => 'Mrz', 'Oct' => 'Okt', 'Dec' => 'Dez',
    ];
    return strtr($s, $map);
}
function de_date(?string $iso, string $fmt = 'd.m.Y'): string {
    if (!$iso) return '';
    $t = strtotime($iso);
    return $t ? de_names(date($fmt, $t)) : $iso;
}
function wd_name(int $i): string {
    return ['', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'][$i] ?? '';
}
function num(float $f, int $dec = 2): string { return number_format($f, $dec, ',', '.'); }
function audit(string $aktion, string $ziel = '', string $detail = ''): void {
    $u = current_user();
    try {
        ins('audit_log', [
            'user_id' => $u['id'] ?? null,
            'actor'   => $u['username'] ?? 'anonym',
            'ip'      => client_ip(),
            'aktion'  => $aktion,
            'ziel'    => $ziel,
            'detail'  => mb_substr($detail, 0, 800),
        ]);
    } catch (Throwable $e) { /* Logging darf nie den Request killen */ }
}

// ===========================================================================
// 5. SICHERHEIT
// ===========================================================================

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">'; }
function csrf_check(): void {
    $ok = isset($_POST['_csrf']) && is_string($_POST['_csrf'])
        && hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf']);
    if (!$ok) {
        audit('csrf_fehler', $_SERVER['REQUEST_URI'] ?? '');
        http_response_code(419);
        exit('<h1>Sitzung abgelaufen</h1><p>Bitte Seite neu laden und erneut absenden.</p>');
    }
}
function gc_maybe(): void {
    if (random_int(1, 50) !== 1) return;
    try {
        q("DELETE FROM login_attempts WHERE ts < ?", [time() - 7 * 86400]);
        q("DELETE FROM ratelimit WHERE started < ?", [time() - 86400]);
        q("DELETE FROM sessions WHERE last_seen < ?", [time() - 30 * 86400]);
    } catch (Throwable $e) { /* egal */ }
}
function rl_hit(string $key, int $max, int $window): bool {
    $now = time();
    $row = one("SELECT cnt, started FROM ratelimit WHERE k = ?", [$key]);
    if (!$row || ($now - (int)$row['started']) > $window) {
        q("INSERT INTO ratelimit (k,cnt,started) VALUES (:k,1,:t)
           ON CONFLICT(k) DO UPDATE SET cnt = 1, started = :t2", ['k'=>$key,'t'=>$now,'t2'=>$now]);
        return true;
    }
    if ((int)$row['cnt'] >= $max) return false;
    q("UPDATE ratelimit SET cnt = cnt + 1 WHERE k = ?", [$key]);
    return true;
}
function pw_hash(string $pw): string {
    if (defined('PASSWORD_ARGON2ID')) {
        return password_hash($pw, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2]);
    }
    return password_hash($pw, PASSWORD_DEFAULT, ['cost' => 12]);
}
function pw_problems(string $pw, string $user = '', string $name = ''): array {
    $p = [];
    if (mb_strlen($pw) < PW_MIN_LEN) $p[] = 'mindestens ' . PW_MIN_LEN . ' Zeichen';
    if (mb_strlen($pw) > 200)        $p[] = 'hoechstens 200 Zeichen';
    $klassen = (preg_match('/[a-z]/u', $pw) ? 1 : 0) + (preg_match('/[A-Z]/u', $pw) ? 1 : 0)
             + (preg_match('/\d/', $pw) ? 1 : 0) + (preg_match('/[^\p{L}\d]/u', $pw) ? 1 : 0);
    if ($klassen < 3) $p[] = 'mindestens 3 von 4 Zeichenarten (Klein-, Grossbuchstaben, Ziffern, Sonderzeichen)';
    $low = mb_strtolower($pw);
    $bad = ['passwort','password','123456','qwertz','qwerty','abc123','admin','azubi','schule','berufsschule',
            'fachinformatiker','systemintegration','willkommen','sommer','winter','fisi','bsfisi','letmein',
            'iloveyou','master','hallo123','test1234','1234567890','pa$$w0rd','p@ssw0rd','geheim'];
    foreach ($bad as $b) if (str_contains($low, $b)) { $p[] = 'kein offensichtliches Wort wie "' . $b . '"'; break; }
    if ($user && mb_stripos($pw, $user) !== false) $p[] = 'nicht den Benutzernamen enthalten';
    if ($name) foreach (preg_split('/\s+/', $name) as $t) {
        if (mb_strlen($t) >= 4 && mb_stripos($pw, $t) !== false) { $p[] = 'nicht den eigenen Namen enthalten'; break; }
    }
    if (preg_match('/^(.)\1+$/u', $pw)) $p[] = 'nicht nur ein wiederholtes Zeichen';
    return $p;
}
function rand_code(int $bytes = 8): string {
    $a = strtoupper(bin2hex(random_bytes($bytes)));
    $a = strtr($a, ['0' => 'W', '1' => 'X']);  // Verwechslungsgefahr entschaerfen
    return implode('-', str_split($a, 4));
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

// --- QR-Code (Byte-Modus, ECC-Level M) - eigenstaendig, ohne Bibliothek ----
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

// ===========================================================================
// 6. AUTHENTIFIZIERUNG
// ===========================================================================

function current_user(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    $u = null;
    if (!empty($_SESSION['uid'])) {
        $row = one("SELECT * FROM users WHERE id = ? AND active = 1", [(int)$_SESSION['uid']]);
        if ($row) $u = $row;
    }
    return $u;
}
function user_role(): string { $u = current_user(); return $u['role'] ?? 'gast'; }
function is_admin(): bool { return user_role() === 'admin'; }
function is_staff(): bool { return in_array(user_role(), ['admin', 'lehrer', 'ausbilder'], true); }
function can_review(): bool { return in_array(user_role(), ['admin', 'ausbilder'], true); }

function session_touch_or_kill(): void {
    if (empty($_SESSION['uid'])) return;
    $now = time();
    $sid = hash('sha256', session_id());
    $rec = one("SELECT * FROM sessions WHERE sid_hash = ?", [$sid]);
    if (!$rec || (int)$rec['revoked'] === 1) { logout('Sitzung wurde beendet.'); }
    if (($now - (int)$rec['last_seen']) > SESSION_IDLE) { logout('Automatisch abgemeldet (Inaktivitaet).'); }
    if (($now - (int)$rec['created_at']) > SESSION_ABS) { logout('Automatisch abgemeldet (maximale Sitzungsdauer).'); }
    $uaNow = substr(hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 32);
    if (!hash_equals($rec['ua'], $uaNow)) { audit('session_ua_wechsel'); logout('Sitzung ungueltig.'); }
    q("UPDATE sessions SET last_seen = ?, ip = ? WHERE id = ?", [$now, client_ip(), $rec['id']]);
}
function login_user(array $user, bool $remember2fa = false): void {
    session_regenerate_id(true);
    $_SESSION = ['uid' => (int)$user['id'], 'csrf' => bin2hex(random_bytes(32))];
    $now = time();
    q("DELETE FROM sessions WHERE user_id = ? AND (last_seen < ? OR revoked = 1)",
      [(int)$user['id'], $now - SESSION_ABS]);
    ins('sessions', [
        'user_id'    => (int)$user['id'],
        'sid_hash'   => hash('sha256', session_id()),
        'ua'         => substr(hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 32),
        'ip'         => client_ip(),
        'created_at' => $now,
        'last_seen'  => $now,
    ]);
    upd('users', [
        'last_login_at' => date('Y-m-d H:i:s'),
        'last_login_ip' => client_ip(),
        'failed_logins' => 0,
        'locked_until'  => 0,
    ], 'id = :id', ['id' => (int)$user['id']]);
    audit('login_ok', $user['username']);
}
function logout(string $msg = ''): void {
    if (!empty($_SESSION['uid'])) {
        q("UPDATE sessions SET revoked = 1 WHERE sid_hash = ?", [hash('sha256', session_id())]);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    session_start();
    session_regenerate_id(true);
    if ($msg) flash($msg, 'warn');
    redirect(url('login'));
}
function require_login(): array {
    $u = current_user();
    if (!$u) { $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? ''; redirect(url('login')); }
    return $u;
}
function require_role(string ...$roles): array {
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        audit('zugriff_verweigert', $_GET['p'] ?? '');
        http_response_code(403);
        render_page('Kein Zugriff', '<div class="card"><h2>Kein Zugriff</h2>'
            . '<p>Fuer diesen Bereich fehlt dir die Berechtigung.</p>'
            . '<a class="btn" href="' . url('dashboard') . '">Zum Dashboard</a></div>');
        exit;
    }
    return $u;
}
function setup_needed(): bool { return (int)val("SELECT COUNT(*) FROM users", [], 0) === 0; }

// ===========================================================================
// 7. FACHLOGIK: Zeitraeume, Berichtsheft, Kategorisierung, Statistik
// ===========================================================================

function iso_week_range(int $year, int $week): array {
    $d = new DateTimeImmutable();
    $mo = $d->setISODate($year, $week, 1)->setTime(0, 0);
    return [$mo->format('Y-m-d'), $mo->modify('+6 days')->format('Y-m-d')];
}
function month_range(int $year, int $month): array {
    $f = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    return [$f->format('Y-m-d'), $f->modify('last day of this month')->format('Y-m-d')];
}
function periode_gueltig(string $periode, string $art): bool {
    return (bool)preg_match($art === 'monat' ? '/^\d{4}-(0[1-9]|1[0-2])$/' : '/^\d{4}-W(0[1-9]|[1-4]\d|5[0-3])$/', $periode);
}
function periode_of(string $datum, string $art): string {
    $t = new DateTimeImmutable($datum);
    return $art === 'monat' ? $t->format('Y-m') : $t->format('o-\WW');
}
function periode_range(string $periode, string $art): array {
    if ($art === 'monat') {
        [$y, $m] = array_map('intval', explode('-', $periode));
        return month_range($y, $m);
    }
    [$y, $w] = explode('-W', $periode);
    return iso_week_range((int)$y, (int)$w);
}
function periode_label(string $periode, string $art): string {
    [$von, $bis] = periode_range($periode, $art);
    if ($art === 'monat') {
        $mn = ['','Januar','Februar','Maerz','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
        [$y, $m] = array_map('intval', explode('-', $periode));
        return $mn[$m] . ' ' . $y;
    }
    [$y, $w] = explode('-W', $periode);
    return 'KW ' . (int)$w . ' / ' . $y . ' (' . de_date($von) . ' - ' . de_date($bis) . ')';
}
function periode_shift(string $periode, string $art, int $delta): string {
    [$von] = periode_range($periode, $art);
    $d = new DateTimeImmutable($von);
    return periode_of($d->modify(($delta >= 0 ? '+' : '-') . abs($delta) . ($art === 'monat' ? ' month' : ' week'))
        ->format('Y-m-d'), $art);
}
function ausbildungsjahr_am(array $user, string $datum): int {
    if (empty($user['ausbildung_start'])) return 1;
    try {
        $s = new DateTimeImmutable($user['ausbildung_start']);
        $d = new DateTimeImmutable($datum);
    } catch (Throwable $e) { return 1; }
    if ($d < $s) return 1;
    return min(4, (int)floor($s->diff($d)->days / 365) + 1);
}

/** Findet die passende Berichtsheft-Kategorie zu einem Freitext. */
function kategorie_fuer_text(string $text): ?array {
    static $rules = null;
    if ($rules === null) {
        $rules = all("SELECT r.keyword, r.category_id, r.prio, r.ersetzung, c.name
                      FROM category_rules r JOIN categories c ON c.id = r.category_id
                      WHERE c.aktiv = 1 ORDER BY r.prio DESC, length(r.keyword) DESC");
    }
    $hay = ' ' . mb_strtolower(strtr($text, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss','Ä'=>'ae','Ö'=>'oe','Ü'=>'ue'])) . ' ';
    $best = null; $bestScore = -1;
    foreach ($rules as $r) {
        $kw = mb_strtolower($r['keyword']);
        if ($kw === '' || !str_contains($hay, $kw)) continue;
        // Prioritaet schlaegt Laenge, bei gleicher Prioritaet gewinnt das laengere Stichwort
        $score = (int)$r['prio'] * 1000 + mb_strlen($kw);
        if ($score > $bestScore) { $best = $r; $bestScore = $score; }
    }
    return $best;
}
/** Nachweis-Nummer = chronologischer Rang innerhalb der Nachweise eines Kontos. */
function report_nr(int $uid, string $von): int {
    return (int)val("SELECT COUNT(*) FROM reports WHERE user_id = ? AND von <= ?", [$uid, $von], 0) ?: 1;
}
/** Liefert den Nachweis - oder eine leere Vorschau, ohne ihn anzulegen. */
function report_or_blank(int $uid, string $art, string $periode): array {
    $r = one("SELECT * FROM reports WHERE user_id = ? AND art = ? AND periode = ?", [$uid, $art, $periode]);
    if ($r) return $r;
    [$von, $bis] = periode_range($periode, $art);
    $u = one("SELECT * FROM users WHERE id = ?", [$uid]) ?? [];
    return [
        'id' => 0, 'user_id' => $uid, 'art' => $art, 'periode' => $periode, 'von' => $von, 'bis' => $bis,
        'nr' => report_nr($uid, $von), 'ausbildungsjahr' => ausbildungsjahr_am($u, $von), 'abteilung' => '',
        'schule_text' => '', 'sonstiges' => '', 'status' => 'entwurf', 'eingereicht_am' => null,
        'geprueft_von' => null, 'geprueft_am' => null, 'pruef_notiz' => '', 'sign_azubi' => '',
        'sign_ausbilder' => '', 'created_at' => '', 'updated_at' => '',
    ];
}
function ensure_report(int $uid, string $art, string $periode): array {
    $r = one("SELECT * FROM reports WHERE user_id = ? AND art = ? AND periode = ?", [$uid, $art, $periode]);
    if ($r) return $r;
    [$von, $bis] = periode_range($periode, $art);
    $u  = one("SELECT * FROM users WHERE id = ?", [$uid]) ?? [];
    $nr = (int)val("SELECT COUNT(*) FROM reports WHERE user_id = ? AND art = ?", [$uid, $art], 0) + 1;
    $id = ins('reports', [
        'user_id'         => $uid,
        'art'             => $art,
        'periode'         => $periode,
        'von'             => $von,
        'bis'             => $bis,
        'nr'              => $nr,
        'ausbildungsjahr' => ausbildungsjahr_am($u, $von),
    ]);
    return one("SELECT * FROM reports WHERE id = ?", [$id]);
}
/**
 * Erzeugt Vorschlags-Eintraege fuer einen Berichtsheft-Zeitraum aus:
 * Routine-Protokollen, Tagesnotizen, Abwesenheiten und Blockwochen.
 */
function report_autofill(array $report, array $user): int {
    $von = $report['von']; $bis = $report['bis']; $uid = (int)$report['user_id'];
    $vorhanden = [];
    foreach (all("SELECT datum, text FROM report_entries WHERE report_id = ?", [(int)$report['id']]) as $e) {
        $vorhanden[$e['datum'] . '|' . mb_strtolower(trim($e['text']))] = true;
    }
    $neu = 0;
    $add = function (string $datum, float $std, ?int $cat, string $ort, string $text, string $quelle, ?int $lf = null)
        use (&$vorhanden, &$neu, $report, $uid) {
        $key = $datum . '|' . mb_strtolower(trim($text));
        if ($text === '' || isset($vorhanden[$key])) return;
        $vorhanden[$key] = true;
        ins('report_entries', [
            'report_id'   => (int)$report['id'], 'user_id' => $uid, 'datum' => $datum,
            'stunden'     => $std, 'category_id' => $cat, 'lf_no' => $lf,
            'ort'         => $ort, 'text' => $text, 'quelle' => $quelle,
        ]);
        $neu++;
    };

    // 1) Erledigte Routinen (z.B. "Kaffeemaschine im Betrieb geleert")
    $logs = all("SELECT rl.*, r.name, r.category_id, r.berichtsheft
                 FROM routine_logs rl JOIN routines r ON r.id = rl.routine_id
                 WHERE rl.user_id = ? AND rl.datum BETWEEN ? AND ?
                 ORDER BY rl.datum, rl.id", [$uid, $von, $bis]);
    $grp = [];
    foreach ($logs as $l) {
        if (!(int)$l['berichtsheft']) continue;
        $cat = $l['category_id'] ? (int)$l['category_id'] : null;
        if (!$cat) { $k = kategorie_fuer_text($l['name']); $cat = $k ? (int)$k['category_id'] : null; }
        $key = $l['datum'] . '|' . (string)$cat;
        $grp[$key]['datum'] = $l['datum'];
        $grp[$key]['cat']   = $cat;
        $grp[$key]['min']   = ($grp[$key]['min'] ?? 0) + (int)$l['minuten'];
        $grp[$key]['texte'][$l['name']] = true;
        if (trim((string)$l['notiz']) !== '') $grp[$key]['texte'][trim($l['notiz'])] = true;
    }
    foreach ($grp as $g) {
        $add($g['datum'], round(($g['min'] ?: 0) / 60, 2), $g['cat'], 'betrieb',
             implode('; ', array_keys($g['texte'])), 'routine');
    }

    // 2) Tagesnotizen aus dem Zeitraum
    foreach (all("SELECT * FROM notes WHERE user_id = ? AND datum BETWEEN ? AND ?
                  ORDER BY datum", [$uid, $von, $bis]) as $n) {
        $txt = trim($n['titel'] !== '' ? $n['titel'] : mb_substr($n['body'], 0, 160));
        if ($txt === '') continue;
        $k   = kategorie_fuer_text($txt . ' ' . $n['body']);
        $ort = $n['subject_id'] ? 'schule' : 'betrieb';
        $cat = $k ? (int)$k['category_id'] : null;
        if (!$cat && $ort === 'schule') {
            $cat = (int)val("SELECT id FROM categories WHERE name = 'Berufsschule (Blockunterricht)'", [], 0) ?: null;
        }
        $add($n['datum'], 0, $cat, $ort, $txt, 'notiz', $n['lf_no'] ? (int)$n['lf_no'] : null);
    }

    // 3) Berufsschultage aus Blockwochen + Stundenplan
    $cid = (int)($user['class_id'] ?? 0);
    if ($cid) {
        $cls = one("SELECT * FROM classes WHERE id = ?", [$cid]);
        $bw  = all("SELECT * FROM blockweeks WHERE (class_id = ? OR (class_id IS NULL AND zeitgruppe = ?))
                    AND NOT (bis < ? OR von > ?)",
                    [$cid, (int)($cls['zeitgruppe'] ?? 0), $von, $bis]);
        $catSchule = (int)val("SELECT id FROM categories WHERE name = 'Berufsschule (Blockunterricht)'", [], 0);
        foreach ($bw as $b) {
            if ($b['art'] !== 'schule') continue;
            $d = new DateTimeImmutable(max($b['von'], $von));
            $e = new DateTimeImmutable(min($b['bis'], $bis));
            while ($d <= $e) {
                $wd = (int)$d->format('N');
                if ($wd <= 5) {
                    $faecher = all("SELECT COALESCE(s.name, t.fach_text) AS f FROM timetable t
                                    LEFT JOIN subjects s ON s.id = t.subject_id
                                    WHERE t.class_id = ? AND t.wochentag = ?", [$cid, $wd]);
                    $liste = array_values(array_unique(array_filter(array_column($faecher, 'f'))));
                    $add($d->format('Y-m-d'), 8, $catSchule ?: null, 'schule',
                         'Berufsschule' . ($liste ? ': ' . implode(', ', $liste) : ''), 'routine');
                }
                $d = $d->modify('+1 day');
            }
        }
    }

    // 4) Abwesenheiten
    foreach (all("SELECT * FROM absences WHERE user_id = ? AND NOT (bis < ? OR von > ?)",
                 [$uid, $von, $bis]) as $a) {
        $map = ['krank' => 'Krankheit', 'urlaub' => 'Urlaub', 'frei' => 'Feiertag / arbeitsfrei',
                'beurlaubt' => 'Feiertag / arbeitsfrei', 'dienstreise' => 'Planen, Vorbereiten und Durchfuehren von Arbeitsaufgaben'];
        $cat = (int)val("SELECT id FROM categories WHERE name = ?", [$map[$a['art']] ?? 'Urlaub'], 0);
        $d = new DateTimeImmutable(max($a['von'], $von));
        $e = new DateTimeImmutable(min($a['bis'], $bis));
        while ($d <= $e) {
            if ((int)$d->format('N') <= 5) {
                $add($d->format('Y-m-d'), 0, $cat ?: null, $a['art'] === 'krank' ? 'krank' : 'urlaub',
                     ucfirst($a['art']) . ($a['grund'] ? ' - ' . $a['grund'] : ''), 'routine');
            }
            $d = $d->modify('+1 day');
        }
    }
    return $neu;
}
/** Fasst die Eintraege eines Nachweises nach Kategorie zusammen. */
function report_summary(int $reportId): array {
    $rows = all("SELECT e.*, c.name AS kategorie, c.abschnitt, c.pos_no, c.farbe
                 FROM report_entries e LEFT JOIN categories c ON c.id = e.category_id
                 WHERE e.report_id = ? ORDER BY e.datum, e.sort, e.id", [$reportId]);
    $byCat = []; $byDay = []; $sum = 0.0;
    foreach ($rows as $r) {
        $k = $r['kategorie'] ?: 'Ohne Zuordnung';
        $byCat[$k]['name']    = $k;
        $byCat[$k]['pos']     = $r['pos_no'] ?? '';
        $byCat[$k]['farbe']   = $r['farbe'] ?? '#8d99ae';
        $byCat[$k]['stunden'] = ($byCat[$k]['stunden'] ?? 0) + (float)$r['stunden'];
        $byCat[$k]['texte'][] = $r['text'];
        $byDay[$r['datum']][] = $r;
        $sum += (float)$r['stunden'];
    }
    uasort($byCat, fn($a, $b) => $b['stunden'] <=> $a['stunden']);
    return ['rows' => $rows, 'byCat' => $byCat, 'byDay' => $byDay, 'stunden' => $sum];
}
/** Erzeugt den zusammenfassenden Fliesstext fuer den Ausbildungsnachweis. */
function report_text(int $reportId): string {
    $s = report_summary($reportId);
    $out = [];
    foreach ($s['byCat'] as $c) {
        $texte = array_values(array_unique(array_filter(array_map('trim', $c['texte']))));
        $zeile = ($c['pos'] ? '[' . $c['pos'] . '] ' : '') . $c['name'];
        if ($c['stunden'] > 0) $zeile .= ' (' . num($c['stunden'], 1) . ' h)';
        $zeile .= ': ' . implode('; ', $texte);
        $out[] = $zeile;
    }
    return implode("\n", $out);
}

// --- Noten / Statistik -----------------------------------------------------
function note_to_points(float $wert, string $skala): ?float {
    if ($skala === 'note')   return max(1.0, min(6.0, $wert));
    if ($skala === 'punkte') { // 15-Punkte-System -> Note
        $p = max(0.0, min(15.0, $wert));
        return round(1 + (15 - $p) / 3, 2);
    }
    if ($skala === 'ihk') {   // IHK-Prozentpunkte -> Note (100-Punkte-Schluessel)
        $p = max(0.0, min(100.0, $wert));
        if ($p >= 92) return 1.0; if ($p >= 81) return 2.0; if ($p >= 67) return 3.0;
        if ($p >= 50) return 4.0; if ($p >= 30) return 5.0; return 6.0;
    }
    return null;
}
function grade_stats(int $uid): array {
    $rows = all("SELECT g.*, COALESCE(s.name, g.fach_text) AS fach, s.color
                 FROM grades g LEFT JOIN subjects s ON s.id = g.subject_id
                 WHERE g.user_id = ? ORDER BY g.datum", [$uid]);
    $faecher = []; $sumW = 0.0; $sumV = 0.0; $verteilung = array_fill(1, 6, 0);
    foreach ($rows as $r) {
        $n = note_to_points((float)$r['wert'], $r['skala']);
        if ($n === null) continue;
        $g = max(0.0, (float)$r['gewicht']);
        $f = $r['fach'] ?: 'Ohne Fach';
        $faecher[$f]['name']  = $f;
        $faecher[$f]['color'] = $r['color'] ?: '#4f7cff';
        $faecher[$f]['sumW']  = ($faecher[$f]['sumW'] ?? 0) + $g;
        $faecher[$f]['sumV']  = ($faecher[$f]['sumV'] ?? 0) + $g * $n;
        $faecher[$f]['n'][]   = ['d' => $r['datum'], 'v' => $n, 't' => $r['titel'] ?: $r['art']];
        $sumW += $g; $sumV += $g * $n;
        $verteilung[(int)round($n)] = ($verteilung[(int)round($n)] ?? 0) + 1;
    }
    foreach ($faecher as $k => $f) {
        $faecher[$k]['schnitt'] = $f['sumW'] > 0 ? $f['sumV'] / $f['sumW'] : null;
        $faecher[$k]['anzahl']  = count($f['n']);
        $t = $f['n'];
        $faecher[$k]['trend'] = null;
        if (count($t) >= 2) {
            $half = (int)ceil(count($t) / 2);
            $a = array_slice($t, 0, $half); $b = array_slice($t, $half);
            $av = array_sum(array_column($a, 'v')) / max(1, count($a));
            $bv = array_sum(array_column($b, 'v')) / max(1, count($b));
            $faecher[$k]['trend'] = $av - $bv;   // positiv = Verbesserung
        }
    }
    ksort($faecher);
    return ['rows' => $rows, 'faecher' => $faecher,
            'schnitt' => $sumW > 0 ? $sumV / $sumW : null, 'verteilung' => $verteilung];
}
/** Gewichtung der gestreckten Abschlusspruefung, FR Systemintegration. */
function ihk_bereiche(): array {
    return [
        'ap1'     => ['Teil 1: Einrichten eines IT-gestuetzten Arbeitsplatzes', 20],
        'projekt' => ['Teil 2: Planen und Umsetzen eines Projektes der Systemintegration', 50],
        'kadis'   => ['Teil 2: Konzeption und Administration von IT-Systemen', 10],
        'aevn'    => ['Teil 2: Analyse und Entwicklung von Netzwerken', 10],
        'wiso'    => ['Teil 2: Wirtschafts- und Sozialkunde', 10],
    ];
}
function ihk_prognose(array $punkte): array {
    $b = ihk_bereiche(); $sum = 0; $gew = 0;
    foreach ($b as $k => [$label, $w]) {
        if (!isset($punkte[$k]) || $punkte[$k] === '' || $punkte[$k] === null) continue;
        $sum += ((float)$punkte[$k]) * $w; $gew += $w;
    }
    if ($gew === 0) return ['punkte' => null, 'note' => null, 'abdeckung' => 0];
    $p = $sum / $gew;
    return ['punkte' => $p, 'note' => note_to_points($p, 'ihk'), 'abdeckung' => $gew];
}
function ihk_bestanden(array $punkte): array {
    // Bestehensregeln (FIAusbV 2020, sinngemaess): Gesamt >= 50,
    // Projektarbeit >= 50, Teil-2-Gesamt >= 50, hoechstens ein Bereich < 50 und keiner = 0.
    $p = ihk_prognose($punkte);
    $probleme = [];
    if ($p['punkte'] === null) return ['ok' => null, 'probleme' => ['Noch keine Punkte erfasst']];
    if ($p['punkte'] < 50) $probleme[] = 'Gesamtergebnis unter 50 Punkten';
    if (isset($punkte['projekt']) && $punkte['projekt'] !== '' && (float)$punkte['projekt'] < 50)
        $probleme[] = 'Projektarbeit unter 50 Punkten';
    $t2 = 0; $w2 = 0;
    foreach (['projekt' => 50, 'kadis' => 10, 'aevn' => 10, 'wiso' => 10] as $k => $w) {
        if (isset($punkte[$k]) && $punkte[$k] !== '') { $t2 += (float)$punkte[$k] * $w; $w2 += $w; }
    }
    if ($w2 > 0 && $t2 / $w2 < 50) $probleme[] = 'Teil 2 insgesamt unter 50 Punkten';
    $unter = 0;
    foreach (['kadis','aevn','wiso'] as $k) {
        if (isset($punkte[$k]) && $punkte[$k] !== '') {
            if ((float)$punkte[$k] < 50) $unter++;
            if ((float)$punkte[$k] <= 0) $probleme[] = 'Ein Pruefungsbereich mit 0 Punkten';
        }
    }
    if ($unter > 1) $probleme[] = 'Mehr als ein Pruefungsbereich unter 50 Punkten';
    return ['ok' => empty($probleme), 'probleme' => $probleme];
}

// ===========================================================================
// 8. LAYOUT / OBERFLAECHE
// ===========================================================================

function nav_items(): array {
    $u = current_user();
    $items = [
        ['gruppe' => 'Alltag', 'items' => [
            ['dashboard',   'Start',            'M3 11l9-8 9 8v9a2 2 0 0 1-2 2h-4v-6H9v6H5a2 2 0 0 1-2-2z'],
            ['woche',       'Woche & Plan',     'M3 5h18v16H3zM3 9h18M8 3v4M16 3v4'],
            ['termine',     'Proben & Termine', 'M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z'],
            ['aufgaben',    'Aufgaben',         'M4 6h16M4 12h16M4 18h10'],
            ['notizen',     'Notizen',          'M5 3h11l4 4v14H5zM16 3v5h5'],
            ['wissen',      'Wissen & Stoff',   'M4 4h7v16H4zM13 4h7v16h-7z'],
        ]],
        ['gruppe' => 'Ausbildung', 'items' => [
            ['noten',       'Noten & Statistik','M4 20V10M10 20V4M16 20v-7M22 20H2'],
            ['berichtsheft','Berichtsheft',     'M4 3h13l3 3v15H4zM8 8h8M8 12h8M8 16h5'],
            ['betrieb',     'Betrieb & Routinen','M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8zM12 2v3M12 19v3M2 12h3M19 12h3'],
            ['abwesenheit', 'Abwesenheiten',    'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zM12 7v5l3 3'],
            ['lernfelder',  'Lernfelder',       'M3 6h18v4H3zM3 14h18v4H3z'],
            ['pruefung',    'IHK-Pruefung',     'M12 2l9 5-9 5-9-5zM3 12l9 5 9-5M3 17l9 5 9-5'],
        ]],
    ];
    $verw = [];
    if ($u && in_array($u['role'], ['admin','lehrer','ausbilder'], true)) {
        $verw[] = ['klasse', 'Klasse & Team', 'M17 20v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2M10 6a3 3 0 1 0 0 6 3 3 0 0 0 0-6z'];
    }
    if ($u && in_array($u['role'], ['admin','ausbilder'], true)) {
        $verw[] = ['pruefen', 'Nachweise pruefen', 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11'];
    }
    if ($u && $u['role'] === 'admin') {
        $verw[] = ['admin', 'Verwaltung', 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z'];
    }
    if ($verw) $items[] = ['gruppe' => 'Verwaltung', 'items' => $verw];
    return $items;
}

function render_page(string $title, string $content, array $opts = []): void {
    $u     = current_user();
    $nonce = $GLOBALS['CSP_NONCE'];
    $p     = $_GET['p'] ?? 'dashboard';
    $theme = $u['theme'] ?? 'auto';
    $flash = take_flash();
    $bare  = !empty($opts['bare']);
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html lang="de" data-theme="<?= h($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<title><?= h($title) ?> &middot; <?= h(APP_NAME) ?></title>
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="7" fill="#2f6fdb"/><text x="16" y="22" font-family="system-ui,sans-serif" font-size="15" font-weight="700" fill="#fff" text-anchor="middle">Fi</text></svg>') ?>">
<style>
:root{
  --bg:#f4f6fa; --bg2:#ffffff; --bg3:#eef1f7; --fg:#111826; --fg2:#5a6473; --fg3:#8a93a3;
  --line:#dde2ec; --line2:#c9d1e0; --acc:#2f6fdb; --acc-fg:#fff; --acc-soft:#e5edfc;
  --ok:#1a7f4b; --ok-soft:#e2f5ea; --warn:#a5620a; --warn-soft:#fdf1dd;
  --err:#c02b3f; --err-soft:#fde8eb; --info:#0b6f8a; --info-soft:#e0f3f8;
  --r:10px; --r2:14px; --sh:0 1px 2px rgba(16,24,40,.06),0 1px 3px rgba(16,24,40,.08);
  --sh2:0 4px 16px rgba(16,24,40,.10); --mono:ui-monospace,"Cascadia Mono","Segoe UI Mono",Consolas,monospace;
}
@media (prefers-color-scheme:dark){ :root:not([data-theme="hell"]){
  --bg:#0e1219; --bg2:#161b25; --bg3:#1d232f; --fg:#e8ecf3; --fg2:#a3adbd; --fg3:#7b8798;
  --line:#262e3c; --line2:#354055; --acc:#5c93ff; --acc-fg:#0b1220; --acc-soft:#18243c;
  --ok:#49c98a; --ok-soft:#12291f; --warn:#e5a94a; --warn-soft:#2b2214; --err:#ff7285;
  --err-soft:#2e161c; --info:#54c4e0; --info-soft:#122630;
  --sh:0 1px 2px rgba(0,0,0,.4); --sh2:0 6px 24px rgba(0,0,0,.5);
}}
:root[data-theme="dunkel"]{
  --bg:#0e1219; --bg2:#161b25; --bg3:#1d232f; --fg:#e8ecf3; --fg2:#a3adbd; --fg3:#7b8798;
  --line:#262e3c; --line2:#354055; --acc:#5c93ff; --acc-fg:#0b1220; --acc-soft:#18243c;
  --ok:#49c98a; --ok-soft:#12291f; --warn:#e5a94a; --warn-soft:#2b2214; --err:#ff7285;
  --err-soft:#2e161c; --info:#54c4e0; --info-soft:#122630;
  --sh:0 1px 2px rgba(0,0,0,.4); --sh2:0 6px 24px rgba(0,0,0,.5);
}
*,*::before,*::after{box-sizing:border-box}
html,body{margin:0;padding:0}
body{background:var(--bg);color:var(--fg);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",sans-serif;-webkit-text-size-adjust:100%}
a{color:var(--acc);text-decoration:none}
a:hover{text-decoration:underline}
h1,h2,h3,h4{margin:0 0 .5rem;line-height:1.25;font-weight:650}
h1{font-size:1.5rem} h2{font-size:1.15rem} h3{font-size:1rem}
p{margin:0 0 .75rem}
hr{border:0;border-top:1px solid var(--line);margin:1rem 0}
small,.small{font-size:.82rem}
.muted{color:var(--fg2)} .muted2{color:var(--fg3)}
code,kbd,pre{font-family:var(--mono);font-size:.87em}
kbd{background:var(--bg3);border:1px solid var(--line2);border-bottom-width:2px;border-radius:5px;padding:1px 5px;font-size:.75rem}
pre{background:var(--bg3);border:1px solid var(--line);border-radius:var(--r);padding:.75rem .9rem;overflow:auto;margin:.5rem 0}
/* ---- Layout ---- */
.scrim{display:none}
.app{display:grid;grid-template-columns:250px 1fr;min-height:100vh}
.side{background:var(--bg2);border-right:1px solid var(--line);display:flex;flex-direction:column;position:sticky;top:0;height:100vh;overflow-y:auto}
.brand{display:flex;align-items:center;gap:.6rem;padding:1rem 1rem .75rem;font-weight:700;font-size:1.02rem;letter-spacing:-.01em}
.brand .logo{width:30px;height:30px;border-radius:8px;background:var(--acc);color:var(--acc-fg);display:grid;place-items:center;font-size:.8rem;font-weight:800;flex:none}
.brand small{display:block;font-weight:500;color:var(--fg3);font-size:.72rem;letter-spacing:0}
.navgrp{padding:.35rem 0}
.navgrp h6{margin:.6rem 1rem .25rem;font-size:.68rem;text-transform:uppercase;letter-spacing:.08em;color:var(--fg3);font-weight:700}
.nav a{display:flex;align-items:center;gap:.6rem;padding:.44rem 1rem;color:var(--fg2);border-left:3px solid transparent;font-size:.9rem}
.nav a:hover{background:var(--bg3);color:var(--fg);text-decoration:none}
.nav a.on{background:var(--acc-soft);color:var(--acc);border-left-color:var(--acc);font-weight:600}
.nav svg{width:17px;height:17px;flex:none;stroke:currentColor;fill:none;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}
.nav .cnt{margin-left:auto;background:var(--bg3);border-radius:99px;padding:0 .42rem;font-size:.72rem;font-weight:700;color:var(--fg2)}
.nav a.on .cnt{background:var(--acc);color:var(--acc-fg)}
.sidefoot{margin-top:auto;border-top:1px solid var(--line);padding:.6rem 1rem;font-size:.8rem;color:var(--fg3)}
.main{min-width:0;display:flex;flex-direction:column}
.top{position:sticky;top:0;z-index:30;background:color-mix(in srgb,var(--bg2) 92%,transparent);backdrop-filter:blur(8px);border-bottom:1px solid var(--line);display:flex;align-items:center;gap:.6rem;padding:.55rem 1.1rem}
.top h1{margin:0;font-size:1.05rem;font-weight:650}
.top .sp{flex:1}
.wrap{padding:1.1rem;max-width:1400px;width:100%}
@media(max-width:900px){
  .app{grid-template-columns:1fr}
  .side{position:fixed;left:0;top:0;bottom:0;width:255px;z-index:60;transform:translateX(-100%);transition:transform .18s ease;box-shadow:var(--sh2)}
  body.navopen .side{transform:none}
  body.navopen .scrim{display:block;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:50}
  .wrap{padding:.8rem}
}
@media(min-width:901px){ .top .burger{display:none} }
/* ---- Bausteine ---- */
.card{background:var(--bg2);border:1px solid var(--line);border-radius:var(--r2);padding:1rem 1.1rem;box-shadow:var(--sh);margin-bottom:1rem;min-width:0;max-width:100%}
.card>h2:first-child,.card>h3:first-child{margin-top:0}
.card.tight{padding:.75rem .85rem}
.grid{display:grid;gap:1rem}
.grid>*{min-width:0}
.g2{grid-template-columns:repeat(auto-fit,minmax(300px,1fr))}
.g3{grid-template-columns:repeat(auto-fit,minmax(230px,1fr))}
.g4{grid-template-columns:repeat(auto-fit,minmax(170px,1fr))}
.row{display:flex;gap:.55rem;flex-wrap:wrap;align-items:center}
.row.end{justify-content:flex-end}
.stack{display:flex;flex-direction:column;gap:.55rem}
.btn{display:inline-flex;align-items:center;gap:.4rem;background:var(--bg2);color:var(--fg);border:1px solid var(--line2);border-radius:9px;padding:.42rem .8rem;font:inherit;font-size:.88rem;font-weight:550;cursor:pointer;white-space:nowrap;transition:.12s}
.btn:hover{background:var(--bg3);text-decoration:none}
.btn:active{transform:translateY(1px)}
.btn.pri{background:var(--acc);border-color:var(--acc);color:var(--acc-fg)}
.btn.pri:hover{filter:brightness(1.08)}
.btn.dan{color:var(--err);border-color:color-mix(in srgb,var(--err) 45%,var(--line2))}
.btn.dan:hover{background:var(--err-soft)}
.btn.sm{padding:.24rem .55rem;font-size:.8rem}
.btn.ghost{border-color:transparent;background:transparent}
.btn.ghost:hover{background:var(--bg3)}
.btn[disabled]{opacity:.5;cursor:not-allowed}
label{display:block;font-size:.8rem;font-weight:600;color:var(--fg2);margin:0 0 .2rem}
input,select,textarea{width:100%;background:var(--bg2);color:var(--fg);border:1px solid var(--line2);border-radius:9px;padding:.44rem .6rem;font:inherit;font-size:.9rem}
input:focus,select:focus,textarea:focus{outline:2px solid var(--acc);outline-offset:-1px;border-color:var(--acc)}
textarea{min-height:90px;resize:vertical;line-height:1.5}
input[type=checkbox],input[type=radio]{width:auto;accent-color:var(--acc)}
fieldset{border:1px solid var(--line);border-radius:var(--r);padding:.7rem .9rem;margin:0 0 .8rem}
legend{font-size:.8rem;font-weight:700;color:var(--fg2);padding:0 .35rem}
.f{margin-bottom:.6rem}
.fgrid{display:grid;gap:.6rem;grid-template-columns:repeat(auto-fit,minmax(180px,1fr))}
table{width:100%;border-collapse:collapse;font-size:.88rem}
th,td{text-align:left;padding:.42rem .55rem;border-bottom:1px solid var(--line);vertical-align:top}
th{font-size:.74rem;text-transform:uppercase;letter-spacing:.05em;color:var(--fg3);font-weight:700;white-space:nowrap}
tbody tr:hover{background:var(--bg3)}
.tw{overflow-x:auto;-webkit-overflow-scrolling:touch;max-width:100%}
.tag{display:inline-flex;align-items:center;gap:.25rem;background:var(--bg3);border:1px solid var(--line);border-radius:99px;padding:.05rem .5rem;font-size:.74rem;font-weight:600;color:var(--fg2);white-space:nowrap}
.tag.ok{background:var(--ok-soft);color:var(--ok);border-color:transparent}
.tag.warn{background:var(--warn-soft);color:var(--warn);border-color:transparent}
.tag.err{background:var(--err-soft);color:var(--err);border-color:transparent}
.tag.info{background:var(--info-soft);color:var(--info);border-color:transparent}
.tag.acc{background:var(--acc-soft);color:var(--acc);border-color:transparent}
.dot{width:9px;height:9px;border-radius:99px;display:inline-block;flex:none}
.msg{border-radius:var(--r);padding:.6rem .85rem;margin-bottom:.75rem;font-size:.9rem;border:1px solid transparent;display:flex;gap:.5rem;align-items:flex-start}
.msg.ok{background:var(--ok-soft);color:var(--ok)}
.msg.warn{background:var(--warn-soft);color:var(--warn)}
.msg.err{background:var(--err-soft);color:var(--err)}
.msg.info{background:var(--info-soft);color:var(--info)}
.kpi{background:var(--bg2);border:1px solid var(--line);border-radius:var(--r2);padding:.75rem .9rem;box-shadow:var(--sh)}
.kpi .v{font-size:1.6rem;font-weight:700;letter-spacing:-.02em;line-height:1.1}
.kpi .l{font-size:.76rem;color:var(--fg3);text-transform:uppercase;letter-spacing:.05em;font-weight:700;margin-bottom:.15rem}
.kpi .s{font-size:.8rem;color:var(--fg2);margin-top:.15rem}
.bar{height:8px;background:var(--bg3);border-radius:99px;overflow:hidden;margin-top:.35rem}
.bar>i{display:block;height:100%;background:var(--acc);border-radius:99px}
.empty{text-align:center;padding:1.6rem .8rem;color:var(--fg3)}
.empty svg{width:34px;height:34px;stroke:currentColor;fill:none;stroke-width:1.4;opacity:.6;margin-bottom:.3rem}
.list{list-style:none;margin:0;padding:0}
.list li{display:flex;gap:.6rem;align-items:flex-start;padding:.45rem 0;border-bottom:1px solid var(--line)}
.list li:last-child{border-bottom:0}
.chips{display:flex;gap:.35rem;flex-wrap:wrap}
.chip{background:var(--bg3);border:1px solid var(--line);border-radius:99px;padding:.14rem .6rem;font-size:.78rem;color:var(--fg2);cursor:pointer}
.chip.on{background:var(--acc);border-color:var(--acc);color:var(--acc-fg);font-weight:600}
details.acc{border:1px solid var(--line);border-radius:var(--r);margin-bottom:.5rem;background:var(--bg2)}
details.acc>summary{cursor:pointer;padding:.5rem .8rem;font-weight:600;font-size:.9rem;list-style:none;display:flex;align-items:center;gap:.5rem}
details.acc>summary::-webkit-details-marker{display:none}
details.acc>summary::before{content:"\25B8";color:var(--fg3);transition:.15s}
details.acc[open]>summary::before{transform:rotate(90deg)}
details.acc>div{padding:0 .8rem .7rem}
.split{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:1rem;align-items:start}
.split>*{min-width:0}
@media(max-width:1100px){.split{grid-template-columns:1fr}}
.scroller{max-height:420px;overflow:auto}
.sticky-actions{position:sticky;bottom:0;background:var(--bg2);border-top:1px solid var(--line);padding:.6rem 0 0;margin-top:.6rem}
.qa{display:flex;gap:.4rem;flex-wrap:wrap}
.qa>select{width:100%}
.qa>input[name=text]{flex:1 1 100%;min-width:0}
.qa>input[type=date]{flex:1 1 auto}
@media(min-width:760px){
  .qa{flex-wrap:nowrap}
  .qa>select{width:auto;min-width:150px;flex:none}
  .qa>input[name=text]{flex:1 1 auto}
  .qa>input[type=date]{width:auto;flex:none}
}
/* Kalender */
.cal{display:grid;grid-template-columns:repeat(7,1fr);gap:3px}
.cal .h{font-size:.7rem;font-weight:700;color:var(--fg3);text-align:center;text-transform:uppercase;padding:.2rem 0}
.cal .d{background:var(--bg3);border-radius:8px;min-height:74px;padding:.28rem .32rem;font-size:.75rem;border:1px solid transparent}
.cal .d.out{opacity:.4}
.cal .d.today{border-color:var(--acc);background:var(--acc-soft)}
.cal .d .n{font-weight:700;color:var(--fg2);font-size:.72rem}
.cal .ev{display:block;border-radius:4px;padding:0 .25rem;margin-top:2px;font-size:.68rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#fff}
/* Stundenplan */
.tt{display:grid;grid-template-columns:44px repeat(5,1fr);gap:3px;font-size:.78rem}
.tt .hd{font-weight:700;color:var(--fg3);text-align:center;font-size:.7rem;text-transform:uppercase;padding:.2rem 0}
.tt .sl{color:var(--fg3);text-align:right;padding-right:.3rem;font-size:.7rem;line-height:2.2}
.tt .c{background:var(--bg3);border-radius:7px;padding:.25rem .35rem;min-height:34px;border-left:3px solid transparent}
/* Command palette */
.cmdk{position:fixed;inset:0;background:rgba(6,10,18,.5);z-index:100;display:none;padding-top:12vh}
.cmdk.on{display:block}
.cmdk .box{max-width:560px;margin:0 auto;background:var(--bg2);border:1px solid var(--line2);border-radius:14px;box-shadow:var(--sh2);overflow:hidden}
.cmdk input{border:0;border-bottom:1px solid var(--line);border-radius:0;padding:.8rem 1rem;font-size:1rem}
.cmdk input:focus{outline:none}
.cmdk ul{list-style:none;margin:0;padding:.3rem;max-height:52vh;overflow:auto}
.cmdk li{padding:.5rem .7rem;border-radius:8px;cursor:pointer;font-size:.9rem;display:flex;gap:.5rem;align-items:center}
.cmdk li.on,.cmdk li:hover{background:var(--acc-soft);color:var(--acc)}
.cmdk li .k{margin-left:auto;font-size:.72rem;color:var(--fg3)}
.copybtn{float:right;margin:-.2rem 0 0 .3rem}
.gradepill{display:inline-grid;place-items:center;width:26px;height:26px;border-radius:7px;font-weight:800;font-size:.85rem;color:#fff}
.spark{display:block}
@media print{
  .side,.top,.noprint,.cmdk{display:none!important}
  .app{display:block} .wrap{padding:0;max-width:none}
  .card{border:0;box-shadow:none;padding:0;margin:0 0 .6rem;break-inside:avoid}
  body{background:#fff;color:#000;font-size:11pt}
  a{color:#000;text-decoration:none}
  .printonly{display:block!important}
  table{font-size:10pt} th,td{border-color:#999}
  @page{margin:15mm}
}
.printonly{display:none}
</style>
</head>
<body>
<?php if ($bare): ?>
  <div class="wrap" style="max-width:520px;margin:0 auto;padding-top:6vh"><?= $content ?></div>
<?php else: ?>
<div class="scrim" data-nav="close"></div>
<div class="app">
  <aside class="side">
    <div class="brand">
      <span class="logo">Fi</span>
      <span><?= h(APP_SHORT) ?>-Portal<small><?= h(setting('schule_kurz', 'BS FiSi')) ?></small></span>
    </div>
    <nav class="nav">
      <?php foreach (nav_items() as $grp): ?>
        <div class="navgrp"><h6><?= h($grp['gruppe']) ?></h6>
        <?php foreach ($grp['items'] as [$key, $label, $path]): ?>
          <a href="<?= url($key) ?>" class="<?= $p === $key ? 'on' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="<?= h($path) ?>"/></svg><?= h($label) ?>
            <?php $c = nav_badge($key); if ($c): ?><span class="cnt"><?= h($c) ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </nav>
    <div class="sidefoot">
      <?php if ($u): ?>
        <div style="font-weight:650;color:var(--fg)"><?= h($u['display_name'] ?: $u['username']) ?></div>
        <div><?= h(rolle_label($u['role'])) ?><?php if ($u['class_id']): ?> &middot; <?= h((string)val("SELECT name FROM classes WHERE id=?", [(int)$u['class_id']], '')) ?><?php endif; ?></div>
        <div class="row" style="margin-top:.4rem">
          <a class="btn sm ghost" href="<?= url('profil') ?>">Profil</a>
          <form method="post" action="<?= url('logout') ?>" style="display:inline"><?= csrf_field() ?>
            <button class="btn sm ghost" type="submit">Abmelden</button></form>
        </div>
      <?php endif; ?>
    </div>
  </aside>
  <div class="main">
    <header class="top noprint">
      <button class="btn sm ghost burger" data-nav="open" aria-label="Menue">&#9776;</button>
      <h1><?= h($title) ?></h1>
      <span class="sp"></span>
      <?= $opts['actions'] ?? '' ?>
      <button class="btn sm ghost" data-cmdk="1" title="Schnellzugriff (Strg+K)">&#128269; <kbd>Strg K</kbd></button>
      <form method="post" action="<?= url('theme') ?>" style="display:inline"><?= csrf_field() ?>
        <input type="hidden" name="theme" value="<?= $theme === 'dunkel' ? 'hell' : ($theme === 'hell' ? 'auto' : 'dunkel') ?>">
        <button class="btn sm ghost" type="submit" title="Design: <?= h($theme) ?>"><?= $theme === 'dunkel' ? '&#9788;' : ($theme === 'hell' ? '&#9789;' : '&#9681;') ?></button>
      </form>
    </header>
    <div class="wrap">
      <?php foreach ($flash as $f): ?>
        <div class="msg <?= h($f['t']) ?>"><span><?= h($f['m']) ?></span></div>
      <?php endforeach; ?>
      <?= $content ?>
    </div>
  </div>
</div>
<div class="cmdk" id="cmdk"><div class="box">
  <input type="search" id="cmdq" placeholder="Springe zu ... oder tippe zum Suchen" autocomplete="off">
  <ul id="cmdl"></ul>
</div></div>
<?php endif; ?>
<script nonce="<?= h($nonce) ?>">
(function(){
 var B=<?= json_encode(base_path()) ?>;
 document.addEventListener('click',function(e){
   var t=e.target.closest('[data-nav]');
   if(t){document.body.classList.toggle('navopen',t.dataset.nav==='open');}
   var c=e.target.closest('[data-cmdk]'); if(c){openCmd();}
   var cp=e.target.closest('[data-copy]');
   if(cp){var el=document.getElementById(cp.dataset.copy);
     if(el&&navigator.clipboard){navigator.clipboard.writeText(el.innerText).then(function(){
       var o=cp.textContent;cp.textContent='kopiert';setTimeout(function(){cp.textContent=o;},1200);});}}
 });
 document.addEventListener('submit',function(e){
   var f=e.target, m=f.getAttribute('data-confirm');
   if(m&&!confirm(m)) e.preventDefault();
 });
 // ---- Schnellzugriff -----------------------------------------------------
 var NAV=<?= json_encode(cmd_targets(), JSON_UNESCAPED_UNICODE) ?>;
 var box=document.getElementById('cmdk'),qi=document.getElementById('cmdq'),ul=document.getElementById('cmdl'),sel=0,cur=[];
 function draw(){ var q=(qi.value||'').toLowerCase().trim();
   cur=NAV.filter(function(n){return !q||n.t.toLowerCase().indexOf(q)>=0||(n.k||'').indexOf(q)>=0;});
   if(q) cur=cur.concat([{t:'Volltextsuche nach "'+qi.value+'"',u:B+'?p=suche&q='+encodeURIComponent(qi.value),k:''}]);
   sel=Math.min(sel,cur.length-1); if(sel<0)sel=0;
   ul.innerHTML=cur.map(function(n,i){return '<li'+(i===sel?' class="on"':'')+' data-u="'+n.u+'">'+
     n.t.replace(/[<>&]/g,'')+(n.k?'<span class="k">'+n.k+'</span>':'')+'</li>';}).join('');
 }
 function openCmd(){ box.classList.add('on'); qi.value=''; sel=0; draw(); qi.focus(); }
 function closeCmd(){ box.classList.remove('on'); }
 if(box){
   qi.addEventListener('input',draw);
   ul.addEventListener('click',function(e){var li=e.target.closest('li'); if(li) location.href=li.dataset.u;});
   box.addEventListener('click',function(e){ if(e.target===box) closeCmd(); });
   document.addEventListener('keydown',function(e){
     if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();openCmd();return;}
     if(!box.classList.contains('on'))return;
     if(e.key==='Escape'){closeCmd();}
     else if(e.key==='ArrowDown'){e.preventDefault();sel=Math.min(sel+1,cur.length-1);draw();}
     else if(e.key==='ArrowUp'){e.preventDefault();sel=Math.max(sel-1,0);draw();}
     else if(e.key==='Enter'){e.preventDefault(); if(cur[sel])location.href=cur[sel].u;}
   });
 }
 // ---- Tabellenfilter ------------------------------------------------------
 document.querySelectorAll('[data-filter]').forEach(function(inp){
   inp.addEventListener('input',function(){
     var q=this.value.toLowerCase(), tb=document.querySelector(this.dataset.filter);
     if(!tb)return;
     tb.querySelectorAll('tbody tr').forEach(function(tr){
       tr.style.display = !q || tr.innerText.toLowerCase().indexOf(q)>=0 ? '' : 'none';
     });
   });
 });
 // ---- Autosave von Entwuerfen (nur lokal im Browser) ----------------------
 document.querySelectorAll('textarea[data-draft]').forEach(function(ta){
   var k='fisi:draft:'+ta.dataset.draft;
   try{ var v=localStorage.getItem(k); if(v&&!ta.value){ta.value=v;} }catch(e){}
   ta.addEventListener('input',function(){ try{localStorage.setItem(k,ta.value);}catch(e){} });
   if(ta.form) ta.form.addEventListener('submit',function(){ try{localStorage.removeItem(k);}catch(e){} });
 });
 // ---- Abmelde-Countdown ---------------------------------------------------
 var idle=<?= (int)SESSION_IDLE ?>*1000, tmr;
 function reset(){ clearTimeout(tmr); tmr=setTimeout(function(){ location.href=B+'?p=login&timeout=1'; }, idle+5000); }
 ['click','keydown','scroll'].forEach(function(ev){document.addEventListener(ev,reset,{passive:true});});
 reset();
})();
</script>
</body></html><?php
}

function rolle_label(string $r): string {
    return ['admin' => 'Administration', 'lehrer' => 'Lehrkraft', 'ausbilder' => 'Ausbilder/-in',
            'azubi' => 'Auszubildende/-r'][$r] ?? $r;
}
function nav_badge(string $key): string {
    static $cache = null;
    $u = current_user();
    if (!$u) return '';
    if ($cache === null) {
        $uid = (int)$u['id'];
        $cache = [
            'aufgaben' => (int)val("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status = 'offen'", [$uid], 0),
            'termine'  => (int)val("SELECT COUNT(*) FROM events WHERE datum BETWEEN date('now','localtime') AND date('now','localtime','+14 day')
                                    AND (visibility = 'class' AND class_id = ? OR user_id = ?)",
                                    [(int)($u['class_id'] ?: 0), $uid], 0),
            'pruefen'  => can_review() ? (int)val("SELECT COUNT(*) FROM reports WHERE status = 'eingereicht'", [], 0) : 0,
        ];
    }
    $v = $cache[$key] ?? 0;
    return $v > 0 ? (string)$v : '';
}
function cmd_targets(): array {
    $t = [];
    foreach (nav_items() as $g) foreach ($g['items'] as [$k, $label]) {
        $t[] = ['t' => $label, 'u' => url($k), 'k' => ''];
    }
    $extra = [
        ['Neue Probe / Termin eintragen', url('termine', ['neu' => 1])],
        ['Neue Notiz anlegen',            url('notizen', ['neu' => 1])],
        ['Note eintragen',                url('noten', ['neu' => 1])],
        ['Berichtsheft dieser Woche',     url('berichtsheft')],
        ['Routine abhaken (Betrieb)',     url('betrieb')],
        ['Abwesenheit melden',            url('abwesenheit')],
        ['Profil & Sicherheit',           url('profil')],
        ['Zwei-Faktor einrichten',        url('profil', ['tab' => '2fa'])],
        ['Eigene Daten exportieren',      url('profil', ['tab' => 'export'])],
    ];
    foreach ($extra as [$label, $u]) $t[] = ['t' => $label, 'u' => $u, 'k' => ''];
    return $t;
}

// ===========================================================================
// 9. KLEINE BAUSTEINE
// ===========================================================================

function ui_empty(string $text, string $hint = ''): string {
    return '<div class="empty"><svg viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/><path d="M8 10h8M8 14h5"/></svg>'
         . '<div>' . h($text) . '</div>' . ($hint ? '<div class="small muted2">' . h($hint) . '</div>' : '') . '</div>';
}
function ui_kpi(string $label, string $value, string $sub = '', ?float $pct = null, string $farbe = ''): string {
    $bar = $pct === null ? '' : '<div class="bar"><i style="width:' . max(0, min(100, $pct)) . '%'
         . ($farbe ? ';background:' . h($farbe) : '') . '"></i></div>';
    return '<div class="kpi"><div class="l">' . h($label) . '</div><div class="v">' . $value . '</div>'
         . ($sub ? '<div class="s">' . $sub . '</div>' : '') . $bar . '</div>';
}
function note_farbe(?float $n): string {
    if ($n === null) return '#8d99ae';
    if ($n <= 1.5) return '#1a7f4b'; if ($n <= 2.5) return '#5b8f22';
    if ($n <= 3.5) return '#c98a12'; if ($n <= 4.5) return '#d97706';
    if ($n <= 5.5) return '#dc2626'; return '#991b1b';
}
function ui_note(?float $n): string {
    if ($n === null) return '<span class="muted2">-</span>';
    return '<span class="gradepill" style="background:' . h(note_farbe($n)) . '">' . num($n, 1) . '</span>';
}
function ui_spark(array $werte, int $w = 120, int $hgt = 30, bool $invert = true): string {
    $n = count($werte);
    if ($n < 2) return '';
    $min = min($werte); $max = max($werte);
    if ($invert) { $min = 1; $max = 6; }
    $sp = max(0.001, $max - $min);
    $pts = [];
    foreach (array_values($werte) as $i => $v) {
        $x = $i * ($w - 4) / ($n - 1) + 2;
        $y = $hgt - 2 - (($v - $min) / $sp) * ($hgt - 4);
        if ($invert) $y = $hgt - $y;
        $pts[] = round($x, 1) . ',' . round($y, 1);
    }
    return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $hgt . '" width="' . $w . '" height="' . $hgt . '">'
         . '<polyline fill="none" stroke="' . h(note_farbe(array_sum($werte) / $n)) . '" stroke-width="2" '
         . 'stroke-linecap="round" stroke-linejoin="round" points="' . implode(' ', $pts) . '"/></svg>';
}
/** @param array $paare Liste aus [Bezeichnung, Balkenwert, Farbe, (optional) Anzeigetext] */
function ui_hbar(array $paare, string $einheit = 'h'): string {
    if (!$paare) return '<p class="small muted">Keine Daten.</p>';
    $max = max(0.0001, max(array_map(fn($p) => (float)$p[1], $paare)));
    $out = '<div class="stack">';
    foreach ($paare as $p) {
        [$label, $wert, $farbe] = [$p[0], (float)$p[1], $p[2] ?? ''];
        $anzeige = $p[3] ?? (num($wert, 1) . ($einheit !== '' ? ' ' . $einheit : ''));
        $pc = max(1.5, ($wert / $max) * 100);
        $out .= '<div><div class="row" style="justify-content:space-between;gap:.4rem">'
              . '<span class="small">' . h($label) . '</span>'
              . '<span class="small muted">' . h($anzeige) . '</span></div>'
              . '<div class="bar"><i style="width:' . round($pc, 1) . '%;background:' . h($farbe ?: '#4f7cff') . '"></i></div></div>';
    }
    return $out . '</div>';
}
function opts(array $rows, $sel, string $idKey = 'id', string $labelKey = 'name', string $leer = ''): string {
    $o = $leer !== '' ? '<option value="">' . h($leer) . '</option>' : '';
    foreach ($rows as $r) {
        $id = is_array($r) ? $r[$idKey] : $r;
        $lb = is_array($r) ? $r[$labelKey] : $r;
        $o .= '<option value="' . h($id) . '"' . ((string)$id === (string)$sel ? ' selected' : '') . '>' . h($lb) . '</option>';
    }
    return $o;
}
function opts_simple(array $map, $sel): string {
    $o = '';
    foreach ($map as $k => $v) $o .= '<option value="' . h($k) . '"' . ((string)$k === (string)$sel ? ' selected' : '') . '>' . h($v) . '</option>';
    return $o;
}
/** Sehr einfache, sichere Textauszeichnung fuer Notizen (kein HTML erlaubt). */
function md_lite(string $s): string {
    $out = [];
    $code = false;
    $buf  = [];
    $flush = function () use (&$buf, &$out) {
        if ($buf) { $out[] = '<pre><code>' . implode("\n", $buf) . '</code></pre>'; $buf = []; }
    };
    foreach (preg_split('/\R/', $s) as $line) {
        if (preg_match('/^```/', $line)) {
            if ($code) $flush();
            $code = !$code; continue;
        }
        if ($code) { $buf[] = h($line); continue; }
        $l = h($line);
        $l = preg_replace('/`([^`]+)`/', '<code>$1</code>', $l);
        $l = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $l);
        $l = preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/', '<em>$1</em>', $l);
        $l = preg_replace_callback('~https?://[^\s<]+~', fn($m) => '<a href="' . $m[0] . '" rel="noopener noreferrer nofollow">' . $m[0] . '</a>', $l);
        if (preg_match('/^\s*[-*]\s+(.*)$/', $line, $m))      $l = '&bull; ' . h($m[1]);
        elseif (preg_match('/^\s*#{1,3}\s+(.*)$/', $line, $m)) $l = '<strong>' . h($m[1]) . '</strong>';
        $out[] = $l;
    }
    $flush();
    return implode("<br>\n", $out);
}
function fach_options(?int $classId, $sel): string {
    $rows = all("SELECT id, name FROM subjects WHERE archived = 0 AND (class_id IS NULL OR class_id = ?)
                 ORDER BY sort, name", [$classId ?: 0]);
    return opts($rows, $sel, 'id', 'name', '- Fach -');
}
function lf_options($sel): string {
    $rows = all("SELECT nr AS id, (code || ' - ' || titel) AS name FROM lernfelder ORDER BY nr");
    return opts($rows, $sel, 'id', 'name', '- Lernfeld -');
}
function kat_options($sel, string $leer = '- Kategorie -'): string {
    $rows = all("SELECT id, (CASE WHEN pos_no <> '' THEN '[' || pos_no || '] ' ELSE '' END || name) AS name
                 FROM categories WHERE aktiv = 1 ORDER BY sort, name");
    return opts($rows, $sel, 'id', 'name', $leer);
}

// ===========================================================================
// 10. EINRICHTUNG / ANMELDUNG
// ===========================================================================

function setup_token_file(): string { return DATA_DIR . '/SETUP-TOKEN.txt'; }
function setup_token(): string {
    $f = setup_token_file();
    if (!is_file($f)) { file_put_contents($f, rand_code(10) . "\n"); @chmod($f, 0600); }
    return trim((string)file_get_contents($f));
}
function is_local_request(): bool {
    return in_array(client_ip(), ['127.0.0.1', '::1', 'localhost'], true);
}

function page_setup(): void {
    if (!setup_needed()) redirect(url('login'));
    $tok   = setup_token();
    $local = is_local_request();
    $err   = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (!$local && !hash_equals($tok, post('token'))) $err[] = 'Setup-Token stimmt nicht.';
        $user = preg_replace('/[^A-Za-z0-9._-]/', '', post('username'));
        $name = post('display_name');
        $mail = filter_var(post('email'), FILTER_VALIDATE_EMAIL) ?: '';
        $pw   = (string)($_POST['password'] ?? '');
        if (mb_strlen($user) < 3) $err[] = 'Benutzername: mindestens 3 Zeichen (A-Z, 0-9, . _ -).';
        if ($pw !== (string)($_POST['password2'] ?? '')) $err[] = 'Die Passwoerter stimmen nicht ueberein.';
        foreach (pw_problems($pw, $user, $name) as $p) $err[] = 'Passwort: ' . $p;
        if (!$err) {
            $uid = ins('users', [
                'username' => $user, 'email' => $mail ?: null, 'pass_hash' => pw_hash($pw),
                'role' => 'admin', 'display_name' => $name ?: $user,
                'ics_token' => bin2hex(random_bytes(16)), 'pw_changed_at' => date('Y-m-d H:i:s'),
            ]);
            if (post('schule') !== '') setting_set('schule', post('schule'));
            if (post('schule_kurz') !== '') setting_set('schule_kurz', post('schule_kurz'));
            @unlink(setup_token_file());
            audit('setup_admin', $user);
            flash('Einrichtung abgeschlossen. Bitte jetzt anmelden.', 'ok');
            redirect(url('login'));
        }
    }
    ob_start(); ?>
    <div class="card">
      <h1>Ersteinrichtung</h1>
      <p class="muted">Lege das Administrationskonto an. Danach ist die Registrierung
      ausschliesslich ueber Einladungscodes moeglich.</p>
      <?php foreach ($err as $e): ?><div class="msg err"><?= h($e) ?></div><?php endforeach; ?>
      <?php if ($local): ?>
        <div class="msg ok">Zugriff von localhost erkannt - kein Token noetig.</div>
      <?php else: ?>
        <div class="msg info">Setup-Token steht in der Datei <code><?= h(setup_token_file()) ?></code> auf dem Server.</div>
      <?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <?php if (!$local): ?>
          <div class="f"><label for="tk">Setup-Token</label><input id="tk" name="token" required autocomplete="off"></div>
        <?php endif; ?>
        <div class="fgrid">
          <div class="f"><label for="us">Benutzername</label><input id="us" name="username" required autocomplete="username" value="<?= h(post('username')) ?>"></div>
          <div class="f"><label for="dn">Anzeigename</label><input id="dn" name="display_name" value="<?= h(post('display_name')) ?>"></div>
        </div>
        <div class="f"><label for="em">E-Mail (optional)</label><input id="em" name="email" type="email" value="<?= h(post('email')) ?>"></div>
        <div class="fgrid">
          <div class="f"><label for="pw">Passwort</label><input id="pw" name="password" type="password" required autocomplete="new-password"></div>
          <div class="f"><label for="pw2">Passwort wiederholen</label><input id="pw2" name="password2" type="password" required autocomplete="new-password"></div>
        </div>
        <div class="small muted">Mindestens <?= PW_MIN_LEN ?> Zeichen, 3 von 4 Zeichenarten, keine offensichtlichen Woerter.</div>
        <hr>
        <div class="fgrid">
          <div class="f"><label for="sc">Schule</label><input id="sc" name="schule" value="<?= h(setting('schule')) ?>"></div>
          <div class="f"><label for="sk">Kuerzel</label><input id="sk" name="schule_kurz" value="<?= h(setting('schule_kurz')) ?>"></div>
        </div>
        <button class="btn pri" type="submit">Konto anlegen</button>
      </form>
    </div>
    <?php
    render_page('Einrichtung', ob_get_clean(), ['bare' => true]);
}

function page_login(): void {
    if (current_user()) redirect(url('dashboard'));
    $err = '';
    if (!empty($_GET['timeout'])) flash('Aus Sicherheitsgruenden abgemeldet.', 'warn');

    // Schritt 2: Zwei-Faktor
    if (!empty($_SESSION['2fa_uid']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code'])) {
        csrf_check();
        $uid = (int)$_SESSION['2fa_uid'];
        if (!rl_hit('2fa:' . $uid, 10, 600)) { $err = 'Zu viele Versuche. Bitte spaeter erneut.'; }
        else {
            $u    = one("SELECT * FROM users WHERE id = ? AND active = 1", [$uid]);
            $code = preg_replace('/\s+/', '', post('code'));
            $ok   = $u && totp_verify((string)$u['totp_secret'], $code);
            if (!$ok && $u) {  // Recovery-Code?
                $codes = json_decode((string)$u['recovery_codes'], true) ?: [];
                foreach ($codes as $i => $hash) {
                    if (password_verify(strtoupper($code), $hash)) {
                        unset($codes[$i]);
                        upd('users', ['recovery_codes' => json_encode(array_values($codes))], 'id = :id', ['id' => $uid]);
                        audit('2fa_recovery_benutzt', $u['username']);
                        $ok = true; break;
                    }
                }
            }
            if ($ok) {
                unset($_SESSION['2fa_uid']);
                login_user($u);
                $to = $_SESSION['after_login'] ?? ''; unset($_SESSION['after_login']);
                redirect($to ?: url('dashboard'));
            }
            audit('2fa_fehlgeschlagen', (string)($u['username'] ?? $uid));
            $err = 'Code ungueltig.';
        }
    }
    // Schritt 1: Passwort
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $ident = post('ident');
        $pw    = (string)($_POST['password'] ?? '');
        $ip    = client_ip();
        if (post('website') !== '') { audit('honeypot', $ident); usleep(400000); $err = 'Anmeldung fehlgeschlagen.'; }
        elseif (!rl_hit('ip:' . $ip, LOGIN_MAX_IP, 900)) {
            $err = 'Zu viele Anmeldeversuche von dieser Adresse. Bitte 15 Minuten warten.';
            audit('ratelimit_login', $ident);
        } else {
            $u = one("SELECT * FROM users WHERE username = ? OR (email IS NOT NULL AND email = ?)", [$ident, $ident]);
            $versuchId = ins('login_attempts', ['ident' => mb_substr($ident, 0, 80), 'ip' => $ip, 'ok' => 0, 'ts' => time()]);
            if ($u && (int)$u['locked_until'] > time()) {
                $err = 'Konto ist voruebergehend gesperrt. Bitte in '
                     . (int)ceil(((int)$u['locked_until'] - time()) / 60) . ' Minuten erneut versuchen.';
            } elseif ($u && (int)$u['active'] !== 1) {
                $err = 'Anmeldung fehlgeschlagen.';
                audit('login_inaktiv', $u['username']);
            } elseif ($u && password_verify($pw, $u['pass_hash'])) {
                if (password_needs_rehash($u['pass_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
                    upd('users', ['pass_hash' => pw_hash($pw)], 'id = :id', ['id' => (int)$u['id']]);
                }
                q("UPDATE login_attempts SET ok = 1 WHERE id = ?", [$versuchId]);
                if ((int)$u['totp_enabled'] === 1) {
                    session_regenerate_id(true);
                    $_SESSION['2fa_uid'] = (int)$u['id'];
                    $_SESSION['csrf']    = bin2hex(random_bytes(32));
                } else {
                    login_user($u);
                    $to = $_SESSION['after_login'] ?? ''; unset($_SESSION['after_login']);
                    redirect($to ?: url('dashboard'));
                }
            } else {
                // Timing angleichen, damit unbekannte Konten nicht schneller antworten
                password_verify($pw, '$2y$12$usesomesillystringforsalt0000000000000000000000000000000000');
                if ($u) {
                    $f = (int)$u['failed_logins'] + 1;
                    $data = ['failed_logins' => $f];
                    if ($f >= LOGIN_MAX_USER) { $data['locked_until'] = time() + LOGIN_LOCK_SEC; $data['failed_logins'] = 0; }
                    upd('users', $data, 'id = :id', ['id' => (int)$u['id']]);
                }
                audit('login_fehlgeschlagen', mb_substr($ident, 0, 80));
                $err = 'Benutzername oder Passwort ist falsch.';
            }
        }
    }

    $zweiFaktor = !empty($_SESSION['2fa_uid']);
    ob_start(); ?>
    <div class="card">
      <div class="brand" style="padding:0 0 .8rem">
        <span class="logo">Fi</span>
        <span><?= h(APP_NAME) ?><small><?= h(setting('schule')) ?></small></span>
      </div>
      <?php foreach (take_flash() as $f): ?><div class="msg <?= h($f['t']) ?>"><?= h($f['m']) ?></div><?php endforeach; ?>
      <?php if ($err): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>
      <?php if ($zweiFaktor): ?>
        <h2>Zwei-Faktor-Bestaetigung</h2>
        <p class="muted small">Sechsstelliger Code aus deiner Authenticator-App - oder ein Wiederherstellungscode.</p>
        <form method="post" autocomplete="off">
          <?= csrf_field() ?>
          <div class="f"><label for="c">Code</label>
            <input id="c" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus
                   style="font-family:var(--mono);font-size:1.2rem;letter-spacing:.15em"></div>
          <button class="btn pri" type="submit" style="width:100%;justify-content:center">Bestaetigen</button>
        </form>
        <p class="small" style="margin-top:.7rem"><a href="<?= url('logout_abbruch') ?>">Abbrechen</a></p>
      <?php else: ?>
        <h2>Anmelden</h2>
        <form method="post">
          <?= csrf_field() ?>
          <div class="f"><label for="id">Benutzername oder E-Mail</label>
            <input id="id" name="ident" required autofocus autocomplete="username" value="<?= h(post('ident')) ?>"></div>
          <div class="f"><label for="pw">Passwort</label>
            <input id="pw" name="password" type="password" required autocomplete="current-password"></div>
          <div style="position:absolute;left:-9999px" aria-hidden="true">
            <label for="wb">Bitte leer lassen</label><input id="wb" name="website" tabindex="-1" autocomplete="off"></div>
          <button class="btn pri" type="submit" style="width:100%;justify-content:center">Anmelden</button>
        </form>
        <hr>
        <p class="small muted">Du hast einen Einladungscode?
          <a href="<?= url('registrieren') ?>">Konto erstellen</a></p>
      <?php endif; ?>
    </div>
    <p class="small muted2" style="text-align:center"><?= h(APP_DOMAIN) ?> &middot; v<?= h(APP_VERSION) ?></p>
    <?php
    render_page('Anmelden', ob_get_clean(), ['bare' => true]);
}

function page_registrieren(): void {
    if (current_user()) redirect(url('dashboard'));
    $err = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (!rl_hit('reg:' . client_ip(), 10, 3600)) { $err[] = 'Zu viele Versuche. Bitte spaeter erneut.'; }
        $code = strtoupper(preg_replace('/\s+/', '', post('code')));
        $inv  = one("SELECT * FROM invites WHERE code_hash = ?", [hash('sha256', $code)]);
        if (!$inv) { $err[] = 'Einladungscode ist ungueltig.'; audit('invite_ungueltig', mb_substr($code, 0, 20)); }
        elseif ($inv['expires_at'] && $inv['expires_at'] < date('Y-m-d H:i:s')) { $err[] = 'Einladungscode ist abgelaufen.'; }
        elseif ((int)$inv['uses'] >= (int)$inv['max_uses']) { $err[] = 'Einladungscode wurde bereits verbraucht.'; }
        $user = preg_replace('/[^A-Za-z0-9._-]/', '', post('username'));
        $name = post('display_name');
        $mail = filter_var(post('email'), FILTER_VALIDATE_EMAIL) ?: '';
        $pw   = (string)($_POST['password'] ?? '');
        if (mb_strlen($user) < 3) $err[] = 'Benutzername: mindestens 3 Zeichen (A-Z, 0-9, . _ -).';
        if (val("SELECT 1 FROM users WHERE username = ?", [$user])) $err[] = 'Benutzername ist schon vergeben.';
        if ($mail && val("SELECT 1 FROM users WHERE email = ?", [$mail])) $err[] = 'E-Mail ist schon vergeben.';
        if ($pw !== (string)($_POST['password2'] ?? '')) $err[] = 'Die Passwoerter stimmen nicht ueberein.';
        foreach (pw_problems($pw, $user, $name) as $p) $err[] = 'Passwort: ' . $p;
        if (!$err && $inv) {
            $uid = ins('users', [
                'username'         => $user,
                'email'            => $mail ?: null,
                'pass_hash'        => pw_hash($pw),
                'role'             => $inv['role'],
                'display_name'     => $name ?: $user,
                'class_id'         => $inv['class_id'] ? (int)$inv['class_id'] : null,
                'ausbildung_start' => post('start') ?: null,
                'betrieb'          => post('betrieb'),
                'ics_token'        => bin2hex(random_bytes(16)),
                'pw_changed_at'    => date('Y-m-d H:i:s'),
            ]);
            if ($inv['class_id']) {
                q("INSERT OR IGNORE INTO class_members (class_id,user_id) VALUES (?,?)", [(int)$inv['class_id'], $uid]);
            }
            q("UPDATE invites SET uses = uses + 1 WHERE id = ?", [(int)$inv['id']]);
            audit('registriert', $user, 'Einladung #' . $inv['id']);
            flash('Konto angelegt. Du kannst dich jetzt anmelden.', 'ok');
            redirect(url('login'));
        }
    }
    ob_start(); ?>
    <div class="card">
      <h1>Konto erstellen</h1>
      <p class="muted small">Die Anmeldung ist nur mit einem Einladungscode moeglich.
      Den bekommst du von deiner Lehrkraft, deinem Ausbilder oder der Administration.</p>
      <?php foreach ($err as $e): ?><div class="msg err"><?= h($e) ?></div><?php endforeach; ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="f"><label for="cd">Einladungscode</label>
          <input id="cd" name="code" required autocomplete="off" style="font-family:var(--mono);letter-spacing:.08em"
                 value="<?= h(post('code')) ?>" placeholder="XXXX-XXXX-XXXX-XXXX"></div>
        <div class="fgrid">
          <div class="f"><label for="us">Benutzername</label><input id="us" name="username" required autocomplete="username" value="<?= h(post('username')) ?>"></div>
          <div class="f"><label for="dn">Voller Name</label><input id="dn" name="display_name" required value="<?= h(post('display_name')) ?>"></div>
        </div>
        <div class="f"><label for="em">E-Mail (optional)</label><input id="em" name="email" type="email" value="<?= h(post('email')) ?>"></div>
        <div class="fgrid">
          <div class="f"><label for="st">Ausbildungsbeginn</label><input id="st" name="start" type="date" value="<?= h(post('start')) ?>"></div>
          <div class="f"><label for="bt">Ausbildungsbetrieb</label><input id="bt" name="betrieb" value="<?= h(post('betrieb')) ?>"></div>
        </div>
        <div class="fgrid">
          <div class="f"><label for="pw">Passwort</label><input id="pw" name="password" type="password" required autocomplete="new-password"></div>
          <div class="f"><label for="pw2">Wiederholen</label><input id="pw2" name="password2" type="password" required autocomplete="new-password"></div>
        </div>
        <div class="small muted">Mindestens <?= PW_MIN_LEN ?> Zeichen, 3 von 4 Zeichenarten.</div>
        <br><button class="btn pri" type="submit">Konto anlegen</button>
        <a class="btn ghost" href="<?= url('login') ?>">Zurueck</a>
      </form>
    </div>
    <?php
    render_page('Registrieren', ob_get_clean(), ['bare' => true]);
}

// ===========================================================================
// 11. SCHNELLERFASSUNG
// ===========================================================================

function quickadd_form(array $u, string $datum = ''): string {
    $datum = $datum ?: today();
    $routinen = all("SELECT id, name FROM routines WHERE aktiv = 1 AND (geteilt = 1 OR owner_id = ?)
                     ORDER BY sort, name", [(int)$u['id']]);
    ob_start(); ?>
    <form method="post" action="<?= url('quickadd') ?>" class="card tight noprint">
      <?= csrf_field() ?>
      <input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
      <div class="qa">
        <select name="typ" aria-label="Art">
          <option value="notiz">Notiz / Randnotiz</option>
          <option value="aufgabe">Aufgabe / Hausaufgabe</option>
          <option value="termin">Probe / Termin</option>
          <option value="bericht">Berichtsheft-Eintrag</option>
          <option value="routine">Routine erledigt</option>
        </select>
        <input name="text" placeholder="Was ist passiert? z.B. Kaffeemaschine im Betrieb geleert - 0,25 h"
               required autocomplete="off">
        <input type="date" name="datum" value="<?= h($datum) ?>" aria-label="Datum">
        <button class="btn pri" type="submit" title="Eintragen">+&nbsp;Eintragen</button>
      </div>
      <details class="acc" style="margin:.5rem 0 0;border:0;background:transparent">
        <summary style="padding:.25rem 0;font-size:.8rem;color:var(--fg2)">Details (optional)</summary>
        <div style="padding:.3rem 0 0" class="fgrid">
          <div><label for="qa_h">Stunden</label><input id="qa_h" name="stunden" type="number" step="0.25" min="0" placeholder="z.B. 0,5"></div>
          <div><label for="qa_k">Kategorie</label><select id="qa_k" name="category_id"><?= kat_options(null, '- automatisch -') ?></select></div>
          <div><label for="qa_f">Fach</label><select id="qa_f" name="subject_id"><?= fach_options($u['class_id'] ? (int)$u['class_id'] : null, null) ?></select></div>
          <div><label for="qa_r">Routine</label><select id="qa_r" name="routine_id"><?= opts($routinen, null, 'id', 'name', '- Routine -') ?></select></div>
        </div>
      </details>
    </form>
    <?php return ob_get_clean();
}

function action_quickadd(): void {
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(url('dashboard'));
    csrf_check();
    if (!rl_hit('qa:' . $u['id'], 200, 3600)) { flash('Zu viele Eintraege in kurzer Zeit.', 'err'); redirect(url('dashboard')); }
    $typ   = post('typ', 'notiz');
    $text  = post('text');
    $datum = post('datum') ?: today();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) $datum = today();
    $std   = (float)str_replace(',', '.', post('stunden', '0'));
    $cat   = int_or_null(postn('category_id'));
    $fach  = int_or_null(postn('subject_id'));
    $rid   = int_or_null(postn('routine_id'));
    if ($text === '') { flash('Kein Text angegeben.', 'warn'); redirect(post('back') ?: url('dashboard')); }

    // "... - 0,5 h" am Ende automatisch als Stundenangabe erkennen
    if ($std <= 0 && preg_match('/(?:^|[\s\-,;(])(\d+(?:[.,]\d+)?)\s*(?:h|std|stunden|min)\b\.?\s*\)?\s*$/iu', $text, $m)) {
        $wert = (float)str_replace(',', '.', $m[1]);
        $std  = stripos($m[0], 'min') !== false ? round($wert / 60, 2) : $wert;
        $text = trim(preg_replace('/(?:^|[\s\-,;(])(\d+(?:[.,]\d+)?)\s*(?:h|std|stunden|min)\b\.?\s*\)?\s*$/iu', '', $text), " -,;\t");
    }
    switch ($typ) {
        case 'aufgabe':
            ins('tasks', ['user_id' => (int)$u['id'], 'class_id' => $u['class_id'] ? (int)$u['class_id'] : null,
                'subject_id' => $fach, 'titel' => $text, 'faellig' => $datum,
                'bereich' => $fach ? 'schule' : 'betrieb']);
            flash('Aufgabe angelegt.'); break;
        case 'termin':
            ins('events', ['user_id' => (int)$u['id'], 'class_id' => $u['class_id'] ? (int)$u['class_id'] : null,
                'subject_id' => $fach, 'typ' => 'probe', 'titel' => $text, 'datum' => $datum,
                'visibility' => $u['class_id'] ? 'class' : 'private']);
            flash('Termin eingetragen.'); break;
        case 'bericht':
            $rep = ensure_report((int)$u['id'], setting('berichtsheft_art', 'woche'), periode_of($datum, setting('berichtsheft_art', 'woche')));
            if (!$cat) { $k = kategorie_fuer_text($text); $cat = $k ? (int)$k['category_id'] : null; }
            ins('report_entries', ['report_id' => (int)$rep['id'], 'user_id' => (int)$u['id'], 'datum' => $datum,
                'stunden' => $std, 'category_id' => $cat, 'ort' => 'betrieb', 'text' => $text, 'quelle' => 'manuell']);
            flash('Ins Berichtsheft uebernommen.'); break;
        case 'routine':
            if ($rid) {
                $r = one("SELECT * FROM routines WHERE id = ?", [$rid]);
                ins('routine_logs', ['routine_id' => $rid, 'user_id' => (int)$u['id'], 'datum' => $datum,
                    'zeit' => date('H:i'), 'minuten' => (int)round($std > 0 ? $std * 60 : (int)$r['standard_min']),
                    'notiz' => $text]);
                flash('Routine protokolliert.');
            } else { flash('Bitte eine Routine auswaehlen.', 'warn'); }
            break;
        default:
            $lines = nl2list($text);
            ins('notes', ['user_id' => (int)$u['id'], 'class_id' => $u['class_id'] ? (int)$u['class_id'] : null,
                'subject_id' => $fach, 'datum' => $datum, 'titel' => mb_substr($lines[0] ?? $text, 0, 120),
                'body' => $text, 'kind' => 'randnotiz', 'visibility' => 'private']);
            flash('Notiz gespeichert.');
    }
    redirect(post('back') ?: url('dashboard'));
}

// ===========================================================================
// 12. START / DASHBOARD
// ===========================================================================

function page_dashboard(): void {
    $u    = require_login();
    $uid  = (int)$u['id'];
    $cid  = (int)($u['class_id'] ?: 0);
    $art  = setting('berichtsheft_art', 'woche');
    $per  = periode_of(today(), $art);
    $rep  = one("SELECT * FROM reports WHERE user_id = ? AND art = ? AND periode = ?", [$uid, $art, $per]);
    $gs   = grade_stats($uid);
    $ev   = all("SELECT e.*, s.name AS fach, s.color FROM events e LEFT JOIN subjects s ON s.id = e.subject_id
                 WHERE e.datum >= date('now','localtime')
                   AND ((e.visibility = 'class' AND e.class_id = ?) OR e.user_id = ?)
                 ORDER BY e.datum LIMIT 8", [$cid, $uid]);
    $tk   = all("SELECT t.*, s.name AS fach FROM tasks t LEFT JOIN subjects s ON s.id = t.subject_id
                 WHERE t.user_id = ? AND t.status = 'offen' ORDER BY (t.faellig IS NULL), t.faellig LIMIT 8", [$uid]);
    $rout = all("SELECT r.*, (SELECT MAX(datum) FROM routine_logs l WHERE l.routine_id = r.id AND l.user_id = ?) AS letzte
                 FROM routines r WHERE r.aktiv = 1 AND (r.geteilt = 1 OR r.owner_id = ?)
                 ORDER BY r.sort, r.name", [$uid, $uid]);
    $offen = array_values(array_filter($rout, function ($r) {
        if ($r['intervall'] === 'taeglich')     return $r['letzte'] !== today();
        if ($r['intervall'] === 'woechentlich') return !$r['letzte'] || $r['letzte'] < date('Y-m-d', strtotime('monday this week'));
        if ($r['intervall'] === 'monatlich')    return !$r['letzte'] || substr((string)$r['letzte'], 0, 7) !== date('Y-m');
        return false;
    }));
    $notes = all("SELECT n.*, s.name AS fach FROM notes n LEFT JOIN subjects s ON s.id = n.subject_id
                  WHERE n.user_id = ? OR (n.visibility IN ('class','all') AND n.class_id = ?)
                  ORDER BY n.pinned DESC, n.datum DESC, n.id DESC LIMIT 6", [$uid, $cid]);

    // Ausbildungsfortschritt
    $fortschritt = null; $restTage = null;
    if (!empty($u['ausbildung_start'])) {
        $s = strtotime($u['ausbildung_start']);
        $e = !empty($u['ausbildung_ende']) ? strtotime($u['ausbildung_ende']) : strtotime('+3 years', $s);
        if ($e > $s) {
            $fortschritt = max(0, min(100, (time() - $s) / ($e - $s) * 100));
            $restTage = (int)ceil(($e - time()) / 86400);
        }
    }
    // Berichtsheft-Quote
    $wochenGesamt = 0; $wochenDa = 0;
    if (!empty($u['ausbildung_start'])) {
        $s = new DateTimeImmutable($u['ausbildung_start']);
        $n = new DateTimeImmutable('now');
        if ($s < $n) $wochenGesamt = max(1, (int)floor($s->diff($n)->days / 7));
        $wochenDa = (int)val("SELECT COUNT(*) FROM reports WHERE user_id = ? AND status <> 'entwurf'", [$uid], 0);
    }
    $blockJetzt = $cid ? one("SELECT b.* FROM blockweeks b
        LEFT JOIN classes c ON c.id = ?
        WHERE (b.class_id = ? OR (b.class_id IS NULL AND b.zeitgruppe = c.zeitgruppe))
          AND date('now','localtime') BETWEEN b.von AND b.bis", [$cid, $cid]) : null;

    ob_start(); ?>
    <?= quickadd_form($u) ?>
    <div class="grid g4" style="margin-bottom:1rem">
      <?= ui_kpi('Notenschnitt', $gs['schnitt'] !== null ? num($gs['schnitt'], 2) : '&ndash;',
            count($gs['rows']) . ' Noten erfasst',
            $gs['schnitt'] !== null ? (6 - $gs['schnitt']) / 5 * 100 : null, note_farbe($gs['schnitt'])) ?>
      <?= ui_kpi('Berichtsheft', $rep ? h(bericht_status_label($rep['status'])) : 'offen',
            periode_label($per, $art), $wochenGesamt ? $wochenDa / $wochenGesamt * 100 : null) ?>
      <?= ui_kpi('Offene Aufgaben', (string)count($tk),
            count(array_filter($tk, fn($t) => $t['faellig'] && $t['faellig'] < today())) . ' ueberfaellig') ?>
      <?= ui_kpi('Ausbildung', $fortschritt !== null ? num($fortschritt, 0) . '&thinsp;%' : '&ndash;',
            $restTage !== null ? ($restTage > 0 ? 'noch ' . $restTage . ' Tage' : 'abgeschlossen') : 'Beginn im Profil eintragen',
            $fortschritt) ?>
    </div>

    <?php if ($blockJetzt): ?>
      <div class="msg info"><strong>Aktuell: <?= h(ucfirst($blockJetzt['art'])) ?><?= $blockJetzt['label'] ? ' - ' . h($blockJetzt['label']) : '' ?></strong>
        &nbsp;<?= h(de_date($blockJetzt['von'])) ?> bis <?= h(de_date($blockJetzt['bis'])) ?></div>
    <?php endif; ?>

    <div class="split">
      <div>
        <div class="card">
          <div class="row" style="justify-content:space-between">
            <h2 style="margin:0">Naechste Proben &amp; Termine</h2>
            <a class="btn sm" href="<?= url('termine') ?>">alle</a>
          </div>
          <?php if (!$ev): ?><?= ui_empty('Keine anstehenden Termine', 'Oben schnell eintragen: Art "Probe / Termin" waehlen.') ?>
          <?php else: ?>
          <div class="tw"><table><tbody>
            <?php foreach ($ev as $e):
              $tage = (int)floor((strtotime($e['datum']) - strtotime(today())) / 86400); ?>
              <tr>
                <td style="white-space:nowrap;width:1%">
                  <span class="tag <?= $tage <= 2 ? 'err' : ($tage <= 7 ? 'warn' : '') ?>">
                    <?= $tage === 0 ? 'heute' : ($tage === 1 ? 'morgen' : 'in ' . $tage . ' T') ?></span>
                </td>
                <td><a href="<?= url('termine', ['id' => $e['id']]) ?>"><strong><?= h($e['titel']) ?></strong></a>
                  <div class="small muted"><?= h(de_date($e['datum'], 'D d.m.')) ?><?= $e['fach'] ? ' &middot; ' . h($e['fach']) : '' ?><?= $e['raum'] ? ' &middot; ' . h($e['raum']) : '' ?></div>
                </td>
                <td style="width:1%"><span class="tag <?= $e['typ'] === 'probe' || $e['typ'] === 'pruefung' ? 'acc' : '' ?>"><?= h(typ_label($e['typ'])) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <?php endif; ?>
        </div>

        <div class="card">
          <div class="row" style="justify-content:space-between">
            <h2 style="margin:0">Offene Aufgaben</h2><a class="btn sm" href="<?= url('aufgaben') ?>">alle</a>
          </div>
          <?php if (!$tk): ?><?= ui_empty('Nichts offen. Sauber.') ?><?php else: ?>
          <ul class="list">
            <?php foreach ($tk as $t): ?>
              <li>
                <form method="post" action="<?= url('aufgaben') ?>" style="margin:0"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                  <input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
                  <button class="btn sm ghost" type="submit" title="Erledigt">&#9744;</button>
                </form>
                <div style="flex:1">
                  <?= h($t['titel']) ?>
                  <div class="small muted"><?= $t['faellig'] ? h(de_date($t['faellig'])) : 'ohne Frist' ?><?= $t['fach'] ? ' &middot; ' . h($t['fach']) : '' ?></div>
                </div>
                <?php if ($t['faellig'] && $t['faellig'] < today()): ?><span class="tag err">ueberfaellig</span><?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </div>

        <div class="card">
          <div class="row" style="justify-content:space-between">
            <h2 style="margin:0">Letzte Notizen</h2><a class="btn sm" href="<?= url('notizen') ?>">alle</a>
          </div>
          <?php if (!$notes): ?><?= ui_empty('Noch keine Notizen') ?><?php else: ?>
          <ul class="list">
            <?php foreach ($notes as $n): ?>
              <li><span class="dot" style="background:var(--acc);margin-top:.45rem"></span>
                <div style="flex:1"><a href="<?= url('notizen', ['id' => $n['id']]) ?>"><?= h($n['titel'] ?: mb_substr($n['body'], 0, 60)) ?></a>
                  <div class="small muted"><?= h(de_date($n['datum'])) ?><?= $n['fach'] ? ' &middot; ' . h($n['fach']) : '' ?>
                    <?= $n['visibility'] !== 'private' ? ' &middot; <span class="tag info">geteilt</span>' : '' ?></div>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </div>
      </div>

      <div>
        <div class="card">
          <h3>Heute im Betrieb</h3>
          <?php if (!$offen): ?><div class="small muted">Alle Routinen erledigt.</div><?php else: ?>
          <ul class="list">
            <?php foreach (array_slice($offen, 0, 8) as $r): ?>
              <li>
                <form method="post" action="<?= url('betrieb') ?>" style="margin:0;display:flex;gap:.4rem;align-items:center;width:100%">
                  <?= csrf_field() ?><input type="hidden" name="action" value="log">
                  <input type="hidden" name="routine_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
                  <button class="btn sm" type="submit" title="Jetzt als erledigt eintragen">&#10003;</button>
                  <span style="flex:1"><?= h($r['name']) ?>
                    <span class="small muted2"><?= h($r['intervall']) ?><?= $r['letzte'] ? ' &middot; zuletzt ' . h(de_date($r['letzte'])) : '' ?></span></span>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
          <a class="btn sm" style="margin-top:.5rem" href="<?= url('betrieb') ?>">Alle Routinen</a>
        </div>

        <div class="card">
          <h3>Berichtsheft <?= h(periode_label($per, $art)) ?></h3>
          <?php
            $stunden = $rep ? (float)val("SELECT COALESCE(SUM(stunden),0) FROM report_entries WHERE report_id = ?", [(int)$rep['id']], 0) : 0;
            $anzahl  = $rep ? (int)val("SELECT COUNT(*) FROM report_entries WHERE report_id = ?", [(int)$rep['id']], 0) : 0;
          ?>
          <div class="row"><span class="tag <?= $rep ? bericht_status_klasse($rep['status']) : 'warn' ?>">
            <?= h($rep ? bericht_status_label($rep['status']) : 'noch nicht begonnen') ?></span>
            <span class="small muted"><?= $anzahl ?> Eintraege &middot; <?= num($stunden, 1) ?> h</span></div>
          <a class="btn pri sm" style="margin-top:.6rem" href="<?= url('berichtsheft') ?>">Woche bearbeiten</a>
        </div>

        <?php if ($gs['faecher']): ?>
        <div class="card">
          <h3>Notenschnitt je Fach</h3>
          <ul class="list">
            <?php foreach ($gs['faecher'] as $f): if ($f['schnitt'] === null) continue; ?>
              <li><?= ui_note($f['schnitt']) ?>
                <div style="flex:1"><?= h($f['name']) ?>
                  <div class="small muted2"><?= (int)$f['anzahl'] ?> Noten
                    <?php if ($f['trend'] !== null && abs($f['trend']) >= 0.15): ?>
                      &middot; <span style="color:<?= $f['trend'] > 0 ? 'var(--ok)' : 'var(--err)' ?>">
                      <?= $f['trend'] > 0 ? '&#9650; besser' : '&#9660; schlechter' ?></span>
                    <?php endif; ?></div>
                </div>
                <?= ui_spark(array_column($f['n'], 'v'), 70, 26) ?>
              </li>
            <?php endforeach; ?>
          </ul>
          <a class="btn sm" href="<?= url('noten') ?>">Noten &amp; Statistik</a>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    render_page('Start', ob_get_clean());
}
function typ_label(string $t): string {
    return ['probe' => 'Probe', 'test' => 'Test / Ex', 'abgabe' => 'Abgabe', 'pruefung' => 'Pruefung',
            'termin' => 'Termin', 'projekt' => 'Projekt', 'ferien' => 'Ferien'][$t] ?? $t;
}
function bericht_status_label(string $s): string {
    return ['entwurf' => 'Entwurf', 'eingereicht' => 'eingereicht', 'geprueft' => 'abgezeichnet',
            'abgelehnt' => 'zurueckgewiesen'][$s] ?? $s;
}
function bericht_status_klasse(string $s): string {
    return ['entwurf' => 'warn', 'eingereicht' => 'info', 'geprueft' => 'ok', 'abgelehnt' => 'err'][$s] ?? '';
}

// ===========================================================================
// 13. PROBEN & TERMINE
// ===========================================================================

function event_zugriff(array $u, array $e): bool {
    return (int)$e['user_id'] === (int)$u['id']
        || ($e['visibility'] === 'class' && (int)$e['class_id'] === (int)($u['class_id'] ?: 0))
        || is_staff();
}
function page_termine(): void {
    $u   = require_login();
    $uid = (int)$u['id'];
    $cid = (int)($u['class_id'] ?: 0);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $act = post('action');
        if ($act === 'save') {
            $id   = (int)post('id', '0');
            $data = [
                'subject_id'   => int_or_null(postn('subject_id')),
                'typ'          => in_array(post('typ'), ['probe','test','abgabe','pruefung','termin','projekt','ferien'], true) ? post('typ') : 'termin',
                'titel'        => mb_substr(post('titel'), 0, 200),
                'beschreibung' => post('beschreibung'),
                'datum'        => preg_match('/^\d{4}-\d{2}-\d{2}$/', post('datum')) ? post('datum') : today(),
                'zeit_von'     => post('zeit_von'), 'zeit_bis' => post('zeit_bis'),
                'raum'         => mb_substr(post('raum'), 0, 60),
                'lf_no'        => int_or_null(postn('lf_no')),
                'stoff'        => post('stoff'),
                'visibility'   => post('visibility') === 'class' && $cid ? 'class' : 'private',
                'wichtig'      => post('wichtig') ? 1 : 0,
                'updated_at'   => date('Y-m-d H:i:s'),
            ];
            if ($data['titel'] === '') { flash('Titel fehlt.', 'err'); }
            elseif ($id) {
                $e = one("SELECT * FROM events WHERE id = ?", [$id]);
                if ($e && ((int)$e['user_id'] === $uid || is_staff())) {
                    upd('events', $data, 'id = :id', ['id' => $id]); flash('Termin aktualisiert.');
                } else flash('Kein Zugriff.', 'err');
            } else {
                $data['user_id']  = $uid;
                $data['class_id'] = $cid ?: null;
                $id = ins('events', $data);
                flash('Termin angelegt.');
            }
            redirect(url('termine', ['id' => $id]));
        }
        if ($act === 'del') {
            $id = (int)post('id', '0');
            $e  = one("SELECT * FROM events WHERE id = ?", [$id]);
            if ($e && ((int)$e['user_id'] === $uid || is_admin())) { del('events', 'id = ?', [$id]); flash('Termin geloescht.'); }
            redirect(url('termine'));
        }
    }

    $edit = null;
    if (get('id') !== '') {
        $edit = one("SELECT * FROM events WHERE id = ?", [(int)get('id')]);
        if ($edit && !event_zugriff($u, $edit)) $edit = null;
    } elseif (get('neu') !== '') { $edit = ['id' => 0]; }

    $von  = get('von') ?: date('Y-m-01');
    $bis  = get('bis') ?: date('Y-m-d', strtotime('+120 days'));
    $filt = get('typ');
    $sql  = "SELECT e.*, s.name AS fach, s.color, u.display_name AS autor
             FROM events e LEFT JOIN subjects s ON s.id = e.subject_id
             LEFT JOIN users u ON u.id = e.user_id
             WHERE ((e.visibility='class' AND e.class_id = ?) OR e.user_id = ?)
               AND e.datum BETWEEN ? AND ?" . ($filt ? " AND e.typ = ?" : "") . " ORDER BY e.datum, e.zeit_von";
    $args = [$cid, $uid, $von, $bis];
    if ($filt) $args[] = $filt;
    $rows = all($sql, $args);

    // Monatskalender
    $mRef  = get('m') ?: date('Y-m');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mRef)) $mRef = date('Y-m');
    $first = new DateTimeImmutable($mRef . '-01');
    $start = $first->modify('monday this week');
    if ($start > $first) $start = $start->modify('-7 days');
    $calEv = [];
    foreach (all("SELECT e.*, s.color FROM events e LEFT JOIN subjects s ON s.id = e.subject_id
                  WHERE ((e.visibility='class' AND e.class_id = ?) OR e.user_id = ?)
                    AND e.datum BETWEEN ? AND ?",
                 [$cid, $uid, $start->format('Y-m-d'), $start->modify('+41 days')->format('Y-m-d')]) as $e) {
        $calEv[$e['datum']][] = $e;
    }

    ob_start(); ?>
    <div class="split">
      <div>
        <div class="card">
          <div class="row" style="justify-content:space-between;margin-bottom:.6rem">
            <h2 style="margin:0">Termine <?= h(de_date($von)) ?> &ndash; <?= h(de_date($bis)) ?></h2>
            <a class="btn pri sm" href="<?= url('termine', ['neu' => 1]) ?>">+ Neu</a>
          </div>
          <form method="get" class="row noprint" style="margin-bottom:.7rem">
            <input type="hidden" name="p" value="termine">
            <input type="date" name="von" value="<?= h($von) ?>" style="width:auto">
            <input type="date" name="bis" value="<?= h($bis) ?>" style="width:auto">
            <select name="typ" style="width:auto"><?= opts_simple(['' => 'Alle Arten', 'probe' => 'Probe', 'test' => 'Test / Ex',
                'abgabe' => 'Abgabe', 'pruefung' => 'Pruefung', 'termin' => 'Termin', 'projekt' => 'Projekt', 'ferien' => 'Ferien'], $filt) ?></select>
            <button class="btn sm" type="submit">Filtern</button>
            <input placeholder="Suchen ..." data-filter="#evt" style="width:auto;flex:1;min-width:120px">
          </form>
          <?php if (!$rows): ?><?= ui_empty('Keine Termine im Zeitraum') ?><?php else: ?>
          <div class="tw"><table id="evt"><thead><tr>
            <th>Datum</th><th>Art</th><th>Titel</th><th>Fach / LF</th><th>Raum</th><th></th></tr></thead><tbody>
            <?php foreach ($rows as $e): $past = $e['datum'] < today(); ?>
              <tr style="<?= $past ? 'opacity:.55' : '' ?>">
                <td style="white-space:nowrap"><strong><?= h(de_date($e['datum'], 'd.m.')) ?></strong>
                  <span class="small muted"><?= h(de_date($e['datum'], 'D')) ?></span>
                  <?php if ($e['zeit_von']): ?><div class="small muted"><?= h($e['zeit_von']) ?></div><?php endif; ?></td>
                <td><span class="tag <?= in_array($e['typ'], ['probe','pruefung'], true) ? 'err' : ($e['typ'] === 'test' ? 'warn' : '') ?>"><?= h(typ_label($e['typ'])) ?></span></td>
                <td><a href="<?= url('termine', ['id' => $e['id']]) ?>"><strong><?= h($e['titel']) ?></strong></a>
                  <?php if ($e['stoff']): ?><div class="small muted"><?= count(nl2list($e['stoff'])) ?> Stoffpunkte</div><?php endif; ?></td>
                <td class="small"><?= h($e['fach'] ?: '') ?><?php if ($e['lf_no']): ?><br><span class="tag">LF <?= (int)$e['lf_no'] ?></span><?php endif; ?></td>
                <td class="small"><?= h($e['raum']) ?></td>
                <td><?= $e['visibility'] === 'class' ? '<span class="tag info">Klasse</span>' : '<span class="tag">privat</span>' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <?php endif; ?>
        </div>

        <div class="card noprint">
          <div class="row" style="justify-content:space-between;margin-bottom:.5rem">
            <h3 style="margin:0"><?= h(de_date($first->format('Y-m-d'), 'F Y')) ?></h3>
            <div class="row">
              <a class="btn sm" href="<?= url('termine', ['m' => $first->modify('-1 month')->format('Y-m')]) ?>">&larr;</a>
              <a class="btn sm" href="<?= url('termine', ['m' => date('Y-m')]) ?>">heute</a>
              <a class="btn sm" href="<?= url('termine', ['m' => $first->modify('+1 month')->format('Y-m')]) ?>">&rarr;</a>
            </div>
          </div>
          <div class="cal">
            <?php foreach (['Mo','Di','Mi','Do','Fr','Sa','So'] as $d): ?><div class="h"><?= $d ?></div><?php endforeach; ?>
            <?php $d = $start; for ($i = 0; $i < 42; $i++):
              $ds = $d->format('Y-m-d'); $out = $d->format('Y-m') !== $first->format('Y-m'); ?>
              <div class="d <?= $out ? 'out' : '' ?> <?= $ds === today() ? 'today' : '' ?>">
                <div class="n"><?= $d->format('j') ?></div>
                <?php foreach (array_slice($calEv[$ds] ?? [], 0, 3) as $e): ?>
                  <a class="ev" style="background:<?= h($e['color'] ?: '#4f7cff') ?>"
                     href="<?= url('termine', ['id' => $e['id']]) ?>" title="<?= h($e['titel']) ?>"><?= h(mb_substr($e['titel'], 0, 18)) ?></a>
                <?php endforeach; ?>
                <?php if (count($calEv[$ds] ?? []) > 3): ?><div class="small muted2">+<?= count($calEv[$ds]) - 3 ?></div><?php endif; ?>
              </div>
            <?php $d = $d->modify('+1 day'); endfor; ?>
          </div>
        </div>
      </div>

      <div>
        <?php if ($edit !== null): $isNew = empty($edit['id']); ?>
        <div class="card">
          <h3><?= $isNew ? 'Neuer Termin' : 'Termin bearbeiten' ?></h3>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="f"><label for="ti">Titel</label>
              <input id="ti" name="titel" required value="<?= h($edit['titel'] ?? '') ?>" placeholder="z.B. Schulaufgabe LF 9"></div>
            <div class="fgrid">
              <div class="f"><label for="ty">Art</label><select id="ty" name="typ"><?= opts_simple(
                ['probe' => 'Probe / Schulaufgabe', 'test' => 'Test / Ex', 'abgabe' => 'Abgabe',
                 'pruefung' => 'IHK-Pruefung', 'termin' => 'Termin', 'projekt' => 'Projekt', 'ferien' => 'Ferien'],
                $edit['typ'] ?? 'probe') ?></select></div>
              <div class="f"><label for="da">Datum</label><input id="da" name="datum" type="date" required value="<?= h($edit['datum'] ?? today()) ?>"></div>
            </div>
            <div class="fgrid">
              <div class="f"><label for="zv">von</label><input id="zv" name="zeit_von" type="time" value="<?= h($edit['zeit_von'] ?? '') ?>"></div>
              <div class="f"><label for="zb">bis</label><input id="zb" name="zeit_bis" type="time" value="<?= h($edit['zeit_bis'] ?? '') ?>"></div>
              <div class="f"><label for="ra">Raum</label><input id="ra" name="raum" value="<?= h($edit['raum'] ?? '') ?>"></div>
            </div>
            <div class="fgrid">
              <div class="f"><label for="fa">Fach</label><select id="fa" name="subject_id"><?= fach_options($cid ?: null, $edit['subject_id'] ?? null) ?></select></div>
              <div class="f"><label for="lf">Lernfeld</label><select id="lf" name="lf_no"><?= lf_options($edit['lf_no'] ?? null) ?></select></div>
            </div>
            <div class="f"><label for="st">Stoff / Lernplan (eine Zeile je Punkt)</label>
              <textarea id="st" name="stoff" data-draft="ev<?= (int)($edit['id'] ?? 0) ?>" placeholder="VLAN-Grundlagen&#10;Subnetting&#10;DHCP-Relay"><?= h($edit['stoff'] ?? '') ?></textarea></div>
            <div class="f"><label for="be">Beschreibung</label><textarea id="be" name="beschreibung" style="min-height:60px"><?= h($edit['beschreibung'] ?? '') ?></textarea></div>
            <div class="f"><label for="vi">Sichtbarkeit</label><select id="vi" name="visibility">
              <option value="class"<?= ($edit['visibility'] ?? 'class') === 'class' ? ' selected' : '' ?>>Fuer die ganze Klasse</option>
              <option value="private"<?= ($edit['visibility'] ?? '') === 'private' ? ' selected' : '' ?>>Nur fuer mich</option></select></div>
            <div class="row">
              <button class="btn pri" type="submit">Speichern</button>
              <a class="btn ghost" href="<?= url('termine') ?>">Abbrechen</a>
            </div>
          </form>
          <?php if (!$isNew): ?>
            <?php $stoff = nl2list($edit['stoff'] ?? ''); if ($stoff): ?>
              <hr><h4>Lernstoff</h4>
              <ul class="list"><?php foreach ($stoff as $s): ?><li><span>&#9633;</span><span><?= h($s) ?></span></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <hr>
            <form method="post" data-confirm="Termin wirklich loeschen?">
              <?= csrf_field() ?><input type="hidden" name="action" value="del">
              <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
              <button class="btn dan sm" type="submit">Loeschen</button>
            </form>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="card">
          <h3>Kalender abonnieren</h3>
          <p class="small muted">Termine im Handy-Kalender: die folgende Adresse als Abo hinzufuegen.</p>
          <pre id="icsurl" style="white-space:pre-wrap;word-break:break-all"><?= h(abs_url(url('ics', ['t' => $u['ics_token'] ?: '-']))) ?></pre>
          <button class="btn sm" data-copy="icsurl" type="button">Adresse kopieren</button>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    render_page('Proben & Termine', ob_get_clean());
}
function abs_url(string $path): string {
    $host = $_SERVER['HTTP_HOST'] ?? APP_DOMAIN;
    return (is_https() ? 'https://' : 'http://') . $host . $path;
}

// ===========================================================================
// 14. AUFGABEN
// ===========================================================================

function page_aufgaben(): void {
    $u   = require_login();
    $uid = (int)$u['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $act = post('action');
        $id  = (int)post('id', '0');
        if ($act === 'toggle' && $id) {
            $t = one("SELECT * FROM tasks WHERE id = ? AND user_id = ?", [$id, $uid]);
            if ($t) {
                $neu = $t['status'] === 'offen' ? 'erledigt' : 'offen';
                upd('tasks', ['status' => $neu, 'erledigt_am' => $neu === 'erledigt' ? date('Y-m-d H:i:s') : null],
                    'id = :id', ['id' => $id]);
            }
        } elseif ($act === 'save') {
            $data = [
                'titel' => mb_substr(post('titel'), 0, 200), 'beschreibung' => post('beschreibung'),
                'faellig' => post('faellig') ?: null, 'prio' => max(0, min(2, (int)post('prio', '1'))),
                'subject_id' => int_or_null(postn('subject_id')),
                'bereich' => in_array(post('bereich'), ['schule','betrieb','privat'], true) ? post('bereich') : 'schule',
                'visibility' => post('visibility') === 'class' ? 'class' : 'private',
            ];
            if ($data['titel'] === '') flash('Titel fehlt.', 'err');
            elseif ($id) { upd('tasks', $data, 'id = :id AND user_id = :u', ['id' => $id, 'u' => $uid]); flash('Aufgabe gespeichert.'); }
            else { $data['user_id'] = $uid; $data['class_id'] = $u['class_id'] ? (int)$u['class_id'] : null;
                   ins('tasks', $data); flash('Aufgabe angelegt.'); }
        } elseif ($act === 'del' && $id) {
            del('tasks', 'id = ? AND user_id = ?', [$id, $uid]); flash('Aufgabe geloescht.');
        }
        redirect(post('back') ?: url('aufgaben'));
    }
    $status  = get('status') ?: 'offen';
    $bereich = get('bereich');
    $sql = "SELECT t.*, s.name AS fach, s.color FROM tasks t LEFT JOIN subjects s ON s.id = t.subject_id
            WHERE t.user_id = ?" . ($status !== 'alle' ? " AND t.status = ?" : "")
          . ($bereich ? " AND t.bereich = ?" : "")
          . " ORDER BY t.status, (t.faellig IS NULL), t.faellig, t.prio DESC";
    $args = [$uid];
    if ($status !== 'alle') $args[] = $status;
    if ($bereich) $args[] = $bereich;
    $rows = all($sql, $args);
    $edit = get('id') !== '' ? one("SELECT * FROM tasks WHERE id = ? AND user_id = ?", [(int)get('id'), $uid]) : null;

    ob_start(); ?>
    <div class="split">
      <div class="card">
        <form method="get" class="row noprint" style="margin-bottom:.7rem">
          <input type="hidden" name="p" value="aufgaben">
          <select name="status" style="width:auto"><?= opts_simple(['offen' => 'Offen', 'erledigt' => 'Erledigt', 'alle' => 'Alle'], $status) ?></select>
          <select name="bereich" style="width:auto"><?= opts_simple(['' => 'Alle Bereiche', 'schule' => 'Schule', 'betrieb' => 'Betrieb', 'privat' => 'Privat'], $bereich) ?></select>
          <button class="btn sm" type="submit">Anzeigen</button>
          <input placeholder="Suchen ..." data-filter="#tsk" style="width:auto;flex:1;min-width:120px">
        </form>
        <?php if (!$rows): ?><?= ui_empty('Keine Aufgaben') ?><?php else: ?>
        <div class="tw"><table id="tsk"><tbody>
          <?php foreach ($rows as $t): $ueber = $t['status'] === 'offen' && $t['faellig'] && $t['faellig'] < today(); ?>
            <tr>
              <td style="width:1%">
                <form method="post" style="margin:0"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                  <input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
                  <button class="btn sm ghost" type="submit"><?= $t['status'] === 'offen' ? '&#9744;' : '&#9745;' ?></button>
                </form>
              </td>
              <td><a href="<?= url('aufgaben', ['id' => $t['id']]) ?>"
                     style="<?= $t['status'] === 'erledigt' ? 'text-decoration:line-through;color:var(--fg3)' : '' ?>"><?= h($t['titel']) ?></a>
                <?php if ($t['beschreibung']): ?><div class="small muted"><?= h(mb_substr($t['beschreibung'], 0, 90)) ?></div><?php endif; ?></td>
              <td class="small" style="white-space:nowrap"><?= $t['faellig'] ? h(de_date($t['faellig'])) : '<span class="muted2">-</span>' ?></td>
              <td><span class="tag"><?= h($t['bereich']) ?></span></td>
              <td class="small"><?= h($t['fach'] ?: '') ?></td>
              <td><?php if ((int)$t['prio'] === 2): ?><span class="tag err">hoch</span><?php endif; ?>
                  <?php if ($ueber): ?><span class="tag err">ueberfaellig</span><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
      </div>
      <div class="card">
        <h3><?= $edit ? 'Aufgabe bearbeiten' : 'Neue Aufgabe' ?></h3>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
          <div class="f"><label for="tt">Titel</label><input id="tt" name="titel" required value="<?= h($edit['titel'] ?? '') ?>"></div>
          <div class="f"><label for="td">Beschreibung</label><textarea id="td" name="beschreibung" style="min-height:60px"><?= h($edit['beschreibung'] ?? '') ?></textarea></div>
          <div class="fgrid">
            <div class="f"><label for="tf">Faellig</label><input id="tf" name="faellig" type="date" value="<?= h($edit['faellig'] ?? '') ?>"></div>
            <div class="f"><label for="tp">Prioritaet</label><select id="tp" name="prio"><?= opts_simple([0 => 'niedrig', 1 => 'normal', 2 => 'hoch'], $edit['prio'] ?? 1) ?></select></div>
          </div>
          <div class="fgrid">
            <div class="f"><label for="tb">Bereich</label><select id="tb" name="bereich"><?= opts_simple(['schule' => 'Schule', 'betrieb' => 'Betrieb', 'privat' => 'Privat'], $edit['bereich'] ?? 'schule') ?></select></div>
            <div class="f"><label for="tfa">Fach</label><select id="tfa" name="subject_id"><?= fach_options($u['class_id'] ? (int)$u['class_id'] : null, $edit['subject_id'] ?? null) ?></select></div>
          </div>
          <div class="row"><button class="btn pri" type="submit">Speichern</button>
            <?php if ($edit): ?><a class="btn ghost" href="<?= url('aufgaben') ?>">Neu</a><?php endif; ?></div>
        </form>
        <?php if ($edit): ?>
          <hr>
          <form method="post" data-confirm="Aufgabe loeschen?"><?= csrf_field() ?>
            <input type="hidden" name="action" value="del"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
            <button class="btn dan sm" type="submit">Loeschen</button></form>
        <?php endif; ?>
      </div>
    </div>
    <?php
    render_page('Aufgaben', ob_get_clean());
}

// ===========================================================================
// 15. DATEIEN (als BLOB in der Datenbank - nichts Ausfuehrbares im Webroot)
// ===========================================================================

const UPLOAD_MIME = [
    'application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif',
    'image/webp' => 'webp', 'image/svg+xml' => 'svg', 'text/plain' => 'txt', 'text/csv' => 'csv',
    'text/markdown' => 'md', 'application/zip' => 'zip', 'application/json' => 'json',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
];
function upload_speichern(array $f, int $uid, string $scope, ?int $scopeId, string $vis = 'private'): ?string {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK) return 'Upload fehlgeschlagen (Fehlercode ' . (int)$f['error'] . ').';
    if ($f['size'] > MAX_UPLOAD_MB * 1024 * 1024) return 'Datei ist groesser als ' . MAX_UPLOAD_MB . ' MB.';
    if (!is_uploaded_file($f['tmp_name'])) return 'Ungueltiger Upload.';
    $mime = 'application/octet-stream';
    if (class_exists('finfo')) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$fi->file($f['tmp_name']);
    }
    if (!isset(UPLOAD_MIME[$mime])) return 'Dateityp nicht erlaubt (' . h($mime) . ').';
    $daten = (string)file_get_contents($f['tmp_name']);
    if ($mime === 'image/svg+xml' && preg_match('/<script|onload=|javascript:/i', $daten)) {
        return 'SVG mit Skriptanteil wird abgelehnt.';
    }
    $name = preg_replace('/[^\p{L}\p{N} ._-]+/u', '_', (string)$f['name']);
    ins('files', [
        'owner_id' => $uid, 'name' => mb_substr($name, 0, 150), 'mime' => $mime,
        'groesse' => (int)$f['size'], 'sha256' => hash('sha256', $daten), 'daten' => $daten,
        'scope' => $scope, 'scope_id' => $scopeId, 'visibility' => $vis,
    ]);
    return null;
}
function datei_zugriff(array $u, array $f): bool {
    if ((int)$f['owner_id'] === (int)$u['id'] || is_admin()) return true;
    if ($f['visibility'] === 'all') return true;
    if ($f['visibility'] === 'class' && $f['scope'] === 'note') {
        $n = one("SELECT class_id FROM notes WHERE id = ?", [(int)$f['scope_id']]);
        return $n && (int)$n['class_id'] === (int)($u['class_id'] ?: 0);
    }
    return false;
}
function action_datei(): void {
    $u  = require_login();
    $id = (int)get('id');
    $f  = one("SELECT * FROM files WHERE id = ?", [$id]);
    if (!$f || !datei_zugriff($u, $f)) { http_response_code(404); exit('Nicht gefunden.'); }
    header_remove('Content-Security-Policy');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Content-Type: application/octet-stream');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^\x20-\x7e]/', '_', $f['name']) . '"');
    header('Content-Length: ' . strlen($f['daten']));
    header('Cache-Control: private, no-store');
    echo $f['daten'];
    exit;
}

// ===========================================================================
// 16. NOTIZEN
// ===========================================================================

function page_notizen(): void {
    $u   = require_login();
    $uid = (int)$u['id'];
    $cid = (int)($u['class_id'] ?: 0);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $act = post('action');
        $id  = (int)post('id', '0');
        if ($act === 'save') {
            $data = [
                'subject_id' => int_or_null(postn('subject_id')),
                'lf_no'      => int_or_null(postn('lf_no')),
                'datum'      => preg_match('/^\d{4}-\d{2}-\d{2}$/', post('datum')) ? post('datum') : today(),
                'titel'      => mb_substr(post('titel'), 0, 200),
                'body'       => post('body'),
                'tags'       => mb_substr(post('tags'), 0, 200),
                'kind'       => in_array(post('kind'), ['notiz','randnotiz','howto','snippet','link','zusammenfassung'], true) ? post('kind') : 'notiz',
                'sprache'    => mb_substr(post('sprache'), 0, 30),
                'visibility' => in_array(post('visibility'), ['private','class','all'], true) ? post('visibility') : 'private',
                'pinned'     => post('pinned') ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($data['titel'] === '' && $data['body'] === '') flash('Leere Notiz.', 'warn');
            elseif ($id) {
                $n = one("SELECT * FROM notes WHERE id = ?", [$id]);
                if ($n && ((int)$n['user_id'] === $uid || is_admin())) { upd('notes', $data, 'id = :id', ['id' => $id]); flash('Notiz gespeichert.'); }
                else flash('Kein Zugriff.', 'err');
            } else {
                $data['user_id'] = $uid; $data['class_id'] = $cid ?: null;
                $id = ins('notes', $data); flash('Notiz angelegt.');
            }
            if (!empty($_FILES['datei']['name'])) {
                $err = upload_speichern($_FILES['datei'], $uid, 'note', $id, $data['visibility']);
                if ($err) flash($err, 'err'); else flash('Datei angehaengt.');
            }
            redirect(url('notizen', ['id' => $id]));
        }
        if ($act === 'del' && $id) {
            $n = one("SELECT * FROM notes WHERE id = ?", [$id]);
            if ($n && ((int)$n['user_id'] === $uid || is_admin())) {
                del('files', "scope = 'note' AND scope_id = ?", [$id]);
                del('notes', 'id = ?', [$id]); flash('Notiz geloescht.');
            }
            redirect(url('notizen'));
        }
        if ($act === 'delfile') {
            $fid = (int)post('fid', '0');
            $f = one("SELECT * FROM files WHERE id = ?", [$fid]);
            if ($f && ((int)$f['owner_id'] === $uid || is_admin())) { del('files', 'id = ?', [$fid]); flash('Anhang entfernt.'); }
            redirect(url('notizen', ['id' => $id]));
        }
    }
    $edit = null;
    if (get('id') !== '') {
        $edit = one("SELECT * FROM notes WHERE id = ?", [(int)get('id')]);
        if ($edit && (int)$edit['user_id'] !== $uid && !($edit['visibility'] !== 'private' && (int)$edit['class_id'] === $cid) && !is_admin()) $edit = null;
    } elseif (get('neu') !== '') { $edit = ['id' => 0, 'datum' => today()]; }

    $qs   = get('q');
    $kind = get('kind');
    $sql  = "SELECT n.*, s.name AS fach, u.display_name AS autor FROM notes n
             LEFT JOIN subjects s ON s.id = n.subject_id LEFT JOIN users u ON u.id = n.user_id
             WHERE (n.user_id = ? OR (n.visibility IN ('class','all') AND n.class_id = ?) OR n.visibility = 'all')"
          . ($kind ? " AND n.kind = ?" : "")
          . ($qs ? " AND (n.titel LIKE ? OR n.body LIKE ? OR n.tags LIKE ?)" : "")
          . " ORDER BY n.pinned DESC, n.datum DESC, n.id DESC LIMIT 300";
    $args = [$uid, $cid];
    if ($kind) $args[] = $kind;
    if ($qs) { $like = '%' . $qs . '%'; array_push($args, $like, $like, $like); }
    $rows = all($sql, $args);
    $files = $edit && !empty($edit['id'])
        ? all("SELECT * FROM files WHERE scope = 'note' AND scope_id = ?", [(int)$edit['id']]) : [];

    ob_start(); ?>
    <div class="split">
      <div>
        <div class="card">
          <form method="get" class="row noprint" style="margin-bottom:.7rem">
            <input type="hidden" name="p" value="notizen">
            <input name="q" value="<?= h($qs) ?>" placeholder="Suchen in Titel, Text, Tags" style="flex:1;min-width:150px">
            <select name="kind" style="width:auto"><?= opts_simple(['' => 'Alle Arten', 'notiz' => 'Notiz', 'randnotiz' => 'Randnotiz',
              'zusammenfassung' => 'Zusammenfassung', 'howto' => 'How-To', 'snippet' => 'Code-Snippet', 'link' => 'Link'], $kind) ?></select>
            <button class="btn sm" type="submit">Suchen</button>
            <a class="btn pri sm" href="<?= url('notizen', ['neu' => 1]) ?>">+ Neu</a>
          </form>
          <?php if (!$rows): ?><?= ui_empty('Keine Notizen gefunden') ?><?php else: ?>
          <ul class="list">
            <?php foreach ($rows as $n): ?>
              <li>
                <span class="dot" style="margin-top:.45rem;background:<?= $n['pinned'] ? 'var(--warn)' : 'var(--line2)' ?>"></span>
                <div style="flex:1;min-width:0">
                  <a href="<?= url('notizen', ['id' => $n['id']]) ?>"><strong><?= h($n['titel'] ?: mb_substr($n['body'], 0, 70)) ?></strong></a>
                  <div class="small muted" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?= h(de_date($n['datum'])) ?>
                    <?= $n['fach'] ? ' &middot; ' . h($n['fach']) : '' ?>
                    <?= $n['lf_no'] ? ' &middot; LF ' . (int)$n['lf_no'] : '' ?>
                    <?= (int)$n['user_id'] !== $uid ? ' &middot; ' . h($n['autor']) : '' ?>
                    <?php foreach (array_slice(array_filter(array_map('trim', explode(',', $n['tags']))), 0, 4) as $t): ?>
                      &middot; <span class="tag"><?= h($t) ?></span><?php endforeach; ?>
                  </div>
                </div>
                <span class="tag <?= $n['visibility'] === 'private' ? '' : 'info' ?>"><?= h($n['kind']) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </div>
      </div>

      <div>
        <?php if ($edit !== null): $isNew = empty($edit['id']); $own = $isNew || (int)$edit['user_id'] === $uid || is_admin(); ?>
        <div class="card">
          <h3><?= $isNew ? 'Neue Notiz' : ($own ? 'Notiz bearbeiten' : 'Notiz') ?></h3>
          <?php if (!$own): ?>
            <p class="small muted"><?= h(de_date($edit['datum'])) ?></p>
            <h4><?= h($edit['titel']) ?></h4>
            <div><?= md_lite($edit['body']) ?></div>
          <?php else: ?>
          <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="f"><label for="nt">Titel</label><input id="nt" name="titel" value="<?= h($edit['titel'] ?? '') ?>"></div>
            <div class="fgrid">
              <div class="f"><label for="nd">Datum</label><input id="nd" name="datum" type="date" value="<?= h($edit['datum'] ?? today()) ?>"></div>
              <div class="f"><label for="nk">Art</label><select id="nk" name="kind"><?= opts_simple(['notiz' => 'Notiz', 'randnotiz' => 'Randnotiz',
                'zusammenfassung' => 'Zusammenfassung', 'howto' => 'How-To', 'snippet' => 'Code-Snippet', 'link' => 'Link'], $edit['kind'] ?? 'notiz') ?></select></div>
            </div>
            <div class="fgrid">
              <div class="f"><label for="nf">Fach</label><select id="nf" name="subject_id"><?= fach_options($cid ?: null, $edit['subject_id'] ?? null) ?></select></div>
              <div class="f"><label for="nl">Lernfeld</label><select id="nl" name="lf_no"><?= lf_options($edit['lf_no'] ?? null) ?></select></div>
            </div>
            <div class="f"><label for="nb">Inhalt <span class="muted small">(**fett**, *kursiv*, `code`, ``` fuer Bloecke)</span></label>
              <textarea id="nb" name="body" style="min-height:200px" data-draft="note<?= (int)($edit['id'] ?? 0) ?>"><?= h($edit['body'] ?? '') ?></textarea></div>
            <div class="fgrid">
              <div class="f"><label for="ng">Tags (Komma)</label><input id="ng" name="tags" value="<?= h($edit['tags'] ?? '') ?>"></div>
              <div class="f"><label for="ns">Sprache (Snippet)</label><input id="ns" name="sprache" value="<?= h($edit['sprache'] ?? '') ?>" placeholder="powershell"></div>
            </div>
            <div class="fgrid">
              <div class="f"><label for="nv">Sichtbarkeit</label><select id="nv" name="visibility"><?= opts_simple(
                ['private' => 'Nur ich', 'class' => 'Meine Klasse', 'all' => 'Alle im Portal'], $edit['visibility'] ?? 'private') ?></select></div>
              <div class="f"><label for="np">Anheften</label>
                <select id="np" name="pinned"><?= opts_simple([0 => 'nein', 1 => 'ja'], $edit['pinned'] ?? 0) ?></select></div>
            </div>
            <div class="f"><label for="nu">Datei anhaengen (max. <?= MAX_UPLOAD_MB ?> MB)</label><input id="nu" name="datei" type="file"></div>
            <div class="row"><button class="btn pri" type="submit">Speichern</button>
              <a class="btn ghost" href="<?= url('notizen') ?>">Abbrechen</a></div>
          </form>
          <?php endif; ?>
          <?php if ($files): ?>
            <hr><h4>Anhaenge</h4>
            <ul class="list">
              <?php foreach ($files as $f): ?>
                <li><a href="<?= url('datei', ['id' => $f['id']]) ?>"><?= h($f['name']) ?></a>
                  <span class="small muted2"><?= num($f['groesse'] / 1024, 0) ?> kB</span>
                  <?php if ($own): ?>
                  <form method="post" style="margin-left:auto"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="delfile"><input type="hidden" name="fid" value="<?= (int)$f['id'] ?>">
                    <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
                    <button class="btn sm ghost dan" type="submit">&times;</button></form>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?php if (!$isNew && $own): ?>
            <hr>
            <form method="post" data-confirm="Notiz loeschen?"><?= csrf_field() ?>
              <input type="hidden" name="action" value="del"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
              <button class="btn dan sm" type="submit">Loeschen</button></form>
          <?php endif; ?>
        </div>
        <?php else: ?>
          <div class="card"><h3>Tipp</h3>
            <p class="small muted">Randnotizen aus dem Unterricht landen mit dem Datum automatisch
            als Vorschlag im Berichtsheft der passenden Woche.</p>
            <a class="btn pri sm" href="<?= url('notizen', ['neu' => 1]) ?>">+ Notiz anlegen</a></div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    render_page('Notizen', ob_get_clean());
}

// ===========================================================================
// 17. WISSEN & STOFF (geteilte Inhalte)
// ===========================================================================

function page_wissen(): void {
    $u   = require_login();
    $uid = (int)$u['id'];
    $cid = (int)($u['class_id'] ?: 0);
    $lf  = get('lf'); $kind = get('kind'); $qs = get('q');
    $sql = "SELECT n.*, s.name AS fach, u.display_name AS autor, l.code AS lfcode, l.titel AS lftitel
            FROM notes n LEFT JOIN subjects s ON s.id = n.subject_id
            LEFT JOIN users u ON u.id = n.user_id LEFT JOIN lernfelder l ON l.nr = n.lf_no
            WHERE (n.visibility = 'all' OR (n.visibility = 'class' AND n.class_id = ?) OR n.user_id = ?)"
         . ($lf ? " AND n.lf_no = ?" : "") . ($kind ? " AND n.kind = ?" : "")
         . ($qs ? " AND (n.titel LIKE ? OR n.body LIKE ? OR n.tags LIKE ?)" : "")
         . " ORDER BY n.lf_no, n.pinned DESC, n.updated_at DESC LIMIT 400";
    $args = [$cid, $uid];
    if ($lf) $args[] = (int)$lf;
    if ($kind) $args[] = $kind;
    if ($qs) { $like = '%' . $qs . '%'; array_push($args, $like, $like, $like); }
    $rows = all($sql, $args);
    $grp = [];
    foreach ($rows as $r) $grp[$r['lfcode'] ? $r['lfcode'] . ' - ' . $r['lftitel'] : 'Ohne Lernfeld'][] = $r;

    $detail = get('id') !== '' ? one("SELECT n.*, s.name AS fach, u.display_name AS autor
        FROM notes n LEFT JOIN subjects s ON s.id = n.subject_id LEFT JOIN users u ON u.id = n.user_id
        WHERE n.id = ?", [(int)get('id')]) : null;
    if ($detail && $detail['visibility'] === 'private' && (int)$detail['user_id'] !== $uid && !is_admin()) $detail = null;

    ob_start(); ?>
    <div class="split">
      <div>
        <div class="card">
          <form method="get" class="row noprint">
            <input type="hidden" name="p" value="wissen">
            <input name="q" value="<?= h($qs) ?>" placeholder="Stoff durchsuchen ..." style="flex:1;min-width:150px">
            <select name="lf" style="width:auto"><?= opts(all("SELECT nr AS id, code AS name FROM lernfelder ORDER BY nr"), $lf, 'id', 'name', 'Alle LF') ?></select>
            <select name="kind" style="width:auto"><?= opts_simple(['' => 'Alle Arten', 'zusammenfassung' => 'Zusammenfassung',
              'howto' => 'How-To', 'snippet' => 'Snippet', 'link' => 'Link', 'notiz' => 'Notiz'], $kind) ?></select>
            <button class="btn sm" type="submit">Filtern</button>
            <a class="btn pri sm" href="<?= url('notizen', ['neu' => 1]) ?>">+ Beitrag</a>
          </form>
        </div>
        <?php if (!$rows): ?><div class="card"><?= ui_empty('Noch kein geteilter Stoff', 'Notizen mit Sichtbarkeit "Klasse" oder "Alle" erscheinen hier.') ?></div><?php endif; ?>
        <?php foreach ($grp as $lfName => $items): ?>
          <details class="acc" open>
            <summary><?= h($lfName) ?> <span class="tag"><?= count($items) ?></span></summary>
            <div><ul class="list">
              <?php foreach ($items as $n): ?>
                <li><span class="tag <?= $n['kind'] === 'snippet' ? 'acc' : '' ?>"><?= h($n['kind']) ?></span>
                  <div style="flex:1"><a href="<?= url('wissen', ['id' => $n['id']]) ?>"><?= h($n['titel'] ?: mb_substr($n['body'], 0, 60)) ?></a>
                    <div class="small muted2"><?= h($n['autor']) ?> &middot; <?= h(de_date($n['updated_at'] ?: $n['datum'])) ?><?= $n['fach'] ? ' &middot; ' . h($n['fach']) : '' ?></div></div>
                </li>
              <?php endforeach; ?>
            </ul></div>
          </details>
        <?php endforeach; ?>
      </div>
      <div>
        <?php if ($detail): ?>
          <div class="card">
            <div class="row" style="justify-content:space-between">
              <h3 style="margin:0"><?= h($detail['titel']) ?></h3>
              <?php if ((int)$detail['user_id'] === $uid || is_admin()): ?>
                <a class="btn sm" href="<?= url('notizen', ['id' => $detail['id']]) ?>">bearbeiten</a><?php endif; ?>
            </div>
            <p class="small muted"><?= h($detail['autor']) ?> &middot; <?= h(de_date($detail['datum'])) ?>
              <?= $detail['fach'] ? ' &middot; ' . h($detail['fach']) : '' ?>
              <?= $detail['lf_no'] ? ' &middot; LF ' . (int)$detail['lf_no'] : '' ?></p>
            <?php if ($detail['kind'] === 'snippet'): ?>
              <button class="btn sm copybtn" data-copy="snip" type="button">kopieren</button>
              <pre id="snip"><?= h($detail['body']) ?></pre>
            <?php else: ?>
              <div><?= md_lite($detail['body']) ?></div>
            <?php endif; ?>
            <?php $af = all("SELECT * FROM files WHERE scope = 'note' AND scope_id = ?", [(int)$detail['id']]);
              if ($af): ?><hr><h4>Anhaenge</h4><ul class="list">
              <?php foreach ($af as $f): ?><li><a href="<?= url('datei', ['id' => $f['id']]) ?>"><?= h($f['name']) ?></a></li><?php endforeach; ?>
              </ul><?php endif; ?>
          </div>
        <?php else: ?>
          <div class="card"><h3>Wissensbasis</h3>
            <p class="small muted">Hier sammelt die Klasse Zusammenfassungen, How-Tos und
            Code-Snippets - sortiert nach Lernfeld. Beitraege entstehen aus Notizen mit
            Sichtbarkeit "Meine Klasse" oder "Alle im Portal".</p>
            <div class="chips">
              <?php foreach (all("SELECT nr, code FROM lernfelder ORDER BY nr") as $l): ?>
                <a class="chip <?= (string)$l['nr'] === $lf ? 'on' : '' ?>" href="<?= url('wissen', ['lf' => $l['nr']]) ?>"><?= h($l['code']) ?></a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    render_page('Wissen & Stoff', ob_get_clean());
}

// ===========================================================================
// 18. GLOBALE SUCHE
// ===========================================================================

function page_suche(): void {
    $u   = require_login();
    $uid = (int)$u['id'];
    $cid = (int)($u['class_id'] ?: 0);
    $qs  = get('q');
    $tref = [];
    if (mb_strlen($qs) >= 2) {
        $like = '%' . $qs . '%';
        $tref['Notizen & Wissen'] = array_map(fn($r) => [
            'titel' => $r['titel'] ?: mb_substr($r['body'], 0, 60), 'sub' => de_date($r['datum']) . ' &middot; ' . h($r['kind']),
            'url' => url('notizen', ['id' => $r['id']])],
            all("SELECT * FROM notes WHERE (user_id = ? OR (visibility IN ('class','all') AND class_id = ?) OR visibility='all')
                 AND (titel LIKE ? OR body LIKE ? OR tags LIKE ?) ORDER BY datum DESC LIMIT 25",
                [$uid, $cid, $like, $like, $like]));
        $tref['Proben & Termine'] = array_map(fn($r) => [
            'titel' => $r['titel'], 'sub' => de_date($r['datum']) . ' &middot; ' . h(typ_label($r['typ'])),
            'url' => url('termine', ['id' => $r['id']])],
            all("SELECT * FROM events WHERE ((visibility='class' AND class_id = ?) OR user_id = ?)
                 AND (titel LIKE ? OR beschreibung LIKE ? OR stoff LIKE ?) ORDER BY datum DESC LIMIT 25",
                [$cid, $uid, $like, $like, $like]));
        $tref['Aufgaben'] = array_map(fn($r) => [
            'titel' => $r['titel'], 'sub' => ($r['faellig'] ? de_date($r['faellig']) : 'ohne Frist') . ' &middot; ' . h($r['status']),
            'url' => url('aufgaben', ['id' => $r['id']])],
            all("SELECT * FROM tasks WHERE user_id = ? AND (titel LIKE ? OR beschreibung LIKE ?) LIMIT 25",
                [$uid, $like, $like]));
        $tref['Berichtsheft'] = array_map(fn($r) => [
            'titel' => $r['text'], 'sub' => de_date($r['datum']) . ' &middot; ' . num((float)$r['stunden'], 1) . ' h',
            'url' => url('berichtsheft', ['periode' => periode_of($r['datum'], setting('berichtsheft_art', 'woche'))])],
            all("SELECT * FROM report_entries WHERE user_id = ? AND text LIKE ? ORDER BY datum DESC LIMIT 25",
                [$uid, $like]));
        $tref['Betriebliche Routinen'] = array_map(fn($r) => [
            'titel' => $r['name'], 'sub' => h($r['intervall']), 'url' => url('betrieb')],
            all("SELECT * FROM routines WHERE aktiv = 1 AND (name LIKE ? OR beschreibung LIKE ?) LIMIT 15",
                [$like, $like]));
    }
    ob_start(); ?>
    <div class="card">
      <form method="get" class="row"><input type="hidden" name="p" value="suche">
        <input name="q" value="<?= h($qs) ?>" placeholder="Suchbegriff (mind. 2 Zeichen)" autofocus style="flex:1">
        <button class="btn pri" type="submit">Suchen</button></form>
    </div>
    <?php if (mb_strlen($qs) >= 2):
      $total = array_sum(array_map('count', $tref)); ?>
      <p class="muted small"><?= $total ?> Treffer fuer &bdquo;<?= h($qs) ?>&ldquo;</p>
      <?php foreach ($tref as $gruppe => $items): if (!$items) continue; ?>
        <div class="card"><h3><?= h($gruppe) ?> <span class="tag"><?= count($items) ?></span></h3>
          <ul class="list"><?php foreach ($items as $i): ?>
            <li><div style="flex:1"><a href="<?= h($i['url']) ?>"><?= h($i['titel']) ?></a>
              <?php /* 'sub' wird beim Aufbau bereits escaped */ ?>
              <div class="small muted2"><?= $i['sub'] ?></div></div></li>
          <?php endforeach; ?></ul></div>
      <?php endforeach; ?>
      <?php if (!$total): ?><div class="card"><?= ui_empty('Nichts gefunden') ?></div><?php endif; ?>
    <?php endif; ?>
    <?php
    render_page('Suche', ob_get_clean());
}

// ===========================================================================
// 19. NOTEN & STATISTIK
// ===========================================================================

function page_noten(): void {
    $u   = require_login();
    $uid = (int)$u['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $act = post('action'); $id = (int)post('id', '0');
        if ($act === 'save') {
            $data = [
                'subject_id' => int_or_null(postn('subject_id')),
                'fach_text'  => mb_substr(post('fach_text'), 0, 80),
                'art'        => in_array(post('art'), ['schulaufgabe','kurzarbeit','test','muendlich','projekt','referat','ihk'], true) ? post('art') : 'test',
                'skala'      => in_array(post('skala'), ['note','punkte','ihk'], true) ? post('skala') : 'note',
                'wert'       => (float)str_replace(',', '.', post('wert', '0')),
                'gewicht'    => max(0, (float)str_replace(',', '.', post('gewicht', '1'))),
                'datum'      => preg_match('/^\d{4}-\d{2}-\d{2}$/', post('datum')) ? post('datum') : today(),
                'titel'      => mb_substr(post('titel'), 0, 150),
                'bemerkung'  => post('bemerkung'),
                'halbjahr'   => mb_substr(post('halbjahr'), 0, 20),
            ];
            if ($id) { upd('grades', $data, 'id = :id AND user_id = :u', ['id' => $id, 'u' => $uid]); flash('Note gespeichert.'); }
            else { $data['user_id'] = $uid; ins('grades', $data); flash('Note eingetragen.'); }
        } elseif ($act === 'del' && $id) { del('grades', 'id = ? AND user_id = ?', [$id, $uid]); flash('Note geloescht.'); }
        elseif ($act === 'ihk') {
            $p = [];
            foreach (array_keys(ihk_bereiche()) as $k) $p[$k] = post('ihk_' . $k);
            setting_set('ihk_' . $uid, json_encode($p));
            flash('Pruefungspunkte gespeichert.');
        }
        redirect(url('noten'));
    }
    $gs   = grade_stats($uid);
    $edit = get('id') !== '' ? one("SELECT * FROM grades WHERE id = ? AND user_id = ?", [(int)get('id'), $uid]) : null;
    if (get('neu') !== '') $edit = ['id' => 0];
    $ihkP = json_decode((string)setting('ihk_' . $uid, '{}'), true) ?: [];
    $prog = ihk_prognose($ihkP);
    $best = ihk_bestanden($ihkP);
    $maxV = max(1, max($gs['verteilung']));

    ob_start(); ?>
    <div class="grid g4" style="margin-bottom:1rem">
      <?= ui_kpi('Gesamtschnitt', $gs['schnitt'] !== null ? num($gs['schnitt'], 2) : '&ndash;',
            count($gs['rows']) . ' Noten', $gs['schnitt'] !== null ? (6 - $gs['schnitt']) / 5 * 100 : null, note_farbe($gs['schnitt'])) ?>
      <?= ui_kpi('Faecher', (string)count($gs['faecher']), 'mit Noten') ?>
      <?php $alle = array_filter(array_map(fn($r) => note_to_points((float)$r['wert'], $r['skala']), $gs['rows']), fn($v) => $v !== null); ?>
      <?= ui_kpi('Beste Note', $alle ? num(min($alle), 1) : '&ndash;', $alle ? 'schlechteste ' . num(max($alle), 1) : '') ?>
      <?= ui_kpi('IHK-Prognose', $prog['punkte'] !== null ? num($prog['punkte'], 1) . ' P' : '&ndash;',
            $prog['note'] !== null ? 'Note ' . num($prog['note'], 1) . ' &middot; ' . $prog['abdeckung'] . '&thinsp;% erfasst' : 'unten eintragen',
            $prog['punkte']) ?>
    </div>

    <div class="split">
      <div>
        <div class="card">
          <div class="row" style="justify-content:space-between;margin-bottom:.5rem">
            <h2 style="margin:0">Alle Noten</h2>
            <div class="row"><a class="btn sm" href="<?= url('export', ['was' => 'noten']) ?>">CSV</a>
              <a class="btn pri sm" href="<?= url('noten', ['neu' => 1]) ?>">+ Note</a></div>
          </div>
          <?php if (!$gs['rows']): ?><?= ui_empty('Noch keine Noten erfasst') ?><?php else: ?>
          <div class="tw"><table><thead><tr><th>Datum</th><th>Fach</th><th>Art</th><th>Titel</th><th>Note</th><th>Gew.</th><th></th></tr></thead><tbody>
            <?php foreach (array_reverse($gs['rows']) as $g): $n = note_to_points((float)$g['wert'], $g['skala']); ?>
              <tr>
                <td class="small" style="white-space:nowrap"><?= h(de_date($g['datum'])) ?></td>
                <td><?= h($g['fach'] ?: '-') ?></td>
                <td><span class="tag"><?= h($g['art']) ?></span></td>
                <td class="small"><?= h($g['titel']) ?><?= $g['halbjahr'] ? ' <span class="muted2">(' . h($g['halbjahr']) . ')</span>' : '' ?></td>
                <td><?= ui_note($n) ?><?php if ($g['skala'] !== 'note'): ?> <span class="small muted2"><?= num((float)$g['wert'], 0) ?><?= $g['skala'] === 'punkte' ? ' P' : ' %' ?></span><?php endif; ?></td>
                <td class="small"><?= num((float)$g['gewicht'], 1) ?></td>
                <td><a class="btn sm ghost" href="<?= url('noten', ['id' => $g['id']]) ?>">&#9998;</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <?php endif; ?>
        </div>

        <?php if ($gs['faecher']): ?>
        <div class="card">
          <h2>Statistik</h2>
          <div class="grid g2">
            <div>
              <h4>Schnitt je Fach</h4>
              <?= ui_hbar(array_map(fn($f) => [$f['name'], max(0.1, 6 - (float)$f['schnitt']), note_farbe($f['schnitt']), num((float)$f['schnitt'], 2)],
                    array_filter($gs['faecher'], fn($f) => $f['schnitt'] !== null)), '') ?>
            </div>
            <div>
              <h4>Notenverteilung</h4>
              <div class="row" style="align-items:flex-end;height:130px;gap:.5rem">
                <?php foreach ($gs['verteilung'] as $note => $anz): ?>
                  <div style="flex:1;text-align:center">
                    <div style="height:<?= (int)round($anz / $maxV * 96) ?>px;background:<?= h(note_farbe((float)$note)) ?>;border-radius:5px 5px 0 0;min-height:2px"></div>
                    <div class="small"><strong><?= $note ?></strong></div>
                    <div class="small muted2"><?= $anz ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
          <hr>
          <h4>Verlauf je Fach</h4>
          <div class="tw"><table><thead><tr><th>Fach</th><th>Schnitt</th><th>Anzahl</th><th>Verlauf</th><th>Tendenz</th></tr></thead><tbody>
            <?php foreach ($gs['faecher'] as $f): if ($f['schnitt'] === null) continue; ?>
              <tr><td><?= h($f['name']) ?></td><td><?= ui_note($f['schnitt']) ?></td><td><?= (int)$f['anzahl'] ?></td>
                <td><?= ui_spark(array_column($f['n'], 'v'), 140, 30) ?></td>
                <td class="small"><?php if ($f['trend'] === null): ?><span class="muted2">-</span>
                  <?php elseif ($f['trend'] > 0.15): ?><span style="color:var(--ok)">&#9650; <?= num(abs($f['trend']), 2) ?></span>
                  <?php elseif ($f['trend'] < -0.15): ?><span style="color:var(--err)">&#9660; <?= num(abs($f['trend']), 2) ?></span>
                  <?php else: ?><span class="muted">stabil</span><?php endif; ?></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </div>
        <?php endif; ?>
      </div>

      <div>
        <div class="card">
          <h3><?= $edit ? ($edit['id'] ? 'Note bearbeiten' : 'Neue Note') : 'Neue Note' ?></h3>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="f"><label for="gf">Fach</label><select id="gf" name="subject_id"><?= fach_options($u['class_id'] ? (int)$u['class_id'] : null, $edit['subject_id'] ?? null) ?></select></div>
            <div class="f"><label for="gft">oder Fach als Text</label><input id="gft" name="fach_text" value="<?= h($edit['fach_text'] ?? '') ?>" placeholder="z.B. Englisch"></div>
            <div class="fgrid">
              <div class="f"><label for="ga">Art</label><select id="ga" name="art"><?= opts_simple(['schulaufgabe' => 'Schulaufgabe', 'kurzarbeit' => 'Kurzarbeit',
                'test' => 'Test / Ex', 'muendlich' => 'Muendlich', 'projekt' => 'Projekt', 'referat' => 'Referat', 'ihk' => 'IHK-Pruefung'], $edit['art'] ?? 'schulaufgabe') ?></select></div>
              <div class="f"><label for="gs">Skala</label><select id="gs" name="skala"><?= opts_simple(['note' => 'Note 1-6', 'punkte' => 'Punkte 0-15', 'ihk' => 'IHK 0-100'], $edit['skala'] ?? 'note') ?></select></div>
            </div>
            <div class="fgrid">
              <div class="f"><label for="gw">Wert</label><input id="gw" name="wert" required value="<?= h($edit['wert'] ?? '') ?>" inputmode="decimal"></div>
              <div class="f"><label for="gg">Gewicht</label><input id="gg" name="gewicht" value="<?= h($edit['gewicht'] ?? '1') ?>" inputmode="decimal"></div>
              <div class="f"><label for="gd">Datum</label><input id="gd" name="datum" type="date" value="<?= h($edit['datum'] ?? today()) ?>"></div>
            </div>
            <div class="f"><label for="gt">Titel</label><input id="gt" name="titel" value="<?= h($edit['titel'] ?? '') ?>" placeholder="z.B. 2. Schulaufgabe LF 9"></div>
            <div class="fgrid">
              <div class="f"><label for="gh">Halbjahr</label><input id="gh" name="halbjahr" value="<?= h($edit['halbjahr'] ?? '') ?>" placeholder="z.B. 2/2026"></div>
            </div>
            <div class="f"><label for="gb">Bemerkung</label><input id="gb" name="bemerkung" value="<?= h($edit['bemerkung'] ?? '') ?>"></div>
            <div class="row"><button class="btn pri" type="submit">Speichern</button>
              <?php if (!empty($edit['id'])): ?><a class="btn ghost" href="<?= url('noten') ?>">Neu</a><?php endif; ?></div>
          </form>
          <?php if (!empty($edit['id'])): ?>
            <hr><form method="post" data-confirm="Note loeschen?"><?= csrf_field() ?>
              <input type="hidden" name="action" value="del"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
              <button class="btn dan sm" type="submit">Loeschen</button></form>
          <?php endif; ?>
        </div>

        <div class="card">
          <h3>IHK-Abschlusspruefung</h3>
          <p class="small muted">Punkte (0-100) je Pruefungsbereich eintragen - die Gewichtung
          entspricht der Verordnung fuer Fachinformatiker/-in Systemintegration.</p>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="ihk">
            <?php foreach (ihk_bereiche() as $k => [$label, $w]): ?>
              <div class="f"><label for="ik<?= $k ?>"><?= h($label) ?> <span class="tag"><?= $w ?>&thinsp;%</span></label>
                <input id="ik<?= $k ?>" name="ihk_<?= $k ?>" inputmode="decimal" value="<?= h((string)($ihkP[$k] ?? '')) ?>" placeholder="0-100"></div>
            <?php endforeach; ?>
            <button class="btn pri sm" type="submit">Speichern</button>
          </form>
          <?php if ($prog['punkte'] !== null): ?>
            <hr>
            <div class="row" style="justify-content:space-between">
              <span>Gesamt</span><strong><?= num($prog['punkte'], 1) ?> Punkte &middot; Note <?= num((float)$prog['note'], 1) ?></strong></div>
            <div class="bar"><i style="width:<?= (int)$prog['punkte'] ?>%;background:<?= $prog['punkte'] >= 50 ? 'var(--ok)' : 'var(--err)' ?>"></i></div>
            <div style="margin-top:.5rem">
              <?php if ($best['ok']): ?><span class="tag ok">Bestehensregeln erfuellt</span>
              <?php else: foreach ($best['probleme'] as $pr): ?><div class="small" style="color:var(--warn)">&#9888; <?= h($pr) ?></div><?php endforeach; endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php
    render_page('Noten & Statistik', ob_get_clean());
}

// ===========================================================================
// 20. BERICHTSHEFT (Ausbildungsnachweis)
// ===========================================================================

function page_berichtsheft(): void {
    $u   = require_login();
    $uid = (int)$u['id'];
    $art = get('art') ?: setting('berichtsheft_art', 'woche');
    if (!in_array($art, ['woche', 'monat'], true)) $art = 'woche';
    $per = get('periode') ?: periode_of(today(), $art);
    if (!periode_gueltig($per, $art)) $per = periode_of(today(), $art);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $act = post('action');
        $perPost = post('periode') ?: $per;
        if (!periode_gueltig($perPost, $art)) $perPost = $per;
        $rep = ensure_report($uid, $art, $perPost);
        $rid = (int)$rep['id'];
        $gesperrt = in_array($rep['status'], ['eingereicht', 'geprueft'], true) && !can_review();
        if ($act === 'addentry' && !$gesperrt) {
            $text = post('text');
            $cat  = int_or_null(postn('category_id'));
            if ($text !== '') {
                if (!$cat) { $k = kategorie_fuer_text($text); $cat = $k ? (int)$k['category_id'] : null; }
                ins('report_entries', [
                    'report_id' => $rid, 'user_id' => $uid,
                    'datum'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', post('datum')) ? post('datum') : $rep['von'],
                    'stunden'   => max(0, (float)str_replace(',', '.', post('stunden', '0'))),
                    'category_id' => $cat, 'lf_no' => int_or_null(postn('lf_no')),
                    'ort'       => in_array(post('ort'), ['betrieb','schule','ueba','urlaub','krank','feiertag','frei'], true) ? post('ort') : 'betrieb',
                    'text'      => $text, 'quelle' => 'manuell',
                ]);
                flash('Eintrag hinzugefuegt.');
            }
        } elseif ($act === 'delentry' && !$gesperrt) {
            del('report_entries', 'id = ? AND user_id = ?', [(int)post('eid', '0'), $uid]);
            flash('Eintrag entfernt.');
        } elseif ($act === 'updentry' && !$gesperrt) {
            $eid = (int)post('eid', '0');
            upd('report_entries', [
                'datum'       => preg_match('/^\d{4}-\d{2}-\d{2}$/', post('datum')) ? post('datum') : $rep['von'],
                'stunden'     => max(0, (float)str_replace(',', '.', post('stunden', '0'))),
                'category_id' => int_or_null(postn('category_id')),
                'ort'         => post('ort'), 'text' => post('text'),
            ], 'id = :id AND user_id = :u', ['id' => $eid, 'u' => $uid]);
            flash('Eintrag aktualisiert.');
        } elseif ($act === 'autofill' && !$gesperrt) {
            $n = report_autofill($rep, $u);
            flash($n > 0 ? $n . ' Vorschlaege uebernommen.' : 'Keine neuen Vorschlaege gefunden.', $n > 0 ? 'ok' : 'info');
        } elseif ($act === 'meta' && !$gesperrt) {
            upd('reports', ['schule_text' => post('schule_text'), 'sonstiges' => post('sonstiges'),
                'abteilung' => mb_substr(post('abteilung'), 0, 100), 'updated_at' => date('Y-m-d H:i:s')],
                'id = :id AND user_id = :u', ['id' => $rid, 'u' => $uid]);
            flash('Gespeichert.');
        } elseif ($act === 'einreichen') {
            $anz = (int)val("SELECT COUNT(*) FROM report_entries WHERE report_id = ?", [$rid], 0);
            if ($anz === 0) flash('Ohne Eintraege kann nichts eingereicht werden.', 'warn');
            else {
                upd('reports', ['status' => 'eingereicht', 'eingereicht_am' => date('Y-m-d H:i:s'),
                    'sign_azubi' => $u['display_name'] ?: $u['username']], 'id = :id AND user_id = :u', ['id' => $rid, 'u' => $uid]);
                audit('berichtsheft_eingereicht', $per);
                flash('Nachweis eingereicht.');
            }
        } elseif ($act === 'zurueckziehen') {
            upd('reports', ['status' => 'entwurf', 'eingereicht_am' => null], "id = :id AND user_id = :u AND status = 'eingereicht'", ['id' => $rid, 'u' => $uid]);
            flash('Zurueckgezogen.');
        }
        redirect(url('berichtsheft', ['periode' => $rep['periode'], 'art' => $art]));
    }

    $rep = report_or_blank($uid, $art, $per);
    $rep['nr'] = report_nr($uid, $rep['von']);
    $sum = report_summary((int)$rep['id']);
    $gesperrt = in_array($rep['status'], ['eingereicht', 'geprueft'], true);
    if (get('druck') !== '') { render_bericht_druck($u, $rep, $sum); return; }
    [$von, $bis] = [$rep['von'], $rep['bis']];
    $tage = [];
    $d = new DateTimeImmutable($von); $e = new DateTimeImmutable($bis);
    while ($d <= $e) { $tage[$d->format('Y-m-d')] = $d; $d = $d->modify('+1 day'); }

    ob_start(); ?>
    <div class="card tight noprint">
      <div class="row" style="justify-content:space-between">
        <div class="row">
          <a class="btn sm" href="<?= url('berichtsheft', ['periode' => periode_shift($per, $art, -1), 'art' => $art]) ?>">&larr;</a>
          <strong style="min-width:230px;text-align:center"><?= h(periode_label($per, $art)) ?></strong>
          <a class="btn sm" href="<?= url('berichtsheft', ['periode' => periode_shift($per, $art, 1), 'art' => $art]) ?>">&rarr;</a>
          <a class="btn sm ghost" href="<?= url('berichtsheft', ['art' => $art]) ?>">heute</a>
        </div>
        <div class="row">
          <a class="btn sm <?= $art === 'woche' ? 'pri' : '' ?>" href="<?= url('berichtsheft', ['art' => 'woche']) ?>">Wochenblatt</a>
          <a class="btn sm <?= $art === 'monat' ? 'pri' : '' ?>" href="<?= url('berichtsheft', ['art' => 'monat']) ?>">Monatsblatt</a>
          <a class="btn sm" href="<?= url('berichtsheft', ['periode' => $per, 'art' => $art, 'druck' => 1]) ?>">Drucken / PDF</a>
          <a class="btn sm" href="<?= url('berichtsheft_liste') ?>">Alle Nachweise</a>
        </div>
      </div>
    </div>

    <div class="grid g4" style="margin-bottom:1rem">
      <?= ui_kpi('Status', '<span class="tag ' . bericht_status_klasse($rep['status']) . '">' . h(bericht_status_label($rep['status'])) . '</span>',
            'Nachweis Nr. ' . (int)$rep['nr']) ?>
      <?= ui_kpi('Stunden', num($sum['stunden'], 1), count($sum['rows']) . ' Eintraege') ?>
      <?= ui_kpi('Ausbildungsjahr', (string)(int)$rep['ausbildungsjahr'], h(de_date($von) . ' - ' . de_date($bis))) ?>
      <?= ui_kpi('Kategorien', (string)count($sum['byCat']), 'zugeordnet') ?>
    </div>

    <?php if ($rep['status'] === 'abgelehnt' && $rep['pruef_notiz']): ?>
      <div class="msg err"><strong>Zurueckgewiesen:</strong>&nbsp;<?= h($rep['pruef_notiz']) ?></div>
    <?php elseif ($rep['status'] === 'geprueft'): ?>
      <div class="msg ok">Abgezeichnet am <?= h(de_date($rep['geprueft_am'])) ?>
        <?= $rep['sign_ausbilder'] ? ' von ' . h($rep['sign_ausbilder']) : '' ?>.</div>
    <?php endif; ?>

    <div class="split">
      <div>
        <div class="card">
          <div class="row" style="justify-content:space-between;margin-bottom:.5rem">
            <h2 style="margin:0">Taetigkeiten</h2>
            <?php if (!$gesperrt): ?>
            <form method="post" style="margin:0"><?= csrf_field() ?>
              <input type="hidden" name="action" value="autofill"><input type="hidden" name="periode" value="<?= h($per) ?>">
              <button class="btn sm" type="submit" title="Aus Routinen, Notizen, Blockplan und Abwesenheiten">
                &#9889; Vorschlaege uebernehmen</button></form>
            <?php endif; ?>
          </div>
          <?php if (!$sum['rows']): ?>
            <?= ui_empty('Noch nichts eingetragen', 'Tipp: "Vorschlaege uebernehmen" fuellt die Woche aus deinen Routinen und Notizen.') ?>
          <?php else: ?>
          <div class="tw"><table><thead><tr><th>Tag</th><th>Std.</th><th>Ort</th><th>Taetigkeit</th><th>Kategorie</th><th></th></tr></thead><tbody>
            <?php foreach ($tage as $ds => $dt):
              $eintraege = $sum['byDay'][$ds] ?? [];
              if (!$eintraege && (int)$dt->format('N') > 5) continue; ?>
              <?php if (!$eintraege): ?>
                <tr><td class="small" style="white-space:nowrap"><strong><?= h(de_date($ds, 'D')) ?></strong> <?= h($dt->format('d.m.')) ?></td>
                  <td colspan="5" class="small muted2">&ndash;</td></tr>
              <?php else: foreach ($eintraege as $i => $r): ?>
                <tr>
                  <td class="small" style="white-space:nowrap"><?php if ($i === 0): ?><strong><?= h(de_date($ds, 'D')) ?></strong> <?= h($dt->format('d.m.')) ?><?php endif; ?></td>
                  <td class="small"><?= $r['stunden'] > 0 ? num((float)$r['stunden'], 2) : '' ?></td>
                  <td><span class="tag <?= in_array($r['ort'], ['krank','urlaub'], true) ? 'warn' : ($r['ort'] === 'schule' ? 'info' : '') ?>"><?= h($r['ort']) ?></span></td>
                  <td><?= h($r['text']) ?><?php if ($r['quelle'] !== 'manuell'): ?> <span class="small muted2">(<?= h($r['quelle']) ?>)</span><?php endif; ?></td>
                  <td class="small"><?php if ($r['kategorie']): ?>
                      <span class="tag" style="border-color:<?= h($r['farbe']) ?>"><?= h($r['pos_no']) ?></span> <?= h($r['kategorie']) ?>
                    <?php else: ?><span class="muted2">ohne</span><?php endif; ?></td>
                  <td><?php if (!$gesperrt): ?>
                    <form method="post" style="margin:0"><?= csrf_field() ?>
                      <input type="hidden" name="action" value="delentry"><input type="hidden" name="eid" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="periode" value="<?= h($per) ?>">
                      <button class="btn sm ghost dan" type="submit" title="Entfernen">&times;</button></form>
                  <?php endif; ?></td>
                </tr>
              <?php endforeach; endif; ?>
            <?php endforeach; ?>
          </tbody></table></div>
          <?php endif; ?>

          <?php if (!$gesperrt): ?>
          <hr>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="addentry">
            <input type="hidden" name="periode" value="<?= h($per) ?>">
            <div class="fgrid">
              <div><label for="bd">Tag</label><input id="bd" name="datum" type="date" min="<?= h($von) ?>" max="<?= h($bis) ?>" value="<?= h(max($von, min($bis, today()))) ?>"></div>
              <div><label for="bs">Stunden</label><input id="bs" name="stunden" inputmode="decimal" placeholder="z.B. 1,5"></div>
              <div><label for="bo">Ort</label><select id="bo" name="ort"><?= opts_simple(['betrieb' => 'Betrieb', 'schule' => 'Berufsschule',
                'ueba' => 'UEBA / Lehrgang', 'urlaub' => 'Urlaub', 'krank' => 'Krank', 'feiertag' => 'Feiertag', 'frei' => 'Frei'], 'betrieb') ?></select></div>
              <div><label for="bk">Kategorie</label><select id="bk" name="category_id"><?= kat_options(null, '- automatisch erkennen -') ?></select></div>
              <div><label for="bl">Lernfeld</label><select id="bl" name="lf_no"><?= lf_options(null) ?></select></div>
            </div>
            <div class="f" style="margin-top:.5rem"><label for="bt">Taetigkeit</label>
              <input id="bt" name="text" required placeholder="z.B. Kaffeemaschine im Betrieb geleert und gereinigt"></div>
            <button class="btn pri" type="submit">+ Eintragen</button>
            <span class="small muted">Die Kategorie wird automatisch erkannt (z.B. &bdquo;Kaffeemaschine&ldquo; &rarr; Allgemeine Officetaetigkeiten).</span>
          </form>
          <?php endif; ?>
        </div>

        <div class="card">
          <h2>Berufsschule &amp; Sonstiges</h2>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="meta">
            <input type="hidden" name="periode" value="<?= h($per) ?>">
            <div class="f"><label for="ab">Abteilung / Einsatzort</label>
              <input id="ab" name="abteilung" value="<?= h($rep['abteilung']) ?>" <?= $gesperrt ? 'disabled' : '' ?>></div>
            <div class="f"><label for="sx">Themen im Berufsschulunterricht</label>
              <textarea id="sx" name="schule_text" <?= $gesperrt ? 'disabled' : '' ?>
                data-draft="rep<?= (int)$rep['id'] ?>s"><?= h($rep['schule_text']) ?></textarea></div>
            <div class="f"><label for="so">Sonstiges (Unterweisungen, Besonderheiten)</label>
              <textarea id="so" name="sonstiges" style="min-height:60px" <?= $gesperrt ? 'disabled' : '' ?>><?= h($rep['sonstiges']) ?></textarea></div>
            <?php if (!$gesperrt): ?><button class="btn" type="submit">Speichern</button><?php endif; ?>
          </form>
        </div>
      </div>

      <div>
        <div class="card">
          <h3>Zusammenfassung</h3>
          <?php if (!$sum['byCat']): ?><p class="small muted">Noch keine Eintraege.</p>
          <?php else: ?>
            <?= ui_hbar(array_map(fn($c) => [$c['name'], $c['stunden'], $c['farbe']], array_values($sum['byCat']))) ?>
            <hr>
            <h4>Text fuer den Nachweis</h4>
            <button class="btn sm copybtn" data-copy="btext" type="button">kopieren</button>
            <pre id="btext" style="white-space:pre-wrap;max-height:260px;overflow:auto;font-size:.78rem"><?= h(report_text((int)$rep['id'])) ?></pre>
          <?php endif; ?>
        </div>

        <div class="card">
          <h3>Abschluss</h3>
          <?php if ($rep['status'] === 'entwurf' || $rep['status'] === 'abgelehnt'): ?>
            <p class="small muted">Mit dem Einreichen bestaetigst du die Richtigkeit deiner Angaben.
            Danach kann der Ausbilder abzeichnen.</p>
            <form method="post" data-confirm="Nachweis jetzt einreichen?"><?= csrf_field() ?>
              <input type="hidden" name="action" value="einreichen"><input type="hidden" name="periode" value="<?= h($per) ?>">
              <button class="btn pri" type="submit">Nachweis einreichen</button></form>
          <?php elseif ($rep['status'] === 'eingereicht'): ?>
            <p class="small">Eingereicht am <?= h(de_date($rep['eingereicht_am'], 'd.m.Y H:i')) ?>.</p>
            <form method="post"><?= csrf_field() ?>
              <input type="hidden" name="action" value="zurueckziehen"><input type="hidden" name="periode" value="<?= h($per) ?>">
              <button class="btn sm" type="submit">Zurueckziehen</button></form>
          <?php else: ?>
            <p class="small"><span class="tag ok">abgezeichnet</span></p>
          <?php endif; ?>
          <hr>
          <div class="row">
            <a class="btn sm" href="<?= url('export', ['was' => 'bericht', 'periode' => $per, 'art' => $art]) ?>">CSV-Export</a>
            <a class="btn sm" href="<?= url('berichtsheft', ['periode' => $per, 'art' => $art, 'druck' => 1]) ?>">Druckansicht</a>
          </div>
        </div>

        <div class="card">
          <h3>Pflichtangaben</h3>
          <ul class="list small">
            <li><span><?= $sum['rows'] ? '&#10003;' : '&#9744;' ?></span><span>Taetigkeiten mindestens stichwortartig</span></li>
            <li><span><?= $rep['schule_text'] !== '' ? '&#10003;' : '&#9744;' ?></span><span>Themen der Berufsschule</span></li>
            <li><span>&#10003;</span><span>Name, Ausbildungsjahr und Berichtszeitraum (automatisch)</span></li>
            <li><span><?= $rep['status'] !== 'entwurf' ? '&#10003;' : '&#9744;' ?></span><span>Bestaetigung durch Auszubildende/-n</span></li>
            <li><span><?= $rep['status'] === 'geprueft' ? '&#10003;' : '&#9744;' ?></span><span>Abzeichnung durch Ausbilder/-in</span></li>
          </ul>
        </div>
      </div>
    </div>
    <?php
    render_page('Berichtsheft', ob_get_clean());
}

function render_bericht_druck(array $u, array $rep, array $sum): void {
    $art = $rep['art'];
    $rep['nr'] = report_nr((int)$rep['user_id'], $rep['von']);
    ob_start(); ?>
    <div class="card">
      <div class="row noprint" style="justify-content:flex-end;margin-bottom:.6rem">
        <a class="btn sm" href="<?= url('berichtsheft', ['periode' => $rep['periode'], 'art' => $art]) ?>">&larr; zurueck</a>
      </div>
      <h1 style="margin-bottom:.2rem">Ausbildungsnachweis Nr. <?= (int)$rep['nr'] ?></h1>
      <p class="muted"><?= $art === 'monat' ? 'Monatsnachweis' : 'Wochennachweis' ?> &middot;
        <?= h(periode_label($rep['periode'], $art)) ?></p>
      <table style="margin-bottom:1rem">
        <tr><th style="width:22%">Auszubildende/-r</th><td><?= h($u['display_name'] ?: $u['username']) ?></td>
            <th style="width:18%">Ausbildungsjahr</th><td><?= (int)$rep['ausbildungsjahr'] ?></td></tr>
        <tr><th>Ausbildungsberuf</th><td><?= h($u['beruf']) ?></td>
            <th>Berichtszeitraum</th><td><?= h(de_date($rep['von'])) ?> &ndash; <?= h(de_date($rep['bis'])) ?></td></tr>
        <tr><th>Ausbildungsbetrieb</th><td><?= h($u['betrieb']) ?></td>
            <th>Abteilung</th><td><?= h($rep['abteilung']) ?></td></tr>
      </table>
      <?php
        $betrieb = array_filter($sum['rows'], fn($r) => $r['ort'] !== 'schule');
        $schule  = array_filter($sum['rows'], fn($r) => $r['ort'] === 'schule');
        $stdBetrieb = array_sum(array_map(fn($r) => (float)$r['stunden'], $betrieb));
        $stdSchule  = array_sum(array_map(fn($r) => (float)$r['stunden'], $schule));
      ?>
      <h3>Betriebliche Taetigkeiten</h3>
      <table><thead><tr><th style="width:14%">Tag</th><th style="width:8%">Std.</th><th>Taetigkeit</th><th style="width:28%">Ausbildungsinhalt</th></tr></thead><tbody>
        <?php foreach ($betrieb as $r): ?>
          <tr><td><?= h(de_date($r['datum'], 'D d.m.')) ?></td>
            <td><?= $r['stunden'] > 0 ? num((float)$r['stunden'], 2) : '' ?></td>
            <td><?= h($r['text']) ?></td>
            <td class="small"><?= h(($r['pos_no'] ? '[' . $r['pos_no'] . '] ' : '') . ($r['kategorie'] ?: '')) ?></td></tr>
        <?php endforeach; ?>
        <tr><th>Summe Betrieb</th><th><?= num($stdBetrieb, 2) ?></th>
            <th colspan="2" style="font-weight:400">zzgl. <?= num($stdSchule, 2) ?> h Berufsschule
              &middot; gesamt <?= num($sum['stunden'], 2) ?> h</th></tr>
      </tbody></table>
      <h3 style="margin-top:1rem">Berufsschule</h3>
      <?php if ($rep['schule_text'] !== ''): ?><p><?= nl2br(h($rep['schule_text'])) ?></p><?php endif; ?>
      <?php if ($schule): ?>
        <table><tbody><?php foreach ($schule as $r): ?>
          <tr><td style="width:14%"><?= h(de_date($r['datum'], 'D d.m.')) ?></td><td><?= h($r['text']) ?></td></tr>
        <?php endforeach; ?></tbody></table>
      <?php endif; ?>
      <?php if ($rep['sonstiges'] !== ''): ?>
        <h3 style="margin-top:1rem">Sonstiges</h3><p><?= nl2br(h($rep['sonstiges'])) ?></p>
      <?php endif; ?>
      <table style="margin-top:2rem;border-top:1px solid #999">
        <tr>
          <td style="padding-top:2.2rem;border:0">______________________________<br>
            <span class="small">Datum, Unterschrift Auszubildende/-r<br>
            <?= $rep['status'] !== 'entwurf' ? 'elektronisch bestaetigt am ' . h(de_date($rep['eingereicht_am'], 'd.m.Y H:i')) : '' ?></span></td>
          <td style="padding-top:2.2rem;border:0">______________________________<br>
            <span class="small">Datum, Unterschrift Ausbilder/-in<br>
            <?= $rep['status'] === 'geprueft' ? 'elektronisch abgezeichnet am ' . h(de_date($rep['geprueft_am'], 'd.m.Y H:i')) . ' von ' . h($rep['sign_ausbilder']) : '' ?></span></td>
        </tr>
      </table>
    </div>
    <?php
    render_page('Ausbildungsnachweis', ob_get_clean());
}

function page_berichtsheft_liste(): void {
    $u   = require_login();
    $uid = (int)$u['id'];
    $rows = all("SELECT r.*, (SELECT COUNT(*) FROM report_entries e WHERE e.report_id = r.id) AS anz,
                 (SELECT COALESCE(SUM(stunden),0) FROM report_entries e WHERE e.report_id = r.id) AS std
                 FROM reports r WHERE r.user_id = ?
                   AND (r.status <> 'entwurf'
                        OR EXISTS (SELECT 1 FROM report_entries e WHERE e.report_id = r.id)
                        OR r.schule_text <> '' OR r.sonstiges <> '')
                 ORDER BY r.von DESC", [$uid]);
    foreach ($rows as $i => $r) $rows[$i]['nr'] = report_nr($uid, $r['von']);
    $ges = count($rows);
    $ein = count(array_filter($rows, fn($r) => $r['status'] !== 'entwurf'));
    ob_start(); ?>
    <div class="grid g4" style="margin-bottom:1rem">
      <?= ui_kpi('Nachweise', (string)$ges, 'angelegt') ?>
      <?= ui_kpi('Eingereicht', (string)$ein, $ges ? num($ein / $ges * 100, 0) . '&thinsp;% der angelegten' : '', $ges ? $ein / $ges * 100 : null) ?>
      <?= ui_kpi('Abgezeichnet', (string)count(array_filter($rows, fn($r) => $r['status'] === 'geprueft')), '') ?>
      <?= ui_kpi('Stunden gesamt', num(array_sum(array_map(fn($r) => (float)$r['std'], $rows)), 1), 'dokumentiert') ?>
    </div>
    <div class="card">
      <h2>Alle Ausbildungsnachweise</h2>
      <?php if (!$rows): ?><?= ui_empty('Noch keine Nachweise') ?><?php else: ?>
      <div class="tw"><table><thead><tr><th>Nr.</th><th>Zeitraum</th><th>Jahr</th><th>Eintraege</th><th>Stunden</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td><?= (int)$r['nr'] ?></td>
            <td><a href="<?= url('berichtsheft', ['periode' => $r['periode'], 'art' => $r['art']]) ?>"><?= h(periode_label($r['periode'], $r['art'])) ?></a></td>
            <td><?= (int)$r['ausbildungsjahr'] ?></td><td><?= (int)$r['anz'] ?></td><td><?= num((float)$r['std'], 1) ?></td>
            <td><span class="tag <?= bericht_status_klasse($r['status']) ?>"><?= h(bericht_status_label($r['status'])) ?></span></td>
            <td><a class="btn sm ghost" href="<?= url('berichtsheft', ['periode' => $r['periode'], 'art' => $r['art'], 'druck' => 1]) ?>">Druck</a></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>
    <?php
    render_page('Alle Nachweise', ob_get_clean());
}

// ===========================================================================
// 21. BETRIEB & ROUTINEN
// ===========================================================================

function page_betrieb(): void {
    $u   = require_login();
    $uid = (int)$u['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $act = post('action');
        if ($act === 'log') {
            $rid = (int)post('routine_id', '0');
            $r   = one("SELECT * FROM routines WHERE id = ? AND aktiv = 1", [$rid]);
            if ($r) {
                $min = (int)post('minuten', (string)(int)$r['standard_min']);
                ins('routine_logs', ['routine_id' => $rid, 'user_id' => $uid,
                    'datum' => preg_match('/^\d{4}-\d{2}-\d{2}$/', post('datum')) ? post('datum') : today(),
                    'zeit' => post('zeit') ?: date('H:i'), 'minuten' => max(0, $min), 'notiz' => post('notiz')]);
                flash('"' . $r['name'] . '" protokolliert.');
            }
        } elseif ($act === 'unlog') {
            del('routine_logs', 'id = ? AND user_id = ?', [(int)post('lid', '0'), $uid]);
            flash('Eintrag entfernt.');
        } elseif ($act === 'save') {
            $id   = (int)post('id', '0');
            $data = [
                'name' => mb_substr(post('name'), 0, 120), 'beschreibung' => post('beschreibung'),
                'intervall' => in_array(post('intervall'), ['taeglich','woechentlich','monatlich','bedarf'], true) ? post('intervall') : 'bedarf',
                'category_id' => int_or_null(postn('category_id')),
                'standard_min' => max(0, (int)post('standard_min', '10')),
                'scope' => in_array(post('scope'), ['betrieb','schule','privat'], true) ? post('scope') : 'betrieb',
                'geteilt' => post('geteilt') ? 1 : 0, 'berichtsheft' => post('berichtsheft') ? 1 : 0,
                'aktiv' => post('aktiv') === '0' ? 0 : 1,
            ];
            if ($data['name'] === '') flash('Name fehlt.', 'err');
            elseif ($id) {
                $r = one("SELECT * FROM routines WHERE id = ?", [$id]);
                if ($r && ($r['owner_id'] === null ? is_admin() : (int)$r['owner_id'] === $uid || is_admin())) {
                    upd('routines', $data, 'id = :id', ['id' => $id]); flash('Routine gespeichert.');
                } else flash('Kein Zugriff.', 'err');
            } else { $data['owner_id'] = $data['geteilt'] && is_staff() ? null : $uid;
                     ins('routines', $data); flash('Routine angelegt.'); }
        } elseif ($act === 'del') {
            $id = (int)post('id', '0');
            $r  = one("SELECT * FROM routines WHERE id = ?", [$id]);
            if ($r && ($r['owner_id'] === null ? is_admin() : (int)$r['owner_id'] === $uid || is_admin())) {
                del('routines', 'id = ?', [$id]); flash('Routine geloescht.');
            }
        }
        redirect(post('back') ?: url('betrieb'));
    }
    $rout = all("SELECT r.*, c.name AS kategorie,
                 (SELECT MAX(datum) FROM routine_logs l WHERE l.routine_id = r.id AND l.user_id = ?) AS letzte,
                 (SELECT COUNT(*) FROM routine_logs l WHERE l.routine_id = r.id AND l.user_id = ?) AS anz
                 FROM routines r LEFT JOIN categories c ON c.id = r.category_id
                 WHERE (r.geteilt = 1 OR r.owner_id = ?) ORDER BY r.aktiv DESC, r.sort, r.name", [$uid, $uid, $uid]);
    $logs = all("SELECT l.*, r.name, u.display_name FROM routine_logs l
                 JOIN routines r ON r.id = l.routine_id JOIN users u ON u.id = l.user_id
                 WHERE l.datum >= date('now','localtime','-30 day')
                 ORDER BY l.datum DESC, l.id DESC LIMIT 120");
    $edit = get('id') !== '' ? one("SELECT * FROM routines WHERE id = ?", [(int)get('id')]) : (get('neu') !== '' ? ['id' => 0] : null);
    $meine = array_filter($logs, fn($l) => (int)$l['user_id'] === $uid);
    $minsMonat = array_sum(array_map(fn($l) => (int)$l['minuten'], $meine));

    ob_start(); ?>
    <?= quickadd_form($u) ?>
    <div class="grid g4" style="margin-bottom:1rem">
      <?= ui_kpi('Routinen', (string)count(array_filter($rout, fn($r) => (int)$r['aktiv'] === 1)), 'aktiv') ?>
      <?= ui_kpi('Heute erledigt', (string)count(array_filter($meine, fn($l) => $l['datum'] === today())), '') ?>
      <?= ui_kpi('Letzte 30 Tage', (string)count($meine), 'Protokolle von dir') ?>
      <?= ui_kpi('Zeitaufwand', num($minsMonat / 60, 1) . ' h', 'letzte 30 Tage') ?>
    </div>

    <div class="split">
      <div>
        <div class="card">
          <div class="row" style="justify-content:space-between;margin-bottom:.5rem">
            <h2 style="margin:0">Wiederkehrende Aufgaben</h2>
            <a class="btn pri sm" href="<?= url('betrieb', ['neu' => 1]) ?>">+ Routine</a>
          </div>
          <div class="tw"><table><thead><tr><th></th><th>Aufgabe</th><th>Rhythmus</th><th>Zuletzt</th><th>Kategorie</th><th></th></tr></thead><tbody>
            <?php foreach ($rout as $r):
              $faellig = match ($r['intervall']) {
                  'taeglich'     => $r['letzte'] !== today(),
                  'woechentlich' => !$r['letzte'] || $r['letzte'] < date('Y-m-d', strtotime('monday this week')),
                  'monatlich'    => !$r['letzte'] || substr((string)$r['letzte'], 0, 7) !== date('Y-m'),
                  default        => false,
              }; ?>
              <tr style="<?= (int)$r['aktiv'] ? '' : 'opacity:.45' ?>">
                <td style="width:1%">
                  <form method="post" style="margin:0"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="log"><input type="hidden" name="routine_id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
                    <button class="btn sm <?= $faellig ? 'pri' : 'ghost' ?>" type="submit" title="Jetzt erledigt">&#10003;</button></form>
                </td>
                <td><strong><?= h($r['name']) ?></strong>
                  <?php if ($r['beschreibung']): ?><div class="small muted"><?= h($r['beschreibung']) ?></div><?php endif; ?></td>
                <td><span class="tag <?= $faellig ? 'warn' : '' ?>"><?= h($r['intervall']) ?></span></td>
                <td class="small"><?= $r['letzte'] ? h(de_date($r['letzte'])) : '<span class="muted2">nie</span>' ?>
                  <div class="small muted2"><?= (int)$r['anz'] ?>x</div></td>
                <td class="small"><?= h($r['kategorie'] ?: '-') ?><?= (int)$r['berichtsheft'] ? ' <span class="tag ok">BH</span>' : '' ?></td>
                <td><a class="btn sm ghost" href="<?= url('betrieb', ['id' => $r['id']]) ?>">&#9998;</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <p class="small muted" style="margin-top:.5rem">&bdquo;BH&ldquo; = fliesst automatisch als Vorschlag ins Berichtsheft.</p>
        </div>

        <div class="card">
          <h2>Protokoll (30 Tage)</h2>
          <?php if (!$logs): ?><?= ui_empty('Noch nichts protokolliert') ?><?php else: ?>
          <div class="tw scroller"><table><thead><tr><th>Datum</th><th>Zeit</th><th>Aufgabe</th><th>Person</th><th>Dauer</th><th></th></tr></thead><tbody>
            <?php foreach ($logs as $l): ?>
              <tr><td class="small" style="white-space:nowrap"><?= h(de_date($l['datum'])) ?></td>
                <td class="small"><?= h($l['zeit']) ?></td>
                <td><?= h($l['name']) ?><?php if ($l['notiz']): ?><div class="small muted"><?= h($l['notiz']) ?></div><?php endif; ?></td>
                <td class="small"><?= h($l['display_name']) ?></td>
                <td class="small"><?= (int)$l['minuten'] ?> min</td>
                <td><?php if ((int)$l['user_id'] === $uid): ?>
                  <form method="post" style="margin:0"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="unlog"><input type="hidden" name="lid" value="<?= (int)$l['id'] ?>">
                    <button class="btn sm ghost dan" type="submit">&times;</button></form><?php endif; ?></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <?php endif; ?>
        </div>
      </div>

      <div>
        <?php if ($edit !== null): $isNew = empty($edit['id']); ?>
        <div class="card">
          <h3><?= $isNew ? 'Neue Routine' : 'Routine bearbeiten' ?></h3>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="f"><label for="rn">Name</label><input id="rn" name="name" required value="<?= h($edit['name'] ?? '') ?>"
              placeholder="z.B. Kaffeemaschine reinigen"></div>
            <div class="f"><label for="rb">Beschreibung</label><input id="rb" name="beschreibung" value="<?= h($edit['beschreibung'] ?? '') ?>"></div>
            <div class="fgrid">
              <div class="f"><label for="ri">Rhythmus</label><select id="ri" name="intervall"><?= opts_simple(
                ['taeglich' => 'taeglich', 'woechentlich' => 'woechentlich', 'monatlich' => 'monatlich', 'bedarf' => 'bei Bedarf'],
                $edit['intervall'] ?? 'taeglich') ?></select></div>
              <div class="f"><label for="rm">Dauer (Minuten)</label><input id="rm" name="standard_min" type="number" min="0" value="<?= h($edit['standard_min'] ?? 10) ?>"></div>
            </div>
            <div class="f"><label for="rk">Berichtsheft-Kategorie</label><select id="rk" name="category_id"><?= kat_options($edit['category_id'] ?? null) ?></select></div>
            <div class="fgrid">
              <div class="f"><label for="rs">Bereich</label><select id="rs" name="scope"><?= opts_simple(['betrieb' => 'Betrieb', 'schule' => 'Schule', 'privat' => 'Privat'], $edit['scope'] ?? 'betrieb') ?></select></div>
              <div class="f"><label for="rg">Sichtbar</label><select id="rg" name="geteilt"><?= opts_simple([1 => 'Team / alle', 0 => 'nur ich'], $edit['geteilt'] ?? 1) ?></select></div>
            </div>
            <div class="fgrid">
              <div class="f"><label for="rbh">Ins Berichtsheft</label><select id="rbh" name="berichtsheft"><?= opts_simple([1 => 'ja', 0 => 'nein'], $edit['berichtsheft'] ?? 1) ?></select></div>
              <div class="f"><label for="ra">Aktiv</label><select id="ra" name="aktiv"><?= opts_simple([1 => 'ja', 0 => 'nein'], $edit['aktiv'] ?? 1) ?></select></div>
            </div>
            <div class="row"><button class="btn pri" type="submit">Speichern</button>
              <a class="btn ghost" href="<?= url('betrieb') ?>">Abbrechen</a></div>
          </form>
          <?php if (!$isNew): ?>
            <hr><form method="post" data-confirm="Routine samt Protokoll loeschen?"><?= csrf_field() ?>
              <input type="hidden" name="action" value="del"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
              <button class="btn dan sm" type="submit">Loeschen</button></form>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="card">
          <h3>Erledigt mit Zeitpunkt</h3>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="log">
            <div class="f"><label for="lr">Routine</label>
              <select id="lr" name="routine_id" required><?= opts(array_filter($rout, fn($r) => (int)$r['aktiv'] === 1), null, 'id', 'name', '- waehlen -') ?></select></div>
            <div class="fgrid">
              <div class="f"><label for="ld">Datum</label><input id="ld" name="datum" type="date" value="<?= h(today()) ?>"></div>
              <div class="f"><label for="lz">Uhrzeit</label><input id="lz" name="zeit" type="time" value="<?= h(date('H:i')) ?>"></div>
              <div class="f"><label for="lm">Minuten</label><input id="lm" name="minuten" type="number" min="0" value="10"></div>
            </div>
            <div class="f"><label for="ln">Notiz</label><input id="ln" name="notiz" placeholder="z.B. inkl. Entkalkung"></div>
            <button class="btn pri" type="submit">Protokollieren</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    render_page('Betrieb & Routinen', ob_get_clean());
}

// ===========================================================================
// 22. ABWESENHEITEN
// ===========================================================================

function page_abwesenheit(): void {
    $u   = require_login();
    $uid = (int)$u['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $act = post('action'); $id = (int)post('id', '0');
        if ($act === 'save') {
            $data = [
                'von' => preg_match('/^\d{4}-\d{2}-\d{2}$/', post('von')) ? post('von') : today(),
                'bis' => preg_match('/^\d{4}-\d{2}-\d{2}$/', post('bis')) ? post('bis') : post('von'),
                'art' => in_array(post('art'), ['krank','urlaub','frei','beurlaubt','dienstreise'], true) ? post('art') : 'krank',
                'grund' => mb_substr(post('grund'), 0, 200),
                'entschuldigt' => post('entschuldigt') ? 1 : 0,
                'schule' => post('schule') ? 1 : 0,
            ];
            if ($data['bis'] < $data['von']) $data['bis'] = $data['von'];
            if ($id) { upd('absences', $data, 'id = :id AND user_id = :u', ['id' => $id, 'u' => $uid]); flash('Gespeichert.'); }
            else { $data['user_id'] = $uid; ins('absences', $data); flash('Abwesenheit erfasst.'); }
        } elseif ($act === 'del' && $id) { del('absences', 'id = ? AND user_id = ?', [$id, $uid]); flash('Geloescht.'); }
        redirect(url('abwesenheit'));
    }
    $rows = all("SELECT * FROM absences WHERE user_id = ? ORDER BY von DESC", [$uid]);
    $edit = get('id') !== '' ? one("SELECT * FROM absences WHERE id = ? AND user_id = ?", [(int)get('id'), $uid]) : null;
    $tage = function (array $r): int {
        $d = new DateTimeImmutable($r['von']); $e = new DateTimeImmutable($r['bis']); $n = 0;
        while ($d <= $e) { if ((int)$d->format('N') <= 5) $n++; $d = $d->modify('+1 day'); }
        return $n;
    };
    $jahr = date('Y');
    $imJahr = array_filter($rows, fn($r) => substr($r['von'], 0, 4) === $jahr);
    $krank = array_sum(array_map($tage, array_filter($imJahr, fn($r) => $r['art'] === 'krank')));
    $urlaub = array_sum(array_map($tage, array_filter($imJahr, fn($r) => $r['art'] === 'urlaub')));
    $offen = array_filter($rows, fn($r) => !(int)$r['entschuldigt'] && (int)$r['schule']);

    ob_start(); ?>
    <div class="grid g4" style="margin-bottom:1rem">
      <?= ui_kpi('Krankheitstage ' . $jahr, (string)$krank, 'Arbeitstage') ?>
      <?= ui_kpi('Urlaubstage ' . $jahr, (string)$urlaub, 'genommen') ?>
      <?= ui_kpi('Eintraege', (string)count($rows), 'insgesamt') ?>
      <?= ui_kpi('Ohne Entschuldigung', (string)count($offen), 'Berufsschule', count($offen) ? 100 : 0, 'var(--err)') ?>
    </div>
    <div class="split">
      <div class="card">
        <h2>Abwesenheiten</h2>
        <?php if (!$rows): ?><?= ui_empty('Keine Abwesenheiten erfasst') ?><?php else: ?>
        <div class="tw"><table><thead><tr><th>Zeitraum</th><th>Tage</th><th>Art</th><th>Grund</th><th>Schule</th><th>Entschuldigt</th><th></th></tr></thead><tbody>
          <?php foreach ($rows as $r): ?>
            <tr><td style="white-space:nowrap"><?= h(de_date($r['von'])) ?><?= $r['bis'] !== $r['von'] ? ' &ndash; ' . h(de_date($r['bis'])) : '' ?></td>
              <td><?= $tage($r) ?></td>
              <td><span class="tag <?= $r['art'] === 'krank' ? 'err' : ($r['art'] === 'urlaub' ? 'ok' : '') ?>"><?= h($r['art']) ?></span></td>
              <td class="small"><?= h($r['grund']) ?></td>
              <td><?= (int)$r['schule'] ? 'ja' : '' ?></td>
              <td><?= (int)$r['entschuldigt'] ? '<span class="tag ok">ja</span>' : ((int)$r['schule'] ? '<span class="tag err">offen</span>' : '') ?></td>
              <td><a class="btn sm ghost" href="<?= url('abwesenheit', ['id' => $r['id']]) ?>">&#9998;</a></td></tr>
          <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
      </div>
      <div class="card">
        <h3><?= $edit ? 'Bearbeiten' : 'Neue Abwesenheit' ?></h3>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
          <div class="fgrid">
            <div class="f"><label for="av">Von</label><input id="av" name="von" type="date" required value="<?= h($edit['von'] ?? today()) ?>"></div>
            <div class="f"><label for="ab2">Bis</label><input id="ab2" name="bis" type="date" value="<?= h($edit['bis'] ?? today()) ?>"></div>
          </div>
          <div class="f"><label for="aa">Art</label><select id="aa" name="art"><?= opts_simple(
            ['krank' => 'Krank', 'urlaub' => 'Urlaub', 'frei' => 'Frei / Gleittag', 'beurlaubt' => 'Beurlaubt', 'dienstreise' => 'Dienstreise'],
            $edit['art'] ?? 'krank') ?></select></div>
          <div class="f"><label for="ag">Grund / Notiz</label><input id="ag" name="grund" value="<?= h($edit['grund'] ?? '') ?>"></div>
          <div class="row">
            <label style="display:flex;gap:.4rem;align-items:center;font-weight:500">
              <input type="checkbox" name="schule" value="1" <?= !empty($edit['schule']) ? 'checked' : '' ?>> betrifft Berufsschule</label>
            <label style="display:flex;gap:.4rem;align-items:center;font-weight:500">
              <input type="checkbox" name="entschuldigt" value="1" <?= !empty($edit['entschuldigt']) ? 'checked' : '' ?>> entschuldigt</label>
          </div>
          <div class="row" style="margin-top:.6rem"><button class="btn pri" type="submit">Speichern</button>
            <?php if ($edit): ?><a class="btn ghost" href="<?= url('abwesenheit') ?>">Neu</a><?php endif; ?></div>
        </form>
        <?php if ($edit): ?>
          <hr><form method="post" data-confirm="Eintrag loeschen?"><?= csrf_field() ?>
            <input type="hidden" name="action" value="del"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
            <button class="btn dan sm" type="submit">Loeschen</button></form>
        <?php endif; ?>
        <hr>
        <p class="small muted">Abwesenheiten erscheinen automatisch als Eintrag im
        Berichtsheft der betroffenen Woche.</p>
      </div>
    </div>
    <?php
    render_page('Abwesenheiten', ob_get_clean());
}

// ===========================================================================
// 23. WOCHE, STUNDENPLAN & BLOCKPLAN
// ===========================================================================

function page_woche(): void {
    $u    = require_login();
    $uid  = (int)$u['id'];
    $cid  = (int)($u['class_id'] ?: 0);
    $cls  = $cid ? one("SELECT * FROM classes WHERE id = ?", [$cid]) : null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (!is_staff()) { flash('Nur Lehrkraft/Ausbilder/Administration.', 'err'); redirect(url('woche')); }
        $act = post('action');
        if ($act === 'tt') {
            $ccid = (int)post('class_id', (string)$cid);
            for ($wd = 1; $wd <= 5; $wd++) {
                for ($sl = 1; $sl <= 10; $sl++) {
                    $key = "c{$wd}_{$sl}";
                    $sid = int_or_null(postn($key));
                    $raum = post("r{$wd}_{$sl}");
                    if ($sid === null && $raum === '') { del('timetable', 'class_id = ? AND wochentag = ? AND stunde = ?', [$ccid, $wd, $sl]); continue; }
                    q("INSERT INTO timetable (class_id,wochentag,stunde,subject_id,raum)
                       VALUES (:c,:w,:s,:su,:r)
                       ON CONFLICT(class_id,wochentag,stunde) DO UPDATE SET subject_id = :su2, raum = :r2",
                      ['c' => $ccid, 'w' => $wd, 's' => $sl, 'su' => $sid, 'r' => $raum, 'su2' => $sid, 'r2' => $raum]);
                }
            }
            flash('Stundenplan gespeichert.');
        } elseif ($act === 'block') {
            ins('blockweeks', [
                'zeitgruppe' => (int)post('zeitgruppe', '1'),
                'class_id'   => int_or_null(postn('class_id')),
                'von' => post('von'), 'bis' => post('bis') ?: post('von'),
                'art' => in_array(post('art'), ['schule','betrieb','ferien','ueba','pruefung'], true) ? post('art') : 'schule',
                'label' => mb_substr(post('label'), 0, 80),
            ]);
            flash('Blockzeitraum eingetragen.');
        } elseif ($act === 'blockdel') {
            del('blockweeks', 'id = ?', [(int)post('id', '0')]); flash('Geloescht.');
        }
        redirect(url('woche'));
    }

    $wRef  = get('w') ?: date('o-\WW');
    if (!periode_gueltig($wRef, 'woche')) $wRef = date('o-\WW');
    [$mo, $so] = periode_range($wRef, 'woche');
    $prev = periode_shift($wRef, 'woche', -1);
    $next = periode_shift($wRef, 'woche', 1);
    $tt = [];
    foreach (all("SELECT t.*, s.name AS fach, s.color, s.short FROM timetable t
                  LEFT JOIN subjects s ON s.id = t.subject_id WHERE t.class_id = ?", [$cid]) as $r) {
        $tt[(int)$r['wochentag']][(int)$r['stunde']] = $r;
    }
    $ev = all("SELECT e.*, s.name AS fach, s.color FROM events e LEFT JOIN subjects s ON s.id = e.subject_id
               WHERE ((e.visibility='class' AND e.class_id = ?) OR e.user_id = ?) AND e.datum BETWEEN ? AND ?
               ORDER BY e.datum, e.zeit_von", [$cid, $uid, $mo, $so]);
    $tasks = all("SELECT * FROM tasks WHERE user_id = ? AND faellig BETWEEN ? AND ? ORDER BY faellig", [$uid, $mo, $so]);
    $blocks = all("SELECT b.*, c.name AS klasse FROM blockweeks b LEFT JOIN classes c ON c.id = b.class_id
                   WHERE b.bis >= date('now','localtime','-30 day')
                     AND (b.class_id = ? OR (b.class_id IS NULL AND b.zeitgruppe = ?))
                   ORDER BY b.von LIMIT 40", [$cid, (int)($cls['zeitgruppe'] ?? 0)]);
    $rep = one("SELECT * FROM reports WHERE user_id = ? AND art = 'woche' AND periode = ?", [$uid, $wRef]);
    $bearbeiten = is_staff() && get('tt') !== '';

    ob_start(); ?>
    <div class="card tight noprint">
      <div class="row" style="justify-content:space-between">
        <div class="row">
          <a class="btn sm" href="<?= url('woche', ['w' => $prev]) ?>">&larr;</a>
          <strong style="min-width:250px;text-align:center"><?= h(periode_label($wRef, 'woche')) ?></strong>
          <a class="btn sm" href="<?= url('woche', ['w' => $next]) ?>">&rarr;</a>
          <a class="btn sm ghost" href="<?= url('woche') ?>">aktuelle Woche</a>
        </div>
        <div class="row">
          <?php if ($cls): ?><span class="tag acc">Klasse <?= h($cls['name']) ?> &middot; Zeitgruppe <?= (int)$cls['zeitgruppe'] ?></span><?php endif; ?>
          <?php if (is_staff()): ?><a class="btn sm" href="<?= url('woche', ['tt' => 1]) ?>">Stundenplan bearbeiten</a><?php endif; ?>
        </div>
      </div>
    </div>

    <?php if (!$cid): ?>
      <div class="msg warn">Dir ist noch keine Klasse zugeordnet - Stundenplan und Blockplan bleiben leer.
        <a href="<?= url('profil') ?>">Profil oeffnen</a></div>
    <?php endif; ?>

    <div class="split">
      <div>
        <?php if ($bearbeiten): ?>
        <div class="card">
          <h2>Stundenplan bearbeiten</h2>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="tt">
            <div class="f"><label for="cc">Klasse</label>
              <select id="cc" name="class_id"><?= opts(all("SELECT id, name FROM classes WHERE archived = 0 ORDER BY name"), $cid) ?></select></div>
            <div class="tw"><table><thead><tr><th>Std.</th>
              <?php for ($wd = 1; $wd <= 5; $wd++): ?><th><?= h(wd_name($wd)) ?></th><?php endfor; ?></tr></thead><tbody>
              <?php for ($sl = 1; $sl <= 10; $sl++): ?>
                <tr><td><?= $sl ?>.</td>
                  <?php for ($wd = 1; $wd <= 5; $wd++): $c = $tt[$wd][$sl] ?? null; ?>
                    <td><select name="c<?= $wd ?>_<?= $sl ?>" style="font-size:.8rem"><?= fach_options($cid, $c['subject_id'] ?? null) ?></select>
                      <input name="r<?= $wd ?>_<?= $sl ?>" value="<?= h($c['raum'] ?? '') ?>" placeholder="Raum" style="font-size:.75rem;margin-top:2px"></td>
                  <?php endfor; ?></tr>
              <?php endfor; ?>
            </tbody></table></div>
            <div class="row" style="margin-top:.6rem"><button class="btn pri" type="submit">Speichern</button>
              <a class="btn ghost" href="<?= url('woche') ?>">Fertig</a></div>
          </form>
        </div>
        <?php else: ?>
        <div class="card">
          <h2>Stundenplan</h2>
          <?php if (!$tt): ?><?= ui_empty('Kein Stundenplan hinterlegt', is_staff() ? 'Oben rechts "Stundenplan bearbeiten".' : 'Deine Lehrkraft kann ihn eintragen.') ?>
          <?php else: ?>
          <div class="tw"><div class="tt">
            <div></div><?php for ($wd = 1; $wd <= 5; $wd++): ?><div class="hd"><?= h(mb_substr(wd_name($wd), 0, 2)) ?></div><?php endfor; ?>
            <?php for ($sl = 1; $sl <= 10; $sl++):
              $leer = true; for ($wd = 1; $wd <= 5; $wd++) if (isset($tt[$wd][$sl])) $leer = false;
              if ($leer) continue; ?>
              <div class="sl"><?= $sl ?>.</div>
              <?php for ($wd = 1; $wd <= 5; $wd++): $c = $tt[$wd][$sl] ?? null; ?>
                <div class="c" style="border-left-color:<?= h($c['color'] ?? 'transparent') ?>">
                  <?php if ($c): ?><strong style="font-size:.78rem"><?= h($c['short'] ?: mb_substr((string)$c['fach'], 0, 14)) ?></strong>
                    <?php if ($c['raum']): ?><div class="small muted2"><?= h($c['raum']) ?></div><?php endif; ?><?php endif; ?>
                </div>
              <?php endfor; ?>
            <?php endfor; ?>
          </div></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="card">
          <h2>Diese Woche</h2>
          <?php
          $d = new DateTimeImmutable($mo);
          for ($i = 0; $i < 7; $i++):
            $ds = $d->format('Y-m-d');
            $dayEv = array_filter($ev, fn($e) => $e['datum'] === $ds);
            $dayTk = array_filter($tasks, fn($t) => $t['faellig'] === $ds);
            if ($i >= 5 && !$dayEv && !$dayTk) { $d = $d->modify('+1 day'); continue; } ?>
            <div style="display:flex;gap:.8rem;padding:.45rem 0;border-bottom:1px solid var(--line)">
              <div style="width:88px;flex:none">
                <strong style="<?= $ds === today() ? 'color:var(--acc)' : '' ?>"><?= h(wd_name((int)$d->format('N'))) ?></strong>
                <div class="small muted"><?= h($d->format('d.m.')) ?></div>
              </div>
              <div style="flex:1">
                <?php if (!$dayEv && !$dayTk): ?><span class="small muted2">&ndash;</span><?php endif; ?>
                <?php foreach ($dayEv as $e): ?>
                  <div><span class="tag <?= in_array($e['typ'], ['probe','pruefung'], true) ? 'err' : '' ?>"><?= h(typ_label($e['typ'])) ?></span>
                    <a href="<?= url('termine', ['id' => $e['id']]) ?>"><?= h($e['titel']) ?></a>
                    <?php if ($e['zeit_von']): ?><span class="small muted"><?= h($e['zeit_von']) ?></span><?php endif; ?></div>
                <?php endforeach; ?>
                <?php foreach ($dayTk as $t): ?>
                  <div class="small"><span class="tag warn">Aufgabe</span> <?= h($t['titel']) ?>
                    <?= $t['status'] === 'erledigt' ? '<span class="tag ok">erledigt</span>' : '' ?></div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php $d = $d->modify('+1 day'); endfor; ?>
        </div>
      </div>

      <div>
        <div class="card">
          <h3>Berichtsheft KW <?= h(explode('-W', $wRef)[1] ?? '') ?></h3>
          <div class="row"><span class="tag <?= $rep ? bericht_status_klasse($rep['status']) : 'warn' ?>">
            <?= h($rep ? bericht_status_label($rep['status']) : 'offen') ?></span></div>
          <a class="btn pri sm" style="margin-top:.5rem" href="<?= url('berichtsheft', ['periode' => $wRef, 'art' => 'woche']) ?>">Oeffnen</a>
        </div>

        <div class="card">
          <h3>Blockplan</h3>
          <?php if (!$blocks): ?><p class="small muted">Keine Blockzeitraeume hinterlegt.</p><?php else: ?>
          <ul class="list">
            <?php foreach ($blocks as $b):
              $aktiv = today() >= $b['von'] && today() <= $b['bis']; ?>
              <li><span class="dot" style="background:<?= $b['art'] === 'schule' ? 'var(--acc)' : ($b['art'] === 'ferien' ? 'var(--ok)' : 'var(--warn)') ?>;margin-top:.45rem"></span>
                <div style="flex:1"><strong><?= h(ucfirst($b['art'])) ?></strong><?= $b['label'] ? ' &middot; ' . h($b['label']) : '' ?>
                  <?php if ($aktiv): ?><span class="tag acc">jetzt</span><?php endif; ?>
                  <div class="small muted"><?= h(de_date($b['von'])) ?> &ndash; <?= h(de_date($b['bis'])) ?></div></div>
                <?php if (is_staff()): ?>
                <form method="post" style="margin:0"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="blockdel"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                  <button class="btn sm ghost dan" type="submit">&times;</button></form><?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
          <?php if (is_staff()): ?>
            <hr>
            <form method="post">
              <?= csrf_field() ?><input type="hidden" name="action" value="block">
              <div class="fgrid">
                <div class="f"><label for="bv">Von</label><input id="bv" name="von" type="date" required></div>
                <div class="f"><label for="bb">Bis</label><input id="bb" name="bis" type="date"></div>
              </div>
              <div class="fgrid">
                <div class="f"><label for="ba">Art</label><select id="ba" name="art"><?= opts_simple(
                  ['schule' => 'Schulblock', 'betrieb' => 'Betrieb', 'ferien' => 'Ferien', 'ueba' => 'UEBA', 'pruefung' => 'Pruefung'], 'schule') ?></select></div>
                <div class="f"><label for="bz">Zeitgruppe</label><input id="bz" name="zeitgruppe" type="number" min="1" max="9" value="<?= (int)($cls['zeitgruppe'] ?? 1) ?>"></div>
              </div>
              <div class="f"><label for="bl2">Bezeichnung</label><input id="bl2" name="label" placeholder="z.B. 3. Block"></div>
              <button class="btn sm pri" type="submit">Eintragen</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php
    render_page('Woche & Plan', ob_get_clean());
}

// ===========================================================================
// 24. LERNFELDER
// ===========================================================================

function page_lernfelder(): void {
    $u   = require_login();
    $uid = (int)$u['id'];
    $cid = (int)($u['class_id'] ?: 0);
    $lfs = all("SELECT * FROM lernfelder ORDER BY nr");
    $stat = [];
    foreach (all("SELECT lf_no, COUNT(*) c FROM notes WHERE lf_no IS NOT NULL
                  AND (user_id = ? OR (visibility IN ('class','all') AND class_id = ?) OR visibility='all')
                  GROUP BY lf_no", [$uid, $cid]) as $r) $stat[(int)$r['lf_no']]['notizen'] = (int)$r['c'];
    foreach (all("SELECT lf_no, COUNT(*) c FROM events WHERE lf_no IS NOT NULL
                  AND ((visibility='class' AND class_id = ?) OR user_id = ?) GROUP BY lf_no", [$cid, $uid]) as $r)
        $stat[(int)$r['lf_no']]['termine'] = (int)$r['c'];
    foreach (all("SELECT lf_no, COUNT(*) c FROM report_entries WHERE lf_no IS NOT NULL AND user_id = ? GROUP BY lf_no", [$uid]) as $r)
        $stat[(int)$r['lf_no']]['bericht'] = (int)$r['c'];
    $detail = get('nr') !== '' ? one("SELECT * FROM lernfelder WHERE nr = ?", [(int)get('nr')]) : null;
    $jahr = ausbildungsjahr_am($u, today());

    ob_start(); ?>
    <div class="split">
      <div>
        <?php foreach ([1, 2, 3] as $j): ?>
          <div class="card">
            <h2><?= $j ?>. Ausbildungsjahr <?= $j === $jahr ? '<span class="tag acc">aktuell</span>' : '' ?></h2>
            <div class="tw"><table><thead><tr><th>LF</th><th>Titel</th><th>Std.</th><th>Notizen</th><th>Termine</th><th>Berichtsheft</th></tr></thead><tbody>
              <?php foreach (array_filter($lfs, fn($l) => (int)$l['jahr'] === $j) as $l):
                $s = $stat[(int)$l['nr']] ?? []; ?>
                <tr><td><span class="tag acc"><?= h($l['code']) ?></span></td>
                  <td><a href="<?= url('lernfelder', ['nr' => $l['nr']]) ?>"><?= h($l['titel']) ?></a></td>
                  <td class="small"><?= (int)$l['stunden'] ?></td>
                  <td><?= $s['notizen'] ?? 0 ?></td><td><?= $s['termine'] ?? 0 ?></td><td><?= $s['bericht'] ?? 0 ?></td></tr>
              <?php endforeach; ?>
            </tbody></table></div>
          </div>
        <?php endforeach; ?>
      </div>
      <div>
        <?php if ($detail): $nr = (int)$detail['nr']; ?>
          <div class="card">
            <h3><?= h($detail['code']) ?></h3>
            <p><?= h($detail['titel']) ?></p>
            <p class="small muted"><?= (int)$detail['jahr'] ?>. Ausbildungsjahr &middot; <?= (int)$detail['stunden'] ?> Stunden</p>
            <hr>
            <h4>Verknuepfte Notizen</h4>
            <ul class="list">
              <?php $ns = all("SELECT * FROM notes WHERE lf_no = ? AND (user_id = ? OR (visibility IN ('class','all') AND class_id = ?) OR visibility='all')
                               ORDER BY datum DESC LIMIT 20", [$nr, $uid, $cid]);
              foreach ($ns as $n): ?>
                <li><div style="flex:1"><a href="<?= url('notizen', ['id' => $n['id']]) ?>"><?= h($n['titel'] ?: mb_substr($n['body'], 0, 50)) ?></a>
                  <div class="small muted2"><?= h(de_date($n['datum'])) ?></div></div></li>
              <?php endforeach; if (!$ns): ?><li class="small muted2">noch nichts</li><?php endif; ?>
            </ul>
            <a class="btn sm" href="<?= url('wissen', ['lf' => $nr]) ?>">Im Wissensbereich anzeigen</a>
          </div>
        <?php else: ?>
          <div class="card">
            <h3>Rahmenlehrplan</h3>
            <p class="small muted">Die Lernfelder 1 bis 9 sind fuer alle IT-Berufe gleich.
            Ab LF 10 folgt die Fachrichtung Systemintegration.</p>
            <hr>
            <h4>Pruefungsbereiche (IHK)</h4>
            <ul class="list small">
              <?php foreach (ihk_bereiche() as [$label, $w]): ?>
                <li><span class="tag acc"><?= $w ?>&thinsp;%</span><span><?= h($label) ?></span></li>
              <?php endforeach; ?>
            </ul>
            <a class="btn sm" href="<?= url('pruefung') ?>">Zur Pruefungsuebersicht</a>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    render_page('Lernfelder', ob_get_clean());
}

// ===========================================================================
// 25. IHK-PRUEFUNG
// ===========================================================================

function page_pruefung(): void {
    $u   = require_login();
    $uid = (int)$u['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (post('action') === 'projekt') {
            setting_set('projekt_' . $uid, json_encode([
                'titel'   => mb_substr(post('titel'), 0, 200),
                'kunde'   => mb_substr(post('kunde'), 0, 120),
                'stunden' => (int)post('stunden', '0'),
                'antrag'  => post('antrag'),
                'status'  => post('status'),
                'notiz'   => post('notiz'),
            ]));
            flash('Projektdaten gespeichert.');
        } elseif (post('action') === 'termine' && is_admin()) {
            setting_set('ap1_datum', post('ap1_datum'));
            setting_set('ap2_datum', post('ap2_datum'));
            flash('Pruefungstermine gespeichert.');
        }
        redirect(url('pruefung'));
    }
    $pj   = json_decode((string)setting('projekt_' . $uid, '{}'), true) ?: [];
    $ihkP = json_decode((string)setting('ihk_' . $uid, '{}'), true) ?: [];
    $prog = ihk_prognose($ihkP);
    $countdown = function (string $datum): ?int {
        if (!$datum) return null;
        $t = strtotime($datum);
        return $t ? (int)ceil(($t - strtotime(today())) / 86400) : null;
    };
    $ap1 = $countdown((string)setting('ap1_datum'));
    $ap2 = $countdown((string)setting('ap2_datum'));

    ob_start(); ?>
    <div class="grid g4" style="margin-bottom:1rem">
      <?= ui_kpi('Teil 1 (AP1)', $ap1 === null ? '&ndash;' : ($ap1 > 0 ? $ap1 . ' Tage' : 'erledigt'),
            setting('ap1_datum') ? h(de_date(setting('ap1_datum'))) : 'Termin nicht gesetzt') ?>
      <?= ui_kpi('Teil 2 (AP2)', $ap2 === null ? '&ndash;' : ($ap2 > 0 ? $ap2 . ' Tage' : 'erledigt'),
            setting('ap2_datum') ? h(de_date(setting('ap2_datum'))) : 'Termin nicht gesetzt') ?>
      <?= ui_kpi('Prognose', $prog['punkte'] !== null ? num($prog['punkte'], 0) . ' P' : '&ndash;',
            $prog['note'] !== null ? 'Note ' . num((float)$prog['note'], 1) : 'in Noten eintragen', $prog['punkte']) ?>
      <?= ui_kpi('Projektstunden', (string)(int)($pj['stunden'] ?? 0), 'von max. 80 h',
            min(100, ((int)($pj['stunden'] ?? 0)) / 80 * 100)) ?>
    </div>

    <div class="split">
      <div>
        <div class="card">
          <h2>Aufbau der gestreckten Abschlusspruefung</h2>
          <p class="small muted">Fachinformatiker/-in fuer Systemintegration - Gewichtung nach Ausbildungsverordnung.</p>
          <div class="tw"><table><thead><tr><th>Pruefungsbereich</th><th>Gewicht</th><th>Deine Punkte</th></tr></thead><tbody>
            <?php foreach (ihk_bereiche() as $k => [$label, $w]): ?>
              <tr><td><?= h($label) ?></td><td><span class="tag acc"><?= $w ?>&thinsp;%</span></td>
                <td><?= isset($ihkP[$k]) && $ihkP[$k] !== '' ? num((float)$ihkP[$k], 0) . ' P' : '<span class="muted2">-</span>' ?></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <p class="small muted" style="margin-top:.6rem">Bestehensregeln: Gesamt mindestens 50 Punkte,
          Projektarbeit mindestens 50 Punkte, Teil 2 insgesamt mindestens 50 Punkte, hoechstens ein
          Pruefungsbereich unter 50 Punkten, kein Bereich mit 0 Punkten.</p>
          <a class="btn sm" href="<?= url('noten') ?>">Punkte eintragen</a>
        </div>

        <div class="card">
          <h2>Betriebliche Projektarbeit</h2>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="projekt">
            <div class="f"><label for="pt">Projekttitel</label><input id="pt" name="titel" value="<?= h($pj['titel'] ?? '') ?>"></div>
            <div class="fgrid">
              <div class="f"><label for="pk">Kunde / Abteilung</label><input id="pk" name="kunde" value="<?= h($pj['kunde'] ?? '') ?>"></div>
              <div class="f"><label for="ps">Geplante Stunden</label><input id="ps" name="stunden" type="number" min="0" max="80" value="<?= (int)($pj['stunden'] ?? 0) ?>"></div>
              <div class="f"><label for="pst">Status</label><select id="pst" name="status"><?= opts_simple(
                ['idee' => 'Idee', 'antrag' => 'Antrag geschrieben', 'eingereicht' => 'Antrag eingereicht',
                 'genehmigt' => 'genehmigt', 'umsetzung' => 'in Umsetzung', 'doku' => 'Dokumentation',
                 'abgegeben' => 'abgegeben', 'praesentiert' => 'praesentiert'], $pj['status'] ?? 'idee') ?></select></div>
            </div>
            <div class="f"><label for="pa">Projektantrag (Kurzfassung)</label>
              <textarea id="pa" name="antrag" data-draft="projekt"><?= h($pj['antrag'] ?? '') ?></textarea></div>
            <div class="f"><label for="pn">Notizen</label><textarea id="pn" name="notiz" style="min-height:60px"><?= h($pj['notiz'] ?? '') ?></textarea></div>
            <button class="btn pri" type="submit">Speichern</button>
          </form>
        </div>
      </div>

      <div>
        <div class="card">
          <h3>Checkliste</h3>
          <ul class="list small">
            <li><span><?= !empty($pj['titel']) ? '&#10003;' : '&#9744;' ?></span><span>Projektthema mit Ausbilder abgestimmt</span></li>
            <li><span><?= in_array($pj['status'] ?? '', ['eingereicht','genehmigt','umsetzung','doku','abgegeben','praesentiert'], true) ? '&#10003;' : '&#9744;' ?></span><span>Projektantrag ueber das IHK-Portal eingereicht</span></li>
            <li><span><?= in_array($pj['status'] ?? '', ['genehmigt','umsetzung','doku','abgegeben','praesentiert'], true) ? '&#10003;' : '&#9744;' ?></span><span>Antrag genehmigt</span></li>
            <li><span><?= in_array($pj['status'] ?? '', ['abgegeben','praesentiert'], true) ? '&#10003;' : '&#9744;' ?></span><span>Dokumentation fristgerecht abgegeben</span></li>
            <li><span><?= (int)val("SELECT COUNT(*) FROM reports WHERE user_id = ? AND status = 'geprueft'", [$uid], 0) > 0 ? '&#10003;' : '&#9744;' ?></span><span>Ausbildungsnachweise vollstaendig und abgezeichnet</span></li>
            <li><span><?= !empty($u['ausbildung_ende']) ? '&#10003;' : '&#9744;' ?></span><span>Ausbildungsende im Profil hinterlegt</span></li>
          </ul>
          <p class="small muted">Die vollstaendig gefuehrten Ausbildungsnachweise sind
          Zulassungsvoraussetzung zur Abschlusspruefung.</p>
        </div>
        <?php if (is_admin()): ?>
        <div class="card">
          <h3>Pruefungstermine (fuer alle)</h3>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="termine">
            <div class="f"><label for="a1">Teil 1</label><input id="a1" name="ap1_datum" type="date" value="<?= h(setting('ap1_datum')) ?>"></div>
            <div class="f"><label for="a2">Teil 2</label><input id="a2" name="ap2_datum" type="date" value="<?= h(setting('ap2_datum')) ?>"></div>
            <button class="btn pri sm" type="submit">Speichern</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    render_page('IHK-Pruefung', ob_get_clean());
}

// ===========================================================================
// 26. PROFIL & SICHERHEIT
// ===========================================================================

function page_profil(): void {
    $u   = require_login();
    $uid = (int)$u['id'];
    $tab = get('tab') ?: 'daten';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $act = post('action');
        if ($act === 'daten') {
            upd('users', [
                'display_name'     => mb_substr(post('display_name'), 0, 100),
                'email'            => filter_var(post('email'), FILTER_VALIDATE_EMAIL) ?: null,
                'betrieb'          => mb_substr(post('betrieb'), 0, 150),
                'ausbilder_name'   => mb_substr(post('ausbilder_name'), 0, 100),
                'beruf'            => mb_substr(post('beruf'), 0, 120),
                'ausbildung_start' => post('ausbildung_start') ?: null,
                'ausbildung_ende'  => post('ausbildung_ende') ?: null,
                'wochenstunden'    => max(0, (float)str_replace(',', '.', post('wochenstunden', '40'))),
                'class_id'         => int_or_null(postn('class_id')),
            ], 'id = :id', ['id' => $uid]);
            if (postn('class_id')) q("INSERT OR IGNORE INTO class_members (class_id,user_id) VALUES (?,?)", [(int)postn('class_id'), $uid]);
            flash('Profil gespeichert.');
            redirect(url('profil'));
        }
        if ($act === 'passwort') {
            $alt = (string)($_POST['alt'] ?? ''); $neu = (string)($_POST['neu'] ?? '');
            if (!rl_hit('pw:' . $uid, 10, 900)) flash('Zu viele Versuche.', 'err');
            elseif (!password_verify($alt, $u['pass_hash'])) { flash('Aktuelles Passwort ist falsch.', 'err'); audit('pw_wechsel_fehl'); }
            elseif ($neu !== (string)($_POST['neu2'] ?? '')) flash('Die neuen Passwoerter stimmen nicht ueberein.', 'err');
            elseif ($p = pw_problems($neu, $u['username'], $u['display_name'])) flash('Passwort: ' . implode(', ', $p), 'err');
            else {
                upd('users', ['pass_hash' => pw_hash($neu), 'must_change_pw' => 0, 'pw_changed_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $uid]);
                q("UPDATE sessions SET revoked = 1 WHERE user_id = ? AND sid_hash <> ?", [$uid, hash('sha256', session_id())]);
                audit('pw_gewechselt');
                flash('Passwort geaendert. Andere Sitzungen wurden abgemeldet.');
            }
            redirect(url('profil', ['tab' => 'sicherheit']));
        }
        if ($act === '2fa_start') {
            $_SESSION['2fa_setup'] = b32_encode(random_bytes(20));
            redirect(url('profil', ['tab' => '2fa']));
        }
        if ($act === '2fa_on') {
            $secret = (string)($_SESSION['2fa_setup'] ?? '');
            if ($secret && totp_verify($secret, post('code'))) {
                $codes = []; $klar = [];
                for ($i = 0; $i < 8; $i++) { $c = rand_code(5); $klar[] = $c; $codes[] = password_hash($c, PASSWORD_DEFAULT); }
                upd('users', ['totp_secret' => $secret, 'totp_enabled' => 1, 'recovery_codes' => json_encode($codes)], 'id = :id', ['id' => $uid]);
                unset($_SESSION['2fa_setup']);
                $_SESSION['recovery_show'] = $klar;
                audit('2fa_aktiviert');
                flash('Zwei-Faktor-Authentifizierung aktiviert.');
            } else flash('Code stimmt nicht. Bitte erneut versuchen.', 'err');
            redirect(url('profil', ['tab' => '2fa']));
        }
        if ($act === '2fa_off') {
            if (password_verify((string)($_POST['pw'] ?? ''), $u['pass_hash'])) {
                upd('users', ['totp_enabled' => 0, 'totp_secret' => null, 'recovery_codes' => null], 'id = :id', ['id' => $uid]);
                audit('2fa_deaktiviert');
                flash('Zwei-Faktor deaktiviert.', 'warn');
            } else flash('Passwort falsch.', 'err');
            redirect(url('profil', ['tab' => '2fa']));
        }
        if ($act === 'sessions') {
            q("UPDATE sessions SET revoked = 1 WHERE user_id = ? AND sid_hash <> ?", [$uid, hash('sha256', session_id())]);
            audit('sessions_beendet');
            flash('Alle anderen Sitzungen wurden beendet.');
            redirect(url('profil', ['tab' => 'sicherheit']));
        }
        if ($act === 'ics_neu') {
            upd('users', ['ics_token' => bin2hex(random_bytes(16))], 'id = :id', ['id' => $uid]);
            flash('Kalender-Adresse erneuert. Alte Abos funktionieren nicht mehr.');
            redirect(url('profil', ['tab' => 'sicherheit']));
        }
    }

    $sessions = all("SELECT * FROM sessions WHERE user_id = ? AND revoked = 0 ORDER BY last_seen DESC", [$uid]);
    $klassen  = all("SELECT id, name FROM classes WHERE archived = 0 ORDER BY name");
    $recovery = $_SESSION['recovery_show'] ?? null; unset($_SESSION['recovery_show']);
    $setupSecret = $_SESSION['2fa_setup'] ?? null;

    ob_start(); ?>
    <div class="chips noprint" style="margin-bottom:1rem">
      <?php foreach (['daten' => 'Stammdaten', 'sicherheit' => 'Sicherheit', '2fa' => 'Zwei-Faktor', 'export' => 'Daten & Export'] as $k => $l): ?>
        <a class="chip <?= $tab === $k ? 'on' : '' ?>" href="<?= url('profil', ['tab' => $k]) ?>"><?= h($l) ?></a>
      <?php endforeach; ?>
    </div>

    <?php if ($tab === 'daten'): ?>
      <div class="card" style="max-width:760px">
        <h2>Stammdaten</h2>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="daten">
          <div class="fgrid">
            <div class="f"><label>Benutzername</label><input value="<?= h($u['username']) ?>" disabled></div>
            <div class="f"><label for="dn">Anzeigename</label><input id="dn" name="display_name" value="<?= h($u['display_name']) ?>"></div>
          </div>
          <div class="fgrid">
            <div class="f"><label for="em">E-Mail</label><input id="em" name="email" type="email" value="<?= h($u['email']) ?>"></div>
            <div class="f"><label for="kl">Klasse</label><select id="kl" name="class_id"><?= opts($klassen, $u['class_id'], 'id', 'name', '- keine -') ?></select></div>
          </div>
          <fieldset><legend>Ausbildung</legend>
            <div class="fgrid">
              <div class="f"><label for="br">Ausbildungsberuf</label><input id="br" name="beruf" value="<?= h($u['beruf']) ?>"></div>
              <div class="f"><label for="ws">Wochenstunden</label><input id="ws" name="wochenstunden" value="<?= h($u['wochenstunden']) ?>" inputmode="decimal"></div>
            </div>
            <div class="fgrid">
              <div class="f"><label for="as">Beginn</label><input id="as" name="ausbildung_start" type="date" value="<?= h($u['ausbildung_start']) ?>"></div>
              <div class="f"><label for="ae">Geplantes Ende</label><input id="ae" name="ausbildung_ende" type="date" value="<?= h($u['ausbildung_ende']) ?>"></div>
            </div>
            <div class="fgrid">
              <div class="f"><label for="bt">Ausbildungsbetrieb</label><input id="bt" name="betrieb" value="<?= h($u['betrieb']) ?>"></div>
              <div class="f"><label for="an">Ausbilder/-in</label><input id="an" name="ausbilder_name" value="<?= h($u['ausbilder_name']) ?>"></div>
            </div>
          </fieldset>
          <button class="btn pri" type="submit">Speichern</button>
        </form>
      </div>

    <?php elseif ($tab === 'sicherheit'): ?>
      <div class="grid g2">
        <div class="card">
          <h2>Passwort aendern</h2>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="passwort">
            <div class="f"><label for="pa">Aktuelles Passwort</label><input id="pa" name="alt" type="password" required autocomplete="current-password"></div>
            <div class="f"><label for="pn">Neues Passwort</label><input id="pn" name="neu" type="password" required autocomplete="new-password"></div>
            <div class="f"><label for="pn2">Wiederholen</label><input id="pn2" name="neu2" type="password" required autocomplete="new-password"></div>
            <div class="small muted">Mindestens <?= PW_MIN_LEN ?> Zeichen, 3 von 4 Zeichenarten.
              Beim Wechsel werden alle anderen Sitzungen beendet.</div>
            <br><button class="btn pri" type="submit">Passwort aendern</button>
          </form>
          <p class="small muted2" style="margin-top:.6rem">Zuletzt geaendert:
            <?= $u['pw_changed_at'] ? h(de_date($u['pw_changed_at'], 'd.m.Y')) : 'unbekannt' ?></p>
        </div>
        <div class="card">
          <h2>Aktive Sitzungen</h2>
          <div class="tw"><table><thead><tr><th>Seit</th><th>Zuletzt</th><th>IP</th></tr></thead><tbody>
            <?php foreach ($sessions as $s): $me = hash_equals($s['sid_hash'], hash('sha256', session_id())); ?>
              <tr><td class="small"><?= h(date('d.m.Y H:i', (int)$s['created_at'])) ?><?= $me ? ' <span class="tag ok">diese</span>' : '' ?></td>
                <td class="small"><?= h(date('d.m. H:i', (int)$s['last_seen'])) ?></td>
                <td class="small"><?= h($s['ip']) ?></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <form method="post" style="margin-top:.7rem" data-confirm="Alle anderen Sitzungen beenden?">
            <?= csrf_field() ?><input type="hidden" name="action" value="sessions">
            <button class="btn" type="submit">Andere Sitzungen abmelden</button></form>
          <hr>
          <h3>Kalender-Adresse</h3>
          <p class="small muted">Wer diese Adresse kennt, sieht deine Termine (lesend).</p>
          <form method="post" data-confirm="Neue Adresse erzeugen? Bestehende Abos brechen ab.">
            <?= csrf_field() ?><input type="hidden" name="action" value="ics_neu">
            <button class="btn sm" type="submit">Adresse erneuern</button></form>
          <hr>
          <h3>Letzte Anmeldung</h3>
          <p class="small"><?= $u['last_login_at'] ? h(de_date($u['last_login_at'], 'd.m.Y H:i')) . ' von ' . h($u['last_login_ip']) : 'unbekannt' ?></p>
        </div>
      </div>

    <?php elseif ($tab === '2fa'): ?>
      <div class="card" style="max-width:640px">
        <h2>Zwei-Faktor-Authentifizierung (TOTP)</h2>
        <?php if ($recovery): ?>
          <div class="msg warn"><div><strong>Wiederherstellungscodes - jetzt sichern!</strong>
            <p class="small">Jeder Code funktioniert genau einmal, falls du dein Handy verlierst.
            Sie werden nur dieses eine Mal angezeigt.</p>
            <pre id="rc"><?= h(implode("\n", $recovery)) ?></pre>
            <button class="btn sm" data-copy="rc" type="button">kopieren</button></div></div>
        <?php endif; ?>
        <?php if ((int)$u['totp_enabled'] === 1): ?>
          <p><span class="tag ok">aktiv</span> Dein Konto ist mit einem zweiten Faktor geschuetzt.</p>
          <p class="small muted">Verbleibende Wiederherstellungscodes:
            <?= count(json_decode((string)$u['recovery_codes'], true) ?: []) ?></p>
          <hr>
          <form method="post" data-confirm="Zwei-Faktor wirklich abschalten?">
            <?= csrf_field() ?><input type="hidden" name="action" value="2fa_off">
            <div class="f"><label for="op">Zur Bestaetigung: Passwort</label><input id="op" name="pw" type="password" required></div>
            <button class="btn dan" type="submit">Deaktivieren</button></form>
        <?php elseif ($setupSecret): ?>
          <p class="small muted">1. QR-Code mit der Authenticator-App scannen (oder Schluessel eintippen).
            2. Den erzeugten Code hier eingeben.</p>
          <?php
            $label = rawurlencode(APP_NAME . ':' . $u['username']);
            $iss   = rawurlencode(APP_NAME);
            $uri   = "otpauth://totp/$label?secret=$setupSecret&issuer=$iss&algorithm=SHA1&digits=6&period=30";
          ?>
          <div class="row" style="align-items:flex-start;gap:1rem">
            <div style="background:#fff;padding:8px;border-radius:10px"><?= QR::svg($uri, 4) ?></div>
            <div style="flex:1;min-width:200px">
              <label>Geheimer Schluessel</label>
              <pre id="sec" style="word-break:break-all;white-space:pre-wrap"><?= h(implode(' ', str_split($setupSecret, 4))) ?></pre>
              <button class="btn sm" data-copy="sec" type="button">kopieren</button>
              <form method="post" style="margin-top:.8rem">
                <?= csrf_field() ?><input type="hidden" name="action" value="2fa_on">
                <div class="f"><label for="cd">Code aus der App</label>
                  <input id="cd" name="code" inputmode="numeric" required autocomplete="one-time-code"
                         style="font-family:var(--mono);font-size:1.1rem;letter-spacing:.12em"></div>
                <button class="btn pri" type="submit">Aktivieren</button>
              </form>
            </div>
          </div>
        <?php else: ?>
          <p>Ein zweiter Faktor schuetzt dein Konto auch dann, wenn dein Passwort bekannt wird.
            Empfohlen fuer alle Konten, dringend fuer Lehrkraefte, Ausbilder und Administration.</p>
          <form method="post"><?= csrf_field() ?>
            <input type="hidden" name="action" value="2fa_start">
            <button class="btn pri" type="submit">Einrichten</button></form>
        <?php endif; ?>
      </div>

    <?php else: ?>
      <div class="grid g2">
        <div class="card">
          <h2>Eigene Daten exportieren</h2>
          <p class="small muted">Alles, was zu deinem Konto gespeichert ist - als JSON oder CSV.</p>
          <ul class="list">
            <li><a href="<?= url('export', ['was' => 'alles']) ?>">Komplettexport (JSON)</a></li>
            <li><a href="<?= url('export', ['was' => 'noten']) ?>">Noten (CSV)</a></li>
            <li><a href="<?= url('export', ['was' => 'berichtsheft']) ?>">Berichtsheft komplett (CSV)</a></li>
            <li><a href="<?= url('export', ['was' => 'routinen']) ?>">Routine-Protokoll (CSV)</a></li>
            <li><a href="<?= url('export', ['was' => 'notizen']) ?>">Notizen (CSV)</a></li>
          </ul>
        </div>
        <div class="card">
          <h2>Kalender-Abo</h2>
          <p class="small muted">Proben, Abgaben und Termine im Handy-Kalender.</p>
          <pre id="ics2" style="white-space:pre-wrap;word-break:break-all"><?= h(abs_url(url('ics', ['t' => $u['ics_token'] ?: '-']))) ?></pre>
          <button class="btn sm" data-copy="ics2" type="button">Adresse kopieren</button>
          <hr>
          <h3>Darstellung</h3>
          <form method="post" action="<?= url('theme') ?>">
            <?= csrf_field() ?>
            <div class="f"><label for="th">Design</label><select id="th" name="theme"><?= opts_simple(
              ['auto' => 'automatisch (System)', 'hell' => 'hell', 'dunkel' => 'dunkel'], $u['theme']) ?></select></div>
            <button class="btn sm" type="submit">Uebernehmen</button>
          </form>
        </div>
      </div>
    <?php endif; ?>
    <?php
    render_page('Profil', ob_get_clean());
}

// ===========================================================================
// 27. KLASSE & TEAM, NACHWEISE PRUEFEN
// ===========================================================================

function page_klasse(): void {
    $u = require_role('admin', 'lehrer', 'ausbilder');
    $cid = (int)(get('c') ?: ($u['class_id'] ?: 0));
    $klassen = all("SELECT c.*, (SELECT COUNT(*) FROM users x WHERE x.class_id = c.id AND x.active = 1) AS anz
                    FROM classes c WHERE c.archived = 0 ORDER BY c.name");
    $mitglieder = $cid ? all("SELECT u.*, (SELECT COUNT(*) FROM reports r WHERE r.user_id = u.id AND r.status <> 'entwurf') AS nw,
                              (SELECT COUNT(*) FROM reports r WHERE r.user_id = u.id AND r.status = 'eingereicht') AS offen
                              FROM users u WHERE u.class_id = ? AND u.active = 1 ORDER BY u.display_name", [$cid]) : [];
    ob_start(); ?>
    <div class="chips noprint" style="margin-bottom:1rem">
      <?php foreach ($klassen as $k): ?>
        <a class="chip <?= (int)$k['id'] === $cid ? 'on' : '' ?>" href="<?= url('klasse', ['c' => $k['id']]) ?>">
          <?= h($k['name']) ?> (<?= (int)$k['anz'] ?>)</a>
      <?php endforeach; ?>
    </div>
    <div class="card">
      <h2>Mitglieder</h2>
      <?php if (!$mitglieder): ?><?= ui_empty('Keine Mitglieder', 'Klassen und Einladungen in der Verwaltung anlegen.') ?><?php else: ?>
      <div class="tw"><table><thead><tr><th>Name</th><th>Benutzer</th><th>Rolle</th><th>Betrieb</th><th>Nachweise</th><th>Offen</th><th>2FA</th><th>Letzter Login</th></tr></thead><tbody>
        <?php foreach ($mitglieder as $m): ?>
          <tr><td><?= h($m['display_name']) ?></td><td class="small"><?= h($m['username']) ?></td>
            <td><span class="tag"><?= h(rolle_label($m['role'])) ?></span></td>
            <td class="small"><?= h($m['betrieb']) ?></td><td><?= (int)$m['nw'] ?></td>
            <td><?= (int)$m['offen'] ? '<span class="tag info">' . (int)$m['offen'] . '</span>' : '' ?></td>
            <td><?= (int)$m['totp_enabled'] ? '<span class="tag ok">ja</span>' : '<span class="tag warn">nein</span>' ?></td>
            <td class="small"><?= $m['last_login_at'] ? h(de_date($m['last_login_at'], 'd.m.Y')) : '-' ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>
    <?php
    render_page('Klasse & Team', ob_get_clean());
}

function page_pruefen(): void {
    $u = require_role('admin', 'ausbilder');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $rid = (int)post('id', '0');
        $rep = one("SELECT * FROM reports WHERE id = ?", [$rid]);
        if ($rep) {
            if (post('action') === 'ok') {
                upd('reports', ['status' => 'geprueft', 'geprueft_von' => (int)$u['id'],
                    'geprueft_am' => date('Y-m-d H:i:s'), 'pruef_notiz' => post('notiz'),
                    'sign_ausbilder' => $u['display_name'] ?: $u['username']], 'id = :id', ['id' => $rid]);
                audit('nachweis_abgezeichnet', $rep['periode'], 'user ' . $rep['user_id']);
                flash('Nachweis abgezeichnet.');
            } elseif (post('action') === 'zurueck') {
                upd('reports', ['status' => 'abgelehnt', 'geprueft_von' => (int)$u['id'],
                    'geprueft_am' => date('Y-m-d H:i:s'), 'pruef_notiz' => post('notiz')], 'id = :id', ['id' => $rid]);
                audit('nachweis_zurueckgewiesen', $rep['periode'], 'user ' . $rep['user_id']);
                flash('Zurueckgewiesen.', 'warn');
            }
        }
        redirect(url('pruefen'));
    }
    $offen = all("SELECT r.*, u.display_name, u.username, u.betrieb,
                  (SELECT COUNT(*) FROM report_entries e WHERE e.report_id = r.id) AS anz,
                  (SELECT COALESCE(SUM(stunden),0) FROM report_entries e WHERE e.report_id = r.id) AS std
                  FROM reports r JOIN users u ON u.id = r.user_id
                  WHERE r.status = 'eingereicht' ORDER BY r.von");
    $detail = get('id') !== '' ? one("SELECT r.*, u.display_name, u.betrieb, u.beruf FROM reports r
                                      JOIN users u ON u.id = r.user_id WHERE r.id = ?", [(int)get('id')]) : null;
    $letzte = all("SELECT r.*, u.display_name FROM reports r JOIN users u ON u.id = r.user_id
                   WHERE r.status IN ('geprueft','abgelehnt') ORDER BY r.geprueft_am DESC LIMIT 20");
    ob_start(); ?>
    <div class="split">
      <div>
        <div class="card">
          <h2>Zur Pruefung eingereicht <span class="tag <?= $offen ? 'info' : 'ok' ?>"><?= count($offen) ?></span></h2>
          <?php if (!$offen): ?><?= ui_empty('Nichts offen') ?><?php else: ?>
          <div class="tw"><table><thead><tr><th>Azubi</th><th>Zeitraum</th><th>Eintraege</th><th>Std.</th><th>Eingereicht</th><th></th></tr></thead><tbody>
            <?php foreach ($offen as $r): ?>
              <tr><td><?= h($r['display_name']) ?><div class="small muted"><?= h($r['betrieb']) ?></div></td>
                <td><?= h(periode_label($r['periode'], $r['art'])) ?></td>
                <td><?= (int)$r['anz'] ?></td><td><?= num((float)$r['std'], 1) ?></td>
                <td class="small"><?= h(de_date($r['eingereicht_am'], 'd.m.Y H:i')) ?></td>
                <td><a class="btn sm pri" href="<?= url('pruefen', ['id' => $r['id']]) ?>">Ansehen</a></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <?php endif; ?>
        </div>
        <?php if ($detail):
          $sum = report_summary((int)$detail['id']); ?>
        <div class="card">
          <h2><?= h($detail['display_name']) ?> &middot; <?= h(periode_label($detail['periode'], $detail['art'])) ?></h2>
          <div class="tw"><table><thead><tr><th>Tag</th><th>Std.</th><th>Ort</th><th>Taetigkeit</th><th>Ausbildungsinhalt</th></tr></thead><tbody>
            <?php foreach ($sum['rows'] as $r): ?>
              <tr><td class="small"><?= h(de_date($r['datum'], 'D d.m.')) ?></td>
                <td class="small"><?= $r['stunden'] > 0 ? num((float)$r['stunden'], 2) : '' ?></td>
                <td><span class="tag"><?= h($r['ort']) ?></span></td>
                <td><?= h($r['text']) ?></td>
                <td class="small"><?= h(($r['pos_no'] ? '[' . $r['pos_no'] . '] ' : '') . ($r['kategorie'] ?: '')) ?></td></tr>
            <?php endforeach; ?>
            <tr><th>Summe</th><th><?= num($sum['stunden'], 2) ?></th><th colspan="3"></th></tr>
          </tbody></table></div>
          <?php if ($detail['schule_text']): ?><h4 style="margin-top:.8rem">Berufsschule</h4><p><?= nl2br(h($detail['schule_text'])) ?></p><?php endif; ?>
          <?php if ($detail['sonstiges']): ?><h4>Sonstiges</h4><p><?= nl2br(h($detail['sonstiges'])) ?></p><?php endif; ?>
          <hr>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
            <div class="f"><label for="pz">Anmerkung (optional)</label><input id="pz" name="notiz" value="<?= h($detail['pruef_notiz']) ?>"></div>
            <div class="row">
              <button class="btn pri" type="submit" name="action" value="ok">Abzeichnen</button>
              <button class="btn dan" type="submit" name="action" value="zurueck">Zurueckweisen</button>
              <a class="btn ghost" href="<?= url('pruefen') ?>">Schliessen</a>
            </div>
          </form>
        </div>
        <?php endif; ?>
      </div>
      <div class="card">
        <h3>Zuletzt bearbeitet</h3>
        <ul class="list">
          <?php foreach ($letzte as $r): ?>
            <li><span class="tag <?= bericht_status_klasse($r['status']) ?>"><?= h(bericht_status_label($r['status'])) ?></span>
              <div style="flex:1"><?= h($r['display_name']) ?>
                <div class="small muted2"><?= h(periode_label($r['periode'], $r['art'])) ?> &middot; <?= h(de_date($r['geprueft_am'], 'd.m.Y')) ?></div></div></li>
          <?php endforeach; ?>
          <?php if (!$letzte): ?><li class="small muted2">noch nichts</li><?php endif; ?>
        </ul>
      </div>
    </div>
    <?php
    render_page('Nachweise pruefen', ob_get_clean());
}

// ===========================================================================
// 28. VERWALTUNG
// ===========================================================================

function page_admin(): void {
    $u   = require_role('admin');
    $tab = get('tab') ?: 'benutzer';
    $neuerCode = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $act = post('action');
        // --- Benutzer ---------------------------------------------------
        if ($act === 'user_save') {
            $id = (int)post('id', '0');
            $data = [
                'display_name' => mb_substr(post('display_name'), 0, 100),
                'email'        => filter_var(post('email'), FILTER_VALIDATE_EMAIL) ?: null,
                'role'         => in_array(post('role'), ['admin','lehrer','ausbilder','azubi'], true) ? post('role') : 'azubi',
                'class_id'     => int_or_null(postn('class_id')),
                'betrieb'      => mb_substr(post('betrieb'), 0, 150),
                'active'       => post('active') === '0' ? 0 : 1,
            ];
            if ($id === (int)$u['id'] && $data['role'] !== 'admin') { flash('Die eigene Adminrolle kann nicht entzogen werden.', 'err'); }
            elseif ($id === (int)$u['id'] && (int)$data['active'] === 0) { flash('Das eigene Konto kann nicht deaktiviert werden.', 'err'); }
            elseif ($id) {
                upd('users', $data, 'id = :id', ['id' => $id]);
                if ($data['class_id']) q("INSERT OR IGNORE INTO class_members (class_id,user_id) VALUES (?,?)", [$data['class_id'], $id]);
                audit('user_geaendert', (string)$id, json_encode($data));
                flash('Benutzer gespeichert.');
            }
        } elseif ($act === 'user_pwreset') {
            $id = (int)post('id', '0');
            $neu = rand_code(6);
            upd('users', ['pass_hash' => pw_hash($neu), 'must_change_pw' => 1, 'failed_logins' => 0, 'locked_until' => 0],
                'id = :id', ['id' => $id]);
            q("UPDATE sessions SET revoked = 1 WHERE user_id = ?", [$id]);
            audit('pw_zurueckgesetzt', (string)$id);
            $_SESSION['flash_code'] = $neu;
            flash('Neues Passwort erzeugt (siehe unten). Bitte sicher uebermitteln.', 'warn');
        } elseif ($act === 'user_unlock') {
            upd('users', ['locked_until' => 0, 'failed_logins' => 0], 'id = :id', ['id' => (int)post('id', '0')]);
            flash('Sperre aufgehoben.');
        } elseif ($act === 'user_2fa_off') {
            upd('users', ['totp_enabled' => 0, 'totp_secret' => null, 'recovery_codes' => null], 'id = :id', ['id' => (int)post('id', '0')]);
            audit('2fa_admin_reset', post('id'));
            flash('Zwei-Faktor beim Konto zurueckgesetzt.', 'warn');
        } elseif ($act === 'user_del') {
            $id = (int)post('id', '0');
            if ($id !== (int)$u['id']) { del('users', 'id = ?', [$id]); audit('user_geloescht', (string)$id); flash('Benutzer geloescht.', 'warn'); }
        }
        // --- Klassen ----------------------------------------------------
        elseif ($act === 'class_save') {
            $id = (int)post('id', '0');
            $name = mb_substr(post('name'), 0, 40);
            $zg = (int)post('zeitgruppe', '0');
            if ($zg === 0 && preg_match('/(\d)$/', $name, $m)) $zg = (int)$m[1];
            $aj = (int)post('ausbildungsjahr', '0');
            if ($aj === 0 && preg_match('/^(\d)/', $name, $m)) $aj = (int)$m[1];
            $data = ['name' => $name, 'ausbildungsjahr' => max(1, min(4, $aj ?: 1)),
                     'zeitgruppe' => max(0, min(9, $zg)), 'schuljahr' => mb_substr(post('schuljahr'), 0, 20),
                     'klassenleitung' => mb_substr(post('klassenleitung'), 0, 80), 'raum' => mb_substr(post('raum'), 0, 30),
                     'note' => post('note'), 'archived' => post('archived') ? 1 : 0];
            if ($name === '') flash('Name fehlt.', 'err');
            elseif ($id) { upd('classes', $data, 'id = :id', ['id' => $id]); flash('Klasse gespeichert.'); }
            else { try { ins('classes', $data); flash('Klasse angelegt.'); } catch (Throwable $e) { flash('Name bereits vergeben.', 'err'); } }
        } elseif ($act === 'class_del') {
            del('classes', 'id = ?', [(int)post('id', '0')]); flash('Klasse geloescht.', 'warn');
        }
        // --- Faecher ----------------------------------------------------
        elseif ($act === 'subject_save') {
            $id = (int)post('id', '0');
            $data = ['name' => mb_substr(post('name'), 0, 80), 'short' => mb_substr(post('short'), 0, 12),
                     'lf_no' => int_or_null(postn('lf_no')), 'color' => preg_match('/^#[0-9a-f]{6}$/i', post('color')) ? post('color') : '#4f7cff',
                     'lehrer' => mb_substr(post('lehrer'), 0, 60), 'class_id' => int_or_null(postn('class_id')),
                     'sort' => (int)post('sort', '0'), 'archived' => post('archived') ? 1 : 0];
            if ($data['name'] === '') flash('Name fehlt.', 'err');
            elseif ($id) { upd('subjects', $data, 'id = :id', ['id' => $id]); flash('Fach gespeichert.'); }
            else { ins('subjects', $data); flash('Fach angelegt.'); }
        } elseif ($act === 'subject_del') {
            del('subjects', 'id = ?', [(int)post('id', '0')]); flash('Fach geloescht.', 'warn');
        } elseif ($act === 'subject_lf') {
            foreach (all("SELECT * FROM lernfelder ORDER BY nr") as $l) {
                if (!val("SELECT 1 FROM subjects WHERE lf_no = ? AND class_id IS NULL", [(int)$l['nr']])) {
                    ins('subjects', ['name' => $l['code'] . ' ' . $l['titel'], 'short' => $l['code'],
                        'lf_no' => (int)$l['nr'], 'sort' => (int)$l['nr'] * 10,
                        'color' => ['#2f6fdb','#22a06b','#a05eea','#e0742a','#0b8fa8','#d43f5c'][(int)$l['nr'] % 6]]);
                }
            }
            foreach (['Deutsch', 'Englisch', 'Politik und Gesellschaft', 'Religion / Ethik', 'Sport'] as $i => $n) {
                if (!val("SELECT 1 FROM subjects WHERE name = ?", [$n]))
                    ins('subjects', ['name' => $n, 'short' => mb_substr($n, 0, 3), 'sort' => 200 + $i, 'color' => '#7a8b99']);
            }
            flash('Faecher aus dem Rahmenlehrplan angelegt.');
        }
        // --- Kategorien & Regeln ----------------------------------------
        elseif ($act === 'cat_save') {
            $id = (int)post('id', '0');
            $data = ['name' => mb_substr(post('name'), 0, 120), 'abschnitt' => mb_substr(post('abschnitt'), 0, 2),
                     'pos_no' => mb_substr(post('pos_no'), 0, 10),
                     'farbe' => preg_match('/^#[0-9a-f]{6}$/i', post('farbe')) ? post('farbe') : '#4f7cff',
                     'sort' => (int)post('sort', '0'), 'aktiv' => post('aktiv') === '0' ? 0 : 1];
            if ($data['name'] === '') flash('Name fehlt.', 'err');
            elseif ($id) { upd('categories', $data, 'id = :id', ['id' => $id]); flash('Kategorie gespeichert.'); }
            else { try { ins('categories', $data); flash('Kategorie angelegt.'); } catch (Throwable $e) { flash('Name existiert bereits.', 'err'); } }
        } elseif ($act === 'cat_del') {
            del('categories', 'id = ?', [(int)post('id', '0')]); flash('Kategorie geloescht.', 'warn');
        } elseif ($act === 'rule_save') {
            $kw  = mb_strtolower(trim(post('keyword')));
            $cid = (int)post('category_id', '0');
            if ($kw !== '' && $cid) { ins('category_rules', ['keyword' => $kw, 'category_id' => $cid,
                'prio' => (int)post('prio', '10'), 'ersetzung' => mb_substr(post('ersetzung'), 0, 200)]);
                flash('Regel angelegt.'); }
            else flash('Stichwort und Kategorie noetig.', 'err');
        } elseif ($act === 'rule_del') {
            del('category_rules', 'id = ?', [(int)post('id', '0')]); flash('Regel geloescht.');
        }
        // --- Einladungen ------------------------------------------------
        elseif ($act === 'invite_new') {
            $code = rand_code(8);
            ins('invites', ['code_hash' => hash('sha256', $code), 'hint' => mb_substr($code, 0, 4) . '...',
                'role' => in_array(post('role'), ['admin','lehrer','ausbilder','azubi'], true) ? post('role') : 'azubi',
                'class_id' => int_or_null(postn('class_id')), 'created_by' => (int)$u['id'],
                'max_uses' => max(1, min(50, (int)post('max_uses', '1'))),
                'expires_at' => post('expires_at') ? post('expires_at') . ' 23:59:59' : null,
                'notiz' => mb_substr(post('notiz'), 0, 120)]);
            audit('einladung_erstellt', post('role'));
            $_SESSION['flash_code'] = $code;
            flash('Einladungscode erzeugt.');
        } elseif ($act === 'invite_del') {
            del('invites', 'id = ?', [(int)post('id', '0')]); flash('Einladung geloescht.');
        }
        // --- Einstellungen ----------------------------------------------
        elseif ($act === 'settings') {
            foreach (['schule', 'schule_kurz', 'berichtsheft_art', 'impressum'] as $k) setting_set($k, post($k));
            flash('Einstellungen gespeichert.');
        } elseif ($act === 'purge_audit') {
            del('audit_log', "ts < date('now','localtime','-180 day')");
            flash('Alte Protokolleintraege entfernt.');
        }
        redirect(url('admin', ['tab' => post('tab') ?: $tab]));
    }

    $code = $_SESSION['flash_code'] ?? null; unset($_SESSION['flash_code']);
    $klassen = all("SELECT id, name FROM classes ORDER BY name");
    ob_start(); ?>
    <div class="chips noprint" style="margin-bottom:1rem">
      <?php foreach (['benutzer' => 'Benutzer', 'klassen' => 'Klassen', 'faecher' => 'Faecher',
                      'kategorien' => 'Berichtsheft-Kategorien', 'einladungen' => 'Einladungen',
                      'protokoll' => 'Sicherheitsprotokoll', 'einstellungen' => 'Einstellungen'] as $k => $l): ?>
        <a class="chip <?= $tab === $k ? 'on' : '' ?>" href="<?= url('admin', ['tab' => $k]) ?>"><?= h($l) ?></a>
      <?php endforeach; ?>
    </div>
    <?php if ($code): ?>
      <div class="msg warn"><div><strong>Code / Passwort - nur jetzt sichtbar:</strong>
        <pre id="gcode"><?= h($code) ?></pre>
        <button class="btn sm" data-copy="gcode" type="button">kopieren</button></div></div>
    <?php endif; ?>

    <?php if ($tab === 'benutzer'):
      $users = all("SELECT u.*, c.name AS klasse FROM users u LEFT JOIN classes c ON c.id = u.class_id ORDER BY u.active DESC, u.display_name");
      $edit  = get('id') !== '' ? one("SELECT * FROM users WHERE id = ?", [(int)get('id')]) : null; ?>
      <div class="split">
        <div class="card">
          <div class="row" style="justify-content:space-between;margin-bottom:.5rem">
            <h2 style="margin:0">Benutzer <span class="tag"><?= count($users) ?></span></h2>
            <input placeholder="Filtern ..." data-filter="#ut" style="width:auto">
          </div>
          <div class="tw"><table id="ut"><thead><tr><th>Name</th><th>Benutzer</th><th>Rolle</th><th>Klasse</th><th>Status</th><th>2FA</th><th></th></tr></thead><tbody>
            <?php foreach ($users as $x): ?>
              <tr><td><?= h($x['display_name']) ?></td><td class="small"><?= h($x['username']) ?></td>
                <td><span class="tag <?= $x['role'] === 'admin' ? 'err' : ($x['role'] === 'azubi' ? '' : 'info') ?>"><?= h(rolle_label($x['role'])) ?></span></td>
                <td class="small"><?= h($x['klasse'] ?: '-') ?></td>
                <td><?php if (!(int)$x['active']): ?><span class="tag warn">inaktiv</span>
                    <?php elseif ((int)$x['locked_until'] > time()): ?><span class="tag err">gesperrt</span>
                    <?php else: ?><span class="tag ok">aktiv</span><?php endif; ?></td>
                <td><?= (int)$x['totp_enabled'] ? '&#10003;' : '' ?></td>
                <td><a class="btn sm ghost" href="<?= url('admin', ['tab' => 'benutzer', 'id' => $x['id']]) ?>">&#9998;</a></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </div>
        <div class="card">
          <?php if ($edit): ?>
            <h3><?= h($edit['display_name'] ?: $edit['username']) ?></h3>
            <form method="post">
              <?= csrf_field() ?><input type="hidden" name="action" value="user_save">
              <input type="hidden" name="tab" value="benutzer"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
              <div class="f"><label for="ud">Anzeigename</label><input id="ud" name="display_name" value="<?= h($edit['display_name']) ?>"></div>
              <div class="f"><label for="ue">E-Mail</label><input id="ue" name="email" type="email" value="<?= h($edit['email']) ?>"></div>
              <div class="fgrid">
                <div class="f"><label for="ur">Rolle</label><select id="ur" name="role"><?= opts_simple(
                  ['azubi' => 'Auszubildende/-r', 'ausbilder' => 'Ausbilder/-in', 'lehrer' => 'Lehrkraft', 'admin' => 'Administration'], $edit['role']) ?></select></div>
                <div class="f"><label for="uc">Klasse</label><select id="uc" name="class_id"><?= opts($klassen, $edit['class_id'], 'id', 'name', '- keine -') ?></select></div>
              </div>
              <div class="f"><label for="ub">Betrieb</label><input id="ub" name="betrieb" value="<?= h($edit['betrieb']) ?>"></div>
              <div class="f"><label for="ua">Status</label><select id="ua" name="active"><?= opts_simple([1 => 'aktiv', 0 => 'deaktiviert'], $edit['active']) ?></select></div>
              <button class="btn pri" type="submit">Speichern</button>
              <a class="btn ghost" href="<?= url('admin', ['tab' => 'benutzer']) ?>">Schliessen</a>
            </form>
            <hr>
            <div class="stack">
              <form method="post" data-confirm="Neues Zufallspasswort erzeugen?"><?= csrf_field() ?>
                <input type="hidden" name="action" value="user_pwreset"><input type="hidden" name="tab" value="benutzer">
                <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
                <button class="btn sm" type="submit">Passwort zuruecksetzen</button></form>
              <?php if ((int)$edit['locked_until'] > time()): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="user_unlock">
                  <input type="hidden" name="tab" value="benutzer"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
                  <button class="btn sm" type="submit">Sperre aufheben</button></form>
              <?php endif; ?>
              <?php if ((int)$edit['totp_enabled']): ?>
                <form method="post" data-confirm="Zwei-Faktor zuruecksetzen?"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="user_2fa_off"><input type="hidden" name="tab" value="benutzer">
                  <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
                  <button class="btn sm dan" type="submit">2FA zuruecksetzen</button></form>
              <?php endif; ?>
              <?php if ((int)$edit['id'] !== (int)$u['id']): ?>
                <form method="post" data-confirm="Konto und ALLE zugehoerigen Daten unwiderruflich loeschen?"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="user_del"><input type="hidden" name="tab" value="benutzer">
                  <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
                  <button class="btn dan sm" type="submit">Konto loeschen</button></form>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <h3>Hinweis</h3>
            <p class="small muted">Neue Konten entstehen ausschliesslich ueber Einladungscodes.
            Wechsle zu <a href="<?= url('admin', ['tab' => 'einladungen']) ?>">Einladungen</a>, um einen Code zu erzeugen.</p>
          <?php endif; ?>
        </div>
      </div>

    <?php elseif ($tab === 'klassen'):
      $rows = all("SELECT c.*, (SELECT COUNT(*) FROM users x WHERE x.class_id = c.id) AS anz FROM classes c ORDER BY c.name");
      $edit = get('id') !== '' ? one("SELECT * FROM classes WHERE id = ?", [(int)get('id')]) : null; ?>
      <div class="split">
        <div class="card">
          <h2>Klassen</h2>
          <div class="tw"><table><thead><tr><th>Name</th><th>Jahr</th><th>Zeitgruppe</th><th>Schuljahr</th><th>Leitung</th><th>Azubis</th><th></th></tr></thead><tbody>
            <?php foreach ($rows as $c): ?>
              <tr style="<?= (int)$c['archived'] ? 'opacity:.5' : '' ?>">
                <td><strong><?= h($c['name']) ?></strong></td><td><?= (int)$c['ausbildungsjahr'] ?></td>
                <td><?= (int)$c['zeitgruppe'] ?></td><td class="small"><?= h($c['schuljahr']) ?></td>
                <td class="small"><?= h($c['klassenleitung']) ?></td><td><?= (int)$c['anz'] ?></td>
                <td><a class="btn sm ghost" href="<?= url('admin', ['tab' => 'klassen', 'id' => $c['id']]) ?>">&#9998;</a></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <p class="small muted" style="margin-top:.5rem">Namensschema wie an der BS FiSi: <code>1FS152</code>
            = Ausbildungsjahr, Beruf, laufende Nummer, Zeitgruppe (letzte Ziffer = Blockgruppe).
            Jahr und Zeitgruppe werden aus dem Namen erkannt, wenn du sie leer laesst.</p>
        </div>
        <div class="card">
          <h3><?= $edit ? 'Klasse bearbeiten' : 'Neue Klasse' ?></h3>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="class_save">
            <input type="hidden" name="tab" value="klassen"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="f"><label for="cn">Name</label><input id="cn" name="name" required value="<?= h($edit['name'] ?? '') ?>" placeholder="1FS152"></div>
            <div class="fgrid">
              <div class="f"><label for="cj">Ausbildungsjahr</label><input id="cj" name="ausbildungsjahr" type="number" min="0" max="4" value="<?= (int)($edit['ausbildungsjahr'] ?? 0) ?>"></div>
              <div class="f"><label for="cz">Zeitgruppe</label><input id="cz" name="zeitgruppe" type="number" min="0" max="9" value="<?= (int)($edit['zeitgruppe'] ?? 0) ?>"></div>
            </div>
            <div class="fgrid">
              <div class="f"><label for="cs">Schuljahr</label><input id="cs" name="schuljahr" value="<?= h($edit['schuljahr'] ?? '') ?>" placeholder="2026/27"></div>
              <div class="f"><label for="cr">Raum</label><input id="cr" name="raum" value="<?= h($edit['raum'] ?? '') ?>"></div>
            </div>
            <div class="f"><label for="ck">Klassenleitung</label><input id="ck" name="klassenleitung" value="<?= h($edit['klassenleitung'] ?? '') ?>"></div>
            <div class="f"><label for="ca">Archiviert</label><select id="ca" name="archived"><?= opts_simple([0 => 'nein', 1 => 'ja'], $edit['archived'] ?? 0) ?></select></div>
            <div class="row"><button class="btn pri" type="submit">Speichern</button>
              <?php if ($edit): ?><a class="btn ghost" href="<?= url('admin', ['tab' => 'klassen']) ?>">Neu</a><?php endif; ?></div>
          </form>
          <?php if ($edit): ?>
            <hr><form method="post" data-confirm="Klasse loeschen? Zuordnungen gehen verloren."><?= csrf_field() ?>
              <input type="hidden" name="action" value="class_del"><input type="hidden" name="tab" value="klassen">
              <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
              <button class="btn dan sm" type="submit">Loeschen</button></form>
          <?php endif; ?>
        </div>
      </div>

    <?php elseif ($tab === 'faecher'):
      $rows = all("SELECT s.*, c.name AS klasse FROM subjects s LEFT JOIN classes c ON c.id = s.class_id ORDER BY s.sort, s.name");
      $edit = get('id') !== '' ? one("SELECT * FROM subjects WHERE id = ?", [(int)get('id')]) : null; ?>
      <div class="split">
        <div class="card">
          <div class="row" style="justify-content:space-between;margin-bottom:.5rem">
            <h2 style="margin:0">Faecher <span class="tag"><?= count($rows) ?></span></h2>
            <form method="post" style="margin:0"><?= csrf_field() ?>
              <input type="hidden" name="action" value="subject_lf"><input type="hidden" name="tab" value="faecher">
              <button class="btn sm" type="submit">Lernfelder als Faecher anlegen</button></form>
          </div>
          <div class="tw"><table><thead><tr><th></th><th>Fach</th><th>Kurz</th><th>LF</th><th>Klasse</th><th>Lehrkraft</th><th></th></tr></thead><tbody>
            <?php foreach ($rows as $s): ?>
              <tr style="<?= (int)$s['archived'] ? 'opacity:.5' : '' ?>">
                <td><span class="dot" style="background:<?= h($s['color']) ?>"></span></td>
                <td><?= h($s['name']) ?></td><td class="small"><?= h($s['short']) ?></td>
                <td class="small"><?= $s['lf_no'] ? 'LF ' . (int)$s['lf_no'] : '' ?></td>
                <td class="small"><?= h($s['klasse'] ?: 'alle') ?></td><td class="small"><?= h($s['lehrer']) ?></td>
                <td><a class="btn sm ghost" href="<?= url('admin', ['tab' => 'faecher', 'id' => $s['id']]) ?>">&#9998;</a></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </div>
        <div class="card">
          <h3><?= $edit ? 'Fach bearbeiten' : 'Neues Fach' ?></h3>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="subject_save">
            <input type="hidden" name="tab" value="faecher"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="f"><label for="sn">Name</label><input id="sn" name="name" required value="<?= h($edit['name'] ?? '') ?>"></div>
            <div class="fgrid">
              <div class="f"><label for="ss">Kuerzel</label><input id="ss" name="short" value="<?= h($edit['short'] ?? '') ?>"></div>
              <div class="f"><label for="sc">Farbe</label><input id="sc" name="color" type="color" value="<?= h($edit['color'] ?? '#4f7cff') ?>"></div>
              <div class="f"><label for="so">Sortierung</label><input id="so" name="sort" type="number" value="<?= (int)($edit['sort'] ?? 0) ?>"></div>
            </div>
            <div class="fgrid">
              <div class="f"><label for="sl">Lernfeld</label><select id="sl" name="lf_no"><?= lf_options($edit['lf_no'] ?? null) ?></select></div>
              <div class="f"><label for="sk">Klasse</label><select id="sk" name="class_id"><?= opts($klassen, $edit['class_id'] ?? null, 'id', 'name', 'alle Klassen') ?></select></div>
            </div>
            <div class="f"><label for="sle">Lehrkraft</label><input id="sle" name="lehrer" value="<?= h($edit['lehrer'] ?? '') ?>"></div>
            <div class="f"><label for="sa">Archiviert</label><select id="sa" name="archived"><?= opts_simple([0 => 'nein', 1 => 'ja'], $edit['archived'] ?? 0) ?></select></div>
            <div class="row"><button class="btn pri" type="submit">Speichern</button>
              <?php if ($edit): ?><a class="btn ghost" href="<?= url('admin', ['tab' => 'faecher']) ?>">Neu</a><?php endif; ?></div>
          </form>
          <?php if ($edit): ?>
            <hr><form method="post" data-confirm="Fach loeschen?"><?= csrf_field() ?>
              <input type="hidden" name="action" value="subject_del"><input type="hidden" name="tab" value="faecher">
              <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
              <button class="btn dan sm" type="submit">Loeschen</button></form>
          <?php endif; ?>
        </div>
      </div>

    <?php elseif ($tab === 'kategorien'):
      $cats  = all("SELECT c.*, (SELECT COUNT(*) FROM category_rules r WHERE r.category_id = c.id) AS regeln FROM categories c ORDER BY c.sort, c.name");
      $rules = all("SELECT r.*, c.name AS kategorie FROM category_rules r JOIN categories c ON c.id = r.category_id ORDER BY r.prio DESC, r.keyword");
      $edit  = get('id') !== '' ? one("SELECT * FROM categories WHERE id = ?", [(int)get('id')]) : null; ?>
      <div class="split">
        <div>
          <div class="card">
            <h2>Kategorien <span class="tag"><?= count($cats) ?></span></h2>
            <p class="small muted">Entsprechen den Berufsbildpositionen der Ausbildungsordnung
              (A = gemeinsam, C = Fachrichtung Systemintegration, B = integrativ, X = organisatorisch).</p>
            <div class="tw"><table><thead><tr><th></th><th>Pos.</th><th>Kategorie</th><th>Regeln</th><th></th></tr></thead><tbody>
              <?php foreach ($cats as $c): ?>
                <tr style="<?= (int)$c['aktiv'] ? '' : 'opacity:.5' ?>">
                  <td><span class="dot" style="background:<?= h($c['farbe']) ?>"></span></td>
                  <td><span class="tag"><?= h($c['pos_no']) ?></span></td>
                  <td><?= h($c['name']) ?></td><td><?= (int)$c['regeln'] ?></td>
                  <td><a class="btn sm ghost" href="<?= url('admin', ['tab' => 'kategorien', 'id' => $c['id']]) ?>">&#9998;</a></td></tr>
              <?php endforeach; ?>
            </tbody></table></div>
          </div>
          <div class="card">
            <div class="row" style="justify-content:space-between;margin-bottom:.5rem">
              <h2 style="margin:0">Erkennungsregeln <span class="tag"><?= count($rules) ?></span></h2>
              <input placeholder="Filtern ..." data-filter="#rt" style="width:auto">
            </div>
            <p class="small muted">Stichwort im Taetigkeitstext &rarr; Kategorie. Beispiel:
              &bdquo;kaffeemaschine&ldquo; &rarr; Allgemeine Officetaetigkeiten. Das laengste passende
              Stichwort gewinnt.</p>
            <div class="tw scroller"><table id="rt"><thead><tr><th>Stichwort</th><th>Kategorie</th><th>Prio</th><th></th></tr></thead><tbody>
              <?php foreach ($rules as $r): ?>
                <tr><td><code><?= h($r['keyword']) ?></code></td><td class="small"><?= h($r['kategorie']) ?></td>
                  <td class="small"><?= (int)$r['prio'] ?></td>
                  <td><form method="post" style="margin:0"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="rule_del"><input type="hidden" name="tab" value="kategorien">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn sm ghost dan" type="submit">&times;</button></form></td></tr>
              <?php endforeach; ?>
            </tbody></table></div>
          </div>
        </div>
        <div>
          <div class="card">
            <h3><?= $edit ? 'Kategorie bearbeiten' : 'Neue Kategorie' ?></h3>
            <form method="post">
              <?= csrf_field() ?><input type="hidden" name="action" value="cat_save">
              <input type="hidden" name="tab" value="kategorien"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
              <div class="f"><label for="kn">Name</label><input id="kn" name="name" required value="<?= h($edit['name'] ?? '') ?>"></div>
              <div class="fgrid">
                <div class="f"><label for="ka">Abschnitt</label><select id="ka" name="abschnitt"><?= opts_simple(
                  ['A' => 'A - gemeinsam', 'B' => 'B - integrativ', 'C' => 'C - Systemintegration', 'X' => 'X - organisatorisch'], $edit['abschnitt'] ?? 'A') ?></select></div>
                <div class="f"><label for="kp">Position</label><input id="kp" name="pos_no" value="<?= h($edit['pos_no'] ?? '') ?>" placeholder="A 4"></div>
              </div>
              <div class="fgrid">
                <div class="f"><label for="kf">Farbe</label><input id="kf" name="farbe" type="color" value="<?= h($edit['farbe'] ?? '#4f7cff') ?>"></div>
                <div class="f"><label for="ks">Sortierung</label><input id="ks" name="sort" type="number" value="<?= (int)($edit['sort'] ?? 0) ?>"></div>
                <div class="f"><label for="kx">Aktiv</label><select id="kx" name="aktiv"><?= opts_simple([1 => 'ja', 0 => 'nein'], $edit['aktiv'] ?? 1) ?></select></div>
              </div>
              <div class="row"><button class="btn pri" type="submit">Speichern</button>
                <?php if ($edit): ?><a class="btn ghost" href="<?= url('admin', ['tab' => 'kategorien']) ?>">Neu</a><?php endif; ?></div>
            </form>
            <?php if ($edit): ?>
              <hr><form method="post" data-confirm="Kategorie loeschen?"><?= csrf_field() ?>
                <input type="hidden" name="action" value="cat_del"><input type="hidden" name="tab" value="kategorien">
                <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
                <button class="btn dan sm" type="submit">Loeschen</button></form>
            <?php endif; ?>
          </div>
          <div class="card">
            <h3>Neue Regel</h3>
            <form method="post">
              <?= csrf_field() ?><input type="hidden" name="action" value="rule_save"><input type="hidden" name="tab" value="kategorien">
              <div class="f"><label for="rk">Stichwort (klein)</label><input id="rk" name="keyword" required placeholder="kaffeemaschine"></div>
              <div class="f"><label for="rc">Kategorie</label><select id="rc" name="category_id" required><?= kat_options(null, '- waehlen -') ?></select></div>
              <div class="fgrid">
                <div class="f"><label for="rp">Prioritaet</label><input id="rp" name="prio" type="number" value="50"></div>
              </div>
              <div class="f"><label for="re">Standardtext (optional)</label><input id="re" name="ersetzung" placeholder="Allgemeine Officetaetigkeiten"></div>
              <button class="btn pri sm" type="submit">Regel anlegen</button>
            </form>
          </div>
        </div>
      </div>

    <?php elseif ($tab === 'einladungen'):
      $inv = all("SELECT i.*, c.name AS klasse, u.display_name AS ersteller FROM invites i
                  LEFT JOIN classes c ON c.id = i.class_id LEFT JOIN users u ON u.id = i.created_by
                  ORDER BY i.created_at DESC"); ?>
      <div class="split">
        <div class="card">
          <h2>Einladungscodes</h2>
          <p class="small muted">Registrierung ist ausschliesslich mit gueltigem Code moeglich.
            Codes werden nur als Hash gespeichert - beim Erzeugen notieren.</p>
          <?php if (!$inv): ?><?= ui_empty('Keine Codes vorhanden') ?><?php else: ?>
          <div class="tw"><table><thead><tr><th>Code</th><th>Rolle</th><th>Klasse</th><th>Nutzung</th><th>Gueltig bis</th><th>Notiz</th><th></th></tr></thead><tbody>
            <?php foreach ($inv as $i):
              $abgelaufen = $i['expires_at'] && $i['expires_at'] < date('Y-m-d H:i:s');
              $voll = (int)$i['uses'] >= (int)$i['max_uses']; ?>
              <tr style="<?= $abgelaufen || $voll ? 'opacity:.5' : '' ?>">
                <td><code><?= h($i['hint']) ?></code></td>
                <td><span class="tag"><?= h(rolle_label($i['role'])) ?></span></td>
                <td class="small"><?= h($i['klasse'] ?: '-') ?></td>
                <td><?= (int)$i['uses'] ?>/<?= (int)$i['max_uses'] ?></td>
                <td class="small"><?= $i['expires_at'] ? h(de_date($i['expires_at'])) : 'unbegrenzt' ?></td>
                <td class="small"><?= h($i['notiz']) ?></td>
                <td><form method="post" style="margin:0"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="invite_del"><input type="hidden" name="tab" value="einladungen">
                  <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                  <button class="btn sm ghost dan" type="submit">&times;</button></form></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <?php endif; ?>
        </div>
        <div class="card">
          <h3>Neuen Code erzeugen</h3>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="invite_new"><input type="hidden" name="tab" value="einladungen">
            <div class="f"><label for="ir">Rolle</label><select id="ir" name="role"><?= opts_simple(
              ['azubi' => 'Auszubildende/-r', 'ausbilder' => 'Ausbilder/-in', 'lehrer' => 'Lehrkraft', 'admin' => 'Administration'], 'azubi') ?></select></div>
            <div class="f"><label for="ic">Klasse</label><select id="ic" name="class_id"><?= opts($klassen, null, 'id', 'name', '- keine -') ?></select></div>
            <div class="fgrid">
              <div class="f"><label for="im">Max. Nutzungen</label><input id="im" name="max_uses" type="number" min="1" max="50" value="1"></div>
              <div class="f"><label for="ie">Gueltig bis</label><input id="ie" name="expires_at" type="date" value="<?= h(date('Y-m-d', strtotime('+14 days'))) ?>"></div>
            </div>
            <div class="f"><label for="in">Notiz</label><input id="in" name="notiz" placeholder="z.B. Klasse 1FS152 Start"></div>
            <button class="btn pri" type="submit">Code erzeugen</button>
          </form>
        </div>
      </div>

    <?php elseif ($tab === 'protokoll'):
      $filter = get('f');
      $log = all("SELECT * FROM audit_log" . ($filter ? " WHERE aktion LIKE ?" : "") . " ORDER BY id DESC LIMIT 400",
                 $filter ? ['%' . $filter . '%'] : []);
      $fehl = all("SELECT ident, ip, COUNT(*) c, MAX(ts) letzte FROM login_attempts
                   WHERE ok = 0 AND ts > ? GROUP BY ident, ip ORDER BY c DESC LIMIT 20", [time() - 86400]); ?>
      <div class="split">
        <div class="card">
          <div class="row" style="justify-content:space-between;margin-bottom:.5rem">
            <h2 style="margin:0">Sicherheitsprotokoll</h2>
            <form method="get" class="row" style="margin:0"><input type="hidden" name="p" value="admin">
              <input type="hidden" name="tab" value="protokoll">
              <input name="f" value="<?= h($filter) ?>" placeholder="Aktion filtern" style="width:auto">
              <button class="btn sm" type="submit">Filtern</button></form>
          </div>
          <div class="tw scroller"><table><thead><tr><th>Zeit</th><th>Person</th><th>IP</th><th>Aktion</th><th>Ziel</th></tr></thead><tbody>
            <?php foreach ($log as $l): ?>
              <tr><td class="small" style="white-space:nowrap"><?= h(de_date($l['ts'], 'd.m. H:i:s')) ?></td>
                <td class="small"><?= h($l['actor']) ?></td><td class="small"><?= h($l['ip']) ?></td>
                <td><span class="tag <?= str_contains($l['aktion'], 'fehl') || str_contains($l['aktion'], 'verweigert') || str_contains($l['aktion'], 'csrf') ? 'err' : '' ?>"><?= h($l['aktion']) ?></span></td>
                <td class="small"><?= h($l['ziel']) ?></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <form method="post" style="margin-top:.6rem" data-confirm="Protokolleintraege aelter als 180 Tage loeschen?">
            <?= csrf_field() ?><input type="hidden" name="action" value="purge_audit"><input type="hidden" name="tab" value="protokoll">
            <button class="btn sm" type="submit">Alteintraege aufraeumen</button></form>
        </div>
        <div class="card">
          <h3>Fehlversuche (24 h)</h3>
          <?php if (!$fehl): ?><p class="small muted">Keine.</p><?php else: ?>
          <div class="tw"><table><thead><tr><th>Kennung</th><th>IP</th><th>Anzahl</th></tr></thead><tbody>
            <?php foreach ($fehl as $f): ?>
              <tr><td class="small"><?= h($f['ident']) ?></td><td class="small"><?= h($f['ip']) ?></td>
                <td><span class="tag <?= (int)$f['c'] > 5 ? 'err' : 'warn' ?>"><?= (int)$f['c'] ?></span></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <?php endif; ?>
        </div>
      </div>

    <?php else: ?>
      <div class="grid g2">
        <div class="card">
          <h2>Einstellungen</h2>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="settings"><input type="hidden" name="tab" value="einstellungen">
            <div class="f"><label for="es">Schule</label><input id="es" name="schule" value="<?= h(setting('schule')) ?>"></div>
            <div class="f"><label for="ek">Kuerzel</label><input id="ek" name="schule_kurz" value="<?= h(setting('schule_kurz')) ?>"></div>
            <div class="f"><label for="eb">Berichtsheft-Rhythmus</label><select id="eb" name="berichtsheft_art"><?= opts_simple(
              ['woche' => 'woechentlich', 'monat' => 'monatlich'], setting('berichtsheft_art', 'woche')) ?></select></div>
            <div class="f"><label for="ei">Impressum / Hinweis</label><textarea id="ei" name="impressum" style="min-height:70px"><?= h(setting('impressum')) ?></textarea></div>
            <button class="btn pri" type="submit">Speichern</button>
          </form>
        </div>
        <div class="card">
          <h2>Verzeichnisschutz</h2>
          <?php $imWebroot = str_starts_with(str_replace('\\', '/', DATA_DIR), str_replace('\\', '/', __DIR__) . '/'); ?>
          <p class="small muted">Das Datenverzeichnis enthaelt Datenbank, Sitzungen und Anhaenge.
            Es darf vom Webserver niemals ausgeliefert werden.</p>
          <p class="small"><code><?= h(DATA_DIR) ?></code></p>
          <?php if ($imWebroot): ?>
            <div class="msg warn"><span>Das Datenverzeichnis liegt <strong>innerhalb</strong> des
              Web-Verzeichnisses. Der Caddyfile-Block unten muss zwingend aktiv sein.</span></div>
            <button class="btn" type="button" id="canarybtn">Jetzt pruefen</button>
            <div id="canaryout" style="margin-top:.6rem"></div>
          <?php else: ?>
            <div class="msg ok"><span>Das Datenverzeichnis liegt <strong>ausserhalb</strong> des
              Web-Verzeichnisses und ist damit grundsaetzlich nicht abrufbar.</span></div>
          <?php endif; ?>
          <hr>
          <h3>Passender Caddyfile-Block</h3>
          <button class="btn sm copybtn" data-copy="caddycfg" type="button">kopieren</button>
          <pre id="caddycfg"><?= h(caddy_snippet()) ?></pre>
        </div>
        <div class="card">
          <h2>Datensicherung</h2>
          <p class="small muted">Sichere regelmaessig das Verzeichnis <code><?= h(DATA_DIR) ?></code>
            (SQLite-Datei inkl. <code>-wal</code>). Ein JSON-Komplettexport steht hier bereit.</p>
          <ul class="list">
            <li><a href="<?= url('export', ['was' => 'backup']) ?>">Komplette Datenbank als JSON exportieren</a></li>
          </ul>
          <hr>
          <h3>System</h3>
          <table><tbody>
            <tr><th>PHP</th><td><?= h(PHP_VERSION) ?></td></tr>
            <tr><th>SQLite</th><td><?= h((string)db()->getAttribute(PDO::ATTR_SERVER_VERSION)) ?></td></tr>
            <tr><th>Passwort-Hash</th><td><?= defined('PASSWORD_ARGON2ID') ? 'Argon2id' : 'bcrypt' ?></td></tr>
            <tr><th>Verbindung</th><td><?= is_https() ? '<span class="tag ok">HTTPS</span>' : '<span class="tag err">unverschluesselt</span>' ?></td></tr>
            <tr><th>Benutzer</th><td><?= (int)val("SELECT COUNT(*) FROM users", [], 0) ?></td></tr>
            <tr><th>Nachweise</th><td><?= (int)val("SELECT COUNT(*) FROM reports", [], 0) ?></td></tr>
            <tr><th>Dateien</th><td><?= (int)val("SELECT COUNT(*) FROM files", [], 0) ?> (<?= num((float)val("SELECT COALESCE(SUM(groesse),0) FROM files", [], 0) / 1048576, 1) ?> MB)</td></tr>
          </tbody></table>
          <?php if (!is_https()): ?>
            <div class="msg err" style="margin-top:.6rem">Die Seite laeuft ohne HTTPS. Passwoerter und
              Sitzungscookies sind dadurch angreifbar - bitte in Caddy eine Domain mit TLS einrichten.</div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
    <script nonce="<?= h($GLOBALS['CSP_NONCE']) ?>">
    (function(){
      var b = document.getElementById('canarybtn'); if (!b) return;
      b.addEventListener('click', function () {
        var out = document.getElementById('canaryout');
        out.innerHTML = '<span class="small muted">pruefe ...</span>';
        fetch(<?= json_encode(base_path() . basename(DATA_DIR) . '/canary.txt') ?>, { cache: 'no-store' })
          .then(function (r) { return r.text().then(function (t) { return { ok: r.ok, status: r.status, t: t }; }); })
          .then(function (r) {
            if (r.ok && r.t.indexOf('DATENVERZEICHNIS') === 0) {
              out.innerHTML = '<div class="msg err"><span><strong>Ungeschuetzt!</strong> Das Verzeichnis '
                + 'data/ wird ausgeliefert. Bitte sofort den Caddyfile-Block unten uebernehmen und neu laden.</span></div>';
            } else {
              out.innerHTML = '<div class="msg ok"><span>Geschuetzt (HTTP ' + r.status + ').</span></div>';
            }
          })
          .catch(function () {
            out.innerHTML = '<div class="msg ok"><span>Geschuetzt (Anfrage wurde blockiert).</span></div>';
          });
      });
    })();
    </script>
    <?php
    render_page('Verwaltung', ob_get_clean());
}

function caddy_snippet(): string {
    $host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? APP_DOMAIN));
    $root = str_replace('\\', '/', __DIR__);
    return $host . " {\n"
        . "    root * " . $root . "\n"
        . "    encode gzip zstd\n\n"
        . "    # Datenverzeichnis niemals ausliefern\n"
        . "    @data path /data /data/*\n"
        . "    respond @data 404\n\n"
        . "    php_fastcgi 127.0.0.1:9000\n"
        . "    file_server\n\n"
        . "    header {\n"
        . "        Strict-Transport-Security \"max-age=31536000; includeSubDomains\"\n"
        . "        -Server\n"
        . "    }\n"
        . "}\n";
}

// ===========================================================================
// 29. EXPORT & KALENDER
// ===========================================================================

function csv_out(string $name, array $header, array $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '.csv"');
    header('Cache-Control: private, no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                 // BOM fuer Excel
    fputcsv($out, $header, ';', '"', '\\');
    foreach ($rows as $r) fputcsv($out, $r, ';', '"', '\\');
    fclose($out);
    exit;
}
function action_export(): void {
    $u   = require_login();
    $uid = (int)$u['id'];
    $was = get('was', 'alles');
    if ($was === 'backup') {
        require_role('admin');
        $dump = [];
        foreach (['users','classes','subjects','lernfelder','events','notes','grades','tasks','categories',
                  'category_rules','reports','report_entries','routines','routine_logs','timetable',
                  'blockweeks','absences','invites','settings','audit_log'] as $t) {
            $rows = all("SELECT * FROM $t");
            if ($t === 'users') foreach ($rows as &$r) { unset($r['pass_hash'], $r['totp_secret'], $r['recovery_codes']); }
            unset($r);
            $dump[$t] = $rows;
        }
        audit('backup_export');
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="bsfisazubi-backup-' . date('Ymd-His') . '.json"');
        echo json_encode(['exportiert' => date('c'), 'version' => APP_VERSION, 'daten' => $dump],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($was === 'noten') {
        csv_out('noten', ['Datum','Fach','Art','Titel','Skala','Wert','Note','Gewicht','Halbjahr','Bemerkung'],
            array_map(fn($g) => [$g['datum'], $g['fach'] ?: $g['fach_text'], $g['art'], $g['titel'], $g['skala'],
                $g['wert'], num((float)note_to_points((float)$g['wert'], $g['skala']), 2), $g['gewicht'], $g['halbjahr'], $g['bemerkung']],
                all("SELECT g.*, s.name AS fach FROM grades g LEFT JOIN subjects s ON s.id = g.subject_id
                     WHERE g.user_id = ? ORDER BY g.datum", [$uid])));
    }
    if ($was === 'berichtsheft' || $was === 'bericht') {
        $sql = "SELECT e.*, r.periode, r.art, r.nr, r.status, c.name AS kategorie, c.pos_no
                FROM report_entries e JOIN reports r ON r.id = e.report_id
                LEFT JOIN categories c ON c.id = e.category_id WHERE e.user_id = ?";
        $args = [$uid];
        if ($was === 'bericht' && get('periode')) { $sql .= " AND r.periode = ? AND r.art = ?"; $args[] = get('periode'); $args[] = get('art') ?: 'woche'; }
        $sql .= " ORDER BY e.datum, e.id";
        csv_out('berichtsheft', ['Nachweis','Periode','Datum','Stunden','Ort','Taetigkeit','Position','Kategorie','Lernfeld','Status'],
            array_map(fn($e) => [$e['nr'], $e['periode'], $e['datum'], $e['stunden'], $e['ort'], $e['text'],
                $e['pos_no'], $e['kategorie'], $e['lf_no'], $e['status']], all($sql, $args)));
    }
    if ($was === 'routinen') {
        csv_out('routinen', ['Datum','Zeit','Aufgabe','Minuten','Notiz'],
            array_map(fn($l) => [$l['datum'], $l['zeit'], $l['name'], $l['minuten'], $l['notiz']],
                all("SELECT l.*, r.name FROM routine_logs l JOIN routines r ON r.id = l.routine_id
                     WHERE l.user_id = ? ORDER BY l.datum DESC", [$uid])));
    }
    if ($was === 'notizen') {
        csv_out('notizen', ['Datum','Art','Titel','Fach','Lernfeld','Tags','Sichtbarkeit','Inhalt'],
            array_map(fn($n) => [$n['datum'], $n['kind'], $n['titel'], $n['fach'], $n['lf_no'], $n['tags'], $n['visibility'], $n['body']],
                all("SELECT n.*, s.name AS fach FROM notes n LEFT JOIN subjects s ON s.id = n.subject_id
                     WHERE n.user_id = ? ORDER BY n.datum DESC", [$uid])));
    }
    // Komplettexport der eigenen Daten
    $daten = [
        'profil'        => array_diff_key($u, array_flip(['pass_hash','totp_secret','recovery_codes'])),
        'noten'         => all("SELECT * FROM grades WHERE user_id = ?", [$uid]),
        'notizen'       => all("SELECT * FROM notes WHERE user_id = ?", [$uid]),
        'aufgaben'      => all("SELECT * FROM tasks WHERE user_id = ?", [$uid]),
        'termine'       => all("SELECT * FROM events WHERE user_id = ?", [$uid]),
        'nachweise'     => all("SELECT * FROM reports WHERE user_id = ?", [$uid]),
        'nachweiszeilen'=> all("SELECT * FROM report_entries WHERE user_id = ?", [$uid]),
        'routinelogs'   => all("SELECT * FROM routine_logs WHERE user_id = ?", [$uid]),
        'abwesenheiten' => all("SELECT * FROM absences WHERE user_id = ?", [$uid]),
    ];
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="meine-daten-' . date('Ymd') . '.json"');
    echo json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function action_ics(): void {
    $t = get('t');
    if (strlen($t) < 20) { http_response_code(404); exit('Nicht gefunden.'); }
    if (!rl_hit('ics:' . client_ip(), 120, 3600)) { http_response_code(429); exit('Zu viele Anfragen.'); }
    $u = one("SELECT * FROM users WHERE ics_token = ? AND active = 1", [$t]);
    if (!$u) { http_response_code(404); exit('Nicht gefunden.'); }
    $cid = (int)($u['class_id'] ?: 0);
    $rows = all("SELECT e.*, s.name AS fach FROM events e LEFT JOIN subjects s ON s.id = e.subject_id
                 WHERE ((e.visibility='class' AND e.class_id = ?) OR e.user_id = ?)
                   AND e.datum >= date('now','localtime','-180 day')", [$cid, (int)$u['id']]);
    $esc = fn($s) => addcslashes(str_replace(["\r\n", "\n"], '\\n', (string)$s), ",;\\");
    $ics = ["BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//" . APP_DOMAIN . "//Azubi-Portal//DE",
            "CALSCALE:GREGORIAN", "METHOD:PUBLISH", "X-WR-CALNAME:" . $esc(APP_SHORT . ' Termine')];
    foreach ($rows as $e) {
        $d = str_replace('-', '', $e['datum']);
        $ics[] = "BEGIN:VEVENT";
        $ics[] = "UID:ev" . (int)$e['id'] . "@" . APP_DOMAIN;
        $ics[] = "DTSTAMP:" . gmdate('Ymd\THis\Z');
        if ($e['zeit_von']) {
            $ics[] = "DTSTART;TZID=Europe/Berlin:" . $d . 'T' . str_replace(':', '', $e['zeit_von']) . '00';
            $ics[] = "DTEND;TZID=Europe/Berlin:" . $d . 'T' . str_replace(':', '', $e['zeit_bis'] ?: $e['zeit_von']) . '00';
        } else {
            $ics[] = "DTSTART;VALUE=DATE:" . $d;
            $ics[] = "DTEND;VALUE=DATE:" . date('Ymd', strtotime($e['datum'] . ' +1 day'));
        }
        $ics[] = "SUMMARY:" . $esc('[' . typ_label($e['typ']) . '] ' . $e['titel']);
        $besch = trim($e['beschreibung'] . ($e['stoff'] ? "\n" . $e['stoff'] : ''));
        if ($besch) $ics[] = "DESCRIPTION:" . $esc($besch);
        if ($e['raum']) $ics[] = "LOCATION:" . $esc($e['raum']);
        $ics[] = "END:VEVENT";
    }
    $ics[] = "END:VCALENDAR";
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="termine.ics"');
    header_remove('Content-Security-Policy');
    echo implode("\r\n", $ics) . "\r\n";
    exit;
}

// ===========================================================================
// 30. ROUTER
// ===========================================================================

$p = $_GET['p'] ?? 'dashboard';
if (!is_string($p)) $p = 'dashboard';

// Oeffentliche Endpunkte (ohne Anmeldung)
if ($p === 'ics') { action_ics(); }

if (setup_needed() && !in_array($p, ['setup'], true)) redirect(url('setup'));

session_touch_or_kill();
gc_maybe();

// Erzwungener Passwortwechsel
$cu = current_user();
if ($cu && (int)$cu['must_change_pw'] === 1 && !in_array($p, ['profil', 'logout', 'theme'], true)) {
    flash('Bitte vergib zuerst ein eigenes Passwort.', 'warn');
    redirect(url('profil', ['tab' => 'sicherheit']));
}

// Schreibende Aktionen nur per POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !rl_hit('post:' . client_ip(), 400, 600)) {
    http_response_code(429);
    exit('Zu viele Anfragen. Bitte kurz warten.');
}

switch ($p) {
    case 'setup':             page_setup(); break;
    case 'login':             page_login(); break;
    case 'registrieren':      page_registrieren(); break;
    case 'logout':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_check(); audit('logout'); }
        logout(); break;
    case 'logout_abbruch':    unset($_SESSION['2fa_uid']); redirect(url('login'));
    case 'theme':
        require_login(); csrf_check();
        $th = in_array(post('theme'), ['auto', 'hell', 'dunkel'], true) ? post('theme') : 'auto';
        upd('users', ['theme' => $th], 'id = :id', ['id' => (int)current_user()['id']]);
        redirect($_SERVER['HTTP_REFERER'] && str_starts_with((string)$_SERVER['HTTP_REFERER'], abs_url('/')) ? $_SERVER['HTTP_REFERER'] : url('dashboard'));
    case 'quickadd':          action_quickadd(); break;
    case 'datei':             action_datei(); break;
    case 'export':            action_export(); break;
    case 'dashboard':         page_dashboard(); break;
    case 'woche':             page_woche(); break;
    case 'termine':           page_termine(); break;
    case 'aufgaben':          page_aufgaben(); break;
    case 'notizen':           page_notizen(); break;
    case 'wissen':            page_wissen(); break;
    case 'suche':             page_suche(); break;
    case 'noten':             page_noten(); break;
    case 'berichtsheft':      page_berichtsheft(); break;
    case 'berichtsheft_liste':page_berichtsheft_liste(); break;
    case 'betrieb':           page_betrieb(); break;
    case 'abwesenheit':       page_abwesenheit(); break;
    case 'lernfelder':        page_lernfelder(); break;
    case 'pruefung':          page_pruefung(); break;
    case 'profil':            page_profil(); break;
    case 'klasse':            page_klasse(); break;
    case 'pruefen':           page_pruefen(); break;
    case 'admin':             page_admin(); break;
    default:
        http_response_code(404);
        require_login();
        render_page('Nicht gefunden', '<div class="card"><h2>Seite nicht gefunden</h2>'
            . '<p class="muted">Die aufgerufene Adresse gibt es nicht.</p>'
            . '<a class="btn pri" href="' . url('dashboard') . '">Zum Start</a></div>');
}
