@echo off
setlocal

if not exist "C:\caddy" echo C:\caddy missing && exit /b

if not exist "C:\caddy\caddy.exe" echo C:\caddy\caddy.exe missing && exit /b

powershell -NoProfile -ExecutionPolicy Bypass -Command "Invoke-WebRequest -Uri 'https://raw.githubusercontent.com/florianthepro/caddy/main/caddyfile' -OutFile 'C:\caddy\caddyfile'"

schtasks /end /tn "caddy start"
schtasks /delete /tn "caddy start" /f
schtasks /create /tn "caddy start" /sc onstart /ru SYSTEM /rl HIGHEST /tr "C:\caddy\caddy.exe run --config C:\caddy\caddyfile" /f
schtasks /run /tn "caddy start"

endlocal
