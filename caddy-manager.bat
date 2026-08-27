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
fltmc >nul 2>&1
if "%errorlevel%"=="0" goto :elevated

echo.
echo   Caddy Manager braucht Administratorrechte.
echo   Geplante Aufgaben, Firewallregeln und C:\caddy gehen sonst nicht.
echo.
echo   Windows fragt gleich nach.
echo.
powershell -NoProfile -ExecutionPolicy Bypass -Command "try { Start-Process -FilePath $env:SELF -Verb RunAs } catch { Write-Host '  Abgebrochen. Ohne Administratorrechte laeuft die Einrichtung nicht.' -ForegroundColor Yellow; Start-Sleep -Seconds 4 }"
endlocal
exit /b

:elevated
where powershell.exe >nul 2>&1
if not "%errorlevel%"=="0" goto :nopowershell

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
try {
    $proto = [Net.SecurityProtocolType]::Tls12
    try { $proto = $proto -bor [Net.SecurityProtocolType]::Tls13 } catch { }
    [Net.ServicePointManager]::SecurityProtocol = $proto
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
            Set-Content -LiteralPath $Paths.Audit -Value $keep -Encoding UTF8
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
        [string]$WorkDir = $null
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
    if ($WorkDir) { $psi.WorkingDirectory = $WorkDir }
    if ($Environment) {
        foreach ($k in $Environment.Keys) { $psi.EnvironmentVariables[$k] = [string]$Environment[$k] }
    }
    $proc = New-Object System.Diagnostics.Process
    $proc.StartInfo = $psi
    try {
        [void]$proc.Start()
        $tOut = $proc.StandardOutput.ReadToEndAsync()
        $tErr = $proc.StandardError.ReadToEndAsync()
        if ($proc.WaitForExit($TimeoutSec * 1000)) {
            $result.code = $proc.ExitCode
            $result.ok = ($proc.ExitCode -eq 0)
        } else {
            $result.timedOut = $true
            try { $proc.Kill() } catch { }
        }
        try { $result.stdout = $tOut.Result } catch { }
        try { $result.stderr = $tErr.Result } catch { }
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
    Set-Content -LiteralPath $Paths.State -Value $json -Encoding UTF8
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
    Add-Line $Sb ($Indent + 1) ('output file ' + (ConvertTo-CaddyPath ($Paths.Logs + '\' + $FileName)) + ' {')
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
        Add-Line $Sb 1 ('tls ' + (ConvertTo-CaddyPath $Site.tlsCert) + ' ' + (ConvertTo-CaddyPath $Site.tlsKey))
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
        Add-Line $Sb 1 ('respond "' + ($Site.respondBody -replace '"', '\"') + '" ' + $Site.respondStatus)
        if ($Site.accessLog) { Add-LogDirective $Sb 1 $Config ((Get-HostLabel $Site.domains[0]) + '-access.log') }
        Add-RawBlock $Sb 1 $Site.extra
        Add-Line $Sb 0 '}'
        Add-Line $Sb 0 ''
        Add-WwwRedirect $Sb $Site
        return
    }

    if ($Site.type -eq 'static' -or $Site.type -eq 'php') {
        Add-Line $Sb 1 ('root * ' + (ConvertTo-CaddyPath $Site.root))
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
    $handlers = New-Object System.Collections.ArrayList
    if ($Site.type -eq 'php') {
        [void]$handlers.Add('php_fastcgi ' + (Get-PhpUpstreams $Config))
    } elseif ($Site.type -eq 'proxy') {
        [void]$handlers.Add('reverse_proxy ' + $Site.upstream + ' {')
        [void]$handlers.Add("`theader_up X-Real-IP {remote_host}")
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

    if ($Site.blockSensitive -and $Site.type -ne 'proxy') {
        Add-Line $Sb 1 '@geschuetzt {'
        Add-Line $Sb 2 'not path /.well-known/*'
        Add-Line $Sb 2 'path */.* *.env *.log *.sql *.bak *.ini *.sqlite /composer.json /composer.lock /package-lock.json'
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
    Add-Line $sb 1 ('storage file_system ' + (ConvertTo-CaddyPath $Paths.Data))
    Add-Line $sb 1 'log {'
    Add-Line $sb 2 ('output file ' + (ConvertTo-CaddyPath ($Paths.Logs + '\caddy.log')) + ' {')
    Add-Line $sb 3 ('roll_size ' + $Config.global.rollSize)
    Add-Line $sb 3 ('roll_keep ' + $Config.global.rollKeep)
    Add-Line $sb 2 '}'
    Add-Line $sb 2 'format json'
    Add-Line $sb 2 ('level ' + $Config.global.logLevel)
    Add-Line $sb 1 '}'
    # Nur ergaenzen, wenn nicht schon ein eigener servers-Block uebernommen wurde
    if ((Test-CaddyAtLeast 2 7) -and ($Config.global.extra -notmatch '(?m)^\s*servers\b')) {
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
                    foreach ($ln in ($d.body -replace "`r`n", "`n").Split("`n")) {
                        if ($ln.Trim()) { [void]$ExtraLines.Add("`t" + $ln.Trim()) }
                    }
                    [void]$ExtraLines.Add('}')
                } else {
                    [void]$ExtraLines.Add($d.head)
                }
            }
        }
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
            $inner = @(($st.body -replace "`r`n", "`n").Split("`n") |
                       Where-Object { $_.Trim() } | ForEach-Object { "`t" + $_.Trim() })
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
                    'storage' { }
                    'log'     { }
                    default {
                        if ($null -eq $g.body) {
                            $cfg.global.extra = ($cfg.global.extra + "`n" + $g.head).Trim()
                        } else {
                            $indented = @(($g.body -replace "`r`n", "`n").Split("`n") |
                                          Where-Object { $_.Trim() } |
                                          ForEach-Object { "`t" + $_.Trim() })
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
            $base = Get-Content -LiteralPath $Paths.PhpIni -Raw
            Backup-File -Path $Paths.PhpIni -Prefix 'php.ini' | Out-Null
        } else {
            $prod = Join-Path $Paths.Php 'php.ini-production'
            if (Test-Path -LiteralPath $prod) { $base = Get-Content -LiteralPath $prod -Raw }
        }
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
    try { return (New-ScheduledTaskSettingsSet @args2) }
    catch { return (New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable) }
}

function Register-Task {
    param([string]$Name, [string]$Execute, [string]$Argument, $Triggers, [string]$RunAs, [string]$Description = '')
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
    $t = New-ScheduledTaskTrigger -Once -At $start
    try {
        $t.Repetition.Interval = 'PT' + $Minutes + 'M'
        $t.Repetition.StopAtDurationEnd = $false
    } catch { }
    return $t
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
    Set-Content -LiteralPath $Paths.Watchdog -Value ($lines -join "`r`n") -Encoding UTF8
}

function Remove-LegacyTasks {
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
        Register-Task -Name $TaskWatch -Execute 'powershell.exe' `
            -Argument ('-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $Paths.Watchdog + '"') `
            -Triggers @((New-StartupTrigger), (New-RepeatTrigger 3)) -RunAs 'SYSTEM' `
            -Description 'Pr{ue}ft alle 3 Minuten, ob Caddy und PHP laufen.'
        [void]$notes.Add('Watchdog alle 3 Minuten eingerichtet')

        Write-Audit 'tasks.install' ("runAs=$runAs php=$($Config.php.enabled)")
        return @{ ok = $true; message = 'Automatischer Betrieb eingerichtet.'; notes = @($notes.ToArray()) }
    } catch {
        return @{ ok = $false; message = "Aufgaben konnten nicht eingerichtet werden: $($_.Exception.Message)"; notes = @($notes.ToArray()) }
    }
}

function Uninstall-Automation {
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
        return @{ exists = $true; state = [string]$t.State; enabled = ($t.State -ne 'Disabled') }
    } catch { return $null }
}

function Start-CaddyServer {
    Clear-StatusCache
    $t = Get-TaskState $TaskServer
    if ($t) {
        try {
            Start-ScheduledTask -TaskPath $TaskFolder -TaskName $TaskServer -ErrorAction Stop
            Start-Sleep -Milliseconds 1200
            if (Get-CaddyProcess) { return @{ ok = $true; message = 'Caddy gestartet.' } }
        } catch { }
    }
    if (-not (Test-Path -LiteralPath $Paths.Exe)) { return @{ ok = $false; message = 'Caddy ist noch nicht installiert.' } }
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
    Set-Content -LiteralPath $Paths.Staging -Value $NewText -Encoding UTF8
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
    if (Test-Path -LiteralPath $Paths.Config) {
        try { return (Get-Content -LiteralPath $Paths.Config -Raw -Encoding UTF8) } catch { return '' }
    }
    return ''
}

function Test-ConfigDirty {
    param($Config)
    if ($Config.mode -eq 'manual') { return $false }
    $gen = Build-Caddyfile $Config
    $live = Get-LiveCaddyfile
    # Kopfzeile mit Zeitstempel beim Vergleich ausblenden
    $strip = {
        param($t)
        return ((($t -replace "`r`n", "`n").Split("`n") | Where-Object { $_ -notmatch '^\s*#' }) -join "`n").Trim()
    }
    return ((& $strip $gen) -ne (& $strip $live))
}

# ---------------------------------------------------------------------------
#  Zertifikate
# ---------------------------------------------------------------------------
$script:CertCache = $null
$script:CertCacheAt = [datetime]::MinValue
$script:PortCache = @{}

function Get-CachedPortOwner {
    param([int]$Port)
    $key = [string]$Port
    if ($script:PortCache.ContainsKey($key)) {
        $e = $script:PortCache[$key]
        if (((Get-Date) - $e.at).TotalSeconds -lt 20) { return $e.value }
    }
    $v = Get-PortOwner $Port
    $script:PortCache[$key] = @{ at = (Get-Date); value = $v }
    return $v
}

$script:FwCache = $null
$script:FwCacheAt = [datetime]::MinValue

function Get-CachedFirewallCount {
    if ($null -ne $script:FwCache -and ((Get-Date) - $script:FwCacheAt).TotalSeconds -lt 60) {
        return $script:FwCache
    }
    $n = 0
    try { $n = @(Get-NetFirewallRule -DisplayName 'Caddy HTTP*' -ErrorAction SilentlyContinue).Count } catch { $n = -1 }
    $script:FwCache = $n
    $script:FwCacheAt = Get-Date
    return $n
}

function Clear-StatusCache {
    $script:CertCache = $null
    $script:CertCacheAt = [datetime]::MinValue
    $script:PortCache = @{}
    $script:FwCache = $null
    $script:FwCacheAt = [datetime]::MinValue
}

function Get-CachedCertificates {
    if ($null -ne $script:CertCache -and ((Get-Date) - $script:CertCacheAt).TotalSeconds -lt 90) {
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
        foreach ($c in @(($Paths.Data + '\certificates'), ($Paths.Data + '\caddy\certificates'))) {
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
        caddyUptime    = $uptime
        phpInstalled   = (Test-Path -LiteralPath $Paths.PhpExe)
        phpEnabled     = [bool]$Config.php.enabled
        phpRunning     = $php.Count
        phpPorts       = @($phpPorts.ToArray())
        taskServer     = (Get-TaskState $TaskServer)
        taskWatchdog   = (Get-TaskState $TaskWatch)
        legacyTasks    = (Get-LegacyTaskNames)
        firewallRules  = (Get-CachedFirewallCount)
        port80         = $owner80
        port443        = $owner443
        certificates   = (Get-CachedCertificates)
        siteCount      = @($Config.sites).Count
        siteActive     = @($Config.sites | Where-Object { $_.enabled }).Count
        dirty          = ($ver.installed -and (Test-ConfigDirty $Config))
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
    } else {
        [void]$f.Add((New-Finding 'ok' 'Watchdog aktiv' 'Pr{ue}ft alle 3 Minuten, ob alles l{ae}uft.'))
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

    if ($Status.port80 -and $Status.port80.name -ne 'caddy') {
        [void]$f.Add((New-Finding 'warn' 'Port 80 geh{oe}rt einem anderen Programm' `
            "Auf Port 80 lauscht '$($Status.port80.name)' (PID $($Status.port80.pid)). Caddy kann den Port dann nicht belegen."))
    }
    if ($Status.port443 -and $Status.port443.name -ne 'caddy') {
        [void]$f.Add((New-Finding 'warn' 'Port 443 geh{oe}rt einem anderen Programm' `
            "Auf Port 443 lauscht '$($Status.port443.name)' (PID $($Status.port443.pid))."))
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
    $text = Get-Content -LiteralPath $full -Raw -Encoding UTF8
    $res = Write-CaddyfileAndReload -NewText $text -Reason 'restore'
    if ($res.ok) { $res.message = "Sicherung '$Name' wiederhergestellt. " + $res.message }
    return $res
}

# ---------------------------------------------------------------------------
#  Passworthash fuer den Zugriffsschutz
# ---------------------------------------------------------------------------
function New-PasswordHash {
    param([string]$Plain)
    if (-not (Test-Path -LiteralPath $Paths.Exe)) { return @{ ok = $false; message = 'Caddy ist noch nicht installiert.' } }
    if ([string]::IsNullOrEmpty($Plain) -or $Plain.Length -lt 8) {
        return @{ ok = $false; message = 'Das Passwort muss mindestens 8 Zeichen haben.' }
    }
    if ($Plain.Length -gt 128 -or -not (Test-NoControlChars $Plain)) {
        return @{ ok = $false; message = 'Das Passwort enth{ae}lt unzul{ae}ssige Zeichen.' }
    }
    $r = Invoke-Caddy @('hash-password', '--plaintext', $Plain) 60
    $out = (Get-ExeOutput $r)
    foreach ($line in ($out -split "`n")) {
        $t = $line.Trim()
        if (Test-BcryptHash $t) {
            Write-Audit 'password.hash' 'Neuer Hash erzeugt'
            return @{ ok = $true; hash = $t }
        }
    }
    return @{ ok = $false; message = 'Der Hash konnte nicht erzeugt werden.' }
}

# ===========================================================================
#  LOKALER VERWALTUNGSSERVER
#
#  Sicherheitsmodell:
#   - Der Listener bindet ausschliesslich auf 127.0.0.1. Aus dem Netz ist
#     die Oberflaeche nicht erreichbar, auch nicht ueber den Rechnernamen.
#   - Jeder Start erzeugt ein neues Zufallstoken. Ohne dieses Token gibt es
#     keinen Zugang; es steht nur im Konsolenfenster und in der Startadresse.
#   - Alle aendernden Aufrufe brauchen zusaetzlich einen CSRF-Wert im Header,
#     der nur im Seitenquelltext steht. Fremde Webseiten kommen nicht daran.
#   - Host- und Origin-Pruefung verhindert DNS-Rebinding.
#   - Nach Ablauf der Leerlaufzeit beendet sich der Server von selbst.
# ===========================================================================

$script:Listener     = $null
$script:Port         = 0
$script:SessionToken = ''
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
$UmlautSkipKeys = @('text', 'validation', 'config', 'site', 'status', 'hash',
                    'csrf', 'backups', 'files', 'skipped', 'addresses', 'domain')

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
        $sr = New-Object System.IO.StreamReader($Ctx.Request.InputStream, [Text.Encoding]::UTF8)
        try { return $sr.ReadToEnd() } finally { $sr.Dispose() }
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

function Test-Authorized {
    param($Ctx)
    try {
        $c = $Ctx.Request.Cookies['cmsid']
        if ($c -and (Test-SecretEqual $c.Value $script:SessionToken)) { return $true }
    } catch { }
    return $false
}

function Test-Csrf {
    param($Ctx)
    $v = [string]$Ctx.Request.Headers['X-Caddy-Manager-Csrf']
    return (Test-SecretEqual $v $script:CsrfToken)
}

function Get-LoginPage {
    $body = @'
<!doctype html><meta charset="utf-8"><title>Caddy Manager</title>
<style>body{font-family:Segoe UI,system-ui,sans-serif;background:#12141a;color:#e6e8ee;
display:flex;align-items:center;justify-content:center;height:100vh;margin:0}
div{max-width:34rem;padding:2rem;background:#1b1e26;border:1px solid #2b3040;border-radius:14px}
h1{margin:0 0 .6rem;font-size:1.2rem}p{line-height:1.6;color:#a8b0c2}code{color:#8fd2ff}</style>
<div><h1>Kein gueltiger Zugang</h1>
<p>Diese Seite laesst sich nur ueber die Adresse oeffnen, die beim Start im
Konsolenfenster steht. Wechsle in das Fenster von <code>caddy-manager.bat</code>
und rufe die dort angezeigte Adresse auf.</p></div>
'@
    return (T $body)
}

# ---------------------------------------------------------------------------
#  Anfragebearbeitung
# ---------------------------------------------------------------------------
function Invoke-Request {
    param($Ctx)
    $path = [string]$Ctx.Request.Url.AbsolutePath
    $method = [string]$Ctx.Request.HttpMethod

    # Die Statusabfrage laeuft im Hintergrund alle paar Sekunden weiter, auch
    # wenn niemand am Rechner sitzt. Sie zaehlt deshalb nicht als Nutzung -
    # sonst wuerde die Leerlaufabschaltung nie greifen.
    if ($path -ne '/api/status') { $script:LastActivity = Get-Date }

    if (-not (Test-RequestHost $Ctx)) {
        Send-Text $Ctx (T 'Ung{ue}ltiger Host.') 'text/plain; charset=utf-8' 400
        return
    }

    if ($path -eq '/favicon.ico') {
        try { $Ctx.Response.StatusCode = 204; Add-CommonHeaders $Ctx.Response } catch { }
        try { $Ctx.Response.Close() } catch { }
        return
    }

    # --- Anmeldung ueber das Starttoken ---
    if ($path -eq '/' -and $method -eq 'GET') {
        $t = [string]$Ctx.Request.QueryString['t']
        if ($t -and (Test-SecretEqual $t $script:SessionToken)) {
            try {
                $Ctx.Response.StatusCode = 302
                Add-CommonHeaders $Ctx.Response
                $Ctx.Response.AppendHeader('Set-Cookie', "cmsid=$($script:SessionToken); Path=/; HttpOnly; SameSite=Strict")
                $Ctx.Response.AppendHeader('Location', '/')
            } catch { }
            try { $Ctx.Response.Close() } catch { }
            return
        }
        if (-not (Test-Authorized $Ctx)) {
            Send-Text $Ctx (Get-LoginPage) 'text/html; charset=utf-8' 401
            return
        }
        $nonce = New-Secret 16
        Send-Html $Ctx (Get-UiHtml $nonce) $nonce
        return
    }

    if (-not (Test-Authorized $Ctx)) {
        Send-Json $Ctx @{ ok = $false; message = 'Nicht angemeldet. Bitte die Startadresse erneut {oe}ffnen.' } 401
        return
    }

    if ($method -eq 'POST') {
        if (-not (Test-RequestOrigin $Ctx)) {
            Send-Json $Ctx @{ ok = $false; message = 'Herkunft der Anfrage abgelehnt.' } 403
            return
        }
        if (-not (Test-Csrf $Ctx)) {
            Send-Json $Ctx @{ ok = $false; message = 'Sicherheitsmerkmal fehlt. Bitte die Seite neu laden.' } 403
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
            $email = Get-SafeString (Get-StringField $data 'email' '') 254
            if ($email -and -not (Test-EmailAddress $email)) {
                Send-Json $Ctx @{ ok = $false; message = 'Die E-Mail-Adresse ist ung{ue}ltig.' } 400
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
            $ge = Get-StringField $data 'globalExtra' $cfg.global.extra
            if ($ge.Length -le 8000 -and (Test-BalancedBraces $ge)) { $cfg.global.extra = $ge }
            else { Send-Json $Ctx @{ ok = $false; message = 'Die zus{ae}tzlichen globalen Zeilen haben unpaarige geschweifte Klammern.' } 400; return }
            $sn = Get-StringField $data 'snippets' $cfg.global.snippets
            if ($sn.Length -le 16000 -and (Test-BalancedBraces $sn)) { $cfg.global.snippets = $sn }
            else { Send-Json $Ctx @{ ok = $false; message = 'Die Bausteine haben unpaarige geschweifte Klammern.' } 400; return }

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
            Set-Content -LiteralPath $Paths.Staging -Value $text -Encoding UTF8
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
            Set-Content -LiteralPath $Paths.Staging -Value $text -Encoding UTF8
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
                        $html = T "<!doctype html>`r`n<meta charset=`"utf-8`">`r`n<title>Neue Seite</title>`r`n" +
                                "<h1>Es funktioniert.</h1>`r`n<p>Diesen Ordner mit den eigenen Dateien fuellen:<br>" +
                                (ConvertTo-HtmlText $p) + "</p>`r`n"
                        Set-Content -LiteralPath $index -Value $html -Encoding UTF8
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
            $restart = Restart-CaddyServer
            if (-not $restart.ok) {
                $cfg.manager.runAs = 'SYSTEM'
                Save-Config $cfg
                Install-Automation $cfg | Out-Null
                Restart-CaddyServer | Out-Null
                return @{ ok = $false; message = 'Der Start mit eingeschr{ae}nkten Rechten hat nicht geklappt. Es wurde auf SYSTEM zur{ue}ckgestellt.' }
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
    $script:SessionToken = New-Secret 32
    $script:CsrfToken = New-Secret 32
    $script:LastActivity = Get-Date

    $url = "$($script:Origin)/?t=$($script:SessionToken)"

    Write-Host ''
    Write-Host2 '  Caddy Manager l{ae}uft.' 'Green'
    Write-Host ''
    Write-Host2 '  Oberfl{ae}che:' 'Gray'
    Write-Host "  $url" -ForegroundColor Cyan
    Write-Host ''
    Write-Host2 '  Nur {ue}ber diese Adresse ist der Zugang m{oe}glich. Sie gilt bis zum Beenden.' 'DarkGray'
    Write-Host2 '  Fenster schlie{ss}en oder Strg+C beendet nur die Oberfl{ae}che - der Webserver l{ae}uft weiter.' 'DarkGray'
    Write-Host ''

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
#  Eine einzige Seite, komplett eingebettet. Keine externen Dateien, keine
#  Schriftarten und keine Skripte von aussen - passend zur strengen CSP.
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
:root{
  --bg:#0e1015; --panel:#161922; --panel2:#1c202b; --panel3:#232836; --line:#2a3040;
  --text:#e8eaf0; --muted:#98a1b5; --dim:#6d768a;
  --accent:#4d9bff; --accent2:#1f3f6b;
  --ok:#3fbf80; --warn:#e2a53c; --bad:#e5574d;
  --radius:12px;
}
@media (prefers-color-scheme: light){
  :root{
    --bg:#f3f5f9; --panel:#ffffff; --panel2:#f7f8fc; --panel3:#eef1f7; --line:#dde2ec;
    --text:#141821; --muted:#5c6577; --dim:#8b93a5;
    --accent:#1c6ee0; --accent2:#dae8ff;
  }
}
*{box-sizing:border-box}
html,body{margin:0;padding:0;height:100%}
body{background:var(--bg);color:var(--text);font:15px/1.55 "Segoe UI",system-ui,-apple-system,sans-serif;overflow:hidden}
button,input,select,textarea{font:inherit;color:inherit}
a{color:var(--accent)}
.hidden{display:none !important}

.app{display:flex;height:100vh}
.nav{width:232px;flex:0 0 232px;background:var(--panel);border-right:1px solid var(--line);
     display:flex;flex-direction:column;padding:16px 12px}
.brand{display:flex;align-items:center;gap:10px;padding:6px 8px 18px}
.brand .logo{width:30px;height:30px;border-radius:8px;background:linear-gradient(135deg,var(--accent),#7b5cff);
     display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:15px}
.brand b{font-size:15px;letter-spacing:.2px}
.brand small{display:block;color:var(--dim);font-size:11px;font-weight:400}
.nav nav{display:flex;flex-direction:column;gap:2px;flex:1}
.nav button.navitem{display:flex;align-items:center;gap:10px;background:none;border:0;text-align:left;
     padding:9px 11px;border-radius:9px;cursor:pointer;color:var(--muted);width:100%}
.nav button.navitem:hover{background:var(--panel2);color:var(--text)}
.nav button.navitem.active{background:var(--accent2);color:var(--accent);font-weight:600}
@media (prefers-color-scheme: dark){.nav button.navitem.active{color:#cfe3ff}}
.nav .ico{width:18px;text-align:center;font-size:14px}
.nav .badge{margin-left:auto;background:var(--bad);color:#fff;border-radius:20px;
     font-size:10px;padding:1px 7px;font-weight:700}
.navfoot{border-top:1px solid var(--line);padding-top:12px;color:var(--dim);font-size:11.5px}
.navfoot div{margin-bottom:3px}

main{flex:1;display:flex;flex-direction:column;min-width:0}
.top{display:flex;align-items:center;gap:10px;padding:12px 22px;border-bottom:1px solid var(--line);
     background:var(--panel);flex-wrap:wrap}
.pill{display:inline-flex;align-items:center;gap:7px;background:var(--panel3);border:1px solid var(--line);
     border-radius:20px;padding:4px 12px;font-size:12.5px;color:var(--muted);white-space:nowrap}
.dot{width:8px;height:8px;border-radius:50%;background:var(--dim);flex:0 0 8px}
.dot.ok{background:var(--ok);box-shadow:0 0 0 3px rgba(63,191,128,.18)}
.dot.bad{background:var(--bad);box-shadow:0 0 0 3px rgba(229,87,77,.18)}
.dot.warn{background:var(--warn);box-shadow:0 0 0 3px rgba(226,165,60,.18)}
.spacer{flex:1}

.scroll{flex:1;overflow-y:auto;padding:22px}
.wrap{max-width:1080px;margin:0 auto}

h2.page{margin:0 0 4px;font-size:20px}
p.lead{margin:0 0 20px;color:var(--muted);font-size:13.5px}

.card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:18px;margin-bottom:16px}
.card h3{margin:0 0 4px;font-size:15px}
.card .sub{color:var(--muted);font-size:13px;margin:0 0 14px}
.grid{display:grid;gap:14px}
.g2{grid-template-columns:repeat(auto-fit,minmax(250px,1fr))}
.g3{grid-template-columns:repeat(auto-fit,minmax(190px,1fr))}

.stat{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:15px 16px}
.stat .k{color:var(--muted);font-size:12px;display:flex;align-items:center;gap:7px;margin-bottom:7px}
.stat .v{font-size:20px;font-weight:650;letter-spacing:-.2px}
.stat .m{color:var(--dim);font-size:11.5px;margin-top:3px}

.btn{background:var(--panel3);border:1px solid var(--line);border-radius:9px;padding:8px 14px;
     cursor:pointer;font-size:13.5px;font-weight:550;transition:.12s;white-space:nowrap}
.btn:hover:not(:disabled){border-color:var(--accent);color:var(--accent)}
.btn:disabled{opacity:.45;cursor:not-allowed}
.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}
.btn.primary:hover:not(:disabled){filter:brightness(1.1);color:#fff}
.btn.danger:hover:not(:disabled){border-color:var(--bad);color:var(--bad)}
.btn.sm{padding:5px 10px;font-size:12.5px}
.btn.link{background:none;border:0;color:var(--accent);padding:4px 6px}
.btn.link:hover{text-decoration:underline}
.row{display:flex;gap:9px;flex-wrap:wrap;align-items:center}

.dirty{display:flex;align-items:center;gap:14px;background:linear-gradient(90deg,rgba(226,165,60,.16),transparent);
       border-bottom:1px solid var(--line);border-left:3px solid var(--warn);padding:11px 22px}
.dirty .t{font-size:13.5px}
.dirty .t b{color:var(--warn)}

table{width:100%;border-collapse:collapse}
th{text-align:left;font-size:11.5px;text-transform:uppercase;letter-spacing:.5px;color:var(--dim);
   font-weight:600;padding:0 10px 9px}
td{padding:11px 10px;border-top:1px solid var(--line);vertical-align:middle;font-size:13.5px}
tr.off td{opacity:.45}
.dom{font-weight:600}
.dom small{display:block;color:var(--dim);font-weight:400;font-size:11.5px;margin-top:2px;
           word-break:break-all;font-family:Consolas,monospace}
.tag{display:inline-block;background:var(--panel3);border:1px solid var(--line);border-radius:6px;
     padding:2px 8px;font-size:11.5px;color:var(--muted)}
.tag.php{color:#a78bfa;border-color:#5b4b9b}
.tag.proxy{color:#4dd0c0;border-color:#2a6a63}
.tag.static{color:#7fb2ff;border-color:#31527f}
.tag.redirect{color:#e2a53c;border-color:#7a5c22}
.tag.respond{color:#9aa3b6}
.cert{font-size:11.5px;color:var(--dim)}
.cert.warn{color:var(--warn)}
.cert.bad{color:var(--bad)}

.sw{position:relative;width:36px;height:20px;flex:0 0 36px;cursor:pointer;display:inline-block}
.sw i{position:absolute;inset:0;background:var(--panel3);border:1px solid var(--line);border-radius:20px;transition:.15s}
.sw i:after{content:"";position:absolute;width:14px;height:14px;border-radius:50%;background:var(--dim);
            top:2px;left:2px;transition:.15s}
.sw.on i{background:var(--accent);border-color:var(--accent)}
.sw.on i:after{background:#fff;transform:translateX(16px)}

label.f{display:block;margin-bottom:13px}
label.f>span{display:block;font-size:12.5px;color:var(--muted);margin-bottom:5px}
label.f>span b{color:var(--text);font-weight:600}
.hint{font-size:11.5px;color:var(--dim);margin-top:4px;line-height:1.45}
input[type=text],input[type=password],input[type=number],select,textarea{
  width:100%;background:var(--panel2);border:1px solid var(--line);border-radius:8px;padding:8px 11px}
input:focus,select:focus,textarea:focus{outline:0;border-color:var(--accent)}
textarea{resize:vertical;font-family:Consolas,"Courier New",monospace;font-size:13px;line-height:1.5}
.chk{display:flex;align-items:flex-start;gap:9px;padding:8px 0;cursor:pointer}
.chk input{margin-top:3px;flex:0 0 auto;accent-color:var(--accent)}
.chk .cl{font-size:13.5px}
.chk .cd{font-size:11.5px;color:var(--dim);line-height:1.4}

.types{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:9px;margin-bottom:16px}
.type{border:1px solid var(--line);background:var(--panel2);border-radius:10px;padding:11px;cursor:pointer;text-align:left}
.type:hover{border-color:var(--accent)}
.type.on{border-color:var(--accent);background:var(--accent2)}
.type b{display:block;font-size:13.5px;margin-bottom:2px}
.type span{font-size:11.5px;color:var(--muted);line-height:1.35;display:block}

.modal{position:fixed;inset:0;background:rgba(0,0,0,.62);display:flex;align-items:flex-start;
       justify-content:center;padding:32px 16px;overflow-y:auto;z-index:60}
.sheet{background:var(--panel);border:1px solid var(--line);border-radius:14px;width:100%;max-width:660px;
       box-shadow:0 24px 60px rgba(0,0,0,.45)}
.sheet header{display:flex;align-items:center;padding:16px 20px;border-bottom:1px solid var(--line)}
.sheet header h3{margin:0;font-size:16px;flex:1}
.sheet .body{padding:20px;max-height:calc(100vh - 220px);overflow-y:auto}
.sheet footer{display:flex;gap:9px;padding:14px 20px;border-top:1px solid var(--line);background:var(--panel2);
              border-radius:0 0 14px 14px}
.x{background:none;border:0;color:var(--muted);font-size:20px;cursor:pointer;line-height:1;padding:2px 6px}
.x:hover{color:var(--text)}

details.more{margin-top:6px;border-top:1px solid var(--line);padding-top:12px}
details.more summary{cursor:pointer;color:var(--accent);font-size:13px;margin-bottom:12px;list-style:none}
details.more summary::-webkit-details-marker{display:none}
details.more summary:before{content:"+ ";font-weight:700}
details.more[open] summary:before{content:"- "}

pre.out{background:#0a0c11;color:#d5dae6;border:1px solid var(--line);border-radius:9px;padding:13px;
     font:12.5px/1.5 Consolas,"Courier New",monospace;white-space:pre-wrap;word-break:break-word;
     max-height:440px;overflow:auto;margin:0}
@media (prefers-color-scheme: light){pre.out{background:#111520;color:#dfe4ee}}

.find{display:flex;gap:12px;padding:13px 0;border-top:1px solid var(--line);align-items:flex-start}
.find:first-child{border-top:0}
.find .ic{flex:0 0 22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;
      font-size:12px;font-weight:700;color:#fff;margin-top:1px}
.find .ic.ok{background:var(--ok)}.find .ic.warn{background:var(--warn)}.find .ic.bad{background:var(--bad)}
.find .tx{flex:1;min-width:0}
.find .tx b{display:block;font-size:13.5px;margin-bottom:2px}
.find .tx span{font-size:12.5px;color:var(--muted);line-height:1.5}

#toasts{position:fixed;right:18px;bottom:18px;display:flex;flex-direction:column;gap:9px;z-index:99;
        max-width:min(430px,90vw)}
.toast{background:var(--panel3);border:1px solid var(--line);border-left:3px solid var(--accent);
       border-radius:10px;padding:11px 15px;font-size:13px;box-shadow:0 10px 30px rgba(0,0,0,.35);
       animation:slide .18s ease-out}
.toast.ok{border-left-color:var(--ok)}
.toast.bad{border-left-color:var(--bad)}
.toast b{display:block;margin-bottom:2px}
.toast span{color:var(--muted);font-size:12px;white-space:pre-wrap;word-break:break-word}
@keyframes slide{from{transform:translateX(20px);opacity:0}to{transform:none;opacity:1}}

.busy{position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;
      justify-content:center;z-index:120;flex-direction:column;gap:16px}
.spin{width:38px;height:38px;border:3px solid var(--line);border-top-color:var(--accent);border-radius:50%;
      animation:sp .8s linear infinite}
@keyframes sp{to{transform:rotate(360deg)}}
.busy .msg{color:#e8eaf0;font-size:14px;text-align:center;max-width:440px;line-height:1.5}

.empty{text-align:center;padding:46px 20px;color:var(--muted)}
.empty .big{font-size:34px;margin-bottom:10px;opacity:.5}
.kv{display:flex;justify-content:space-between;gap:14px;padding:7px 0;border-top:1px solid var(--line);font-size:13px}
.kv:first-child{border-top:0}
.kv .k{color:var(--muted)}
.kv .v{font-family:Consolas,monospace;word-break:break-all;text-align:right}
code{font-family:Consolas,monospace;background:var(--panel3);padding:1px 6px;border-radius:5px;font-size:12.5px}
.card .row{margin-top:12px}
.grid{margin-bottom:16px}
.stat .v small{font-size:13px;color:var(--dim);font-weight:400}
.sheet footer .hint{margin:0}
.flabel{display:block;font-size:11.5px;text-transform:uppercase;letter-spacing:.5px;
        color:var(--dim);font-weight:600;margin-bottom:7px}
th.c1,td.c1{width:46px}
td.cact{text-align:right;white-space:nowrap}
.warnbox .row,.okbox .row{margin-top:10px}
#hashPw{max-width:260px}
#prevSheet{max-width:880px}
.byebye{display:flex;height:100vh;align-items:center;justify-content:center;color:var(--muted);font-size:15px;text-align:center;padding:20px}
.byebye>div{max-width:38rem;line-height:1.6}
.warnbox{background:rgba(226,165,60,.1);border:1px solid rgba(226,165,60,.35);border-radius:9px;
         padding:12px 14px;font-size:13px;line-height:1.5;margin-bottom:14px}
.okbox{background:rgba(63,191,128,.1);border:1px solid rgba(63,191,128,.35);border-radius:9px;
       padding:12px 14px;font-size:13px;line-height:1.5;margin-bottom:14px}
</style>
</head>
<body>
<div class="app">
  <aside class="nav">
    <div class="brand">
      <div class="logo">C</div>
      <div><b>Caddy Manager</b><small>Webserver-Verwaltung</small></div>
    </div>
    <nav id="nav">
      <button class="navitem active" data-view="dash"><span class="ico">#</span>{Ue}bersicht</button>
      <button class="navitem" data-view="domains"><span class="ico">@</span>Domains</button>
      <button class="navitem" data-view="setup"><span class="ico">+</span>Einrichtung</button>
      <button class="navitem" data-view="security"><span class="ico">!</span>Sicherheit<span class="badge hidden" id="secBadge">0</span></button>
      <button class="navitem" data-view="logs"><span class="ico">=</span>Protokolle</button>
      <button class="navitem" data-view="settings"><span class="ico">*</span>Einstellungen</button>
      <button class="navitem" data-view="expert"><span class="ico">&gt;</span>Experte</button>
    </nav>
    <div class="navfoot">
      <div id="footVer">Caddy: unbekannt</div>
      <div id="footRoot"></div>
      <button class="btn sm" id="btnQuit">Oberfl{ae}che beenden</button>
    </div>
  </aside>

  <main>
    <div class="top">
      <span class="pill"><span class="dot" id="dCaddy"></span><span id="tCaddy">Caddy</span></span>
      <span class="pill hidden" id="pPhp"><span class="dot" id="dPhp"></span><span id="tPhp">PHP</span></span>
      <span class="pill"><span class="dot" id="dAuto"></span><span id="tAuto">Autostart</span></span>
      <span class="spacer"></span>
      <button class="btn sm" data-act="service" data-arg="start">Start</button>
      <button class="btn sm" data-act="service" data-arg="restart">Neu starten</button>
      <button class="btn sm danger" data-act="service" data-arg="stop">Stopp</button>
    </div>

    <div class="dirty hidden" id="dirtyBar">
      <div class="t"><b>Nicht {ue}bernommene {Ae}nderungen.</b> Die Konfiguration weicht vom laufenden Webserver ab.</div>
      <span class="spacer"></span>
      <button class="btn sm" data-act="preview">Vorschau</button>
      <button class="btn sm primary" data-act="apply">Jetzt {ue}bernehmen</button>
    </div>

    <div class="scroll"><div class="wrap" id="view"></div></div>
  </main>
</div>

<div class="modal hidden" id="modal"></div>
<div class="busy hidden" id="busy"><div class="spin"></div><div class="msg" id="busyMsg"></div></div>
<div id="toasts"></div>

<script nonce="__NONCE__">
"use strict";
const CSRF = "__CSRF__";
let ST = { status:null, config:null };
let view = "dash";
let busyCount = 0;
let pollTimer = null;
let editing = null;
let pendingPw = "";
let offline = 0;

/* ---------- Helfer ---------- */
const $ = (s,r) => (r||document).querySelector(s);
const $$ = (s,r) => Array.from((r||document).querySelectorAll(s));
function arr(x){ return Array.isArray(x) ? x : (x===null||x===undefined||x==="" ? [] : [x]); }
function esc(s){ return String(s==null?"":s).replace(/[&<>"']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c])); }

function toast(msg, kind, title){
  const el = document.createElement("div");
  el.className = "toast " + (kind||"");
  el.innerHTML = "<b>"+esc(title|| (kind==="bad"?"Fehler":"Erledigt"))+"</b><span>"+esc(msg)+"</span>";
  $("#toasts").appendChild(el);
  setTimeout(()=>{ el.style.opacity="0"; el.style.transform="translateX(20px)";
                   el.style.transition=".25s"; setTimeout(()=>el.remove(),260); }, kind==="bad"?9000:4500);
}

function showBusy(msg){
  busyCount++;
  $("#busyMsg").textContent = msg || "Einen Moment...";
  $("#busy").classList.remove("hidden");
}
function hideBusy(){
  busyCount = Math.max(0, busyCount-1);
  if(!busyCount) $("#busy").classList.add("hidden");
}

async function api(path, body, busyMsg){
  if(busyMsg) showBusy(busyMsg);
  try{
    const opt = { method: body===undefined ? "GET" : "POST",
                  headers: { "X-Caddy-Manager-Csrf": CSRF } };
    if(body!==undefined){ opt.headers["Content-Type"]="application/json"; opt.body=JSON.stringify(body); }
    const r = await fetch(path, opt);
    let j = null;
    try{ j = await r.json(); }catch(e){ j = { ok:false, message:"Ung{ue}ltige Antwort vom Manager." }; }
    if(r.status===401){ toast("Die Sitzung ist abgelaufen. Bitte die Startadresse im Konsolenfenster erneut {oe}ffnen.","bad"); }
    return j;
  }catch(e){
    return { ok:false, message:"Der Manager antwortet nicht: "+e.message };
  }finally{
    if(busyMsg) hideBusy();
  }
}

function setCfg(j){ if(j && j.config){ ST.config = j.config; } }

/* ---------- Statuskopf ---------- */
function paintStatus(){
  const s = ST.status; if(!s) return;
  const set = (dot,txt,state,label)=>{ const d=$(dot); d.className="dot "+state; $(txt).textContent=label; };

  if(!s.caddyInstalled) set("#dCaddy","#tCaddy","bad","Caddy nicht installiert");
  else if(s.caddyRunning) set("#dCaddy","#tCaddy","ok","Caddy l{ae}uft"+(s.caddyUptime?" ("+s.caddyUptime+")":""));
  else set("#dCaddy","#tCaddy","bad","Caddy gestoppt");

  const php = $("#pPhp");
  if(s.phpEnabled){
    php.classList.remove("hidden");
    const open = arr(s.phpPorts).filter(p=>p.open).length;
    const tot = arr(s.phpPorts).length;
    set("#dPhp","#tPhp", open===0?"bad":(open<tot?"warn":"ok"), "PHP "+open+"/"+tot);
  } else php.classList.add("hidden");

  const auto = s.taskServer && s.taskWatchdog;
  set("#dAuto","#tAuto", auto?"ok":"warn", auto?"Autostart aktiv":"Kein Autostart");

  $("#footVer").textContent = "Caddy: " + (s.caddyVersion || "nicht installiert");
  $("#footRoot").textContent = s.root;
  $("#dirtyBar").classList.toggle("hidden", !s.dirty);
}

/* ---------- Navigation ---------- */
function go(v){
  view = v;
  $$(".navitem").forEach(b=>b.classList.toggle("active", b.dataset.view===v));
  render();
}

function render(){
  const el = $("#view");
  if(view==="dash") renderDash(el);
  else if(view==="domains") renderDomains(el);
  else if(view==="setup") renderSetup(el);
  else if(view==="security") renderSecurity(el);
  else if(view==="logs") renderLogs(el);
  else if(view==="settings") renderSettings(el);
  else if(view==="expert") renderExpert(el);
}

/* ---------- {Ue}bersicht ---------- */
function renderDash(el){
  const s = ST.status, c = ST.config;
  if(!s){ el.innerHTML = "<p class='lead'>Status wird geladen...</p>"; return; }

  if(!s.caddyInstalled){
    el.innerHTML = `
      <h2 class="page">Ersteinrichtung</h2>
      <p class="lead">Caddy ist auf diesem Rechner noch nicht eingerichtet. Ein Klick gen{ue}gt.</p>
      <div class="card">
        <h3>Alles einrichten</h3>
        <p class="sub">Es werden angelegt: die Verzeichnisse unter ${esc(s.root)}, die aktuelle Caddy-Version,
           Firewallfreigaben f{ue}r Port 80 und 443 sowie der automatische Start beim Hochfahren samt Watchdog.</p>
        <label class="chk"><input type="checkbox" id="setupPhp">
          <span><span class="cl">PHP mitinstallieren</span>
          <span class="cd">Aktuelles PHP (Non-Thread-Safe) mit abgesicherter php.ini und mehreren
          FastCGI-Prozessen. Nur n{oe}tig, wenn eine Seite PHP verwendet.</span></span></label>
        <div class="row"><button class="btn primary" data-act="setup-all">Jetzt einrichten</button></div>
      </div>`;
    return;
  }

  const certs = arr(s.certificates).slice(0,6);
  const soon = arr(s.certificates).filter(x=>x.daysLeft<21).length;

  el.innerHTML = `
    <h2 class="page">{Ue}bersicht</h2>
    <p class="lead">Der Webserver l{ae}uft unabh{ae}ngig von diesem Fenster. Die Oberfl{ae}che wird nur
       zum {Ae}ndern gebraucht.</p>

    <div class="grid g3">
      <div class="stat"><div class="k">Webserver</div>
        <div class="v">${s.caddyRunning?"L{ae}uft":"Gestoppt"}</div>
        <div class="m">${s.caddyRunning? "Caddy "+esc(s.caddyVersion)+" {bull} PID "+s.caddyPid : "Version "+esc(s.caddyVersion)}</div></div>
      <div class="stat"><div class="k">Domains</div>
        <div class="v">${s.siteActive}<small> / ${s.siteCount}</small></div>
        <div class="m">aktiv von insgesamt</div></div>
      <div class="stat"><div class="k">Zertifikate</div>
        <div class="v">${arr(s.certificates).length}</div>
        <div class="m">${soon? soon+" laufen bald ab" : "alle mit Restlaufzeit"}</div></div>
      <div class="stat"><div class="k">Automatik</div>
        <div class="v">${(s.taskServer&&s.taskWatchdog)?"Aktiv":"Fehlt"}</div>
        <div class="m">Autostart und Watchdog</div></div>
    </div>

    ${arr(s.legacyTasks).length ? `<div class="warnbox">
       <b>Alte geplante Aufgaben gefunden:</b> ${esc(arr(s.legacyTasks).join(", "))}.
       Sie stammen aus der Einrichtung von Hand und k{oe}nnen sich mit der neuen Automatik in die Quere kommen.
       <div class="row"><button class="btn sm" data-act="fix" data-arg="remove-legacy">Alte Aufgaben entfernen</button></div>
       </div>` : ""}

    ${s.port80 && s.port80.name!=="caddy" ? `<div class="warnbox">Auf Port 80 lauscht
       <code>${esc(s.port80.name)}</code> (PID ${s.port80.pid}) statt Caddy.</div>` : ""}

    <div class="grid g2">
      <div class="card">
        <h3>Schnellzugriff</h3>
        <p class="sub">Die h{ae}ufigsten Handgriffe.</p>
        <div class="row">
          <button class="btn primary" data-act="newsite">Domain hinzuf{ue}gen</button>
          <button class="btn" data-act="dnsall">DNS aller Domains pr{ue}fen</button>
          <button class="btn" data-act="preview">Konfiguration ansehen</button>
          <button class="btn" data-act="setup" data-arg="caddy-update">Auf Updates pr{ue}fen</button>
        </div>
      </div>
      <div class="card">
        <h3>Zertifikate</h3>
        <p class="sub">Caddy verl{ae}ngert automatisch, etwa 30 Tage vor Ablauf.</p>
        ${certs.length ? certs.map(c=>`<div class="kv"><span class="k">${esc(c.domain)}</span>
           <span class="v ${c.daysLeft<0?"cert bad":(c.daysLeft<21?"cert warn":"")}">${c.daysLeft<0?"abgelaufen":c.daysLeft+" Tage"}</span></div>`).join("")
          : "<p class='sub'>Noch keine Zertifikate. Sie entstehen beim ersten Abruf einer Domain {ue}ber HTTPS.</p>"}
      </div>
    </div>

    <div class="card">
      <h3>Ablageorte</h3>
      <div class="kv"><span class="k">Konfiguration</span><span class="v">${esc(s.configPath)}</span></div>
      <div class="kv"><span class="k">Webseiten</span><span class="v">${esc(s.root)}\\www</span></div>
      <div class="kv"><span class="k">Protokolle</span><span class="v">${esc(s.root)}\\logs</span></div>
      <div class="kv"><span class="k">Zertifikate</span><span class="v">${esc(s.root)}\\data</span></div>
      ${s.disk? `<div class="kv"><span class="k">Freier Speicher</span><span class="v">${s.disk.freeGb} GB von ${s.disk.totalGb} GB</span></div>`:""}
    </div>`;
}

/* ---------- Domains ---------- */
function typeLabel(t){
  return {static:"Statisch", php:"PHP", proxy:"Proxy", redirect:"Weiterleitung", respond:"Text"}[t] || t;
}
function siteTarget(s){
  if(s.type==="proxy") return s.upstream;
  if(s.type==="redirect") return s.redirectTo;
  if(s.type==="respond") return '"'+s.respondBody+'"';
  return s.root;
}
function certFor(dom){
  const c = arr(ST.status && ST.status.certificates).find(x=>x.domain===String(dom).replace(/^https?:\/\//,"").split(":")[0]);
  return c;
}

function renderDomains(el){
  const c = ST.config;
  if(!c){ el.innerHTML=""; return; }

  if(c.mode==="manual"){
    el.innerHTML = `<h2 class="page">Domains</h2>
      <div class="warnbox"><b>Manueller Modus ist aktiv.</b> Die Caddyfile wird von Hand gepflegt und
      nicht mehr erzeugt. Zum Verwalten {ue}ber diese Liste im Reiter <b>Experte</b> wieder auf den
      verwalteten Modus umschalten - die bestehende Caddyfile wird dabei eingelesen.</div>`;
    return;
  }

  const rows = arr(c.sites).map(s=>{
    const cert = certFor(s.domains[0]);
    const certTxt = cert ? (cert.daysLeft<0? "Zertifikat abgelaufen"
                        : "Zertifikat "+cert.daysLeft+" Tage") : "kein Zertifikat";
    const certCls = cert ? (cert.daysLeft<0?"cert bad":(cert.daysLeft<21?"cert warn":"cert")) : "cert";
    return `<tr class="${s.enabled?"":"off"}">
      <td class="c1"><span class="sw ${s.enabled?"on":""}" data-act="toggle" data-arg="${esc(s.id)}"><i></i></span></td>
      <td><div class="dom">${esc(s.domains[0])}${arr(s.domains).length>1?' <span class="tag">+'+(arr(s.domains).length-1)+'</span>':''}
          <small>${esc(siteTarget(s))}</small></div></td>
      <td><span class="tag ${s.type}">${typeLabel(s.type)}</span></td>
      <td><span class="${certCls}">${esc(certTxt)}</span></td>
      <td class="cact">
        <button class="btn sm" data-act="edit" data-arg="${esc(s.id)}">Bearbeiten</button>
        <button class="btn sm" data-act="dns" data-arg="${esc(s.id)}">DNS</button>
        <button class="btn sm danger" data-act="del" data-arg="${esc(s.id)}">L{oe}schen</button>
      </td></tr>`;
  }).join("");

  el.innerHTML = `
    <h2 class="page">Domains</h2>
    <p class="lead">Jede Zeile wird beim {Ue}bernehmen in einen sauberen Caddyfile-Block {ue}bersetzt.</p>
    <div class="card">
      <div class="row"><button class="btn primary" data-act="newsite">Domain hinzuf{ue}gen</button>
        <button class="btn" data-act="dnsall">DNS aller Domains pr{ue}fen</button>
        <button class="btn" data-act="preview">Erzeugte Caddyfile ansehen</button>
        <span class="spacer"></span>
        <button class="btn" data-act="import">Bestehende Caddyfile einlesen</button></div>
    </div>
    ${arr(c.sites).length ? `<div class="card"><table>
       <thead><tr><th class="c1">An</th><th>Domain / Ziel</th><th>Art</th><th>TLS</th><th></th></tr></thead>
       <tbody>${rows}</tbody></table></div>`
     : `<div class="card"><div class="empty"><div class="big">@</div>
        <b>Noch keine Domain eingerichtet.</b>
        <p>Mit "Domain hinzuf{ue}gen" die erste Seite anlegen.</p></div></div>`}`;

}

/* ---------- Editor ---------- */
function openSite(id){
  const c = ST.config;
  let s = arr(c.sites).find(x=>x.id===id);
  const isNew = !s;
  if(!s){
    s = { id:"", enabled:true, label:"", domains:[], type:"static", root:"", upstream:"",
          redirectTo:"", redirectCode:"permanent", respondBody:"OK", respondStatus:200,
          encode:true, browse:false, indexFiles:"", securityHeaders:true, hsts:false,
          blockSensitive:true, accessLog:true, wwwRedirect:true, basicAuthUser:"", basicAuthHash:"",
          maxBody:"", tlsMode:"auto", tlsCert:"", tlsKey:"", extra:"" };
  }
  editing = JSON.parse(JSON.stringify(s));
  pendingPw = "";
  drawSheet(isNew);
}

const TYPES = [
  ["static","Statische Seite","HTML, Bilder, JavaScript aus einem Ordner"],
  ["php","PHP-Seite","WordPress, Nextcloud und andere PHP-Anwendungen"],
  ["proxy","Reverse Proxy","Leitet an ein Programm auf diesem Rechner weiter"],
  ["redirect","Weiterleitung","Schickt Besucher auf eine andere Adresse"],
  ["respond","Fester Text","Antwortet direkt, ohne Dateien - z.B. f{ue}r Statusseiten"]
];

function drawSheet(isNew){
  const s = editing;
  const t = s.type;
  $("#modal").classList.remove("hidden");
  $("#modal").innerHTML = `
  <div class="sheet">
    <header><h3>${isNew?"Neue Domain":"Domain bearbeiten"}</h3><button class="x" data-act="close">&times;</button></header>
    <div class="body">
      <label class="f"><span><b>Domains</b> {ndash} eine pro Zeile</span>
        <textarea id="fDomains" rows="2" placeholder="beispiel.de">${esc(arr(s.domains).join("\n"))}</textarea>
        <div class="hint">Ohne https:// davor. Mehrere Adressen liefern dieselbe Seite aus.
             Umlautdomains werden automatisch umgeschrieben.</div></label>

      <span class="flabel">Art der Seite</span>
      <div class="types">${TYPES.map(([k,n,d])=>`
        <button class="type ${t===k?"on":""}" data-act="settype" data-arg="${k}"><b>${n}</b><span>${d}</span></button>`).join("")}
      </div>

      ${(t==="static"||t==="php") ? `
      <label class="f"><span><b>Ordner mit den Dateien</b></span>
        <input type="text" id="fRoot" value="${esc(s.root)}" placeholder="C:\\caddy\\www\\beispiel.de">
        <div class="hint">Wird beim Speichern automatisch vorgeschlagen, wenn das Feld leer bleibt.
             <button class="btn link sm" data-act="mkdir">Ordner jetzt anlegen</button></div></label>` : ""}

      ${t==="proxy" ? `
      <label class="f"><span><b>Ziel</b></span>
        <input type="text" id="fUpstream" value="${esc(s.upstream)}" placeholder="127.0.0.1:3000">
        <div class="hint">Adresse und Port des Programms auf diesem Rechner, z.B. <code>127.0.0.1:3000</code>.
             Mehrere Ziele mit Leerzeichen trennen - Caddy verteilt dann die Anfragen.</div></label>` : ""}

      ${t==="redirect" ? `
      <label class="f"><span><b>Zieladresse</b></span>
        <input type="text" id="fRedirect" value="${esc(s.redirectTo)}" placeholder="https://beispiel.de">
        <div class="hint">Der aufgerufene Pfad wird angeh{ae}ngt.</div></label>
      <label class="f"><span>Art der Weiterleitung</span>
        <select id="fRedirectCode">
          <option value="permanent"${s.redirectCode==="permanent"?" selected":""}>Dauerhaft (301)</option>
          <option value="temporary"${s.redirectCode==="temporary"?" selected":""}>Vor{ue}bergehend (302)</option>
        </select></label>` : ""}

      ${t==="respond" ? `
      <label class="f"><span><b>Antworttext</b></span>
        <input type="text" id="fRespond" value="${esc(s.respondBody)}" placeholder="Alles in Ordnung"></label>
      <label class="f"><span>HTTP-Status</span>
        <input type="number" id="fStatus" value="${s.respondStatus}" min="100" max="599"></label>` : ""}

      <label class="chk"><input type="checkbox" id="fSec"${s.securityHeaders?" checked":""}>
        <span><span class="cl">Sicherheitskopfzeilen senden</span>
        <span class="cd">Verhindert MIME-Raten und das Einbetten in fremde Seiten. Sollte an bleiben.</span></span></label>

      ${t!=="proxy" ? `<label class="chk"><input type="checkbox" id="fBlock"${s.blockSensitive?" checked":""}>
        <span><span class="cl">Versteckte und heikle Dateien sperren</span>
        <span class="cd">.env, .git, *.sql, *.log und {ae}hnliche Dateien werden nicht ausgeliefert.</span></span></label>`:""}

      <label class="chk"><input type="checkbox" id="fWww"${s.wwwRedirect?" checked":""}>
        <span><span class="cl">www-Adresse auf die Hauptdomain umleiten</span>
        <span class="cd">Legt zus{ae}tzlich einen Block f{ue}r www.<i>domain</i> an.</span></span></label>

      <details class="more"><summary>Weitere Einstellungen</summary>

        <label class="f"><span>Bezeichnung (nur zur {Ue}bersicht)</span>
          <input type="text" id="fLabel" value="${esc(s.label)}" placeholder="z.B. Firmenseite"></label>

        <label class="chk"><input type="checkbox" id="fEncode"${s.encode?" checked":""}>
          <span><span class="cl">Antworten komprimieren</span><span class="cd">gzip und zstd - spart Bandbreite.</span></span></label>
        <label class="chk"><input type="checkbox" id="fLog"${s.accessLog?" checked":""}>
          <span><span class="cl">Zugriffe protokollieren</span>
          <span class="cd">Eigene Protokolldatei mit automatischer Rotation.</span></span></label>
        <label class="chk"><input type="checkbox" id="fHsts"${s.hsts?" checked":""}>
          <span><span class="cl">HSTS erzwingen</span>
          <span class="cd">Browser merken sich HTTPS f{ue}r ein Jahr. Erst einschalten, wenn die Seite
          dauerhaft {ue}ber HTTPS l{ae}uft - das l{ae}sst sich nicht schnell zur{ue}cknehmen.</span></span></label>
        ${(t==="static"||t==="php") ? `<label class="chk"><input type="checkbox" id="fBrowse"${s.browse?" checked":""}>
          <span><span class="cl">Verzeichnisauflistung erlauben</span>
          <span class="cd">Besucher sehen alle Dateinamen im Ordner. Normalerweise aus lassen.</span></span></label>
        <label class="f"><span>Startdateien</span>
          <input type="text" id="fIndex" value="${esc(s.indexFiles)}" placeholder="index.html index.php">
          <div class="hint">Leer lassen f{ue}r die Standardreihenfolge.</div></label>`:""}

        <label class="f"><span>Maximale Uploadgr{oe}sse</span>
          <input type="text" id="fMaxBody" value="${esc(s.maxBody)}" placeholder="z.B. 100MB">
          <div class="hint">Leer lassen f{ue}r Caddys Voreinstellung.</div></label>

        <label class="f"><span><b>Zugriffsschutz</b> {ndash} Benutzername</span>
          <input type="text" id="fAuthUser" value="${esc(s.basicAuthUser)}" placeholder="leer lassen f{ue}r offenen Zugang"></label>
        <label class="f"><span>Passwort</span>
          <input type="password" id="fAuthPw" placeholder="${s.basicAuthHash?"gesetzt - nur bei {Ae}nderung ausf{ue}llen":"neues Passwort"}">
          <div class="hint">Das Passwort wird sofort als Hash abgelegt, niemals im Klartext.
            ${s.basicAuthHash?'<button class="btn link sm" data-act="clearauth">Zugriffsschutz entfernen</button>':""}</div></label>

        <label class="f"><span>Zertifikat</span>
          <select id="fTls">
            <option value="auto"${s.tlsMode==="auto"?" selected":""}>Automatisch von Let's Encrypt</option>
            <option value="internal"${s.tlsMode==="internal"?" selected":""}>Selbst ausgestellt (nur intern)</option>
            <option value="custom"${s.tlsMode==="custom"?" selected":""}>Eigene Dateien</option>
          </select></label>
        <div id="tlsFiles" class="${s.tlsMode==="custom"?"":"hidden"}">
          <label class="f"><span>Zertifikatsdatei (.crt)</span>
            <input type="text" id="fTlsCert" value="${esc(s.tlsCert)}"></label>
          <label class="f"><span>Schl{ue}sseldatei (.key)</span>
            <input type="text" id="fTlsKey" value="${esc(s.tlsKey)}"></label>
        </div>

        <label class="f"><span>Zus{ae}tzliche Caddyfile-Zeilen</span>
          <textarea id="fExtra" rows="4" placeholder="handle_path /api/* {&#10;    reverse_proxy 127.0.0.1:1234&#10;}">${esc(s.extra)}</textarea>
          <div class="hint">F{ue}r Sonderf{ae}lle. Der Inhalt wird unver{ae}ndert in den Block {ue}bernommen und
               vor dem Anwenden gepr{ue}ft.</div></label>
      </details>
    </div>
    <footer>
      <button class="btn primary" data-act="savesite">Speichern</button>
      <button class="btn" data-act="close">Abbrechen</button>
      <span class="spacer"></span>
      <span class="hint">Aktiv wird die {Ae}nderung erst mit "{Ue}bernehmen".</span>
    </footer>
  </div>`;
  const pwEl2 = $("#fAuthPw");
  if(pwEl2 && pendingPw) pwEl2.value = pendingPw;
  const tls = $("#fTls");
  if(tls) tls.addEventListener("change", ()=>{ $("#tlsFiles").classList.toggle("hidden", tls.value!=="custom"); });
}

function collectSheet(){
  const s = editing;
  const g = id => { const e=$(id); return e? e.value : undefined; };
  const b = id => { const e=$(id); return e? e.checked : undefined; };
  const dom = g("#fDomains");
  if(dom!==undefined) s.domains = dom.split(/[\n,;]+/).map(x=>x.trim()).filter(Boolean);
  if(g("#fRoot")!==undefined) s.root = g("#fRoot").trim();
  if(g("#fUpstream")!==undefined) s.upstream = g("#fUpstream").trim();
  if(g("#fRedirect")!==undefined) s.redirectTo = g("#fRedirect").trim();
  if(g("#fRedirectCode")!==undefined) s.redirectCode = g("#fRedirectCode");
  if(g("#fRespond")!==undefined) s.respondBody = g("#fRespond");
  if(g("#fStatus")!==undefined) s.respondStatus = parseInt(g("#fStatus"),10)||200;
  if(g("#fLabel")!==undefined) s.label = g("#fLabel").trim();
  if(g("#fIndex")!==undefined) s.indexFiles = g("#fIndex").trim();
  if(g("#fMaxBody")!==undefined) s.maxBody = g("#fMaxBody").trim();
  if(g("#fAuthUser")!==undefined) s.basicAuthUser = g("#fAuthUser").trim();
  if(g("#fTls")!==undefined) s.tlsMode = g("#fTls");
  if(g("#fTlsCert")!==undefined) s.tlsCert = g("#fTlsCert").trim();
  if(g("#fTlsKey")!==undefined) s.tlsKey = g("#fTlsKey").trim();
  if(g("#fExtra")!==undefined) s.extra = g("#fExtra");
  if(b("#fSec")!==undefined) s.securityHeaders = b("#fSec");
  if(b("#fBlock")!==undefined) s.blockSensitive = b("#fBlock");
  if(b("#fWww")!==undefined) s.wwwRedirect = b("#fWww");
  if(b("#fEncode")!==undefined) s.encode = b("#fEncode");
  if(b("#fLog")!==undefined) s.accessLog = b("#fLog");
  if(b("#fHsts")!==undefined) s.hsts = b("#fHsts");
  if(b("#fBrowse")!==undefined) s.browse = b("#fBrowse");
  const pwEl = $("#fAuthPw");
  if(pwEl) pendingPw = pwEl.value;
}

async function saveSite(){
  collectSheet();
  const pw = pendingPw;
  if(editing.basicAuthUser && pw){
    const h = await api("/api/hash", { password: pw }, "Passwort wird verschl{ue}sselt...");
    if(!h.ok){ toast(h.message,"bad"); return; }
    editing.basicAuthHash = h.hash;
  }
  if(!editing.basicAuthUser) editing.basicAuthHash = "";
  if(editing.basicAuthUser && !editing.basicAuthHash){
    toast("F{ue}r den Zugriffsschutz fehlt noch ein Passwort.","bad"); return;
  }
  const r = await api("/api/site/save", editing, "Wird gespeichert...");
  if(!r.ok){ toast(r.message,"bad"); return; }
  setCfg(r);
  closeModal();
  toast(r.message,"ok");
  await refresh();
  render();
}

function closeModal(){ $("#modal").classList.add("hidden"); $("#modal").innerHTML=""; editing=null; }

/* ---------- Einrichtung ---------- */
function renderSetup(el){
  const s = ST.status, c = ST.config;
  if(!s) return;
  const line = (title, desc, ok, btn, act, arg) => `
    <div class="find"><div class="ic ${ok?"ok":"warn"}">${ok?"+":"!"}</div>
      <div class="tx"><b>${title}</b><span>${desc}</span></div>
      ${btn?`<button class="btn sm" data-act="${act}" data-arg="${arg}">${btn}</button>`:""}</div>`;

  el.innerHTML = `
    <h2 class="page">Einrichtung</h2>
    <p class="lead">Alles, was fr{ue}her von Hand aus der Anleitung kopiert werden musste.</p>

    <div class="card">
      <h3>Bausteine</h3>
      <p class="sub">Jeder Schritt l{ae}sst sich einzeln ausf{ue}hren und beliebig wiederholen.</p>
      ${line("Verzeichnisse", "Ordner f{ue}r Webseiten, Protokolle, Zertifikate und Sicherungen unter "+esc(s.root)+".",
             true, "Pr{ue}fen", "setup", "dirs")}
      ${line("Caddy "+(s.caddyInstalled?esc(s.caddyVersion):""), s.caddyInstalled
             ? "Installiert. Updates bringen Sicherheitskorrekturen - vor dem Tausch wird die alte Datei gesichert."
             : "Noch nicht installiert.",
             s.caddyInstalled, s.caddyInstalled?"Auf Updates pr{ue}fen":"Installieren", "setup",
             s.caddyInstalled?"caddy-update":"caddy")}
      ${line("Automatischer Start", s.taskServer
             ? "Caddy startet beim Hochfahren, ein Watchdog pr{ue}ft alle drei Minuten nach."
             : "Ohne diesen Schritt bleibt der Server nach einem Neustart aus.",
             !!(s.taskServer && s.taskWatchdog), "Einrichten", "setup", "tasks")}
      ${line("Firewall", s.firewallRules > 0
             ? s.firewallRules+" Freigabe(n) f{ue}r Port 80 und 443 vorhanden."
             : (s.firewallRules < 0 ? "Der Zustand lie{ss} sich nicht abfragen."
                                    : "Ohne Freigabe f{ue}r Port 80 und 443 kommt von au{ss}en niemand an."),
             s.firewallRules > 0, "Freigaben anlegen", "setup", "firewall")}
      ${line("PHP", s.phpInstalled
             ? (c.php.enabled? "Installiert und aktiv mit "+c.php.poolSize+" FastCGI-Prozessen."
                             : "Installiert, aber abgeschaltet.")
             : "Nicht installiert. Wird nur f{ue}r PHP-Seiten gebraucht.",
             s.phpInstalled && c.php.enabled,
             (s.phpInstalled && c.php.enabled)?"Aktualisieren":"Installieren und aktivieren", "setup", "php")}
    </div>

    <div class="card">
      <h3>Rundum-Einrichtung</h3>
      <p class="sub">F{ue}hrt alle Schritte nacheinander aus und startet den Server anschlie{ss}end.
         Eine bereits vorhandene Caddyfile wird dabei eingelesen, nicht {ue}berschrieben.</p>
      <label class="chk"><input type="checkbox" id="setupPhp"${c.php.enabled?" checked":""}>
        <span><span class="cl">PHP mit einrichten</span></span></label>
      <div class="row"><button class="btn primary" data-act="setup-all">Alles einrichten</button></div>
    </div>

    <div class="card">
      <h3>Zur{ue}ckbauen</h3>
      <p class="sub">Entfernt Autostart und Watchdog. Dateien, Zertifikate und die Caddyfile bleiben erhalten,
         der Webserver l{ae}uft bis zum n{ae}chsten Neustart weiter.</p>
      <div class="row">
        <button class="btn danger" data-act="setup" data-arg="uninstall">Automatik entfernen</button>
        ${c.php.enabled?`<button class="btn" data-act="setup" data-arg="php-off">PHP abschalten</button>`:""}
      </div>
    </div>`;
}

/* ---------- Sicherheit ---------- */
async function renderSecurity(el){
  el.innerHTML = `<h2 class="page">Sicherheit</h2><p class="lead">Wird gepr{ue}ft...</p>`;
  const r = await api("/api/security");
  if(!r.ok){ el.innerHTML = "<p class='lead'>Pr{ue}fung fehlgeschlagen.</p>"; return; }
  const f = arr(r.findings);
  const bad = f.filter(x=>x.level==="bad").length;
  const warn = f.filter(x=>x.level==="warn").length;
  setSecBadge(f);

  const order = { bad:0, warn:1, ok:2 };
  f.sort((a,b)=>order[a.level]-order[b.level]);

  el.innerHTML = `
    <h2 class="page">Sicherheit</h2>
    <p class="lead">${bad? bad+" dringende und "+warn+" empfohlene Punkte." : (warn? warn+" empfohlene Punkte." : "Alles in Ordnung.")}</p>
    ${bad===0&&warn===0? `<div class="okbox">Keine Auff{ae}lligkeiten gefunden.</div>`:""}
    <div class="card">
      ${f.map(x=>`<div class="find">
        <div class="ic ${x.level}">${x.level==="ok"?"+":(x.level==="warn"?"!":"x")}</div>
        <div class="tx"><b>${esc(x.title)}</b><span>${esc(x.detail)}</span></div>
        ${x.fix?`<button class="btn sm" data-act="fix" data-arg="${esc(x.fix)}">${esc(x.fixLabel||"Beheben")}</button>`:""}
      </div>`).join("")}
    </div>
    <div class="card">
      <h3>Wie diese Oberfl{ae}che gesch{ue}tzt ist</h3>
      <p class="sub">Damit klar ist, was hier eigentlich offen steht.</p>
      <div class="kv"><span class="k">Erreichbar nur {ue}ber</span><span class="v">127.0.0.1 (nicht aus dem Netz)</span></div>
      <div class="kv"><span class="k">Zugang</span><span class="v">Einmaltoken, bei jedem Start neu</span></div>
      <div class="kv"><span class="k">Schutz vor fremden Seiten</span><span class="v">CSRF-Header, Origin- und Host-Pr{ue}fung</span></div>
      <div class="kv"><span class="k">Automatisches Ende</span><span class="v">${ST.config.manager.idleMinutes} Minuten ohne Nutzung</span></div>
    </div>`;
}

function showOffline(){
  if(pollTimer){ clearInterval(pollTimer); pollTimer = null; }
  document.body.innerHTML = "<div class='byebye'><div><b>Die Oberfl{ae}che ist nicht mehr erreichbar.</b>"+
    "<br><br>Vermutlich wurde sie nach l{ae}ngerer Unt{ae}tigkeit automatisch beendet, oder das "+
    "Konsolenfenster wurde geschlossen.<br><br>Der Webserver l{ae}uft davon unber{ue}hrt weiter. "+
    "Zum Weiterarbeiten einfach <b>caddy-manager.bat</b> erneut starten.</div></div>";
}

function setSecBadge(findings){
  const list = arr(findings);
  const bad = list.filter(x=>x.level==="bad").length;
  const n = bad + list.filter(x=>x.level==="warn").length;
  const badge = $("#secBadge");
  badge.textContent = n;
  badge.classList.toggle("hidden", n===0);
  badge.style.background = bad ? "var(--bad)" : "var(--warn)";
}

/* ---------- Protokolle ---------- */
async function renderLogs(el){
  el.innerHTML = `<h2 class="page">Protokolle</h2><p class="lead">Wird geladen...</p>`;
  const r = await api("/api/logs");
  const files = arr(r.files);
  el.innerHTML = `
    <h2 class="page">Protokolle</h2>
    <p class="lead">Caddy dreht die Dateien automatisch, sobald sie zu gro{ss} werden.</p>
    <div class="card">
      <div class="row">
        <select id="logPick">${files.map(f=>`<option value="${esc(f.name)}">${esc(f.name)} (${f.sizeKb} KB, ${esc(f.modified)})</option>`).join("")}</select>
        <button class="btn" data-act="logshow">Anzeigen</button>
      </div>
    </div>
    <div class="card"><pre class="out" id="logOut">Datei ausw{ae}hlen und "Anzeigen" dr{ue}cken.</pre></div>`;
  if(files.length){
    const pick = $("#logPick");
    const pref = files.find(f=>f.name==="caddy.log") || files.find(f=>f.name==="manager.log");
    if(pref) pick.value = pref.name;
    showLog();
  }
}

async function showLog(){
  const pick = $("#logPick"); if(!pick) return;
  const r = await api("/api/log?name="+encodeURIComponent(pick.value)+"&lines=400");
  const out = $("#logOut");
  let text = r.text || "(leer)";
  if(pick.value.endsWith(".log") && text.trim().startsWith("{")){
    text = text.split("\n").map(l=>{
      try{
        const j = JSON.parse(l);
        if(j.request){
          const d = j.ts? new Date(j.ts*1000).toLocaleString("de-DE") : "";
          return d+"  "+(j.status||"")+"  "+(j.request.method||"")+"  "+
                 (j.request.host||"")+(j.request.uri||"")+"  "+(j.request.remote_ip||"");
        }
        const d = j.ts? new Date(j.ts*1000).toLocaleString("de-DE") : "";
        return d+"  ["+(j.level||"")+"] "+(j.logger?j.logger+": ":"")+(j.msg||l);
      }catch(e){ return l; }
    }).join("\n");
  }
  out.textContent = text;
  out.scrollTop = out.scrollHeight;
}

/* ---------- Einstellungen ---------- */
function renderSettings(el){
  const c = ST.config;
  el.innerHTML = `
    <h2 class="page">Einstellungen</h2>
    <p class="lead">Gilt f{ue}r alle Domains gemeinsam.</p>
    <div class="card">
      <h3>Zertifikate</h3>
      <label class="f"><span><b>E-Mail-Adresse</b> f{ue}r Let's Encrypt</span>
        <input type="text" id="sEmail" value="${esc(c.global.email)}" placeholder="admin@beispiel.de">
        <div class="hint">Dorthin gehen Warnungen, wenn eine Verl{ae}ngerung scheitert. Sehr empfohlen.</div></label>
    </div>
    <div class="card">
      <h3>PHP</h3>
      <label class="chk"><input type="checkbox" id="sPhp"${c.php.enabled?" checked":""}>
        <span><span class="cl">PHP verwenden</span>
        <span class="cd">Muss an sein, damit Seiten vom Typ "PHP" funktionieren.</span></span></label>
      <label class="f"><span>Anzahl paralleler PHP-Prozesse</span>
        <input type="number" id="sPool" value="${c.php.poolSize}" min="1" max="16">
        <div class="hint">Jeder Prozess bearbeitet eine Anfrage gleichzeitig. Vier ist ein guter Anfang.
             Nach dem {Ae}ndern die Einrichtung f{ue}r PHP erneut ausf{ue}hren.</div></label>
      <label class="chk"><input type="checkbox" id="sRisky"${c.php.disableRiskyFunctions?" checked":""}>
        <span><span class="cl">Gef{ae}hrliche PHP-Funktionen sperren</span>
        <span class="cd">exec, shell_exec, system und {ae}hnliche. Erh{oe}ht die Sicherheit deutlich, kann aber
        einzelne Anwendungen st{oe}ren. Danach php.ini neu schreiben lassen.</span></span></label>
    </div>
    <div class="card">
      <h3>Protokollierung</h3>
      <label class="f"><span>Ausf{ue}hrlichkeit</span>
        <select id="sLevel">
          ${["ERROR","WARN","INFO","DEBUG"].map(l=>`<option value="${l}"${c.global.logLevel===l?" selected":""}>${l}</option>`).join("")}
        </select></label>
      <label class="f"><span>Gr{oe}sse pro Protokolldatei</span>
        <input type="text" id="sRoll" value="${esc(c.global.rollSize)}" placeholder="10MiB"></label>
      <label class="f"><span>Anzahl aufbewahrter Dateien</span>
        <input type="number" id="sKeep" value="${c.global.rollKeep}" min="1" max="100"></label>
    </div>
    <div class="card">
      <h3>Diese Oberfl{ae}che</h3>
      <label class="f"><span>Automatisch beenden nach (Minuten ohne Nutzung)</span>
        <input type="number" id="sIdle" value="${c.manager.idleMinutes}" min="0" max="1440">
        <div class="hint">0 schaltet die Abschaltung aus. Je k{ue}rzer, desto kleiner das Zeitfenster,
             in dem die Verwaltung offen steht.</div></label>
      <label class="chk"><input type="checkbox" id="sBrowser"${c.manager.openBrowser?" checked":""}>
        <span><span class="cl">Browser beim Start automatisch {oe}ffnen</span></span></label>
    </div>
    <div class="card">
      <h3>Zus{ae}tzliche globale Caddyfile-Zeilen</h3>
      <p class="sub">Landen unver{ae}ndert im globalen Optionsblock. F{ue}r Sonderf{ae}lle.</p>
      <textarea id="sExtra" rows="4">${esc(c.global.extra)}</textarea>
    </div>
    <div class="card">
      <h3>Bausteine und Importe</h3>
      <p class="sub">Wiederverwendbare Bl{oe}cke in der Form <code>(name) { ... }</code> und
         <code>import</code>-Zeilen. Sie stehen in der erzeugten Datei vor allen Domains und
         lassen sich in einer Domain unter "Zus{ae}tzliche Caddyfile-Zeilen" mit
         <code>import name</code> einbinden.</p>
      <textarea id="sSnippets" rows="6" placeholder="(gemeinsam) {&#10;    encode gzip zstd&#10;}">${esc(c.global.snippets||"")}</textarea>
    </div>
    <div class="row"><button class="btn primary" data-act="savesettings">Einstellungen speichern</button></div>`;
}

/* ---------- Experte ---------- */
async function renderExpert(el){
  const c = ST.config;
  el.innerHTML = `<h2 class="page">Experte</h2><p class="lead">Wird geladen...</p>`;
  const [live, backups] = await Promise.all([api("/api/caddyfile"), api("/api/backups")]);
  el.innerHTML = `
    <h2 class="page">Experte</h2>
    <p class="lead">Direktzugriff auf die Caddyfile. Vor jedem Schreiben wird gepr{ue}ft und gesichert.</p>

    ${c.mode==="managed" ? `<div class="warnbox">
      <b>Verwalteter Modus.</b> Die Caddyfile wird aus der Domainliste erzeugt; {Ae}nderungen hier werden beim
      n{ae}chsten "{Ue}bernehmen" {ue}berschrieben. Zum dauerhaften Bearbeiten von Hand in den manuellen Modus wechseln.
      <div class="row"><button class="btn sm" data-act="mode" data-arg="manual">Auf manuellen Modus umschalten</button></div>
      </div>` : `<div class="okbox">
      <b>Manueller Modus.</b> Die Datei wird nicht mehr erzeugt. Zur{ue}ck im verwalteten Modus wird die
      aktuelle Datei eingelesen und in die Domainliste {ue}bernommen.
      <div class="row"><button class="btn sm" data-act="mode" data-arg="managed">Zur{ue}ck zum verwalteten Modus</button></div>
      </div>`}

    <div class="card">
      <h3>Caddyfile</h3>
      <textarea id="cfText" rows="20">${esc(live.text||"")}</textarea>
      <div class="row">
        <button class="btn" data-act="cfvalidate">Pr{ue}fen</button>
        <button class="btn" data-act="cfformat">Formatieren</button>
        <button class="btn primary" data-act="cfsave">Pr{ue}fen, sichern und {ue}bernehmen</button>
        <span class="spacer"></span>
        <button class="btn" data-act="preview">Erzeugte Fassung ansehen</button>
      </div>
      <pre class="out hidden" id="cfOut"></pre>
    </div>

    <div class="card">
      <h3>Sicherungen</h3>
      <p class="sub">Vor jeder {Ae}nderung wird die laufende Datei weggeschrieben. Die letzten 30 bleiben liegen.</p>
      ${arr(backups.backups).length ? arr(backups.backups).map(b=>`<div class="kv">
          <span class="k">${esc(b.modified)}<span class="hint"> {bull} ${b.sizeKb} KB</span></span>
          <span class="v"><button class="btn sm" data-act="restore" data-arg="${esc(b.name)}">Wiederherstellen</button></span>
        </div>`).join("") : "<p class='sub'>Noch keine Sicherungen vorhanden.</p>"}
    </div>

    <div class="card">
      <h3>Passworthash erzeugen</h3>
      <p class="sub">F{ue}r <code>basic_auth</code> in eigenen Bl{oe}cken.</p>
      <div class="row">
        <input type="password" id="hashPw" placeholder="Passwort">
        <button class="btn" data-act="mkhash">Hash erzeugen</button>
      </div>
      <pre class="out hidden" id="hashOut"></pre>
    </div>`;
}

/* ---------- Aktionen ---------- */
async function refresh(){
  const r = await api("/api/state");
  if(r && r.ok){ ST.status = r.status; ST.config = r.config; paintStatus(); }
  return r;
}

async function doApply(){
  const r = await api("/api/apply", {}, "Konfiguration wird gepr{ue}ft und {ue}bernommen...");
  if(r.ok){ toast(r.message,"ok"); }
  else { toast((r.message||"")+(r.validation?"\n\n"+r.validation:""),"bad","Nicht {ue}bernommen"); }
  await refresh(); render();
}

async function showPreview(){
  const r = await api("/api/preview");
  $("#modal").classList.remove("hidden");
  $("#modal").innerHTML = `<div class="sheet" id="prevSheet">
    <header><h3>Erzeugte Caddyfile</h3><button class="x" data-act="close">&times;</button></header>
    <div class="body"><pre class="out">${esc(r.text||"")}</pre></div>
    <footer><button class="btn primary" data-act="apply">{Ue}bernehmen</button>
      <button class="btn" data-act="close">Schlie{ss}en</button></footer></div>`;
}

async function showDns(id){
  const r = await api("/api/dns", id? { id } : {}, "DNS wird abgefragt...");
  if(!r.ok){ toast(r.message||"Pr{ue}fung fehlgeschlagen","bad"); return; }
  const rows = arr(r.results).map(x=>`<div class="find">
     <div class="ic ${x.status==="ok"?"ok":(x.status==="bad"?"bad":"warn")}">${x.status==="ok"?"+":(x.status==="bad"?"x":"!")}</div>
     <div class="tx"><b>${esc(x.domain)}</b><span>${esc(x.message)}${arr(x.addresses).length?" ("+esc(arr(x.addresses).join(", "))+")":""}</span></div>
     </div>`).join("");
  $("#modal").classList.remove("hidden");
  $("#modal").innerHTML = `<div class="sheet">
    <header><h3>DNS-Pr{ue}fung</h3><button class="x" data-act="close">&times;</button></header>
    <div class="body">
      <p class="sub">Diese Abfrage hat die {oe}ffentliche Adresse dieses Servers bei einem externen Dienst erfragt.
         Ergebnis: <code>${esc(r.publicIp||"unbekannt")}</code></p>
      ${rows||"<p class='sub'>Nichts zu pr{ue}fen.</p>"}
      <p class="hint">Zeigt eine Domain woanders hin, kann Let's Encrypt kein Zertifikat ausstellen.
         Der A-Eintrag beim Domainanbieter muss auf die oben genannte Adresse zeigen.</p>
    </div>
    <footer><button class="btn" data-act="close">Schlie{ss}en</button></footer></div>`;
}

async function runSetup(step){
  const labels = { "all":"Vollst{ae}ndige Einrichtung l{ae}uft. Das kann einige Minuten dauern...",
                   "caddy":"Caddy wird heruntergeladen...", "caddy-update":"Es wird nach Updates gesucht...",
                   "php":"PHP wird heruntergeladen und eingerichtet. Das dauert etwas...",
                   "tasks":"Geplante Aufgaben werden angelegt...", "firewall":"Firewallregeln werden gesetzt...",
                   "dirs":"Verzeichnisse werden gepr{ue}ft...", "uninstall":"Automatik wird entfernt...",
                   "php-off":"PHP wird abgeschaltet..." };
  const body = { step };
  if(step==="all"){ const p=$("#setupPhp"); body.php = p? p.checked : false; }
  const r = await api("/api/setup", body, labels[step]||"Wird ausgef{ue}hrt...");
  const detail = arr(r.notes).length? arr(r.notes).join("\n") : (r.message||"");
  toast(detail, r.ok?"ok":"bad", r.ok? (r.message||"Fertig") : "Fehlgeschlagen");
  await refresh(); render();
}

/* ---------- Ereignisse ---------- */
document.addEventListener("click", async (e)=>{
  const nav = e.target.closest(".navitem");
  if(nav){ go(nav.dataset.view); return; }
  const t = e.target.closest("[data-act]");
  if(!t) {
    if(e.target.id==="modal") closeModal();
    return;
  }
  const act = t.dataset.act, arg = t.dataset.arg;

  if(act==="close"){ closeModal(); return; }
  if(act==="apply"){ closeModal(); await doApply(); return; }
  if(act==="preview"){ await showPreview(); return; }
  if(act==="newsite"){ openSite(null); return; }
  if(act==="edit"){ openSite(arg); return; }
  if(act==="dns"){ await showDns(arg); return; }
  if(act==="dnsall"){ await showDns(null); return; }
  if(act==="savesite"){ await saveSite(); return; }
  if(act==="settype"){ collectSheet(); editing.type = arg; drawSheet(!editing.id); return; }
  if(act==="clearauth"){ editing.basicAuthUser=""; editing.basicAuthHash=""; collectSheet(); drawSheet(!editing.id);
                         toast("Zugriffsschutz entfernt. Noch speichern.","ok"); return; }
  if(act==="mkdir"){
    collectSheet();
    let p = editing.root;
    if(!p && editing.domains.length){ p = (ST.status.root||"C:\\caddy")+"\\www\\"+editing.domains[0].replace(/^https?:\/\//,"").split(":")[0]; }
    const r = await api("/api/folder", { path:p }, "Ordner wird angelegt...");
    toast(r.message, r.ok?"ok":"bad");
    if(r.ok){ editing.root = p; drawSheet(!editing.id); }
    return;
  }
  if(act==="toggle"){
    const r = await api("/api/site/toggle", { id:arg });
    setCfg(r); await refresh(); render(); return;
  }
  if(act==="del"){
    const s = arr(ST.config.sites).find(x=>x.id===arg);
    if(!confirm("Eintrag \""+(s?s.domains[0]:"")+"\" wirklich l{oe}schen?\n\nDie Dateien im Ordner bleiben erhalten.")) return;
    const r = await api("/api/site/delete", { id:arg }, "Wird entfernt...");
    setCfg(r); toast(r.message, r.ok?"ok":"bad"); await refresh(); render(); return;
  }
  if(act==="service"){
    const r = await api("/api/service", { action:arg }, "Wird ausgef{ue}hrt...");
    toast(r.message, r.ok?"ok":"bad"); await refresh(); render(); return;
  }
  if(act==="setup"){ await runSetup(arg); return; }
  if(act==="setup-all"){ await runSetup("all"); return; }
  if(act==="fix"){
    const r = await api("/api/fix", { id:arg }, "Wird umgesetzt...");
    toast(r.message, r.ok?"ok":"bad"); await refresh(); render(); return;
  }
  if(act==="import"){
    if(!confirm("Die bestehende Caddyfile einlesen?\n\nDie aktuelle Domainliste im Manager wird dabei ersetzt.")) return;
    const r = await api("/api/import", {}, "Caddyfile wird eingelesen...");
    setCfg(r);
    toast((r.message||"")+(arr(r.skipped).length?"\n\nNicht erkannt: "+arr(r.skipped).join(", "):""), r.ok?"ok":"bad");
    await refresh(); render(); return;
  }
  if(act==="mode"){
    if(arg==="manual" && !confirm("In den manuellen Modus wechseln?\n\nDie Caddyfile wird dann nicht mehr aus der Domainliste erzeugt.")) return;
    const r = await api("/api/mode", { mode:arg }, "Wird umgestellt...");
    setCfg(r); toast(r.message, r.ok?"ok":"bad"); await refresh(); render(); return;
  }
  if(act==="savesettings"){
    const r = await api("/api/settings", {
      email: $("#sEmail").value.trim(),
      logLevel: $("#sLevel").value,
      rollSize: $("#sRoll").value.trim(),
      rollKeep: $("#sKeep").value,
      globalExtra: $("#sExtra").value,
      snippets: $("#sSnippets").value,
      phpEnabled: $("#sPhp").checked,
      phpPoolSize: $("#sPool").value,
      phpDisableRisky: $("#sRisky").checked,
      idleMinutes: $("#sIdle").value,
      openBrowser: $("#sBrowser").checked
    }, "Wird gespeichert...");
    setCfg(r); toast(r.message, r.ok?"ok":"bad"); await refresh(); render(); return;
  }
  if(act==="logshow"){ await showLog(); return; }
  if(act==="cfvalidate"){
    const r = await api("/api/validate", { text: $("#cfText").value }, "Wird gepr{ue}ft...");
    const o = $("#cfOut"); o.classList.remove("hidden");
    o.textContent = (r.message||"") + "\n\n" + (r.validation||"");
    return;
  }
  if(act==="cfformat"){
    const r = await api("/api/format", { text: $("#cfText").value }, "Wird formatiert...");
    if(r.ok){ $("#cfText").value = r.text; toast("Formatiert.","ok"); } else { toast(r.message,"bad"); }
    return;
  }
  if(act==="cfsave"){
    const r = await api("/api/caddyfile", { text: $("#cfText").value }, "Wird gepr{ue}ft und {ue}bernommen...");
    const o = $("#cfOut"); o.classList.remove("hidden");
    o.textContent = (r.message||"") + (r.validation? "\n\n"+r.validation : "");
    toast(r.message, r.ok?"ok":"bad");
    await refresh(); return;
  }
  if(act==="restore"){
    if(!confirm("Diese Sicherung wiederherstellen?\n\nDie aktuelle Caddyfile wird vorher ebenfalls gesichert.")) return;
    const r = await api("/api/restore", { name:arg }, "Wird wiederhergestellt...");
    toast(r.message, r.ok?"ok":"bad"); await refresh(); render(); return;
  }
  if(act==="mkhash"){
    const r = await api("/api/hash", { password: $("#hashPw").value }, "Wird erzeugt...");
    const o = $("#hashOut"); o.classList.remove("hidden");
    o.textContent = r.ok ? r.hash : (r.message||"Fehlgeschlagen");
    if(r.ok) $("#hashPw").value="";
    return;
  }
});

document.addEventListener("keydown", (e)=>{
  if(e.key==="Escape" && !$("#modal").classList.contains("hidden")) closeModal();
});

$("#btnQuit").addEventListener("click", async ()=>{
  if(!confirm("Die Oberfl{ae}che beenden?\n\nDer Webserver l{ae}uft unver{ae}ndert weiter.")) return;
  await api("/api/quit", {});
  document.body.innerHTML = "<div class='byebye'>Der Manager wurde beendet. Der Webserver l{ae}uft weiter. "+
    "Dieses Fenster kann geschlossen werden.</div>";
});

/* ---------- Start ---------- */
(async function(){
  await refresh();
  render();
  api("/api/security").then(r=>{ if(r && r.ok) setSecBadge(r.findings); });
  pollTimer = setInterval(async ()=>{
    if(busyCount>0) return;
    if(!$("#modal").classList.contains("hidden")) return;
    const r = await api("/api/status");
    if(!r || !r.ok){
      offline++;
      if(offline>=3) showOffline();
      return;
    }
    offline = 0;
    if(r && r.ok){
      const before = ST.status? JSON.stringify([ST.status.caddyRunning, ST.status.phpPorts, ST.status.dirty]) : "";
      ST.status = r.status;
      paintStatus();
      const after = JSON.stringify([ST.status.caddyRunning, ST.status.phpPorts, ST.status.dirty]);
      if(before!==after && (view==="dash")) render();
    }
  }, 5000);
})();
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

if (-not (Start-ManagerServer $Config)) {
    exit 1
}

Write-Host ''
Write-Host2 '  Oberfl{ae}che beendet. Der Webserver l{ae}uft weiter.' 'DarkGray'
Write-Host ''
