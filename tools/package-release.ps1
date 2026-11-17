param(
    [Parameter(Mandatory=$true)][string]$OutputDirectory,
    [string]$PhpBinary='php',
    [string]$ComposerPhar='composer.phar'
)
$ErrorActionPreference='Stop'
$sourceRoot=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$releaseRoot=[IO.Path]::GetFullPath($OutputDirectory)
if(Test-Path -LiteralPath $releaseRoot){throw 'Choose a new empty release directory; existing releases are never overwritten.'}
if($releaseRoot.StartsWith($sourceRoot+[IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase)){throw 'Release output must be outside the application source.'}
New-Item -ItemType Directory -Path $releaseRoot | Out-Null
$allowed=@('app','config','database/migrations','database/seeders','database/seed-assets','routes','docs','deploy','tools','bootstrap/app.php','bootstrap/providers.php','public/index.php','public/.htaccess','artisan','composer.json','composer.lock','.env.example','README.md')
foreach($relativePath in $allowed){
    $item=Join-Path $sourceRoot $relativePath
    if(!(Test-Path -LiteralPath $item)){throw ('Required source is missing: '+$relativePath)}
    $destination=Join-Path $releaseRoot $relativePath
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $destination) | Out-Null
    Copy-Item -LiteralPath $item -Destination $destination -Recurse
}
foreach($relativePath in @('bootstrap/cache','storage/app/public','storage/app/private','storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs')){
    New-Item -ItemType Directory -Force -Path (Join-Path $releaseRoot $relativePath) | Out-Null
}
Push-Location $releaseRoot
try {
    & $PhpBinary -d extension=sodium -d extension=curl -d extension=gd $ComposerPhar install --no-dev --prefer-dist --no-interaction --classmap-authoritative --no-scripts
    if($LASTEXITCODE -ne 0){throw 'Production dependency installation failed.'}
    & $PhpBinary -d extension=sodium -d extension=curl -d extension=gd $ComposerPhar check-platform-reqs --no-dev
    if($LASTEXITCODE -ne 0){throw 'Build-host platform check failed. Target hosting still needs independent verification.'}
    $forbidden=Get-ChildItem -LiteralPath $releaseRoot -Recurse -File -Force | Where-Object { $_.Name -in @('.env','auth.json','database.sqlite','mysql-private.json','preview-user.json') }
    if($forbidden){throw 'A private or local-runtime file entered the release.'}
    $manifest=Get-ChildItem -LiteralPath $releaseRoot -Recurse -File -Force | Sort-Object FullName | ForEach-Object {
        @{path=[IO.Path]::GetRelativePath($releaseRoot,$_.FullName).Replace('\','/');sha256=(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash}
    }
    $manifest | ConvertTo-Json -Depth 3 | Set-Content -LiteralPath (Join-Path $releaseRoot 'manifest.sha256.json') -Encoding utf8
    Write-Output ('Release prepared at '+$releaseRoot+'. No environment, production data or deployment activation included.')
} finally {Pop-Location}
