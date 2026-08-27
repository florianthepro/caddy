@echo off
setlocal

if not exist "C:\caddy\" mkdir "C:\caddy"

if not exist "C:\caddy\caddy.exe" powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "Invoke-WebRequest -Uri 'https://caddyserver.com/api/download?os=windows&arch=amd64' -OutFile 'C:\caddy\caddy.exe'"

powershell -NoProfile -ExecutionPolicy Bypass -Command "Invoke-WebRequest -Uri 'https://raw.githubusercontent.com/florianthepro/caddy/main/caddyfile' -OutFile 'C:\caddy\caddyfile'"

schtasks /create /tn "caddy start" /sc onstart /ru SYSTEM /rl HIGHEST /tr "C:\caddy\caddy.exe run --config C:\caddy\caddyfile" /f

tasklist /FI "IMAGENAME eq caddy.exe" | find /I "caddy.exe" >nul || schtasks /run /tn "caddy start"

endlocal
