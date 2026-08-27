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
set "B64=%TEMP%\caddy-payload.b64"
>"%B64%" echo H4sIAAAAAAAC/71a/1fbOBL/nb9Cr+tXOwWb0F7vduGxbZpCyz4oHMkefRcoZ2wlcXFsryw3BJb//WZGsiMnTkrfu73stg3SaDT6zFeNsA6ESEUnkFGanAk+5IInAWf7zO7JNLM3NqzzNJWMPjDY3b0M/DCc2RvWxXTKyvHf0ihxz3w5Zorcnk6nQHJwx1eTEB+P33EgPPGjpDscrSEcRjES9iLJc6JsIMxx0jPJ++ktTw7heyPf4bXEeU/eSaA9TkfvI7FC2Dgd5Yqm6UB6qe2PeCI9oAXSbpoM4yiQx82kYZK7gSbRK87GmQZMAZ2NM/zjBqNIwwQU76IkVBQ7L//hteG/nd1f2u02zvoJj+kIDQfIcLIkIiY1Fj+3fyYWIr2LuNrAkqLgG9ZR
>>"%B64%" echo GPMLP5I49KqNH7CJPBBRJnf/nSY8VwJbSRHHtYl+NEHYB6EvuYTvV7u7J1HyLz9GrprurLg5ypoYHGW0nK1isDEsErJZdiFA6+5xlHBnkEsRJaMrCzW+xaofT/JRiz1s4D5SzPQ3/ERD5rgJwOP0eS5rGmrBCvaJT90jySeM/u7PMs5gigcyBTZ1hbqHqQC/+ZOdFtL9BEdhj7VtzB1QvBZzfVCC84FLtYUa9Y55MgIadyTZy5N3LUNY/Fi3nGeACa4CA5Ngbsxgyty+H8XsNejIXNVbRU1YKqbVgrnYnTBct8wh2d+DcujwEx+sbAYf9+TEDUP28ePuZLKb53aLbTKbwf+bjDRB/B9Z4MtgDBg/bjwa2gQ0nbre6PNgqJkgJ1awtlpYutvC6vrC
>>"%B64%" echo uU/i7N4SU8funn46PD7q9ufiMlM8PDHFFK2XRgOqgg7ZkOCyEIkybw2uJUGFzuDo1EMqMOtz7oedOO7zO+mYy72+iCZOq9rKkpV9xGAf7RX8yyG5sSg7+BucH1xuLn7dFZVRVpoFK2V1lwSZUunH4IgFBFuSY+e1KUeNnZJnmArug7Idq2BRwt469ljKLN/d3o4CPxn791HmBenE3mLVhJ9FXpRFw5mXipHdMv2g7sKEp0A8j5JvgJx7Doo44XKchsz9XUQM9nRR8LSQPR6gsHCCHtmIUwPY9Fbg6E7IQO0vl+HDztarR+fS099aD68eLXvRNUmQ2uEhpomVJDrAKVdGqJcoS0DrPObuabrQfKZJDTUz0DB1Dw1PIbiMgIl2vMWsd2k4K49JNluZ
>>"%B64%" echo f934LYkGIMcinTI7SRllVbs09jEsfPvAOgVsIqJ7n6SA5POO+4IL5WiyJC5wxjSCIE6LcBgDKZrIdhBHEI62v/2NlqGcc0M2xTWgaLAL/a+1aCcfwQ+5yFFm02ZetVkZCCkH2H6WgRvRSba/5imc1cXNtQyQBID6Gxeyn7q/wTSunmSC53lrWVP/C/GW/Fwl5mUnV+NrnLxM3E1u3m5wc8VQK8+HCAS61v5kZVAOwc879NN0jKnDoaLCVBL5bmWTzP5w0LchDm/fI+M3GRfXyGb/dfs5/Utqx2+t5SRuCS8vggBhnpsj8mFxlEs2hNTIQ9vwIBJ4E93UA+UUsazxVPK78Keav46SYepJRIbEoo1uILjdmlxxZnPT0HQdrH3ad2miKRyUYCN9k4oh
>>"%B64%" echo 7c69+H06gSq68tcbMKiqrKpH4XuMwk5lJ7XoSidXrJjL/2DWvZf4IJmbCqbHvYMkzC8iOXZsj/ShSFqLEXGuGJKFOChSs8qhSXMUMa2kvzcDXkOYQ7olaDrnPEhF6Fh4PCOsfUIpS3zW2d02HQtXe1GI5cs2lOvXgrjmbyTEgP3OcxSZ7HFQiAiS+EEe+BkH3fk6u6j9WhvfN1LFmcVpeltkC4aq0pE2QK+bFliNAW41byznB+2rBYBI/UsAJbO/FqL/EzaLx68dtDdLAlcfc5WTRGUxXRZGCwJFGUqiSkNIbBlRsSjbYvltlGXAUuUvzXavlEj7PQWffdNdS9KFfZAQdyoL1LkPYu089nMGu1NBglt2q5zIcKFt7qs25ncQ8ZB0v65vLZIphOWj
>>"%B64%" echo n1UL/mQXYy64e3rzFe456IrXHho8BQO7A8gDSY/HMFnSuIeRAG9Vcd5KJax/IksQBVlSPqoN9j/37fIw5ADE1QwwzUj5McQ4yMCImE+q0UvBMYi9uoxom3JId9V8oO86QIEpERUj00JBHkm7tVBYGNGIRPSXwqhfsaRIqoypZiGrT9IphczgJi9zkERZmm+KSTLSMBgy/qykTqH2mMLVBgRfJ7V1g8UKFWeE/D4qY49RuN8vbWSPlfvt4y57TEpM8jt7LNO9Ahgv2waPjXHj7LS3FDjumwMHAE9SmZHPCAnKEQPQsYTdOqbvqeP/WkNDS6iKBi1jSwvJ45yvdDgNPW2EoURwtFzY8mZmOJ/dWri8oqthJ0txytfdEXXDy7whQt1UAhhg22PF9bBc
>>"%B64%" echo aVAKPuJ3RJfFfsAdK4CblPNm0vpymb/4yXth4c1KW4IFRaNRpM2LggkWBRWrE4w1PDdYOQPg9tPD49Xgy8PjZXL14k0LuF8+1C9nc34ZFRnWxPsg0iLLBztXnuobuDkUz2C2g63L/GrTXiobLLo5ZSuvZvqSChRYkyPQWIcQknQ4KOiISCg04AZH94k3cKEgHIyZ3ctwU4PznToDGC+p+jCNoRx/grIvptMVil6ljLCq0LpQN4eqB1UxY67RhTL6t6wH9pLIeIbAQOVeL8ksvHVZoYeZF6r743TKBXipLyI/kU69ngba6v478N37tvvLlaO/uFcvyqHWG7gVr51vbdJNudKMlTwJ3ncFnNqlZm+ZuwdXZfbOq/SdYCgaBGNfXO20dUV9A0PYt9PZ
>>"%B64%" echo Bp3GU0UHMeXqOj34lkbhFVB7nSzjSahaEmgi6l5ZNkkxGjzQWBK3mlc++48U2GJ9wZ5RvqnasHM7u7xEI9umVthaRtk4ux76uQxGEXGrOr7fWYft7uucCwj8z9bS2o+1wyxaXIkw5vhUlBm+ZkZLLFELwLIJq6fg5SiLbsTKVuoIW09hypMgDTkb3UP0v89l+Owpi54C+I+C/hTgF5o1N0ZParmIxSQVjdY6Ap+C2RteU6bFXMeZmPr9dhWjjOhUPqkoNyXCVblnTmrUPbg31Tew1ryTDH1MsWVFegN35P35ZqhcD8a+K1E3zWa6QV6tJWa64a4EMQSmnu6SxFuEUUsJ85zRy9Q3P45Cal+7fuhnEsrW6u0IxgJCvUrV7OWvqsc6b/HPMTju9PoH
>>"%B64%" echo n4/63dP3B1TBtheLQeN4IP3CyfA88+OVJytrlHM+gYpuEYTa+Wt1pD3iCRdUIuGLGNMHmUJNbBYydFYDBCbSOMYpP9BqMU2UtGmYrirFTP5FhnzCXV12l5HE/QoFLEOf1te9qjuNz0umrR8keQHXBCjUsN7jyymVLmpqlrmYxph+HvuBLLigC/XmtlSC8OTb7tnHs+vD7oej65PO5+vzg3/+ftDr99CL2nb9dUX6wpALzdDkzdyOGBUTkOgYu1C2ewNYVGHGvUjFLTj1PKHrlz8oUy6AIJ325AzM8WMUhrrtWn5QA3aOm4PSNBBNkvVifDNye1DTJmHOXjbWOetAVobydIhXAtKAhigSNI3KA/GH+QMuzSjzQtBKT1wGTT1yrkOshpZ+wl6L06sl
>>"%B64%" echo FyCDXQ7eyjMaWuznPE79UAPzQ6DQwr8UFxjzI1l5st5yDk3jcWDHAGOjTjolmvtLnqtj/pAK5Fw3IWrlsiKgC9f+4r2pnEv8EXF/61ScllsJqlEJlhvoywCzrtmjDvQWlIaJYmIk0or3PH6XdHSzUC0ZfTqM1HV9Gm2J6j2k+QpENVW52WKMoWcss0mFJdT8TUfFV7gaM45+h1zV3ZbqLBVkrWvv4C7gGWrJOwHksS/ecJEBidfeS57yzE1FWsMbN1kP/d4D04jh74xM6foo6qW4ztG9WQ67XGgKZAzm/MKuFnlHSRAXIe8VN6GWIlJtc+VpFd2nVEbDGbCUtBPWAOZQDsVAdQwMZBsbTY8QSg9LNr6oCVHNkD6+r4D6MyX1RCrJ0fcAy66yOnoJ
>>"%B64%" echo 1nioIVQBig9lDLh3+RsYzU8e+G4QgkJQkc2BrLSD6kSNZH9vk6X8F4P5isQQJAAA
powershell -NoProfile -ExecutionPolicy Bypass -Command "$b=[Convert]::FromBase64String(((Get-Content '%B64%' -Raw) -replace '\s',''));$i=New-Object -TypeName IO.MemoryStream -ArgumentList (,$b);$g=New-Object -TypeName IO.Compression.GzipStream -ArgumentList $i,([IO.Compression.CompressionMode]::Decompress);$o=New-Object IO.MemoryStream;$g.CopyTo($o);$g.Close();[IO.File]::WriteAllBytes('C:\caddy\agent.ps1',$o.ToArray())"
>"%B64%" echo H4sIAAAAAAAC/7Va63LbNhb+r6dAZDWSUom62I4d6uKmttN4ktiprWxnK7kaiAQlxryVAGM5rmb2IfZx+q9vsk+y5wAkRUq06852o4klgAcH5/qdA0j9o2ARlExmODRkNS5C2xBTcRcwPujUe6WS4XtckJ9++onE/wakeqxPJgY1zbvJ5Pb2ttqLiUYX707Pp2/O3p9uEBnWVPg3zNPEUqTUH16fnU+P3/ywxVK+WbbDUtKrs9HplaLNk3JbMK5tLzi+unyjBNlYYFJBJ5OAeszRDB5a6xUX52/enx2PrjZXOP6cwzqPN4HQcsA6GkzBulIlZK4vGNBXplenl/84vRxXL08/XIxOp69PTi6r1+ToiFSB0rZI7ZntTWkY0rtavKxBxtVO90Brw6tT
>>"%B64%" echo bZCqrneq1w0iwojV6+S+hLZeCBFMQ8YDkJFNDd9ktb32LrgFH7KlLWpVxzeoQ6hhMM6J7zl3VXi8KpWsyDOE7XsE1azVdYKu9eYlxViJxKdotVpqrXRf/Pede2PaYQ3+e9TNEjVI+6DdjiXtpfTIahpEAsT0BPMEXy9pkJntdRdsWQupZ/rudHYHfqvtduv1mMFK/g2ZiEIPGNturabkrUu2c1bEtr6hqYwxVPXoYV3XMZpTNt7ZixwnK1BFgHf/RJwsx15WDVjhgDwVUSdD0m2TI2Snx3vkPGTF3EkloGKBCshQieVXQijdeqlGODsYSG5PUcRYABMjCp2p7WHYYGRxvdWiga0Zjh+ZFua/Zvhuy3BsUKz1Za9KtFgixUou50z4gUhi2VhAHKeb
>>"%B64%" echo H3+6fH/xcTS9PB19ujwfXb4+v3pzekkGQxkrjS260dmH04tPoxRZ0EzbVG9Ho49vT1+fAKeYalx9HYmFH9pfKRpQJ98zED4kUmBxrVhcx1JXQnqb6M6WzEChswqB9pxlJpNwUS6p4fInhcpn2OQz970pYCkmKi7MJUm8EFjHxvtcJ8+fgyc/j6s8kgmsMMOiDmd1jJfPhfHy1fcgeyBMclGC+0MkVVvy8VHAwmlA52yw367mBcD9AFMiR6jtxtd59iHIH5o8jUnkd2Y+vl0LTR9hvCvd4zUwWW0Bdk5jnmupuu2/KpblOyYLt/X2I8wPpJYo5IeMQrDX5o4/q2HZAhEmkxeAsD+8v/h+enH+/p8nZ5dgXR3WEMpJxcx6t+IBM9Bc+I5/y8LajHIm
>>"%B64%" echo 8Q/IMmCHQRKEbD51qTAWYIVfxrT5td18dV2LPzSvXyRT9aPaRHv0ef3bSgskrHi5SEvUAzmhxnjr3VeZuON+KGpItWFNmNlAGShf9jwKmflUE46TEt1YV2BlMisrZg5f4dGmCoiUthexTfkVLsHG0pAhCxxqMDTlhL/Y0V5UWi6WRfj/MPbibmu2a4dMqeMgp9oYmO3cr67Hv9yvJt71i6M6MJ/cS9YJ24oBlnezlSwxQMUdd5TCju2xTb1SMrkvDxzE1da4MeHX36I3Zd1QK7PhFmzykXYICuwgMfpIn7QmrVZsCljde9JifWKqmMoasHC1LCYBeQbFpFpFQAJiaWFqe2DhAFho1XqR0Ln4zORM4TarUvFotV3/ZWxOI8/+NWJJaEMkVzxf2Ab2
>>"%B64%" echo WwoSKywM/ZCkY9ln1bK92I+fTq9G0w+no7cXJ0k7Vpdls/rx4mpUTZSSEbygfDFlv0YAvjXVM60NB1xxwbgqe8aEU84osTTYPEJ/0hxhvSaRN//jd0fYc41cMehVicci4lATWuFqXDwIA7DP8uHRjOTtmWtAUlGALiPJ2uASe5/CAOmKOVi/mgiDNSnLQAXGb7+R9fC7qixPciddzWsYJlh/cRKckUOH/wNUoozbaJk64VNieWgLzgG/E3uvbb7RlaPYjTj0DN+JXK8W11lIAKwA1fpGc1606YzdUA/AifwMa4v3BKTEvjotTdJof6LNRWh6oAh0/FzYLBRkxkKIJ769wyYLQTEOB5l2ZR0ntklI4aMU2WILSNz6WgQBEjy+jpWBrmVwSEs+CBe4
>>"%B64%" echo KdKMq7ZZvX46TORxGbk8i7tfCMSkY0lxDp7XSYq44UOSZ1IkXRomyiigUJ55SJvEupXwr2miGni5+FlBE78dAJdSQ3C7w+ZCjxtdXD+u4lkdTA+xRJrD3JO4TG6aeR2K8RFvOxTxiLe//0C8y8Ph9mlvm4sa2p7JltpCuA6kbbm/6Azlw34LPk28clE9S4E+ZUOoN5e6a+Tk/KoZmyPyTHKMx3SYcHxqYo84B5vSSPiANjY3FtkMeTBLCpPtxpeJ7NnGQqTbE4iVDHo/XM6wXsnsSS5N4lSCsgWFRlUHsj7XpWEAz+NGVy1Lu154sO7gsPfOtHPwjDNnfUHzCPT/cJpDfoV7fNy+TnM4qQcx1zMz4arEyyShVC8HDUVwADxydVLx3MQAtFgF8piT
>>"%B64%" echo QULyWIJLCpXieBMDRwwEeR53sY/KmD7cRg0lUH0bNNINxo9DBjZC2WmVm2sN1904S49XfPNuJu6CMGN4wAybOsaCQghUeIOcno+mP366gGYcqtKn0ZvmobruORqW+s9M38AN5UoYu0xQIpcyMShHwmoelpNplHdQ/mKz2wDOD2USZ/GgfGubYjEw2RdIwKYcNPDCAIRocoM6bNBBHsIWDhvK1CMf8TKt31JTpT4Xd/iuh74v7pvN2VzfsWbwor1m04JBh8LrEAZuJPSdlzN4vYQRtsn6Dttlu6YFQ4OGJiy08LN/o+90rQNzF1nc0tDTd17Rl7N2G4aQtPoO3d2Fj3gF5gHLrrFPaXtV+s5lpk2xObcggZpQ0/0QdFgwl+kmDW/q91kZOwfwSmRk
>>"%B64%" echo h/DaS2R8ReG1n8rYZV0mN1QydliHdbuxmC8No3uwFtN8Rff2OomYrH24e5iV9IDSfXa4WpVe3M/8ZZPbXyEI9BkEJQubMLMqzXzz7t6l4dz29HYvAHsjRTdkLulo3X14782ocTMPfYBC/QsNa6hNvSeVjccWjC1wr97ZD5atjrZPIrvJqcebnIW21eB3XDC3GdmN8hWb+4x8Ois31s9XJRcOAiDEUoWD/rKN2yZCSaxdlRade9wDdWB6R9vdz9G0iRIW6LpZugyR1AnoDuRKSXPL7PlCwH7gzECDHvM+q5aLx4LMDl3JX0On3G/ZBGfrPWVZvRMs4dTsQAuhHsoTWvywGVLTjrjeaQfL1N4dZe9ORilwj4AaIx+tSoLOHHavzNNpt79JmIG8Dg04
>>"%B64%" echo 05MPvbXy2iu1dHEv2FI0qWPPPd1hlthUvreldSKXtoeCaSoMkqhRYhWpCJuZ9+u1+391cag7lIumsbAdkwCr/CpwEl79SPfq2uE+MIc4c33P5wEcSRvHvgdMKW+kU9nQVdnp2sua7REezmeNNHjJwTcNEUI8BjSEtMmo30EFdvfXCsTO2wsgdTT/Jhcv/k19pWFa5mZxAuYhP3PTMAaNoVK496YNJ3t6p1sOW/bmNNCVyXDYvA1hjH960n1NOOG5XMfkZuGqBDWKGQLgE9qkMQLzAD19rSxkewvILtHb8kfGIU+K1AMI1KdgwKq0JYgLcRwHrcqeWQSu9PICbkbjhsCdjLjtp4imwC8RT0K8EYUcPge+rQynuXy+DtQDaZdX4ZabD4H9djZu2U7x
>>"%B64%" echo w3BYZ2U2KnqbUaLoMSQKFmBk9LZDReNGCDl+739hoQXtQXOpK2CE8rMG8Dg3wifkxu0CgqkpP+vAQ4baFhKsSv1WXG/7iNLwBk11rizDuNQPiAHc+aAMIFoexv0t9aChhS4U/v/xO3S2//nXvzd767irdim2wGAJ8hqIhdZvBcNSqY9fHKqOL+7X06OMTo6GfdP+kmwL9iT+TXnYPxpA4xOT15GoBVRDxYl5pm31CHYya86qKX+UMZCknCX5Uxg/S7vwh3m+Y4BFx+l3I/GVzkIGqTqK9BHxhvKLQm1GRb8lx1ASufXH7wtAK61YDnBTd3jOIkauwMvgpC7IlhEByxW2WQhABPq1hW8OyoHPBU7KNCYyjcsL24TTSDlu5/ByqgxQ4UQwUBZRF1lo
>>"%B64%" echo kc2lCAHJQgwLIm8OF/K4IWdMH0OK1BzG8Fj0lkaBUHP1sqz6hu8GDhOw3rcsZK8AL+aJnXc5MXlxQy4t7weyH85Jne3Ik8M4avDY035LcVobO95Ueb6lhINPCuRiK4Cerg1mfa2Sod9ST3EBGh/f0YHKY+gsXuwtovJf9sbYDOB7OOyLxfBEmgz644UcquRLh5Bw6WeZt+noSlARcTVsAa8tSybHRHUfr6ubfIjqEw+PQTaHrr+2PrdUrPT7MKA5tuZIk15+WQ2SOV3mvrWSaRizff48WQ2HIzi54LcG1T6AlJfYArOc3gj7CxgcpofVXnwiTm/cYl71+GRVzAbLchmNI0/0oohZ0X3MY8wyNwQ5fkfKVfDHHKp8jrHEUlGlplrwFEmyen6m6bRc
>>"%B64%" echo k1jpqMAmeCfCNtyB9xqJJHCSLRLaYgsnlVaKs7kherJoQxTuf2ENdkznMuG3mVYZQI3jUWW1DH6wlu/gBoPyfjm/+7kPMXwDAMtIXI4AapT5j/X4pxe3t7ex+TUpiJRjC9JbSb5lEvV7eVfDiCpl/IkAO2eiGMWI78Ex2psjai5sruEqTSFHrf43Y5w0/vYNCvqYKMmYKV2KPvt7ABFaDiO0AzEshsafoffLIGO/ldJvguTTQRGTIEW60V2Qfv7ZlncJavAx9Jd3jyCgvC5SdzU6eSiPiy52t/O6gFjd3dTzqfEA4/Sm9UHez5gbCPzxz7gagFo2kxdNRZlLvQczF3o+eI5VYTNtn5aiaLDi/NzbyM93MjXj/IEWUX1n9VfSMN02/ZVR8gurelKs
>>"%B64%" echo BLUdQGv1nQt3oCesqc9ADz1WjS0DB39NUJ545ce+Bc5wrjdIs9PN/UwGNonDA1DgnfwN183DTVcfmu3Ywbab3V4xUv6VNHHMZw2RtYfqx/8L3lsbcF4nAAA=
powershell -NoProfile -ExecutionPolicy Bypass -Command "$b=[Convert]::FromBase64String(((Get-Content '%B64%' -Raw) -replace '\s',''));$i=New-Object -TypeName IO.MemoryStream -ArgumentList (,$b);$g=New-Object -TypeName IO.Compression.GzipStream -ArgumentList $i,([IO.Compression.CompressionMode]::Decompress);$o=New-Object IO.MemoryStream;$g.CopyTo($o);$g.Close();[IO.File]::WriteAllBytes('C:\caddy\panel\index.php',$o.ToArray())"
del /q "%B64%" >nul 2>&1
if not exist "C:\caddy\agent.ps1" (echo AGENT INSTALL FAILED & pause & exit /b 1)
if not exist "C:\caddy\panel\index.php" (echo PANEL INSTALL FAILED & pause & exit /b 1)
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
