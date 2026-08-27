@echo off
setlocal

if not exist "C:\caddy" echo C:\caddy missing && exit /b
if not exist "C:\caddy\caddy.exe" echo C:\caddy\caddy.exe missing && exit /b

powershell -NoProfile -ExecutionPolicy Bypass -Command "Invoke-WebRequest -Uri 'https://raw.githubusercontent.com/florianthepro/caddy/main/caddyfile' -OutFile 'C:\caddy\caddyfile'"

schtasks /create /tn "caddy start" /sc onstart /ru SYSTEM /rl HIGHEST /tr "C:\caddy\caddy.exe run --config C:\caddy\caddyfile" /f

tasklist /FI "IMAGENAME eq caddy.exe" | find /I "caddy.exe" >nul || schtasks /run /tn "caddy start"

endlocal
