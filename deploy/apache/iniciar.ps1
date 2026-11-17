# Arranca el proxy TLS local de Restia con el Apache de XAMPP (Actividad 3.2).
#   .\deploy\apache\iniciar.ps1                 arranca en https://localhost:8443 y http://localhost:8080
#   .\deploy\apache\iniciar.ps1 -Stop           lo detiene
# Antes: bash deploy/apache/generar-certificado.sh, `npm run build` en restia-web y `php artisan serve`.
param(
    [switch]$Stop,
    [string]$Apache = 'C:\xampp\apache',
    [string]$Web = (Join-Path $PSScriptRoot '..\..\..\restia-web\dist')
)

$deploy = (Resolve-Path $PSScriptRoot).Path -replace '\\', '/'
$pidFile = Join-Path $PSScriptRoot 'logs\httpd.pid'

if ($Stop) {
    if (Test-Path $pidFile) {
        Stop-Process -Id ([int](Get-Content $pidFile)) -Force -ErrorAction SilentlyContinue
        Remove-Item $pidFile -Force -ErrorAction SilentlyContinue
        Write-Output 'Proxy TLS detenido.'
    }
    return
}

if (-not (Test-Path (Join-Path $PSScriptRoot 'certs\restia.crt'))) {
    throw 'Falta el certificado: corre bash deploy/apache/generar-certificado.sh'
}
New-Item -ItemType Directory -Force (Join-Path $PSScriptRoot 'logs') | Out-Null

$env:APACHE_ROOT = $Apache -replace '\\', '/'
$env:RESTIA_DEPLOY = $deploy
$env:RESTIA_WEB = (Resolve-Path $Web).Path -replace '\\', '/'

$httpd = Join-Path $Apache 'bin\httpd.exe'
& $httpd -t -f "$deploy/restia-tls.conf"
if ($LASTEXITCODE -ne 0) { throw 'La configuración de Apache tiene errores.' }
Start-Process -FilePath $httpd -ArgumentList @('-f', "$deploy/restia-tls.conf") -WindowStyle Hidden
Write-Output 'Proxy TLS en https://localhost:8443 (HTTP 8080 redirige).'
