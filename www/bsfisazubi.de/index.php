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
    if ((int)($pdo->query("SELECT v FROM meta WHERE k='schema'")->fetchColumn() ?: 0) >= 2) return;
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

/** Legt fuer ein neues Konto Faecher und die ueblichen Routinen an. */
function seed_user(int $uid): void {
    $farben = ['#2563eb','#0d9488','#7c3aed','#ea580c','#0891b2','#dc2626'];
    foreach (all("SELECT * FROM lernfelder ORDER BY nr") as $i => $l) {
        ins('subjects', ['user_id' => $uid, 'name' => $l['code'] . ' ' . $l['titel'],
            'short' => $l['code'], 'lf_no' => (int)$l['nr'], 'sort' => (int)$l['nr'] * 10,
            'color' => $farben[$i % 6]]);
    }
    foreach (['Deutsch', 'Englisch', 'Politik und Gesellschaft', 'Religion / Ethik', 'Sport'] as $i => $n) {
        ins('subjects', ['user_id' => $uid, 'name' => $n, 'short' => mb_substr($n, 0, 2),
            'sort' => 200 + $i, 'color' => '#64748b']);
    }
    $kat = fn(string $n) => (int)val("SELECT id FROM categories WHERE name = ?", [$n], 0) ?: null;
    foreach ([
        ['Kaffeemaschine reinigen', 'taeglich', 'Allgemeine Officetaetigkeiten', 10],
        ['Spuelmaschine ein-/ausraeumen', 'taeglich', 'Allgemeine Officetaetigkeiten', 10],
        ['Post holen und verteilen', 'taeglich', 'Allgemeine Officetaetigkeiten', 15],
        ['Ticketqueue sichten', 'taeglich', 'Leistungserbringung und Auftragsabschluss', 30],
        ['Backup-Protokoll pruefen', 'taeglich', 'Speicherloesungen in Betrieb nehmen', 15],
        ['Monitoring durchsehen', 'taeglich', 'IT-Systeme betreiben', 15],
        ['Drucker: Papier und Toner', 'woechentlich', 'Allgemeine Officetaetigkeiten', 15],
        ['Serverraum-Check', 'woechentlich', 'IT-Systeme betreiben', 15],
        ['Patchstand pruefen', 'woechentlich', 'IT-Systeme betreiben', 30],
        ['Lager inventarisieren', 'monatlich', 'Leistungserbringung und Auftragsabschluss', 45],
    ] as $i => [$n, $iv, $k, $m]) {
        ins('routines', ['user_id' => $uid, 'name' => $n, 'intervall' => $iv,
            'category_id' => $kat($k), 'minuten' => $m, 'sort' => $i * 10]);
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
function post(string $k, string $d = ''): string { return is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : $d; }
function postn(string $k) { $v = $_POST[$k] ?? null; return ($v === null || $v === '') ? null : $v; }
function get(string $k, string $d = ''): string { return is_string($_GET[$k] ?? null) ? trim($_GET[$k]) : $d; }
function inull($v): ?int { return ($v === null || $v === '' || $v === '0') ? null : (int)$v; }
function today(): string { return date('Y-m-d'); }
function isodate(string $s): bool { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s); }
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
    if (!isset($_POST['_csrf']) || !is_string($_POST['_csrf'])
        || !hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'])) {
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
function pw_problems(string $pw, string $user = '', string $name = ''): array {
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
    if ($name) foreach (preg_split('/\s+/', $name) as $t) {
        if (mb_strlen($t) >= 4 && mb_stripos($pw, $t) !== false) { $p[] = 'nicht der eigene Name'; break; }
    }
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
    return (bool)preg_match($art === 'monat' ? '/^\d{4}-(0[1-9]|1[0-2])$/' : '/^\d{4}-W(0[1-9]|[1-4]\d|5[0-3])$/', $p);
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
function lehrjahr(array $u, string $datum): int {
    if (empty($u['start'])) return 1;
    try { $s = new DateTimeImmutable($u['start']); $d = new DateTimeImmutable($datum); }
    catch (Throwable $e) { return 1; }
    return $d < $s ? 1 : min(4, (int)floor($s->diff($d)->days / 365) + 1);
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
function report_ensure(int $uid, string $art, string $periode): array {
    $r = one("SELECT * FROM reports WHERE user_id = ? AND art = ? AND periode = ?", [$uid, $art, $periode]);
    if ($r) return $r;
    [$von, $bis] = periode_range($periode, $art);
    $u = one("SELECT * FROM users WHERE id = ?", [$uid]) ?? [];
    $id = ins('reports', ['user_id' => $uid, 'art' => $art, 'periode' => $periode, 'von' => $von,
        'bis' => $bis, 'jahr' => lehrjahr($u, $von), 'abteilung' => $u['abteilung'] ?? '']);
    return one("SELECT * FROM reports WHERE id = ?", [$id]);
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
//  Oberflaeche
// ===========================================================================

function nav(): array {
    return [
        ['heute',       'Heute'],
        ['termine',     'Termine'],
        ['aufgaben',    'Aufgaben'],
        ['notizen',     'Notizen'],
        ['noten',       'Noten'],
        ['berichtsheft','Berichtsheft'],
        ['routinen',    'Routinen'],
        ['pruefung',    'Pruefung'],
    ];
}
function nav_zahl(string $key): string {
    $u = me(); if (!$u) return '';
    static $c = null;
    if ($c === null) {
        $uid = (int)$u['id'];
        $c = [
            'aufgaben' => (int)val("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status='offen'
                                    AND faellig IS NOT NULL AND faellig <= date('now','localtime','+2 day')", [$uid], 0),
            'termine'  => (int)val("SELECT COUNT(*) FROM events WHERE user_id = ?
                                    AND datum BETWEEN date('now','localtime') AND date('now','localtime','+7 day')", [$uid], 0),
        ];
    }
    return ($c[$key] ?? 0) > 0 ? (string)$c[$key] : '';
}

function page(string $titel, string $inhalt, array $o = []): void {
    $u = me(); $n = $GLOBALS['NONCE']; $p = $_GET['p'] ?? 'heute';
    $theme = $u['theme'] ?? 'auto';
    $flash = take_flash();
    $bare = !empty($o['bare']);
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html lang="de" data-t="<?= h($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= h($titel) ?> – <?= h(APP_NAME) ?></title>
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="6" fill="#2563eb"/><path d="M9 9h14M9 16h10M9 23h6" stroke="#fff" stroke-width="2.6" stroke-linecap="round"/></svg>') ?>">
<style>
:root{--bg:#f7f8fa;--pa:#fff;--pa2:#f1f3f6;--fg:#15171c;--fg2:#5c626c;--fg3:#8b919b;
--li:#e3e6ea;--li2:#cfd4db;--ac:#2563eb;--acb:#eaf0ff;--ok:#15803d;--okb:#e8f5ec;
--wa:#a16207;--wab:#fdf6e3;--er:#b91c1c;--erb:#fdeceb;--r:6px;
--mo:ui-monospace,"Cascadia Mono","Segoe UI Mono",Consolas,monospace}
@media(prefers-color-scheme:dark){:root:not([data-t=hell]){--bg:#0c0d10;--pa:#131519;--pa2:#1a1d22;
--fg:#e7e9ec;--fg2:#9aa0aa;--fg3:#6c727c;--li:#242830;--li2:#343a44;--ac:#6d9aff;--acb:#182136;
--ok:#4ade80;--okb:#122419;--wa:#e3b341;--wab:#241f10;--er:#ff7b72;--erb:#2a1414}}
:root[data-t=dunkel]{--bg:#0c0d10;--pa:#131519;--pa2:#1a1d22;--fg:#e7e9ec;--fg2:#9aa0aa;--fg3:#6c727c;
--li:#242830;--li2:#343a44;--ac:#6d9aff;--acb:#182136;--ok:#4ade80;--okb:#122419;
--wa:#e3b341;--wab:#241f10;--er:#ff7b72;--erb:#2a1414}
*,*::before,*::after{box-sizing:border-box}
html,body{margin:0;padding:0}
body{background:var(--bg);color:var(--fg);font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,system-ui,sans-serif;
font-variant-numeric:tabular-nums;-webkit-text-size-adjust:100%}
a{color:var(--ac);text-decoration:none}a:hover{text-decoration:underline}
h1,h2,h3{margin:0;font-weight:600;line-height:1.3}
h1{font-size:15px}h2{font-size:13px;color:var(--fg2);text-transform:uppercase;letter-spacing:.05em}
h3{font-size:13px}
p{margin:0 0 8px}
code,pre,.mo{font-family:var(--mo);font-size:12.5px}
pre{background:var(--pa2);border:1px solid var(--li);border-radius:var(--r);padding:10px;overflow:auto;margin:6px 0}
kbd{font:11px var(--mo);background:var(--pa2);border:1px solid var(--li2);border-radius:4px;padding:1px 4px;color:var(--fg2)}
hr{border:0;border-top:1px solid var(--li);margin:14px 0}
.mu{color:var(--fg2)}.mu2{color:var(--fg3)}.sm{font-size:12.5px}
/* Rahmen */
.app{display:grid;grid-template-columns:206px 1fr;min-height:100vh}
.sb{background:var(--pa);border-right:1px solid var(--li);position:sticky;top:0;height:100vh;
display:flex;flex-direction:column;overflow:auto}
.bd{display:flex;align-items:center;gap:8px;padding:14px 14px 10px;font-weight:600;font-size:14px}
.bd i{width:22px;height:22px;border-radius:5px;background:var(--ac);display:block;flex:none}
.nv{padding:2px 8px;display:flex;flex-direction:column;gap:1px}
.nv a{display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:5px;color:var(--fg2);font-size:13.5px}
.nv a:hover{background:var(--pa2);color:var(--fg);text-decoration:none}
.nv a.on{background:var(--acb);color:var(--ac);font-weight:600}
.nv .k{margin-left:auto;font:11px var(--mo);color:var(--fg3);opacity:.55}
.nv a.on .k{opacity:.8;color:var(--ac)}
.nv .b{margin-left:auto;background:var(--ac);color:#fff;border-radius:9px;font-size:11px;
font-weight:600;padding:0 5px;min-width:17px;text-align:center}
.nv a.on .b{background:var(--ac)}
.sf{margin-top:auto;border-top:1px solid var(--li);padding:10px 14px;font-size:12.5px;color:var(--fg3)}
.sf b{display:block;color:var(--fg);font-weight:600;font-size:13px}
.sf .lk{display:flex;gap:10px;margin-top:6px}
.mn{min-width:0;display:flex;flex-direction:column}
.tb{position:sticky;top:0;z-index:20;background:var(--pa);border-bottom:1px solid var(--li);
display:flex;align-items:center;gap:10px;padding:0 16px;height:46px}
.tb .sp{flex:1}
.tb h1{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tb input[type=search]{width:190px;height:28px;font-size:13px}
.ct{padding:16px;max-width:1180px;width:100%}
@media(max-width:880px){
 .app{grid-template-columns:1fr}
 .sb{position:fixed;left:0;top:0;bottom:0;width:230px;z-index:60;transform:translateX(-101%);
 transition:transform .15s ease;box-shadow:0 0 0 100vmax rgba(0,0,0,0)}
 body.nav .sb{transform:none}
 body.nav .sc{display:block;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:50}
 .ct{padding:12px}.tb input[type=search]{width:110px}
}
@media(max-width:430px){.tb{padding:0 8px;gap:5px}.tb input[type=search]{width:76px}.tb h1{font-size:14px}}
.sc{display:none}
@media(min-width:881px){.tb .bg{display:none}}
/* Bausteine */
.c{background:var(--pa);border:1px solid var(--li);border-radius:var(--r);margin-bottom:12px;min-width:0;max-width:100%}
.c>.hd{display:flex;align-items:center;gap:8px;padding:9px 12px;border-bottom:1px solid var(--li);flex-wrap:wrap}
.c>.hd h2,.c>.hd h3{margin:0}.c>.hd .sp{flex:1}
.c>.bo{padding:12px}
.c>.bo.p0{padding:0}
.g{display:grid;gap:12px}.g>*{min-width:0}
.g2{grid-template-columns:repeat(auto-fit,minmax(280px,1fr))}
.g3{grid-template-columns:repeat(auto-fit,minmax(200px,1fr))}
.sp2{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:12px;align-items:start}
.sp2>*{min-width:0}
@media(max-width:1000px){.sp2{grid-template-columns:1fr}}
.rw{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.line{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.line>*{min-width:0}
.line>input[name=text]{flex:1 1 100%}
@media(min-width:760px){.line{flex-wrap:nowrap}.line>input[name=text]{flex:1 1 auto}}
.st{display:flex;flex-direction:column;gap:6px}
button,.bt{display:inline-flex;align-items:center;gap:5px;height:30px;padding:0 10px;font:inherit;
font-size:13px;font-weight:500;background:var(--pa);color:var(--fg);border:1px solid var(--li2);
border-radius:5px;cursor:pointer;white-space:nowrap}
.bt:hover,button:hover{background:var(--pa2);text-decoration:none}
.bt.p,button.p{background:var(--ac);border-color:var(--ac);color:#fff}
.bt.p:hover,button.p:hover{filter:brightness(1.1)}
.bt.d,button.d{color:var(--er);border-color:var(--li2)}
.bt.d:hover,button.d:hover{background:var(--erb)}
.bt.s,button.s{height:24px;padding:0 7px;font-size:12.5px}
.bt.g,button.g{border-color:transparent;background:transparent}
.bt.g:hover,button.g:hover{background:var(--pa2)}
button[disabled]{opacity:.45;cursor:not-allowed}
label{display:block;font-size:12px;font-weight:500;color:var(--fg2);margin-bottom:3px}
input,select,textarea{width:100%;height:30px;background:var(--pa);color:var(--fg);border:1px solid var(--li2);
border-radius:5px;padding:0 8px;font:inherit;font-size:13.5px}
textarea{height:auto;min-height:78px;padding:6px 8px;line-height:1.5;resize:vertical}
input:focus,select:focus,textarea:focus{outline:2px solid var(--ac);outline-offset:-1px;border-color:var(--ac)}
input[type=checkbox]{width:auto;height:auto;accent-color:var(--ac)}
input[type=color]{padding:2px}
input:disabled,textarea:disabled,select:disabled{background:var(--pa2);color:var(--fg3);cursor:not-allowed}
.f{margin-bottom:8px}
.fg{display:grid;gap:8px;grid-template-columns:repeat(auto-fit,minmax(150px,1fr))}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{text-align:left;padding:6px 10px;border-bottom:1px solid var(--li);vertical-align:top}
th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--fg3);font-weight:600;white-space:nowrap}
tbody tr:last-child td{border-bottom:0}
tbody tr:hover{background:var(--pa2)}
td.n,th.n{text-align:right;font-family:var(--mo);font-size:12.5px}
.tw{overflow-x:auto;max-width:100%}
.tg{display:inline-block;background:var(--pa2);border:1px solid var(--li);border-radius:4px;
padding:0 5px;font-size:11.5px;font-weight:500;color:var(--fg2);line-height:17px;white-space:nowrap}
.tg.a{background:var(--acb);color:var(--ac);border-color:transparent}
.tg.o{background:var(--okb);color:var(--ok);border-color:transparent}
.tg.w{background:var(--wab);color:var(--wa);border-color:transparent}
.tg.e{background:var(--erb);color:var(--er);border-color:transparent}
.ms{border-radius:var(--r);padding:8px 11px;margin-bottom:10px;font-size:13px}
.ms.ok{background:var(--okb);color:var(--ok)}
.ms.warn{background:var(--wab);color:var(--wa)}
.ms.err{background:var(--erb);color:var(--er)}
.ms.info{background:var(--acb);color:var(--ac)}
.li{list-style:none;margin:0;padding:0}
.li li{display:flex;gap:8px;align-items:baseline;padding:6px 12px;border-bottom:1px solid var(--li)}
.li li:last-child{border-bottom:0}
.li li:hover{background:var(--pa2)}
.em{padding:18px 12px;color:var(--fg3);font-size:13px}
.br{height:5px;background:var(--pa2);border-radius:3px;overflow:hidden}
.br>i{display:block;height:100%;background:var(--ac)}
.dot{width:7px;height:7px;border-radius:4px;display:inline-block;flex:none}
.nt{display:inline-block;min-width:26px;text-align:center;border-radius:4px;padding:1px 5px;
font-family:var(--mo);font-size:12px;font-weight:600;color:#fff}
.ch{display:flex;gap:5px;flex-wrap:wrap}
.ch a{border:1px solid var(--li);border-radius:20px;padding:2px 9px;font-size:12.5px;color:var(--fg2)}
.ch a:hover{background:var(--pa2);text-decoration:none}
.ch a.on{background:var(--ac);border-color:var(--ac);color:#fff;font-weight:500}
/* Stundenplan */
.tt{display:grid;grid-template-columns:26px repeat(5,1fr);gap:2px;font-size:12px}
.tt .h{font-size:11px;color:var(--fg3);text-transform:uppercase;text-align:center;font-weight:600}
.tt .s{color:var(--fg3);text-align:right;padding-right:3px;font-size:11px;line-height:26px}
.tt .c2{background:var(--pa2);border-radius:4px;padding:3px 5px;min-height:26px;border-left:2px solid transparent}
/* Kalender */
.cal{display:grid;grid-template-columns:repeat(7,1fr);gap:2px}
.cal .h{font-size:11px;color:var(--fg3);text-align:center;font-weight:600;text-transform:uppercase;padding:2px}
.cal .d{background:var(--pa2);border-radius:4px;min-height:58px;padding:3px 4px;font-size:11.5px;border:1px solid transparent}
.cal .d.o{opacity:.35}.cal .d.t{border-color:var(--ac)}
.cal .d b{font-weight:600;color:var(--fg2);font-size:11px}
.cal .e{display:block;border-radius:3px;padding:0 3px;margin-top:2px;font-size:10.5px;color:#fff;
overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
/* Palette */
.pl{position:fixed;inset:0;background:rgba(8,10,14,.45);z-index:100;display:none;padding-top:14vh}
.pl.on{display:block}
.pl .bx{max-width:520px;margin:0 auto;background:var(--pa);border:1px solid var(--li2);
border-radius:8px;box-shadow:0 12px 40px rgba(0,0,0,.28);overflow:hidden}
.pl input{height:42px;border:0;border-bottom:1px solid var(--li);border-radius:0;font-size:15px;padding:0 14px}
.pl input:focus{outline:none}
.pl ul{list-style:none;margin:0;padding:4px;max-height:44vh;overflow:auto}
.pl li{padding:7px 10px;border-radius:5px;cursor:pointer;font-size:13.5px;display:flex;gap:8px}
.pl li.on{background:var(--acb);color:var(--ac)}
@media print{.sb,.tb,.np,.pl{display:none!important}.tw{overflow:visible}.app{display:block}.ct{padding:0;max-width:none}
.c{border:0;margin:0 0 10px}.c>.hd{border:0;padding:0 0 4px}.c>.bo{padding:0}
body{background:#fff;color:#000;font-size:10.5pt}th,td{border-color:#bbb}a{color:#000}@page{margin:16mm}}
</style>
</head>
<body>
<?php if ($bare): ?>
<div style="max-width:340px;margin:0 auto;padding:9vh 16px"><?= $inhalt ?></div>
<?php else: ?>
<div class="sc" data-nav="0"></div>
<div class="app">
 <aside class="sb">
  <div class="bd"><i></i><?= h(APP_NAME) ?></div>
  <nav class="nv">
   <?php foreach (nav() as $i => [$k, $lbl]): $b = nav_zahl($k); ?>
    <a href="<?= url($k) ?>"<?= $p === $k ? ' class="on"' : '' ?>><?= h($lbl) ?>
     <?= $b !== '' ? '<span class="b">' . h($b) . '</span>' : '<span class="k">' . ($i + 1) . '</span>' ?></a>
   <?php endforeach; ?>
  </nav>
  <div class="sf">
   <?php if ($u): ?>
    <b><?= h($u['name'] ?: $u['username']) ?></b>
    <?= h($u['klasse'] ?: $u['betrieb']) ?>
    <div class="lk">
     <a href="<?= url('einstellungen') ?>">Einstellungen</a>
     <form method="post" action="<?= url('logout') ?>" style="display:inline"><?= csrf_field() ?>
      <button class="g s" style="height:auto;padding:0;color:var(--ac)" type="submit">Abmelden</button></form>
    </div>
   <?php endif; ?>
  </div>
 </aside>
 <div class="mn">
  <header class="tb np">
   <button class="g s bg" data-nav="1" aria-label="Menue">&#9776;</button>
   <h1><?= h($titel) ?></h1>
   <span class="sp"></span>
   <?= $o['aktion'] ?? '' ?>
   <form method="get" action="<?= h(base_path()) ?>" style="display:flex">
    <input type="hidden" name="p" value="suche">
    <input type="search" name="q" id="sq" placeholder="Suchen  /" value="<?= h(get('q')) ?>">
   </form>
   <form method="post" action="<?= url('theme') ?>"><?= csrf_field() ?>
    <input type="hidden" name="theme" value="<?= $theme === 'dunkel' ? 'hell' : ($theme === 'hell' ? 'auto' : 'dunkel') ?>">
    <button class="g s" type="submit" title="Design"><?= $theme === 'dunkel' ? '&#9788;' : ($theme === 'hell' ? '&#9789;' : '&#9681;') ?></button>
   </form>
  </header>
  <div class="ct">
   <?php foreach ($flash as [$t, $m]): ?><div class="ms <?= h($t) ?>"><?= h($m) ?></div><?php endforeach; ?>
   <?= $inhalt ?>
  </div>
 </div>
</div>
<div class="pl" id="pl"><div class="bx"><input type="search" id="pq" placeholder="Springen oder suchen" autocomplete="off"><ul id="pu"></ul></div></div>
<?php endif; ?>
<script nonce="<?= h($n) ?>">
(function(){
var B=<?= json_encode(base_path()) ?>, N=<?= json_encode(array_map(fn($x) => ['t'=>$x[1],'u'=>url($x[0])], nav())) ?>;
var Z=<?= json_encode(array_merge(
  array_map(fn($x) => ['t'=>$x[1],'u'=>url($x[0])], nav()),
  [['t'=>'Einstellungen','u'=>url('einstellungen')],
   ['t'=>'Zwei-Faktor','u'=>url('einstellungen',['t'=>'sicherheit'])],
   ['t'=>'Faecher','u'=>url('einstellungen',['t'=>'faecher'])],
   ['t'=>'Stundenplan','u'=>url('einstellungen',['t'=>'plan'])],
   ['t'=>'Blockplan','u'=>url('einstellungen',['t'=>'plan'])],
   ['t'=>'Abwesenheiten','u'=>url('berichtsheft',['t'=>'abwesend'])],
   ['t'=>'Lernfelder','u'=>url('pruefung',['t'=>'lf'])],
   ['t'=>'Alle Nachweise','u'=>url('berichtsheft',['t'=>'alle'])],
   ['t'=>'Export','u'=>url('einstellungen',['t'=>'daten'])]]), JSON_UNESCAPED_UNICODE) ?>;
document.addEventListener('click',function(e){
 var t=e.target.closest('[data-nav]'); if(t){document.body.classList.toggle('nav',t.dataset.nav==='1');}
 var c=e.target.closest('[data-copy]');
 if(c){var el=document.getElementById(c.dataset.copy);
  if(el&&navigator.clipboard)navigator.clipboard.writeText(el.innerText).then(function(){
   var o=c.textContent;c.textContent='kopiert';setTimeout(function(){c.textContent=o;},1100);});}
});
document.addEventListener('submit',function(e){var m=e.target.getAttribute('data-q');if(m&&!confirm(m))e.preventDefault();});
var pl=document.getElementById('pl'),pq=document.getElementById('pq'),pu=document.getElementById('pu'),sel=0,cur=[];
function draw(){var q=(pq.value||'').toLowerCase().trim();
 cur=Z.filter(function(x){return !q||x.t.toLowerCase().indexOf(q)>=0;});
 if(q)cur=cur.concat([{t:'Volltextsuche: '+pq.value,u:B+'?p=suche&q='+encodeURIComponent(pq.value)}]);
 if(sel>=cur.length)sel=Math.max(0,cur.length-1);
 pu.innerHTML=cur.map(function(x,i){return '<li'+(i===sel?' class="on"':'')+' data-u="'+x.u+'">'+x.t.replace(/[<>&]/g,'')+'</li>';}).join('');}
function open_(){pl.classList.add('on');pq.value='';sel=0;draw();pq.focus();}
function close_(){pl.classList.remove('on');}
if(pl){pq.addEventListener('input',draw);
 pu.addEventListener('click',function(e){var l=e.target.closest('li');if(l)location.href=l.dataset.u;});
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
 if(e.key>='1'&&e.key<='8'){var x=N[+e.key-1];if(x){e.preventDefault();location.href=x.u;}return;}
 if(e.key==='n'){var a=document.querySelector('[data-new]');if(a){e.preventDefault();
  if(a.nodeName==='A')location.href=a.href;else a.focus();}}
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
            'termin'=>'Termin','projekt'=>'Projekt','frei'=>'Frei'][$t] ?? $t;
}
function md(string $s): string {
    $out = []; $code = false; $buf = [];
    $flush = function () use (&$buf, &$out) { if ($buf) { $out[] = '<pre>' . implode("\n", $buf) . '</pre>'; $buf = []; } };
    foreach (preg_split('/\R/', $s) as $line) {
        if (preg_match('/^```/', $line)) { if ($code) $flush(); $code = !$code; continue; }
        if ($code) { $buf[] = h($line); continue; }
        $l = h($line);
        $l = preg_replace('/`([^`]+)`/', '<code>$1</code>', $l);
        $l = preg_replace('/\*\*([^*]+)\*\*/', '<b>$1</b>', $l);
        $l = preg_replace_callback('~https?://[^\s<]+~', fn($m) => '<a href="' . $m[0] . '" rel="noopener nofollow">' . $m[0] . '</a>', $l);
        if (preg_match('/^\s*[-*]\s+(.*)$/', $line, $m)) $l = '&bull; ' . h($m[1]);
        elseif (preg_match('/^\s*#{1,3}\s+(.*)$/', $line, $m)) $l = '<b>' . h($m[1]) . '</b>';
        $out[] = $l;
    }
    $flush();
    return implode("<br>\n", $out);
}

// --- Anmelden / Konto anlegen ---------------------------------------------
function p_login(): void {
    if (me()) redirect(url('heute'));
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
                redirect($to ?: url('heute'));
            }
            $err = 'Code stimmt nicht.';
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $ident = post('ident'); $pw = (string)($_POST['pw'] ?? ''); $ip = client_ip();
        if (post('web') !== '') { usleep(400000); $err = 'Anmeldung fehlgeschlagen.'; }
        elseif (!rl('ip:' . $ip, LOGIN_MAX_IP, 900)) $err = 'Zu viele Versuche von dieser Adresse. 15 Minuten warten.';
        else {
            $u = one("SELECT * FROM users WHERE username = ? OR (email IS NOT NULL AND email = ?)", [$ident, $ident]);
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
                    redirect($to ?: url('heute'));
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
    if (me()) redirect(url('heute'));
    $erst = erstes_konto();
    reg_code();   // legt REGISTRIERUNG.txt an, falls noch nicht vorhanden
    $err = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (!rl('reg:' . client_ip(), 10, 3600)) $err[] = 'Zu viele Versuche.';
        if (!$erst && !hash_equals(reg_code(), strtoupper(preg_replace('/\s+/', '', post('code'))))) {
            $err[] = 'Code stimmt nicht.';
        }
        $user = preg_replace('/[^A-Za-z0-9._-]/', '', post('username'));
        $name = post('name');
        $mail = filter_var(post('email'), FILTER_VALIDATE_EMAIL) ?: '';
        $pw   = (string)($_POST['pw'] ?? '');
        if (mb_strlen($user) < 3) $err[] = 'Benutzername: mindestens 3 Zeichen.';
        if (val("SELECT 1 FROM users WHERE username = ?", [$user])) $err[] = 'Benutzername vergeben.';
        if ($mail && val("SELECT 1 FROM users WHERE email = ?", [$mail])) $err[] = 'E-Mail vergeben.';
        if ($pw !== (string)($_POST['pw2'] ?? '')) $err[] = 'Passwoerter stimmen nicht ueberein.';
        foreach (pw_problems($pw, $user, $name) as $p) $err[] = 'Passwort: ' . $p;
        if (!$err) {
            $klasse = mb_substr(post('klasse'), 0, 20);
            $zg = 0;
            if (preg_match('/(\d)\s*$/', $klasse, $m)) $zg = (int)$m[1];
            $uid = ins('users', ['username' => $user, 'email' => $mail ?: null, 'pass_hash' => pw_hash($pw),
                'name' => $name ?: $user, 'klasse' => $klasse, 'zeitgruppe' => $zg,
                'start' => post('start') ?: null, 'betrieb' => post('betrieb'),
                'ics_token' => bin2hex(random_bytes(16)), 'pw_changed' => date('Y-m-d H:i:s')]);
            seed_user($uid);
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
        <div class="fg">
          <div class="f"><label for="us">Benutzername</label><input id="us" name="username" required autocomplete="username" value="<?= h(post('username')) ?>"></div>
          <div class="f"><label for="nm">Name</label><input id="nm" name="name" required value="<?= h(post('name')) ?>"></div>
        </div>
        <div class="fg">
          <div class="f"><label for="kl">Klasse</label><input id="kl" name="klasse" placeholder="1FS152" value="<?= h(post('klasse')) ?>"></div>
          <div class="f"><label for="st">Ausbildungsbeginn</label><input id="st" name="start" type="date" value="<?= h(post('start')) ?>"></div>
        </div>
        <div class="f"><label for="bt">Betrieb</label><input id="bt" name="betrieb" value="<?= h(post('betrieb')) ?>"></div>
        <div class="f"><label for="em">E-Mail</label><input id="em" name="email" type="email" value="<?= h(post('email')) ?>"></div>
        <div class="fg">
          <div class="f"><label for="pw">Passwort</label><input id="pw" name="pw" type="password" required autocomplete="new-password"></div>
          <div class="f"><label for="p2">Wiederholen</label><input id="p2" name="pw2" type="password" required autocomplete="new-password"></div>
        </div>
        <div class="rw"><button class="p" type="submit">Anlegen</button>
          <a class="bt g" href="<?= url('login') ?>">Zurueck</a></div>
      </form>
    </div></div>
    <?php
    page('Konto', ob_get_clean(), ['bare' => true]);
}

// --- Schnellerfassung ------------------------------------------------------
function quick(array $u, string $typ = 'bericht'): string {
    $uid = (int)$u['id'];
    $rt = all("SELECT id, name FROM routines WHERE user_id = ? AND aktiv = 1 ORDER BY sort, name", [$uid]);
    ob_start(); ?>
    <form method="post" action="<?= url('neu') ?>" class="c np"><div class="bo" style="padding:8px">
      <?= csrf_field() ?>
      <input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
      <div class="line">
        <select name="typ" style="width:104px;flex:none" aria-label="Art"><?= optm(
          ['bericht'=>'Bericht','notiz'=>'Notiz','aufgabe'=>'Aufgabe','termin'=>'Termin',
           'routine'=>'Routine','abwesend'=>'Abwesend'], $typ) ?></select>
        <input name="text" required autocomplete="off" data-new placeholder="Kaffeemaschine geleert 0,5h">
        <input type="date" name="datum" value="<?= h(today()) ?>" style="width:140px;flex:none" aria-label="Datum">
        <select name="rid" style="width:130px;flex:none" aria-label="Routine" data-only="routine"><?= opts($rt, null, 'Routine …') ?></select>
        <button class="p" type="submit" style="flex:none">Speichern</button>
      </div>
    </div></form>
    <?php return ob_get_clean();
}
function a_neu(): void {
    $u = need_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(url('heute'));
    csrf_check();
    $uid = (int)$u['id'];
    if (!rl('n:' . $uid, 200, 3600)) { flash('Zu viele Eintraege.', 'err'); redirect(url('heute')); }
    $typ = post('typ', 'bericht'); $text = post('text');
    $datum = isodate(post('datum')) ? post('datum') : today();
    $rid = inull(postn('rid'));
    if ($text === '') redirect(post('back') ?: url('heute'));
    $std = 0.0;
    if (preg_match('/(?:^|[\s\-,;(])(\d+(?:[.,]\d+)?)\s*(h|std|stunden|min)\b\.?\s*\)?\s*$/iu', $text, $m)) {
        $w = (float)str_replace(',', '.', $m[1]);
        $std = strtolower($m[2]) === 'min' ? round($w / 60, 2) : $w;
        $text = trim(preg_replace('/(?:^|[\s\-,;(])(\d+(?:[.,]\d+)?)\s*(h|std|stunden|min)\b\.?\s*\)?\s*$/iu', '', $text), " -,;\t");
    }
    switch ($typ) {
        case 'notiz':
            ins('notes', ['user_id' => $uid, 'datum' => $datum, 'titel' => mb_substr($text, 0, 160),
                'body' => $text, 'kind' => 'notiz']);
            flash('Notiz gespeichert.'); break;
        case 'aufgabe':
            ins('tasks', ['user_id' => $uid, 'titel' => mb_substr($text, 0, 200), 'faellig' => $datum]);
            flash('Aufgabe angelegt.'); break;
        case 'termin':
            ins('events', ['user_id' => $uid, 'typ' => 'probe', 'titel' => mb_substr($text, 0, 200), 'datum' => $datum]);
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
    redirect(post('back') ?: url('heute'));
}

// --- Heute -----------------------------------------------------------------
function p_heute(): void {
    $u = need_login(); $uid = (int)$u['id'];
    $art = $u['bh_art']; $per = periode_of(today(), $art);
    $rep = report_get($uid, $art, $per);
    $sum = report_sum((int)$rep['id']);
    $mo  = date('Y-m-d', strtotime('monday this week'));

    $ev = all("SELECT e.*, s.short, s.color FROM events e LEFT JOIN subjects s ON s.id = e.subject_id
               WHERE e.user_id = ? AND e.datum >= date('now','localtime') ORDER BY e.datum, e.zeit_von LIMIT 12", [$uid]);
    $tk = all("SELECT t.*, s.short FROM tasks t LEFT JOIN subjects s ON s.id = t.subject_id
               WHERE t.user_id = ? AND t.status='offen' ORDER BY (t.faellig IS NULL), t.faellig LIMIT 12", [$uid]);
    $an = [];
    foreach ($ev as $e) $an[] = ['d' => $e['datum'], 'k' => 'termin', 'typ' => typ_label($e['typ']),
        't' => $e['titel'], 'u' => url('termine', ['id' => $e['id']]), 'f' => $e['short'], 'z' => $e['zeit_von']];
    foreach ($tk as $t) $an[] = ['d' => $t['faellig'] ?: '9999-12-31', 'k' => 'aufgabe', 'typ' => 'Aufgabe',
        't' => $t['titel'], 'u' => url('aufgaben', ['id' => $t['id']]), 'f' => $t['short'], 'z' => ''];
    usort($an, fn($a, $b) => [$a['d'], $a['t']] <=> [$b['d'], $b['t']]);
    $an = array_slice($an, 0, 9);

    $block = one("SELECT * FROM blocks WHERE user_id = ? AND date('now','localtime') BETWEEN von AND bis", [$uid]);
    $heuteSchule = $block && $block['art'] === 'schule';
    $tag = (int)date('N');
    $plan = $heuteSchule && $tag <= 5
        ? all("SELECT t.*, s.short, s.name, s.color FROM timetable t LEFT JOIN subjects s ON s.id = t.subject_id
               WHERE t.user_id = ? AND t.tag = ? ORDER BY t.stunde", [$uid, $tag]) : [];
    $rout = all("SELECT r.*, (SELECT MAX(datum) FROM routine_logs l WHERE l.routine_id = r.id) AS letzte
                 FROM routines r WHERE r.user_id = ? AND r.aktiv = 1 ORDER BY r.sort, r.name", [$uid]);
    $offen = array_values(array_filter($rout, fn($r) => match ($r['intervall']) {
        'taeglich'     => $r['letzte'] !== today(),
        'woechentlich' => !$r['letzte'] || $r['letzte'] < $mo,
        'monatlich'    => !$r['letzte'] || substr((string)$r['letzte'], 0, 7) !== date('Y-m'),
        default        => false,
    }));
    // Wochenstreifen
    $marks = [];
    foreach (all("SELECT datum FROM events WHERE user_id = ? AND datum BETWEEN ? AND date(?,'+6 day')", [$uid, $mo, $mo]) as $r) $marks[$r['datum']]['e'] = true;
    foreach (all("SELECT faellig FROM tasks WHERE user_id = ? AND status='offen' AND faellig BETWEEN ? AND date(?,'+6 day')", [$uid, $mo, $mo]) as $r) $marks[$r['faellig']]['t'] = true;

    ob_start(); ?>
    <?= quick($u) ?>
    <div class="c"><div class="bo" style="padding:8px 10px">
      <div class="rw" style="gap:3px">
        <?php $d = new DateTimeImmutable($mo); for ($i = 0; $i < 7; $i++):
          $ds = $d->format('Y-m-d'); $ist = $ds === today(); ?>
          <a href="<?= url('termine', ['von' => $ds, 'bis' => $ds]) ?>" style="flex:1 1 0;min-width:34px;text-align:center;
             padding:2px 2px 3px;border-radius:5px;text-decoration:none;<?= $ist ? 'background:var(--acb);color:var(--ac);font-weight:600' : 'color:var(--fg2)' ?>">
            <div class="sm"><?= h(mb_substr(wd((int)$d->format('N')), 0, 2)) ?></div>
            <div style="font-size:15px;line-height:1.2"><?= $d->format('j') ?></div>
            <div style="height:6px;line-height:6px"><?php if (isset($marks[$ds])): ?><span class="dot" style="background:<?= isset($marks[$ds]['e']) ? 'var(--ac)' : 'var(--wa)' ?>"></span><?php endif; ?></div>
          </a>
        <?php $d = $d->modify('+1 day'); endfor; ?>
      </div>
      <div class="rw sm mu" style="margin-top:6px;gap:12px">
        <span><?= h(dt(today(), 'l, j. F Y')) ?></span>
        <?php if ($block): ?><span class="tg a"><?= h(ucfirst($block['art'])) ?> bis <?= h(dt($block['bis'], 'd.m.')) ?></span><?php endif; ?>
        <a href="<?= url('berichtsheft') ?>"><span class="tg <?= $rep['status'] === 'fertig' ? 'o' : 'w' ?>">Berichtsheft
          <?= h(periode_label($per, $art)) ?> · <?= num($sum['std'], 1) ?> h</span></a>
      </div>
    </div></div>

    <div class="sp2">
      <div class="c">
        <div class="hd"><h2>Anstehend</h2><span class="sp"></span>
          <a class="bt s g" href="<?= url('termine') ?>">Termine</a>
          <a class="bt s g" href="<?= url('aufgaben') ?>">Aufgaben</a></div>
        <?php if (!$an): ?><?= em('Nichts offen.') ?><?php else: ?>
        <div class="tw"><table><tbody>
          <?php foreach ($an as $x):
            $tage = $x['d'] === '9999-12-31' ? null : (int)floor((strtotime($x['d']) - strtotime(today())) / 86400); ?>
            <tr>
              <td style="width:76px;white-space:nowrap" class="sm">
                <?php if ($tage === null): ?><span class="mu2">–</span>
                <?php elseif ($tage < 0): ?><span class="tg e"><?= abs($tage) ?> T ueber</span>
                <?php elseif ($tage === 0): ?><span class="tg a">heute</span>
                <?php elseif ($tage === 1): ?><span class="tg">morgen</span>
                <?php else: ?><?= h(dt($x['d'], 'D d.m.')) ?><?php endif; ?>
              </td>
              <td style="width:60px"><span class="tg<?= $x['k'] === 'aufgabe' ? '' : ' a' ?>"><?= h($x['typ']) ?></span></td>
              <td><a href="<?= h($x['u']) ?>"><?= h($x['t']) ?></a>
                <?= $x['f'] ? ' <span class="sm mu2">' . h($x['f']) . '</span>' : '' ?>
                <?= $x['z'] ? ' <span class="sm mu2 mo">' . h($x['z']) . '</span>' : '' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
      </div>

      <div>
        <?php if ($plan): ?>
        <div class="c">
          <div class="hd"><h2>Unterricht heute</h2></div>
          <ul class="li">
            <?php foreach ($plan as $s): ?>
              <li><span class="mu2 mo" style="width:16px"><?= (int)$s['stunde'] ?></span>
                <span style="flex:1"><?= h($s['short'] ?: $s['name'] ?: '–') ?></span>
                <span class="sm mu2"><?= h($s['raum']) ?></span></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
        <div class="c">
          <div class="hd"><h2>Routinen offen</h2><span class="sp"></span>
            <a class="bt s g" href="<?= url('routinen') ?>">alle</a></div>
          <?php if (!$offen): ?><?= em('Alles erledigt.') ?><?php else: ?>
          <ul class="li">
            <?php foreach (array_slice($offen, 0, 9) as $r): ?>
              <li style="padding:4px 12px">
                <form method="post" action="<?= url('routinen') ?>" style="display:flex;gap:8px;align-items:center;width:100%">
                  <?= csrf_field() ?><input type="hidden" name="a" value="log">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
                  <button class="s" type="submit" title="erledigt">&check;</button>
                  <span style="flex:1"><?= h($r['name']) ?></span>
                  <span class="sm mu2"><?= h(substr($r['intervall'], 0, 1) === 't' ? 'tgl' : ($r['intervall'] === 'woechentlich' ? 'wtl' : 'mtl')) ?></span>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php
    page('Heute', ob_get_clean());
}

// --- Termine ---------------------------------------------------------------
function p_termine(): void {
    $u = need_login(); $uid = (int)$u['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $a = post('a'); $id = (int)post('id', '0');
        if ($a === 'save') {
            $d = ['subject_id' => inull(postn('subject_id')),
                'typ' => in_array(post('typ'), ['probe','test','abgabe','pruefung','termin','projekt','frei'], true) ? post('typ') : 'termin',
                'titel' => mb_substr(post('titel'), 0, 200), 'beschreibung' => post('beschreibung'),
                'datum' => isodate(post('datum')) ? post('datum') : today(),
                'zeit_von' => post('zeit_von'), 'zeit_bis' => post('zeit_bis'),
                'raum' => mb_substr(post('raum'), 0, 40), 'lf_no' => inull(postn('lf_no')), 'stoff' => post('stoff')];
            if ($d['titel'] === '') flash('Titel fehlt.', 'err');
            elseif ($id) { upd('events', $d, 'id = :id AND user_id = :u', ['id' => $id, 'u' => $uid]); flash('Gespeichert.'); }
            else { $d['user_id'] = $uid; $id = ins('events', $d); flash('Angelegt.'); }
            redirect(url('termine', ['id' => $id]));
        }
        if ($a === 'del') { del('events', 'id = ? AND user_id = ?', [$id, $uid]); flash('Geloescht.'); redirect(url('termine')); }
    }
    $edit = get('id') !== '' ? one("SELECT * FROM events WHERE id = ? AND user_id = ?", [(int)get('id'), $uid]) : null;
    if (get('neu') !== '') $edit = ['id' => 0, 'datum' => isodate(get('von')) ? get('von') : today()];

    $von = get('von') ?: date('Y-m-01');
    $bis = get('bis') ?: date('Y-m-d', strtotime('+180 days'));
    if (!isodate($von)) $von = date('Y-m-01');
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
    <div class="sp2">
      <div>
        <div class="c">
          <div class="hd">
            <form method="get" class="rw" style="flex:1">
              <input type="hidden" name="p" value="termine">
              <input type="date" name="von" value="<?= h($von) ?>" style="width:135px">
              <input type="date" name="bis" value="<?= h($bis) ?>" style="width:135px">
              <select name="typ" style="width:110px"><?= optm(['' => 'Alle', 'probe' => 'Probe', 'test' => 'Test',
                'abgabe' => 'Abgabe', 'pruefung' => 'Pruefung', 'termin' => 'Termin', 'projekt' => 'Projekt'], $typ) ?></select>
              <button class="s" type="submit">Filter</button>
            </form>
            <a class="bt p s" data-new href="<?= url('termine', ['neu' => 1]) ?>">Neu <kbd>n</kbd></a>
          </div>
          <?php if (!$rows): ?><?= em('Keine Termine im Zeitraum.') ?><?php else: ?>
          <div class="tw"><table><thead><tr><th>Datum</th><th>Art</th><th>Titel</th><th>Fach</th><th>Raum</th></tr></thead><tbody>
            <?php foreach ($rows as $e): $alt = $e['datum'] < today(); ?>
              <tr<?= $alt ? ' style="opacity:.5"' : '' ?>>
                <td style="white-space:nowrap" class="mo"><?= h(dt($e['datum'], 'D d.m.y')) ?>
                  <?= $e['zeit_von'] ? '<span class="mu2">' . h(substr($e['zeit_von'], 0, 5)) . '</span>' : '' ?></td>
                <td><span class="tg<?= in_array($e['typ'], ['probe','pruefung'], true) ? ' e' : ($e['typ'] === 'test' ? ' w' : '') ?>"><?= h(typ_label($e['typ'])) ?></span></td>
                <td><a href="<?= url('termine', ['id' => $e['id']]) ?>"><?= h($e['titel']) ?></a>
                  <?php if ($e['stoff']): ?><span class="sm mu2"><?= count(lines($e['stoff'])) ?> Punkte</span><?php endif; ?></td>
                <td class="sm"><?= h($e['short'] ?: '') ?><?= $e['lf_no'] ? ' <span class="tg">LF' . (int)$e['lf_no'] . '</span>' : '' ?></td>
                <td class="sm"><?= h($e['raum']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <?php endif; ?>
        </div>
        <div class="c np">
          <div class="hd"><h2><?= h(dt($m . '-01', 'F Y')) ?></h2><span class="sp"></span>
            <a class="bt s g" href="<?= url('termine', ['m' => $first->modify('-1 month')->format('Y-m')]) ?>">&larr;</a>
            <a class="bt s g" href="<?= url('termine', ['m' => date('Y-m')]) ?>">heute</a>
            <a class="bt s g" href="<?= url('termine', ['m' => $first->modify('+1 month')->format('Y-m')]) ?>">&rarr;</a></div>
          <div class="bo">
            <div class="cal">
              <?php foreach (['Mo','Di','Mi','Do','Fr','Sa','So'] as $x): ?><div class="h"><?= $x ?></div><?php endforeach; ?>
              <?php $d = $start; for ($i = 0; $i < 42; $i++): $ds = $d->format('Y-m-d'); ?>
                <div class="d <?= $d->format('Y-m') !== $m ? 'o' : '' ?> <?= $ds === today() ? 't' : '' ?>">
                  <b><?= $d->format('j') ?></b>
                  <?php foreach (array_slice($cal[$ds] ?? [], 0, 2) as $e): ?>
                    <a class="e" style="background:<?= h($e['color'] ?: '#2563eb') ?>" href="<?= url('termine', ['id' => $e['id']]) ?>"><?= h(mb_substr($e['titel'], 0, 16)) ?></a>
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
                 'projekt'=>'Projekt','frei'=>'Frei'], $edit['typ'] ?? 'probe') ?></select></div>
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
              <a class="bt g" href="<?= url('termine') ?>">Schliessen</a></div>
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
    page('Termine', ob_get_clean());
}

// --- Aufgaben --------------------------------------------------------------
function p_aufgaben(): void {
    $u = need_login(); $uid = (int)$u['id'];
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
        redirect(post('back') ?: url('aufgaben'));
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
    <div class="sp2">
      <div class="c">
        <div class="hd">
          <form method="get" class="rw" style="flex:1"><input type="hidden" name="p" value="aufgaben">
            <select name="status" style="width:100px"><?= optm(['offen'=>'Offen','erledigt'=>'Erledigt','alle'=>'Alle'], $st) ?></select>
            <select name="bereich" style="width:110px"><?= optm(['' => 'Alle Bereiche','schule'=>'Schule','betrieb'=>'Betrieb','privat'=>'Privat'], $be) ?></select>
            <button class="s" type="submit">Filter</button>
            <input placeholder="Filtern" data-fl="#tt" style="width:130px">
          </form>
        </div>
        <?php if (!$rows): ?><?= em('Keine Aufgaben.') ?><?php else: ?>
        <div class="tw"><table id="tt"><tbody>
          <?php foreach ($rows as $t): $ue = $t['status'] === 'offen' && $t['faellig'] && $t['faellig'] < today(); ?>
            <tr>
              <td style="width:34px">
                <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="ok">
                  <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                  <input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
                  <button class="g s" type="submit"><?= $t['status'] === 'offen' ? '&#9633;' : '&#9635;' ?></button></form>
              </td>
              <td><a href="<?= url('aufgaben', ['id' => $t['id']]) ?>"<?= $t['status'] === 'erledigt' ? ' style="text-decoration:line-through;color:var(--fg3)"' : '' ?>><?= h($t['titel']) ?></a></td>
              <td class="mo sm" style="width:88px;white-space:nowrap"><?= $t['faellig'] ? h(dt($t['faellig'], 'D d.m.')) : '' ?></td>
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
            <?php if ($edit): ?><a class="bt g" href="<?= url('aufgaben') ?>">Neu</a><?php endif; ?></div>
        </form>
        <?php if ($edit): ?>
          <hr><form method="post" data-q="Loeschen?"><?= csrf_field() ?>
            <input type="hidden" name="a" value="del"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
            <button class="d s" type="submit">Loeschen</button></form>
        <?php endif; ?>
      </div></div>
    </div>
    <?php
    page('Aufgaben', ob_get_clean());
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
    <div class="sp2">
      <div class="c">
        <div class="hd">
          <form method="get" class="rw" style="flex:1"><input type="hidden" name="p" value="notizen">
            <input name="q" value="<?= h($qs) ?>" placeholder="Suchen" style="width:150px">
            <select name="kind" style="width:110px"><?= optm(['' => 'Alle Arten','notiz'=>'Notiz','stoff'=>'Stoff',
              'howto'=>'How-To','snippet'=>'Snippet','link'=>'Link'], $kind) ?></select>
            <select name="lf" style="width:100px"><?= opts(all("SELECT nr AS id, code AS name FROM lernfelder ORDER BY nr"), $lf, 'Alle LF') ?></select>
            <button class="s" type="submit">Filter</button>
          </form>
          <a class="bt p s" data-new href="<?= url('notizen', ['neu' => 1]) ?>">Neu <kbd>n</kbd></a>
        </div>
        <?php if (!$rows): ?><?= em('Nichts gefunden.') ?><?php else: ?>
        <div class="tw"><table><tbody>
          <?php foreach ($rows as $n): ?>
            <tr>
              <td class="mo sm" style="width:78px;white-space:nowrap"><?= h(dt($n['datum'], 'd.m.y')) ?></td>
              <td style="width:64px"><span class="tg<?= $n['kind'] === 'snippet' ? ' a' : '' ?>"><?= h($n['kind']) ?></span></td>
              <td><?= $n['pinned'] ? '<span class="tg w">fix</span> ' : '' ?>
                <a href="<?= url('notizen', ['id' => $n['id']]) ?>"><?= h($n['titel'] ?: mb_substr($n['body'], 0, 70)) ?></a></td>
              <td class="sm mu2" style="width:104px"><?= h($n['short'] ?: '') ?>
                <?= $n['lf_no'] ? '<span class="tg">LF' . (int)$n['lf_no'] . '</span>' : '' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
      </div>
      <div>
        <?php if ($edit !== null): $neu = empty($edit['id']); ?>
        <div class="c"><div class="hd"><h2><?= $neu ? 'Neue Notiz' : 'Notiz' ?></h2>
          <?php if (!$neu && ($edit['kind'] ?? '') === 'snippet'): ?><span class="sp"></span>
            <button class="s" data-copy="snip" type="button">Code kopieren</button><?php endif; ?></div><div class="bo">
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
          <?php if (!$neu && ($edit['kind'] ?? '') === 'snippet'): ?>
            <hr><pre id="snip"><?= h($edit['body']) ?></pre>
          <?php elseif (!$neu && trim((string)$edit['body']) !== ''): ?>
            <hr><div class="sm"><?= md($edit['body']) ?></div>
          <?php endif; ?>
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
    page('Notizen', ob_get_clean());
}

// --- Noten -----------------------------------------------------------------
function p_noten(): void {
    $u = need_login(); $uid = (int)$u['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $a = post('a'); $id = (int)post('id', '0');
        if ($a === 'save') {
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
          <div class="tw"><table><thead><tr><th>Datum</th><th>Fach</th><th>Art</th><th>Titel</th><th class="n">Note</th><th class="n">Gew</th></tr></thead><tbody>
            <?php foreach (array_reverse($g['rows']) as $r): $n = to_note((float)$r['wert'], $r['skala']); ?>
              <tr>
                <td class="mo sm" style="white-space:nowrap"><?= h(dt($r['datum'], 'd.m.y')) ?></td>
                <td class="sm"><?= h($r['short'] ?: ($r['fach'] ?: '–')) ?></td>
                <td><span class="tg"><?= h($r['art']) ?></span></td>
                <td><a href="<?= url('noten', ['id' => $r['id']]) ?>"><?= h($r['titel'] ?: '–') ?></a></td>
                <td class="n"><?= npill($n) ?><?= $r['skala'] !== 'note' ? ' <span class="mu2 sm">' . num((float)$r['wert'], 0) . '</span>' : '' ?></td>
                <td class="n"><?= num((float)$r['gewicht'], 1) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <?php endif; ?>
        </div>
        <?php if ($g['faecher']): ?>
        <div class="c">
          <div class="hd"><h2>Faecher</h2></div>
          <div class="tw"><table><thead><tr><th>Fach</th><th class="n">Schnitt</th><th class="n">Anz</th><th>Verlauf</th><th>Tendenz</th></tr></thead><tbody>
            <?php foreach ($g['faecher'] as $f): if ($f['schnitt'] === null) continue; ?>
              <tr><td><?= h($f['name']) ?></td>
                <td class="n"><?= npill($f['schnitt']) ?></td>
                <td class="n"><?= (int)$f['anzahl'] ?></td>
                <td style="width:100px"><?= spark($f['n']) ?></td>
                <td class="sm"><?php if ($f['trend'] === null): ?><span class="mu2">–</span>
                  <?php elseif ($f['trend'] > 0.15): ?><span style="color:var(--ok)">besser</span>
                  <?php elseif ($f['trend'] < -0.15): ?><span style="color:var(--er)">schlechter</span>
                  <?php else: ?><span class="mu">stabil</span><?php endif; ?></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
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
    page('Noten', ob_get_clean());
}

// --- Berichtsheft ----------------------------------------------------------
function p_berichtsheft(): void {
    $u = need_login(); $uid = (int)$u['id'];
    $art = get('art') ?: $u['bh_art'];
    if (!in_array($art, ['woche', 'monat'], true)) $art = 'woche';
    $per = get('periode') ?: periode_of(today(), $art);
    if (!periode_ok($per, $art)) $per = periode_of(today(), $art);
    $tab = get('t');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $a = post('a');
        if ($a === 'abw') {
            $von = isodate(post('von')) ? post('von') : today();
            $bis = isodate(post('bis')) && post('bis') >= $von ? post('bis') : $von;
            ins('absences', ['user_id' => $uid, 'von' => $von, 'bis' => $bis,
                'art' => in_array(post('art'), ['krank','urlaub','frei','dienstreise'], true) ? post('art') : 'krank',
                'grund' => mb_substr(post('grund'), 0, 200), 'schule' => post('schule') ? 1 : 0,
                'entschuldigt' => post('entschuldigt') ? 1 : 0]);
            flash('Erfasst.');
            redirect(url('berichtsheft', ['t' => 'abwesend']));
        }
        if ($a === 'abwdel') {
            del('absences', 'id = ? AND user_id = ?', [(int)post('id', '0'), $uid]);
            redirect(url('berichtsheft', ['t' => 'abwesend']));
        }
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

    if ($tab === 'alle') { bh_liste($u); return; }
    if ($tab === 'abwesend') { bh_abwesend($u); return; }

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
          <a class="bt s g" href="<?= url('berichtsheft', ['periode' => periode_shift($per, $art, -1), 'art' => $art]) ?>">&larr;</a>
          <b style="min-width:190px;text-align:center"><?= h(periode_label($per, $art)) ?></b>
          <a class="bt s g" href="<?= url('berichtsheft', ['periode' => periode_shift($per, $art, 1), 'art' => $art]) ?>">&rarr;</a>
          <a class="bt s g" href="<?= url('berichtsheft', ['art' => $art]) ?>">heute</a>
          <span class="tg <?= $zu ? 'o' : 'w' ?>"><?= $zu ? 'fertig' : 'offen' ?></span>
          <span class="sm mu mo"><?= num($s['std'], 1) ?> h · Nr. <?= (int)$rep['nr'] ?></span>
        </div>
        <div class="rw">
          <a class="bt s <?= $art === 'woche' ? 'p' : 'g' ?>" href="<?= url('berichtsheft', ['art' => 'woche']) ?>">Woche</a>
          <a class="bt s <?= $art === 'monat' ? 'p' : 'g' ?>" href="<?= url('berichtsheft', ['art' => 'monat']) ?>">Monat</a>
          <a class="bt s g" href="<?= url('berichtsheft', ['periode' => $per, 'art' => $art, 'druck' => 1]) ?>">Drucken</a>
          <a class="bt s g" href="<?= url('berichtsheft', ['t' => 'alle']) ?>">Alle</a>
          <a class="bt s g" href="<?= url('berichtsheft', ['t' => 'abwesend']) ?>">Abwesend</a>
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
              <button class="s" type="submit">Aus Routinen &amp; Notizen fuellen</button></form>
            <?php endif; ?>
          </div>
          <?php if (!$s['rows']): ?><?= em('Noch nichts eingetragen.') ?><?php else: ?>
          <div class="tw"><table><thead><tr><th>Tag</th><th class="n">Std</th><th>Ort</th><th>Taetigkeit</th><th>Zuordnung</th><th></th></tr></thead><tbody>
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
                      <select name="cat" onchange="this.form.submit()" style="height:24px;font-size:12px"><?= kat_opts($r['category_id'], 'ohne') ?></select>
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
    page('Berichtsheft', ob_get_clean());
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
      <div class="tw"><table><thead><tr><th class="n">Nr</th><th>Zeitraum</th><th class="n">Jahr</th>
        <th class="n">Eintraege</th><th class="n">Stunden</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td class="n"><?= report_nr($uid, $r['von']) ?></td>
            <td><a href="<?= url('berichtsheft', ['periode' => $r['periode'], 'art' => $r['art']]) ?>"><?= h(periode_label($r['periode'], $r['art'])) ?></a></td>
            <td class="n"><?= (int)$r['jahr'] ?></td><td class="n"><?= (int)$r['anz'] ?></td>
            <td class="n"><?= num((float)$r['std'], 1) ?></td>
            <td><span class="tg <?= $r['status'] === 'fertig' ? 'o' : 'w' ?>"><?= $r['status'] === 'fertig' ? 'fertig' : 'offen' ?></span></td>
            <td><a class="bt s g" href="<?= url('berichtsheft', ['periode' => $r['periode'], 'art' => $r['art'], 'druck' => 1]) ?>">Druck</a></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>
    <?php
    page('Nachweise', ob_get_clean());
}

function bh_abwesend(array $u): void {
    $uid = (int)$u['id'];
    $rows = all("SELECT * FROM absences WHERE user_id = ? ORDER BY von DESC", [$uid]);
    $at = function (array $r): int {
        $d = new DateTimeImmutable($r['von']); $e = new DateTimeImmutable($r['bis']); $n = 0;
        while ($d <= $e) { if ((int)$d->format('N') <= 5) $n++; $d = $d->modify('+1 day'); }
        return $n;
    };
    $jahr = date('Y');
    $imJ = array_filter($rows, fn($r) => substr($r['von'], 0, 4) === $jahr);
    ob_start(); ?>
    <div class="sp2">
      <div class="c">
        <div class="hd"><h2>Abwesenheiten</h2><span class="sp"></span>
          <span class="sm mu"><?= $jahr ?>: <?= array_sum(array_map($at, array_filter($imJ, fn($r) => $r['art'] === 'krank'))) ?> krank,
            <?= array_sum(array_map($at, array_filter($imJ, fn($r) => $r['art'] === 'urlaub'))) ?> Urlaub</span>
          <a class="bt s g" href="<?= url('berichtsheft') ?>">Berichtsheft</a></div>
        <?php if (!$rows): ?><?= em('Nichts erfasst.') ?><?php else: ?>
        <div class="tw"><table><thead><tr><th>Zeitraum</th><th class="n">Tage</th><th>Art</th><th>Grund</th><th>Schule</th><th></th></tr></thead><tbody>
          <?php foreach ($rows as $r): ?>
            <tr><td class="mo sm" style="white-space:nowrap"><?= h(dt($r['von'], 'd.m.y')) ?><?= $r['bis'] !== $r['von'] ? '–' . h(dt($r['bis'], 'd.m.y')) : '' ?></td>
              <td class="n"><?= $at($r) ?></td>
              <td><span class="tg <?= $r['art'] === 'krank' ? 'e' : ($r['art'] === 'urlaub' ? 'o' : '') ?>"><?= h($r['art']) ?></span></td>
              <td class="sm"><?= h($r['grund']) ?></td>
              <td class="sm"><?= (int)$r['schule'] ? ((int)$r['entschuldigt'] ? '<span class="tg o">entsch.</span>' : '<span class="tg e">offen</span>') : '' ?></td>
              <td style="width:30px"><form method="post" action="<?= url('berichtsheft') ?>" data-q="Loeschen?">
                <?= csrf_field() ?><input type="hidden" name="a" value="abwdel">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="g s d" type="submit">&times;</button></form></td></tr>
          <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
      </div>
      <div class="c"><div class="hd"><h2>Neu</h2></div><div class="bo">
        <form method="post" action="<?= url('berichtsheft') ?>">
          <?= csrf_field() ?><input type="hidden" name="a" value="abw">
          <div class="fg">
            <div class="f"><label for="v">Von</label><input id="v" name="von" type="date" required value="<?= h(today()) ?>" data-new></div>
            <div class="f"><label for="b">Bis</label><input id="b" name="bis" type="date" value="<?= h(today()) ?>"></div>
          </div>
          <div class="f"><label for="ar">Art</label><select id="ar" name="art"><?= optm(
            ['krank'=>'Krank','urlaub'=>'Urlaub','frei'=>'Frei','dienstreise'=>'Dienstreise'], 'krank') ?></select></div>
          <div class="f"><label for="gr">Grund</label><input id="gr" name="grund"></div>
          <div class="rw" style="margin-bottom:8px">
            <label style="display:flex;gap:5px;align-items:center;font-weight:400"><input type="checkbox" name="schule" value="1"> Berufsschule</label>
            <label style="display:flex;gap:5px;align-items:center;font-weight:400"><input type="checkbox" name="entschuldigt" value="1"> entschuldigt</label>
          </div>
          <button class="p" type="submit">Speichern</button>
        </form>
      </div></div>
    </div>
    <?php
    page('Abwesenheiten', ob_get_clean());
}

function bh_druck(array $u, array $rep, array $s): void {
    $betrieb = array_filter($s['rows'], fn($r) => $r['ort'] !== 'schule');
    $schule  = array_filter($s['rows'], fn($r) => $r['ort'] === 'schule');
    $sb = array_sum(array_map(fn($r) => (float)$r['stunden'], $betrieb));
    $ss = array_sum(array_map(fn($r) => (float)$r['stunden'], $schule));
    ob_start(); ?>
    <div class="rw np" style="justify-content:flex-end;margin-bottom:10px">
      <a class="bt s g" href="<?= url('berichtsheft', ['periode' => $rep['periode'], 'art' => $rep['art']]) ?>">&larr; zurueck</a></div>
    <div class="c"><div class="bo">
      <h1 style="font-size:19px;margin-bottom:2px">Ausbildungsnachweis Nr. <?= (int)$rep['nr'] ?></h1>
      <p class="mu sm"><?= $rep['art'] === 'monat' ? 'Monatsnachweis' : 'Wochennachweis' ?> ·
        <?= h(periode_label($rep['periode'], $rep['art'])) ?></p>
      <div class="tw"><table style="margin-bottom:12px">
        <tr><th style="width:20%">Auszubildende/-r</th><td><?= h($u['name'] ?: $u['username']) ?></td>
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
function p_routinen(): void {
    $u = need_login(); $uid = (int)$u['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $a = post('a'); $id = (int)post('id', '0');
        if ($a === 'log' && $id) {
            $r = one("SELECT * FROM routines WHERE id = ? AND user_id = ?", [$id, $uid]);
            if ($r) ins('routine_logs', ['routine_id' => $id, 'user_id' => $uid,
                'datum' => isodate(post('datum')) ? post('datum') : today(),
                'zeit' => post('zeit') ?: date('H:i'),
                'minuten' => max(0, (int)post('minuten', (string)(int)$r['minuten'])),
                'notiz' => mb_substr(post('notiz'), 0, 200)]);
        } elseif ($a === 'unlog') {
            del('routine_logs', 'id = ? AND user_id = ?', [(int)post('lid', '0'), $uid]);
        } elseif ($a === 'save') {
            $d = ['name' => mb_substr(post('name'), 0, 100),
                'intervall' => in_array(post('intervall'), ['taeglich','woechentlich','monatlich','bedarf'], true) ? post('intervall') : 'bedarf',
                'category_id' => inull(postn('cat')), 'minuten' => max(0, (int)post('minuten', '10')),
                'bh' => post('bh') === '0' ? 0 : 1, 'aktiv' => post('aktiv') === '0' ? 0 : 1];
            if ($d['name'] === '') flash('Name fehlt.', 'err');
            elseif ($id) { upd('routines', $d, 'id = :id AND user_id = :u', ['id' => $id, 'u' => $uid]); flash('Gespeichert.'); }
            else { $d['user_id'] = $uid; $d['sort'] = 500; ins('routines', $d); flash('Angelegt.'); }
        } elseif ($a === 'del' && $id) { del('routines', 'id = ? AND user_id = ?', [$id, $uid]); flash('Geloescht.'); }
        redirect(post('back') ?: url('routinen'));
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
    <?= quick($u, 'routine') ?>
    <div class="sp2">
      <div>
        <div class="c">
          <div class="hd"><h2>Routinen</h2><span class="sp"></span>
            <a class="bt p s" data-new href="<?= url('routinen', ['neu' => 1]) ?>">Neu <kbd>n</kbd></a></div>
          <div class="tw"><table><tbody>
            <?php foreach ($rt as $r):
              $f = match ($r['intervall']) {
                  'taeglich' => $r['letzte'] !== today(),
                  'woechentlich' => !$r['letzte'] || $r['letzte'] < $mo,
                  'monatlich' => !$r['letzte'] || substr((string)$r['letzte'], 0, 7) !== date('Y-m'),
                  default => false }; ?>
              <tr<?= (int)$r['aktiv'] ? '' : ' style="opacity:.4"' ?>>
                <td style="width:36px">
                  <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="log">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
                    <button class="<?= $f ? 'p' : 'g' ?> s" type="submit" title="erledigt">&check;</button></form>
                </td>
                <td><a href="<?= url('routinen', ['id' => $r['id']]) ?>"><?= h($r['name']) ?></a></td>
                <td style="width:96px"><span class="tg<?= $f ? ' w' : '' ?>"><?= h($r['intervall']) ?></span></td>
                <td class="mo sm" style="width:96px"><?= $r['letzte'] ? h(dt($r['letzte'], 'd.m.y')) : '<span class="mu2">nie</span>' ?></td>
                <td class="n sm" style="width:44px"><?= (int)$r['anz'] ?>&times;</td>
                <td class="sm mu2"><?= h($r['kat'] ?: '') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </div>
        <div class="c">
          <div class="hd"><h2>Protokoll</h2><span class="sp"></span>
            <input placeholder="Filtern" data-fl="#lg" style="width:130px">
            <a class="bt s g" href="<?= url('export', ['w' => 'routinen']) ?>">CSV</a></div>
          <?php if (!$logs): ?><?= em('Noch nichts protokolliert.') ?><?php else: ?>
          <div class="tw" style="max-height:420px;overflow:auto"><table id="lg"><tbody>
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
              <a class="bt g" href="<?= url('routinen') ?>">Schliessen</a></div>
          </form>
          <?php if (!$neu): ?>
            <hr><form method="post" data-q="Routine und Protokoll loeschen?"><?= csrf_field() ?>
              <input type="hidden" name="a" value="del"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
              <button class="d s" type="submit">Loeschen</button></form>
          <?php endif; ?>
        </div></div>
        <?php else: ?>
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
    page('Routinen', ob_get_clean());
}

// --- Pruefung --------------------------------------------------------------
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
        } elseif (post('a') === 'projekt') {
            $d = ['titel' => mb_substr(post('titel'), 0, 200), 'kunde' => mb_substr(post('kunde'), 0, 100),
                  'stunden' => (int)post('stunden', '0'), 'status' => post('status'), 'antrag' => post('antrag')];
            q("INSERT INTO meta (k,v) VALUES (:k,:v) ON CONFLICT(k) DO UPDATE SET v = :v2",
              ['k' => 'prj' . $uid, 'v' => json_encode($d), 'v2' => json_encode($d)]);
            flash('Gespeichert.');
        }
        redirect(url('pruefung', get('t') ? ['t' => get('t')] : []));
    }
    $ihk = json_decode((string)val("SELECT v FROM meta WHERE k = ?", ['ihk' . $uid], '{}'), true) ?: [];
    $prj = json_decode((string)val("SELECT v FROM meta WHERE k = ?", ['prj' . $uid], '{}'), true) ?: [];
    $pg  = ihk_prognose($ihk);
    $pb  = ihk_probleme($ihk);
    $cd  = function (?string $d): ?int { return $d ? (int)ceil((strtotime($d) - strtotime(today())) / 86400) : null; };
    $a1 = $cd($u['ap1']); $a2 = $cd($u['ap2']);
    $tab = get('t');
    ob_start(); ?>
    <?php if ($tab === 'lf'): ?>
      <div class="c"><div class="hd"><h2>Lernfelder</h2><span class="sp"></span>
        <a class="bt s g" href="<?= url('pruefung') ?>">Pruefung</a></div>
        <div class="tw"><table><thead><tr><th>LF</th><th>Titel</th><th class="n">Jahr</th><th class="n">Std</th><th class="n">Notizen</th></tr></thead><tbody>
          <?php foreach (all("SELECT l.*, (SELECT COUNT(*) FROM notes n WHERE n.user_id = ? AND n.lf_no = l.nr) AS anz
                              FROM lernfelder l ORDER BY l.nr", [$uid]) as $l): ?>
            <tr><td><span class="tg a"><?= h($l['code']) ?></span></td>
              <td><a href="<?= url('notizen', ['lf' => $l['nr']]) ?>"><?= h($l['titel']) ?></a></td>
              <td class="n"><?= (int)$l['jahr'] ?></td><td class="n"><?= (int)$l['stunden'] ?></td>
              <td class="n"><?= (int)$l['anz'] ?></td></tr>
          <?php endforeach; ?>
        </tbody></table></div>
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
        <div class="c"><div class="hd"><h2>Pruefungsbereiche</h2><span class="sp"></span>
          <a class="bt s g" href="<?= url('pruefung', ['t' => 'lf']) ?>">Lernfelder</a></div><div class="bo">
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
      <div class="c"><div class="hd"><h2>Projektarbeit</h2></div><div class="bo">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="a" value="projekt">
          <div class="f"><label for="pt">Titel</label><input id="pt" name="titel" value="<?= h($prj['titel'] ?? '') ?>"></div>
          <div class="fg">
            <div class="f"><label for="pk">Kunde</label><input id="pk" name="kunde" value="<?= h($prj['kunde'] ?? '') ?>"></div>
            <div class="f"><label for="ps">Stunden</label><input id="ps" name="stunden" type="number" min="0" max="80" value="<?= (int)($prj['stunden'] ?? 0) ?>"></div>
          </div>
          <div class="f"><label for="px">Status</label><select id="px" name="status"><?= optm(
            ['idee'=>'Idee','antrag'=>'Antrag geschrieben','eingereicht'=>'eingereicht','genehmigt'=>'genehmigt',
             'umsetzung'=>'Umsetzung','doku'=>'Dokumentation','abgegeben'=>'abgegeben','praesentiert'=>'praesentiert'],
            $prj['status'] ?? 'idee') ?></select></div>
          <div class="f"><label for="pa">Antrag</label>
            <textarea id="pa" name="antrag" style="min-height:150px" data-d="prj"><?= h($prj['antrag'] ?? '') ?></textarea></div>
          <button class="p" type="submit">Speichern</button>
        </form>
        <?php $st = (int)($prj['stunden'] ?? 0); if ($st): ?>
          <hr><div class="rw" style="justify-content:space-between"><span class="sm mu">Stunden</span>
            <span class="sm mo"><?= $st ?> / 80</span></div>
          <div class="br"><i style="width:<?= min(100, (int)round($st / 80 * 100)) ?>%"></i></div>
        <?php endif; ?>
      </div></div>
    </div>
    <?php endif; ?>
    <?php
    page('Pruefung', ob_get_clean());
}

// --- Suche -----------------------------------------------------------------
function p_suche(): void {
    $u = need_login(); $uid = (int)$u['id']; $qs = get('q');
    $tref = [];
    if (mb_strlen($qs) >= 2) {
        $l = '%' . $qs . '%';
        $tref['Notizen'] = array_map(fn($r) => [$r['titel'] ?: mb_substr($r['body'], 0, 60),
            dt($r['datum']) . ' · ' . $r['kind'], url('notizen', ['id' => $r['id']])],
            all("SELECT * FROM notes WHERE user_id = ? AND (titel LIKE ? OR body LIKE ? OR tags LIKE ?)
                 ORDER BY datum DESC LIMIT 20", [$uid, $l, $l, $l]));
        $tref['Termine'] = array_map(fn($r) => [$r['titel'], dt($r['datum']) . ' · ' . typ_label($r['typ']),
            url('termine', ['id' => $r['id']])],
            all("SELECT * FROM events WHERE user_id = ? AND (titel LIKE ? OR beschreibung LIKE ? OR stoff LIKE ?)
                 ORDER BY datum DESC LIMIT 20", [$uid, $l, $l, $l]));
        $tref['Aufgaben'] = array_map(fn($r) => [$r['titel'],
            ($r['faellig'] ? dt($r['faellig']) : 'ohne Frist') . ' · ' . $r['status'], url('aufgaben', ['id' => $r['id']])],
            all("SELECT * FROM tasks WHERE user_id = ? AND (titel LIKE ? OR beschreibung LIKE ?) LIMIT 20", [$uid, $l, $l]));
        $tref['Berichtsheft'] = array_map(fn($r) => [$r['text'], dt($r['datum']) . ' · ' . num((float)$r['stunden'], 1) . ' h',
            url('berichtsheft', ['periode' => periode_of($r['datum'], $u['bh_art']), 'art' => $u['bh_art']])],
            all("SELECT * FROM report_entries WHERE user_id = ? AND text LIKE ? ORDER BY datum DESC LIMIT 20", [$uid, $l]));
        $tref['Routinen'] = array_map(fn($r) => [$r['name'], $r['intervall'], url('routinen', ['id' => $r['id']])],
            all("SELECT * FROM routines WHERE user_id = ? AND name LIKE ? LIMIT 10", [$uid, $l]));
    }
    ob_start(); ?>
    <div class="c"><div class="bo">
      <form method="get" class="rw"><input type="hidden" name="p" value="suche">
        <input name="q" value="<?= h($qs) ?>" placeholder="Suchbegriff" autofocus style="flex:1">
        <button class="p" type="submit">Suchen</button></form>
    </div></div>
    <?php if (mb_strlen($qs) >= 2): $t = array_sum(array_map('count', $tref)); ?>
      <?php if (!$t): ?><div class="c"><?= em('Nichts gefunden.') ?></div><?php endif; ?>
      <?php foreach ($tref as $g => $items): if (!$items) continue; ?>
        <div class="c"><div class="hd"><h2><?= h($g) ?></h2><span class="sp"></span><span class="sm mu2"><?= count($items) ?></span></div>
          <div class="tw"><table><tbody>
            <?php foreach ($items as [$ti, $sub, $ur]): ?>
              <tr><td><a href="<?= h($ur) ?>"><?= h($ti) ?></a></td>
                <td class="sm mu2" style="width:200px"><?= h($sub) ?></td></tr>
            <?php endforeach; ?>
          </tbody></table></div></div>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php
    page('Suche', ob_get_clean());
}

// --- Einstellungen ---------------------------------------------------------
function p_einstellungen(): void {
    $u = need_login(); $uid = (int)$u['id'];
    $t = get('t') ?: 'profil';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $a = post('a');
        if ($a === 'profil') {
            $kl = mb_substr(post('klasse'), 0, 20);
            $zg = (int)post('zeitgruppe', '0');
            if (!$zg && preg_match('/(\d)\s*$/', $kl, $m)) $zg = (int)$m[1];
            upd('users', ['name' => mb_substr(post('name'), 0, 80),
                'email' => filter_var(post('email'), FILTER_VALIDATE_EMAIL) ?: null,
                'beruf' => mb_substr(post('beruf'), 0, 100), 'klasse' => $kl, 'zeitgruppe' => max(0, min(9, $zg)),
                'betrieb' => mb_substr(post('betrieb'), 0, 120), 'ausbilder' => mb_substr(post('ausbilder'), 0, 80),
                'abteilung' => mb_substr(post('abteilung'), 0, 80),
                'start' => post('start') ?: null, 'ende' => post('ende') ?: null,
                'wochenstunden' => max(0, (float)str_replace(',', '.', post('wochenstunden', '40'))),
                'bh_art' => post('bh_art') === 'monat' ? 'monat' : 'woche'], 'id = :id', ['id' => $uid]);
            flash('Gespeichert.');
        } elseif ($a === 'fach') {
            $id = (int)post('id', '0');
            $d = ['name' => mb_substr(post('name'), 0, 80), 'short' => mb_substr(post('short'), 0, 10),
                'lf_no' => inull(postn('lf_no')), 'lehrer' => mb_substr(post('lehrer'), 0, 60),
                'color' => preg_match('/^#[0-9a-f]{6}$/i', post('color')) ? post('color') : '#2563eb',
                'sort' => (int)post('sort', '0'), 'archiv' => post('archiv') ? 1 : 0];
            if ($d['name'] === '') flash('Name fehlt.', 'err');
            elseif ($id) upd('subjects', $d, 'id = :id AND user_id = :u', ['id' => $id, 'u' => $uid]);
            else { $d['user_id'] = $uid; ins('subjects', $d); }
        } elseif ($a === 'fachdel') {
            del('subjects', 'id = ? AND user_id = ?', [(int)post('id', '0'), $uid]);
        } elseif ($a === 'plan') {
            for ($tg = 1; $tg <= 5; $tg++) for ($st = 1; $st <= 11; $st++) {
                $sid = inull(postn("c{$tg}_{$st}")); $raum = post("r{$tg}_{$st}");
                if ($sid === null && $raum === '') { del('timetable', 'user_id = ? AND tag = ? AND stunde = ?', [$uid, $tg, $st]); continue; }
                q("INSERT INTO timetable (user_id,tag,stunde,subject_id,raum) VALUES (:u,:t,:s,:i,:r)
                   ON CONFLICT(user_id,tag,stunde) DO UPDATE SET subject_id = :i2, raum = :r2",
                  ['u' => $uid, 't' => $tg, 's' => $st, 'i' => $sid, 'r' => $raum, 'i2' => $sid, 'r2' => $raum]);
            }
            flash('Stundenplan gespeichert.');
        } elseif ($a === 'block') {
            $von = isodate(post('von')) ? post('von') : today();
            ins('blocks', ['user_id' => $uid, 'von' => $von,
                'bis' => isodate(post('bis')) && post('bis') >= $von ? post('bis') : $von,
                'art' => in_array(post('art'), ['schule','betrieb','ferien','ueba','pruefung'], true) ? post('art') : 'schule',
                'label' => mb_substr(post('label'), 0, 60)]);
        } elseif ($a === 'blockdel') {
            del('blocks', 'id = ? AND user_id = ?', [(int)post('id', '0'), $uid]);
        } elseif ($a === 'pw') {
            $alt = (string)($_POST['alt'] ?? ''); $neu = (string)($_POST['neu'] ?? '');
            if (!rl('pw:' . $uid, 10, 900)) flash('Zu viele Versuche.', 'err');
            elseif (!password_verify($alt, $u['pass_hash'])) flash('Aktuelles Passwort falsch.', 'err');
            elseif ($neu !== (string)($_POST['neu2'] ?? '')) flash('Passwoerter ungleich.', 'err');
            elseif ($p = pw_problems($neu, $u['username'], $u['name'])) flash('Passwort: ' . implode(', ', $p), 'err');
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
    $rc = $_SESSION['rc'] ?? null; unset($_SESSION['rc']);
    $sec = $_SESSION['tfa'] ?? null;
    ob_start(); ?>
    <div class="ch np" style="margin-bottom:12px">
      <?php foreach (['profil'=>'Profil','faecher'=>'Faecher','plan'=>'Stundenplan','sicherheit'=>'Sicherheit','daten'=>'Daten'] as $k => $l): ?>
        <a class="<?= $t === $k ? 'on' : '' ?>" href="<?= url('einstellungen', ['t' => $k]) ?>"><?= h($l) ?></a>
      <?php endforeach; ?>
    </div>

    <?php if ($t === 'profil'): ?>
      <div class="c" style="max-width:620px"><div class="bo">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="a" value="profil"><input type="hidden" name="t" value="profil">
          <div class="fg">
            <div class="f"><label>Benutzername</label><input value="<?= h($u['username']) ?>" disabled></div>
            <div class="f"><label for="nm">Name</label><input id="nm" name="name" value="<?= h($u['name']) ?>"></div>
          </div>
          <div class="fg">
            <div class="f"><label for="em">E-Mail</label><input id="em" name="email" type="email" value="<?= h($u['email']) ?>"></div>
            <div class="f"><label for="kl">Klasse</label><input id="kl" name="klasse" value="<?= h($u['klasse']) ?>" placeholder="1FS152"></div>
            <div class="f"><label for="zg">Zeitgruppe</label><input id="zg" name="zeitgruppe" type="number" min="0" max="9" value="<?= (int)$u['zeitgruppe'] ?>"></div>
          </div>
          <div class="f"><label for="br">Beruf</label><input id="br" name="beruf" value="<?= h($u['beruf']) ?>"></div>
          <div class="fg">
            <div class="f"><label for="st">Beginn</label><input id="st" name="start" type="date" value="<?= h($u['start']) ?>"></div>
            <div class="f"><label for="en">Ende</label><input id="en" name="ende" type="date" value="<?= h($u['ende']) ?>"></div>
            <div class="f"><label for="ws">Wochenstunden</label><input id="ws" name="wochenstunden" inputmode="decimal" value="<?= h($u['wochenstunden']) ?>"></div>
          </div>
          <div class="fg">
            <div class="f"><label for="bt">Betrieb</label><input id="bt" name="betrieb" value="<?= h($u['betrieb']) ?>"></div>
            <div class="f"><label for="ab">Abteilung</label><input id="ab" name="abteilung" value="<?= h($u['abteilung']) ?>"></div>
          </div>
          <div class="fg">
            <div class="f"><label for="au">Ausbilder/-in</label><input id="au" name="ausbilder" value="<?= h($u['ausbilder']) ?>"></div>
            <div class="f"><label for="bh">Berichtsheft</label><select id="bh" name="bh_art"><?= optm(['woche'=>'woechentlich','monat'=>'monatlich'], $u['bh_art']) ?></select></div>
          </div>
          <button class="p" type="submit">Speichern</button>
        </form>
      </div></div>

    <?php elseif ($t === 'faecher'):
      $rows = all("SELECT * FROM subjects WHERE user_id = ? ORDER BY sort, name", [$uid]);
      $e = get('id') !== '' ? one("SELECT * FROM subjects WHERE id = ? AND user_id = ?", [(int)get('id'), $uid]) : null; ?>
      <div class="sp2">
        <div class="c">
          <div class="hd"><h2>Faecher</h2><span class="sp"></span><input placeholder="Filtern" data-fl="#fx" style="width:130px"></div>
          <div class="tw"><table id="fx"><tbody>
            <?php foreach ($rows as $s): ?>
              <tr<?= (int)$s['archiv'] ? ' style="opacity:.4"' : '' ?>>
                <td style="width:16px"><span class="dot" style="background:<?= h($s['color']) ?>"></span></td>
                <td style="width:64px" class="mo sm"><?= h($s['short']) ?></td>
                <td><a href="<?= url('einstellungen', ['t' => 'faecher', 'id' => $s['id']]) ?>"><?= h($s['name']) ?></a></td>
                <td class="sm mu2" style="width:110px"><?= h($s['lehrer']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </div>
        <div class="c"><div class="hd"><h2><?= $e ? 'Fach' : 'Neues Fach' ?></h2></div><div class="bo">
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="a" value="fach"><input type="hidden" name="t" value="faecher">
            <input type="hidden" name="id" value="<?= (int)($e['id'] ?? 0) ?>">
            <div class="f"><label for="sn">Name</label><input id="sn" name="name" required value="<?= h($e['name'] ?? '') ?>"></div>
            <div class="fg">
              <div class="f"><label for="sk">Kuerzel</label><input id="sk" name="short" value="<?= h($e['short'] ?? '') ?>"></div>
              <div class="f"><label for="sc">Farbe</label><input id="sc" name="color" type="color" value="<?= h($e['color'] ?? '#2563eb') ?>"></div>
              <div class="f"><label for="so2">Sort</label><input id="so2" name="sort" type="number" value="<?= (int)($e['sort'] ?? 0) ?>"></div>
            </div>
            <div class="fg">
              <div class="f"><label for="sl">Lernfeld</label><select id="sl" name="lf_no"><?= lf_opts($e['lf_no'] ?? null) ?></select></div>
              <div class="f"><label for="sa">Archiv</label><select id="sa" name="archiv"><?= optm([0=>'nein',1=>'ja'], $e['archiv'] ?? 0) ?></select></div>
            </div>
            <div class="f"><label for="sle">Lehrkraft</label><input id="sle" name="lehrer" value="<?= h($e['lehrer'] ?? '') ?>"></div>
            <div class="rw"><button class="p" type="submit">Speichern</button>
              <?php if ($e): ?><a class="bt g" href="<?= url('einstellungen', ['t' => 'faecher']) ?>">Neu</a><?php endif; ?></div>
          </form>
          <?php if ($e): ?>
            <hr><form method="post" data-q="Fach loeschen?"><?= csrf_field() ?>
              <input type="hidden" name="a" value="fachdel"><input type="hidden" name="t" value="faecher">
              <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
              <button class="d s" type="submit">Loeschen</button></form>
          <?php endif; ?>
        </div></div>
      </div>

    <?php elseif ($t === 'plan'):
      $tt = [];
      foreach (all("SELECT * FROM timetable WHERE user_id = ?", [$uid]) as $r) $tt[(int)$r['tag']][(int)$r['stunde']] = $r;
      $bl = all("SELECT * FROM blocks WHERE user_id = ? ORDER BY von DESC LIMIT 40", [$uid]); ?>
      <div class="sp2">
        <div class="c"><div class="hd"><h2>Stundenplan</h2></div><div class="bo">
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="a" value="plan"><input type="hidden" name="t" value="plan">
            <div class="tw"><table><thead><tr><th></th>
              <?php for ($tg = 1; $tg <= 5; $tg++): ?><th><?= h(mb_substr(wd($tg), 0, 2)) ?></th><?php endfor; ?></tr></thead><tbody>
              <?php for ($st = 1; $st <= 11; $st++): ?>
                <tr><td class="mu2 mo sm" style="width:20px"><?= $st ?></td>
                  <?php for ($tg = 1; $tg <= 5; $tg++): $c = $tt[$tg][$st] ?? null; ?>
                    <td style="padding:2px">
                      <select name="c<?= $tg ?>_<?= $st ?>" style="height:24px;font-size:12px"><?= fach_opts($uid, $c['subject_id'] ?? null, '–') ?></select>
                      <input name="r<?= $tg ?>_<?= $st ?>" value="<?= h($c['raum'] ?? '') ?>" placeholder="Raum" style="height:22px;font-size:11px;margin-top:2px"></td>
                  <?php endfor; ?></tr>
              <?php endfor; ?>
            </tbody></table></div>
            <button class="p" style="margin-top:8px" type="submit">Speichern</button>
          </form>
        </div></div>
        <div class="c"><div class="hd"><h2>Blockplan</h2></div><div class="bo">
          <form method="post" style="margin-bottom:10px">
            <?= csrf_field() ?><input type="hidden" name="a" value="block"><input type="hidden" name="t" value="plan">
            <div class="fg">
              <div class="f"><label for="bv">Von</label><input id="bv" name="von" type="date" required></div>
              <div class="f"><label for="bb">Bis</label><input id="bb" name="bis" type="date"></div>
            </div>
            <div class="fg">
              <div class="f"><label for="ba">Art</label><select id="ba" name="art"><?= optm(
                ['schule'=>'Schulblock','betrieb'=>'Betrieb','ferien'=>'Ferien','ueba'=>'UEBA','pruefung'=>'Pruefung'], 'schule') ?></select></div>
              <div class="f"><label for="bl2">Label</label><input id="bl2" name="label" placeholder="3. Block"></div>
            </div>
            <button class="p" type="submit">Eintragen</button>
          </form>
          <?php if ($bl): ?>
          <div class="tw" style="max-height:360px;overflow:auto"><table><tbody>
            <?php foreach ($bl as $b): $jetzt = today() >= $b['von'] && today() <= $b['bis']; ?>
              <tr><td style="width:14px"><span class="dot" style="background:<?= $b['art'] === 'schule' ? 'var(--ac)' : ($b['art'] === 'ferien' ? 'var(--ok)' : 'var(--wa)') ?>"></span></td>
                <td class="mo sm" style="white-space:nowrap"><?= h(dt($b['von'], 'd.m.y')) ?>–<?= h(dt($b['bis'], 'd.m.y')) ?></td>
                <td class="sm"><?= h(ucfirst($b['art'])) ?> <?= h($b['label']) ?><?= $jetzt ? ' <span class="tg a">jetzt</span>' : '' ?></td>
                <td style="width:28px"><form method="post"><?= csrf_field() ?>
                  <input type="hidden" name="a" value="blockdel"><input type="hidden" name="t" value="plan">
                  <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                  <button class="g s d" type="submit">&times;</button></form></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
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
            <li><a href="<?= url('export', ['w' => 'routinen']) ?>">Routine-Protokoll (CSV)</a></li>
            <li><a href="<?= url('export', ['w' => 'notizen']) ?>">Notizen (CSV)</a></li>
          </ul>
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
    page('Einstellungen', ob_get_clean());
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
    $d = ['profil' => array_diff_key($u, array_flip(['pass_hash','totp_secret','recovery']))];
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

// --- Router ----------------------------------------------------------------
$p = $_GET['p'] ?? 'heute';
if (!is_string($p)) $p = 'heute';
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
        if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_check();
        logout();
    case 'theme':
        need_login(); csrf_check();
        upd('users', ['theme' => in_array(post('theme'), ['auto','hell','dunkel'], true) ? post('theme') : 'auto'],
            'id = :id', ['id' => (int)me()['id']]);
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        redirect(str_starts_with($ref, abs_url('/')) ? $ref : url('heute'));
    case 'neu':           a_neu(); break;
    case 'datei':         a_datei(); break;
    case 'export':        a_export(); break;
    case 'heute':         p_heute(); break;
    case 'termine':       p_termine(); break;
    case 'aufgaben':      p_aufgaben(); break;
    case 'notizen':       p_notizen(); break;
    case 'noten':         p_noten(); break;
    case 'berichtsheft':  p_berichtsheft(); break;
    case 'routinen':      p_routinen(); break;
    case 'pruefung':      p_pruefung(); break;
    case 'einstellungen': p_einstellungen(); break;
    case 'suche':         p_suche(); break;
    default:
        http_response_code(404);
        need_login();
        page('Nicht gefunden', '<div class="c"><div class="bo">Diese Seite gibt es nicht. '
            . '<a href="' . url('heute') . '">Zur Startseite</a></div></div>');
}
