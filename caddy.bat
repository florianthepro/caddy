@echo off
setlocal EnableExtensions

net session >nul 2>&1 || (
    powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
    exit /b
)

set "EXE=C:\caddy\caddy.exe"
set "CFG=C:\caddy\caddyfile"
set "WD=C:\caddy\watchdog.ps1"
set "INI=C:\php\php.ini"
set "ARCH=amd64"
if /i "%PROCESSOR_ARCHITECTURE%"=="ARM64" set "ARCH=arm64"
for /f %%T in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd-HHmmss"') do set "TS=%%T"

for %%D in ("C:\caddy" "C:\caddy\www" "C:\caddy\data" "C:\caddy\logs" "C:\caddy\backup" "C:\php") do if not exist "%%~D" mkdir "%%~D"

for %%T in ("caddy agent" "caddy start" "caddy watchdog" "caddy reload" "php fastcgi" "php watchdog") do schtasks /change /tn %%T /disable >nul 2>&1
schtasks /end /tn "caddy agent" >nul 2>&1
powershell -NoProfile -Command "Start-Sleep -Seconds 3"
if exist "%EXE%" "%EXE%" stop >nul 2>&1
taskkill /F /IM caddy.exe >nul 2>&1
taskkill /F /IM php-cgi.exe >nul 2>&1

set "DLURL=https://caddyserver.com/api/download?os=windows&arch=%ARCH%&p=github.com/caddy-dns/cloudflare&p=github.com/mholt/caddy-dynamicdns"
set "MODS=%TEMP%\caddy-modules.txt"
set "NEEDDL=1"
"%EXE%" list-modules >"%MODS%" 2>nul
findstr /x "dns.providers.cloudflare" "%MODS%" >nul 2>&1 && findstr /x "dynamic_dns" "%MODS%" >nul 2>&1 && set "NEEDDL=0"
if "%NEEDDL%"=="1" powershell -NoProfile -ExecutionPolicy Bypass -Command "$ProgressPreference='SilentlyContinue';[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12;Invoke-WebRequest -Uri '%DLURL%' -OutFile 'C:\caddy\caddy.exe.new' -UseBasicParsing -TimeoutSec 900"
if exist "C:\caddy\caddy.exe.new" "C:\caddy\caddy.exe.new" version >nul 2>&1 && move /y "C:\caddy\caddy.exe.new" "%EXE%" >nul
del /q "C:\caddy\caddy.exe.new" >nul 2>&1
"%EXE%" version >nul 2>&1 || (echo CADDY DOWNLOAD FAILED & pause & exit /b 1)
set "DNSOK=1"
"%EXE%" list-modules >"%MODS%" 2>nul
findstr /x "dns.providers.cloudflare" "%MODS%" >nul 2>&1 || set "DNSOK=0"
findstr /x "dynamic_dns" "%MODS%" >nul 2>&1 || set "DNSOK=0"
del /q "%MODS%" >nul 2>&1
if "%DNSOK%"=="0" echo DNS PLUGINS MISSING - DNS MANAGEMENT DISABLED

"C:\php\php-cgi.exe" -v >nul 2>&1 || powershell -NoProfile -ExecutionPolicy Bypass -Command "$ProgressPreference='SilentlyContinue';[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12;$z=Join-Path $env:TEMP 'php-setup.zip';foreach($u in @('https://windows.php.net/downloads/releases/latest/php-8.5-nts-Win32-vs17-x64.zip','https://windows.php.net/downloads/releases/latest/php-8.4-nts-Win32-vs17-x64.zip','https://windows.php.net/downloads/releases/latest/php-8.3-nts-Win32-vs16-x64.zip')){try{Invoke-WebRequest -Uri $u -OutFile $z -UseBasicParsing;break}catch{}};if(Test-Path $z){Expand-Archive -Path $z -DestinationPath 'C:\php' -Force;Remove-Item $z -Force}"
"C:\php\php-cgi.exe" -v >nul 2>&1 || echo PHP DOWNLOAD FAILED

