# Caddy Manager

Eine Datei: [`caddy-manager.bat`](./caddy-manager.bat). Doppelklick, fertig.

Richtet Caddy vollständig ein — Programm, Autostart, Watchdog, Firewall, optional PHP —
und öffnet während der Laufzeit eine Oberfläche auf `http://127.0.0.1:8787`.
Danach läuft der Webserver ohne die Datei weiter.

## Beim ersten Start

- Eine vorhandene `C:\caddy\caddyfile` wird eingelesen: Domains, Bausteine `(name) { }`,
  `import`-Zeilen, eigene `storage`-Zeile, alles Unbekannte bleibt wörtlich erhalten.
- Vorhandene Zertifikate werden nach `C:\caddy\data` übernommen, statt sie neu zu beantragen.
- Alte Aufgaben aus der Handinstallation werden erkannt und ersetzt.
- Läuft Caddy daneben schon als Dienst (nssm, WinSW), wird das gemeldet und lässt sich
  unter *Sicherheit* anhalten — sonst streiten sich zwei Server um Port 80 und 443.
  Gelöscht wird nichts, der vorherige Starttyp landet in `C:\caddy\manager\backups`.

## Oberfläche

| Reiter | Inhalt |
| --- | --- |
| Domains | statisch, PHP, Proxy, Weiterleitung, Text |
| Einrichtung | Bausteine einzeln oder alles auf einmal |
| Sicherheit | geprüfte Liste, jeder Punkt mit Knopf |
| Logs | Zugriffs- und Fehlerprotokolle |
| Caddyfile | direkt bearbeiten, prüfen, Sicherungen zurückholen |
| Einstellungen | E-Mail, PHP, Protokolle, Dateirechte |

Die Caddyfile wird aus der Domainliste erzeugt und ist byte-gleich mit `caddy fmt`.
Wer sie selbst pflegen will, schaltet unter *Caddyfile* auf manuellen Modus.

Es wird nichts im Hintergrund geladen: aktualisiert wird beim Öffnen, nach jeder
Aktion und beim Zurückwechseln in den Reiter.

## Zugang

Die Oberfläche hat **keine Anmeldung** — bewusst so gewählt. Wer lokal auf
`127.0.0.1` zugreifen kann, darf alles.

Was trotzdem greift:

- Aus dem Netz nicht erreichbar, auch nicht über den Rechnernamen (Host-Prüfung).
- Ändernde Aufrufe brauchen eine eigene Kopfzeile und die richtige Herkunft. Ohne das
  könnte jede Webseite, die im Browser dieses Rechners geöffnet wird, per Formular
  auf `127.0.0.1` schreiben.
- Nach 60 Minuten ohne Nutzung beendet sich die Oberfläche.

## Dateirechte

Ein Ordner direkt unter `C:\` erbt von dort „Ändern" für alle angemeldeten Benutzer.
Damit könnte jemand ohne Administratorrechte `caddy.exe` oder `watchdog.ps1`
austauschen — beide laufen als SYSTEM. Der Manager entzieht diese Vererbung; nur
Administratoren und SYSTEM dürfen schreiben. Ein einzelnes Konto lässt sich unter
*Einstellungen* für `C:\caddy\www` freigeben.

## Änderungen anwenden

`caddy validate` → Sicherung → schreiben → unterbrechungsfrei neu laden. Lehnt Caddy ab,
kommt der vorherige Stand zurück. Die letzten 30 Sicherungen liegen unter *Caddyfile*.

## Ablage

```
C:\caddy\caddy.exe            Programm
C:\caddy\caddyfile            erzeugte Konfiguration
C:\caddy\www\<domain>\        Webseiten
C:\caddy\logs\                Protokolle, rotieren automatisch
C:\caddy\data\                Zertifikate
C:\caddy\manager\             Domainliste, Sicherungen, Watchdog
C:\php\                       PHP, falls installiert
```

Geplante Aufgaben unter `\CaddyManager\`.

## Voraussetzungen

Windows 10 / Server 2016 oder neuer, Windows PowerShell 5.1, Administratorrechte.
x64 und ARM64 werden erkannt.
