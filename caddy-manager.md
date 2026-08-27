# Caddy Manager

Eine Datei: [`caddy-manager.bat`](./caddy-manager.bat). Doppelklick, fertig.

Ersetzt die Handarbeit aus der [README](./README.md) – Ordner anlegen, Caddy laden,
Caddyfile tippen, `schtasks`-Zeilen kopieren, PHP entpacken. Das macht die Datei
jetzt selbst, und danach braucht man sie nicht mehr: Caddy startet mit Windows und
wird alle drei Minuten überwacht. Zum Ändern von Domains startet man sie wieder.

## Was passiert beim Start

1. Windows fragt nach Administratorrechten (nötig für Aufgabenplanung, Firewall und `C:\caddy`).
2. Eine bereits vorhandene `C:\caddy\caddyfile` wird eingelesen und in die Domainliste übernommen –
   samt Bausteinen `(name) { … }`, `import`-Zeilen und allem, was der Manager nicht selbst kennt.
3. Vorhandene Zertifikate werden aus dem bisherigen Speicher nach `C:\caddy\data` übernommen.
   Ohne diesen Schritt würde Caddy alles neu beantragen und könnte in die Mengenbegrenzung
   von Let's Encrypt laufen.
4. Im Konsolenfenster steht eine Adresse wie `http://127.0.0.1:8787/?t=…` – der Browser öffnet sich automatisch.

Fenster schließen oder Strg+C beendet nur die Oberfläche. Der Webserver läuft weiter.

## Was die Oberfläche kann

| Bereich | Inhalt |
| --- | --- |
| Übersicht | Zustand von Caddy und PHP, Zertifikatslaufzeiten, Ablageorte, Start/Stopp/Neustart |
| Domains | Seiten anlegen und ändern – statisch, PHP, Reverse Proxy, Weiterleitung, fester Text |
| Einrichtung | Caddy, PHP, Autostart, Watchdog und Firewall einzeln oder in einem Rutsch |
| Sicherheit | Geprüfte Checkliste, jeder Punkt mit Knopf zum Beheben |
| Protokolle | Zugriffs- und Fehlerprotokolle, JSON wird lesbar aufbereitet |
| Einstellungen | E-Mail für Let's Encrypt, PHP-Prozesse, Protokollumfang |
| Experte | Caddyfile direkt bearbeiten, prüfen, formatieren, Sicherungen zurückholen |

Die Caddyfile wird aus der Domainliste erzeugt und ist byte-gleich mit dem, was
`caddy fmt` schreiben würde. Wer sie lieber selbst pflegt, schaltet unter *Experte*
auf den manuellen Modus um; der Weg zurück liest die Datei wieder ein.

## Sicherheit

Die Oberfläche läuft mit Administratorrechten, deshalb ist sie eng eingezäunt:

- Sie lauscht ausschließlich auf `127.0.0.1` – aus dem Netz nicht erreichbar, auch nicht über den Rechnernamen.
- Jeder Start erzeugt ein neues Zufallstoken. Ohne dieses Token kein Zugang.
- Ändernde Aufrufe brauchen zusätzlich einen CSRF-Wert aus dem Seitenquelltext, dazu Origin- und Host-Prüfung gegen DNS-Rebinding.
- Strenge Content-Security-Policy mit Nonce, keinerlei Inhalte von außen.
- Nach 60 Minuten ohne Nutzung beendet sich die Oberfläche von selbst (einstellbar).
- Jede Eingabe wird geprüft, bevor sie in die Caddyfile, einen Prozessaufruf oder auf die Platte gelangt.

Für die ausgelieferten Seiten setzt der Manager standardmäßig Sicherheitskopfzeilen
und sperrt Dateien wie `.env`, `.git`, `*.sql` oder `*.log`.

## Änderungen anwenden

Beim Übernehmen wird zuerst mit `caddy validate` geprüft, dann die laufende Datei
gesichert, dann geschrieben und ohne Unterbrechung neu geladen. Lehnt Caddy die neue
Fassung ab, kommt automatisch der vorherige Stand zurück. Die letzten 30 Sicherungen
lassen sich unter *Experte* mit einem Klick wiederherstellen.

## Ablage

```
C:\caddy\caddy.exe              Programm
C:\caddy\caddyfile              erzeugte Konfiguration
C:\caddy\www\<domain>\          Webseiten
C:\caddy\logs\                  Protokolle, rotieren automatisch
C:\caddy\data\                  Zertifikate
C:\caddy\manager\config.json    Domainliste und Einstellungen
C:\caddy\manager\backups\       Sicherungen
C:\php\                         PHP, falls installiert
```

Geplante Aufgaben liegen unter `\CaddyManager\` (Server, Watchdog, PHP-Prozesse).
Alte Aufgaben aus der Handinstallation erkennt und entfernt der Manager.

## Voraussetzungen

Windows 10 / Server 2016 oder neuer, Windows PowerShell 5.1 (ist enthalten),
Administratorrechte. x64 und ARM64 werden erkannt.