if not exist "%INI%" if exist "C:\php\php.ini-production" copy /y "C:\php\php.ini-production" "%INI%" >nul
set "ADDINI=0"
if exist "%INI%" set "ADDINI=1"
if exist "%INI%" findstr /c:"[caddy]" "%INI%" >nul 2>&1 && set "ADDINI=0"
if "%ADDINI%"=="1" (
    >>"%INI%" echo.
    >>"%INI%" echo [caddy]
    >>"%INI%" echo extension_dir = "C:\php\ext"
    >>"%INI%" echo cgi.fix_pathinfo = 1
    >>"%INI%" echo cgi.force_redirect = 0
    >>"%INI%" echo fastcgi.impersonate = 0
    >>"%INI%" echo display_startup_errors = Off
    >>"%INI%" echo max_execution_time = 300
    >>"%INI%" echo memory_limit = 512M
    >>"%INI%" echo upload_max_filesize = 100M
    >>"%INI%" echo post_max_size = 100M
    >>"%INI%" echo date.timezone = Europe/Berlin
    >>"%INI%" echo extension = mbstring
    >>"%INI%" echo extension = openssl
    >>"%INI%" echo extension = curl
    >>"%INI%" echo extension = fileinfo
    >>"%INI%" echo extension = gd
    >>"%INI%" echo extension = intl
    >>"%INI%" echo extension = exif
    >>"%INI%" echo extension = zip
    >>"%INI%" echo extension = sodium
    >>"%INI%" echo extension = mysqli
    >>"%INI%" echo extension = pdo_mysql
    >>"%INI%" echo extension = sqlite3
    >>"%INI%" echo extension = pdo_sqlite
    >>"%INI%" echo opcache.enable = 1
)
if "%ADDINI%"=="1" if exist "C:\php\ext\php_opcache.dll" echo zend_extension = php_opcache.dll>>"%INI%"
powershell -NoProfile -ExecutionPolicy Bypass -Command "$i='C:\php\php.ini'; if ((Test-Path $i) -and -not (Test-Path 'C:\php\ext\php_opcache.dll')) { $c=Get-Content -Path $i; $n=$c -replace '^\s*zend_extension\s*=\s*(php_)?opcache(\.dll)?\s*$',';$0'; if (Compare-Object $c $n) { Copy-Item $i 'C:\caddy\backup\php.ini.bak' -Force; Set-Content -Path $i -Value $n } }"

if exist "%CFG%" copy /y "%CFG%" "C:\caddy\backup\caddyfile.%TS%" >nul
if exist "%CFG%" findstr /i /c:"404: Not Found" "%CFG%" >nul 2>&1 && move /y "%CFG%" "C:\caddy\backup\caddyfile.broken.%TS%" >nul
if exist "%CFG%" for %%A in ("%CFG%") do if %%~zA LSS 5 move /y "%CFG%" "C:\caddy\backup\caddyfile.empty.%TS%" >nul
if not exist "%CFG%" powershell -NoProfile -ExecutionPolicy Bypass -Command "$ProgressPreference='SilentlyContinue';[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12;try{Invoke-WebRequest -Uri 'https://raw.githubusercontent.com/florianthepro/caddy/main/caddyfile' -OutFile 'C:\caddy\caddyfile' -UseBasicParsing}catch{}"
if not exist "%CFG%" (
    >"%CFG%" echo :80 {
    >>"%CFG%" echo     root * C:/caddy/www
    >>"%CFG%" echo     encode gzip zstd
    >>"%CFG%" echo     php_fastcgi 127.0.0.1:9000
    >>"%CFG%" echo     file_server
    >>"%CFG%" echo }
)

