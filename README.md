```
.\caddy.exe run
.\caddy.exe run --config .\Caddyfile
```
```
# ============================================================
# GLOBALE OPTIONEN
# Diese Optionen stehen außerhalb aller Domain-Blöcke.
# ============================================================

{
    # E-Mail-Adresse für automatisch beantragte TLS-Zertifikate.
    # Empfohlen bei öffentlichen Domains.
    email admin@deine-domain.tld

    # Admin-API-Adresse.
    # Standard ist localhost:2019.
    # Dadurch ist die Admin-API nur lokal auf Windows erreichbar.
    admin localhost:2019

    # Caddy-Datenverzeichnis.
    # Dort werden unter anderem Zertifikate gespeichert.
    # Windows-Pfad mit / schreiben.
    storage file_system C:/caddy/data

    # Automatisches HTTPS deaktivieren.
    # Normalerweise NICHT aktivieren.
    # auto_https off

    # Nur HTTP verwenden und kein automatisches HTTPS aktivieren.
    # Alternative zu auto_https off:
    # auto_https disable_redirects

    # Caddy im Entwicklungsmodus starten.
    # Aktiviert unter anderem weniger strenge Standardwerte.
    # Nicht für den Produktivbetrieb verwenden.
    # debug
}


# ============================================================
# STATISCHE WEBSITE
# Verzeichnis C:/eins wird für diese Domain verwendet.
# ============================================================

deine-domain.tld {
    # Genau ein Stammverzeichnis pro Site-Block.
    # Windows-Pfad:
    root * C:/eins

    # Mehrere Verzeichnisse sind nicht direkt als root möglich.
    # Für mehrere Verzeichnisse nutzt du handle_path oder handle.
    #
    # Beispiel:
    # handle_path /bilder/* {
    #     root * C:/bilder
    #     file_server
    # }
    #
    # handle_path /downloads/* {
    #     root * C:/downloads
    #     file_server
    # }

    # Antwortkompression aktivieren.
    # Möglich sind:
    # encode gzip
    # encode zstd
    # encode gzip zstd
    encode gzip zstd

    # Statische Dateien aus dem root-Verzeichnis ausliefern.
    file_server

    # Standardmäßig wird unter anderem index.html verwendet.
    # Eigene Reihenfolge:
    # file_server {
    #     index index.html index.htm
    # }

    # Verzeichnisauflistung aktivieren.
    # Nur einschalten, wenn Besucher Dateien auflisten dürfen.
    # file_server browse

    # Zugriffsprotokoll in eine Windows-Datei schreiben.
    # Der Ordner muss existieren oder vorher erstellt werden.
    log {
        output file C:/caddy/logs/deine-domain-access.log
        format json
    }

    # Zusätzliche HTTP-Header.
    # Sicherheitsheader sollten vor dem Einsatz getestet werden.
    header {
        # Verhindert MIME-Type-Raten durch Browser.
        X-Content-Type-Options nosniff

        # Clickjacking-Schutz.
        X-Frame-Options SAMEORIGIN

        # Referer-Informationen begrenzen.
        Referrer-Policy strict-origin-when-cross-origin

        # Caching für statische Dateien.
        # max-age ist in Sekunden.
        # Cache-Control "public, max-age=86400"

        # Header entfernen:
        # -Server
    }

    # Eigene Fehlerseiten.
    # Beispiel: C:/eins/404.html
    # handle_errors {
    #     rewrite * /404.html
    #     file_server
    # }

    # HTTPS-Zertifikat automatisch von Caddy verwalten lassen.
    # Diese Zeile ist normalerweise nicht erforderlich.
    # tls admin@deine-domain.tld

    # Eigenes Zertifikat verwenden.
    # Beide Dateien müssen vorhanden sein.
    # tls C:/caddy/certs/deine-domain.crt C:/caddy/certs/deine-domain.key
}


# ============================================================
# ZWEITE STATISCHE WEBSITE
# Verzeichnis C:/zwei wird für diese Domain verwendet.
# ============================================================

zweite-domain.tld {
    root * C:/zwei
    encode gzip zstd
    file_server

    log {
        output file C:/caddy/logs/zweite-domain-access.log
        format json
    }
}


# ============================================================
# REVERSE PROXY
# Domain wird an eine intern laufende Windows-Anwendung
# auf Port 1234 weitergeleitet.
# ============================================================

app.deine-domain.tld {
    # Interne Anwendung auf demselben Windows-Rechner.
    reverse_proxy 127.0.0.1:1234

    # Alternativ:
    # reverse_proxy localhost:1234

    # Anwendung auf einem anderen Rechner im Netzwerk:
    # reverse_proxy 192.168.1.50:1234

    # Mehrere Backends:
    # Caddy verteilt Anfragen auf diese Ziele.
    # reverse_proxy 127.0.0.1:1234 127.0.0.1:1235

    # Nur einen bestimmten URL-Pfad weiterleiten:
    # @api path /api/*
    # reverse_proxy @api 127.0.0.1:1234

    # Protokollierung:
    log {
        output file C:/caddy/logs/app-access.log
        format json
    }

    # Zusätzliche Header an das Backend senden:
    header_up X-Real-IP {remote_host}
    header_up X-Forwarded-Proto {scheme}

    # Host-Header des ursprünglichen Clients weitergeben.
    # In vielen Fällen ist dies bereits korrekt voreingestellt.
    # header_up Host {host}
}


# ============================================================
# REVERSE PROXY MIT URL-PFAD
# Nur /api/... wird intern weitergeleitet.
# Andere URLs können statische Dateien liefern.
# ============================================================

api.deine-domain.tld {
    # Nur Anfragen mit /api/ werden verarbeitet.
    handle_path /api/* {
        # handle_path entfernt /api/ vor der Weiterleitung.
        # Aus /api/users wird beim Backend /users.
        reverse_proxy 127.0.0.1:1234
    }

    # Alles andere kommt aus diesem Verzeichnis.
    handle {
        root * C:/eins
        file_server
    }
}


# ============================================================
# URL-UMLEITUNG
# ============================================================

www.deine-domain.tld {
    # Permanente Weiterleitung auf die Hauptdomain.
    redir https://deine-domain.tld{uri} permanent

    # Alternativen:
    # temporary  = temporäre Weiterleitung
    # permanent  = dauerhafte Weiterleitung
}


# ============================================================
# PHP-WEBSITE UNTER WINDOWS
# Nur verwenden, wenn PHP-CGI läuft.
# ============================================================

php.deine-domain.tld {
    root * C:/php-webseite

    # PHP-CGI muss auf diesem Port laufen.
    php_fastcgi 127.0.0.1:9000

    # Statische Dateien zusätzlich ausliefern.
    file_server
}


# ============================================================
# ZUGRIFFSBESCHRÄNKUNG
# ============================================================

intern.deine-domain.tld {
    root * C:/interne-webseite

    # basic_auth benötigt einen HASH, niemals ein Klartextpasswort.
    # Einen Hash erzeugen:
    # caddy hash-password
    #
    # basic_auth {
    #     admin $2a$14$HIER_DEN_ERZEUGTEN_HASH_EINTRAGEN
    # }

    file_server
}


# ============================================================
# GROSSE UPLOADS ERLAUBEN ODER BEGRENZEN
# ============================================================

upload.deine-domain.tld {
    # Maximale Größe des Request-Bodys.
    # Beispiele:
    # request_body {
    #     max_size 10MB
    # }
    #
    # max_size 1GB
    # max_size 0  bedeutet unbegrenzt, normalerweise vermeiden.

    request_body {
        max_size 100MB
    }

    reverse_proxy 127.0.0.1:1234
}


# ============================================================
# HEALTH-CHECK / STATUS
# ============================================================

status.deine-domain.tld {
    # Antwort ohne Backend.
    respond "Caddy ist aktiv" 200
}
