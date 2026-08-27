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

for %%T in ("caddy start" "caddy watchdog" "caddy reload" "php fastcgi" "php watchdog") do schtasks /change /tn %%T /disable >nul 2>&1
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

>"%WD%" echo $ErrorActionPreference = 'SilentlyContinue'
>>"%WD%" echo $env:PHP_FCGI_MAX_REQUESTS = '0'
>>"%WD%" echo $exe = 'C:\caddy\caddy.exe'
>>"%WD%" echo $cfg = 'C:\caddy\caddyfile'
>>"%WD%" echo $hsh = 'C:\caddy\data\caddyfile.sha256'
>>"%WD%" echo if (-not (Get-Process -Name php-cgi)) { Start-Process -FilePath 'C:\php\php-cgi.exe' -ArgumentList '-b','127.0.0.1:9000' -WorkingDirectory 'C:\php' -WindowStyle Hidden }
>>"%WD%" echo if (-not (Test-Path $cfg)) { exit }
>>"%WD%" echo $new = (Get-FileHash -Path $cfg -Algorithm SHA256).Hash
>>"%WD%" echo $old = ''
>>"%WD%" echo if (Test-Path $hsh) { $old = (Get-Content -Path $hsh -Raw).Trim() }
>>"%WD%" echo $run = Get-Process -Name caddy
>>"%WD%" echo if (-not $run) { Start-Process -FilePath $exe -ArgumentList 'run','--adapter','caddyfile','--config',$cfg -WorkingDirectory 'C:\caddy' -WindowStyle Hidden; Start-Sleep -Seconds 3; Set-Content -Path $hsh -Value $new }
>>"%WD%" echo if ($run -and ($new -ne $old)) { Start-Process -FilePath $exe -ArgumentList 'reload','--adapter','caddyfile','--config',$cfg -WorkingDirectory 'C:\caddy' -WindowStyle Hidden -Wait; Set-Content -Path $hsh -Value $new }

for %%T in ("caddy start" "caddy watchdog" "caddy reload" "php fastcgi" "php watchdog") do schtasks /delete /tn %%T /f >nul 2>&1
schtasks /create /tn "caddy start" /sc onstart /ru SYSTEM /rl HIGHEST /tr "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File C:\caddy\watchdog.ps1" /f >nul
schtasks /create /tn "caddy watchdog" /sc minute /mo 5 /ru SYSTEM /rl HIGHEST /tr "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File C:\caddy\watchdog.ps1" /f >nul

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
del /q "C:\caddy\data\caddyfile.sha256" >nul 2>&1
schtasks /run /tn "caddy watchdog" >nul
powershell -NoProfile -Command "Start-Sleep -Seconds 10"

tasklist /FI "IMAGENAME eq caddy.exe" | find /I "caddy.exe" >nul && echo CADDY RUNNING || echo CADDY NOT RUNNING
tasklist /FI "IMAGENAME eq php-cgi.exe" | find /I "php-cgi.exe" >nul && echo PHP RUNNING || echo PHP NOT RUNNING
"%EXE%" version
"C:\php\php-cgi.exe" -v
echo DNS MANAGEMENT %DNSSTATE%

endlocal
pause
