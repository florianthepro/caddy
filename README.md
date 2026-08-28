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

Aufgebaut entlang der Faecher und des Betriebs, nicht entlang von Terminen:
jede Information haengt an der Stelle, zu der sie gehoert.

### Die Jetzt-Karte

Der Startbildschirm beginnt mit einer einzigen Karte, die nach Zeitnaehe
entscheidet, was gerade zaehlt: laufender Unterricht mit Raum und Endzeit,
Termin heute, ueberfaellige Aufgabe, Termin morgen, Projektfrist in Sicht,
offener Nachweis ab Donnerstag, laufender Block oder Ferien, sonst der
naechste Termin innerhalb von drei Wochen. Steht nichts an, faellt sie
ersatzlos weg. Jede Karte fuehrt auf die Seite, auf der man es selbst
nachsieht.

### Konto

Nur ein Benutzername und ein Passwort. Keine E-Mail, kein voller Name, kein
Geburtsdatum. Wer einen Ausdruck braucht, der einen Namen traegt, gibt ihn
vorher in einer Maske ein - auf Wunsch gemerkt, sonst nur fuer die Sitzung.

Ausbildungsjahr und Klasse ergeben sich aus Klassenbezeichnung und
Ausbildungsbeginn und werden nicht abgefragt: aus `2FS152` und dem 10.09.2024
wird im August 2026 *2FS152, 2. Ausbildungsjahr, ab 15.09.2026 3FS152*. Der
Schuljahreswechsel kommt aus den importierten Ferienterminen, das
Ausbildungsjahr aus dem Vertragsbeginn.

### Navigation

Eine Kopfleiste mit zwei Reihen - auf dem Handy genauso wie am Rechner. Oben
sechs Gruppen, darunter ihre Unterpunkte:

| Gruppe | Unterpunkte |
|---|---|
| Heute | – |
| Schule | Faecher · Notizen · Noten |
| Plan | Termine · Aufgaben · Stundenplan · Blockplan |
| Betrieb | Berichtsheft · Einsaetze · Kontakte |
| Abschluss | Pruefung · Projekt · Lernfelder |
| Mehr | Alles · Profil · Quellen · Sicherheit · Daten |

Keine Seitenleiste, keine Schublade, keine untere Leiste: dieselbe Struktur auf
jedem Geraet, damit man an einer Stelle lernt, wo etwas liegt. Auf schmalen
Schirmen scrollen beide Reihen waagerecht mit weicher Kante, die offene Gruppe
rueckt von selbst ins Bild, und die Suchpille wird zum Knopf. Tasten `1`–`9`
springen auf die ersten neun Unterpunkte, `/` in die Suche, `Strg+K` in die
Sprungliste.

*Mehr → Alles* listet jedes Ziel als Zeile mit Symbol, Wert und Chevron und ist
damit zugleich die Antwortliste: Noten `2,13`, Berichtsheft `3,5 h`,
Fehlzeiten `18 T frei`, Einsaetze `IT-Betrieb`.

### Suche

Ein Feld, ueberall dasselbe: die Suchpille oben oeffnet am Rechner wie auf dem
Handy dieselbe Sofortsuche (`Strg+K`); ohne JavaScript ist sie ein Formular
auf `?p=suche`, das genau dasselbe findet.

Drei Abschnitte in fester Reihenfolge:

1. **Antwort** - fertig gerechnet, damit man nicht erst eine Seite oeffnen
   muss: Resturlaub, Krankheitstage, Stand des laufenden Nachweises,
   Notenschnitt, naechste Blockwoche, laufende Ferien, naechster Termin mit
   Countdown, naechste Projektfrist, Ausbildungsstand, aktuelle Abteilung.
2. **Springen** - 23 Seiten und Reiter mit den Woertern, unter denen jemand
   danach sucht: `ur` findet *Fehlzeiten & Urlaub*, `doku` das
   *Abschlussprojekt*, `raum` den *Stundenplan*, `2fa` die *Sicherheit*.
   Was oft geoeffnet wird, steigt - gedeckelt auf 20 Punkte, damit ein genauer
   Treffer nie verdraengt wird.
