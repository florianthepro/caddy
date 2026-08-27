$ErrorActionPreference = 'Stop'

$Root      = 'C:\caddy'
$Www       = Join-Path $Root 'www'
$Exe       = Join-Path $Root 'caddy.exe'
$MainCfg   = Join-Path $Root 'caddyfile'
$SitesCfg  = Join-Path $Root 'sites.caddyfile'
$TokenFile = Join-Path $Root 'cf_token.txt'
$LogDir    = Join-Path $Root 'logs'
$Log       = Join-Path $LogDir 'agent.log'
$ConflictL = Join-Path $LogDir 'dns-conflict.log'
$PhpExe    = 'C:\php\php-cgi.exe'
$PhpBind   = '127.0.0.1:9000'
$PanelDir  = Join-Path $Root 'panel'
$PanelBind = '127.0.0.1:8080'
$Proxied   = $true
$IdleWait  = 300000

$script:Zones    = $null
$script:ZoneTime = [datetime]::MinValue
$script:PubIp    = $null
$script:IpTime   = [datetime]::MinValue

function Write-Line([string]$File, [string]$Msg) {
    try {
        if (-not (Test-Path $LogDir)) { New-Item -ItemType Directory -Path $LogDir -Force | Out-Null }
        if ((Test-Path $File) -and (Get-Item $File).Length -gt 2MB) {
            $keep = Get-Content -Path $File -Tail 500
            Set-Content -Path $File -Value $keep
        }
        Add-Content -Path $File -Value ((Get-Date -Format 'yyyy-MM-dd HH:mm:ss') + '  ' + $Msg)
    } catch { }
}

function Log([string]$Msg)      { Write-Line $Log $Msg }
function Conflict([string]$Msg) { Write-Line $ConflictL $Msg; Write-Line $Log ('CONFLICT ' + $Msg) }

function Get-Token {
    if (-not (Test-Path $TokenFile)) { return $null }
    $t = ([IO.File]::ReadAllText($TokenFile)).Trim()
    if ($t.Length -lt 20) { return $null }
    return $t
}

function Get-PublicIp {
    if ($script:PubIp -and ((Get-Date) - $script:IpTime).TotalMinutes -lt 15) { return $script:PubIp }
    foreach ($u in @('https://icanhazip.com', 'https://api.ipify.org')) {
        try {
            $r = (Invoke-RestMethod -Uri $u -TimeoutSec 15).ToString().Trim()
            if ($r -match '^\d{1,3}(\.\d{1,3}){3}$') {
                $script:PubIp = $r
                $script:IpTime = Get-Date
                return $r
            }
        } catch { }
    }
    return $script:PubIp
}

function Invoke-CF([string]$Method, [string]$Path, $Body) {
    $t = Get-Token
    if (-not $t) { throw 'no token' }
    $h = @{ Authorization = 'Bearer ' + $t }
    $u = 'https://api.cloudflare.com/client/v4' + $Path
    if ($Body) {
        return Invoke-RestMethod -Method $Method -Uri $u -Headers $h -TimeoutSec 30 -ContentType 'application/json' -Body ($Body | ConvertTo-Json -Compress)
    }
    return Invoke-RestMethod -Method $Method -Uri $u -Headers $h -TimeoutSec 30
}

function Get-Zones {
    if ($script:Zones -and ((Get-Date) - $script:ZoneTime).TotalMinutes -lt 10) { return $script:Zones }
    $all = @()
    $page = 1
    while ($true) {
        $r = Invoke-CF 'GET' ('/zones?per_page=50&page=' + $page)
        if (-not $r.success) { throw 'zone list failed' }
        $all += $r.result
        if ($page -ge $r.result_info.total_pages) { break }
        $page++
    }
    $script:Zones = $all
    $script:ZoneTime = Get-Date
    return $all
}

function Get-ZoneFor([string]$Domain) {
    $best = $null
    foreach ($z in (Get-Zones)) {
        if ($Domain -eq $z.name -or $Domain.EndsWith('.' + $z.name)) {
            if (-not $best -or $z.name.Length -gt $best.name.Length) { $best = $z }
        }
    }
    return $best
}

function Get-ARecord($Zone, [string]$Name) {
    $r = Invoke-CF 'GET' ('/zones/' + $Zone.id + '/dns_records?type=A&name=' + [uri]::EscapeDataString($Name))
    if (-not $r.success) { throw 'record lookup failed' }
    if ($r.result.Count -gt 0) { return $r.result[0] }
    return $null
}

function Get-AnyRecord($Zone, [string]$Name) {
    $r = Invoke-CF 'GET' ('/zones/' + $Zone.id + '/dns_records?name=' + [uri]::EscapeDataString($Name))
    if (-not $r.success) { throw 'record lookup failed' }
    return $r.result
}

function Sync-Record([string]$Domain) {
    $ip = Get-PublicIp
    if (-not $ip) { Log ('no public ip, skipping ' + $Domain); return }
    $zone = Get-ZoneFor $Domain
    if (-not $zone) { Conflict ($Domain + ' has no matching Cloudflare zone'); return }

    $existing = Get-AnyRecord $zone $Domain
    $a = $existing | Where-Object { $_.type -eq 'A' } | Select-Object -First 1
    $other = $existing | Where-Object { $_.type -ne 'A' -and $_.type -ne 'TXT' }

    if ($other) {
        Conflict ($Domain + ' already has a ' + ($other[0].type) + ' record (' + $other[0].content + ') - not touching it')
        return
    }
    if ($a) {
        if ($a.content -eq $ip) { return }
        Conflict ($Domain + ' A record points to ' + $a.content + ' not ' + $ip + ' - not overwriting')
        return
    }
    $body = @{ type = 'A'; name = $Domain; content = $ip; ttl = 1; proxied = $Proxied }
    $r = Invoke-CF 'POST' ('/zones/' + $zone.id + '/dns_records') $body
    if ($r.success) { Log ('created A ' + $Domain + ' -> ' + $ip + ' proxied=' + $Proxied) }
    else { Conflict ($Domain + ' record creation rejected by Cloudflare') }
}

