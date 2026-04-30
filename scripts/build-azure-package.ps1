$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$deployDir = Join-Path $root 'deploy'
$zipPath = Join-Path $root 'azure-package.zip'
$endpointsDir = Join-Path $root 'endpoints'
$webConfigPath = Join-Path $root 'web.config'
$requiredEndpointFiles = @(
    (Join-Path $endpointsDir 'cliente-chat-buscar.php'),
    (Join-Path $endpointsDir 'cliente-chat-enviar.php')
)

if (-not (Test-Path -LiteralPath $endpointsDir)) {
    throw 'A pasta endpoints nao existe.'
}

foreach ($requiredFile in $requiredEndpointFiles) {
    if (-not (Test-Path -LiteralPath $requiredFile)) {
        throw "Arquivo obrigatorio ausente no pacote: $requiredFile"
    }
}

if (Test-Path -LiteralPath $deployDir) {
    Remove-Item -LiteralPath $deployDir -Recurse -Force
}

if (Test-Path -LiteralPath $zipPath) {
    Remove-Item -LiteralPath $zipPath -Force
}

New-Item -ItemType Directory -Path $deployDir | Out-Null
Copy-Item -LiteralPath $endpointsDir -Destination (Join-Path $deployDir 'endpoints') -Recurse
Copy-Item -LiteralPath $webConfigPath -Destination (Join-Path $deployDir 'web.config')

$packagedChatFile = Join-Path $deployDir 'endpoints\cliente-chat-buscar.php'
$packagedChatSendFile = Join-Path $deployDir 'endpoints\cliente-chat-enviar.php'

if (-not (Test-Path -LiteralPath $packagedChatFile) -or -not (Test-Path -LiteralPath $packagedChatSendFile)) {
    throw 'Os endpoints raiz do chat nao foram copiados para a pasta deploy.'
}

Compress-Archive -Path (Join-Path $deployDir '*') -DestinationPath $zipPath -Force

Write-Host "Pacote criado em: $zipPath"
