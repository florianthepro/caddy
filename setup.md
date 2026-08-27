create task
```
schtasks /create /tn "caddy webserver" /sc minute /mo 5 /ru SYSTEM /rl HIGHEST /tr "powershell.exe -NoProfile -ExecutionPolicy Bypass -Command ""Invoke-WebRequest -Uri 'https://raw.githubusercontent.com/florianthepro/caddy/main/caddy.bat' -OutFile 'C:\caddy\caddy.bat'; cmd.exe /c C:\caddy\caddy.bat""" /f
```