function Get-MainDomains {
    if (-not (Test-Path $MainCfg)) { return @() }
    $c = [IO.File]::ReadAllText($MainCfg)
    $c = [regex]::Replace($c, '(?m)^\s*#.*$', '')
    $out = @()
    foreach ($m in [regex]::Matches($c, '(?m)^([^\s#{}][^{}\n]*?)\s*\{')) {
        foreach ($p in ($m.Groups[1].Value -split '[,\s]+')) {
            $p = $p.Trim()
            if ($p -and $p.Contains('.')) { $out += ($p -replace '^https?://', '' -replace ':\d+$', '') }
        }
    }
    return $out
}

function Get-FolderDomains {
    if (-not (Test-Path $Www)) { return @() }
    $out = @()
    foreach ($d in (Get-ChildItem -Path $Www -Directory -ErrorAction SilentlyContinue)) {
        $n = $d.Name.ToLowerInvariant()
        if ($n -match '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$') { $out += $n }
    }
    return $out
}

function Build-Sites([string[]]$Domains) {
    $nl = [char]10
    $sb = New-Object Text.StringBuilder
    [void]$sb.Append('http://' + $PanelBind + ' {' + $nl)
    [void]$sb.Append("`troot * " + ($PanelDir -replace '\\', '/') + $nl)
    [void]$sb.Append("`tphp_fastcgi " + $PhpBind + $nl)
    [void]$sb.Append("`tfile_server" + $nl)
    [void]$sb.Append('}' + $nl)
    foreach ($d in ($Domains | Sort-Object)) {
        [void]$sb.Append($nl + $d + ' {' + $nl)
        [void]$sb.Append("`troot * " + (($Www -replace '\\', '/') + '/' + $d) + $nl)
        [void]$sb.Append("`tencode gzip zstd" + $nl)
        [void]$sb.Append("`tphp_fastcgi " + $PhpBind + $nl)
        [void]$sb.Append("`tfile_server" + $nl)
        [void]$sb.Append('}' + $nl)
    }
    return $sb.ToString()
}

function Sync-Config([string[]]$Domains) {
    $new = Build-Sites $Domains
    $old = ''
    if (Test-Path $SitesCfg) { $old = [IO.File]::ReadAllText($SitesCfg) }
    if ($new -eq $old) { return $false }

    $bak = $SitesCfg + '.bak'
    if (Test-Path $SitesCfg) { Copy-Item $SitesCfg $bak -Force }
    [IO.File]::WriteAllText($SitesCfg, $new)

    & $Exe validate --adapter caddyfile --config $MainCfg 2>$null | Out-Null
    if ($LASTEXITCODE -ne 0) {
        if (Test-Path $bak) { Copy-Item $bak $SitesCfg -Force } else { Remove-Item $SitesCfg -Force }
        Conflict 'generated site config was rejected by caddy validate - rolled back'
        return $false
    }
    Log ('site config updated: ' + ($Domains -join ', '))
    return $true
}

function Ensure-Processes {
    if (-not (Get-Process -Name php-cgi -ErrorAction SilentlyContinue)) {
        if (Test-Path $PhpExe) {
            $env:PHP_FCGI_MAX_REQUESTS = '0'
            Start-Process -FilePath $PhpExe -ArgumentList '-b', $PhpBind -WorkingDirectory 'C:\php' -WindowStyle Hidden
            Log 'started php-cgi'
            Start-Sleep -Seconds 2
        }
    }
    if (-not (Get-Process -Name caddy -ErrorAction SilentlyContinue)) {
        Start-Process -FilePath $Exe -ArgumentList 'run', '--adapter', 'caddyfile', '--config', $MainCfg -WorkingDirectory $Root -WindowStyle Hidden
        Log 'started caddy'
        Start-Sleep -Seconds 3
        return $true
    }
    return $false
}

function Invoke-Reload {
    Start-Process -FilePath $Exe -ArgumentList 'reload', '--adapter', 'caddyfile', '--config', $MainCfg -WorkingDirectory $Root -WindowStyle Hidden -Wait
    Log 'reloaded caddy'
}

function Invoke-Reconcile {
    $started = Ensure-Processes

    $folders = Get-FolderDomains
    $main = Get-MainDomains
    $managed = @($folders | Where-Object { $main -notcontains $_ })

    $changed = Sync-Config $managed
    if ($changed -and -not $started) { Invoke-Reload }

    if (Get-Token) {
        foreach ($d in $managed) {
            try { Sync-Record $d } catch { Log ('dns error for ' + $d + ': ' + $_.Exception.Message) }
        }
    }
}

if (-not (Test-Path $Www)) { New-Item -ItemType Directory -Path $Www -Force | Out-Null }
Log 'agent started'

$watcher = New-Object IO.FileSystemWatcher $Www, '*'
$watcher.IncludeSubdirectories = $false
$watcher.NotifyFilter = [IO.NotifyFilters]::DirectoryName

while ($true) {
    try { Invoke-Reconcile } catch { Log ('reconcile error: ' + $_.Exception.Message) }
    try {
        $r = $watcher.WaitForChanged([IO.WatcherChangeTypes]::All, $IdleWait)
        if (-not $r.TimedOut) { Start-Sleep -Seconds 3 }
    } catch { Start-Sleep -Seconds 60 }
}
