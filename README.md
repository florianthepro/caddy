Installation
1. Create Folder
   ```
   mkdir "C:\caddy" && mkdir "C:\caddy\www"
2. Copy Caddy to Folder
   ```
   powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "Invoke-WebRequest -Uri 'https://caddyserver.com/api/download?os=windows&arch=amd64' -OutFile 'C:\caddy\caddy.exe'"
   ```
3. Create caddyfile `use `[example](./caddyfileexample)` as a reference`
   ```
   notepad C:\caddy\caddyfile
   ```

Start&Stop
1. Using Task Scheduler `recommendet`
   1.1. Create
   ```
   schtasks /create /tn "caddy start" /sc onstart /ru SYSTEM /rl HIGHEST /tr "C:\caddy\caddy.exe run --config C:\caddy\caddyfile" /f
   ```
   1.2. Start
   ```
   schtasks /end /tn "caddy start"
   ```
   1.3. Stop
   ```
   schtasks /end /tn "caddy start"
   ```
2. Manuelly
   2.1. Start
   ```
   C:\caddy\caddy.exe start
   ```
   2.2. Stop
   ```
   C:\caddy\caddy.exe stop
   ```

Refresh
   ```
   C:\caddy\caddy.exe reload
   ```
