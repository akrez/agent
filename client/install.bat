Start "" /b mitmdump.exe
TASKKILL /IM mitmdump.exe /F
certutil -addstore root "%USERPROFILE%\.mitmproxy\mitmproxy-ca-cert.cer"
pause