3. **Treffer** - eine gerankte Liste, nicht eine Karte je Art: Titeltreffer vor
   Texttreffer, frisch vor alt. Volltextindex (SQLite FTS5) ueber Notizen,
   Termine, Aufgaben, Berichtsheft und Routinen; direkt dazu Faecher,
   Lernfelder, Noten, Abschlussprojekt, Fehlzeiten, Schulbloecke,
   Berufsbildpositionen, Ansprechpartner, Einsaetze und Dateien.

Deutsche Komposita greifen: `maschine` findet `Kaffeemaschine`. Praefixsuche
zuerst, Suche im Wort als Rueckfall ab vier Zeichen.
Filter: `lf:9`, `fach:LF9`, `typ:notiz` - auch allein, ohne Suchwort.

### Was wo liegt

| Seite | Inhalt |
|---|---|
| Heute | die Jetzt-Karte, Wochenstreifen, Schnellerfassung, Faecher mit Inhalt, anstehende Termine, offene Routinen |
| Faecher | je Fach: Notizen nach Art, Noten mit Schnitt und Verlauf, Proben mit Stoff, Material |
| Notizen | alles Festgehaltene mit Filter nach Art und Lernfeld |
| Noten | Noten, Schnitt je Fach, Verteilung, Verlauf |
| Plan | Termine, Aufgaben, Stundenplan, Blockplan |
| Berichtsheft | Wochen-/Monatsnachweis, alle Nachweise, Ausbildungsplan, Routinen |
| Einsaetze | Abteilungsdurchlauf, Fehlzeiten, Urlaubskonto |
| Kontakte | Ansprechpartner in Betrieb, Schule und IHK |
| Pruefung | Teil 1 und 2, Punkte und Prognose, Abschlussprojekt, Lernfelder |

### Anbindung an die Schule

* **Schulsuche in der Registrierung** - sucht im oeffentlichen WebUntis-Verzeichnis
  nach Name oder Strasse und uebernimmt Server und Schulkuerzel. Aus der
  Klassenbezeichnung (z.B. `2FS152`) leitet das Formular Stufe, laufende Nummer
  und Zeitgruppe ab und zeigt sofort, was daraus folgt. Wer Zugangsdaten
  hinterlegt, kann die Klassenliste spaeter direkt aus WebUntis abrufen
  (anonymer Zugriff ist dort meist gesperrt).
