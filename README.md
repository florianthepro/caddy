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

## bsfisazubi.de - Ausbildungsportal (Fachinformatiker Systemintegration)

Eine Datei: [`www/bsfisazubi.de/index.php`](./www/bsfisazubi.de/index.php).
SQLite, kein Composer, keine CDNs. Jedes Konto steht fuer sich - eigene Faecher,
eigene Notizen, eigenes Berichtsheft. Keine Rollen, keine Verwaltung.

Aufgebaut entlang der Faecher, nicht entlang von Terminen: jede Information
haengt an dem Fach, zu dem sie gehoert.

### Navigation

`1` Uebersicht - `2` Faecher - `3` Notizen - `4` Noten - `5` Plan -
`6` Berichtsheft - `7` Pruefung.
Dazu `/` Suche, `n` neuer Eintrag, `Strg+K` Sprungliste mit Sofortsuche.

### Anbindung an die Schule

* **Blockplan** - laedt das oeffentliche Archiv der BS FiSi
  ([Blockplaene](https://bsfisi.m-bildung.de/service/blockplaene)) und uebernimmt
  Blockwochen, Ferien und Schultermine fuer die eigene Zeitgruppe und
  Jahrgangsstufe. Die IHK-Pruefungstermine landen dabei direkt im Profil.
  Zu finden unter *Plan > Blockplan*.
* **iCal-Quellen** - WebUntis, Moodle und mebis/BYCS geben jeweils eine
  persoenliche Kalenderadresse aus. Als Quelle eingetragen wird sie regelmaessig
  abgerufen, wahlweise als Termine oder als Stundenplan.
* **WebUntis** - optional direkt ueber die JSON-RPC-Schnittstelle (Stundenplan
  und, wenn der Server es unterstuetzt, Pruefungen). Zugangsdaten werden mit
  libsodium verschluesselt abgelegt.
* **Externe Suchziele** - frei definierbare Adressen mit `%s`; sie erscheinen in
  der Sprungliste, damit ein Suchbegriff direkt in WebUntis oder Moodle weiterlaeuft.

Importe gehen nur ueber https und niemals ins lokale Netz; `IMPORT_PRIVAT`
erlaubt das ausdruecklich fuer einen eigenen Server.

### Suche

Volltextindex (SQLite FTS5) ueber Notizen, Termine, Aufgaben, Berichtsheft und
Routinen. Ergebnisse erscheinen waehrend des Tippens in `Strg+K`.
Filter: `lf:9`, `fach:LF9`, `typ:notiz`.

### Fachseite

Pro Fach an einem Ort: Notizen nach Art gruppiert (Stoff, How-To, Snippet),
Noten mit Schnitt und Verlauf, kommende Proben mit Stoffliste, offene Aufgaben,
angehaengtes Material - und jeweils eine Erfassung direkt daneben.

### Berichtsheft

Wochen- oder Monatsnachweis, ordnet Taetigkeiten automatisch den
Berufsbildpositionen der Ausbildungsordnung zu ("Kaffeemaschine geleert" ->
Allgemeine Officetaetigkeiten), fuellt sich aus Routinen, Notizen, Blockplan und
Abwesenheiten und druckt IHK-konform.

### Installation

1. `index.php` nach `C:\\caddy\\www\\bsfisazubi.de\\` kopieren
2. In `C:\\php\\php.ini` aktivieren: `extension=pdo_sqlite`, `sqlite3`,
   `mbstring`, `openssl`, `fileinfo`, `curl`, `sodium`
   (die letzten beiden nur fuer Importe und gespeicherte Zugangsdaten)
3. Den Block `bsfisazubi.de` aus dem [caddyfile](./caddyfile) uebernehmen:
   ```
   "C:\\caddy\\caddy.exe" reload --config "C:\\caddy\\caddyfile"
   ```
4. Aufrufen. Das erste Konto legt sich ohne Code an. Jedes weitere braucht den
   Code aus `C:\\caddy\\www\\bsfisazubi.de-data\\REGISTRIERUNG.txt`
   (oder `REGISTRIER_CODE` oben in der Datei fest setzen).

Daten liegen in `C:\\caddy\\www\\bsfisazubi.de-data`, also neben dem Webroot. Dieser Ordner gehoert ins Backup.

### Sicherheit

Argon2id, Passwortrichtlinie, Brute-Force-Schutz pro IP und Konto, CSRF-Token,
gehaertete Sessions mit serverseitigem Widerruf, optionale TOTP-Zwei-Faktor-
Anmeldung mit QR-Code, strikte CSP mit Script-Nonce, ausschliesslich Prepared
Statements, Uploads als BLOB mit MIME-Whitelist. Importe pruefen Schema und
Zieladresse gegen lokale Netze.
