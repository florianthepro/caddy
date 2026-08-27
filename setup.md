create task
```
powershell -NoProfile -ExecutionPolicy Bypass -Command "$p='%TEMP%\caddy-install.bat'; iwr 'https://raw.githubusercontent.com/florianthepro/caddy/main/caddy.bat' -OutFile $p; & $p"
```
C:\caddy\caddy.exe reload
