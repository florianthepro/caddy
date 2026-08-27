@echo off
rem ===========================================================================
rem   CADDY MANAGER
rem
rem   Eine Datei. Doppelklick genuegt.
rem
rem   Richtet den Caddy-Webserver auf diesem Rechner vollstaendig ein
rem   (Programm, Autostart, Watchdog, Firewall, optional PHP) und oeffnet
rem   waehrend der Laufzeit eine Verwaltungsoberflaeche unter
rem   http://127.0.0.1:8787 - erreichbar nur von diesem Rechner und nur mit
rem   dem Zufallstoken, das beim Start in diesem Fenster steht.
rem
rem   Nach der Einrichtung wird diese Datei nicht mehr gebraucht: der
rem   Webserver startet von allein mit Windows und wird ueberwacht.
rem   Zum Aendern von Domains einfach wieder starten.
rem
rem   Der PowerShell-Teil steht unveraendert am Ende dieser Datei, ab der
rem   Zeile mit dem Startmarker. Er wird beim Start eingelesen und
rem   ausgefuehrt - es entsteht keine Zwischendatei auf der Platte.
rem ===========================================================================

setlocal EnableExtensions DisableDelayedExpansion
title Caddy Manager
set "SELF=%~f0"

rem --- Administratorrechte pruefen -----------------------------------------
rem  Das Kennwort --elevated bekommt nur die Fassung, die wir selbst mit
rem  erhoehten Rechten gestartet haben. Es verhindert eine Endlosschleife,
rem  falls fltmc gesperrt ist oder fehlt: die Pruefung meldet dann auch mit
rem  Adminrechten einen Fehler, und die Datei wuerde sich endlos neu starten.
rem  Ueber die Umgebung ginge das nicht - der erhoehte Prozess erbt sie nicht.
if /i "%~1"=="--elevated" goto :elevated
fltmc >nul 2>&1
if "%errorlevel%"=="0" goto :elevated

where powershell.exe >nul 2>&1
if not "%errorlevel%"=="0" goto :nopowershell

echo.
echo   Caddy Manager braucht Administratorrechte.
echo   Geplante Aufgaben, Firewallregeln und C:\caddy gehen sonst nicht.
echo.
echo   Windows fragt gleich nach.
echo.
powershell -NoProfile -ExecutionPolicy Bypass -Command "try { Start-Process -FilePath $env:SELF -ArgumentList '--elevated' -Verb RunAs } catch { Write-Host '  Abgebrochen. Ohne Administratorrechte laeuft die Einrichtung nicht.' -ForegroundColor Yellow; Start-Sleep -Seconds 4 }"
endlocal
exit /b

:elevated
rem --- Eingebetteten PowerShell-Teil einlesen und ausfuehren ----------------
powershell -NoProfile -ExecutionPolicy Bypass -Command "$ErrorActionPreference='Stop'; $marker=':::PS'+'START:::'; $lines=@(Get-Content -LiteralPath $env:SELF -Encoding UTF8); $i=[array]::IndexOf($lines,$marker); if ($i -lt 0) { Write-Host '  Der eingebettete Programmteil fehlt - ist die Datei vollstaendig?' -ForegroundColor Red; exit 2 }; $code=($lines[($i+1)..($lines.Count-1)] -join [Environment]::NewLine); Invoke-Expression $code"

if not "%errorlevel%"=="0" (
  echo.
  echo   Der Manager wurde mit einem Fehler beendet ^(Code %errorlevel%^).
  echo.
  pause
)
endlocal
exit /b

:nopowershell
echo.
echo   Windows PowerShell wurde nicht gefunden.
echo   Ohne PowerShell laesst sich dieses Werkzeug nicht starten.
echo.
pause
endlocal
exit /b 1

:::PSSTART:::
# ============================================================================
#  Caddy Manager - eingebetteter PowerShell-Teil von caddy-manager.bat
#  Laeuft mit Administratorrechten und oeffnet eine lokale Weboberflaeche
#  auf 127.0.0.1. Dieser Teil wird von der .bat eingelesen und ausgefuehrt.
# ============================================================================

$ErrorActionPreference = 'Stop'

if ($PSVersionTable.PSVersion.Major -lt 5) {
    Write-Host 'Es wird Windows PowerShell 5.0 oder neuer benoetigt.' -ForegroundColor Red
    exit 1
}

# ---------------------------------------------------------------------------
#  Umlaut-Helfer: die Quelldatei bleibt reines ASCII, damit die .bat unter
#  jeder Windows-Codepage unveraendert bleibt. T() setzt die Platzhalter ein.
# ---------------------------------------------------------------------------
# Schluessel-Wert-Paare als Liste, nicht als Hashtable: PowerShell behandelt
# Hashtable-Schluessel ohne Ruecksicht auf Gross- und Kleinschreibung, dadurch
# wuerden sich {ae} und {Ae} gegenseitig ueberschreiben.
$UmlautMap = @(
    @('{ae}',    [string][char]0x00E4),
    @('{oe}',    [string][char]0x00F6),
    @('{ue}',    [string][char]0x00FC),
    @('{Ae}',    [string][char]0x00C4),
    @('{Oe}',    [string][char]0x00D6),
    @('{Ue}',    [string][char]0x00DC),
    @('{ss}',    [string][char]0x00DF),
    @('{eur}',   [string][char]0x20AC),
    @('{deg}',   [string][char]0x00B0),
    @('{arr}',   [string][char]0x2192),
    @('{bull}',  [string][char]0x2022),
    @('{ndash}', [string][char]0x2013)
)

function T {
    param([string]$s)
    if ([string]::IsNullOrEmpty($s)) { return '' }
    foreach ($pair in $UmlautMap) {
        $needle = [string]$pair[0]
        if ($s.Contains($needle)) { $s = $s.Replace($needle, [string]$pair[1]) }
    }
    return $s
}