* **Blockplan** - laedt das oeffentliche Archiv der BS FiSi
  ([Blockplaene](https://bsfisi.m-bildung.de/service/blockplaene)) und uebernimmt
  Blockwochen, Ferien und Schultermine fuer die eigene Zeitgruppe und
  Jahrgangsstufe. Die IHK-Pruefungstermine landen dabei direkt im Profil.
  Zu finden unter *Plan > Blockplan*.
* **Ferien und Feiertage** - ein Klick unter *Einstellungen > Quellen* holt
  Schulferien und gesetzliche Feiertage des Bundeslandes aus dem offenen
  Verzeichnis [openholidaysapi.org](https://openholidaysapi.org). Sie erscheinen
  im Plan, im Berichtsheft und bestimmen den Schuljahreswechsel.
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

### Fachseite

Pro Fach an einem Ort: Notizen nach Art gruppiert (Stoff, How-To, Snippet),
Noten mit Schnitt und Verlauf, kommende Proben mit Stoffliste, offene Aufgaben,
angehaengtes Material - und jeweils eine Erfassung direkt daneben.

### Berichtsheft

Wochen- oder Monatsnachweis, ordnet Taetigkeiten automatisch den
Berufsbildpositionen der Ausbildungsordnung zu ("Kaffeemaschine geleert" ->
Allgemeine Officetaetigkeiten), fuellt sich aus Routinen, Notizen, Blockplan und
Abwesenheiten, uebernimmt die Abteilung aus dem Einsatzplan und druckt
IHK-konform.

Der Reiter *Ausbildungsplan* zeigt daraus, welche Berufsbildpositionen der
FIAusbV schon belegt sind und welche noch offen stehen - dazu die Lernfelder
mit ihrem gesammelten Material.

### Betrieb

* **Einsaetze** - Abteilungsdurchlauf mit Zeitraum, Schwerpunkt und
  Ansprechpartner. Der Nachweis traegt die passende Abteilung von selbst ein.
* **Fehlzeiten und Urlaub** - krank, Urlaub, frei, Dienstreise, Schulung;
  Urlaubsanspruch und Resturlaub, offene Entschuldigungen fuer die Schule.
* **Kontakte** - Ausbilder, Lehrkraefte, IHK; mit Rolle, Erreichbarkeit und Fach.

### Abschlusspruefung

Teil 1 und Teil 2 mit Punkten, Gewichtung und Prognose samt Bestehensregeln.
Das Abschlussprojekt fuehrt Titel, Status, Stundenbudget und Phasen sowie alle
Fristen (Antrag, Genehmigung, Durchfuehrung, Dokumentation, Praesentation) -
auf Knopfdruck wandern sie als Termine in den Plan.

### Teilen

Der einzige Weg, auf dem etwas dieses Konto verlaesst. Notiz, Fach oder
Ausbildungsnachweis bekommen eine eigene Adresse - entweder fuer jeden mit dem
Link oder nur fuer bestimmte Benutzernamen. Nur lesend, ohne Kommentare,
jederzeit aufhebbar, `noindex`, mit eigener Ratenbegrenzung.
Uebersicht unter *Einstellungen > Daten > Geteilte Links*.

### Installation

1. `index.php` nach `C:\caddy\www\bsfisazubi.de\` kopieren
2. In `C:\php\php.ini` aktivieren: `extension=pdo_sqlite`, `sqlite3`,
   `mbstring`, `openssl`, `fileinfo`, `curl`, `sodium`
   (die letzten beiden nur fuer Importe und gespeicherte Zugangsdaten)
3. Den Block `bsfisazubi.de` aus dem [caddyfile](./caddyfile) uebernehmen:
   ```
   "C:\caddy\caddy.exe" reload --config "C:\caddy\caddyfile"
   ```
4. Aufrufen. Das erste Konto legt sich ohne Code an. Jedes weitere braucht den
   Code aus `C:\caddy\www\bsfisazubi.de-data\REGISTRIERUNG.txt`
   (oder `REGISTRIER_CODE` oben in der Datei fest setzen).

Daten liegen in `C:\caddy\www\bsfisazubi.de-data`, also neben dem Webroot.
Dieser Ordner gehoert ins Backup.

### Auf dem iPhone

Tab-Leiste statt Schublade, Eingabefelder mit 16px (sonst zoomt Safari beim
Antippen hinein), Tippflaechen ab 38px und Listenzeilen ab 46px,
`viewport-fit=cover` mit `env(safe-area-inset-*)` fuer Notch und
Home-Indicator, `:hover` nur unter `@media(hover:hover)` - sonst klebt der
Zustand nach dem Antippen. Die Tagestabelle des Berichtshefts stapelt unter
700px, statt seitlich zu scrollen.

Typografie in vier Stufen nach iOS-Vorbild: 28px Seitentitel, 17px
Abschnittsueberschrift in voller Farbe, 15px Zeilen, 13px Nebentext. Rang ist
damit ohne Lesen erkennbar.

### Sicherheit

Argon2id, Passwortrichtlinie, Brute-Force-Schutz pro IP und Konto, CSRF-Token,
gehaertete Sessions mit serverseitigem Widerruf, optionale TOTP-Zwei-Faktor-
Anmeldung mit QR-Code, strikte CSP mit Script-Nonce, ausschliesslich Prepared
Statements, Uploads als BLOB mit MIME-Whitelist. Importe pruefen Schema und
Zieladresse gegen lokale Netze. Geteilte Links tragen ein Zufallstoken,
werden nicht indiziert und lassen sich einzeln aufheben.
