<#
.SYNOPSIS
    Arranca un MongoDB local como replica set de un nodo, para desarrollo y pruebas.

.DESCRIPTION
    Usa otro puerto (27018) y su propia carpeta de datos, así que no toca un MongoDB
    que ya esté instalado como servicio en el 27017. Las transacciones exigen replica set.

.EXAMPLE
    .\scripts\mongo-local.ps1
    .\scripts\mongo-local.ps1 -Stop
#>
param(
    [int]$Port = 27018,
    [string]$Mongod = "",
    [string]$Php = "php",
    [switch]$Stop
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$data = Join-Path $root ".mongo-data"

$listening = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue

if ($Stop) {
    if ($listening) {
        $proc = Get-Process -Id $listening[0].OwningProcess
        if ($proc.ProcessName -ne "mongod") { throw "El puerto $Port lo usa $($proc.ProcessName), no mongod." }
        Stop-Process -Id $proc.Id -Confirm:$false
        Write-Host "MongoDB local detenido."
    } else {
        Write-Host "No hay nada escuchando en el puerto $Port."
    }
    return
}

if (-not $listening) {
    if (-not $Mongod) {
        $found = Get-ChildItem "C:\Program Files\MongoDB\Server\*\bin\mongod.exe" -ErrorAction SilentlyContinue |
            Sort-Object { [version]($_.Directory.Parent.Name + ".0") } -Descending | Select-Object -First 1
        if (-not $found) { throw "No encontré mongod.exe. Instala MongoDB Server 8 o pasa -Mongod <ruta>." }
        $Mongod = $found.FullName
    }
    New-Item -ItemType Directory -Force $data | Out-Null
    $args = @("--replSet", "rs-restia", "--port", $Port, "--bind_ip", "127.0.0.1",
        "--dbpath", "`"$data`"", "--logpath", "`"$(Join-Path $data 'mongod.log')`"", "--logappend")
    Start-Process -FilePath $Mongod -ArgumentList $args -WindowStyle Hidden | Out-Null
    for ($i = 0; $i -lt 30 -and -not (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue); $i++) {
        Start-Sleep -Seconds 1
    }
    if (-not (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue)) {
        throw "mongod no arrancó. Revisa $(Join-Path $data 'mongod.log')."
    }
    Write-Host "mongod escuchando en 127.0.0.1:$Port."
}

& $Php (Join-Path $root "artisan") restia:replica-init --port=$Port
if ($LASTEXITCODE -ne 0) { throw "No se pudo iniciar el replica set." }
