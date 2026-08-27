## Installation

Create Folder
```
mkdir "C:\caddy" && mkdir "C:\caddy\www"
```

Copy Caddy to Folder
```
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "Invoke-WebRequest -Uri 'https://caddyserver.com/api/download?os=windows&arch=amd64' -OutFile 'C:\caddy\caddy.exe'"
```

[Create your caddyfile](./caddyfileexample)
```
notepad C:\caddy\caddyfile
```

---

## Start&Stop
| Using | Create | Start | Stop |
|---|---|---|
| Task Scheduler `recommendet` | schtasks /create /tn "caddy start" /sc onstart /ru SYSTEM /rl HIGHEST /tr "C:\caddy\caddy.exe run --config C:\caddy\caddyfile" /f | schtasks /run /tn "caddy start" | schtasks /end /tn "caddy start" |
| Manuelly | / | C:\caddy\caddy.exe start | C:\caddy\caddy.exe stop |

Refresh
```
C:\caddy\caddy.exe reload
```
