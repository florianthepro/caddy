```
# ============================================================
# Statische Website 1
# ============================================================

# Domain oder mehrere Domains:
example.com {
    # Verzeichnis, aus dem Dateien ausgeliefert werden.
    # Windows-Pfade am besten mit normalen Schrägstrichen schreiben.
    root * C:/eins

    # Komprimiert Antworten.
    # Alternativen:
    #   encode gzip
    #   encode zstd
    #   encode gzip zstd
    #
    # gzip ist sehr kompatibel.
    # zstd ist moderner und häufig effizienter.
    encode gzip zstd

    # Liefert statische Dateien aus dem root-Verzeichnis.
    file_server
}


# ============================================================
# Statische Website 2
# ============================================================

domain2.com {
    root * C:/zwei
    encode gzip zstd
    file_server
}


# ============================================================
# Reverse Proxy
# ============================================================

# Alle Anfragen an google.com werden intern weitergeleitet.
google.com {
    # Ziel der internen Anwendung.
    reverse_proxy 127.0.0.1:1234
}


# ============================================================
# Beispiel mit www
# ============================================================

# Beide Hostnamen verwenden dieselbe Website.
# Diese Zeile ist nur ein Beispiel und darf nicht zusätzlich
# verwendet werden, wenn der Block oben bereits existiert.
#
# example.com, www.example.com {
#     root * C:/eins
#     encode gzip zstd
#     file_server
# }


# ============================================================
# Beispiel: Reverse Proxy mit mehreren Backend-Servern
# ============================================================

# app.example.com {
#     reverse_proxy 127.0.0.1:1234 127.0.0.1:1235
# }


# ============================================================
# Beispiel: Weiterleitung auf eine andere Domain
# ============================================================

# www.example.com {
#     redir https://example.com{uri} permanent
# }


# ============================================================
# Beispiel: Benutzerdefinierte Fehlerseite
# ============================================================

# error.example.com {
#     root * C:/eins
#     file_server
#     handle_errors {
#         rewrite * /fehler.html
#         file_server
#     }
# }
