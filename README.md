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

## bsfisazubi.de – Ausbildungsportal (Fachinformatiker Systemintegration)

Eine Datei: [`www/bsfisazubi.de/index.php`](./www/bsfisazubi.de/index.php).
SQLite, kein Composer, keine CDNs. Jedes Konto steht fuer sich – eigene Faecher,
eigener Stundenplan, eigenes Berichtsheft. Keine Rollen, keine Verwaltung.

### Navigation

`1` Heute · `2` Termine · `3` Aufgaben · `4` Notizen · `5` Noten ·
`6` Berichtsheft · `7` Routinen · `8` Pruefung
Dazu `/` Suche, `n` neuer Eintrag, `Strg+K` Sprungliste, `Esc` raus aus dem Feld.

### Kern

* **Heute** – Wochenstreifen, Schnellerfassung, was ansteht, offene Routinen
* **Berichtsheft** – Wochen- oder Monatsnachweis, ordnet Taetigkeiten automatisch
  den Berufsbildpositionen der Ausbildungsordnung zu ("Kaffeemaschine geleert"
  → *Allgemeine Officetaetigkeiten*), fuellt sich aus Routinen, Notizen,
  Blockplan und Abwesenheiten, druckt IHK-konform
* **Noten** – Note 1–6, 15 Punkte oder IHK-Punkte, gewichtete Schnitte,
  Verteilung, Verlauf je Fach
* **Pruefung** – Countdown, Pruefungsbereiche mit Gewichtung, Bestehensregeln,
  Projektarbeit
* **Routinen** – wiederkehrende Aufgaben mit Zeitprotokoll
* **Termine / Aufgaben / Notizen** – Proben mit Lernstoff, To-dos, Notizen und
  Code-Snippets, alles nach Fach und Lernfeld filterbar

### Installation

1. `index.php` nach `C:\caddy\www\bsfisazubi.de\` kopieren
2. In `C:\php\php.ini` aktivieren: `extension=pdo_sqlite`, `sqlite3`,
   `mbstring`, `openssl`, `fileinfo`
3. Den Block `bsfisazubi.de` aus dem [caddyfile](./caddyfile) uebernehmen:
   ```
   "C:\caddy\caddy.exe" reload --config "C:\caddy\caddyfile"
   ```
4. Aufrufen. Das erste Konto legt sich ohne Code an. Jedes weitere braucht den
   Code aus `C:\caddy\www\bsfisazubi.de-data\REGISTRIERUNG.txt`
   (oder `REGISTRIER_CODE` oben in der Datei fest setzen).

Daten liegen in `C:\caddy\www\bsfisazubi.de-data`, also neben dem Webroot und
damit ausserhalb der Auslieferung. Dieser Ordner gehoert ins Backup.

### Sicherheit

Argon2id, Passwortrichtlinie, Brute-Force-Schutz pro IP und Konto, CSRF-Token,
gehaertete Sessions mit serverseitigem Widerruf, optionale TOTP-Zwei-Faktor-
Anmeldung mit QR-Code und Recovery-Codes, strikte CSP mit Script-Nonce,
ausschliesslich Prepared Statements, Uploads als BLOB mit MIME-Whitelist.