set "TOK=C:\caddy\cf_token.txt"
set "DNSF=C:\caddy\dns.caddyfile"
set "SETUPDNS=n"
if "%DNSOK%"=="1" if not exist "%TOK%" echo.
if "%DNSOK%"=="1" if not exist "%TOK%" echo CLOUDFLARE DNS MANAGEMENT - TOKEN PERMISSIONS: Zone.Zone Read + Zone.DNS Edit
if "%DNSOK%"=="1" if not exist "%TOK%" set /p "SETUPDNS=SETUP NOW [j/N] "
if /i "%SETUPDNS%"=="j" powershell -NoProfile -ExecutionPolicy Bypass -Command "$s=Read-Host -Prompt 'Cloudflare API Token' -AsSecureString;$b=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($s);$t=[Runtime.InteropServices.Marshal]::PtrToStringAuto($b).Trim();[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b);if($t.Length -gt 20){[IO.File]::WriteAllText('C:\caddy\cf_token.txt',$t)}"
if exist "%TOK%" icacls "%TOK%" /inheritance:r /grant:r "*S-1-5-18:(R)" "*S-1-5-32-544:(F)" >nul 2>&1
set "DNSON=0"
if "%DNSOK%"=="1" if exist "%TOK%" powershell -NoProfile -ExecutionPolicy Bypass -Command "try{[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12;$t=([IO.File]::ReadAllText('C:\caddy\cf_token.txt')).Trim();$h=@{Authorization='Bearer '+$t};$r=Invoke-RestMethod -Uri 'https://api.cloudflare.com/client/v4/user/tokens/verify' -Headers $h -TimeoutSec 30;if(-not ($r.success -and $r.result.status -eq 'active')){exit 1};$z=Invoke-RestMethod -Uri 'https://api.cloudflare.com/client/v4/zones' -Headers $h -TimeoutSec 30;if(-not $z.success){exit 1};exit 0}catch{exit 1}" && set "DNSON=1"
if "%DNSOK%"=="1" if exist "%TOK%" if "%DNSON%"=="0" echo CLOUDFLARE TOKEN REJECTED - DNS MANAGEMENT DISABLED
if "%DNSON%"=="1" powershell -NoProfile -ExecutionPolicy Bypass -Command "$t=([IO.File]::ReadAllText('C:\caddy\cf_token.txt')).Trim();$n=[char]10;$b=[char]9;$s='acme_dns cloudflare '+$t+$n+'dynamic_dns {'+$n+$b+'provider cloudflare '+$t+$n+$b+'dynamic_domains'+$n+$b+'versions ipv4'+$n+$b+'check_interval 5m'+$n+$b+'ip_source simple_http https://icanhazip.com'+$n+$b+'ip_source simple_http https://api.ipify.org'+$n+'}'+$n;[IO.File]::WriteAllText('C:\caddy\dns.caddyfile',$s)"
if "%DNSON%"=="1" icacls "%DNSF%" /inheritance:r /grant:r "*S-1-5-18:(R)" "*S-1-5-32-544:(F)" >nul 2>&1
if "%DNSON%"=="1" copy /y "%CFG%" "C:\caddy\backup\caddyfile.predns" >nul
if "%DNSON%"=="1" powershell -NoProfile -ExecutionPolicy Bypass -Command "$p='C:\caddy\caddyfile';$c=[IO.File]::ReadAllText($p);if($c -notmatch 'import\s+C:/caddy/dns\.caddyfile'){$n=[char]10;$i=[char]9+'import C:/caddy/dns.caddyfile';if($c -match '^\s*\{'){$c=$c -replace '^(\s*\{)',('$1'+$n+$i)}else{$c='{'+$n+$i+$n+'}'+$n+$n+$c};[IO.File]::WriteAllText($p,$c)}"
if "%DNSON%"=="1" "%EXE%" validate --adapter caddyfile --config "%CFG%" >nul 2>&1 || copy /y "C:\caddy\backup\caddyfile.predns" "%CFG%" >nul
if "%DNSON%"=="0" powershell -NoProfile -ExecutionPolicy Bypass -Command "$p='C:\caddy\caddyfile';$c=[IO.File]::ReadAllText($p);$d=$c -replace '(?m)^[ \t]*import[ \t]+C:/caddy/dns\.caddyfile[ \t]*\r?\n?','';$d=$d -replace '^\{\s*\}\s*','';if($d -ne $c){[IO.File]::WriteAllText($p,$d)}"
set "DNSSTATE=OFF"
findstr /c:"import C:/caddy/dns.caddyfile" "%CFG%" >nul 2>&1 && set "DNSSTATE=ON"

if not exist "C:\caddy\panel" mkdir "C:\caddy\panel"
powershell -NoProfile -ExecutionPolicy Bypass -Command "$ProgressPreference='SilentlyContinue';[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12;$b='https://raw.githubusercontent.com/florianthepro/caddy/';foreach($f in @(,@('agent.ps1','C:\caddy\agent.ps1','Invoke-Reconcile'))+@(,@('panel/index.php','C:\caddy\panel\index.php','Caddy Panel'))){foreach($r in @('main','claude/caddy-setup-bat-php-lv4i7c')){try{$t=$f[1]+'.new';Invoke-WebRequest -Uri ($b+$r+'/'+$f[0]) -OutFile $t -UseBasicParsing -TimeoutSec 60;if(([IO.File]::ReadAllText($t)).Contains($f[2])){Move-Item $t $f[1] -Force;break}else{Remove-Item $t -Force}}catch{}}}"
if not exist "C:\caddy\agent.ps1" (echo AGENT DOWNLOAD FAILED & pause & exit /b 1)
del /q "%WD%" >nul 2>&1
del /q "C:\caddy\data\caddyfile.sha256" >nul 2>&1

