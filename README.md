## Installation

1. Create Folder
   ```
   mkdir "C:\caddy" && mkdir "C:\caddy\www"

2. Copy Caddy to Folder
   ```
   powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "Invoke-WebRequest -Uri 'https://caddyserver.com/api/download?os=windows&arch=amd64' -OutFile 'C:\caddy\caddy.exe'"
   ```

3. [Create your caddyfile](./caddyfileexample)
   ```
   notepad C:\caddy\caddyfile
   ```

Start&Stop

1. Using Task Scheduler `recommendet`

   Create
   ```
   schtasks /create /tn "caddy start" /sc onstart /ru SYSTEM /rl HIGHEST /tr "C:\caddy\caddy.exe run --config C:\caddy\caddyfile" /f
   ```

   Start
   ```
   schtasks /end /tn "caddy start"
   ```

   Stop
   ```
   schtasks /end /tn "caddy start"
   ```

2. Manuelly

   Start
   ```
   C:\caddy\caddy.exe start
   ```

   Stop
   ```
   C:\caddy\caddy.exe stop
   ```

3. Refresh
   ```
   C:\caddy\caddy.exe reload
   ```
