## Instalation

Create Folder
```
mkdir "C:\caddy" && mkdir "C:\caddy\www"
```
Copy Caddy to Folder
```
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "Invoke-WebRequest -Uri 'https://caddyserver.com/api/download?os=windows&arch=amd64' -OutFile 'C:\caddy\caddy.exe'"
```
[Create your caddyfile](./caddyfileexample)
```
notepad C:\caddy\caddyfile
```
Create Watchdog
```
schtasks /create /tn "caddy watchdog" /sc minute /mo 5 /ru SYSTEM /rl HIGHEST /tr "powershell.exe -NoProfile -WindowStyle Hidden -Command \"if (Get-Process caddy -ErrorAction SilentlyContinue) { & 'C:\caddy\caddy.exe' reload --config 'C:\caddy\caddyfile' } else { Start-Process 'C:\caddy\caddy.exe' -ArgumentList 'run --config C:\caddy\caddyfile' }\"" /f && schtasks /run /tn "caddy watchdog"
```

## Commands

Start
```
C:\caddy\caddy.exe start
```
Stop
```
C:\caddy\caddy.exe stop
```
Refresh # use if updatet the caddyfile
```
"C:\caddy\caddy.exe" reload --config "C:\caddy\caddyfile"
```
Check Watchdog
```
schtasks /query /tn "caddy watchdog" /v /fo list
```

## PHP

[VSXXX x64 Non Thread Safe](https://www.php.net/downloads.php?os=windows&osvariant=windows-downloads&version=default)

VS17:
```
powershell -NoProfile -ExecutionPolicy Bypass -Command "New-Item -ItemType Directory 'C:\php' -Force; Invoke-WebRequest 'https://downloads.php.net/~windows/releases/archives/php-8.5.10-nts-Win32-vs17-x64.zip' -OutFile 'C:\php.zip'; Expand-Archive 'C:\php.zip' -DestinationPath 'C:\php' -Force; Copy-Item 'C:\php\php.ini-production' 'C:\php\php.ini' -Force" && schtasks /create /tn "php fastcgi" /sc onstart /ru SYSTEM /rl HIGHEST /tr "\"C:\php\php-cgi.exe\" -b 127.0.0.1:9000" /f && schtasks /run /tn "php fastcgi" && "C:\caddy\caddy.exe" reload --config "C:\caddy\caddyfile"
```

## bsfisazubi.de - Azubi-Portal (Fachinformatiker Systemintegration)

Eine einzige Datei: [`www/bsfisazubi.de/index.php`](./www/bsfisazubi.de/index.php).
Kein Composer, keine CDNs, keine externen Abhaengigkeiten. Datenbank ist SQLite und
wird beim ersten Aufruf automatisch angelegt.

### Was drin ist

| Bereich | Inhalt |
| --- | --- |
| Start | Schnellerfassung, Kennzahlen, naechste Proben, offene Aufgaben, Routinen von heute |
| Woche & Plan | Stundenplan, Blockplan nach Zeitgruppe, Wochenagenda |
| Proben & Termine | Klassenarbeiten, Tests, Abgaben, Lernstoff-Listen, Monatskalender, ICS-Abo |
| Aufgaben | Hausaufgaben und betriebliche To-dos mit Frist und Prioritaet |
| Notizen | Unterrichts- und Randnotizen mit Datum, Fach, Lernfeld, Tags, Anhaengen |
| Wissen & Stoff | Geteilte Zusammenfassungen, How-Tos und Code-Snippets, nach Lernfeld sortiert |
| Noten & Statistik | Noten 1-6 / 15 Punkte / IHK-Punkte, gewichtete Schnitte, Verteilung, Trend, IHK-Prognose |
| Berichtsheft | Wochen- und Monatsnachweis, automatische Kategorisierung, Einreichen und Abzeichnen, Druckansicht |
| Betrieb & Routinen | Wiederkehrende Aufgaben (Kaffeemaschine, Backup-Check, Ticketqueue) mit Zeitprotokoll |
| Abwesenheiten | Krank, Urlaub, frei - fliesst automatisch ins Berichtsheft |
| Lernfelder | LF 1-12 nach KMK-Rahmenlehrplan mit Verknuepfung zu Notizen und Terminen |
| IHK-Pruefung | Countdown, Pruefungsbereiche mit Gewichtung, Bestehensregeln, Projektarbeit-Tracker |
| Verwaltung | Benutzer, Klassen, Faecher, Kategorien und Erkennungsregeln, Einladungen, Sicherheitsprotokoll |

### Installation

1. `index.php` nach `C:\caddy\www\bsfisazubi.de\` kopieren.
2. In `C:\php\php.ini` aktivieren (Semikolon entfernen):
   `extension=pdo_sqlite`, `extension=sqlite3`, `extension=mbstring`,
   `extension=openssl`, `extension=fileinfo`
3. Den Block `bsfisazubi.de` aus dem [caddyfile](./caddyfile) uebernehmen und neu laden:
   ```
   "C:\caddy\caddy.exe" reload --config "C:\caddy\caddyfile"
   ```
4. Seite aufrufen. Beim ersten Start fuehrt eine Ersteinrichtung durch das Anlegen
   des Administrationskontos. Der dafuer noetige Token steht in
   `C:\caddy\www\bsfisazubi.de-data\SETUP-TOKEN.txt`; direkt vom Server aus
   (localhost) geht es auch ohne Token.

Das Datenverzeichnis `C:\caddy\www\bsfisazubi.de-data` liegt bewusst **neben**
dem Webroot und ist damit ueber den Webserver nicht erreichbar. Genau dieses
Verzeichnis gehoert ins Backup.

### Sicherheit

* Registrierung ausschliesslich per Einladungscode, kein offener Signup
* Argon2id-Passworthashes, Passwortrichtlinie, Blacklist gaengiger Woerter
* Optionale Zwei-Faktor-Anmeldung (TOTP) mit QR-Code und Wiederherstellungscodes
* CSRF-Token bei jeder schreibenden Aktion, Sessions serverseitig widerrufbar
* Brute-Force-Schutz pro IP und pro Konto, Honeypot, Timing-Angleichung
* Content-Security-Policy mit Script-Nonce, `X-Frame-Options`, `nosniff`, HSTS
* Ausschliesslich Prepared Statements, konsequentes Output-Escaping
* Uploads liegen als BLOB in der Datenbank, MIME-Whitelist, erzwungener Download
* Rollen: Auszubildende, Ausbilder, Lehrkraft, Administration - inkl. Audit-Log
