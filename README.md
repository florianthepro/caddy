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

Die Registrierung fragt drei Dinge: Benutzername, Klasse, Passwort. Sonst
nichts - keine E-Mail, kein voller Name, kein Geburtsdatum, keine Schule, kein
Betrieb, kein Ausbildungsbeginn.

Alles Weitere leitet sich aus der Klasse ab. `2FS152` heisst zweite
Jahrgangsstufe, Kuerzel FS, laufende Nummer 15, Zeitgruppe 2; `WFS152` ist die
Verkuerzerklasse. Aus Stufe und Eingabetag ergibt sich der Vertragsbeginn und
daraus das Ausbildungsjahr: im August 2026 steht dort *2FS152, 2.
Ausbildungsjahr, ab 15.09.2026 3FS152*. Den Schuljahreswechsel liefern die
importierten Ferientermine.

Was danach noch fehlt, wird erst gefragt, wenn es gebraucht wird - und dann
behalten. Der erste Ausdruck eines Nachweises fragt Name, Geburtsdatum
(freiwillig), Betrieb und den aus der Klasse abgeleiteten Ausbildungsbeginn;
der zweite fragt nichts mehr. Der volle Name bleibt die einzige Angabe mit
Haken: ohne ihn lebt er nur in der Sitzung. Alles davon steht danach unter
*Mehr > Profil* in drei kleinen Formularen und laesst sich dort aendern oder
loeschen. Ein geaenderter Beginn rechnet die Ausbildungsjahre aller Nachweise
nach und sagt, wie viele es waren.

Ein neues Konto bekommt die Faecher der Stundentafel - zwoelf Lernfelder nach
Rahmenlehrplan und die allgemeinbildenden Faecher. Sonst nichts: keine
Beispielroutinen, keine Beispieltermine, keine Musterkontakte. Was regelmaessig
anfaellt, legt jeder selbst fest. Konten aus einer frueheren Fassung sehen ihre
zehn vorangelegten Routinen als *Beispiel* markiert und werden sie mit einem
Knopf los; sobald eine davon bearbeitet oder abgehakt wird, gilt sie als
uebernommen.

### Navigation

Sechs Gruppen mit ihren Unterpunkten - dieselbe Aufteilung auf jedem Geraet:

| Gruppe | Unterpunkte |
|---|---|
| Heute | – |
| Schule | Faecher · Notizen · Noten |
| Plan | Termine · Aufgaben · Stundenplan · Blockplan |
| Betrieb | Berichtsheft · Einsaetze · Kontakte |
| Abschluss | Pruefung · Projekt · Lernfelder |
| Mehr | Alles · Profil · Quellen · Sicherheit · Daten |

Keine Seitenleiste und keine Schublade. Am Rechner stehen die Gruppen in der
Kopfleiste neben dem Namen, die Unterpunkte in einer zweiten Reihe darunter;
Kopfreihen und Inhalt teilen sich dieselbe 1220px-Spalte, also fluchten Marke,
Gruppe und Seitentitel auf einer Linie. Auf dem Handy wandern die Gruppen an
den unteren Rand in den Daumenbereich - mit Symbol und Beschriftung, ueber der
Home-Indicator-Zone - waehrend oben die Suche die volle Breite bekommt und die
Unterpunkte als Segmentreihe darunter bleiben. Gleiche Struktur, gleiche
Reihenfolge, gleiche Namen; nur die Leiste sitzt dort, wo der Daumen ist.
Die offene Gruppe rueckt von selbst ins Bild, schmale Unterpunktreihen scrollen
waagerecht mit weicher Kante. Tasten `1`–`9` springen auf die ersten neun
Unterpunkte, `/` in die Suche, `Strg+K` in die Sprungliste.

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

* **Schulsuche im Profil** - ein GET-Formular, das im oeffentlichen
  WebUntis-Verzeichnis nach Name oder Strasse sucht; ein Klick auf einen Treffer
  traegt Server und Schulkuerzel ein. Laeuft ohne JavaScript. Fuer Klassen mit
  dem Kuerzel `FS` ist die BS FiSi bereits vorbelegt. Wer Zugangsdaten
  hinterlegt, kann die Klassenliste spaeter direkt aus WebUntis abrufen
  (anonymer Zugriff ist dort gesperrt).
* **Blockplan mit einem Knopf** - *Plan > Blockplan > Blockplan holen* nimmt
  Zeitgruppe und Jahrgangsstufe aus der Klasse, laedt das oeffentliche Archiv der
  BS FiSi ([Blockplaene](https://bsfisi.m-bildung.de/service/blockplaene)) und
  uebernimmt Blockwochen, Ferien und Schultermine; beim ersten Mal legt es
  gleich die Ferienquelle des Bundeslandes mit an. Die IHK-Pruefungstermine
  landen direkt im Profil. Solange keine Blockwoche eingetragen ist, steht der
  Weg dorthin als Chip auf *Heute*. Ein anderes Schuljahr waehlt man darunter.
* **Ferien und Feiertage** - ein Klick unter *Einstellungen > Quellen* holt
  Schulferien und gesetzliche Feiertage des Bundeslandes aus dem offenen
  Verzeichnis [openholidaysapi.org](https://openholidaysapi.org). Sie erscheinen
  im Plan, im Berichtsheft und bestimmen den Schuljahreswechsel.
* **Moodle / mebis** - die Quellenart *Moodle* nimmt die Kalender-URL aus
  *Kalender > Kalender exportieren > Kalender-URL abfragen*, prueft sie, stellt
  sie auf alle kommenden Termine um und legt sie **verschluesselt** ab; in der
  Quellenliste steht nur die Adresse ohne `authtoken`. Ohne die Extension
  `sodium` wird sie gar nicht gespeichert. Termine bringen ihren Kurslink mit.
* **iCal-Quellen** - jede andere Plattform, die eine persoenliche
  Kalenderadresse ausgibt. Wahlweise als Termine oder als Stundenplan.
* **WebUntis** - optional direkt ueber die JSON-RPC-Schnittstelle (Stundenplan
  und, wenn der Server es unterstuetzt, Pruefungen). Zugangsdaten werden mit
  libsodium verschluesselt abgelegt.
* **Schul-Apps** - frei verknuepfbare Adressen unter *Quellen*. Sie stehen
  danach in derselben Rangfolge wie die eigenen Seiten: in der Sprungliste, in
  der serverseitigen Suche und unter *Mehr* in der Gruppe *Verknuepft*. Steht
  ein `%s` darin, laeuft der Suchbegriff direkt in WebUntis oder Moodle weiter.

Importe gehen nur ueber https und niemals ins lokale Netz; jede Umleitung wird
einzeln geprueft, damit sie nicht daran vorbeifuehrt. `IMPORT_PRIVAT` erlaubt
den eigenen Server ausdruecklich.

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
jederzeit aufhebbar, `noindex`, mit eigener Ratenbegrenzung. Ein geteilter
Nachweis traegt die Klasse, nicht den Namen; der volle Name steht nur darauf,
wenn er beim Erstellen des Links ausdruecklich freigegeben wurde.
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

Breite Tabellen stapeln unter 700px zu Zeilen statt seitlich zu scrollen -
Termine, Aufgaben, Routine-Protokoll, Ausbildungsplan und Lernfelder; die
Spaltenueberschrift wandert als `data-l` vor den Wert. Der Stundenplan zeigt
zuerst die Woche als Gitter, das Bearbeiten liegt darunter zugeklappt.
Gruppenleiste unten im Daumenbereich, Eingabefelder mit 16px (sonst zoomt Safari beim
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