if not exist "C:\caddy\sites.caddyfile" (
    >"C:\caddy\sites.caddyfile" echo http://127.0.0.1:8080 {
    >>"C:\caddy\sites.caddyfile" echo     root * C:/caddy/panel
    >>"C:\caddy\sites.caddyfile" echo     php_fastcgi 127.0.0.1:9000
    >>"C:\caddy\sites.caddyfile" echo     file_server
    >>"C:\caddy\sites.caddyfile" echo }
)
copy /y "%CFG%" "C:\caddy\backup\caddyfile.presites" >nul
powershell -NoProfile -ExecutionPolicy Bypass -Command "$p='C:\caddy\caddyfile';$c=[IO.File]::ReadAllText($p);if($c -notmatch 'import\s+C:/caddy/sites\.caddyfile'){$n=[char]10;[IO.File]::WriteAllText($p,$c.TrimEnd()+$n+$n+'import C:/caddy/sites.caddyfile'+$n)}"
"%EXE%" validate --adapter caddyfile --config "%CFG%" >nul 2>&1 || copy /y "C:\caddy\backup\caddyfile.presites" "%CFG%" >nul

>"C:\caddy\panel.bat" echo @echo off
>>"C:\caddy\panel.bat" echo start "" http://127.0.0.1:8080

for %%T in ("caddy start" "caddy watchdog" "caddy reload" "php fastcgi" "php watchdog") do schtasks /delete /tn %%T /f >nul 2>&1
powershell -NoProfile -ExecutionPolicy Bypass -Command "$a=New-ScheduledTaskAction -Execute 'powershell.exe' -Argument '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File C:\caddy\agent.ps1';$t=New-ScheduledTaskTrigger -AtStartup;$p=New-ScheduledTaskPrincipal -UserId 'S-1-5-18' -LogonType ServiceAccount -RunLevel Highest;$g=New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew;Register-ScheduledTask -TaskName 'caddy agent' -Action $a -Trigger $t -Principal $p -Settings $g -Force | Out-Null"
schtasks /query /tn "caddy agent" >nul 2>&1 || schtasks /create /tn "caddy agent" /sc onstart /ru SYSTEM /rl HIGHEST /tr "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File C:\caddy\agent.ps1" /f >nul

netsh advfirewall firewall delete rule name="Caddy HTTP HTTPS" >nul 2>&1
netsh advfirewall firewall add rule name="Caddy HTTP HTTPS" dir=in action=allow protocol=TCP localport=80,443 >nul

powershell -NoProfile -Command "Get-NetTCPConnection -State Listen -LocalPort 80,443 -EA SilentlyContinue | ForEach-Object { $p = Get-Process -Id $_.OwningProcess -EA SilentlyContinue; if ($p -and $p.ProcessName -ne 'caddy') { 'PORT ' + $_.LocalPort + ' BLOCKED BY ' + $p.ProcessName } } | Sort-Object -Unique"

"%EXE%" fmt --overwrite "%CFG%" >nul 2>&1
"%EXE%" validate --adapter caddyfile --config "%CFG%"
if errorlevel 1 (
    echo CADDYFILE INVALID - NOT STARTED
    echo BACKUP: C:\caddy\backup\caddyfile.%TS%
    if exist "C:\caddy\backup\caddyfile.good" echo RESTORE: copy /y "C:\caddy\backup\caddyfile.good" "%CFG%"
    pause
    exit /b 1
)

copy /y "%CFG%" "C:\caddy\backup\caddyfile.good" >nul
taskkill /F /IM caddy.exe >nul 2>&1
schtasks /run /tn "caddy agent" >nul
powershell -NoProfile -Command "Start-Sleep -Seconds 10"

tasklist /FI "IMAGENAME eq caddy.exe" | find /I "caddy.exe" >nul && echo CADDY RUNNING || echo CADDY NOT RUNNING
tasklist /FI "IMAGENAME eq php-cgi.exe" | find /I "php-cgi.exe" >nul && echo PHP RUNNING || echo PHP NOT RUNNING
"%EXE%" version
"C:\php\php-cgi.exe" -v
echo DNS MANAGEMENT %DNSSTATE%
echo PANEL http://127.0.0.1:8080 - C:\caddy\panel.bat

endlocal
pause