# ---------------------------------------------------------------------------
#  Pfade
# ---------------------------------------------------------------------------
$Root = $env:CADDY_MANAGER_ROOT
if ([string]::IsNullOrWhiteSpace($Root)) { $Root = 'C:\caddy' }
$Root = $Root.TrimEnd('\')

$Paths = [ordered]@{
    Root      = $Root
    Exe       = "$Root\caddy.exe"
    Config    = "$Root\caddyfile"
    Www       = "$Root\www"
    Logs      = "$Root\logs"
    Data      = "$Root\data"
    Manager   = "$Root\manager"
    State     = "$Root\manager\config.json"
    Backups   = "$Root\manager\backups"
    Audit     = "$Root\manager\manager.log"
    Watchdog  = "$Root\manager\watchdog.ps1"
    Lock      = "$Root\manager\manager.pid"
    Staging   = "$Root\manager\caddyfile.staged"
    Php       = 'C:\php'
    PhpExe    = 'C:\php\php-cgi.exe'
    PhpIni    = 'C:\php\php.ini'
}

$TaskNameMain   = 'CaddyManager'
$LegacyTaskNames = @('caddy watchdog', 'caddy start', 'php fastcgi')

# ---------------------------------------------------------------------------
#  TLS fuer alle ausgehenden Downloads erzwingen
# ---------------------------------------------------------------------------
# Nur TLS 1.2 dazuschalten. TLS 1.3 wird bewusst nicht gesetzt: auf aelteren
# Windows-Ausgaben existiert der Enum-Wert zwar, der Aufbau scheitert dann aber
# bei jeder Verbindung. Alle benoetigten Gegenstellen koennen TLS 1.2.
try {
    [Net.ServicePointManager]::SecurityProtocol =
        [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
} catch { }

# ---------------------------------------------------------------------------
#  Audit-Log
# ---------------------------------------------------------------------------
function Write-Audit {
    param([string]$Action, [string]$Detail = '', [string]$Level = 'info')
    try {
        if (-not (Test-Path -LiteralPath $Paths.Manager)) {
            New-Item -ItemType Directory -Path $Paths.Manager -Force | Out-Null
        }
        $line = '{0} [{1}] {2}{3}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Level.ToUpper(), $Action,
                $(if ([string]::IsNullOrWhiteSpace($Detail)) { '' } else { ' :: ' + $Detail })
        Add-Content -LiteralPath $Paths.Audit -Value $line -Encoding UTF8
        # Log begrenzen: ab 4000 Zeilen auf die letzten 2000 kuerzen
        $fi = Get-Item -LiteralPath $Paths.Audit -ErrorAction SilentlyContinue
        if ($fi -and $fi.Length -gt 900KB) {
            $keep = Get-Content -LiteralPath $Paths.Audit -Tail 2000
            Write-TextFile $Paths.Audit (($keep -join "`r`n") + "`r`n")
        }
    } catch { }
}

# Set-Content -Encoding UTF8 schreibt unter Windows PowerShell 5.1 eine
# Bytefolgemarkierung an den Dateianfang. Caddy verkraftet sie zwar, meldet die
# Datei dann aber als "nicht formatiert". Deshalb ueberall ohne Markierung.
function Write-TextFile {
    param([string]$Path, [string]$Text)
    $enc = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Path, [string]$Text, $enc)
}

# Textdateien enden mit genau einem Zeilenumbruch. Fehlt er, meldet
# "caddy fmt" die Datei als nicht formatiert.
function ConvertTo-FileText {
    param([string]$Text)
    if ($null -eq $Text) { return "`r`n" }
    return ($Text.TrimEnd("`r", "`n") + [Environment]::NewLine)
}

# Liest eine Textdatei, ohne die Kodierung zu erraten. Eine Caddyfile, die mit
# einem aelteren Editor angelegt wurde, steht oft in der ANSI-Codepage - als
# UTF-8 gelesen wuerden daraus Fragezeichen, und beim Zurueckschreiben waeren
# die Umlaute in Pfaden und Kommentaren dauerhaft zerstoert.
$script:AnsiEnc = $null
function Get-AnsiEncoding {
    if ($script:AnsiEnc) { return $script:AnsiEnc }
    $enc = $null
    try {
        $cp = [System.Globalization.CultureInfo]::CurrentCulture.TextInfo.ANSICodePage
        if ($cp -gt 0 -and $cp -ne 65001) { $enc = [Text.Encoding]::GetEncoding($cp) }
    } catch { }
    if (-not $enc) { try { $enc = [Text.Encoding]::GetEncoding(1252) } catch { } }
    if (-not $enc) { $enc = [Text.Encoding]::Default }
    $script:AnsiEnc = $enc
    return $enc
}

function Read-TextFileSmart {
    param([string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) { return '' }
    try {
        $b = [System.IO.File]::ReadAllBytes($Path)
        if ($b.Length -eq 0) { return '' }
        if ($b.Length -ge 3 -and $b[0] -eq 0xEF -and $b[1] -eq 0xBB -and $b[2] -eq 0xBF) {
            return [Text.Encoding]::UTF8.GetString($b, 3, $b.Length - 3)
        }
        if ($b.Length -ge 2 -and $b[0] -eq 0xFF -and $b[1] -eq 0xFE) { return [Text.Encoding]::Unicode.GetString($b) }
        if ($b.Length -ge 2 -and $b[0] -eq 0xFE -and $b[1] -eq 0xFF) { return [Text.Encoding]::BigEndianUnicode.GetString($b) }
        try {
            # Strenges UTF-8: wirft bei ungueltigen Folgen
            $enc = New-Object System.Text.UTF8Encoding($false, $true)
            return $enc.GetString($b)
        } catch {
            # Kein gueltiges UTF-8 - also die ANSI-Codepage des Systems.
            # Encoding::Default bedeutet je nach Laufzeit etwas anderes,
            # deshalb die Codepage ausdruecklich holen.
            return (Get-AnsiEncoding).GetString($b)
        }
    } catch { return '' }
}

# Nimmt einer Datei das Schreibschutz-Merkmal, sonst schlaegt jedes Ueberschreiben fehl.
function Clear-ReadOnly {
    param([string]$Path)
    try {
        if (-not (Test-Path -LiteralPath $Path)) { return }
        $fi = Get-Item -LiteralPath $Path -Force
        if ($fi.Attributes -band [System.IO.FileAttributes]::ReadOnly) {
            $fi.Attributes = $fi.Attributes -bxor [System.IO.FileAttributes]::ReadOnly
        }
    } catch { }
}

function Write-Host2 {
    param([string]$Msg, [string]$Color = 'Gray')
    try { Write-Host (T $Msg) -ForegroundColor $Color } catch { Write-Host $Msg }
}

# ---------------------------------------------------------------------------
#  Krypto-Helfer
# ---------------------------------------------------------------------------
function New-Secret {
    param([int]$Bytes = 32)
    $buf = New-Object byte[] $Bytes
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($buf) } finally { $rng.Dispose() }
    return (($buf | ForEach-Object { $_.ToString('x2') }) -join '')
}

function Test-SecretEqual {
    param([string]$A, [string]$B)
    if ([string]::IsNullOrEmpty($A) -or [string]::IsNullOrEmpty($B)) { return $false }
    $ab = [Text.Encoding]::UTF8.GetBytes($A)
    $bb = [Text.Encoding]::UTF8.GetBytes($B)
    $diff = $ab.Length -bxor $bb.Length
    $n = [Math]::Min($ab.Length, $bb.Length)
    for ($i = 0; $i -lt $n; $i++) { $diff = $diff -bor ($ab[$i] -bxor $bb[$i]) }
    return ($diff -eq 0)
}

function Get-FileHashSafe {
    param([string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) { return '' }
    try { return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLower() } catch { return '' }
}

function Get-TextHash {
    param([string]$Text)
    if ($null -eq $Text) { $Text = '' }
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        $h = $sha.ComputeHash([Text.Encoding]::UTF8.GetBytes(($Text -replace "`r`n", "`n").Trim()))
        return (($h | ForEach-Object { $_.ToString('x2') }) -join '')
    } finally { $sha.Dispose() }
}

# ---------------------------------------------------------------------------
#  Prozessaufruf mit sauberem stdout/stderr (kein Deadlock, mit Timeout)
# ---------------------------------------------------------------------------
function ConvertTo-CmdArg {
    param([string]$Value)
    if ([string]::IsNullOrEmpty($Value)) { return '""' }
    if ($Value -match '^[A-Za-z0-9_\-\.\\/:=@,]+$') { return $Value }
    $escaped = $Value -replace '(\\*)"', '$1$1\"'
    $escaped = $escaped -replace '(\\+)$', '$1$1'
    return '"' + $escaped + '"'
}

function Invoke-Exe {
    param(
        [Parameter(Mandatory = $true)][string]$FilePath,
        [string[]]$Arguments = @(),
        [int]$TimeoutSec = 120,
        [hashtable]$Environment = $null,
        [string]$WorkDir = $null,
        [string]$StdIn = $null
    )
    $result = [ordered]@{ ok = $false; code = -1; stdout = ''; stderr = ''; timedOut = $false }
    if (-not (Test-Path -LiteralPath $FilePath)) {
        $result.stderr = "Programm nicht gefunden: $FilePath"
        return $result
    }
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = $FilePath
    $psi.Arguments = (($Arguments | ForEach-Object { ConvertTo-CmdArg $_ }) -join ' ')
    $psi.UseShellExecute = $false
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $psi.CreateNoWindow = $true
    if ($null -ne $StdIn) { $psi.RedirectStandardInput = $true }
    if ($WorkDir) { $psi.WorkingDirectory = $WorkDir }
    if ($Environment) {
        foreach ($k in $Environment.Keys) { $psi.EnvironmentVariables[$k] = [string]$Environment[$k] }
    }
    $proc = New-Object System.Diagnostics.Process
    $proc.StartInfo = $psi
    try {
        [void]$proc.Start()
        if ($null -ne $StdIn) {
            try { $proc.StandardInput.WriteLine($StdIn) } catch { }
            try { $proc.StandardInput.Close() } catch { }
        }
        $tOut = $proc.StandardOutput.ReadToEndAsync()
        $tErr = $proc.StandardError.ReadToEndAsync()
        if ($proc.WaitForExit($TimeoutSec * 1000)) {
            $result.code = $proc.ExitCode
            $result.ok = ($proc.ExitCode -eq 0)
        } else {
            $result.timedOut = $true
            try { $proc.Kill() } catch { }
        }
        # Nach einem Abbruch koennen Enkelprozesse die Ausgabekanaele offen
        # halten. Deshalb auch hier mit Zeitgrenze warten statt zu blockieren.
        try { if ($tOut.Wait(3000)) { $result.stdout = $tOut.Result } } catch { }
        try { if ($tErr.Wait(3000)) { $result.stderr = $tErr.Result } } catch { }
    } catch {
        $result.stderr = $_.Exception.Message
    } finally {
        try { $proc.Dispose() } catch { }
    }
    return $result
}

function Invoke-Caddy {
    param([string[]]$Arguments, [int]$TimeoutSec = 90)
    return (Invoke-Exe -FilePath $Paths.Exe -Arguments $Arguments -TimeoutSec $TimeoutSec)
}

function Get-ExeOutput {
    param($Result)
    $parts = @()
    if ($Result.stdout -and $Result.stdout.Trim()) { $parts += $Result.stdout.Trim() }
    if ($Result.stderr -and $Result.stderr.Trim()) { $parts += $Result.stderr.Trim() }
    return (($parts -join "`n").Trim())
}

# ---------------------------------------------------------------------------
#  Validierung - jede Nutzereingabe geht hier durch, bevor sie in die
#  Caddyfile, in einen Prozessaufruf oder auf die Platte gelangt.
# ---------------------------------------------------------------------------
$ForbiddenRoots = @(
    'C:\', 'C:\WINDOWS', 'C:\WINDOWS\SYSTEM32', 'C:\PROGRAM FILES', 'C:\PROGRAM FILES (X86)',
    'C:\USERS', 'C:\PROGRAMDATA'
)

function Test-NoControlChars {
    param([string]$Value)
    if ($null -eq $Value) { return $true }
    foreach ($ch in $Value.ToCharArray()) {
        if ([int]$ch -lt 32 -or [int]$ch -eq 127) { return $false }
    }
    return $true
}

function ConvertTo-Punycode {
    param([string]$Host2)
    try {
        $idn = New-Object System.Globalization.IdnMapping
        return $idn.GetAscii($Host2)
    } catch { return $Host2 }
}

# Prueft eine Caddy-Site-Adresse: optionales Schema, Hostname (oder Wildcard),
# optionaler Port. Gibt die normalisierte Adresse zurueck oder $null.
function Resolve-SiteAddress {
    param([string]$Value)
    if ([string]::IsNullOrWhiteSpace($Value)) { return $null }
    $v = $Value.Trim()
    if (-not (Test-NoControlChars $v)) { return $null }
    if ($v.Length -gt 253) { return $null }

    $scheme = ''
    if ($v -match '^(?i)(https?)://(.+)$') { $scheme = $Matches[1].ToLower() + '://'; $v = $Matches[2] }
    $v = $v.TrimEnd('/')

    $port = ''
    if ($v -match '^(.*):(\d{1,5})$') {
        $port = $Matches[2]
        $v = $Matches[1]
        if ([int]$port -lt 1 -or [int]$port -gt 65535) { return $null }
        $port = ':' + $port
    }

    # Reiner Port-Block wie ":8080"
    if ($v -eq '' -and $port -ne '') { return $port }

    $v = $v.ToLower()
    $wild = ''
    if ($v.StartsWith('*.')) { $wild = '*.'; $v = $v.Substring(2) }

    $v = ConvertTo-Punycode $v
    if ($v -eq 'localhost') { return ($scheme + $wild + $v + $port) }
    if ($v -match '^\d{1,3}(\.\d{1,3}){3}$') {
        foreach ($o in $v.Split('.')) { if ([int]$o -gt 255) { return $null } }
        return ($scheme + $v + $port)
    }
    if ($v -match '^([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$') {
        return ($scheme + $wild + $v + $port)
    }
    return $null
}

function Resolve-Upstream {
    param([string]$Value)
    if ([string]::IsNullOrWhiteSpace($Value)) { return $null }
    $v = $Value.Trim()
    if (-not (Test-NoControlChars $v)) { return $null }
    if ($v.Length -gt 300) { return $null }
    $scheme = ''
    if ($v -match '^(?i)(https?|h2c)://(.+)$') { $scheme = $Matches[1].ToLower() + '://'; $v = $Matches[2] }
    $v = $v.TrimEnd('/')
    # IPv6 in Klammern
    if ($v -match '^\[[0-9a-fA-F:]+\](:\d{1,5})?$') { return ($scheme + $v) }
    $port = ''
    if ($v -match '^(.*):(\d{1,5})$') {
        $port = $Matches[2]
        $v = $Matches[1]
        if ([int]$port -lt 1 -or [int]$port -gt 65535) { return $null }
        $port = ':' + $port
    }
    $v = $v.ToLower()
    if ($v -eq 'localhost' -or $v -match '^\d{1,3}(\.\d{1,3}){3}$' -or
        $v -match '^([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?$') {
        if ($scheme -eq '' -and $port -eq '') { return $null }
        return ($scheme + $v + $port)
    }
    return $null
}

function Resolve-LocalPath {
    param([string]$Value, [switch]$AllowMissing)
    if ([string]::IsNullOrWhiteSpace($Value)) { return $null }
    $v = $Value.Trim().Replace('/', '\')
    if (-not (Test-NoControlChars $v)) { return $null }
    if ($v.Length -gt 240) { return $null }
    if ($v -match '[*?"<>|]') { return $null }
    if ($v -match '(^|\\)\.\.($|\\)') { return $null }
    if ($v -notmatch '^[A-Za-z]:\\') { return $null }
    $v = $v.TrimEnd('\')
    if ($v -match '^[A-Za-z]:$') { return $null }
    $upper = $v.ToUpper()
    foreach ($bad in $ForbiddenRoots) {
        if ($upper -eq $bad.TrimEnd('\')) { return $null }
    }
    if ($upper -like 'C:\WINDOWS\*') { return $null }
    return $v
}

function ConvertTo-CaddyPath {
    param([string]$Value)
    if ([string]::IsNullOrWhiteSpace($Value)) { return '' }
    return $Value.Replace('\', '/')
}

# Pfade mit Leerzeichen brauchen in der Caddyfile Anfuehrungszeichen, sonst
# zerfaellt der Pfad in mehrere Argumente und Caddy lehnt die Zeile ab.
function ConvertTo-PathToken {
    param([string]$Value)
    $p = ConvertTo-CaddyPath $Value
    if ($p -match '[\s"]') { return '"' + ($p -replace '"', '\"') + '"' }
    return $p
}

function Test-EmailAddress {
    param([string]$Value)
    if ([string]::IsNullOrWhiteSpace($Value)) { return $true }
    if (-not (Test-NoControlChars $Value)) { return $false }
    return ($Value -match '^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,63}$' -and $Value.Length -le 254)
}

function Test-BalancedBraces {
    param([string]$Text)
    if ([string]::IsNullOrEmpty($Text)) { return $true }
    $depth = 0
    $inQuote = $false
    $prev = ''
    foreach ($ch in $Text.ToCharArray()) {
        if ($ch -eq '"' -and $prev -ne '\') { $inQuote = -not $inQuote }
        elseif (-not $inQuote) {
            if ($ch -eq '{') { $depth++ }
            elseif ($ch -eq '}') { $depth--; if ($depth -lt 0) { return $false } }
        }
        $prev = [string]$ch
    }
    return ($depth -eq 0 -and -not $inQuote)
}

function Test-SizeValue {
    param([string]$Value)
    if ([string]::IsNullOrWhiteSpace($Value)) { return $true }
    return ($Value -match '^\d{1,6}\s?(B|KB|MB|GB|KiB|MiB|GiB)$')
}

function Test-BcryptHash {
    param([string]$Value)
    if ([string]::IsNullOrWhiteSpace($Value)) { return $false }
    return ($Value -match '^\$2[aby]\$\d{2}\$[A-Za-z0-9./]{53}$')
}

function Test-UserName {
    param([string]$Value)
    if ([string]::IsNullOrWhiteSpace($Value)) { return $false }
    return ($Value -match '^[A-Za-z0-9._\-]{1,64}$')
}

function ConvertTo-HtmlText {
    param([string]$Value)
    if ($null -eq $Value) { return '' }
    return $Value.Replace('&', '&amp;').Replace('<', '&lt;').Replace('>', '&gt;').Replace('"', '&quot;').Replace("'", '&#39;')
}

function Get-SafeString {
    param([string]$Value, [int]$MaxLength = 200)
    if ($null -eq $Value) { return '' }
    $v = $Value.Trim()
    if ($v.Length -gt $MaxLength) { $v = $v.Substring(0, $MaxLength) }
    $sb = New-Object System.Text.StringBuilder
    foreach ($ch in $v.ToCharArray()) {
        if ([int]$ch -ge 32 -and [int]$ch -ne 127) { [void]$sb.Append($ch) }
    }
    return $sb.ToString()
}

# ---------------------------------------------------------------------------
#  Umwandlung PSCustomObject -> Hashtable (PS 5.1 kennt kein -AsHashtable)
# ---------------------------------------------------------------------------
# Nimmt den Inhalt eines Blocks und ruecke ihn relativ ein, statt alles auf
# eine Ebene zu ziehen. Sonst passt die erzeugte Datei nicht mehr zu "caddy fmt".
function Get-ReindentedLines {
    param([string]$Body, [string]$Prefix = "`t")
    $out = New-Object System.Collections.ArrayList
    if ([string]::IsNullOrWhiteSpace($Body)) { return ,@() }
    $raw = ($Body -replace "`r`n", "`n").Split("`n")
    $min = -1
    foreach ($l in $raw) {
        if (-not $l.Trim()) { continue }
        $lead = 0
        foreach ($ch in $l.ToCharArray()) {
            if ($ch -eq ' ' -or $ch -eq "`t") { $lead++ } else { break }
        }
        if ($min -lt 0 -or $lead -lt $min) { $min = $lead }
    }
    if ($min -lt 0) { $min = 0 }
    foreach ($l in $raw) {
        if (-not $l.Trim()) { continue }
        $t = $(if ($l.Length -ge $min) { $l.Substring($min) } else { $l.TrimStart() })
        [void]$out.Add($Prefix + $t.TrimEnd())
    }
    return ,@($out.ToArray())
}

function ConvertTo-Hash {
    param($InputObject)
    if ($null -eq $InputObject) { return $null }
    if ($InputObject -is [System.Collections.IDictionary]) {
        $h = @{}
        foreach ($k in @($InputObject.Keys)) { $h[[string]$k] = ConvertTo-Hash $InputObject[$k] }
        return $h
    }
    if ($InputObject -is [System.Management.Automation.PSCustomObject]) {
        $h = @{}
        foreach ($p in $InputObject.PSObject.Properties) { $h[$p.Name] = ConvertTo-Hash $p.Value }
        return $h
    }
    if ($InputObject -is [string]) { return $InputObject }
    if ($InputObject -is [System.Collections.IEnumerable]) {
        $list = New-Object System.Collections.ArrayList
        foreach ($item in $InputObject) { [void]$list.Add((ConvertTo-Hash $item)) }
        return , $list.ToArray()
    }
    return $InputObject
}

function Get-Field {
    param($Obj, [string]$Name, $Default = $null)
    if ($null -eq $Obj) { return $Default }
    if ($Obj -is [System.Collections.IDictionary]) {
        if ($Obj.Contains($Name)) {
            $v = $Obj[$Name]
            if ($null -eq $v) { return $Default }
            return $v
        }
        return $Default
    }
    $p = $Obj.PSObject.Properties[$Name]
    if ($null -eq $p -or $null -eq $p.Value) { return $Default }
    return $p.Value
}

function Get-BoolField {
    param($Obj, [string]$Name, [bool]$Default = $false)
    $v = Get-Field $Obj $Name $null
    if ($null -eq $v) { return $Default }
    if ($v -is [bool]) { return $v }
    $s = [string]$v
    return ($s -eq 'true' -or $s -eq 'True' -or $s -eq '1')
}

function Get-StringField {
    param($Obj, [string]$Name, [string]$Default = '')
    $v = Get-Field $Obj $Name $null
    if ($null -eq $v) { return $Default }
    return ([string]$v)
}

function Get-ArrayField {
    param($Obj, [string]$Name)
    $v = Get-Field $Obj $Name $null
    if ($null -eq $v) { return ,@() }
    if ($v -is [string]) { return ,@($v) }
    return ,@($v)
}

# ===========================================================================
#  KONFIGURATIONSMODELL
#  Die Weboberflaeche bearbeitet ausschliesslich dieses Modell. Die Caddyfile
#  wird daraus erzeugt - niemand muss die Caddyfile von Hand anfassen.
# ===========================================================================

$script:CaddyVersion = $null

function Get-CaddyVersionInfo {
    param([switch]$Refresh)
    if ($script:CaddyVersion -and -not $Refresh) { return $script:CaddyVersion }
    $info = [ordered]@{ raw = ''; version = ''; major = 0; minor = 0; patch = 0; installed = $false }
    if (Test-Path -LiteralPath $Paths.Exe) {
        $r = Invoke-Exe -FilePath $Paths.Exe -Arguments @('version') -TimeoutSec 20
        $out = (Get-ExeOutput $r)
        if ($out) {
            $info.raw = ($out -split "`n")[0].Trim()
            if ($info.raw -match 'v?(\d+)\.(\d+)\.(\d+)') {
                $info.version = $Matches[0].TrimStart('v')
                $info.major = [int]$Matches[1]; $info.minor = [int]$Matches[2]; $info.patch = [int]$Matches[3]
                $info.installed = $true
            }
        }
    }
    $script:CaddyVersion = $info
    return $info
}

function Test-CaddyAtLeast {
    param([int]$Major, [int]$Minor)
    $v = Get-CaddyVersionInfo
    if (-not $v.installed) { return $true }   # unbekannt: neueste Syntax annehmen
    if ($v.major -gt $Major) { return $true }
    if ($v.major -lt $Major) { return $false }
    return ($v.minor -ge $Minor)
}

function Get-BasicAuthDirective {
    if (Test-CaddyAtLeast 2 8) { return 'basic_auth' }
    return 'basicauth'
}

function Get-HostLabel {
    param([string]$Address)
    $h = $Address -replace '^https?://', ''
    $h = ($h -split ':')[0]
    $h = $h.Replace('*.', 'wildcard.')
    $h = $h -replace '[^A-Za-z0-9\.\-]', '_'
    if ([string]::IsNullOrWhiteSpace($h)) { $h = 'site' }
    return $h
}

function New-DefaultConfig {
    return [ordered]@{
        version = 1
        mode    = 'managed'
        global  = [ordered]@{
            email       = ''
            adminListen = 'localhost:2019'
            logLevel    = 'INFO'
            rollSize    = '10MiB'
            rollKeep    = 7
            storage     = ''
            trustedProxies = $false
            extra       = ''
            snippets    = ''
        }
        php     = [ordered]@{
            enabled              = $false
            poolSize             = 4
            basePort             = 9000
            disableRiskyFunctions = $false
        }
        manager = [ordered]@{
            port        = 8787
            idleMinutes = 60
            runAs       = 'SYSTEM'
            openBrowser = $true
        }
        sites   = @()
    }
}

function New-SiteObject {
    return [ordered]@{
        id              = [guid]::NewGuid().ToString('n')
        enabled         = $true
        label           = ''
        domains         = @()
        type            = 'static'
        root            = ''
        upstream        = ''
        redirectTo      = ''
        redirectCode    = 'permanent'
        respondBody     = ''
        respondStatus   = 200
        encode          = $true
        browse          = $false
        indexFiles      = ''
        securityHeaders = $true
        hsts            = $false
        blockSensitive  = $true
        accessLog       = $true
        wwwRedirect     = $false
        basicAuthUser   = ''
        basicAuthHash   = ''
        maxBody         = ''
        tlsMode         = 'auto'
        tlsCert         = ''
        tlsKey          = ''
        handlerExtra    = ''
        extra           = ''
    }
}

function Read-Config {
    $cfg = New-DefaultConfig
    if (Test-Path -LiteralPath $Paths.State) {
        try {
            $raw = Get-Content -LiteralPath $Paths.State -Raw -Encoding UTF8
            if ($raw -and $raw.Trim()) {
                $loaded = ConvertTo-Hash (ConvertFrom-Json $raw)
                $cfg = Merge-Config $loaded
            }
        } catch {
            Write-Audit 'config.read.error' $_.Exception.Message 'error'
        }
    }
    return $cfg
}

function Merge-Config {
    param($Loaded)
    $out = New-DefaultConfig
    if ($null -eq $Loaded) { return $out }
    $out.mode = $(if ((Get-StringField $Loaded 'mode') -eq 'manual') { 'manual' } else { 'managed' })

    $g = Get-Field $Loaded 'global' $null
    if ($g) {
        $out.global.email       = Get-SafeString (Get-StringField $g 'email' '') 254
        $adm                    = Get-SafeString (Get-StringField $g 'adminListen' 'localhost:2019') 120
        $out.global.adminListen = $(if ($adm) { $adm } else { 'localhost:2019' })
        $lvl                    = (Get-StringField $g 'logLevel' 'INFO').ToUpper()
        $out.global.logLevel    = $(if (@('DEBUG', 'INFO', 'WARN', 'ERROR') -contains $lvl) { $lvl } else { 'INFO' })
        $rs                     = Get-StringField $g 'rollSize' '10MiB'
        $out.global.rollSize    = $(if (Test-SizeValue $rs) { $rs } else { '10MiB' })
        $rk = 0
        [void][int]::TryParse((Get-StringField $g 'rollKeep' '7'), [ref]$rk)
        $out.global.rollKeep    = $(if ($rk -ge 1 -and $rk -le 100) { $rk } else { 7 })
        $ge = Get-StringField $g 'extra' ''
        if ($ge.Length -gt 8000) { $ge = $ge.Substring(0, 8000) }
        $out.global.extra       = $(if (Test-BalancedBraces $ge) { $ge } else { '' })
        $out.global.trustedProxies = Get-BoolField $g 'trustedProxies' $false
        $st2 = Resolve-LocalPath (Get-StringField $g 'storage' '')
        $out.global.storage     = $(if ($st2) { $st2 } else { '' })
        $sn = Get-StringField $g 'snippets' ''
        if ($sn.Length -gt 16000) { $sn = $sn.Substring(0, 16000) }
        $out.global.snippets    = $(if (Test-BalancedBraces $sn) { $sn } else { '' })
    }

    $p = Get-Field $Loaded 'php' $null
    if ($p) {
        $out.php.enabled  = Get-BoolField $p 'enabled' $false
        $ps = 0; [void][int]::TryParse((Get-StringField $p 'poolSize' '4'), [ref]$ps)
        $out.php.poolSize = $(if ($ps -ge 1 -and $ps -le 16) { $ps } else { 4 })
        $bp = 0; [void][int]::TryParse((Get-StringField $p 'basePort' '9000'), [ref]$bp)
        $out.php.basePort = $(if ($bp -ge 1024 -and $bp -le 65000) { $bp } else { 9000 })
        $out.php.disableRiskyFunctions = Get-BoolField $p 'disableRiskyFunctions' $false
    }

    $m = Get-Field $Loaded 'manager' $null
    if ($m) {
        $mp = 0; [void][int]::TryParse((Get-StringField $m 'port' '8787'), [ref]$mp)
        $out.manager.port        = $(if ($mp -ge 1024 -and $mp -le 65000) { $mp } else { 8787 })
        $im = 0; [void][int]::TryParse((Get-StringField $m 'idleMinutes' '60'), [ref]$im)
        $out.manager.idleMinutes = $(if ($im -ge 0 -and $im -le 1440) { $im } else { 60 })
        $ra = (Get-StringField $m 'runAs' 'SYSTEM').ToUpper()
        $out.manager.runAs       = $(if ($ra -eq 'LOCAL SERVICE') { 'LOCAL SERVICE' } else { 'SYSTEM' })
        $out.manager.openBrowser = Get-BoolField $m 'openBrowser' $true
    }

    $sites = New-Object System.Collections.ArrayList
    foreach ($s in (Get-ArrayField $Loaded 'sites')) {
        $clean = ConvertTo-CleanSite $s
        if ($clean) { [void]$sites.Add($clean) }
        if ($sites.Count -ge 200) { break }
    }
    $out.sites = $sites.ToArray()
    return $out
}

# Normalisiert und validiert einen Site-Eintrag. Ungueltige Werte werden
# verworfen statt uebernommen - so kann nichts Unerwartetes in die Caddyfile.
function ConvertTo-CleanSite {
    param($Raw)
    if ($null -eq $Raw) { return $null }
    $s = New-SiteObject

    $id = Get-StringField $Raw 'id' ''
    if ($id -match '^[a-f0-9]{32}$') { $s.id = $id }

    $s.enabled = Get-BoolField $Raw 'enabled' $true
    $s.label   = Get-SafeString (Get-StringField $Raw 'label' '') 80

    $domains = New-Object System.Collections.ArrayList
    foreach ($d in (Get-ArrayField $Raw 'domains')) {
        $norm = Resolve-SiteAddress ([string]$d)
        if ($norm -and -not $domains.Contains($norm)) { [void]$domains.Add($norm) }
        if ($domains.Count -ge 32) { break }
    }
    if ($domains.Count -eq 0) { return $null }
    $s.domains = $domains.ToArray()

    $type = (Get-StringField $Raw 'type' 'static').ToLower()
    if (@('static', 'php', 'proxy', 'redirect', 'respond') -notcontains $type) { $type = 'static' }
    $s.type = $type

    $root = Resolve-LocalPath (Get-StringField $Raw 'root' '')
    if ($root) { $s.root = $root }
    if (($type -eq 'static' -or $type -eq 'php') -and -not $s.root) {
        $s.root = $Paths.Www + '\' + (Get-HostLabel $s.domains[0])
    }

    if ($type -eq 'proxy') {
        $ups = New-Object System.Collections.ArrayList
        foreach ($u in ((Get-StringField $Raw 'upstream' '') -split '[,\s]+')) {
            if ([string]::IsNullOrWhiteSpace($u)) { continue }
            $n = Resolve-Upstream $u
            if ($n -and -not $ups.Contains($n)) { [void]$ups.Add($n) }
            if ($ups.Count -ge 8) { break }
        }
        if ($ups.Count -eq 0) { return $null }
        $s.upstream = ($ups.ToArray() -join ' ')
    }

    if ($type -eq 'redirect') {
        $target = Get-SafeString (Get-StringField $Raw 'redirectTo' '') 300
        $target = $target -replace '\{uri\}\s*$', ''
        $target = $target.TrimEnd('/')
        if ($target -notmatch '^https?://[A-Za-z0-9\-\._~:/?#\[\]@!$&()*+,;=%]+$') { return $null }
        $s.redirectTo   = $target
        $code           = (Get-StringField $Raw 'redirectCode' 'permanent').ToLower()
        $s.redirectCode = $(if (@('permanent', 'temporary', 'html') -contains $code) { $code } else { 'permanent' })
    }

    if ($type -eq 'respond') {
        $s.respondBody = Get-SafeString (Get-StringField $Raw 'respondBody' 'OK') 500
        $st = 0; [void][int]::TryParse((Get-StringField $Raw 'respondStatus' '200'), [ref]$st)
        $s.respondStatus = $(if ($st -ge 100 -and $st -le 599) { $st } else { 200 })
    }

    $s.encode          = Get-BoolField $Raw 'encode' $true
    $s.browse          = Get-BoolField $Raw 'browse' $false
    $s.securityHeaders = Get-BoolField $Raw 'securityHeaders' $true
    $s.hsts            = Get-BoolField $Raw 'hsts' $false
    $s.blockSensitive  = Get-BoolField $Raw 'blockSensitive' $true
    $s.accessLog       = Get-BoolField $Raw 'accessLog' $true
    $s.wwwRedirect     = Get-BoolField $Raw 'wwwRedirect' $false

    $idx = New-Object System.Collections.ArrayList
    foreach ($f in ((Get-StringField $Raw 'indexFiles' '') -split '[,\s]+')) {
        if ($f -match '^[A-Za-z0-9_\-\.]{1,64}$') { [void]$idx.Add($f) }
        if ($idx.Count -ge 8) { break }
    }
    $s.indexFiles = ($idx.ToArray() -join ' ')

    $bu = Get-StringField $Raw 'basicAuthUser' ''
    $bh = Get-StringField $Raw 'basicAuthHash' ''
    if ((Test-UserName $bu) -and (Test-BcryptHash $bh)) { $s.basicAuthUser = $bu; $s.basicAuthHash = $bh }

    $mb = Get-StringField $Raw 'maxBody' ''
    if ($mb -and (Test-SizeValue $mb)) { $s.maxBody = $mb }

    $tm = (Get-StringField $Raw 'tlsMode' 'auto').ToLower()
    if (@('auto', 'internal', 'custom') -notcontains $tm) { $tm = 'auto' }
    $s.tlsMode = $tm
    if ($tm -eq 'custom') {
        $c = Resolve-LocalPath (Get-StringField $Raw 'tlsCert' '')
        $k = Resolve-LocalPath (Get-StringField $Raw 'tlsKey' '')
        if ($c -and $k) { $s.tlsCert = $c; $s.tlsKey = $k } else { $s.tlsMode = 'auto' }
    }

    $he = Get-StringField $Raw 'handlerExtra' ''
    if ($he.Length -gt 4000) { $he = $he.Substring(0, 4000) }
    $s.handlerExtra = $(if (Test-BalancedBraces $he) { $he } else { '' })

    $extra = Get-StringField $Raw 'extra' ''
    if ($extra.Length -gt 8000) { $extra = $extra.Substring(0, 8000) }
    $s.extra = $(if (Test-BalancedBraces $extra) { $extra } else { '' })

    return $s
}

function Save-Config {
    param($Config)
    if (-not (Test-Path -LiteralPath $Paths.Manager)) {
        New-Item -ItemType Directory -Path $Paths.Manager -Force | Out-Null
    }
    if (Test-Path -LiteralPath $Paths.State) { Backup-File -Path $Paths.State -Prefix 'config' | Out-Null }
    $json = $Config | ConvertTo-Json -Depth 8
    Write-TextFile $Paths.State $json
}

function Backup-File {
    param([string]$Path, [string]$Prefix)
    try {
        if (-not (Test-Path -LiteralPath $Path)) { return $null }
        if (-not (Test-Path -LiteralPath $Paths.Backups)) {
            New-Item -ItemType Directory -Path $Paths.Backups -Force | Out-Null
        }
        $name = '{0}-{1}.bak' -f $Prefix, (Get-Date -Format 'yyyyMMdd-HHmmss-fff')
        $dest = Join-Path $Paths.Backups $name
        Copy-Item -LiteralPath $Path -Destination $dest -Force
        $old = @(Get-ChildItem -LiteralPath $Paths.Backups -Filter "$Prefix-*.bak" -ErrorAction SilentlyContinue |
                 Sort-Object LastWriteTime -Descending | Select-Object -Skip 30)
        foreach ($f in $old) { Remove-Item -LiteralPath $f.FullName -Force -ErrorAction SilentlyContinue }
        return $dest
    } catch { return $null }
}

# ===========================================================================
#  CADDYFILE-GENERATOR
# ===========================================================================

function Add-Line {
    param([System.Text.StringBuilder]$Sb, [int]$Indent, [string]$Text)
    if ([string]::IsNullOrEmpty($Text)) { [void]$Sb.AppendLine(''); return }
    [void]$Sb.AppendLine(("`t" * $Indent) + $Text)
}

function Add-RawBlock {
    param([System.Text.StringBuilder]$Sb, [int]$Indent, [string]$Text)
    if ([string]::IsNullOrWhiteSpace($Text)) { return }
    foreach ($line in ($Text -replace "`r`n", "`n").Split("`n")) {
        $t = $line.TrimEnd()
        if ([string]::IsNullOrWhiteSpace($t)) { continue }
        [void]$Sb.AppendLine(("`t" * $Indent) + $t)
    }
}

function Add-LogDirective {
    param([System.Text.StringBuilder]$Sb, [int]$Indent, $Config, [string]$FileName)
    Add-Line $Sb $Indent 'log {'
    Add-Line $Sb ($Indent + 1) ('output file ' + (ConvertTo-PathToken ($Paths.Logs + '\' + $FileName)) + ' {')
    Add-Line $Sb ($Indent + 2) ('roll_size ' + $Config.global.rollSize)
    Add-Line $Sb ($Indent + 2) ('roll_keep ' + $Config.global.rollKeep)
    Add-Line $Sb ($Indent + 1) '}'
    Add-Line $Sb ($Indent + 1) 'format json'
    Add-Line $Sb $Indent '}'
}

function Get-PhpUpstreams {
    param($Config)
    $list = @()
    for ($i = 0; $i -lt $Config.php.poolSize; $i++) {
        $list += ('127.0.0.1:' + ($Config.php.basePort + $i))
    }
    return ($list -join ' ')
}

function Build-SiteBlock {
    param([System.Text.StringBuilder]$Sb, $Site, $Config)

    $label = $(if ($Site.label) { $Site.label } else { $Site.domains[0] })
    Add-Line $Sb 0 ('# ' + ('-' * 68))
    Add-Line $Sb 0 ('#  ' + $label + '   [' + $Site.type + ']')
    Add-Line $Sb 0 ('# ' + ('-' * 68))
    Add-Line $Sb 0 (($Site.domains -join ', ') + ' {')

    if ($Site.tlsMode -eq 'internal') {
        Add-Line $Sb 1 'tls internal'
    } elseif ($Site.tlsMode -eq 'custom') {
        Add-Line $Sb 1 ('tls ' + (ConvertTo-PathToken $Site.tlsCert) + ' ' + (ConvertTo-PathToken $Site.tlsKey))
    }

    if ($Site.basicAuthUser -and $Site.basicAuthHash) {
        Add-Line $Sb 1 ((Get-BasicAuthDirective) + ' {')
        Add-Line $Sb 2 ($Site.basicAuthUser + ' ' + $Site.basicAuthHash)
        Add-Line $Sb 1 '}'
    }

    if ($Site.type -eq 'redirect') {
        Add-Line $Sb 1 ('redir ' + $Site.redirectTo + '{uri} ' + $Site.redirectCode)
        if ($Site.accessLog) { Add-LogDirective $Sb 1 $Config ((Get-HostLabel $Site.domains[0]) + '-access.log') }
        Add-RawBlock $Sb 1 $Site.extra
        Add-Line $Sb 0 '}'
        Add-Line $Sb 0 ''
        Add-WwwRedirect $Sb $Site
        return
    }

    if ($Site.type -eq 'respond') {
        $body = $Site.respondBody.Replace('\', '\\').Replace('"', '\"')
        Add-Line $Sb 1 ('respond "' + $body + '" ' + $Site.respondStatus)
        if ($Site.accessLog) { Add-LogDirective $Sb 1 $Config ((Get-HostLabel $Site.domains[0]) + '-access.log') }
        Add-RawBlock $Sb 1 $Site.extra
        Add-Line $Sb 0 '}'
        Add-Line $Sb 0 ''
        Add-WwwRedirect $Sb $Site
        return
    }

    if ($Site.type -eq 'static' -or $Site.type -eq 'php') {
        Add-Line $Sb 1 ('root * ' + (ConvertTo-PathToken $Site.root))
    }

    if ($Site.encode) { Add-Line $Sb 1 'encode gzip zstd' }

    if ($Site.maxBody) {
        Add-Line $Sb 1 'request_body {'
        Add-Line $Sb 2 ('max_size ' + $Site.maxBody)
        Add-Line $Sb 1 '}'
    }

    if ($Site.securityHeaders -or $Site.hsts) {
        Add-Line $Sb 1 'header {'
        if ($Site.securityHeaders) {
            Add-Line $Sb 2 'X-Content-Type-Options nosniff'
            Add-Line $Sb 2 'X-Frame-Options SAMEORIGIN'
            Add-Line $Sb 2 'Referrer-Policy strict-origin-when-cross-origin'
            Add-Line $Sb 2 'X-Permitted-Cross-Domain-Policies none'
            Add-Line $Sb 2 '-Server'
        }
        if ($Site.hsts) {
            Add-Line $Sb 2 'Strict-Transport-Security "max-age=31536000; includeSubDomains"'
        }
        Add-Line $Sb 1 '}'
    }

    if ($Site.accessLog) { Add-LogDirective $Sb 1 $Config ((Get-HostLabel $Site.domains[0]) + '-access.log') }

    # Terminal-Handler. Bei aktivem Schutz sensibler Pfade werden handle-Bloecke
    # verwendet: die Reihenfolge ist dann eindeutig und unabhaengig von Caddys
    # interner Direktiven-Sortierung.
    # Bei mehreren Zielen wird ein ausgefallenes kurzzeitig uebersprungen.
    # Ohne diese beiden Zeilen schickt Caddy weiter Anfragen an tote Prozesse
    # und antwortet mit 502, statt das naechste Ziel zu nehmen.
    $handlers = New-Object System.Collections.ArrayList
    $handlerLines = New-Object System.Collections.ArrayList
    foreach ($l in (($Site.handlerExtra -replace "`r`n", "`n").Split("`n"))) {
        if ($l.Trim()) { [void]$handlerLines.Add("`t" + $l.Trim()) }
    }
    if ($Site.type -eq 'php') {
        $ups = Get-PhpUpstreams $Config
        if ($Config.php.poolSize -gt 1) {
            [void]$handlerLines.Insert(0, "`tfail_duration 10s")
            [void]$handlerLines.Insert(0, "`tlb_try_duration 5s")
        }
        if ($handlerLines.Count -gt 0) {
            [void]$handlers.Add('php_fastcgi ' + $ups + ' {')
            foreach ($l in $handlerLines) { [void]$handlers.Add($l) }
            [void]$handlers.Add('}')
        } else {
            [void]$handlers.Add('php_fastcgi ' + $ups)
        }
    } elseif ($Site.type -eq 'proxy') {
        if (($Site.upstream -split '\s+').Count -gt 1) {
            [void]$handlerLines.Insert(0, "`tfail_duration 10s")
            [void]$handlerLines.Insert(0, "`tlb_try_duration 5s")
        }
        [void]$handlerLines.Insert(0, "`theader_up X-Real-IP {remote_host}")
        [void]$handlers.Add('reverse_proxy ' + $Site.upstream + ' {')
        foreach ($l in $handlerLines) { [void]$handlers.Add($l) }
        [void]$handlers.Add('}')
    }
    if ($Site.type -eq 'static' -or $Site.type -eq 'php') {
        if ($Site.browse -or $Site.indexFiles) {
            [void]$handlers.Add('file_server {')
            if ($Site.browse) { [void]$handlers.Add("`tbrowse") }
            if ($Site.indexFiles) { [void]$handlers.Add("`tindex " + $Site.indexFiles) }
            [void]$handlers.Add('}')
        } else {
            [void]$handlers.Add('file_server')
        }
    }

    if ($Site.blockSensitive -and $Site.type -ne 'proxy' -and (Test-CaddyAtLeast 2 5)) {
        Add-Line $Sb 1 '@geschuetzt {'
        Add-Line $Sb 2 'not path /.well-known/*'
        # Reihenfolge nach Herkunft: versteckte Dateien, Ablagen von Editoren,
        # Datenbanken, Schluessel und Zertifikate, Konfigurationsreste.
        Add-Line $Sb 2 ('path */.* *~ *.env *.log *.sql *.sqlite *.sqlite3 *.db ' +
                        '*.bak *.old *.orig *.save *.swp *.swo *.tmp ' +
                        '*.ini *web.config *.pem *.key *.crt *.pfx *.p12 ' +
                        '/composer.json /composer.lock /package-lock.json')
        Add-Line $Sb 1 '}'
        Add-Line $Sb 1 'handle @geschuetzt {'
        Add-Line $Sb 2 'error 404'
        Add-Line $Sb 1 '}'
        Add-Line $Sb 1 'handle {'
        foreach ($h in $handlers) { Add-Line $Sb 2 $h }
        Add-Line $Sb 1 '}'
    } else {
        foreach ($h in $handlers) { Add-Line $Sb 1 $h }
    }

    Add-RawBlock $Sb 1 $Site.extra
    Add-Line $Sb 0 '}'
    Add-Line $Sb 0 ''
    Add-WwwRedirect $Sb $Site
}

function Add-WwwRedirect {
    param([System.Text.StringBuilder]$Sb, $Site)
    if (-not $Site.wwwRedirect) { return }
    $primary = $Site.domains[0] -replace '^https?://', ''
    if ($primary -match '^www\.' -or $primary -match '^\*' -or $primary -match '^\d' -or $primary -like 'localhost*') { return }
    $wwwName = 'www.' + $primary
    if ($Site.domains -contains $wwwName) { return }
    Add-Line $Sb 0 ('# www-Umleitung fuer ' + $primary)
    Add-Line $Sb 0 ($wwwName + ' {')
    Add-Line $Sb 1 ('redir https://' + $primary + '{uri} permanent')
    Add-Line $Sb 0 '}'
    Add-Line $Sb 0 ''
}

function Build-Caddyfile {
    param($Config)
    $sb = New-Object System.Text.StringBuilder

    Add-Line $sb 0 ('# ' + ('=' * 74))
    Add-Line $sb 0 '#  Diese Datei wird vom Caddy Manager erzeugt.'
    Add-Line $sb 0 ('#  Erzeugt am ' + (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'))
    Add-Line $sb 0 '#'
    Add-Line $sb 0 '#  Aenderungen von Hand werden beim naechsten "Anwenden" ueberschrieben.'
    Add-Line $sb 0 '#  Wer die Datei selbst pflegen moechte, schaltet im Manager unter'
    Add-Line $sb 0 '#  "Experte" auf den manuellen Modus um.'
    Add-Line $sb 0 ('# ' + ('=' * 74))
    Add-Line $sb 0 '{'
    if ($Config.global.email) { Add-Line $sb 1 ('email ' + $Config.global.email) }
    Add-Line $sb 1 ('admin ' + $Config.global.adminListen)
    # Eine eigene storage-Zeile aus der uebernommenen Caddyfile hat Vorrang:
    # ein Wechsel des Speicherorts wuerde alle Zertifikate neu beantragen.
    if ($Config.global.extra -notmatch '(?m)^\s*storage\b') {
        $storeDir = $(if ($Config.global.storage) { $Config.global.storage } else { $Paths.Data })
        Add-Line $sb 1 ('storage file_system ' + (ConvertTo-PathToken $storeDir))
    }
    Add-Line $sb 1 'log {'
    Add-Line $sb 2 ('output file ' + (ConvertTo-PathToken ($Paths.Logs + '\caddy.log')) + ' {')
    Add-Line $sb 3 ('roll_size ' + $Config.global.rollSize)
    Add-Line $sb 3 ('roll_keep ' + $Config.global.rollKeep)
    Add-Line $sb 2 '}'
    Add-Line $sb 2 'format json'
    Add-Line $sb 2 ('level ' + $Config.global.logLevel)
    Add-Line $sb 1 '}'
    # Nur auf ausdruecklichen Wunsch. Caddy vertraut von Haus aus keinem
    # vorgelagerten Proxy. Das pauschal zu lockern waere ein Rueckschritt, wenn
    # gar keiner davorsteht: dann koennte jeder aus dem lokalen Netz die
    # Client-Adresse in Protokollen und Zugriffsregeln faelschen.
    if ($Config.global.trustedProxies -and (Test-CaddyAtLeast 2 7) -and
        ($Config.global.extra -notmatch '(?m)^\s*servers\b')) {
        Add-Line $sb 1 'servers {'
        Add-Line $sb 2 'trusted_proxies static private_ranges'
        Add-Line $sb 1 '}'
    }
    Add-RawBlock $sb 1 $Config.global.extra
    Add-Line $sb 0 '}'
    Add-Line $sb 0 ''

    # Bausteine und Importe muessen vor ihrer Verwendung stehen
    if ($Config.global.snippets -and $Config.global.snippets.Trim()) {
        Add-Line $sb 0 '# Bausteine und Importe aus der urspruenglichen Caddyfile'
        Add-RawBlock $sb 0 $Config.global.snippets
        Add-Line $sb 0 ''
    }

    $active = @($Config.sites | Where-Object { $_.enabled })
    if ($active.Count -eq 0) {
        Add-Line $sb 0 '# Noch keine Domain aktiv. Im Manager unter "Domains" anlegen.'
        Add-Line $sb 0 'http://localhost:8080 {'
        Add-Line $sb 1 'respond "Caddy laeuft. Im Caddy Manager unter Domains die erste Seite anlegen." 200'
        Add-Line $sb 0 '}'
        Add-Line $sb 0 ''
    } else {
        foreach ($site in $active) { Build-SiteBlock $sb $site $Config }
    }

    # Ohne abschliessende Leerzeilen - Set-Content haengt selbst einen
    # Zeilenumbruch an, sonst meldet "caddy fmt" eine Abweichung.
    return $sb.ToString().TrimEnd("`r", "`n")
}

# ===========================================================================
#  CADDYFILE-IMPORT
#  Liest eine bestehende Caddyfile ein, damit vorhandene Setups ohne
#  Abtippen uebernommen werden koennen. Nicht erkannte Direktiven landen
#  unveraendert im Feld "extra" und gehen damit nicht verloren.
# ===========================================================================

function Remove-CaddyComments {
    param([string]$Text)
    $sb = New-Object System.Text.StringBuilder
    foreach ($line in ($Text -replace "`r`n", "`n").Split("`n")) {
        $out = New-Object System.Text.StringBuilder
        $inQuote = $false
        $prev = ''
        $chars = $line.ToCharArray()
        for ($i = 0; $i -lt $chars.Length; $i++) {
            $ch = $chars[$i]
            if ($ch -eq '"' -and $prev -ne '\') { $inQuote = -not $inQuote }
            if ($ch -eq '#' -and -not $inQuote) {
                if ($i -eq 0 -or [char]::IsWhiteSpace($chars[$i - 1])) { break }
            }
            [void]$out.Append($ch)
            $prev = [string]$ch
        }
        [void]$sb.AppendLine($out.ToString().TrimEnd())
    }
    return $sb.ToString()
}

# Zerlegt einen Block in seine Anweisungen auf oberster Ebene.
function Split-CaddyStatements {
    param([string]$Body)
    $result = New-Object System.Collections.ArrayList
    if ([string]::IsNullOrWhiteSpace($Body)) { return ,@() }
    $chars = ($Body -replace "`r`n", "`n").ToCharArray()
    $head = New-Object System.Text.StringBuilder
    $inner = New-Object System.Text.StringBuilder
    $depth = 0
    $placeholder = 0
    $inQuote = $false
    $prev = ''
    for ($i = 0; $i -lt $chars.Length; $i++) {
        $ch = $chars[$i]
        if ($ch -eq '"' -and $prev -ne '\') { $inQuote = -not $inQuote }
        if (-not $inQuote) {
            if ($ch -eq '{') {
                # Ein Blockanfang steht immer am Zeilenende. Alles andere ist
                # ein Caddy-Platzhalter wie {uri} oder {remote_host}.
                $j = $i + 1
                while ($j -lt $chars.Length -and ($chars[$j] -eq ' ' -or $chars[$j] -eq "`t" -or $chars[$j] -eq "`r")) { $j++ }
                if ($j -ge $chars.Length -or $chars[$j] -eq "`n") {
                    $depth++
                    if ($depth -eq 1) { $prev = [string]$ch; continue }
                } else {
                    $placeholder++
                }
            } elseif ($ch -eq '}') {
                if ($placeholder -gt 0) {
                    $placeholder--
                } else {
                    $depth--
                    if ($depth -eq 0) {
                        [void]$result.Add([pscustomobject]@{ head = $head.ToString().Trim(); body = $inner.ToString() })
                        [void]$head.Clear(); [void]$inner.Clear()
                        $prev = [string]$ch
                        continue
                    }
                    if ($depth -lt 0) { $depth = 0 }
                }
            } elseif ($ch -eq "`n") {
                $placeholder = 0   # Platzhalter gehen nie ueber das Zeilenende
                if ($depth -eq 0) {
                    $h = $head.ToString().Trim()
                    if ($h) { [void]$result.Add([pscustomobject]@{ head = $h; body = $null }) }
                    [void]$head.Clear()
                    $prev = [string]$ch
                    continue
                }
            }
        }
        if ($depth -eq 0) { [void]$head.Append($ch) } else { [void]$inner.Append($ch) }
        $prev = [string]$ch
    }
    $h = $head.ToString().Trim()
    if ($h) { [void]$result.Add([pscustomobject]@{ head = $h; body = $null }) }
    return ,@($result.ToArray())
}

function Get-Tokens {
    param([string]$Line)
    $tokens = New-Object System.Collections.ArrayList
    $cur = New-Object System.Text.StringBuilder
    $inQuote = $false
    $prev = ''
    foreach ($ch in $Line.ToCharArray()) {
        if ($ch -eq '"' -and $prev -ne '\') { $inQuote = -not $inQuote; $prev = [string]$ch; continue }
        if ([char]::IsWhiteSpace($ch) -and -not $inQuote) {
            if ($cur.Length -gt 0) { [void]$tokens.Add($cur.ToString()); [void]$cur.Clear() }
        } else {
            [void]$cur.Append($ch)
        }
        $prev = [string]$ch
    }
    if ($cur.Length -gt 0) { [void]$tokens.Add($cur.ToString()) }
    return ,@($tokens.ToArray())
}

# Verarbeitet die Direktiven eines Site-Blocks. $Site und $ExtraLines sind
# Referenztypen und werden direkt befuellt, damit handle-Bloecke rekursiv
# eingelesen werden koennen.
function Read-SiteDirectives {
    param($Statements, $Site, $ExtraLines, $Config, [int]$Level = 0)
    foreach ($d in $Statements) {
        $tk = Get-Tokens $d.head
        if ($tk.Count -eq 0) { continue }
        $name = $tk[0].ToLower()

        if ($name -eq '@geschuetzt') { $Site.blockSensitive = $true; continue }
        if ($name -eq 'handle') {
            if ($tk.Count -ge 2 -and $tk[1] -eq '@geschuetzt') { $Site.blockSensitive = $true; continue }
            if ($tk.Count -eq 1 -and $d.body -and $Level -lt 3) {
                Read-SiteDirectives (Split-CaddyStatements $d.body) $Site $ExtraLines $Config ($Level + 1)
                continue
            }
        }

        switch ($name) {
            'root' {
                $p = $(if ($tk.Count -ge 3 -and $tk[1] -eq '*') { $tk[2] } elseif ($tk.Count -ge 2) { $tk[1] } else { '' })
                $rp = Resolve-LocalPath $p
                if ($rp) { $Site.root = $rp }
            }
            'encode' { $Site.encode = $true }
            'file_server' {
                if ($tk -contains 'browse') { $Site.browse = $true }
                if ($d.body) {
                    foreach ($f in (Split-CaddyStatements $d.body)) {
                        $ft = Get-Tokens $f.head
                        if ($ft.Count -ge 1 -and $ft[0] -eq 'browse') { $Site.browse = $true }
                        elseif ($ft.Count -ge 2 -and $ft[0] -eq 'index') { $Site.indexFiles = (($ft | Select-Object -Skip 1) -join ' ') }
                    }
                }
            }
            'php_fastcgi' {
                $Site.type = 'php'
                $Config.php.enabled = $true
                # Anzahl und Startport aus der vorhandenen Zeile uebernehmen. Sonst
                # wuerden aus einem laufenden PHP-Prozess stillschweigend vier, und
                # drei davon liefen ins Leere, bis die Einrichtung nachgezogen hat.
                $pports = New-Object System.Collections.ArrayList
                foreach ($u in ($tk | Select-Object -Skip 1)) {
                    if ($u -match '^127\.0\.0\.1:(\d{2,5})$') { [void]$pports.Add([int]$Matches[1]) }
                }
                if ($pports.Count -gt 0) {
                    $sorted = @($pports.ToArray() | Sort-Object)
                    if ($sorted.Count -gt $Config.php.poolSize) {
                        $Config.php.poolSize = $sorted.Count
                        $Config.php.basePort = $sorted[0]
                    }
                }
                Read-HandlerOptions $d $Site
            }
            'reverse_proxy' {
                $ups = New-Object System.Collections.ArrayList
                foreach ($u in ($tk | Select-Object -Skip 1)) {
                    $n = Resolve-Upstream $u
                    if ($n) { [void]$ups.Add($n) }
                }
                if ($ups.Count -gt 0) {
                    $Site.type = 'proxy'
                    $Site.upstream = ($ups.ToArray() -join ' ')
                    Read-HandlerOptions $d $Site
                } else {
                    [void]$ExtraLines.Add($d.head)
                }
            }
            'redir' {
                if ($tk.Count -ge 2) {
                    $target = $tk[1] -replace '\{uri\}\s*$', ''
                    if ($target -match '^https?://') {
                        $Site.type = 'redirect'
                        $Site.redirectTo = $target
                        if ($tk.Count -ge 3 -and @('permanent', 'temporary', 'html') -contains $tk[2]) {
                            $Site.redirectCode = $tk[2]
                        }
                    } else { [void]$ExtraLines.Add($d.head) }
                }
            }
            'respond' {
                if ($tk.Count -ge 2) {
                    $Site.type = 'respond'
                    $Site.respondBody = Get-SafeString $tk[1] 500
                    if ($tk.Count -ge 3 -and $tk[2] -match '^\d{3}$') { $Site.respondStatus = [int]$tk[2] }
                }
            }
            'error' { }
            'log' { $Site.accessLog = $true }
            'header' {
                $body = $(if ($d.body) { $d.body } else { '' })
                if ($body -match 'X-Content-Type-Options') { $Site.securityHeaders = $true }
                if ($body -match 'Strict-Transport-Security') { $Site.hsts = $true }
                $rest = New-Object System.Collections.ArrayList
                foreach ($h in (Split-CaddyStatements $body)) {
                    $ht = Get-Tokens $h.head
                    if ($ht.Count -eq 0) { continue }
                    if (@('x-content-type-options', 'x-frame-options', 'referrer-policy',
                          'x-permitted-cross-domain-policies', '-server',
                          'strict-transport-security') -contains $ht[0].ToLower()) { continue }
                    [void]$rest.Add($h.head)
                }
                if ($rest.Count -gt 0) {
                    [void]$ExtraLines.Add('header {')
                    foreach ($r in $rest) { [void]$ExtraLines.Add("`t" + $r) }
                    [void]$ExtraLines.Add('}')
                }
            }
            'request_body' {
                if ($d.body) {
                    foreach ($rb in (Split-CaddyStatements $d.body)) {
                        $rt = Get-Tokens $rb.head
                        if ($rt.Count -ge 2 -and $rt[0] -eq 'max_size' -and (Test-SizeValue $rt[1])) { $Site.maxBody = $rt[1] }
                    }
                }
            }
            'tls' {
                if ($tk.Count -eq 2 -and $tk[1] -eq 'internal') { $Site.tlsMode = 'internal' }
                elseif ($tk.Count -ge 3) {
                    $c = Resolve-LocalPath $tk[1]; $k = Resolve-LocalPath $tk[2]
                    if ($c -and $k) { $Site.tlsMode = 'custom'; $Site.tlsCert = $c; $Site.tlsKey = $k }
                }
            }
            'basicauth'  { Read-BasicAuth $d $Site }
            'basic_auth' { Read-BasicAuth $d $Site }
            default {
                if ($d.body) {
                    [void]$ExtraLines.Add($d.head + ' {')
                    foreach ($ln in (Get-ReindentedLines $d.body)) { [void]$ExtraLines.Add($ln) }
                    [void]$ExtraLines.Add('}')
                } else {
                    [void]$ExtraLines.Add($d.head)
                }
            }
        }
    }
}

# Eigene Optionen innerhalb von reverse_proxy / php_fastcgi bleiben erhalten.
# Was der Manager selbst schreibt, wird dabei nicht doppelt uebernommen.
function Read-HandlerOptions {
    param($Statement, $Site)
    if (-not $Statement.body) { return }
    $keep = New-Object System.Collections.ArrayList
    foreach ($o in (Split-CaddyStatements $Statement.body)) {
        $t = $o.head.Trim()
        if (-not $t) { continue }
        if ($t -eq 'header_up X-Real-IP {remote_host}') { continue }
        if ($t -match '^(lb_try_duration|fail_duration)\s') { continue }
        if ($o.body) {
            [void]$keep.Add($t + ' {')
            foreach ($l in (Get-ReindentedLines $o.body)) { [void]$keep.Add($l) }
            [void]$keep.Add('}')
        } else {
            [void]$keep.Add($t)
        }
    }
    if ($keep.Count -gt 0) {
        $joined = ($keep.ToArray() -join "`n")
        if (Test-BalancedBraces $joined) { $Site.handlerExtra = $joined }
    }
}

function Read-BasicAuth {
    param($Statement, $Site)
    if (-not $Statement.body) { return }
    foreach ($b in (Split-CaddyStatements $Statement.body)) {
        $bt = Get-Tokens $b.head
        if ($bt.Count -ge 2 -and (Test-UserName $bt[0]) -and (Test-BcryptHash $bt[1])) {
            $Site.basicAuthUser = $bt[0]
            $Site.basicAuthHash = $bt[1]
        }
    }
}

function Import-Caddyfile {
    param([string]$Text)
    $cfg = New-DefaultConfig
    # 0 heisst: noch keine php_fastcgi-Zeile gesehen. Am Ende wird daraus die
    # Vorgabe, falls die Datei keine Angabe enthielt.
    $cfg.php.poolSize = 0
    $clean = Remove-CaddyComments $Text
    $statements = Split-CaddyStatements $clean
    $sites = New-Object System.Collections.ArrayList
    $skipped = New-Object System.Collections.ArrayList

    $snippets = New-Object System.Collections.ArrayList

    foreach ($st in $statements) {
        if ($null -eq $st.body) {
            # Ein import ausserhalb aller Bloecke gehoert unveraendert erhalten
            if ($st.head -match '^(?i)import\s') { [void]$snippets.Add($st.head); continue }
            if ($st.head) { [void]$skipped.Add((Get-SafeString $st.head 120)) }
            continue
        }

        # Baustein: (name) { ... } - wird woertlich uebernommen, weil Site-Bloecke
        # ihn per import verwenden koennen.
        if ($st.head -match '^\([A-Za-z0-9_\-\.]+\)$') {
            $inner = Get-ReindentedLines $st.body
            [void]$snippets.Add($st.head + ' {' + "`n" + ($inner -join "`n") + "`n" + '}')
            continue
        }

        # Globaler Optionsblock
        if ([string]::IsNullOrWhiteSpace($st.head)) {
            foreach ($g in (Split-CaddyStatements $st.body)) {
                $tk = Get-Tokens $g.head
                if ($tk.Count -eq 0) { continue }
                switch ($tk[0].ToLower()) {
                    'email'   { if ($tk.Count -gt 1 -and (Test-EmailAddress $tk[1])) { $cfg.global.email = $tk[1] } }
                    'admin'   { if ($tk.Count -gt 1) { $cfg.global.adminListen = (Get-SafeString $tk[1] 120) } }
                    'storage' {
                        # file_system kennt der Manager, alles andere bleibt woertlich stehen
                        if ($tk.Count -ge 3 -and $tk[1] -eq 'file_system') {
                            $sp = Resolve-LocalPath $tk[2]
                            if ($sp) { $cfg.global.storage = $sp }
                        } else {
                            $keep = $g.head
                            if ($g.body) {
                                $keep = $g.head + ' {' + "`n" +
                                        ((Get-ReindentedLines $g.body) -join "`n") + "`n" + '}'
                            }
                            $cfg.global.extra = ($cfg.global.extra + "`n" + $keep).Trim()
                        }
                    }
                    'log'     { }
                    'servers' {
                        if ($g.body -and $g.body -match 'trusted_proxies') {
                            $cfg.global.trustedProxies = $true
                        } elseif ($g.body) {
                            $blockText = $g.head + ' {' + "`n" +
                                         ((Get-ReindentedLines $g.body) -join "`n") + "`n" + '}'
                            $cfg.global.extra = ($cfg.global.extra + "`n" + $blockText).Trim()
                        }
                    }
                    default {
                        if ($null -eq $g.body) {
                            $cfg.global.extra = ($cfg.global.extra + "`n" + $g.head).Trim()
                        } else {
                            $indented = Get-ReindentedLines $g.body
                            $blockText = $g.head + ' {' + "`n" + ($indented -join "`n") + "`n" + '}'
                            $cfg.global.extra = ($cfg.global.extra + "`n" + $blockText).Trim()
                        }
                    }
                }
            }
            continue
        }

        # Site-Block
        $addresses = New-Object System.Collections.ArrayList
        $bad = $false
        foreach ($a in ($st.head -split '[,\s]+')) {
            if ([string]::IsNullOrWhiteSpace($a)) { continue }
            $n = Resolve-SiteAddress $a
            if ($n) { [void]$addresses.Add($n) } else { $bad = $true }
        }
        if ($addresses.Count -eq 0) { [void]$skipped.Add((Get-SafeString $st.head 120)); continue }
        if ($bad) { [void]$skipped.Add((Get-SafeString $st.head 120)) }

        $site = New-SiteObject
        $site.domains         = $addresses.ToArray()
        $site.type            = 'static'
        $site.securityHeaders = $false
        $site.blockSensitive  = $false
        $site.accessLog       = $false
        $site.encode          = $false
        $site.hsts            = $false

        $extraLines = New-Object System.Collections.ArrayList
        Read-SiteDirectives (Split-CaddyStatements $st.body) $site $extraLines $cfg 0

        $joined = ($extraLines.ToArray() -join "`n")
        if (Test-BalancedBraces $joined) { $site.extra = $joined }
        $cleanSite = ConvertTo-CleanSite $site
        if ($cleanSite) { [void]$sites.Add($cleanSite) }
    }

    if ($cfg.php.poolSize -lt 1) { $cfg.php.poolSize = 4 }
    $cfg.sites = $sites.ToArray()
    $joinedSnippets = ($snippets.ToArray() -join "`n`n")
    if ($joinedSnippets.Length -le 16000 -and (Test-BalancedBraces $joinedSnippets)) {
        $cfg.global.snippets = $joinedSnippets
    }
    return [pscustomobject]@{ config = $cfg; imported = $sites.Count; skipped = @($skipped.ToArray()) }
}

# ===========================================================================
#  EINRICHTUNG
#  Alles was frueher von Hand aus der README kopiert werden musste.
# ===========================================================================

$TaskFolder = '\CaddyManager\'
$TaskServer = 'Server'
$TaskWatch  = 'Watchdog'

function Test-IsAdmin {
    try {
        $id = [Security.Principal.WindowsIdentity]::GetCurrent()
        $pr = New-Object Security.Principal.WindowsPrincipal($id)
        return $pr.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
    } catch { return $false }
}

function Get-SystemArch {
    $a = $env:PROCESSOR_ARCHITECTURE
    if ($env:PROCESSOR_ARCHITEW6432) { $a = $env:PROCESSOR_ARCHITEW6432 }
    switch ($a) {
        'ARM64' { return 'arm64' }
        'AMD64' { return 'amd64' }
        'x86'   { return '386' }
        default { return 'amd64' }
    }
}

function Invoke-Download {
    param([string]$Uri, [string]$OutFile, [int]$TimeoutSec = 600)
    $old = $ProgressPreference
    $ProgressPreference = 'SilentlyContinue'
    try {
        $dir = Split-Path -Parent $OutFile
        if ($dir -and -not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
        if (Test-Path -LiteralPath $OutFile) { Remove-Item -LiteralPath $OutFile -Force }
        Invoke-WebRequest -Uri $Uri -OutFile $OutFile -UseBasicParsing -TimeoutSec $TimeoutSec -MaximumRedirection 5
        if (-not (Test-Path -LiteralPath $OutFile)) { throw 'Datei wurde nicht geschrieben' }
        return $true
    } finally {
        $ProgressPreference = $old
    }
}

function Test-IsExecutable {
    param([string]$Path)
    try {
        $fs = [System.IO.File]::OpenRead($Path)
        try {
            $b = New-Object byte[] 2
            [void]$fs.Read($b, 0, 2)
            return ($b[0] -eq 0x4D -and $b[1] -eq 0x5A)   # "MZ"
        } finally { $fs.Close() }
    } catch { return $false }
}

function Initialize-Directories {
    $created = New-Object System.Collections.ArrayList
    foreach ($p in @($Paths.Root, $Paths.Www, $Paths.Logs, $Paths.Data, $Paths.Manager, $Paths.Backups)) {
        if (-not (Test-Path -LiteralPath $p)) {
            New-Item -ItemType Directory -Path $p -Force | Out-Null
            [void]$created.Add($p)
        }
    }
    return @{ ok = $true; message = "Verzeichnisse bereit ($($created.Count) neu angelegt)"; created = @($created.ToArray()) }
}

# ---------------------------------------------------------------------------
#  Vorhandenen Zertifikatsspeicher uebernehmen
#
#  Ohne "storage"-Zeile legt Caddy seine Zertifikate im Profil des ausfuehrenden
#  Kontos ab. Der Manager setzt die Zeile auf C:\caddy\data - ohne Umzug wuerde
#  Caddy alle Zertifikate neu beantragen und koennte in die Mengenbegrenzung von
#  Let's Encrypt laufen. Deshalb wird ein vorhandener Speicher einmalig kopiert.
# ---------------------------------------------------------------------------
function Get-DefaultStorageDirs {
    $dirs = New-Object System.Collections.ArrayList
    try {
        $win = $env:SystemRoot
        if (-not $win) { $win = 'C:\Windows' }
        $win = $win.TrimEnd('\')
        # Bewusst ohne Join-Path: das wirft, wenn das Laufwerk nicht existiert.
        $candidates = @(
            ($win + '\System32\config\systemprofile\AppData\Roaming\Caddy'),
            ($win + '\ServiceProfiles\LocalService\AppData\Roaming\Caddy'),
            ($win + '\ServiceProfiles\NetworkService\AppData\Roaming\Caddy')
        )
        if ($env:AppData) { $candidates += ($env:AppData.TrimEnd('\') + '\Caddy') }
        if ($env:LocalAppData) { $candidates += ($env:LocalAppData.TrimEnd('\') + '\Caddy') }
        foreach ($d in $candidates) {
            try { if (Test-Path -LiteralPath $d) { [void]$dirs.Add($d) } } catch { }
        }
    } catch { }
    return ,@($dirs.ToArray())
}

function Import-CertificateStore {
    try {
        $target = $Paths.Data
        if (Test-Path -LiteralPath ($target + '\certificates')) {
            return @{ ok = $true; message = 'Zertifikatsspeicher ist bereits vorhanden.'; changed = $false }
        }
        foreach ($src in (Get-DefaultStorageDirs)) {
            $certs = $src.TrimEnd('\') + '\certificates'
            if (-not (Test-Path -LiteralPath $certs)) { continue }
            $found = @(Get-ChildItem -LiteralPath $certs -Filter '*.crt' -Recurse -ErrorAction SilentlyContinue)
            if ($found.Count -eq 0) { continue }
            if (-not (Test-Path -LiteralPath $target)) { New-Item -ItemType Directory -Path $target -Force | Out-Null }
            Copy-Item -LiteralPath $src -Destination $target -Recurse -Force -Container -ErrorAction Stop
            # Copy-Item legt einen Unterordner an, wenn das Ziel existiert
            $nested = $target + '\' + (Split-Path -Leaf $src)
            if (Test-Path -LiteralPath ($nested + '\certificates')) {
                foreach ($item in @(Get-ChildItem -LiteralPath $nested -Force -ErrorAction SilentlyContinue)) {
                    Move-Item -LiteralPath $item.FullName -Destination $target -Force -ErrorAction SilentlyContinue
                }
                Remove-Item -LiteralPath $nested -Recurse -Force -ErrorAction SilentlyContinue
            }
            Write-Audit 'certs.migrated' "$src -> $target ($($found.Count) Zertifikate)"
            return @{ ok = $true; changed = $true
                      message = "$($found.Count) vorhandene Zertifikate uebernommen - es wird nichts neu beantragt." }
        }
        return @{ ok = $true; message = 'Kein vorhandener Zertifikatsspeicher gefunden.'; changed = $false }
    } catch {
        return @{ ok = $false; message = "Zertifikate konnten nicht uebernommen werden: $($_.Exception.Message)" }
    }
}

# ---------------------------------------------------------------------------
#  Caddy herunterladen / aktualisieren
# ---------------------------------------------------------------------------
function Install-Caddy {
    param([switch]$Force)
    $arch = Get-SystemArch
    $url = "https://caddyserver.com/api/download?os=windows&arch=$arch"
    $tmp = Join-Path $Paths.Manager 'caddy.download.exe'
    $installed = Get-CaddyVersionInfo -Refresh

    if ($installed.installed -and -not $Force) {
        return @{ ok = $true; message = "Caddy $($installed.version) ist bereits installiert."; version = $installed.version; changed = $false }
    }

    if (-not (Test-Path -LiteralPath $Paths.Manager)) { New-Item -ItemType Directory -Path $Paths.Manager -Force | Out-Null }
    try {
        Invoke-Download -Uri $url -OutFile $tmp -TimeoutSec 900 | Out-Null
    } catch {
        return @{ ok = $false; message = "Download fehlgeschlagen: $($_.Exception.Message)" }
    }

    $size = (Get-Item -LiteralPath $tmp).Length
    if ($size -lt 5MB -or -not (Test-IsExecutable $tmp)) {
        Remove-Item -LiteralPath $tmp -Force -ErrorAction SilentlyContinue
        return @{ ok = $false; message = 'Die heruntergeladene Datei ist kein g{ue}ltiges Windows-Programm. Vermutlich hat ein Proxy oder Virenschutz den Download veraendert.' }
    }

    $newVer = ''
    $r = Invoke-Exe -FilePath $tmp -Arguments @('version') -TimeoutSec 30
    $out = Get-ExeOutput $r
    if ($out -match 'v?(\d+\.\d+\.\d+)') { $newVer = $Matches[1] }
    if (-not $newVer) {
        Remove-Item -LiteralPath $tmp -Force -ErrorAction SilentlyContinue
        return @{ ok = $false; message = 'Die heruntergeladene Datei l{ae}sst sich nicht ausf{ue}hren.' }
    }

    if ($installed.installed -and $installed.version -eq $newVer -and -not $Force) {
        Remove-Item -LiteralPath $tmp -Force -ErrorAction SilentlyContinue
        return @{ ok = $true; message = "Caddy $newVer ist bereits aktuell."; version = $newVer; changed = $false }
    }

    $wasRunning = [bool](Get-Process -Name caddy -ErrorAction SilentlyContinue)
    if ($wasRunning) { Stop-CaddyServer | Out-Null }

    try {
        if (Test-Path -LiteralPath $Paths.Exe) {
            Copy-Item -LiteralPath $Paths.Exe -Destination (Join-Path $Paths.Manager 'caddy.previous.exe') -Force -ErrorAction SilentlyContinue
        }
        Move-Item -LiteralPath $tmp -Destination $Paths.Exe -Force
    } catch {
        return @{ ok = $false; message = "Caddy konnte nicht ersetzt werden: $($_.Exception.Message)" }
    }

    # Ein Virenschutz greift oft erst zu, wenn die Datei am endgueltigen Platz liegt
    Start-Sleep -Milliseconds 500
    if (-not (Test-Path -LiteralPath $Paths.Exe)) {
        return @{ ok = $false
                  message = ('caddy.exe ist direkt nach dem Kopieren wieder verschwunden. ' +
                             'Sehr wahrscheinlich hat ein Virenschutz sie in Quarant{ae}ne verschoben. ' +
                             'Bitte ' + $Paths.Root + ' dort als Ausnahme eintragen und erneut versuchen.') }
    }
    $script:CaddyVersion = $null
    Get-CaddyVersionInfo -Refresh | Out-Null
    if ($wasRunning) { Start-CaddyServer | Out-Null }
    Write-Audit 'caddy.install' "version=$newVer arch=$arch"
    $verb = $(if ($installed.installed) { 'aktualisiert auf' } else { 'installiert:' })
    return @{ ok = $true; message = "Caddy $verb $newVer"; version = $newVer; changed = $true }
}

# ---------------------------------------------------------------------------
#  PHP
# ---------------------------------------------------------------------------
function Test-VcRedist {
    return (Test-Path -LiteralPath "$env:SystemRoot\System32\vcruntime140.dll")
}

function Install-VcRedist {
    if (Test-VcRedist) { return @{ ok = $true; message = 'Visual-C++-Laufzeit ist vorhanden.'; changed = $false } }
    $arch = Get-SystemArch
    $url = $(if ($arch -eq 'arm64') { 'https://aka.ms/vs/17/release/vc_redist.arm64.exe' } else { 'https://aka.ms/vs/17/release/vc_redist.x64.exe' })
    $tmp = Join-Path $Paths.Manager 'vc_redist.exe'
    try {
        Invoke-Download -Uri $url -OutFile $tmp -TimeoutSec 600 | Out-Null
        if (-not (Test-IsExecutable $tmp)) { throw 'Ung{ue}ltige Datei' }
        $r = Invoke-Exe -FilePath $tmp -Arguments @('/quiet', '/norestart') -TimeoutSec 600
        Remove-Item -LiteralPath $tmp -Force -ErrorAction SilentlyContinue
        # 0 = ok, 1638/3010 = bereits vorhanden / Neustart noetig
        if ($r.code -eq 0 -or $r.code -eq 1638 -or $r.code -eq 3010) {
            return @{ ok = $true; message = 'Visual-C++-Laufzeit eingerichtet.'; changed = $true }
        }
        return @{ ok = $false; message = "Visual-C++-Laufzeit meldete Code $($r.code)." }
    } catch {
        return @{ ok = $false; message = "Visual-C++-Laufzeit konnte nicht installiert werden: $($_.Exception.Message)" }
    }
}

function Get-PhpDownloadInfo {
    $arch = Get-SystemArch
    $suffix = $(if ($arch -eq 'arm64') { 'arm64' } elseif ($arch -eq '386') { 'x86' } else { 'x64' })
    $json = Invoke-WebRequest -Uri 'https://windows.php.net/downloads/releases/releases.json' -UseBasicParsing -TimeoutSec 60
    $data = ConvertFrom-Json $json.Content
    $best = $null
    $bestVer = $null
    foreach ($prop in $data.PSObject.Properties) {
        if ($prop.Name -notmatch '^\d+\.\d+$') { continue }
        $branch = $prop.Value
        $verText = ''
        try { $verText = [string]$branch.version } catch { }
        if (-not $verText) { continue }
        $v = $null
        if (-not [version]::TryParse($verText, [ref]$v)) { continue }
        if ($null -eq $bestVer -or $v -gt $bestVer) {
            foreach ($k in $branch.PSObject.Properties) {
                if ($k.Name -match ('^nts-vs\d+-' + $suffix + '$')) {
                    $zip = $null
                    try { $zip = $k.Value.zip } catch { }
                    if ($zip -and $zip.path) {
                        $bestVer = $v
                        $sha = ''
                        try { $sha = [string]$zip.sha256 } catch { }
                        $best = @{
                            version = $verText
                            file    = [string]$zip.path
                            sha256  = $sha
                            url     = 'https://windows.php.net/downloads/releases/' + [string]$zip.path
                        }
                    }
                }
            }
        }
    }
    return $best
}

function Install-Php {
    param($Config)
    $vc = Install-VcRedist
    if (-not $vc.ok) { return $vc }

    $info = $null
    try { $info = Get-PhpDownloadInfo } catch {
        return @{ ok = $false; message = "PHP-Versionsliste nicht erreichbar: $($_.Exception.Message)" }
    }
    if (-not $info) { return @{ ok = $false; message = 'Keine passende PHP-Version f{ue}r diese Architektur gefunden.' } }

    if (Test-Path -LiteralPath $Paths.PhpExe) {
        $cur = Invoke-Exe -FilePath (Join-Path $Paths.Php 'php.exe') -Arguments @('-n', '-r', 'echo PHP_VERSION;') -TimeoutSec 30
        $curVer = (Get-ExeOutput $cur).Trim()
        if ($curVer -eq $info.version) {
            Set-PhpIni $Config | Out-Null
            return @{ ok = $true; message = "PHP $curVer ist bereits aktuell."; version = $curVer; changed = $false }
        }
    }

    $zip = Join-Path $Paths.Manager 'php.zip'
    try {
        Invoke-Download -Uri $info.url -OutFile $zip -TimeoutSec 900 | Out-Null
    } catch {
        return @{ ok = $false; message = "PHP-Download fehlgeschlagen: $($_.Exception.Message)" }
    }

    if ($info.sha256) {
        $actual = (Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash.ToLower()
        if ($actual -ne $info.sha256.ToLower()) {
            Remove-Item -LiteralPath $zip -Force -ErrorAction SilentlyContinue
            return @{ ok = $false; message = 'Pr{ue}fsumme des PHP-Downloads stimmt nicht. Abbruch.' }
        }
    }

    Stop-PhpPool | Out-Null
    try {
        if (-not (Test-Path -LiteralPath $Paths.Php)) { New-Item -ItemType Directory -Path $Paths.Php -Force | Out-Null }
        $old = $ProgressPreference
        $ProgressPreference = 'SilentlyContinue'
        try { Expand-Archive -LiteralPath $zip -DestinationPath $Paths.Php -Force } finally { $ProgressPreference = $old }
    } catch {
        return @{ ok = $false; message = "PHP konnte nicht entpackt werden: $($_.Exception.Message)" }
    } finally {
        Remove-Item -LiteralPath $zip -Force -ErrorAction SilentlyContinue
    }

    if (-not (Test-Path -LiteralPath $Paths.PhpExe)) {
        return @{ ok = $false; message = 'php-cgi.exe wurde im Archiv nicht gefunden.' }
    }

    Set-PhpIni $Config | Out-Null
    try { [Environment]::SetEnvironmentVariable('PHP_FCGI_MAX_REQUESTS', '0', 'Machine') } catch { }
    Write-Audit 'php.install' "version=$($info.version)"
    return @{ ok = $true; message = "PHP $($info.version) eingerichtet."; version = $info.version; changed = $true }
}

$PhpIniMarkerStart = '; ===== CADDY MANAGER START - nicht von Hand aendern ====='
$PhpIniMarkerEnd   = '; ===== CADDY MANAGER ENDE ====='

function Get-PhpIniBlock {
    param($Config)
    $sessionDir = $Paths.Data + '\php-sessions'
    $lines = @(
        $PhpIniMarkerStart,
        '; Diese Einstellungen setzt der Caddy Manager. Sie stehen bewusst am',
        '; Dateiende, damit sie die Vorgaben weiter oben ueberschreiben.',
        '',
        '; --- Sicherheit ---',
        'expose_php = Off',
        'display_errors = Off',
        'display_startup_errors = Off',
        'log_errors = On',
        'error_log = "' + $Paths.Logs + '\php-error.log"',
        'allow_url_include = Off',
        'zend.exception_ignore_args = On',
        '',
        '; --- FastCGI hinter Caddy ---',
        'cgi.fix_pathinfo = 0',
        'cgi.force_redirect = 0',
        'fastcgi.impersonate = 0',
        '',
        '; --- Grenzwerte ---',
        'memory_limit = 256M',
        'max_execution_time = 120',
        'max_input_time = 120',
        'post_max_size = 128M',
        'upload_max_filesize = 128M',
        'max_file_uploads = 30',
        'date.timezone = "Europe/Berlin"',
        '',
        '; --- Sitzungen ---',
        'session.save_path = "' + $sessionDir + '"',
        'session.cookie_httponly = 1',
        'session.cookie_samesite = "Lax"',
        'session.use_strict_mode = 1',
        'session.use_only_cookies = 1',
        '',
        '; --- Erweiterungen ---',
        'extension_dir = "' + $Paths.Php + '\ext"',
        'extension=curl',
        'extension=exif',
        'extension=fileinfo',
        'extension=gd',
        'extension=intl',
        'extension=mbstring',
        'extension=mysqli',
        'extension=openssl',
        'extension=pdo_mysql',
        'extension=pdo_sqlite',
        'extension=sqlite3',
        'extension=sodium',
        'extension=zip',
        '',
        '; --- Opcache ---',
        'zend_extension=opcache',
        'opcache.enable = 1',
        'opcache.memory_consumption = 128',
        'opcache.max_accelerated_files = 10000',
        'opcache.revalidate_freq = 2'
    )
    if ($Config -and (Get-BoolField $Config.php 'disableRiskyFunctions' $false)) {
        $lines += ''
        $lines += '; --- Riskante Funktionen gesperrt ---'
        $lines += 'disable_functions = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec'
    }
    $lines += $PhpIniMarkerEnd
    return ($lines -join "`r`n")
}

function Set-PhpIni {
    param($Config)
    try {
        if (-not (Test-Path -LiteralPath $Paths.Php)) { return @{ ok = $false; message = 'PHP-Verzeichnis fehlt.' } }
        $sessionDir = $Paths.Data + '\php-sessions'
        if (-not (Test-Path -LiteralPath $sessionDir)) { New-Item -ItemType Directory -Path $sessionDir -Force | Out-Null }

        $base = ''
        if (Test-Path -LiteralPath $Paths.PhpIni) {
            $base = [string](Get-Content -LiteralPath $Paths.PhpIni -Raw)
            Backup-File -Path $Paths.PhpIni -Prefix 'php.ini' | Out-Null
        } else {
            $prod = Join-Path $Paths.Php 'php.ini-production'
            if (Test-Path -LiteralPath $prod) { $base = [string](Get-Content -LiteralPath $prod -Raw) }
        }
        if ($null -eq $base) { $base = '' }
        # frueheren Block entfernen
        $idx = $base.IndexOf($PhpIniMarkerStart)
        if ($idx -ge 0) { $base = $base.Substring(0, $idx) }
        $text = $base.TrimEnd() + "`r`n`r`n" + (Get-PhpIniBlock $Config) + "`r`n"
        Set-Content -LiteralPath $Paths.PhpIni -Value $text -Encoding ASCII
        return @{ ok = $true; message = 'php.ini geschrieben.' }
    } catch {
        return @{ ok = $false; message = "php.ini konnte nicht geschrieben werden: $($_.Exception.Message)" }
    }
}

# ---------------------------------------------------------------------------
#  Geplante Aufgaben
# ---------------------------------------------------------------------------
function Get-TaskPrincipal {
    param([string]$RunAs)
    $ids = $(if ($RunAs -eq 'LOCAL SERVICE') { @('S-1-5-19', 'NT AUTHORITY\LOCAL SERVICE', 'LOCAL SERVICE') }
             else { @('S-1-5-18', 'NT AUTHORITY\SYSTEM', 'SYSTEM') })
    foreach ($id in $ids) {
        try {
            return (New-ScheduledTaskPrincipal -UserId $id -LogonType ServiceAccount -RunLevel Highest)
        } catch { }
    }
    throw 'Es konnte kein Dienstkonto f{ue}r die geplante Aufgabe gesetzt werden.'
}

function Get-TaskSettings {
    $args2 = @{
        AllowStartIfOnBatteries    = $true
        DontStopIfGoingOnBatteries = $true
        StartWhenAvailable         = $true
        MultipleInstances          = 'IgnoreNew'
        ExecutionTimeLimit         = ([TimeSpan]::Zero)
    }
    try { return (New-ScheduledTaskSettingsSet @args2) } catch { }
    # Ohne ExecutionTimeLimit gilt die Vorgabe von 72 Stunden - Caddy wuerde
    # danach beendet. Deshalb im Ersatzweg nachtraeglich auf "unbegrenzt" setzen.
    $set = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable
    try { $set.ExecutionTimeLimit = 'PT0S' } catch { }
    try { $set.MultipleInstances = 'IgnoreNew' } catch { }
    return $set
}

function Register-Task {
    param([string]$Name, [string]$Execute, [string]$Argument, $Triggers, [string]$RunAs, [string]$Description = '')
    try { Clear-TaskCache } catch { }
    $action = New-ScheduledTaskAction -Execute $Execute -Argument $Argument
    $principal = Get-TaskPrincipal $RunAs
    $settings = Get-TaskSettings
    $params = @{
        TaskName  = $Name
        TaskPath  = $TaskFolder
        Action    = $action
        Trigger   = $Triggers
        Principal = $principal
        Settings  = $settings
        Force     = $true
    }
    if ($Description) { $params['Description'] = (T $Description) }
    Register-ScheduledTask @params | Out-Null
}

function New-StartupTrigger {
    $t = New-ScheduledTaskTrigger -AtStartup
    try { $t.Delay = 'PT15S' } catch { }
    return $t
}

# Ein Wiederholungstrigger laesst sich je nach Windows-Version unterschiedlich
# gut erzeugen. Deshalb mehrere Wege, vom saubersten zum groebsten.
function New-RepeatTrigger {
    param([int]$Minutes = 3)
    $start = (Get-Date).AddMinutes(1)
    $span = New-TimeSpan -Minutes $Minutes
    try {
        return (New-ScheduledTaskTrigger -Once -At $start -RepetitionInterval $span `
                   -RepetitionDuration ([TimeSpan]::MaxValue))
    } catch { }
    try {
        return (New-ScheduledTaskTrigger -Once -At $start -RepetitionInterval $span `
                   -RepetitionDuration (New-TimeSpan -Days 3650))
    } catch { }
    # Kein stiller Rueckfall auf einen Einmal-Trigger: lieber nichts liefern,
    # dann meldet die Sicherheitspruefung den fehlenden Takt ehrlich.
    return $null
}

# Register-ScheduledTask legt den Ordner normalerweise selbst an. Auf einigen
# Windows-Ausgaben klappt das nicht, deshalb hier vorsorglich ueber COM.
function Confirm-TaskFolder {
    try {
        $svc = New-Object -ComObject 'Schedule.Service'
        $svc.Connect()
        $root = $svc.GetFolder('\')
        $name = $TaskFolder.Trim('\')
        try { [void]$root.GetFolder($name) } catch { [void]$root.CreateFolder($name) }
    } catch { }
}

function Get-PhpTaskName {
    param([int]$Port)
    return ('PHP ' + $Port)
}

function Write-WatchdogScript {
    param($Config)
    $ports = @()
    if ($Config.php.enabled) {
        for ($i = 0; $i -lt $Config.php.poolSize; $i++) { $ports += ($Config.php.basePort + $i) }
    }
    $portList = $(if ($ports.Count -gt 0) { '@(' + ($ports -join ',') + ')' } else { '@()' })
    $lines = @(
        '# Wird vom Caddy Manager erzeugt. Aenderungen gehen verloren.',
        '# Startet Caddy und die PHP-Prozesse neu, falls sie nicht laufen.',
        '$ErrorActionPreference = ''SilentlyContinue''',
        ('$taskPath = ''' + $TaskFolder + ''''),
        ('$phpPorts = ' + $portList),
        '',
        'function Test-PortOpen([int]$Port) {',
        '    try {',
        '        $c = New-Object System.Net.Sockets.TcpClient',
        '        $iar = $c.BeginConnect(''127.0.0.1'', $Port, $null, $null)',
        '        $ok = $iar.AsyncWaitHandle.WaitOne(700)',
        '        if ($ok) { try { $c.EndConnect($iar) } catch { $ok = $false } }',
        '        $c.Close()',
        '        return $ok',
        '    } catch { return $false }',
        '}',
        '',
        'function Start-ManagedTask([string]$Name) {',
        '    try {',
        '        $t = Get-ScheduledTask -TaskPath $taskPath -TaskName $Name -ErrorAction Stop',
        '        if ($t.State -ne ''Running'') { Start-ScheduledTask -TaskPath $taskPath -TaskName $Name }',
        '    } catch { }',
        '}',
        '',
        'if (-not (Get-Process -Name caddy -ErrorAction SilentlyContinue)) {',
        ('    Start-ManagedTask ''' + $TaskServer + ''''),
        '}',
        '',
        'foreach ($p in $phpPorts) {',
        '    if (-not (Test-PortOpen $p)) { Start-ManagedTask ("PHP " + $p) }',
        '}'
    )
    if (-not (Test-Path -LiteralPath $Paths.Manager)) { New-Item -ItemType Directory -Path $Paths.Manager -Force | Out-Null }
    Write-TextFile $Paths.Watchdog (($lines -join "`r`n") + "`r`n")
}

function Remove-LegacyTasks {
    try { Clear-TaskCache } catch { }
    $removed = New-Object System.Collections.ArrayList
    foreach ($n in $LegacyTaskNames) {
        try {
            $t = Get-ScheduledTask -TaskName $n -ErrorAction SilentlyContinue
            if ($t) {
                Stop-ScheduledTask -TaskName $n -ErrorAction SilentlyContinue
                Unregister-ScheduledTask -TaskName $n -Confirm:$false -ErrorAction Stop
                [void]$removed.Add($n)
            }
        } catch { }
    }
    if ($removed.Count -gt 0) { Write-Audit 'tasks.legacy.removed' ($removed -join ', ') }
    return ,@($removed.ToArray())
}

function Get-ManagedTasks {
    try { return ,@(Get-ScheduledTask -TaskPath $TaskFolder -ErrorAction SilentlyContinue) } catch { return ,@() }
}

function Remove-ManagedPhpTasks {
    param([int[]]$Keep = @())
    try { Clear-TaskCache } catch { }
    foreach ($t in (Get-ManagedTasks)) {
        if ($t.TaskName -notmatch '^PHP (\d+)$') { continue }
        $port = [int]$Matches[1]
        if ($Keep -contains $port) { continue }
        try {
            Stop-ScheduledTask -TaskPath $TaskFolder -TaskName $t.TaskName -ErrorAction SilentlyContinue
            Unregister-ScheduledTask -TaskPath $TaskFolder -TaskName $t.TaskName -Confirm:$false -ErrorAction SilentlyContinue
        } catch { }
    }
}

function Install-Automation {
    param($Config)
    $notes = New-Object System.Collections.ArrayList
    try {
        $legacy = Remove-LegacyTasks
        if ($legacy.Count -gt 0) {
            [void]$notes.Add('Alte Aufgaben entfernt: ' + ($legacy -join ', '))
        }

        $runAs = $Config.manager.runAs
        Confirm-TaskFolder
        Write-WatchdogScript $Config

        # Caddy-Dienstaufgabe
        Register-Task -Name $TaskServer -Execute $Paths.Exe `
            -Argument ('run --config "' + $Paths.Config + '" --adapter caddyfile') `
            -Triggers (New-StartupTrigger) -RunAs $runAs `
            -Description 'Startet den Caddy-Webserver beim Hochfahren.'
        [void]$notes.Add('Autostart f{ue}r Caddy eingerichtet')

        # PHP-Prozesse
        $keep = @()
        if ($Config.php.enabled -and (Test-Path -LiteralPath $Paths.PhpExe)) {
            for ($i = 0; $i -lt $Config.php.poolSize; $i++) {
                $port = $Config.php.basePort + $i
                $keep += $port
                Register-Task -Name (Get-PhpTaskName $port) -Execute $Paths.PhpExe `
                    -Argument ('-b 127.0.0.1:' + $port) `
                    -Triggers (New-StartupTrigger) -RunAs $runAs `
                    -Description 'PHP-FastCGI-Prozess f{ue}r Caddy.'
            }
            [void]$notes.Add("PHP-Pool mit $($Config.php.poolSize) Prozessen eingerichtet")
        }
        Remove-ManagedPhpTasks -Keep $keep

        # Watchdog
        $repeat = New-RepeatTrigger 3
        $wdTriggers = $(if ($repeat) { @((New-StartupTrigger), $repeat) } else { @(New-StartupTrigger) })
        Register-Task -Name $TaskWatch -Execute 'powershell.exe' `
            -Argument ('-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $Paths.Watchdog + '"') `
            -Triggers $wdTriggers -RunAs 'SYSTEM' `
            -Description 'Pr{ue}ft alle 3 Minuten, ob Caddy und PHP laufen.'
        $wd = Get-TaskState $TaskWatch
        if ($wd -and $wd.repeats) {
            [void]$notes.Add('Watchdog alle 3 Minuten eingerichtet')
        } else {
            [void]$notes.Add('Watchdog eingerichtet, aber ohne Wiederholungstakt - er greift nur beim Hochfahren')
        }

        Write-Audit 'tasks.install' ("runAs=$runAs php=$($Config.php.enabled)")
        return @{ ok = $true; message = 'Automatischer Betrieb eingerichtet.'; notes = @($notes.ToArray()) }
    } catch {
        return @{ ok = $false; message = "Aufgaben konnten nicht eingerichtet werden: $($_.Exception.Message)"; notes = @($notes.ToArray()) }
    }
}

function Uninstall-Automation {
    try { Clear-TaskCache } catch { }
    $removed = New-Object System.Collections.ArrayList
    foreach ($t in (Get-ManagedTasks)) {
        try {
            Stop-ScheduledTask -TaskPath $TaskFolder -TaskName $t.TaskName -ErrorAction SilentlyContinue
            Unregister-ScheduledTask -TaskPath $TaskFolder -TaskName $t.TaskName -Confirm:$false -ErrorAction Stop
            [void]$removed.Add($t.TaskName)
        } catch { }
    }
    foreach ($n in (Remove-LegacyTasks)) { [void]$removed.Add($n) }
    Write-Audit 'tasks.uninstall' ($removed -join ', ')
    return @{ ok = $true; message = "Automatik entfernt ($($removed.Count) Aufgaben). Dateien und Zertifikate bleiben erhalten."; removed = @($removed.ToArray()) }
}

# ---------------------------------------------------------------------------
#  Firewall
# ---------------------------------------------------------------------------
function Set-FirewallRules {
    $done = New-Object System.Collections.ArrayList
    foreach ($port in @(80, 443)) {
        $name = "Caddy HTTP $port"
        try {
            $existing = Get-NetFirewallRule -DisplayName $name -ErrorAction SilentlyContinue
            if ($existing) { [void]$done.Add("Regel '$name' war bereits vorhanden"); continue }
            New-NetFirewallRule -DisplayName $name -Direction Inbound -Protocol TCP `
                -LocalPort $port -Action Allow -Profile Any -ErrorAction Stop | Out-Null
            [void]$done.Add("Regel '$name' angelegt")
        } catch {
            $r = Invoke-Exe -FilePath "$env:SystemRoot\System32\netsh.exe" `
                    -Arguments @('advfirewall', 'firewall', 'add', 'rule', "name=$name", 'dir=in',
                                 'action=allow', 'protocol=TCP', "localport=$port") -TimeoutSec 30
            if ($r.ok) { [void]$done.Add("Regel '$name' angelegt (netsh)") }
            else { [void]$done.Add("Regel '$name' fehlgeschlagen") }
        }
    }
    # UDP 443 fuer HTTP/3
    try {
        if (-not (Get-NetFirewallRule -DisplayName 'Caddy HTTP/3 443 UDP' -ErrorAction SilentlyContinue)) {
            New-NetFirewallRule -DisplayName 'Caddy HTTP/3 443 UDP' -Direction Inbound -Protocol UDP `
                -LocalPort 443 -Action Allow -Profile Any -ErrorAction Stop | Out-Null
            [void]$done.Add("Regel 'Caddy HTTP/3 443 UDP' angelegt")
        }
    } catch { }
    Clear-StatusCache
    Write-Audit 'firewall.rules' ($done -join '; ')
    return @{ ok = $true; message = 'Firewallregeln gepr{ue}ft.'; notes = @($done.ToArray()) }
}

function Remove-FirewallRules {
    foreach ($name in @('Caddy HTTP 80', 'Caddy HTTP 443', 'Caddy HTTP/3 443 UDP')) {
        try { Remove-NetFirewallRule -DisplayName $name -ErrorAction SilentlyContinue } catch { }
    }
    return @{ ok = $true; message = 'Firewallregeln entfernt.' }
}

# ---------------------------------------------------------------------------
#  Dateirechte fuer den eingeschraenkten Betrieb
# ---------------------------------------------------------------------------
function Grant-ServiceRights {
    param([string]$RunAs)
    if ($RunAs -ne 'LOCAL SERVICE') { return @{ ok = $true; message = 'Keine zus{ae}tzlichen Rechte n{oe}tig (SYSTEM).' } }
    $sid = '*S-1-5-19'
    $notes = New-Object System.Collections.ArrayList
    $grants = @(
        @{ Path = $Paths.Root; Right = '(OI)(CI)(RX)' },
        @{ Path = $Paths.Data; Right = '(OI)(CI)(M)' },
        @{ Path = $Paths.Logs; Right = '(OI)(CI)(M)' },
        @{ Path = $Paths.Www;  Right = '(OI)(CI)(RX)' }
    )
    if (Test-Path -LiteralPath $Paths.Php) { $grants += @{ Path = $Paths.Php; Right = '(OI)(CI)(RX)' } }
    foreach ($g in $grants) {
        if (-not (Test-Path -LiteralPath $g.Path)) { continue }
        $r = Invoke-Exe -FilePath "$env:SystemRoot\System32\icacls.exe" `
                -Arguments @($g.Path, '/grant', ($sid + ':' + $g.Right), '/T', '/C', '/Q') -TimeoutSec 180
        [void]$notes.Add(($g.Path + ': ' + $(if ($r.ok) { 'ok' } else { 'fehlgeschlagen' })))
    }
    Write-Audit 'acl.grant' ($notes -join '; ')
    return @{ ok = $true; message = 'Dateirechte f{ue}r LOCAL SERVICE gesetzt.'; notes = @($notes.ToArray()) }
}

# ---------------------------------------------------------------------------
#  Dateirechte des Installationsverzeichnisses
#
#  Ein Ordner, der direkt unter C:\ angelegt wird, erbt von dort die Regel
#  "Authentifizierte Benutzer: Aendern". Damit duerfte jeder angemeldete
#  Benutzer ohne Administratorrechte caddy.exe oder manager\watchdog.ps1
#  austauschen - beide werden als SYSTEM ausgefuehrt. Das ist eine glatte
#  Rechteausweitung. Deshalb wird die Vererbung aufgeloest und alles ausser
#  Administratoren und SYSTEM entfernt.
# ---------------------------------------------------------------------------

# Everyone, Authentifizierte Benutzer, Benutzer, Interaktiv, Gaeste, Jeder-Netz
$WeakSids = @('S-1-1-0', 'S-1-5-11', 'S-1-5-32-545', 'S-1-5-4', 'S-1-5-32-546', 'S-1-5-7')

# Schreiben, Anhaengen, Loeschen, Rechte aendern, Besitz uebernehmen
$DangerousRights = 0x2 -bor 0x4 -bor 0x10000 -bor 0x40000 -bor 0x80000

function Get-AceSid {
    param($Ace)
    try {
        return $Ace.IdentityReference.Translate([System.Security.Principal.SecurityIdentifier]).Value
    } catch {
        try { return ([string]$Ace.IdentityReference) } catch { return '' }
    }
}

# Liefert die Liste der Konten, die dort schreiben duerfen, obwohl sie es nicht
# sollten. Leere Liste heisst: sauber.
function Get-WeakDirectoryAccess {
    param([string]$Path)
    $weak = New-Object System.Collections.ArrayList
    if (-not (Test-Path -LiteralPath $Path)) { return ,@() }
    try {
        $acl = Get-Acl -LiteralPath $Path
        foreach ($ace in $acl.Access) {
            if ($ace.AccessControlType -ne 'Allow') { continue }
            $sid = Get-AceSid $ace
            if ($WeakSids -notcontains $sid) { continue }
            if (([int]$ace.FileSystemRights -band $DangerousRights) -eq 0) { continue }
            $name = ''
            try { $name = [string]$ace.IdentityReference } catch { $name = $sid }
            if (-not $weak.Contains($name)) { [void]$weak.Add($name) }
        }
    } catch { }
    return ,@($weak.ToArray())
}

function Invoke-Icacls {
    # Grosszuegig bemessen: bei vielen tausend Dateien laeuft icacls mit /T lange,
    # und ein Abbruch mittendrin liesse den Baum halb umgestellt zurueck.
    param([string[]]$Arguments, [int]$TimeoutSec = 1800)
    return (Invoke-Exe -FilePath "$env:SystemRoot\System32\icacls.exe" -Arguments $Arguments -TimeoutSec $TimeoutSec)
}

function Protect-InstallDirectory {
    param([string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) {
        return @{ ok = $true; message = "$Path gibt es nicht - nichts zu tun."; changed = $false }
    }
    $before = Get-WeakDirectoryAccess $Path
    try {
        # 1. Geerbte Regeln in eigene umwandeln. Danach haengt nichts mehr an C:\
        #    und es kann bei keinem Schritt eine leere Rechteliste entstehen.
        Invoke-Icacls @($Path, '/inheritance:d', '/T', '/C', '/Q') | Out-Null
        # 2. Die zu weit gefassten Gruppen im ganzen Baum entfernen
        $rm = @($Path, '/remove:g')
        foreach ($s in $WeakSids) { $rm += ('*' + $s) }
        $rm += @('/T', '/C', '/Q')
        Invoke-Icacls $rm | Out-Null
        # 3. Administratoren und SYSTEM sicherstellen, vererbbar fuer alles Neue
        Invoke-Icacls @($Path, '/grant', '*S-1-5-32-544:(OI)(CI)(F)', '*S-1-5-18:(OI)(CI)(F)', '/C', '/Q') | Out-Null
    } catch {
        return @{ ok = $false; message = "Dateirechte konnten nicht gesetzt werden: $($_.Exception.Message)" }
    }
    $after = Get-WeakDirectoryAccess $Path
    if ($after.Count -gt 0) {
        return @{ ok = $false
                  message = ("In $Path duerfen weiterhin schreiben: " + ($after -join ', ') +
                             '. Bitte die Rechte im Explorer pruefen.') }
    }
    Write-Audit 'acl.protect' ("$Path - vorher offen fuer: " + $(if ($before.Count) { $before -join ', ' } else { 'niemanden' }))
    $verb = $(if ($before.Count -gt 0) { 'Schreibrecht entzogen: ' + ($before -join ', ') } else { 'war bereits abgesichert' })
    return @{ ok = $true; message = "$Path abgesichert ($verb)."; changed = ($before.Count -gt 0) }
}

function Protect-AllInstallDirectories {
    $notes = New-Object System.Collections.ArrayList
    $ok = $true
    foreach ($p in @($Paths.Root, $Paths.Php)) {
        if (-not (Test-Path -LiteralPath $p)) { continue }
        $r = Protect-InstallDirectory $p
        [void]$notes.Add($r.message)
        if (-not $r.ok) { $ok = $false }
    }
    # Das Dienstkonto braucht danach wieder Leserechte
    if ($script:Config -and $script:Config.manager.runAs -eq 'LOCAL SERVICE') {
        $g = Grant-ServiceRights 'LOCAL SERVICE'
        [void]$notes.Add($g.message)
    }
    return @{ ok = $ok
              message = $(if ($ok) { 'Dateirechte abgesichert.' } else { 'Dateirechte nur teilweise abgesichert.' })
              notes = @($notes.ToArray()) }
}

# Erlaubt einem zusaetzlichen Konto das Bearbeiten der Webseiten-Dateien, ohne
# dass es Administrator sein muss. Der Name wird zu einer SID aufgeloest und nur
# diese landet im Aufruf - der Eingabetext selbst nie.
function Grant-WwwAccess {
    param([string]$Account)
    $a = Get-SafeString $Account 104
    if ($a -notmatch '^[A-Za-z0-9 ._\-]+(\\[A-Za-z0-9 ._\-]+)?$') {
        return @{ ok = $false; message = 'Der Kontoname enthaelt unzulaessige Zeichen.' }
    }
    $sid = ''
    try {
        $nt = New-Object System.Security.Principal.NTAccount($a)
        $sid = $nt.Translate([System.Security.Principal.SecurityIdentifier]).Value
    } catch {
        return @{ ok = $false; message = "Das Konto '$a' gibt es auf diesem Rechner nicht." }
    }
    if ($WeakSids -contains $sid) {
        return @{ ok = $false; message = 'Das waere eine Gruppe, die jeden einschliesst. Bitte ein einzelnes Konto angeben.' }
    }
    if (-not (Test-Path -LiteralPath $Paths.Www)) {
        return @{ ok = $false; message = 'Das Webseiten-Verzeichnis gibt es noch nicht.' }
    }
    $r = Invoke-Icacls @($Paths.Www, '/grant', ('*' + $sid + ':(OI)(CI)(M)'), '/T', '/C', '/Q')
    if (-not $r.ok) {
        return @{ ok = $false; message = ('Die Rechte liessen sich nicht setzen: ' + (Get-ExeOutput $r)) }
    }
    Write-Audit 'acl.grant.www' "$a ($sid)"
    return @{ ok = $true; message = "'$a' darf jetzt die Dateien unter $($Paths.Www) bearbeiten." }
}

# ===========================================================================
#  BETRIEB: Status, Steuerung, Anwenden, Sicherheitspruefung
# ===========================================================================

function Test-PortOpen {
    param([string]$Address = '127.0.0.1', [int]$Port, [int]$TimeoutMs = 600)
    try {
        $c = New-Object System.Net.Sockets.TcpClient
        $iar = $c.BeginConnect($Address, $Port, $null, $null)
        $ok = $iar.AsyncWaitHandle.WaitOne($TimeoutMs)
        if ($ok) { try { $c.EndConnect($iar) } catch { $ok = $false } }
        $c.Close()
        return [bool]$ok
    } catch { return $false }
}

function Get-PortOwner {
    param([int]$Port)
    try {
        $conn = @(Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue)
        if ($conn.Count -eq 0) { return $null }
        $pid2 = $conn[0].OwningProcess
        $p = Get-Process -Id $pid2 -ErrorAction SilentlyContinue
        if ($p) { return @{ pid = $pid2; name = $p.ProcessName } }
        return @{ pid = $pid2; name = 'unbekannt' }
    } catch { return $null }
}

function Get-CaddyProcess {
    return (Get-Process -Name caddy -ErrorAction SilentlyContinue | Select-Object -First 1)
}

function Get-PhpProcesses {
    return ,@(Get-Process -Name 'php-cgi' -ErrorAction SilentlyContinue)
}

function Get-TaskState {
    param([string]$Name)
    try {
        $t = Get-ScheduledTask -TaskPath $TaskFolder -TaskName $Name -ErrorAction SilentlyContinue
        if (-not $t) { return $null }
        $repeats = $false
        try {
            foreach ($tr in @($t.Triggers)) {
                if ($tr.Repetition -and $tr.Repetition.Interval) { $repeats = $true }
            }
        } catch { }
        return @{ exists = $true; state = [string]$t.State; enabled = ($t.State -ne 'Disabled'); repeats = $repeats }
    } catch { return $null }
}

function Start-CaddyServer {
    Clear-StatusCache
    if (-not (Test-Path -LiteralPath $Paths.Exe)) { return @{ ok = $false; message = 'Caddy ist noch nicht installiert.' } }
    $t = Get-TaskState $TaskServer
    if ($t) {
        # Ueber die geplante Aufgabe starten, damit Caddy unter dem
        # vorgesehenen Dienstkonto laeuft - niemals ersatzweise unter dem
        # angemeldeten Administrator, das waere ein stiller Rechteausbruch.
        try { Start-ScheduledTask -TaskPath $TaskFolder -TaskName $TaskServer -ErrorAction Stop } catch {
            return @{ ok = $false; message = "Die Aufgabe liess sich nicht starten: $($_.Exception.Message)" }
        }
        for ($i = 0; $i -lt 20; $i++) {
            Start-Sleep -Milliseconds 400
            if (Get-CaddyProcess) { return @{ ok = $true; message = 'Caddy gestartet.' } }
        }
        return @{ ok = $false
                  message = 'Die Aufgabe wurde gestartet, Caddy laeuft aber nicht. Bitte das Protokoll pruefen.' }
    }
    try {
        Start-Process -FilePath $Paths.Exe `
            -ArgumentList @('run', '--config', ('"' + $Paths.Config + '"'), '--adapter', 'caddyfile') `
            -WindowStyle Hidden
        Start-Sleep -Milliseconds 1500
        if (Get-CaddyProcess) { return @{ ok = $true; message = 'Caddy gestartet.' } }
        return @{ ok = $false; message = 'Caddy wurde gestartet, l{ae}uft aber nicht. Bitte Protokoll pr{ue}fen.' }
    } catch {
        return @{ ok = $false; message = "Start fehlgeschlagen: $($_.Exception.Message)" }
    }
}

function Stop-CaddyServer {
    Clear-StatusCache
    if (Test-Path -LiteralPath $Paths.Exe) {
        Invoke-Caddy @('stop') 30 | Out-Null
        Start-Sleep -Milliseconds 600
    }
    if (Get-CaddyProcess) {
        try { Stop-ScheduledTask -TaskPath $TaskFolder -TaskName $TaskServer -ErrorAction SilentlyContinue } catch { }
        Start-Sleep -Milliseconds 600
    }
    $p = Get-CaddyProcess
    if ($p) {
        try { Stop-Process -Id $p.Id -Force -ErrorAction Stop; Start-Sleep -Milliseconds 400 } catch { }
    }
    if (Get-CaddyProcess) { return @{ ok = $false; message = 'Caddy l{ae}uft weiterhin.' } }
    return @{ ok = $true; message = 'Caddy gestoppt.' }
}

function Restart-CaddyServer {
    Stop-CaddyServer | Out-Null
    Start-Sleep -Milliseconds 500
    return (Start-CaddyServer)
}

function Start-PhpPool {
    param($Config)
    if (-not $Config.php.enabled) { return @{ ok = $true; message = 'PHP ist nicht aktiviert.' } }
    if (-not (Test-Path -LiteralPath $Paths.PhpExe)) { return @{ ok = $false; message = 'PHP ist nicht installiert.' } }
    $started = 0
    for ($i = 0; $i -lt $Config.php.poolSize; $i++) {
        $port = $Config.php.basePort + $i
        if (Test-PortOpen -Port $port) { continue }
        $name = Get-PhpTaskName $port
        if (Get-TaskState $name) {
            try { Start-ScheduledTask -TaskPath $TaskFolder -TaskName $name -ErrorAction Stop; $started++; continue } catch { }
        }
        try {
            Start-Process -FilePath $Paths.PhpExe -ArgumentList @('-b', ('127.0.0.1:' + $port)) -WindowStyle Hidden
            $started++
        } catch { }
    }
    Start-Sleep -Milliseconds 900
    return @{ ok = $true; message = "PHP-Pool gepr{ue}ft ($started gestartet)." }
}

function Stop-PhpPool {
    $n = 0
    foreach ($t in (Get-ManagedTasks)) {
        if ($t.TaskName -match '^PHP \d+$') {
            try { Stop-ScheduledTask -TaskPath $TaskFolder -TaskName $t.TaskName -ErrorAction SilentlyContinue } catch { }
        }
    }
    foreach ($p in (Get-PhpProcesses)) {
        try { Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue; $n++ } catch { }
    }
    Start-Sleep -Milliseconds 400
    return @{ ok = $true; message = "PHP gestoppt ($n Prozesse)." }
}

# ---------------------------------------------------------------------------
#  Konfiguration anwenden
# ---------------------------------------------------------------------------
# Caddy protokolliert als JSON. Fuer die Anzeige wird daraus lesbarer Text;
# sobald es Fehler gibt, werden die reinen Infozeilen weggelassen.
function Format-CaddyOutput {
    param([string]$Text)
    if ([string]::IsNullOrWhiteSpace($Text)) { return '' }
    $plain = New-Object System.Collections.ArrayList
    $notes = New-Object System.Collections.ArrayList
    $hasProblem = $false
    foreach ($line in ($Text -replace "`r`n", "`n").Split("`n")) {
        $t = $line.Trim()
        if (-not $t) { continue }
        if ($t.StartsWith('{')) {
            $obj = $null
            try { $obj = ConvertFrom-Json $t } catch { }
            if ($obj) {
                $lvl = ''
                try { $lvl = [string]$obj.level } catch { }
                $msg = ''
                try { $msg = [string]$obj.msg } catch { }
                $err = ''
                try { if ($obj.PSObject.Properties['error']) { $err = [string]$obj.error } } catch { }
                $text = $msg
                if ($err) { $text = $text + ': ' + $err }
                if (-not $text) { continue }
                if ($lvl -eq 'error' -or $lvl -eq 'warn') {
                    $hasProblem = $true
                    [void]$plain.Add(($lvl.ToUpper() + ': ' + $text))
                } else {
                    [void]$notes.Add($text)
                }
                continue
            }
        }
        # Klartextzeilen von caddy sind fast immer die eigentliche Fehlermeldung
        if ($t -match '(?i)^error') { $hasProblem = $true; [void]$plain.Add($t) }
        else { [void]$plain.Add($t) }
    }
    if ($hasProblem) { return (($plain.ToArray()) -join "`n") }
    $all = @($plain.ToArray()) + @($notes.ToArray())
    return (($all | Select-Object -Last 12) -join "`n")
}

function Test-CaddyConfigFile {
    param([string]$Path)
    if (-not (Test-Path -LiteralPath $Paths.Exe)) {
        return @{ ok = $true; skipped = $true; output = 'Caddy ist nicht installiert - die Pr{ue}fung wurde {ue}bersprungen.' }
    }
    $r = Invoke-Caddy @('validate', '--config', $Path, '--adapter', 'caddyfile') 90
    # caddy schreibt auch bei Erfolg auf stderr
    $out = Format-CaddyOutput (Get-ExeOutput $r)
    # Der Pfad der Zwischendatei verwirrt nur - er zeigt auf nichts Bleibendes
    $out = $out.Replace($Paths.Staging, 'Caddyfile').Replace((ConvertTo-CaddyPath $Paths.Staging), 'Caddyfile')
    return @{ ok = $r.ok; skipped = $false; output = $out }
}

function Write-CaddyfileAndReload {
    param([string]$NewText, [string]$Reason = 'apply')
    $result = [ordered]@{ ok = $false; message = ''; validation = ''; reloaded = $false; backup = '' }

    if (-not (Test-Path -LiteralPath $Paths.Root)) { New-Item -ItemType Directory -Path $Paths.Root -Force | Out-Null }
    if (-not (Test-Path -LiteralPath $Paths.Manager)) { New-Item -ItemType Directory -Path $Paths.Manager -Force | Out-Null }
    if (-not (Test-Path -LiteralPath $Paths.Logs)) { New-Item -ItemType Directory -Path $Paths.Logs -Force | Out-Null }

    # 1. In eine Zwischendatei schreiben und pruefen
    $NewText = ConvertTo-FileText $NewText
    Write-TextFile $Paths.Staging $NewText
    $check = Test-CaddyConfigFile $Paths.Staging
    $result.validation = $check.output
    if (-not $check.ok) {
        $result.message = 'Die Konfiguration ist fehlerhaft und wurde NICHT {ue}bernommen.'
        Write-Audit "config.$Reason.invalid" ($check.output -replace "`n", ' | ') 'warn'
        return $result
    }

    # 2. Sicherung der laufenden Datei
    $backup = Backup-File -Path $Paths.Config -Prefix 'caddyfile'
    if ($backup) { $result.backup = Split-Path -Leaf $backup }

    # 3. Uebernehmen
    try {
        Clear-ReadOnly $Paths.Config
        Copy-Item -LiteralPath $Paths.Staging -Destination $Paths.Config -Force
    } catch {
        $result.message = "Datei konnte nicht geschrieben werden: $($_.Exception.Message)"
        return $result
    }

    # 4. Neu laden, falls Caddy laeuft
    if (Get-CaddyProcess) {
        $r = Invoke-Caddy @('reload', '--config', $Paths.Config, '--adapter', 'caddyfile') 90
        if ($r.ok) {
            $result.ok = $true
            $result.reloaded = $true
            $result.message = 'Konfiguration aktiv - Caddy hat ohne Unterbrechung neu geladen.'
        } else {
            $out = Format-CaddyOutput (Get-ExeOutput $r)
            $result.validation = $out
            # Rueckfall auf die Sicherung
            if ($backup -and (Test-Path -LiteralPath $backup)) {
                Copy-Item -LiteralPath $backup -Destination $Paths.Config -Force
                Invoke-Caddy @('reload', '--config', $Paths.Config, '--adapter', 'caddyfile') 90 | Out-Null
                $result.message = 'Caddy hat die neue Konfiguration abgelehnt. Der vorherige Stand wurde wiederhergestellt.'
            } else {
                $result.message = 'Caddy hat die neue Konfiguration abgelehnt.'
            }
            Write-Audit "config.$Reason.reload.failed" ($out -replace "`n", ' | ') 'error'
            return $result
        }
    } else {
        $result.ok = $true
        $result.message = 'Konfiguration gespeichert. Caddy l{ae}uft gerade nicht - beim n{ae}chsten Start wird sie verwendet.'
    }

    Remove-Item -LiteralPath $Paths.Staging -Force -ErrorAction SilentlyContinue
    Write-Audit "config.$Reason.applied" ("backup=" + $result.backup)
    return $result
}

function Get-LiveCaddyfile {
    return (Read-TextFileSmart $Paths.Config)
}

function Test-ConfigDirty {
    param($Config)
    if ($Config.mode -eq 'manual') { return $false }
    $gen = Build-Caddyfile $Config
    $live = Get-LiveCaddyfile
    # Kopfzeile mit Zeitstempel beim Vergleich ausblenden
    # Kommentare, Leerzeilen und Einzuege bleiben beim Vergleich aussen vor
    $strip = {
        param($t)
        $keep = @(($t -replace "`r`n", "`n").Split("`n") |
                  ForEach-Object { $_.Trim() } |
                  Where-Object { $_ -and $_ -notmatch '^#' })
        return ($keep -join "`n")
    }
    return ((& $strip $gen) -ne (& $strip $live))
}

# ---------------------------------------------------------------------------
#  Zertifikate
# ---------------------------------------------------------------------------
# ---------------------------------------------------------------------------
#  Zwischenspeicher
#
#  Get-ScheduledTask, Get-NetFirewallRule und Get-NetTCPConnection gehen ueber
#  CIM und brauchen unter Windows jeweils hunderte Millisekunden bis Sekunden.
#  Ohne Zwischenspeicher kostet ein Statusabruf mehrere Sekunden und laesst die
#  Maschine dauernd arbeiten. Alles hier drin aendert sich nur, wenn der Manager
#  selbst etwas aendert - dann wird der Speicher verworfen.
# ---------------------------------------------------------------------------
$script:AclCache   = $null;  $script:AclCacheAt   = [datetime]::MinValue
$CacheSecAcl = 300
$script:CertCache  = $null;  $script:CertCacheAt  = [datetime]::MinValue
$script:FwCache    = $null;  $script:FwCacheAt    = [datetime]::MinValue
$script:LegacyCache = $null; $script:LegacyCacheAt = [datetime]::MinValue
$script:PortCache  = @{}
$script:TaskCache  = @{}

$CacheSecCert   = 300
$CacheSecFw     = 300
$CacheSecLegacy = 300
$CacheSecPort   = 60
$CacheSecTask   = 120

function Clear-StatusCache {
    $script:AclCache = $null;    $script:AclCacheAt = [datetime]::MinValue
    $script:CertCache = $null;   $script:CertCacheAt = [datetime]::MinValue
    $script:FwCache = $null;     $script:FwCacheAt = [datetime]::MinValue
    $script:LegacyCache = $null; $script:LegacyCacheAt = [datetime]::MinValue
    $script:PortCache = @{}
    $script:TaskCache = @{}
}

function Clear-TaskCache {
    $script:TaskCache = @{}
    $script:LegacyCache = $null
    $script:LegacyCacheAt = [datetime]::MinValue
}

function Get-CachedTaskState {
    param([string]$Name)
    if ($script:TaskCache.ContainsKey($Name)) {
        $e = $script:TaskCache[$Name]
        if (((Get-Date) - $e.at).TotalSeconds -lt $CacheSecTask) { return $e.value }
    }
    $v = Get-TaskState $Name
    $script:TaskCache[$Name] = @{ at = (Get-Date); value = $v }
    return $v
}

function Get-CachedPortOwner {
    param([int]$Port)
    $key = [string]$Port
    if ($script:PortCache.ContainsKey($key)) {
        $e = $script:PortCache[$key]
        if (((Get-Date) - $e.at).TotalSeconds -lt $CacheSecPort) { return $e.value }
    }
    $v = Get-PortOwner $Port
    $script:PortCache[$key] = @{ at = (Get-Date); value = $v }
    return $v
}

function Get-CachedFirewallCount {
    if ($null -ne $script:FwCache -and ((Get-Date) - $script:FwCacheAt).TotalSeconds -lt $CacheSecFw) {
        return $script:FwCache
    }
    $n = 0
    try { $n = @(Get-NetFirewallRule -DisplayName 'Caddy HTTP*' -ErrorAction SilentlyContinue).Count } catch { $n = -1 }
    $script:FwCache = $n
    $script:FwCacheAt = Get-Date
    return $n
}

function Get-CachedLegacyTasks {
    if ($null -ne $script:LegacyCache -and ((Get-Date) - $script:LegacyCacheAt).TotalSeconds -lt $CacheSecLegacy) {
        return ,$script:LegacyCache
    }
    $script:LegacyCache = Get-LegacyTaskNames
    $script:LegacyCacheAt = Get-Date
    return ,$script:LegacyCache
}

# Wer darf in den Programmverzeichnissen schreiben, obwohl er es nicht sollte?
function Get-CachedWeakAcl {
    if ($null -ne $script:AclCache -and ((Get-Date) - $script:AclCacheAt).TotalSeconds -lt $CacheSecAcl) {
        return ,$script:AclCache
    }
    $weak = New-Object System.Collections.ArrayList
    foreach ($p in @($Paths.Root, $Paths.Php)) {
        if (-not (Test-Path -LiteralPath $p)) { continue }
        foreach ($w in (Get-WeakDirectoryAccess $p)) {
            $e = "$w ($p)"
            if (-not $weak.Contains($e)) { [void]$weak.Add($e) }
        }
    }
    $script:AclCache = @($weak.ToArray())
    $script:AclCacheAt = Get-Date
    return ,$script:AclCache
}

function Get-CachedCertificates {
    if ($null -ne $script:CertCache -and ((Get-Date) - $script:CertCacheAt).TotalSeconds -lt $CacheSecCert) {
        return ,$script:CertCache
    }
    $script:CertCache = Get-Certificates
    $script:CertCacheAt = Get-Date
    return ,$script:CertCache
}

# Bei "storage file_system <pfad>" legt Caddy die Zertifikate unter
# <pfad>/certificates/<aussteller>/<domain>/ ab.
function Get-CertificateRoots {
    $roots = New-Object System.Collections.ArrayList
    try {
        $base = $Paths.Data
        try {
            if ($script:Config -and $script:Config.global.storage) { $base = $script:Config.global.storage }
        } catch { }
        foreach ($c in @(($base + '\certificates'), ($base + '\caddy\certificates'),
                         ($Paths.Data + '\certificates'))) {
            if ($roots.Contains($c)) { continue }
            if (Test-Path -LiteralPath $c) { [void]$roots.Add($c) }
        }
        if ($roots.Count -eq 0) {
            foreach ($d in (Get-DefaultStorageDirs)) {
                $c = $d.TrimEnd('\') + '\certificates'
                if (Test-Path -LiteralPath $c) { [void]$roots.Add($c) }
            }
        }
    } catch { }
    return ,@($roots.ToArray())
}

function Get-Certificates {
    $list = New-Object System.Collections.ArrayList
    $roots = Get-CertificateRoots
    if ($roots.Count -eq 0) { return ,@() }
    try {
        $files = @(foreach ($r in $roots) {
                       Get-ChildItem -LiteralPath $r -Filter '*.crt' -Recurse -ErrorAction SilentlyContinue
                   }) | Select-Object -First 200
        foreach ($f in $files) {
            try {
                $c = New-Object System.Security.Cryptography.X509Certificates.X509Certificate2($f.FullName)
                $days = [int]([Math]::Floor(($c.NotAfter - (Get-Date)).TotalDays))
                [void]$list.Add(@{
                    domain    = [System.IO.Path]::GetFileNameWithoutExtension($f.Name)
                    notAfter  = $c.NotAfter.ToString('yyyy-MM-dd')
                    daysLeft  = $days
                    issuer    = ($c.Issuer -replace '.*?CN=([^,]+).*', '$1')
                })
                $c.Dispose()
            } catch { }
        }
    } catch { }
    return ,@($list.ToArray() | Sort-Object { $_.daysLeft })
}

# ---------------------------------------------------------------------------
#  Gesamtstatus
# ---------------------------------------------------------------------------
# Prueft die aktive Caddyfile - aber nur neu, wenn sich die Datei geaendert hat.
$script:CfgCheck = $null
$script:CfgCheckKey = ''

function Get-CachedConfigCheck {
    $key = ''
    try {
        $fi = Get-Item -LiteralPath $Paths.Config -ErrorAction SilentlyContinue
        if ($fi) { $key = [string]$fi.LastWriteTimeUtc.Ticks + '-' + [string]$fi.Length }
    } catch { }
    if (-not $key) { return @{ broken = $false; error = '' } }
    if ($script:CfgCheckKey -eq $key -and $null -ne $script:CfgCheck) { return $script:CfgCheck }
    $r = Test-CaddyConfigFile $Paths.Config
    $res = @{ broken = (-not $r.ok); error = $(if ($r.ok) { '' } else { (Get-SafeString $r.output 300) }) }
    $script:CfgCheck = $res
    $script:CfgCheckKey = $key
    return $res
}

function Get-Status {
    param($Config)
    $caddy = Get-CaddyProcess
    $ver = Get-CaddyVersionInfo
    $php = Get-PhpProcesses
    $phpPorts = New-Object System.Collections.ArrayList
    if ($Config.php.enabled) {
        for ($i = 0; $i -lt $Config.php.poolSize; $i++) {
            $p = $Config.php.basePort + $i
            [void]$phpPorts.Add(@{ port = $p; open = (Test-PortOpen -Port $p -TimeoutMs 300) })
        }
    }

    $uptime = ''
    if ($caddy) {
        try {
            $ts = (Get-Date) - $caddy.StartTime
            $uptime = '{0}d {1}h {2}m' -f [int]$ts.TotalDays, $ts.Hours, $ts.Minutes
        } catch { }
    }

    $cfgChk = $(if ($ver.installed) { Get-CachedConfigCheck } else { @{ broken = $false; error = '' } })
    $owner80 = Get-CachedPortOwner 80
    $owner443 = Get-CachedPortOwner 443

    $disk = $null
    try {
        $drive = (Split-Path -Qualifier $Paths.Root)
        $d = Get-PSDrive -Name $drive.TrimEnd(':') -ErrorAction SilentlyContinue
        if ($d) {
            $disk = @{
                freeGb  = [Math]::Round($d.Free / 1GB, 1)
                totalGb = [Math]::Round(($d.Free + $d.Used) / 1GB, 1)
            }
        }
    } catch { }

    return [ordered]@{
        caddyInstalled = $ver.installed
        caddyVersion   = $ver.version
        caddyRunning   = [bool]$caddy
        caddyPid       = $(if ($caddy) { $caddy.Id } else { 0 })
        caddyPaths     = (Get-CaddyProcessPaths)
        caddyUptime    = $uptime
        phpInstalled   = (Test-Path -LiteralPath $Paths.PhpExe)
        phpEnabled     = [bool]$Config.php.enabled
        phpRunning     = $php.Count
        phpPorts       = @($phpPorts.ToArray())
        taskServer     = (Get-CachedTaskState $TaskServer)
        taskWatchdog   = (Get-CachedTaskState $TaskWatch)
        legacyTasks    = (Get-CachedLegacyTasks)
        firewallRules  = (Get-CachedFirewallCount)
        aclWeak        = (Get-CachedWeakAcl)
        aclOk          = ((Get-CachedWeakAcl).Count -eq 0)
        port80         = $owner80
        port443        = $owner443
        certificates   = (Get-CachedCertificates)
        siteCount      = @($Config.sites).Count
        siteActive     = @($Config.sites | Where-Object { $_.enabled }).Count
        dirty          = ($ver.installed -and (Test-ConfigDirty $Config))
        configBroken   = $cfgChk.broken
        configError    = $cfgChk.error
        mode           = $Config.mode
        runAs          = $Config.manager.runAs
        disk           = $disk
        root           = $Paths.Root
        configPath     = $Paths.Config
    }
}

function Get-LegacyTaskNames {
    $found = New-Object System.Collections.ArrayList
    foreach ($n in $LegacyTaskNames) {
        try {
            if (Get-ScheduledTask -TaskName $n -ErrorAction SilentlyContinue) { [void]$found.Add($n) }
        } catch { }
    }
    return ,@($found.ToArray())
}

# ---------------------------------------------------------------------------
#  Netzwerkpruefungen (DNS / oeffentliche Adresse)
# ---------------------------------------------------------------------------
function Get-PublicIp {
    foreach ($u in @('https://api.ipify.org', 'https://ifconfig.me/ip', 'https://icanhazip.com')) {
        try {
            $r = Invoke-WebRequest -Uri $u -UseBasicParsing -TimeoutSec 8
            $ip = ([string]$r.Content).Trim()
            if ($ip -match '^\d{1,3}(\.\d{1,3}){3}$') { return $ip }
        } catch { }
    }
    return ''
}

function Resolve-DomainAddresses {
    param([string]$Name)
    $out = New-Object System.Collections.ArrayList
    try {
        $rec = @(Resolve-DnsName -Name $Name -Type A_AAAA -DnsOnly -ErrorAction Stop)
        foreach ($r in $rec) {
            if ($r.PSObject.Properties['IPAddress'] -and $r.IPAddress) { [void]$out.Add([string]$r.IPAddress) }
        }
    } catch {
        try {
            foreach ($a in [System.Net.Dns]::GetHostAddresses($Name)) { [void]$out.Add($a.IPAddressToString) }
        } catch { }
    }
    return ,@($out.ToArray() | Select-Object -Unique)
}

function Test-DomainPointsHere {
    param([string[]]$Domains)
    $publicIp = Get-PublicIp
    $localIps = New-Object System.Collections.ArrayList
    try {
        foreach ($a in [System.Net.Dns]::GetHostAddresses([System.Net.Dns]::GetHostName())) {
            [void]$localIps.Add($a.IPAddressToString)
        }
    } catch { }
    if ($publicIp) { [void]$localIps.Add($publicIp) }

    $results = New-Object System.Collections.ArrayList
    foreach ($d in $Domains) {
        $host2 = ($d -replace '^https?://', '')
        $host2 = ($host2 -split ':')[0]
        if ($host2 -match '\*' -or $host2 -eq 'localhost') {
            [void]$results.Add(@{ domain = $d; status = 'skip'; addresses = @(); message = 'Wird nicht gepr{ue}ft.' })
            continue
        }
        $addr = Resolve-DomainAddresses $host2
        if ($addr.Count -eq 0) {
            [void]$results.Add(@{ domain = $d; status = 'bad'; addresses = @(); message = 'Kein DNS-Eintrag gefunden.' })
            continue
        }
        $match = $false
        foreach ($a in $addr) { if ($localIps -contains $a) { $match = $true } }
        if ($match) {
            [void]$results.Add(@{ domain = $d; status = 'ok'; addresses = @($addr); message = 'Zeigt auf diesen Server.' })
        } else {
            [void]$results.Add(@{ domain = $d; status = 'warn'; addresses = @($addr)
                                  message = 'Zeigt auf eine andere Adresse. Ein Zertifikat kann so nicht ausgestellt werden.' })
        }
    }
    return @{ publicIp = $publicIp; results = @($results.ToArray()) }
}

# ---------------------------------------------------------------------------
#  Sicherheitspruefung
# ---------------------------------------------------------------------------
function New-Finding {
    param([string]$Level, [string]$Title, [string]$Detail, [string]$Fix = '', [string]$FixLabel = '')
    return @{ level = $Level; title = $Title; detail = $Detail; fix = $Fix; fixLabel = $FixLabel }
}

function Get-SecurityFindings {
    param($Config, $Status)
    $f = New-Object System.Collections.ArrayList

    if (-not $Status.caddyInstalled) {
        [void]$f.Add((New-Finding 'bad' 'Caddy ist nicht installiert' 'Ohne Caddy l{ae}uft kein Webserver.' 'setup-all' 'Jetzt einrichten'))
    } elseif (-not $Status.caddyRunning) {
        [void]$f.Add((New-Finding 'bad' 'Caddy l{ae}uft nicht' 'Aktuell werden keine Seiten ausgeliefert.' 'start' 'Caddy starten'))
    } else {
        [void]$f.Add((New-Finding 'ok' 'Caddy l{ae}uft' "Version $($Status.caddyVersion), seit $($Status.caddyUptime)."))
    }

    if (-not $Status.taskServer) {
        [void]$f.Add((New-Finding 'bad' 'Kein Autostart eingerichtet' 'Nach einem Neustart des Servers w{ae}ren alle Seiten offline.' 'setup-tasks' 'Autostart einrichten'))
    } else {
        [void]$f.Add((New-Finding 'ok' 'Autostart ist eingerichtet' 'Caddy startet automatisch beim Hochfahren.'))
    }

    if (-not $Status.taskWatchdog) {
        [void]$f.Add((New-Finding 'warn' 'Kein Watchdog eingerichtet' 'Nach einem Absturz w{ue}rde Caddy nicht von allein zur{ue}ckkommen.' 'setup-tasks' 'Watchdog einrichten'))
    } elseif (-not $Status.taskWatchdog.repeats) {
        [void]$f.Add((New-Finding 'warn' 'Watchdog l{ae}uft nur beim Hochfahren' `
            ('Der Wiederholungstakt konnte auf diesem Windows nicht gesetzt werden. Nach einem Absturz ' +
             'im laufenden Betrieb kommt Caddy erst beim n{ae}chsten Neustart zur{ue}ck.') `
            'setup-tasks' 'Erneut versuchen'))
    } else {
        [void]$f.Add((New-Finding 'ok' 'Watchdog aktiv' 'Pr{ue}ft alle 3 Minuten, ob alles l{ae}uft.'))
    }

    # --- Was sonst noch auf dieser Maschine mitspielt ---
    $paths = @($Status.caddyPaths)
    if ($paths.Count -gt 1) {
        [void]$f.Add((New-Finding 'bad' 'Mehrere Caddy-Prozesse laufen gleichzeitig' `
            ('Sie streiten sich um Port 80 und 443. Laufende Programmdateien: ' + ($paths -join ' | '))))
    } elseif ($paths.Count -eq 1 -and $paths[0] -ne $Paths.Exe -and $paths[0] -ne '(Pfad nicht lesbar)') {
        [void]$f.Add((New-Finding 'bad' 'Der laufende Caddy stammt aus einem anderen Verzeichnis' `
            ('L{ae}uft: ' + $paths[0] + '. Verwaltet wird aber ' + $Paths.Exe + '. Solange das so ist, ' +
             'wirken {Ae}nderungen hier nicht auf den laufenden Server. Erst die fremde Einrichtung ' +
             'beenden, dann hier neu starten.')))
    }

    $foreign = Get-ForeignSetup
    if (@($foreign.services).Count -gt 0) {
        $txt = (@($foreign.services | ForEach-Object { $_.display + ' (' + $_.state + ')' }) -join ', ')
        [void]$f.Add((New-Finding 'bad' 'Caddy laeuft zusaetzlich als Windows-Dienst' `
            ("Gefunden: $txt. Dienst und geplante Aufgabe starten denselben Server doppelt. " +
             'Bitte einen der beiden Wege abschalten - der Manager fasst fremde Dienste nicht an.')))
    }
    $fremd = @($foreign.tasks | Where-Object { $LegacyTaskNames -notcontains ($_.name -replace '^\\', '') })
    if ($fremd.Count -gt 0) {
        [void]$f.Add((New-Finding 'warn' 'Fremde geplante Aufgaben mit Caddy-Bezug' `
            ('Sie stammen nicht vom Manager und k{oe}nnen dagegenarbeiten: ' +
             ((@($fremd | ForEach-Object { $_.name })) -join ', '))))
    }

    $missing = Get-MissingSiteRoots $Config
    if ($missing.Count -gt 0) {
        [void]$f.Add((New-Finding 'warn' "$($missing.Count) Seite(n) zeigen auf ein Verzeichnis, das es nicht gibt" `
            ('Besucher bekommen dort nur Fehler. Betroffen: ' +
             ((@($missing | ForEach-Object { $_.domain + ' -> ' + $_.root })) -join '; ')) `
            'create-roots' 'Verzeichnisse anlegen'))
    }

    if ($Status.configBroken) {
        [void]$f.Add((New-Finding 'bad' 'Die aktive Caddyfile ist fehlerhaft' `
            ('Caddy kann sie nicht laden: ' + [string]$Status.configError +
             ' Der Server startet damit nicht. Unter Caddyfile pr{ue}fen oder eine Sicherung zur{ue}ckholen.')))
    }

    if (@($Status.legacyTasks).Count -gt 0) {
        [void]$f.Add((New-Finding 'warn' 'Alte Aufgaben aus der Handinstallation gefunden' `
            ('Diese k{oe}nnen sich mit der neuen Automatik ins Gehege kommen: ' + (@($Status.legacyTasks) -join ', ')) `
            'remove-legacy' 'Alte Aufgaben entfernen'))
    }

    $admin = [string]$Config.global.adminListen
    if ($admin -match '^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$' -or $admin -eq 'off') {
        [void]$f.Add((New-Finding 'ok' 'Verwaltungsschnittstelle ist nur lokal erreichbar' "admin $admin"))
    } else {
        [void]$f.Add((New-Finding 'bad' 'Verwaltungsschnittstelle ist von au{ss}en erreichbar' `
            "Die Caddy-Admin-API steht auf '$admin'. Wer sie erreicht, kann die gesamte Konfiguration {ae}ndern." `
            'fix-admin' 'Auf localhost begrenzen'))
    }

    foreach ($pc in @(@{ n = 80; o = $Status.port80 }, @{ n = 443; o = $Status.port443 })) {
        if (-not $pc.o -or $pc.o.name -eq 'caddy') { continue }
        $hint = ''
        switch -Regex ($pc.o.name) {
            '(?i)^(w3wp|inetinfo)$' { $hint = ' Das ist der IIS. Er muss beendet oder umgestellt werden: in der Systemsteuerung unter Dienste "World Wide Web-Publishingdienst" (W3SVC) beenden und auf Deaktiviert setzen.' }
            '(?i)^System$'          { $hint = ' Der Port haengt an http.sys - meist IIS, WinRM oder die Windows-Freigabe von Hyper-V. Mit "netsh http show servicestate" laesst sich der Verursacher finden.' }
            '(?i)^(vmware|Skype)'   { $hint = ' Im Programm laesst sich der Port umstellen.' }
        }
        [void]$f.Add((New-Finding 'bad' ("Port $($pc.n) geh{oe}rt einem anderen Programm") `
            ("Dort lauscht '$($pc.o.name)' (Prozess $($pc.o.pid)). Caddy kann den Port nicht belegen und " +
             'startet damit nicht.' + $hint)))
    }

    if ($Status.caddyVersion -and -not (Test-CaddyAtLeast 2 5)) {
        [void]$f.Add((New-Finding 'warn' "Caddy $($Status.caddyVersion) ist alt" `
            ('Der Schutz versteckter Dateien wird bei dieser Fassung weggelassen, weil sie die ' +
             'n{oe}tigen Direktiven noch nicht kennt. Ein Update bringt ihn zur{ue}ck.') `
            'setup-caddy-update' 'Caddy aktualisieren'))
    }

    try {
        $fw = @(Get-NetFirewallRule -DisplayName 'Caddy HTTP*' -ErrorAction SilentlyContinue)
        if ($fw.Count -eq 0) {
            [void]$f.Add((New-Finding 'warn' 'Keine Firewallregeln f{ue}r den Webserver' `
                'Ohne Freigabe f{ue}r Port 80 und 443 kommt von au{ss}en niemand an.' 'setup-firewall' 'Freigaben anlegen'))
        } else {
            [void]$f.Add((New-Finding 'ok' 'Firewallfreigaben vorhanden' "$($fw.Count) Regeln f{ue}r Port 80/443."))
        }
    } catch { }

    if (-not $Status.aclOk) {
        [void]$f.Add((New-Finding 'bad' 'Programmverzeichnis ist f{ue}r jeden beschreibbar' `
            ('Ein Ordner direkt unter C:\ erbt von dort das Recht "{Ae}ndern" f{ue}r alle angemeldeten ' +
             'Benutzer. Wer keine Administratorrechte hat, kann so caddy.exe oder watchdog.ps1 ' +
             'austauschen - beide laufen als SYSTEM. Betrifft: ' + ((@($Status.aclWeak)) -join '; ')) `
            'harden-acl' 'Rechte entziehen'))
    } else {
        [void]$f.Add((New-Finding 'ok' 'Programmverzeichnis abgesichert' 'Nur Administratoren und SYSTEM d{ue}rfen schreiben.'))
    }

    [void]$f.Add((New-Finding 'warn' 'Oberfl{ae}che ohne Anmeldung' `
        ('So eingestellt. Jedes Programm und jeder angemeldete Benutzer auf diesem Rechner kann ' +
         '{ue}ber 127.0.0.1 den Webserver umbauen. Von aussen ist die Oberfl{ae}che nicht erreichbar, ' +
         'und fremde Webseiten werden durch die Kopfzeilenpr{ue}fung abgewiesen.')))

    if ($Config.manager.runAs -eq 'SYSTEM') {
        [void]$f.Add((New-Finding 'warn' 'Caddy l{ae}uft mit vollen Systemrechten' `
            ('Das ist die Voreinstellung und funktioniert immer. Sicherer ist das Konto LOCAL SERVICE: ' +
             'Caddy kann dann nur noch auf die Webserver-Verzeichnisse zugreifen. Der Umbau ist jederzeit umkehrbar.') `
            'harden-runas' 'Auf LOCAL SERVICE umstellen'))
    } else {
        [void]$f.Add((New-Finding 'ok' 'Caddy l{ae}uft mit eingeschr{ae}nkten Rechten' 'Konto: LOCAL SERVICE.'))
    }

    if (-not $Config.global.email) {
        [void]$f.Add((New-Finding 'warn' 'Keine E-Mail-Adresse f{ue}r Zertifikate hinterlegt' `
            'Let''s Encrypt verschickt dar{ue}ber Warnungen, wenn eine Verl{ae}ngerung scheitert. Unter Einstellungen nachtragen.'))
    }

    # Seiten pruefen
    $noHeaders = @($Config.sites | Where-Object { $_.enabled -and -not $_.securityHeaders })
    if ($noHeaders.Count -gt 0) {
        [void]$f.Add((New-Finding 'warn' "$($noHeaders.Count) Seite(n) ohne Sicherheitskopfzeilen" `
            ('Betroffen: ' + ((@($noHeaders | ForEach-Object { $_.domains[0] })) -join ', ')) `
            'harden-sites' 'F{ue}r alle Seiten aktivieren'))
    }
    $noBlock = @($Config.sites | Where-Object { $_.enabled -and -not $_.blockSensitive -and $_.type -ne 'proxy' })
    if ($noBlock.Count -gt 0) {
        [void]$f.Add((New-Finding 'warn' "$($noBlock.Count) Seite(n) liefern versteckte Dateien aus" `
            ('Dateien wie .env, .git oder *.sql sind dort abrufbar. Betroffen: ' +
             ((@($noBlock | ForEach-Object { $_.domains[0] })) -join ', ')) `
            'harden-sites' 'Schutz f{ue}r alle Seiten aktivieren'))
    }
    $browse = @($Config.sites | Where-Object { $_.enabled -and $_.browse })
    if ($browse.Count -gt 0) {
        [void]$f.Add((New-Finding 'warn' "$($browse.Count) Seite(n) mit Verzeichnisauflistung" `
            ('Besucher sehen dort alle Dateinamen: ' + ((@($browse | ForEach-Object { $_.domains[0] })) -join ', '))))
    }
    $plain = @($Config.sites | Where-Object { $_.enabled -and $_.domains[0] -like 'http://*' })
    if ($plain.Count -gt 0) {
        [void]$f.Add((New-Finding 'warn' "$($plain.Count) Seite(n) ohne Verschl{ue}sselung" `
            ('Adressen mit http:// bekommen kein Zertifikat: ' + ((@($plain | ForEach-Object { $_.domains[0] })) -join ', '))))
    }

    foreach ($c in @($Status.certificates)) {
        if ($c.daysLeft -lt 0) {
            [void]$f.Add((New-Finding 'bad' "Zertifikat abgelaufen: $($c.domain)" "Abgelaufen am $($c.notAfter)."))
        } elseif ($c.daysLeft -lt 14) {
            [void]$f.Add((New-Finding 'warn' "Zertifikat l{ae}uft bald ab: $($c.domain)" `
                "Noch $($c.daysLeft) Tage (bis $($c.notAfter)). Caddy verlaengert normalerweise selbst."))
        }
    }

    if ($Status.phpInstalled) {
        try {
            $ini = Get-Content -LiteralPath $Paths.PhpIni -Raw -ErrorAction SilentlyContinue
            if ($ini -and $ini -notmatch $([regex]::Escape($PhpIniMarkerStart))) {
                [void]$f.Add((New-Finding 'warn' 'php.ini ist nicht abgesichert' `
                    'Die H{ae}rtung des Managers fehlt in der php.ini.' 'php-ini' 'php.ini absichern'))
            } else {
                [void]$f.Add((New-Finding 'ok' 'php.ini ist abgesichert' 'expose_php aus, Fehlerausgabe nur ins Protokoll.'))
            }
        } catch { }
    }

    if ($Status.disk -and $Status.disk.freeGb -lt 2) {
        [void]$f.Add((New-Finding 'bad' 'Wenig freier Speicherplatz' "Nur noch $($Status.disk.freeGb) GB frei."))
    }

    return ,@($f.ToArray())
}

# ---------------------------------------------------------------------------
#  Protokolle
# ---------------------------------------------------------------------------
function Get-LogFileList {
    $list = New-Object System.Collections.ArrayList
    if (Test-Path -LiteralPath $Paths.Logs) {
        foreach ($f in @(Get-ChildItem -LiteralPath $Paths.Logs -File -ErrorAction SilentlyContinue |
                         Sort-Object LastWriteTime -Descending | Select-Object -First 60)) {
            [void]$list.Add(@{
                name     = $f.Name
                sizeKb   = [Math]::Round($f.Length / 1KB, 1)
                modified = $f.LastWriteTime.ToString('yyyy-MM-dd HH:mm')
            })
        }
    }
    if (Test-Path -LiteralPath $Paths.Audit) {
        $a = Get-Item -LiteralPath $Paths.Audit
        [void]$list.Add(@{
            name     = 'manager.log'
            sizeKb   = [Math]::Round($a.Length / 1KB, 1)
            modified = $a.LastWriteTime.ToString('yyyy-MM-dd HH:mm')
        })
    }
    return ,@($list.ToArray())
}

function Get-LogTail {
    param([string]$Name, [int]$Lines = 200)
    if ($Name -eq 'manager.log') {
        if (-not (Test-Path -LiteralPath $Paths.Audit)) { return '' }
        return ((Get-Content -LiteralPath $Paths.Audit -Tail $Lines -ErrorAction SilentlyContinue) -join "`n")
    }
    # Nur Dateien aus dem Protokollverzeichnis, kein Verlassen des Ordners
    if ($Name -notmatch '^[A-Za-z0-9_\.\-]{1,120}$') { return '' }
    $full = Join-Path $Paths.Logs $Name
    $resolved = ''
    try { $resolved = [System.IO.Path]::GetFullPath($full) } catch { return '' }
    $logRoot = [System.IO.Path]::GetFullPath($Paths.Logs)
    if (-not $resolved.StartsWith($logRoot, [StringComparison]::OrdinalIgnoreCase)) { return '' }
    if (-not (Test-Path -LiteralPath $resolved -PathType Leaf)) { return '' }
    try {
        return ((Get-Content -LiteralPath $resolved -Tail $Lines -ErrorAction SilentlyContinue) -join "`n")
    } catch { return '' }
}

function Get-BackupList {
    $list = New-Object System.Collections.ArrayList
    if (Test-Path -LiteralPath $Paths.Backups) {
        foreach ($f in @(Get-ChildItem -LiteralPath $Paths.Backups -Filter 'caddyfile-*.bak' -ErrorAction SilentlyContinue |
                         Sort-Object LastWriteTime -Descending | Select-Object -First 30)) {
            [void]$list.Add(@{
                name     = $f.Name
                sizeKb   = [Math]::Round($f.Length / 1KB, 1)
                modified = $f.LastWriteTime.ToString('yyyy-MM-dd HH:mm:ss')
            })
        }
    }
    return ,@($list.ToArray())
}

function Restore-Backup {
    param([string]$Name)
    if ($Name -notmatch '^caddyfile-\d{8}-\d{6}-\d{3}\.bak$') { return @{ ok = $false; message = 'Ung{ue}ltiger Name.' } }
    $full = Join-Path $Paths.Backups $Name
    if (-not (Test-Path -LiteralPath $full -PathType Leaf)) { return @{ ok = $false; message = 'Sicherung nicht gefunden.' } }
    $text = Read-TextFileSmart $full
    $res = Write-CaddyfileAndReload -NewText $text -Reason 'restore'
    if ($res.ok) { $res.message = "Sicherung '$Name' wiederhergestellt. " + $res.message }
    return $res
}

# ---------------------------------------------------------------------------
#  Passworthash fuer den Zugriffsschutz
# ---------------------------------------------------------------------------
function Find-BcryptLine {
    param([string]$Text)
    foreach ($line in (([string]$Text) -split "`n")) {
        $t = $line.Trim()
        if (Test-BcryptHash $t) { return $t }
    }
    return ''
}

function New-PasswordHash {
    param([string]$Plain)
    if (-not (Test-Path -LiteralPath $Paths.Exe)) { return @{ ok = $false; message = 'Caddy ist noch nicht installiert.' } }
    if ([string]::IsNullOrEmpty($Plain) -or $Plain.Length -lt 8) {
        return @{ ok = $false; message = 'Das Passwort muss mindestens 8 Zeichen haben.' }
    }
    if ($Plain.Length -gt 128 -or -not (Test-NoControlChars $Plain)) {
        return @{ ok = $false; message = 'Das Passwort enth{ae}lt unzul{ae}ssige Zeichen.' }
    }
    # Bevorzugt ueber die Standardeingabe: dann steht das Passwort nicht in der
    # Befehlszeile, die jeder lokale Benutzer auslesen kann.
    $r = Invoke-Exe -FilePath $Paths.Exe -Arguments @('hash-password') -TimeoutSec 60 -StdIn $Plain
    $hash = Find-BcryptLine (Get-ExeOutput $r)
    if (-not $hash) {
        # Aeltere Caddy-Ausgaben lesen nicht von der Standardeingabe
        $r = Invoke-Exe -FilePath $Paths.Exe -Arguments @('hash-password', '--plaintext', $Plain) -TimeoutSec 60
        $hash = Find-BcryptLine (Get-ExeOutput $r)
    }
    if ($hash) {
        Write-Audit 'password.hash' 'Neuer Hash erzeugt'
        return @{ ok = $true; hash = $hash }
    }
    return @{ ok = $false; message = 'Der Hash konnte nicht erzeugt werden.' }
}

# ===========================================================================
#  BESTANDSAUFNAHME
#  Was laeuft auf diesem Rechner schon, das mit Caddy zu tun hat und nicht vom
#  Manager stammt? Die Abfragen gehen ueber CIM und sind langsam, deshalb nur
#  auf Anforderung (Sicherheitsansicht) und mit langem Zwischenspeicher.
# ===========================================================================
$script:ForeignCache = $null
$script:ForeignCacheAt = [datetime]::MinValue
$CacheSecForeign = 1800

function Get-CaddyProcessPaths {
    $list = New-Object System.Collections.ArrayList
    foreach ($p in @(Get-Process -Name caddy -ErrorAction SilentlyContinue)) {
        $path = ''
        try { $path = [string]$p.Path } catch { }
        if (-not $path) { $path = '(Pfad nicht lesbar)' }
        if (-not $list.Contains($path)) { [void]$list.Add($path) }
    }
    return ,@($list.ToArray())
}

function Get-ForeignSetup {
    if ($null -ne $script:ForeignCache -and ((Get-Date) - $script:ForeignCacheAt).TotalSeconds -lt $CacheSecForeign) {
        return $script:ForeignCache
    }
    $services = New-Object System.Collections.ArrayList
    $tasks = New-Object System.Collections.ArrayList

    # Dienste, die caddy ausfuehren - typisch nach einer Einrichtung mit nssm oder
    # WinSW. Der Filter laeuft in der Abfrage selbst, das ist deutlich billiger,
    # als alle Dienste zu holen und hier zu sieben.
    $svcList = @()
    try {
        $svcList = @(Get-CimInstance -ClassName Win32_Service -Filter "PathName LIKE '%caddy%'" -ErrorAction Stop)
    } catch {
        try { $svcList = @(Get-CimInstance -ClassName Win32_Service -ErrorAction SilentlyContinue) } catch { }
    }
    try {
        foreach ($s in $svcList) {
            $pn = [string]$s.PathName
            if ($pn -and $pn -match '(?i)caddy') {
                [void]$services.Add(@{ name = [string]$s.Name; display = [string]$s.DisplayName
                                       state = [string]$s.State; path = (Get-SafeString $pn 200) })
            }
        }
    } catch { }

    # Geplante Aufgaben ausserhalb unseres Ordners, die caddy oder php-cgi starten
    try {
        foreach ($t in @(Get-ScheduledTask -ErrorAction SilentlyContinue)) {
            if ([string]$t.TaskPath -eq $TaskFolder) { continue }
            $hit = $false
            $what = ''
            foreach ($a in @($t.Actions)) {
                $ex = ''
                $ar = ''
                try { $ex = [string]$a.Execute } catch { }
                try { $ar = [string]$a.Arguments } catch { }
                $both = $ex + ' ' + $ar
                if ($both -match '(?i)caddy|php-cgi') { $hit = $true; $what = (Get-SafeString $both 160) }
            }
            if ($hit) {
                [void]$tasks.Add(@{ name = ([string]$t.TaskPath + [string]$t.TaskName)
                                    state = [string]$t.State; action = $what })
            }
        }
    } catch { }

    $script:ForeignCache = @{ services = @($services.ToArray()); tasks = @($tasks.ToArray()) }
    $script:ForeignCacheAt = Get-Date
    return $script:ForeignCache
}

function Get-MissingSiteRoots {
    param($Config)
    $miss = New-Object System.Collections.ArrayList
    foreach ($s in @($Config.sites)) {
        if (-not $s.enabled) { continue }
        if ($s.type -ne 'static' -and $s.type -ne 'php') { continue }
        if (-not $s.root) { continue }
        if (-not (Test-Path -LiteralPath $s.root)) {
            [void]$miss.Add(@{ id = $s.id; domain = $s.domains[0]; root = $s.root })
        }
    }
    return ,@($miss.ToArray())
}

function New-MissingSiteRoots {
    param($Config)
    $made = 0
    foreach ($m in (Get-MissingSiteRoots $Config)) {
        try {
            New-Item -ItemType Directory -Path $m.root -Force | Out-Null
            $index = $m.root + '\index.html'
            if (-not (Test-Path -LiteralPath $index)) {
                Write-TextFile $index (T ("<!doctype html>`r`n<meta charset=`"utf-8`">`r`n" +
                    "<title>" + (ConvertTo-HtmlText $m.domain) + "</title>`r`n" +
                    "<h1>Es funktioniert.</h1>`r`n"))
            }
            $made++
        } catch { }
    }
    Write-Audit 'roots.create' "count=$made"
    return @{ ok = $true; message = "$made Verzeichnis(se) angelegt." }
}

# ===========================================================================
#  LOKALER VERWALTUNGSSERVER
#
#  Sicherheitsmodell (bewusst offen gewaehlt):
#   - Der Listener bindet ausschliesslich auf 127.0.0.1. Aus dem Netz ist die
#     Oberflaeche nicht erreichbar, auch nicht ueber den Rechnernamen.
#   - Es gibt KEINE Anmeldung. Wer lokal auf 127.0.0.1 zugreifen kann, darf
#     alles. Das ist so gewuenscht; der Schutz liegt in den Dateirechten und
#     darin, dass der Manager nur laeuft, wenn ihn jemand startet.
#   - Was trotzdem bleibt und bleiben muss: aendernde Aufrufe brauchen einen
#     eigenen Kopfzeilen-Wert und die richtige Herkunft. Ohne das koennte jede
#     beliebige Webseite, die im Browser dieses Rechners geoeffnet wird, per
#     Formular auf 127.0.0.1 schreiben und den Webserver umbauen. Ein eigener
#     Kopfzeilen-Wert loest eine Vorabanfrage aus, die wir ablehnen.
#   - Die Host-Pruefung verhindert DNS-Rebinding aus dem Netz.
#   - Nach Ablauf der Leerlaufzeit beendet sich der Server von selbst.
# ===========================================================================

$script:Listener     = $null
$script:Port         = 0
$script:CsrfToken    = ''
$script:LastActivity = Get-Date
$script:Running      = $true
$script:Config       = $null
$script:Origin       = ''

function Start-Listener {
    param([int]$PreferredPort)
    $ports = @($PreferredPort)
    for ($i = 1; $i -le 20; $i++) { $ports += ($PreferredPort + $i) }
    foreach ($p in $ports) {
        if ($p -lt 1024 -or $p -gt 65535) { continue }
        $l = New-Object System.Net.HttpListener
        try {
            $l.Prefixes.Add("http://127.0.0.1:$p/")
            $l.Start()
            return @{ listener = $l; port = $p }
        } catch {
            try { $l.Close() } catch { }
        }
    }
    return $null
}

function Add-CommonHeaders {
    param($Response)
    try {
        $Response.Headers['X-Content-Type-Options'] = 'nosniff'
        $Response.Headers['X-Frame-Options'] = 'DENY'
        $Response.Headers['Referrer-Policy'] = 'no-referrer'
        $Response.Headers['Cache-Control'] = 'no-store, must-revalidate'
        $Response.Headers['Permissions-Policy'] = 'geolocation=(), microphone=(), camera=()'
        $Response.Headers.Remove('Server')
    } catch { }
}

function Send-Bytes {
    param($Ctx, [byte[]]$Bytes, [string]$ContentType, [int]$Status = 200)
    try {
        $Ctx.Response.StatusCode = $Status
        $Ctx.Response.ContentType = $ContentType
        Add-CommonHeaders $Ctx.Response
        $Ctx.Response.ContentLength64 = $Bytes.Length
        $Ctx.Response.OutputStream.Write($Bytes, 0, $Bytes.Length)
    } catch { } finally {
        try { $Ctx.Response.Close() } catch { }
    }
}

function Send-Text {
    param($Ctx, [string]$Text, [string]$ContentType = 'text/plain; charset=utf-8', [int]$Status = 200)
    Send-Bytes $Ctx ([Text.Encoding]::UTF8.GetBytes($Text)) $ContentType $Status
}

# Meldungsfelder tragen Umlaut-Platzhalter, weil die Quelldatei reines ASCII
# ist. Felder mit Nutzerdaten (Caddyfile-Text, Konfiguration, Pfade) bleiben
# bewusst unberuehrt.
$UmlautSkipKeys = @('text', 'live', 'validation', 'config', 'site', 'status', 'hash',
                    'csrf', 'backups', 'files', 'skipped', 'addresses', 'domain',
                    'created', 'removed', 'backup')

function Convert-Umlauts {
    param($Obj, [int]$Depth = 0)
    if ($Depth -gt 6 -or $null -eq $Obj) { return $Obj }
    if ($Obj -is [string]) { return (T $Obj) }
    if ($Obj -is [System.Collections.IDictionary]) {
        foreach ($k in @($Obj.Keys)) {
            if ($UmlautSkipKeys -contains ([string]$k)) { continue }
            $Obj[$k] = Convert-Umlauts $Obj[$k] ($Depth + 1)
        }
        return $Obj
    }
    if ($Obj -is [object[]]) {
        for ($i = 0; $i -lt $Obj.Length; $i++) { $Obj[$i] = Convert-Umlauts $Obj[$i] ($Depth + 1) }
        return $Obj
    }
    return $Obj
}

function Send-Json {
    param($Ctx, $Obj, [int]$Status = 200)
    try { $Obj = Convert-Umlauts $Obj } catch { }
    $json = ''
    try { $json = $Obj | ConvertTo-Json -Depth 12 -Compress }
    catch { $json = '{"ok":false,"message":"Antwort konnte nicht erzeugt werden."}'; $Status = 500 }
    Send-Text $Ctx $json 'application/json; charset=utf-8' $Status
}

function Send-Html {
    param($Ctx, [string]$Html, [string]$Nonce = '')
    try {
        if ($Nonce) {
            $csp = "default-src 'none'; script-src 'nonce-$Nonce'; style-src 'nonce-$Nonce'; " +
                   "img-src data:; font-src data:; connect-src 'self'; form-action 'none'; " +
                   "base-uri 'none'; frame-ancestors 'none'"
            $Ctx.Response.Headers['Content-Security-Policy'] = $csp
        }
    } catch { }
    Send-Text $Ctx $Html 'text/html; charset=utf-8' 200
}

function Read-RequestBody {
    param($Ctx, [int]$MaxBytes = 1048576)
    try {
        if ($Ctx.Request.ContentLength64 -gt $MaxBytes) { return $null }
        # Bei zerstueckelten Anfragen ist ContentLength64 gleich -1. Deshalb
        # nicht auf die Angabe verlassen, sondern hart begrenzt einlesen.
        $buf = New-Object char[] 8192
        $sb = New-Object System.Text.StringBuilder
        $sr = New-Object System.IO.StreamReader($Ctx.Request.InputStream, [Text.Encoding]::UTF8)
        try {
            while ($true) {
                $n = $sr.Read($buf, 0, $buf.Length)
                if ($n -le 0) { break }
                [void]$sb.Append($buf, 0, $n)
                if ($sb.Length -gt $MaxBytes) { return $null }
            }
        } finally { $sr.Dispose() }
        return $sb.ToString()
    } catch { return $null }
}

function Read-RequestJson {
    param($Ctx)
    $body = Read-RequestBody $Ctx
    if ([string]::IsNullOrWhiteSpace($body)) { return $null }
    try { return (ConvertTo-Hash (ConvertFrom-Json $body)) } catch { return $null }
}

function Test-RequestHost {
    param($Ctx)
    $h = [string]$Ctx.Request.Headers['Host']
    return ($h -eq ("127.0.0.1:" + $script:Port))
}

function Test-RequestOrigin {
    param($Ctx)
    $o = [string]$Ctx.Request.Headers['Origin']
    if ([string]::IsNullOrEmpty($o)) { return $true }   # gleiche Herkunft sendet oft keinen Origin
    return ($o -eq $script:Origin)
}

function Test-Csrf {
    param($Ctx)
    $v = [string]$Ctx.Request.Headers['X-Caddy-Manager-Csrf']
    return (Test-SecretEqual $v $script:CsrfToken)
}

# ---------------------------------------------------------------------------
#  Anfragebearbeitung
# ---------------------------------------------------------------------------
function Invoke-Request {
    param($Ctx)
    $path = [string]$Ctx.Request.Url.AbsolutePath
    $method = [string]$Ctx.Request.HttpMethod

    $script:LastActivity = Get-Date

    if (-not (Test-RequestHost $Ctx)) {
        Send-Text $Ctx (T 'Ung{ue}ltiger Host.') 'text/plain; charset=utf-8' 400
        return
    }

    if ($path -eq '/favicon.ico') {
        try { $Ctx.Response.StatusCode = 204; Add-CommonHeaders $Ctx.Response } catch { }
        try { $Ctx.Response.Close() } catch { }
        return
    }

    if ($path -eq '/' -and $method -eq 'GET') {
        $nonce = New-Secret 16
        Send-Html $Ctx (Get-UiHtml $nonce) $nonce
        return
    }

    # Aendernde Aufrufe nur ueber POST, sonst greift der Schutz unten nicht.
    if ($method -ne 'POST' -and $path -like '/api/*') {
        $readOnly = @('/api/state', '/api/status', '/api/security', '/api/preview',
                      '/api/logs', '/api/log', '/api/backups', '/api/audit', '/api/caddyfile')
        if ($readOnly -notcontains $path) {
            Send-Json $Ctx @{ ok = $false; message = 'Diese Aktion ist nur per POST erlaubt.' } 405
            return
        }
    }

    # Der einzige verbliebene Schutz - und der einzige, der hier zaehlt: eine
    # fremde Webseite kann zwar ein Formular an 127.0.0.1 schicken, aber keine
    # eigene Kopfzeile setzen, ohne vorher eine Vorabanfrage zu stellen. Die
    # beantworten wir nicht. Damit bleibt Drive-by-Umkonfiguration aussen vor.
    if ($method -eq 'POST') {
        if (-not (Test-RequestOrigin $Ctx)) {
            Send-Json $Ctx @{ ok = $false; message = 'Herkunft der Anfrage abgelehnt.' } 403
            return
        }
        if (-not (Test-Csrf $Ctx)) {
            Send-Json $Ctx @{ ok = $false; message = 'Kopfzeile fehlt. Bitte die Seite neu laden.' } 403
            return
        }
    }

    try {
        Invoke-ApiRoute $Ctx $path $method
    } catch {
        Write-Audit 'api.error' ("$path :: " + $_.Exception.Message) 'error'
        Send-Json $Ctx @{ ok = $false; message = ('Unerwarteter Fehler: ' + $_.Exception.Message) } 500
    }
}

function Invoke-ApiRoute {
    param($Ctx, [string]$Path, [string]$Method)
    $cfg = $script:Config

    switch ($Path) {

        '/api/state' {
            $status = Get-Status $cfg
            Send-Json $Ctx @{ ok = $true; status = $status; config = $cfg; csrf = $script:CsrfToken }
            return
        }

        '/api/status' {
            Send-Json $Ctx @{ ok = $true; status = (Get-Status $cfg) }
            return
        }

        '/api/security' {
            $status = Get-Status $cfg
            Send-Json $Ctx @{ ok = $true; findings = (Get-SecurityFindings $cfg $status) }
            return
        }

        '/api/preview' {
            Send-Json $Ctx @{ ok = $true; text = (Build-Caddyfile $cfg); live = (Get-LiveCaddyfile) }
            return
        }

        '/api/site/save' {
            $data = Read-RequestJson $Ctx
            if (-not $data) { Send-Json $Ctx @{ ok = $false; message = 'Keine Daten empfangen.' } 400; return }
            $clean = ConvertTo-CleanSite $data
            if (-not $clean) {
                Send-Json $Ctx @{ ok = $false; message = 'Die Eingaben sind unvollst{ae}ndig. Domain pr{ue}fen - bei einem Reverse Proxy zus{ae}tzlich das Ziel, bei einer Weiterleitung die Zieladresse.' } 400
                return
            }
            $list = New-Object System.Collections.ArrayList
            $replaced = $false
            foreach ($s in @($cfg.sites)) {
                if ($s.id -eq $clean.id) { [void]$list.Add($clean); $replaced = $true }
                else { [void]$list.Add($s) }
            }
            if (-not $replaced) { [void]$list.Add($clean) }
            $cfg.sites = $list.ToArray()
            Save-Config $cfg
            Write-Audit 'site.save' ($clean.domains -join ',')
            Send-Json $Ctx @{ ok = $true; message = 'Gespeichert. Mit "{Ue}bernehmen" wird die {Ae}nderung aktiv.'; site = $clean; config = $cfg }
            return
        }

        '/api/site/delete' {
            $data = Read-RequestJson $Ctx
            $id = Get-StringField $data 'id' ''
            $keep = New-Object System.Collections.ArrayList
            $removed = ''
            foreach ($s in @($cfg.sites)) {
                if ($s.id -eq $id) { $removed = ($s.domains -join ',') } else { [void]$keep.Add($s) }
            }
            $cfg.sites = $keep.ToArray()
            Save-Config $cfg
            Write-Audit 'site.delete' $removed
            Send-Json $Ctx @{ ok = $true; message = 'Eintrag entfernt.'; config = $cfg }
            return
        }

        '/api/site/toggle' {
            $data = Read-RequestJson $Ctx
            $id = Get-StringField $data 'id' ''
            foreach ($s in @($cfg.sites)) {
                if ($s.id -eq $id) { $s.enabled = -not $s.enabled }
            }
            Save-Config $cfg
            Send-Json $Ctx @{ ok = $true; config = $cfg }
            return
        }

        '/api/site/order' {
            $data = Read-RequestJson $Ctx
            $order = @(Get-ArrayField $data 'ids')
            $byId = @{}
            foreach ($s in @($cfg.sites)) { $byId[[string]$s.id] = $s }
            $list = New-Object System.Collections.ArrayList
            foreach ($id in $order) {
                $k = [string]$id
                if ($byId.ContainsKey($k)) { [void]$list.Add($byId[$k]); $byId.Remove($k) }
            }
            foreach ($s in @($cfg.sites)) { if ($byId.ContainsKey([string]$s.id)) { [void]$list.Add($s) } }
            $cfg.sites = $list.ToArray()
            Save-Config $cfg
            Send-Json $Ctx @{ ok = $true; config = $cfg }
            return
        }

        '/api/settings' {
            $data = Read-RequestJson $Ctx
            if (-not $data) { Send-Json $Ctx @{ ok = $false; message = 'Keine Daten empfangen.' } 400; return }
            # Erst alles pruefen, dann uebernehmen. Sonst bliebe bei einer
            # Ablehnung ein halb geaenderter Stand im Speicher zurueck.
            $email = Get-SafeString (Get-StringField $data 'email' '') 254
            if ($email -and -not (Test-EmailAddress $email)) {
                Send-Json $Ctx @{ ok = $false; message = 'Die E-Mail-Adresse ist ung{ue}ltig.' } 400
                return
            }
            $geCheck = Get-StringField $data 'globalExtra' $cfg.global.extra
            if ($geCheck.Length -gt 8000 -or -not (Test-BalancedBraces $geCheck)) {
                Send-Json $Ctx @{ ok = $false; message = 'Die zus{ae}tzlichen globalen Zeilen haben unpaarige geschweifte Klammern.' } 400
                return
            }
            $snCheck = Get-StringField $data 'snippets' $cfg.global.snippets
            if ($snCheck.Length -gt 16000 -or -not (Test-BalancedBraces $snCheck)) {
                Send-Json $Ctx @{ ok = $false; message = 'Die Bausteine haben unpaarige geschweifte Klammern.' } 400
                return
            }
            $cfg.global.email = $email
            $lvl = (Get-StringField $data 'logLevel' $cfg.global.logLevel).ToUpper()
            if (@('DEBUG', 'INFO', 'WARN', 'ERROR') -contains $lvl) { $cfg.global.logLevel = $lvl }
            $rs = Get-StringField $data 'rollSize' $cfg.global.rollSize
            if (Test-SizeValue $rs) { $cfg.global.rollSize = $rs }
            $rk = 0
            if ([int]::TryParse((Get-StringField $data 'rollKeep' '7'), [ref]$rk) -and $rk -ge 1 -and $rk -le 100) {
                $cfg.global.rollKeep = $rk
            }
            $cfg.global.extra = $geCheck
            $cfg.global.snippets = $snCheck

            $cfg.global.trustedProxies = Get-BoolField $data 'trustedProxies' $cfg.global.trustedProxies
            $cfg.php.enabled = Get-BoolField $data 'phpEnabled' $cfg.php.enabled
            $psz = 0
            if ([int]::TryParse((Get-StringField $data 'phpPoolSize' '4'), [ref]$psz) -and $psz -ge 1 -and $psz -le 16) {
                $cfg.php.poolSize = $psz
            }
            $cfg.php.disableRiskyFunctions = Get-BoolField $data 'phpDisableRisky' $cfg.php.disableRiskyFunctions
            $im = 0
            if ([int]::TryParse((Get-StringField $data 'idleMinutes' '60'), [ref]$im) -and $im -ge 0 -and $im -le 1440) {
                $cfg.manager.idleMinutes = $im
            }
            $cfg.manager.openBrowser = Get-BoolField $data 'openBrowser' $cfg.manager.openBrowser

            Save-Config $cfg
            Write-Audit 'settings.save'
            Send-Json $Ctx @{ ok = $true; message = 'Einstellungen gespeichert.'; config = $cfg }
            return
        }

        '/api/apply' {
            if ($cfg.mode -eq 'manual') {
                Send-Json $Ctx @{ ok = $false; message = 'Im manuellen Modus wird die Caddyfile nicht erzeugt.' } 400
                return
            }
            $text = Build-Caddyfile $cfg
            $res = Write-CaddyfileAndReload -NewText $text -Reason 'apply'
            Send-Json $Ctx @{ ok = $res.ok; message = $res.message; validation = $res.validation
                              backup = $res.backup; status = (Get-Status $cfg) }
            return
        }

        '/api/caddyfile' {
            if ($Method -eq 'GET') {
                Send-Json $Ctx @{ ok = $true; text = (Get-LiveCaddyfile); mode = $cfg.mode }
                return
            }
            $data = Read-RequestJson $Ctx
            $text = Get-StringField $data 'text' ''
            if ([string]::IsNullOrWhiteSpace($text)) {
                Send-Json $Ctx @{ ok = $false; message = 'Der Text ist leer.' } 400
                return
            }
            if ($text.Length -gt 400000) {
                Send-Json $Ctx @{ ok = $false; message = 'Die Datei ist zu gro{ss}.' } 400
                return
            }
            $res = Write-CaddyfileAndReload -NewText $text -Reason 'manual'
            Send-Json $Ctx @{ ok = $res.ok; message = $res.message; validation = $res.validation; backup = $res.backup }
            return
        }

        '/api/validate' {
            $data = Read-RequestJson $Ctx
            $text = Get-StringField $data 'text' ''
            if ([string]::IsNullOrWhiteSpace($text)) { Send-Json $Ctx @{ ok = $false; message = 'Kein Text.' } 400; return }
            Write-TextFile $Paths.Staging (ConvertTo-FileText $text)
            $check = Test-CaddyConfigFile $Paths.Staging
            Remove-Item -LiteralPath $Paths.Staging -Force -ErrorAction SilentlyContinue
            Send-Json $Ctx @{ ok = $check.ok
                              message = $(if ($check.ok) { 'Die Konfiguration ist g{ue}ltig.' } else { 'Die Konfiguration enth{ae}lt Fehler.' })
                              validation = $check.output }
            return
        }

        '/api/format' {
            $data = Read-RequestJson $Ctx
            $text = Get-StringField $data 'text' ''
            if ([string]::IsNullOrWhiteSpace($text)) { Send-Json $Ctx @{ ok = $false; message = 'Kein Text.' } 400; return }
            Write-TextFile $Paths.Staging (ConvertTo-FileText $text)
            $r = Invoke-Caddy @('fmt', '--overwrite', $Paths.Staging) 60
            $out = ''
            if (Test-Path -LiteralPath $Paths.Staging) { $out = Get-Content -LiteralPath $Paths.Staging -Raw -Encoding UTF8 }
            Remove-Item -LiteralPath $Paths.Staging -Force -ErrorAction SilentlyContinue
            if ($r.ok -and $out) { Send-Json $Ctx @{ ok = $true; text = $out; message = 'Formatiert.' } }
            else { Send-Json $Ctx @{ ok = $false; message = ('Formatieren fehlgeschlagen: ' + (Get-ExeOutput $r)) } }
            return
        }

        '/api/mode' {
            $data = Read-RequestJson $Ctx
            $mode = (Get-StringField $data 'mode' 'managed').ToLower()
            if ($mode -eq 'manual') {
                $cfg.mode = 'manual'
                Save-Config $cfg
                Write-Audit 'mode.manual'
                Send-Json $Ctx @{ ok = $true; message = 'Manueller Modus aktiv. Die Caddyfile wird nicht mehr erzeugt.'; config = $cfg }
                return
            }
            $live = Get-LiveCaddyfile
            if ($live.Trim()) {
                $res = Import-Caddyfile $live
                $new = $res.config
                $new.mode = 'managed'
                $new.manager = $cfg.manager
                if (-not $new.php.enabled) { $new.php.enabled = $cfg.php.enabled }
                $new.php.poolSize = $cfg.php.poolSize
                $new.php.basePort = $cfg.php.basePort
                $script:Config = $new
                Save-Config $new
                Write-Audit 'mode.managed' ("imported=" + $res.imported)
                Send-Json $Ctx @{ ok = $true
                                  message = "Verwalteter Modus aktiv. $($res.imported) Eintr{ae}ge aus der Caddyfile {ue}bernommen."
                                  skipped = $res.skipped; config = $new }
                return
            }
            $cfg.mode = 'managed'
            Save-Config $cfg
            Send-Json $Ctx @{ ok = $true; message = 'Verwalteter Modus aktiv.'; config = $cfg }
            return
        }

        '/api/import' {
            $live = Get-LiveCaddyfile
            if (-not $live.Trim()) {
                Send-Json $Ctx @{ ok = $false; message = 'Es gibt noch keine Caddyfile zum Einlesen.' } 400
                return
            }
            $res = Import-Caddyfile $live
            $new = $res.config
            $new.mode = 'managed'
            $new.manager = $cfg.manager
            $new.php.poolSize = $cfg.php.poolSize
            $new.php.basePort = $cfg.php.basePort
            $script:Config = $new
            Save-Config $new
            Write-Audit 'config.import' ("imported=" + $res.imported)
            Send-Json $Ctx @{ ok = $true
                              message = "$($res.imported) Eintr{ae}ge aus der bestehenden Caddyfile {ue}bernommen."
                              skipped = $res.skipped; config = $new }
            return
        }

        '/api/service' {
            $data = Read-RequestJson $Ctx
            $action = (Get-StringField $data 'action' '').ToLower()
            $res = $null
            switch ($action) {
                'start'     { $res = Start-CaddyServer }
                'stop'      { $res = Stop-CaddyServer }
                'restart'   { $res = Restart-CaddyServer }
                'reload'    { $res = Write-CaddyfileAndReload -NewText (Get-LiveCaddyfile) -Reason 'reload' }
                'php-start' { $res = Start-PhpPool $cfg }
                'php-stop'  { $res = Stop-PhpPool }
                default     { $res = @{ ok = $false; message = 'Unbekannte Aktion.' } }
            }
            Write-Audit "service.$action" ([string]$res.message)
            Send-Json $Ctx @{ ok = $res.ok; message = $res.message; status = (Get-Status $cfg) }
            return
        }

        '/api/setup' {
            $data = Read-RequestJson $Ctx
            $step = (Get-StringField $data 'step' '').ToLower()
            Send-Json $Ctx (Invoke-SetupStep $step $data)
            return
        }

        '/api/fix' {
            $data = Read-RequestJson $Ctx
            $id = (Get-StringField $data 'id' '').ToLower()
            Send-Json $Ctx (Invoke-Fix $id)
            return
        }

        '/api/logs' {
            Send-Json $Ctx @{ ok = $true; files = (Get-LogFileList) }
            return
        }

        '/api/log' {
            $name = [string]$Ctx.Request.QueryString['name']
            $lines = 300
            $ql = [string]$Ctx.Request.QueryString['lines']
            if ($ql -match '^\d{1,4}$') { $lines = [Math]::Min([int]$ql, 3000) }
            Send-Json $Ctx @{ ok = $true; text = (Get-LogTail $name $lines) }
            return
        }

        '/api/backups' {
            Send-Json $Ctx @{ ok = $true; backups = (Get-BackupList) }
            return
        }

        '/api/restore' {
            $data = Read-RequestJson $Ctx
            $res = Restore-Backup (Get-StringField $data 'name' '')
            Send-Json $Ctx @{ ok = $res.ok; message = $res.message; validation = ([string]$res.validation) }
            return
        }

        '/api/hash' {
            $data = Read-RequestJson $Ctx
            $res = New-PasswordHash (Get-StringField $data 'password' '')
            Send-Json $Ctx $res
            return
        }

        '/api/dns' {
            $data = Read-RequestJson $Ctx
            $domains = New-Object System.Collections.ArrayList
            $id = Get-StringField $data 'id' ''
            if ($id) {
                foreach ($s in @($cfg.sites)) { if ($s.id -eq $id) { foreach ($d in $s.domains) { [void]$domains.Add($d) } } }
            } else {
                foreach ($s in @($cfg.sites)) {
                    if (-not $s.enabled) { continue }
                    foreach ($d in $s.domains) { [void]$domains.Add($d) }
                }
            }
            if ($domains.Count -eq 0) { Send-Json $Ctx @{ ok = $false; message = 'Keine Domain zum Pr{ue}fen.' } 400; return }
            $list = @($domains.ToArray() | Select-Object -Unique -First 40)
            Send-Json $Ctx (@{ ok = $true } + (Test-DomainPointsHere $list))
            return
        }

        '/api/folder' {
            $data = Read-RequestJson $Ctx
            $p = Resolve-LocalPath (Get-StringField $data 'path' '')
            if (-not $p) { Send-Json $Ctx @{ ok = $false; message = 'Der Pfad ist nicht zulaessig.' } 400; return }
            try {
                if (-not (Test-Path -LiteralPath $p)) {
                    New-Item -ItemType Directory -Path $p -Force | Out-Null
                    $index = Join-Path $p 'index.html'
                    if (-not (Test-Path -LiteralPath $index)) {
                        # Klammern sind hier zwingend: "T \"a\" + \"b\"" wuerde als
                        # Befehlsaufruf gelesen und alles nach dem ersten Wert verwerfen.
                        $html = T ("<!doctype html>`r`n<meta charset=`"utf-8`">`r`n" +
                                   "<title>Neue Seite</title>`r`n" +
                                   "<h1>Es funktioniert.</h1>`r`n" +
                                   "<p>Diesen Ordner mit den eigenen Dateien f{ue}llen:<br>" +
                                   (ConvertTo-HtmlText $p) + "</p>`r`n")
                        Write-TextFile $index $html
                    }
                    Write-Audit 'folder.create' $p
                    Send-Json $Ctx @{ ok = $true; message = "Ordner angelegt: $p"; created = $true }
                    return
                }
                Send-Json $Ctx @{ ok = $true; message = 'Der Ordner ist bereits vorhanden.'; created = $false }
            } catch {
                Send-Json $Ctx @{ ok = $false; message = ('Ordner konnte nicht angelegt werden: ' + $_.Exception.Message) } 500
            }
            return
        }

        '/api/acl' {
            $data = Read-RequestJson $Ctx
            Send-Json $Ctx (Grant-WwwAccess (Get-StringField $data 'account' ''))
            return
        }

        '/api/audit' {
            Send-Json $Ctx @{ ok = $true; text = (Get-LogTail 'manager.log' 400) }
            return
        }

        '/api/quit' {
            Write-Audit 'manager.quit'
            Send-Json $Ctx @{ ok = $true; message = 'Der Manager wird beendet. Der Webserver l{ae}uft weiter.' }
            $script:Running = $false
            return
        }

        default {
            Send-Json $Ctx @{ ok = $false; message = 'Unbekannter Aufruf.' } 404
        }
    }
}

# ---------------------------------------------------------------------------
#  Einrichtungsschritte
# ---------------------------------------------------------------------------
function Invoke-SetupStep {
    param([string]$Step, $Data)
    $cfg = $script:Config
    switch ($Step) {

        'dirs' { return (Initialize-Directories) }

        'caddy' { return (Install-Caddy) }

        'caddy-update' { return (Install-Caddy -Force) }

        'php' {
            $cfg.php.enabled = $true
            $res = Install-Php $cfg
            if ($res.ok) {
                Save-Config $cfg
                Install-Automation $cfg | Out-Null
                Start-PhpPool $cfg | Out-Null
            }
            return $res
        }

        'php-off' {
            $cfg.php.enabled = $false
            Save-Config $cfg
            Stop-PhpPool | Out-Null
            Install-Automation $cfg | Out-Null
            return @{ ok = $true; message = 'PHP deaktiviert. Die PHP-Prozesse wurden beendet.' }
        }

        'tasks' { return (Install-Automation $cfg) }

        'firewall' { return (Set-FirewallRules) }

        'all' {
            $notes = New-Object System.Collections.ArrayList
            $withPhp = Get-BoolField $Data 'php' $false

            $r = Initialize-Directories
            [void]$notes.Add($r.message)

            $r = Install-Caddy
            [void]$notes.Add($r.message)
            if (-not $r.ok) { return @{ ok = $false; message = $r.message; notes = @($notes.ToArray()) } }

            # Vorhandene Caddyfile uebernehmen, sonst eine neue erzeugen
            $live = Get-LiveCaddyfile
            if ($live.Trim() -and @($cfg.sites).Count -eq 0) {
                $imp = Import-Caddyfile $live
                $new = $imp.config
                $new.mode = 'managed'
                $new.manager = $cfg.manager
                $script:Config = $new
                $cfg = $new
                Save-Config $cfg
                [void]$notes.Add("$($imp.imported) Eintr{ae}ge aus der vorhandenen Caddyfile {ue}bernommen")
            }

            if ($withPhp) {
                $cfg.php.enabled = $true
                $r = Install-Php $cfg
                [void]$notes.Add($r.message)
            }
            Save-Config $cfg

            $r = Set-FirewallRules
            [void]$notes.Add($r.message)

            $r = Install-Automation $cfg
            [void]$notes.Add($r.message)
            foreach ($n in @($r.notes)) { [void]$notes.Add($n) }

            $acl = Protect-AllInstallDirectories
            [void]$notes.Add($acl.message)

            $mig = Import-CertificateStore
            if ($mig.changed) { [void]$notes.Add($mig.message) }

            $apply = Write-CaddyfileAndReload -NewText (Build-Caddyfile $cfg) -Reason 'setup'
            [void]$notes.Add($apply.message)

            if ($cfg.php.enabled) { Start-PhpPool $cfg | Out-Null }
            $start = Start-CaddyServer
            [void]$notes.Add($start.message)

            Write-Audit 'setup.all' ("php=$withPhp")
            return @{ ok = $true; message = 'Einrichtung abgeschlossen.'; notes = @($notes.ToArray()) }
        }

        'uninstall' { return (Uninstall-Automation) }

        default { return @{ ok = $false; message = 'Unbekannter Schritt.' } }
    }
}

function Invoke-Fix {
    param([string]$Id)
    $cfg = $script:Config
    switch ($Id) {

        'start' { return (Start-CaddyServer) }

        'setup-tasks' { return (Install-Automation $cfg) }

        'setup-caddy-update' { return (Install-Caddy -Force) }

        'setup-firewall' { return (Set-FirewallRules) }

        'setup-all' { return (Invoke-SetupStep 'all' @{}) }

        'remove-legacy' {
            $removed = Remove-LegacyTasks
            return @{ ok = $true; message = "Entfernt: $($removed.Count) alte Aufgabe(n)." }
        }

        'fix-admin' {
            $cfg.global.adminListen = 'localhost:2019'
            Save-Config $cfg
            return @{ ok = $true; message = 'Die Verwaltungsschnittstelle steht wieder auf localhost. Jetzt "{Ue}bernehmen" dr{ue}cken.' }
        }

        'harden-sites' {
            $n = 0
            foreach ($s in @($cfg.sites)) {
                if (-not $s.securityHeaders) { $s.securityHeaders = $true; $n++ }
                if (-not $s.blockSensitive -and $s.type -ne 'proxy') { $s.blockSensitive = $true; $n++ }
            }
            Save-Config $cfg
            Write-Audit 'fix.harden-sites' "changes=$n"
            return @{ ok = $true; message = "$n Einstellungen angepasst. Jetzt `"{Ue}bernehmen`" dr{ue}cken." }
        }

        'harden-runas' {
            $cfg.manager.runAs = 'LOCAL SERVICE'
            Save-Config $cfg
            $acl = Grant-ServiceRights 'LOCAL SERVICE'
            $tasks = Install-Automation $cfg
            $restart = $(if ($tasks.ok) { Restart-CaddyServer } else { @{ ok = $false; message = $tasks.message } })
            if (-not $restart.ok) {
                $cfg.manager.runAs = 'SYSTEM'
                Save-Config $cfg
                Install-Automation $cfg | Out-Null
                Restart-CaddyServer | Out-Null
                return @{ ok = $false
                          message = ('Der Start mit eingeschr{ae}nkten Rechten hat nicht geklappt. Es wurde auf SYSTEM zur{ue}ckgestellt. ' +
                                     [string]$restart.message) }
            }
            return @{ ok = $true; message = 'Caddy l{ae}uft jetzt als LOCAL SERVICE. ' + $acl.message }
        }

        'restore-runas' {
            $cfg.manager.runAs = 'SYSTEM'
            Save-Config $cfg
            Install-Automation $cfg | Out-Null
            Restart-CaddyServer | Out-Null
            return @{ ok = $true; message = 'Caddy l{ae}uft wieder unter SYSTEM.' }
        }

        'php-ini' { return (Set-PhpIni $cfg) }

        'create-roots' { return (New-MissingSiteRoots $cfg) }

        'harden-acl' {
            $r = Protect-AllInstallDirectories
            Clear-StatusCache
            return $r
        }

        default { return @{ ok = $false; message = 'Unbekannte Aktion.' } }
    }
}

# ---------------------------------------------------------------------------
#  Hauptschleife
# ---------------------------------------------------------------------------
function Start-ManagerServer {
    param($Config)
    $script:Config = $Config
    $started = Start-Listener $Config.manager.port
    if (-not $started) {
        Write-Host2 'Es konnte kein freier Port f{ue}r die Oberfl{ae}che belegt werden.' 'Red'
        return $false
    }
    $script:Listener = $started.listener
    $script:Port = $started.port
    $script:Origin = "http://127.0.0.1:$($started.port)"
    $script:CsrfToken = New-Secret 32
    $script:LastActivity = Get-Date

    $url = $script:Origin

    Write-Host ''
    Write-Host2 '  Caddy Manager l{ae}uft.' 'Green'
    Write-Host ''
    Write-Host "  $url" -ForegroundColor Cyan
    Write-Host ''
    Write-Host2 '  Ohne Anmeldung, nur von diesem Rechner aus erreichbar.' 'DarkGray'
    Write-Host2 '  Fenster schlie{ss}en oder Strg+C beendet nur die Oberfl{ae}che - der Webserver l{ae}uft weiter.' 'DarkGray'
    Write-Host ''

    # Aufgabenplanung und Firewall einmal vorab abfragen. Danach liegt alles im
    # Zwischenspeicher und die Oberflaeche ist beim ersten Aufruf sofort da,
    # statt mehrere Sekunden auf CIM zu warten.
    try { Get-Status $Config | Out-Null } catch { }

    if ($Config.manager.openBrowser) {
        try { Start-Process $url | Out-Null } catch { }
    }

    Write-Audit 'manager.start' "port=$($script:Port)"

    $pending = $null
    try {
        while ($script:Running) {
            if ($null -eq $pending) { $pending = $script:Listener.BeginGetContext($null, $null) }
            if ($pending.AsyncWaitHandle.WaitOne(500)) {
                $ctx = $null
                try { $ctx = $script:Listener.EndGetContext($pending) } catch { }
                $pending = $null
                if ($ctx) {
                    try { Invoke-Request $ctx }
                    catch {
                        try { Send-Json $ctx @{ ok = $false; message = 'Interner Fehler.' } 500 } catch { }
                    }
                }
            } else {
                $idle = $script:Config.manager.idleMinutes
                if ($idle -gt 0 -and ((Get-Date) - $script:LastActivity).TotalMinutes -ge $idle) {
                    Write-Host ''
                    Write-Host2 "  Keine Nutzung seit $idle Minuten - die Oberfl{ae}che wird beendet." 'DarkYellow'
                    Write-Host2 '  Der Webserver l{ae}uft unabh{ae}ngig davon weiter.' 'DarkGray'
                    $script:Running = $false
                }
            }
        }
    } finally {
        try { $script:Listener.Stop() } catch { }
        try { $script:Listener.Close() } catch { }
        Write-Audit 'manager.stop'
    }
    return $true
}

# ===========================================================================
#  WEBOBERFLAECHE
#  Eine Seite, alles eingebettet. Kein Nachladen im Hintergrund: aktualisiert
#  wird beim Oeffnen, nach jeder Aktion und wenn der Reiter wieder Fokus hat.
# ===========================================================================

function Get-UiHtml {
    param([string]$Nonce)
    $html = @'
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Caddy Manager</title>
<style nonce="__NONCE__">
:root{--bg:#0f1115;--p1:#171a21;--p2:#1e222b;--ln:#2b313d;--tx:#e6e8ee;--mu:#98a1b3;--dm:#6d768a;
--ac:#4d9bff;--ok:#3fbf80;--wa:#e2a53c;--ba:#e5574d;--r:8px}
@media(prefers-color-scheme:light){:root{--bg:#f4f6fa;--p1:#fff;--p2:#f7f8fc;--ln:#dde2ec;
--tx:#151922;--mu:#5c6577;--dm:#8b93a5;--ac:#1c6ee0}}
*{box-sizing:border-box}
html,body{margin:0;height:100%}
body{background:var(--bg);color:var(--tx);font:14px/1.45 "Segoe UI",system-ui,sans-serif;
display:flex;flex-direction:column;overflow:hidden}
button,input,select,textarea{font:inherit;color:inherit}
.hide{display:none!important}

header{display:flex;align-items:center;gap:8px;padding:8px 14px;background:var(--p1);
border-bottom:1px solid var(--ln);flex-wrap:wrap}
header b{font-size:14px;margin-right:6px}
.st{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:var(--mu);
padding:2px 8px;border:1px solid var(--ln);border-radius:20px;white-space:nowrap}
.d{width:7px;height:7px;border-radius:50%;background:var(--dm);flex:0 0 7px}
.d.ok{background:var(--ok)}.d.wa{background:var(--wa)}.d.ba{background:var(--ba)}
.sp{flex:1}

nav{display:flex;gap:1px;padding:0 10px;background:var(--p1);border-bottom:1px solid var(--ln);
overflow-x:auto}
nav button{background:none;border:0;border-bottom:2px solid transparent;padding:7px 12px;
cursor:pointer;color:var(--mu);white-space:nowrap;font-size:13.5px}
nav button:hover{color:var(--tx)}
nav button.on{color:var(--ac);border-bottom-color:var(--ac);font-weight:600}
nav .bg{background:var(--ba);color:#fff;border-radius:20px;font-size:10px;padding:0 5px;margin-left:5px}

main{flex:1;overflow:auto;padding:14px}
.w{max-width:940px;margin:0 auto}
h2{margin:0 0 10px;font-size:15px;font-weight:600}
.box{background:var(--p1);border:1px solid var(--ln);border-radius:var(--r);padding:12px;margin-bottom:12px}
.bar{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:12px}

button.b{background:var(--p2);border:1px solid var(--ln);border-radius:6px;padding:5px 11px;
cursor:pointer;font-size:13px;white-space:nowrap}
button.b:hover:not(:disabled){border-color:var(--ac);color:var(--ac)}
button.b:disabled{opacity:.4;cursor:not-allowed}
button.b.pri{background:var(--ac);border-color:var(--ac);color:#fff}
button.b.pri:hover{filter:brightness(1.1);color:#fff}
button.b.dn:hover{border-color:var(--ba);color:var(--ba)}
button.b.s{padding:3px 8px;font-size:12px}
button.lk{background:none;border:0;color:var(--ac);cursor:pointer;padding:0;font-size:12.5px}

.dirty{display:flex;align-items:center;gap:10px;padding:7px 14px;background:rgba(226,165,60,.14);
border-bottom:1px solid var(--ln);font-size:13px}

table{width:100%;border-collapse:collapse}
th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--dm);
padding:0 8px 6px;font-weight:600}
td{padding:7px 8px;border-top:1px solid var(--ln);font-size:13px;vertical-align:middle}
tr.off td{opacity:.4}
td.c1{width:40px}td.ce{text-align:right;white-space:nowrap}
.dom{font-weight:600}
.dom em{display:block;font-style:normal;color:var(--dm);font-size:11.5px;font-family:Consolas,monospace;
word-break:break-all}
.tg{display:inline-block;padding:1px 6px;border:1px solid var(--ln);border-radius:4px;
font-size:11px;color:var(--mu)}
.sw{position:relative;width:30px;height:17px;display:inline-block;cursor:pointer;vertical-align:middle}
.sw i{position:absolute;inset:0;background:var(--p2);border:1px solid var(--ln);border-radius:20px}
.sw i:after{content:"";position:absolute;width:11px;height:11px;border-radius:50%;background:var(--dm);
top:2px;left:2px;transition:.12s}
.sw.on i{background:var(--ac);border-color:var(--ac)}
.sw.on i:after{background:#fff;transform:translateX(13px)}

label.f{display:block;margin-bottom:9px}
label.f>span{display:block;font-size:12px;color:var(--mu);margin-bottom:3px}
input[type=text],input[type=password],input[type=number],select,textarea{width:100%;
background:var(--p2);border:1px solid var(--ln);border-radius:6px;padding:5px 8px}
input:focus,select:focus,textarea:focus{outline:0;border-color:var(--ac)}
textarea{resize:vertical;font-family:Consolas,monospace;font-size:12.5px}
.ck{display:flex;align-items:center;gap:7px;padding:3px 0;cursor:pointer;font-size:13px}
.ck input{accent-color:var(--ac);margin:0}
.g2{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0 14px}

.kv{display:flex;justify-content:space-between;gap:12px;padding:5px 0;border-top:1px solid var(--ln);
font-size:12.5px}
.kv:first-child{border-top:0}
.kv b{font-weight:400;color:var(--mu)}
.kv span{font-family:Consolas,monospace;text-align:right;word-break:break-all}

.row{display:flex;gap:9px;align-items:flex-start;padding:8px 0;border-top:1px solid var(--ln)}
.row:first-child{border-top:0}
.ic{flex:0 0 16px;height:16px;border-radius:50%;color:#fff;font-size:10px;font-weight:700;
display:flex;align-items:center;justify-content:center;margin-top:2px}
.ic.ok{background:var(--ok)}.ic.warn{background:var(--wa)}.ic.bad{background:var(--ba)}
.row .t{flex:1;min-width:0;font-size:13px}
.row .t em{display:block;font-style:normal;color:var(--mu);font-size:12px}

pre{background:#0b0d12;color:#d6dae4;border:1px solid var(--ln);border-radius:6px;padding:10px;
font:12px/1.45 Consolas,monospace;white-space:pre-wrap;word-break:break-word;max-height:60vh;
overflow:auto;margin:0}
code{font-family:Consolas,monospace;background:var(--p2);padding:0 4px;border-radius:3px;font-size:12px}

.mo{position:fixed;inset:0;background:rgba(0,0,0,.6);display:flex;align-items:flex-start;
justify-content:center;padding:24px 12px;overflow:auto;z-index:50}
.sh{background:var(--p1);border:1px solid var(--ln);border-radius:10px;width:100%;max-width:560px}
.sh h3{margin:0;font-size:14px;flex:1}
.sh .hd{display:flex;align-items:center;padding:11px 14px;border-bottom:1px solid var(--ln)}
.sh .bd{padding:14px;max-height:calc(100vh - 190px);overflow:auto}
.sh .ft{display:flex;gap:6px;padding:10px 14px;border-top:1px solid var(--ln);background:var(--p2);
border-radius:0 0 10px 10px}
.x{background:none;border:0;color:var(--mu);font-size:18px;cursor:pointer;line-height:1;padding:0 4px}
details{border-top:1px solid var(--ln);margin-top:8px;padding-top:8px}
summary{cursor:pointer;color:var(--ac);font-size:12.5px;margin-bottom:8px}
#pv{max-width:860px}

#ts{position:fixed;right:12px;bottom:12px;display:flex;flex-direction:column;gap:6px;z-index:90;
max-width:min(400px,88vw)}
.to{background:var(--p2);border:1px solid var(--ln);border-left:3px solid var(--ac);border-radius:6px;
padding:8px 11px;font-size:12.5px;box-shadow:0 6px 20px rgba(0,0,0,.3);white-space:pre-wrap}
.to.ok{border-left-color:var(--ok)}.to.bad{border-left-color:var(--ba)}
#bz{position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;
justify-content:center;flex-direction:column;gap:12px;z-index:99;color:#e6e8ee;font-size:13px}
.sn{width:30px;height:30px;border:3px solid #2b313d;border-top-color:#4d9bff;border-radius:50%;
animation:s .8s linear infinite}
@keyframes s{to{transform:rotate(360deg)}}
.mut{color:var(--mu);font-size:12.5px}
.bye{display:flex;height:100vh;align-items:center;justify-content:center;color:var(--mu)}
.nt{background:rgba(226,165,60,.12);border:1px solid rgba(226,165,60,.3);border-radius:6px;
padding:8px 10px;font-size:12.5px;margin-bottom:10px}
</style>
</head>
<body>
<header>
  <b>Caddy Manager</b>
  <span class="st"><i class="d" id="dC"></i><span id="tC">-</span></span>
  <span class="st hide" id="sP"><i class="d" id="dP"></i><span id="tP">PHP</span></span>
  <span class="st"><i class="d" id="dA"></i><span id="tA">-</span></span>
  <span class="sp"></span>
  <button class="b s" data-a="rf" title="Aktualisieren">&#8635;</button>
  <button class="b s" data-a="sv" data-x="start">Start</button>
  <button class="b s" data-a="sv" data-x="restart">Neu</button>
  <button class="b s dn" data-a="sv" data-x="stop">Stopp</button>
  <button class="b s" data-a="quit" title="Nur die Oberfl{ae}che beenden">Beenden</button>
</header>
<nav id="nv">
  <button class="on" data-v="dom">Domains</button>
  <button data-v="set">Einrichtung</button>
  <button data-v="sec">Sicherheit<span class="bg hide" id="sb">0</span></button>
  <button data-v="log">Logs</button>
  <button data-v="cf">Caddyfile</button>
  <button data-v="cfg">Einstellungen</button>
</nav>
<div class="dirty hide" id="dy">
  <span><b>Nicht {ue}bernommen.</b> Die Konfiguration weicht vom laufenden Server ab.</span>
  <span class="sp"></span>
  <button class="b s" data-a="pv">Vorschau</button>
  <button class="b s pri" data-a="ap">{Ue}bernehmen</button>
</div>
<main><div class="w" id="vw"></div></main>
<div class="mo hide" id="md"></div>
<div id="bz" class="hide"><div class="sn"></div><div id="bzm"></div></div>
<div id="ts"></div>

<script nonce="__NONCE__">
"use strict";
const CS="__CSRF__";
let S={}, C={}, view="dom", busy=0, ed=null, pw="", secLoaded=false;
const $=(s,r)=>(r||document).querySelector(s);
const $$=(s,r)=>Array.from((r||document).querySelectorAll(s));
const A=x=>Array.isArray(x)?x:(x==null||x===""?[]:[x]);
const E=s=>String(s==null?"":s).replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));

function toast(m,k){const e=document.createElement("div");e.className="to "+(k||"");e.textContent=m;
  $("#ts").appendChild(e);setTimeout(()=>e.remove(),k==="bad"?9000:4000);}
function bz(m){busy++;$("#bzm").textContent=m||"...";$("#bz").classList.remove("hide");}
function unbz(){busy=Math.max(0,busy-1);if(!busy)$("#bz").classList.add("hide");}

async function api(p,b,m){
  if(m)bz(m);
  try{
    const o={method:b===undefined?"GET":"POST",headers:{"X-Caddy-Manager-Csrf":CS}};
    if(b!==undefined){o.headers["Content-Type"]="application/json";o.body=JSON.stringify(b);}
    const r=await fetch(p,o);
    try{return await r.json();}catch(e){return{ok:false,message:"Ung{ue}ltige Antwort."};}
  }catch(e){return{ok:false,message:"Der Manager antwortet nicht."};}
  finally{if(m)unbz();}
}

/* ---------- Kopfzeile ---------- */
function head(){
  const s=S;if(!s)return;
  const set=(d,t,c,l)=>{$(d).className="d "+c;$(t).textContent=l;};
  if(!s.caddyInstalled)set("#dC","#tC","ba","nicht installiert");
  else if(s.caddyRunning)set("#dC","#tC","ok","Caddy "+s.caddyVersion);
  else set("#dC","#tC","ba","gestoppt");
  const p=$("#sP");
  if(s.phpEnabled){p.classList.remove("hide");
    const o=A(s.phpPorts).filter(x=>x.open).length,t=A(s.phpPorts).length;
    set("#dP","#tP",o===0?"ba":(o<t?"wa":"ok"),"PHP "+o+"/"+t);}
  else p.classList.add("hide");
  const au=s.taskServer&&s.taskWatchdog;
  set("#dA","#tA",au?"ok":"wa",au?"Autostart":"kein Autostart");
  $("#dy").classList.toggle("hide",!s.dirty);
}

function go(v){view=v;$$("#nv button").forEach(b=>b.classList.toggle("on",b.dataset.v===v));draw();}
function draw(){
  const e=$("#vw");
  ({dom:vDom,set:vSet,sec:vSec,log:vLog,cf:vCf,cfg:vCfg}[view]||vDom)(e);
}

/* ---------- Domains ---------- */
const TN={static:"Statisch",php:"PHP",proxy:"Proxy",redirect:"Weiterleitung",respond:"Text"};
function tgt(s){return s.type==="proxy"?s.upstream:s.type==="redirect"?s.redirectTo:
  s.type==="respond"?s.respondBody:s.root;}
function cert(d){return A(S.certificates).find(c=>c.domain===String(d).replace(/^https?:\/\//,"").split(":")[0]);}

function vDom(e){
  if(C.mode==="manual"){
    e.innerHTML=`<h2>Domains</h2><div class="nt">Manueller Modus. Die Caddyfile wird von Hand
      gepflegt. Umschalten unter <b>Caddyfile</b>.</div>`;return;}
  const rows=A(C.sites).map(s=>{
    const c=cert(s.domains[0]);
    const ct=c?(c.daysLeft<0?"abgelaufen":c.daysLeft+"d"):"-";
    return `<tr class="${s.enabled?"":"off"}">
      <td class="c1"><span class="sw ${s.enabled?"on":""}" data-a="tg" data-x="${E(s.id)}"><i></i></span></td>
      <td><span class="dom">${E(s.domains[0])}${A(s.domains).length>1?' <span class="tg">+'+(A(s.domains).length-1)+'</span>':''}
        <em>${E(tgt(s))}</em></span></td>
      <td><span class="tg">${TN[s.type]||s.type}</span></td>
      <td class="mut">${ct}</td>
      <td class="ce"><button class="b s" data-a="ed" data-x="${E(s.id)}">Bearbeiten</button>
        <button class="b s" data-a="dns" data-x="${E(s.id)}">DNS</button>
        <button class="b s dn" data-a="del" data-x="${E(s.id)}">L{oe}schen</button></td></tr>`;}).join("");
  e.innerHTML=`<div class="bar">
      <button class="b pri" data-a="new">Domain hinzuf{ue}gen</button>
      <button class="b" data-a="dnsa">DNS pr{ue}fen</button>
      <button class="b" data-a="pv">Vorschau</button>
      <span class="sp"></span>
      <button class="b" data-a="imp">Caddyfile einlesen</button></div>
    ${A(C.sites).length?`<div class="box"><table><thead><tr><th></th><th>Domain / Ziel</th>
      <th>Art</th><th>TLS</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>`
     :`<div class="box mut">Noch keine Domain.</div>`}`;
}

/* ---------- Editor ---------- */
function open2(id){
  let s=A(C.sites).find(x=>x.id===id);
  if(!s)s={id:"",enabled:true,label:"",domains:[],type:"static",root:"",upstream:"",redirectTo:"",
    redirectCode:"permanent",respondBody:"OK",respondStatus:200,encode:true,browse:false,indexFiles:"",
    securityHeaders:true,hsts:false,blockSensitive:true,accessLog:true,wwwRedirect:true,
    basicAuthUser:"",basicAuthHash:"",maxBody:"",tlsMode:"auto",tlsCert:"",tlsKey:"",handlerExtra:"",extra:""};
  ed=JSON.parse(JSON.stringify(s));pw="";sheet(!id);
}
function sheet(isNew){
  const s=ed,t=s.type;
  $("#md").classList.remove("hide");
  $("#md").innerHTML=`<div class="sh"><div class="hd"><h3>${isNew?"Neue Domain":"Domain"}</h3>
   <button class="x" data-a="cl">&times;</button></div><div class="bd">
   <label class="f"><span>Domains, eine pro Zeile</span>
     <textarea id="fD" rows="2" placeholder="beispiel.de">${E(A(s.domains).join("\n"))}</textarea></label>
   <label class="f"><span>Art</span><select id="fT">
     ${Object.keys(TN).map(k=>`<option value="${k}"${t===k?" selected":""}>${TN[k]}</option>`).join("")}
   </select></label>
   ${(t==="static"||t==="php")?`<label class="f"><span>Ordner
     <button class="lk" data-a="mk">anlegen</button></span>
     <input type="text" id="fR" value="${E(s.root)}" placeholder="C:\\caddy\\www\\beispiel.de"></label>`:""}
   ${t==="proxy"?`<label class="f"><span>Ziel</span>
     <input type="text" id="fU" value="${E(s.upstream)}" placeholder="127.0.0.1:3000"></label>`:""}
   ${t==="redirect"?`<label class="f"><span>Zieladresse</span>
     <input type="text" id="fW" value="${E(s.redirectTo)}" placeholder="https://beispiel.de"></label>
     <label class="f"><span>Art</span><select id="fWC">
       <option value="permanent"${s.redirectCode==="permanent"?" selected":""}>dauerhaft (301)</option>
       <option value="temporary"${s.redirectCode==="temporary"?" selected":""}>tempor{ae}r (302)</option>
     </select></label>`:""}
   ${t==="respond"?`<label class="f"><span>Text</span>
     <input type="text" id="fB" value="${E(s.respondBody)}"></label>
     <label class="f"><span>Status</span><input type="number" id="fS" value="${s.respondStatus}" min="100" max="599"></label>`:""}
   <div class="g2">
     <label class="ck"><input type="checkbox" id="cS"${s.securityHeaders?" checked":""}>Sicherheitskopfzeilen</label>
     ${t!=="proxy"?`<label class="ck"><input type="checkbox" id="cB"${s.blockSensitive?" checked":""}>Versteckte Dateien sperren</label>`:""}
     <label class="ck"><input type="checkbox" id="cW"${s.wwwRedirect?" checked":""}>www umleiten</label>
     <label class="ck"><input type="checkbox" id="cE"${s.encode?" checked":""}>Komprimieren</label>
     <label class="ck"><input type="checkbox" id="cL"${s.accessLog?" checked":""}>Zugriffe protokollieren</label>
     <label class="ck" title="Browser merken sich HTTPS ein Jahr lang. Schwer zur{ue}cknehmbar."><input type="checkbox" id="cH"${s.hsts?" checked":""}>HSTS</label>
     ${(t==="static"||t==="php")?`<label class="ck"><input type="checkbox" id="cV"${s.browse?" checked":""}>Verzeichnisauflistung</label>`:""}
   </div>
   <details><summary>Mehr</summary>
     <label class="f"><span>Bezeichnung</span><input type="text" id="fL" value="${E(s.label)}"></label>
     ${(t==="static"||t==="php")?`<label class="f"><span>Startdateien</span>
       <input type="text" id="fI" value="${E(s.indexFiles)}" placeholder="index.html index.php"></label>`:""}
     <label class="f"><span>Max. Upload</span><input type="text" id="fM" value="${E(s.maxBody)}" placeholder="100MB"></label>
     <label class="f"><span>Zugriffsschutz Benutzer</span><input type="text" id="fAU" value="${E(s.basicAuthUser)}"></label>
     <label class="f"><span>Passwort${s.basicAuthHash?" (gesetzt)":""}</span>
       <input type="password" id="fAP" placeholder="${s.basicAuthHash?"nur bei {Ae}nderung":"neu"}"></label>
     <label class="f"><span>Zertifikat</span><select id="fTL">
       <option value="auto"${s.tlsMode==="auto"?" selected":""}>Let's Encrypt</option>
       <option value="internal"${s.tlsMode==="internal"?" selected":""}>selbst ausgestellt</option>
       <option value="custom"${s.tlsMode==="custom"?" selected":""}>eigene Dateien</option>
     </select></label>
     <div id="tf" class="${s.tlsMode==="custom"?"":"hide"}">
       <label class="f"><span>.crt</span><input type="text" id="fTC" value="${E(s.tlsCert)}"></label>
       <label class="f"><span>.key</span><input type="text" id="fTK" value="${E(s.tlsKey)}"></label></div>
     ${(t==="proxy"||t==="php")?`<label class="f"><span>Zeilen im ${t==="proxy"?"reverse_proxy":"php_fastcgi"}-Block</span>
       <textarea id="fHX" rows="2" placeholder="header_up Host {upstream_hostport}">${E(s.handlerExtra||"")}</textarea></label>`:""}
     <label class="f"><span>Zus{ae}tzliche Zeilen im Block</span>
       <textarea id="fX" rows="3" placeholder="handle_path /api/* {&#10;  reverse_proxy 127.0.0.1:1234&#10;}">${E(s.extra)}</textarea></label>
   </details></div>
   <div class="ft"><button class="b pri" data-a="sa">Speichern</button>
     <button class="b" data-a="cl">Abbrechen</button></div></div>`;
  const p=$("#fAP");if(p&&pw)p.value=pw;
  const tl=$("#fTL");if(tl)tl.addEventListener("change",()=>$("#tf").classList.toggle("hide",tl.value!=="custom"));
  const ty=$("#fT");if(ty)ty.addEventListener("change",()=>{grab();ed.type=ty.value;sheet(!ed.id);});
}
function grab(){
  const s=ed,g=i=>{const e=$(i);return e?e.value:undefined},b=i=>{const e=$(i);return e?e.checked:undefined};
  const d=g("#fD");if(d!==undefined)s.domains=d.split(/[\n,;]+/).map(x=>x.trim()).filter(Boolean);
  const m={"#fR":"root","#fU":"upstream","#fW":"redirectTo","#fWC":"redirectCode","#fB":"respondBody",
    "#fL":"label","#fI":"indexFiles","#fM":"maxBody","#fAU":"basicAuthUser","#fTL":"tlsMode",
    "#fTC":"tlsCert","#fTK":"tlsKey","#fHX":"handlerExtra","#fX":"extra"};
  for(const k in m){const v=g(k);if(v!==undefined)s[m[k]]=v.trim?v.trim():v;}
  if(g("#fS")!==undefined)s.respondStatus=parseInt(g("#fS"),10)||200;
  const cm={"#cS":"securityHeaders","#cB":"blockSensitive","#cW":"wwwRedirect","#cE":"encode",
    "#cL":"accessLog","#cH":"hsts","#cV":"browse"};
  for(const k in cm){const v=b(k);if(v!==undefined)s[cm[k]]=v;}
  const p=$("#fAP");if(p)pw=p.value;
}
async function save(){
  grab();
  if(ed.basicAuthUser&&pw){
    const h=await api("/api/hash",{password:pw},"Passwort...");
    if(!h.ok){toast(h.message,"bad");return;} ed.basicAuthHash=h.hash;}
  if(!ed.basicAuthUser)ed.basicAuthHash="";
  if(ed.basicAuthUser&&!ed.basicAuthHash){toast("Passwort fehlt.","bad");return;}
  const r=await api("/api/site/save",ed,"Speichern...");
  if(!r.ok){toast(r.message,"bad");return;}
  if(r.config)C=r.config;
  close2();toast(r.message,"ok");await refresh();draw();
}
function close2(){$("#md").classList.add("hide");$("#md").innerHTML="";ed=null;pw="";}

/* ---------- Einrichtung ---------- */
function ln(t,ok,btn,a,x,sub){return `<div class="row"><div class="ic ${ok?"ok":"warn"}">${ok?"+":"!"}</div>
  <div class="t">${t}${sub?`<em>${sub}</em>`:""}</div>
  ${btn?`<button class="b s" data-a="${a}" data-x="${x}">${btn}</button>`:""}</div>`;}
function vSet(e){
  const s=S;
  e.innerHTML=`<h2>Einrichtung</h2><div class="box">
    ${ln("Verzeichnisse",true,"Pr{ue}fen","su","dirs",s.root)}
    ${ln("Caddy "+(s.caddyInstalled?s.caddyVersion:""),s.caddyInstalled,
        s.caddyInstalled?"Updates":"Installieren","su",s.caddyInstalled?"caddy-update":"caddy")}
    ${ln("Autostart und Watchdog",!!(s.taskServer&&s.taskWatchdog),"Einrichten","su","tasks")}
    ${ln("Firewall Port 80/443",s.firewallRules>0,"Freigeben","su","firewall",
        s.firewallRules>0?s.firewallRules+" Regeln":"")}
    ${ln("Dateirechte",s.aclOk===true,"Absichern","fx","harden-acl",
        s.aclWeak&&s.aclWeak.length?"schreibbar f{ue}r: "+E(A(s.aclWeak).join(", ")):"")}
    ${ln("PHP",!!(s.phpInstalled&&C.php.enabled),
        (s.phpInstalled&&C.php.enabled)?"Aktualisieren":"Installieren","su","php",
        s.phpInstalled?(C.php.enabled?C.php.poolSize+" Prozesse":"abgeschaltet"):"")}
  </div>
  <div class="box"><label class="ck"><input type="checkbox" id="sp"${C.php.enabled?" checked":""}>PHP mit einrichten</label>
    <div class="bar"><button class="b pri" data-a="su" data-x="all">Alles einrichten</button></div></div>
  <div class="box"><div class="bar">
    <button class="b dn" data-a="su" data-x="uninstall">Automatik entfernen</button>
    ${C.php.enabled?`<button class="b" data-a="su" data-x="php-off">PHP abschalten</button>`:""}
  </div></div>`;
}

/* ---------- Sicherheit ---------- */
async function vSec(e){
  e.innerHTML=`<h2>Sicherheit</h2><div class="box mut">wird gepr{ue}ft...</div>`;
  const r=await api("/api/security");
  const f=A(r.findings);badge(f);secLoaded=true;
  const o={bad:0,warn:1,ok:2};f.sort((a,b)=>o[a.level]-o[b.level]);
  e.innerHTML=`<h2>Sicherheit</h2><div class="box">${f.map(x=>`<div class="row">
    <div class="ic ${x.level}">${x.level==="ok"?"+":x.level==="warn"?"!":"x"}</div>
    <div class="t">${E(x.title)}<em>${E(x.detail)}</em></div>
    ${x.fix?`<button class="b s" data-a="fx" data-x="${E(x.fix)}">${E(x.fixLabel||"Beheben")}</button>`:""}
    </div>`).join("")}</div>
    <div class="box"><div class="kv"><b>Oberfl{ae}che</b><span>127.0.0.1, ohne Anmeldung</span></div>
    <div class="kv"><b>Schutz vor fremden Seiten</b><span>Kopfzeile + Herkunft + Host</span></div>
    <div class="kv"><b>Beendet sich nach</b><span>${C.manager.idleMinutes} min</span></div></div>`;
}
function badge(f){const n=A(f).filter(x=>x.level!=="ok").length,b=$("#sb");
  b.textContent=n;b.classList.toggle("hide",n===0);}

/* ---------- Logs ---------- */
async function vLog(e){
  const r=await api("/api/logs");const fs=A(r.files);
  e.innerHTML=`<h2>Logs</h2><div class="bar">
    <select id="lp">${fs.map(f=>`<option value="${E(f.name)}">${E(f.name)} (${f.sizeKb} KB)</option>`).join("")}</select>
    <button class="b" data-a="ls">Anzeigen</button></div><pre id="lo">-</pre>`;
  if(fs.length){const p=fs.find(x=>x.name==="caddy.log")||fs[0];$("#lp").value=p.name;showLog();}
}
async function showLog(){
  const p=$("#lp");if(!p)return;
  const r=await api("/api/log?name="+encodeURIComponent(p.value)+"&lines=400");
  let t=r.text||"(leer)";
  if(t.trim().startsWith("{"))t=t.split("\n").map(l=>{try{const j=JSON.parse(l);
    const d=j.ts?new Date(j.ts*1000).toLocaleString("de-DE"):"";
    if(j.request)return d+"  "+(j.status||"")+"  "+(j.request.method||"")+"  "+(j.request.host||"")+(j.request.uri||"");
    return d+"  ["+(j.level||"")+"] "+(j.msg||l);}catch(e){return l;}}).join("\n");
  $("#lo").textContent=t;$("#lo").scrollTop=$("#lo").scrollHeight;
}

/* ---------- Caddyfile ---------- */
async function vCf(e){
  const [lv,bk]=await Promise.all([api("/api/caddyfile"),api("/api/backups")]);
  e.innerHTML=`<h2>Caddyfile</h2>
   <div class="nt">${C.mode==="managed"
     ?`Wird aus der Domainliste erzeugt. {Ae}nderungen hier gehen beim n{ae}chsten {Ue}bernehmen verloren.
        <button class="lk" data-a="md" data-x="manual">Auf manuell umschalten</button>`
     :`Manueller Modus.
        <button class="lk" data-a="md" data-x="managed">Zur{ue}ck auf verwaltet</button>`}</div>
   <div class="box"><textarea id="cx" rows="18">${E(lv.text||"")}</textarea>
   <div class="bar"><button class="b" data-a="cv">Pr{ue}fen</button>
     <button class="b" data-a="cfm">Formatieren</button>
     <button class="b pri" data-a="cs">Speichern und {ue}bernehmen</button></div>
   <pre id="co" class="hide"></pre></div>
   <div class="box"><h2>Sicherungen</h2>${A(bk.backups).length?A(bk.backups).map(b=>
     `<div class="kv"><b>${E(b.modified)}</b><span><button class="b s" data-a="rs" data-x="${E(b.name)}">Zur{ue}ck</button></span></div>`).join("")
     :`<div class="mut">keine</div>`}</div>
   <div class="box"><h2>Passworthash</h2><div class="bar">
     <input type="password" id="hp" placeholder="Passwort"><button class="b" data-a="hs">Erzeugen</button></div>
     <pre id="ho" class="hide"></pre></div>`;
}

/* ---------- Einstellungen ---------- */
function vCfg(e){
  e.innerHTML=`<h2>Einstellungen</h2>
  <div class="box">
    <label class="f"><span>E-Mail f{ue}r Let's Encrypt</span><input type="text" id="gE" value="${E(C.global.email)}"></label>
    <div class="g2">
      <label class="f"><span>Protokollstufe</span><select id="gL">
        ${["ERROR","WARN","INFO","DEBUG"].map(l=>`<option${C.global.logLevel===l?" selected":""}>${l}</option>`).join("")}
      </select></label>
      <label class="f"><span>Gr{oe}sse je Datei</span><input type="text" id="gR" value="${E(C.global.rollSize)}"></label>
      <label class="f"><span>Dateien aufbewahren</span><input type="number" id="gK" value="${C.global.rollKeep}" min="1" max="100"></label>
      <label class="f"><span>Beenden nach (min, 0 = nie)</span><input type="number" id="gI" value="${C.manager.idleMinutes}" min="0" max="1440"></label>
    </div>
    <label class="ck"><input type="checkbox" id="gB"${C.manager.openBrowser?" checked":""}>Browser beim Start {oe}ffnen</label>
    <label class="ck" title="Nur einschalten, wenn ein Proxy oder Loadbalancer davorsteht. Sonst kann jeder im lokalen Netz die Client-Adresse f{ae}lschen."><input type="checkbox" id="gT"${C.global.trustedProxies?" checked":""}>Hinter einem Proxy (X-Forwarded-For vertrauen)</label>
  </div>
  <div class="box"><h2>PHP</h2><div class="g2">
    <label class="ck"><input type="checkbox" id="pE"${C.php.enabled?" checked":""}>PHP verwenden</label>
    <label class="ck" title="exec, shell_exec, system und {ae}hnliche sperren"><input type="checkbox" id="pR"${C.php.disableRiskyFunctions?" checked":""}>Riskante Funktionen sperren</label>
    <label class="f"><span>Parallele Prozesse</span><input type="number" id="pP" value="${C.php.poolSize}" min="1" max="16"></label>
  </div></div>
  <div class="box"><h2>Dateirechte</h2>
    <label class="f"><span>Konto darf ${E(S.root||"")}\\www bearbeiten</span>
      <input type="text" id="ac" placeholder="DOMAENE\\benutzer"></label>
    <button class="b s" data-a="gw">Freigeben</button></div>
  <div class="box"><h2>Caddyfile-Zusatz</h2>
    <label class="f"><span>Globale Zeilen</span><textarea id="gX" rows="3">${E(C.global.extra)}</textarea></label>
    <label class="f"><span>Bausteine und Importe</span><textarea id="gS" rows="4">${E(C.global.snippets||"")}</textarea></label></div>
  <div class="bar"><button class="b pri" data-a="gs">Speichern</button></div>`;
}

/* ---------- Aktionen ---------- */
async function refresh(){
  const r=await api("/api/state");
  if(r&&r.ok){S=r.status;C=r.config;head();}
  return r;
}
async function apply(){
  const r=await api("/api/apply",{},"{Ue}bernehmen...");
  toast(r.ok?r.message:(r.message||"")+(r.validation?"\n\n"+r.validation:""),r.ok?"ok":"bad");
  await refresh();draw();
}
async function preview(){
  const r=await api("/api/preview");
  $("#md").classList.remove("hide");
  $("#md").innerHTML=`<div class="sh" id="pv"><div class="hd"><h3>Erzeugte Caddyfile</h3>
    <button class="x" data-a="cl">&times;</button></div><div class="bd"><pre>${E(r.text||"")}</pre></div>
    <div class="ft"><button class="b pri" data-a="ap">{Ue}bernehmen</button>
    <button class="b" data-a="cl">Schliessen</button></div></div>`;
}
async function dns(id){
  const r=await api("/api/dns",id?{id}:{},"DNS...");
  if(!r.ok){toast(r.message,"bad");return;}
  $("#md").classList.remove("hide");
  $("#md").innerHTML=`<div class="sh"><div class="hd"><h3>DNS</h3><button class="x" data-a="cl">&times;</button></div>
    <div class="bd"><div class="kv"><b>Diese Adresse</b><span>${E(r.publicIp||"unbekannt")}</span></div>
    ${A(r.results).map(x=>`<div class="row"><div class="ic ${x.status==="ok"?"ok":x.status==="bad"?"bad":"warn"}">${x.status==="ok"?"+":x.status==="bad"?"x":"!"}</div>
      <div class="t">${E(x.domain)}<em>${E(x.message)}${A(x.addresses).length?" ("+E(A(x.addresses).join(", "))+")":""}</em></div></div>`).join("")}
    </div><div class="ft"><button class="b" data-a="cl">Schliessen</button></div></div>`;
}
async function setup(x){
  const L={all:"Vollst{ae}ndige Einrichtung, das dauert...",caddy:"Caddy wird geladen...",
    "caddy-update":"Update wird gesucht...",php:"PHP wird eingerichtet...",tasks:"Aufgaben...",
    firewall:"Firewall...",dirs:"Verzeichnisse...",uninstall:"Entfernen...","php-off":"PHP aus..."};
  const b={step:x};
  if(x==="all"){const p=$("#sp");b.php=p?p.checked:false;}
  const r=await api("/api/setup",b,L[x]||"...");
  toast(A(r.notes).length?A(r.notes).join("\n"):(r.message||""),r.ok?"ok":"bad");
  await refresh();draw();
}

document.addEventListener("click",async ev=>{
  const n=ev.target.closest("#nv button");if(n){go(n.dataset.v);return;}
  const t=ev.target.closest("[data-a]");
  if(!t){if(ev.target.id==="md")close2();return;}
  const a=t.dataset.a,x=t.dataset.x;
  if(a==="cl"){close2();return;}
  if(a==="rf"){await refresh();draw();return;}
  if(a==="ap"){close2();await apply();return;}
  if(a==="pv"){await preview();return;}
  if(a==="new"){open2(null);return;}
  if(a==="ed"){open2(x);return;}
  if(a==="sa"){await save();return;}
  if(a==="dns"){await dns(x);return;}
  if(a==="dnsa"){await dns(null);return;}
  if(a==="su"){await setup(x);return;}
  if(a==="mk"){grab();let p=ed.root;
    if(!p&&ed.domains.length)p=(S.root||"C:\\caddy")+"\\www\\"+ed.domains[0].replace(/^https?:\/\//,"").split(":")[0];
    const r=await api("/api/folder",{path:p},"Ordner...");toast(r.message,r.ok?"ok":"bad");
    if(r.ok){ed.root=p;sheet(!ed.id);}return;}
  if(a==="tg"){const r=await api("/api/site/toggle",{id:x});if(r.config)C=r.config;await refresh();draw();return;}
  if(a==="del"){const s=A(C.sites).find(y=>y.id===x);
    if(!confirm("\""+(s?s.domains[0]:"")+"\" l{oe}schen? Die Dateien bleiben.")) return;
    const r=await api("/api/site/delete",{id:x},"...");if(r.config)C=r.config;
    toast(r.message,r.ok?"ok":"bad");await refresh();draw();return;}
  if(a==="sv"){const r=await api("/api/service",{action:x},"...");toast(r.message,r.ok?"ok":"bad");
    await refresh();draw();return;}
  if(a==="fx"){
    const FM={"harden-acl":"Dateirechte werden gesetzt. Bei vielen Dateien dauert das etwas...",
      "setup-all":"Vollst{ae}ndige Einrichtung...","setup-tasks":"Aufgaben...",
      "setup-firewall":"Firewall...","setup-caddy-update":"Caddy wird aktualisiert...",
      "harden-runas":"Wird umgestellt...","create-roots":"Verzeichnisse werden angelegt...",
      "harden-sites":"Wird angepasst...","php-ini":"php.ini wird geschrieben..."};
    const r=await api("/api/fix",{id:x},FM[x]||"...");toast(r.message,r.ok?"ok":"bad");
    await refresh();if(view==="sec")vSec($("#vw"));else draw();return;}
  if(a==="imp"){if(!confirm("Caddyfile einlesen? Die Domainliste wird ersetzt."))return;
    const r=await api("/api/import",{},"Einlesen...");if(r.config)C=r.config;
    toast((r.message||"")+(A(r.skipped).length?"\nNicht erkannt: "+A(r.skipped).join(", "):""),r.ok?"ok":"bad");
    await refresh();draw();return;}
  if(a==="md"){if(x==="manual"&&!confirm("Auf manuell umschalten?"))return;
    const r=await api("/api/mode",{mode:x},"...");if(r.config)C=r.config;
    toast(r.message,r.ok?"ok":"bad");await refresh();draw();return;}
  if(a==="ls"){await showLog();return;}
  if(a==="cv"){const r=await api("/api/validate",{text:$("#cx").value},"Pr{ue}fen...");
    const o=$("#co");o.classList.remove("hide");o.textContent=(r.message||"")+"\n"+(r.validation||"");return;}
  if(a==="cfm"){const r=await api("/api/format",{text:$("#cx").value},"...");
    if(r.ok){$("#cx").value=r.text;toast("Formatiert.","ok");}else toast(r.message,"bad");return;}
  if(a==="cs"){const r=await api("/api/caddyfile",{text:$("#cx").value},"...");
    const o=$("#co");o.classList.remove("hide");o.textContent=(r.message||"")+(r.validation?"\n"+r.validation:"");
    toast(r.message,r.ok?"ok":"bad");await refresh();return;}
  if(a==="rs"){if(!confirm("Diese Sicherung wiederherstellen?"))return;
    const r=await api("/api/restore",{name:x},"...");toast(r.message,r.ok?"ok":"bad");
    await refresh();draw();return;}
  if(a==="hs"){const r=await api("/api/hash",{password:$("#hp").value},"...");
    const o=$("#ho");o.classList.remove("hide");o.textContent=r.ok?r.hash:(r.message||"");
    if(r.ok)$("#hp").value="";return;}
  if(a==="gw"){const r=await api("/api/acl",{account:$("#ac").value},
      "Rechte werden gesetzt. Bei vielen Dateien dauert das etwas...");
    toast(r.message,r.ok?"ok":"bad");return;}
  if(a==="gs"){const r=await api("/api/settings",{email:$("#gE").value.trim(),logLevel:$("#gL").value,
      rollSize:$("#gR").value.trim(),rollKeep:$("#gK").value,globalExtra:$("#gX").value,
      snippets:$("#gS").value,trustedProxies:$("#gT").checked,
      phpEnabled:$("#pE").checked,phpPoolSize:$("#pP").value,
      phpDisableRisky:$("#pR").checked,idleMinutes:$("#gI").value,openBrowser:$("#gB").checked},"...");
    if(r.config)C=r.config;toast(r.message,r.ok?"ok":"bad");await refresh();return;}
  if(a==="quit"){if(!confirm("Oberfl{ae}che beenden? Der Webserver l{ae}uft weiter."))return;
    await api("/api/quit",{});
    document.body.innerHTML="<div class='bye'>Beendet. Der Webserver l{ae}uft weiter.</div>";return;}
});
document.addEventListener("keydown",e=>{if(e.key==="Escape"&&!$("#md").classList.contains("hide"))close2();});
document.addEventListener("visibilitychange",()=>{if(!document.hidden&&!busy)refresh().then(head);});

(async()=>{await refresh();draw();})();
</script>
</body>
</html>
'@
    $html = $html.Replace('__NONCE__', $Nonce).Replace('__CSRF__', $script:CsrfToken)
    return (T $html)
}

# ===========================================================================
#  START
# ===========================================================================

try { [Console]::OutputEncoding = [Text.Encoding]::UTF8 } catch { }
try { $Host.UI.RawUI.WindowTitle = 'Caddy Manager' } catch { }
$ProgressPreference = 'SilentlyContinue'

Write-Host ''
Write-Host '  ============================================================' -ForegroundColor DarkGray
Write-Host '    CADDY MANAGER' -ForegroundColor White
Write-Host2 '    Webserver einrichten und verwalten - ohne Handarbeit' 'DarkGray'
Write-Host '  ============================================================' -ForegroundColor DarkGray
Write-Host ''

if (-not (Test-IsAdmin)) {
    Write-Host2 '  Achtung: dieses Fenster l{ae}uft ohne Administratorrechte.' 'Yellow'
    Write-Host2 '  Geplante Aufgaben, Firewallregeln und der Zugriff auf C:\ funktionieren dann nicht.' 'DarkGray'
    Write-Host2 '  Bitte caddy-manager.bat schlie{ss}en und erneut starten.' 'DarkGray'
    Write-Host ''
}

# --- Grundverzeichnisse, damit Konfiguration und Protokoll ablegbar sind ---
try {
    Initialize-Directories | Out-Null
} catch {
    Write-Host2 ("  Verzeichnisse konnten nicht angelegt werden: " + $_.Exception.Message) 'Red'
    Write-Host2 '  L{ae}uft das Fenster wirklich als Administrator?' 'DarkGray'
    exit 1
}

# --- Nur ein Manager gleichzeitig ---
#  Zwei Fenster wuerden sich beim Speichern gegenseitig ueberschreiben, weil
#  jedes seinen eigenen Stand im Speicher haelt.
$LockFile = $Paths.Lock
try {
    if (Test-Path -LiteralPath $LockFile) {
        $txt = ([string](Get-Content -LiteralPath $LockFile -Raw)).Trim()
        if ($txt -match '^\d+$' -and [int]$txt -ne $PID) {
            $other = Get-Process -Id ([int]$txt) -ErrorAction SilentlyContinue
            if ($other -and $other.ProcessName -match '(?i)powershell|pwsh') {
                Write-Host2 ("  Es l{ae}uft bereits ein Caddy Manager (Prozess " + $txt + ").") 'Yellow'
                Write-Host2 '  Zwei gleichzeitig wuerden sich beim Speichern gegenseitig ueberschreiben.' 'DarkGray'
                Write-Host2 '  Bitte das andere Fenster verwenden oder schliessen.' 'DarkGray'
                Write-Host ''
                exit 1
            }
        }
    }
    Write-TextFile $LockFile ([string]$PID)
} catch { }

# --- Vorhandene Zertifikate uebernehmen, damit nichts neu beantragt wird ---
try {
    $mig = Import-CertificateStore
    if ($mig.changed) { Write-Host2 ('  ' + $mig.message) 'Green'; Write-Host '' }
} catch { }

# --- Konfiguration laden, beim ersten Start vorhandene Caddyfile uebernehmen ---
$firstRun = -not (Test-Path -LiteralPath $Paths.State)
$Config = Read-Config

if ($firstRun) {
    $live = Get-LiveCaddyfile
    if ($live.Trim()) {
        try {
            $imp = Import-Caddyfile $live
            $Config = $imp.config
            Write-Host2 ("  Bestehende Caddyfile gefunden: " + $imp.imported + " Eintr{ae}ge {ue}bernommen.") 'Green'
            if (@($imp.skipped).Count -gt 0) {
                Write-Host2 ("  Nicht eindeutig erkannt: " + ((@($imp.skipped)) -join ', ')) 'DarkYellow'
            }
            Write-Host ''
        } catch {
            Write-Host2 ("  Die vorhandene Caddyfile konnte nicht gelesen werden: " + $_.Exception.Message) 'DarkYellow'
        }
    }
    try { Save-Config $Config } catch { }
    Write-Audit 'manager.firstrun'
}

$ver = Get-CaddyVersionInfo
if (-not $ver.installed) {
    Write-Host2 '  Caddy ist noch nicht installiert. Die Oberfl{ae}che f{ue}hrt durch die Einrichtung.' 'DarkYellow'
    Write-Host ''
}

try {
    if (-not (Start-ManagerServer $Config)) { exit 1 }
} finally {
    try { Remove-Item -LiteralPath $LockFile -Force -ErrorAction SilentlyContinue } catch { }
}

Write-Host ''
Write-Host2 '  Oberfl{ae}che beendet. Der Webserver l{ae}uft weiter.' 'DarkGray'
Write-Host ''
