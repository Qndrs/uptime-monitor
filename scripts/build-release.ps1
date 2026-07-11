param(
    [string]$OutputDirectory = "dist"
)

$ErrorActionPreference = "Stop"

$root = Resolve-Path (Join-Path $PSScriptRoot "..")
$pluginFile = Join-Path $root "uptime-monitor.php"
$pluginHeader = Get-Content -LiteralPath $pluginFile -Raw

if ($pluginHeader -notmatch "\*\s+Version:\s+([0-9]+\.[0-9]+\.[0-9]+)") {
    throw "Could not find plugin version in uptime-monitor.php"
}

$version = $Matches[1]
$dist = Join-Path $root $OutputDirectory
$stagingRoot = Join-Path $dist "_build"
$stagingPlugin = Join-Path $stagingRoot "uptime-monitor"
$zipPath = Join-Path $dist "uptime-monitor-$version.zip"

if (Test-Path -LiteralPath $stagingRoot) {
    Remove-Item -LiteralPath $stagingRoot -Recurse -Force
}

New-Item -ItemType Directory -Force -Path $stagingPlugin | Out-Null

$files = @(
    "LICENSE",
    "README.md",
    "readme.txt",
    "uninstall.php",
    "uptime-monitor.php"
)

$directories = @(
    "css",
    "docs",
    "js",
    "languages"
)

foreach ($file in $files) {
    Copy-Item -LiteralPath (Join-Path $root $file) -Destination $stagingPlugin
}

foreach ($directory in $directories) {
    Copy-Item -LiteralPath (Join-Path $root $directory) -Destination $stagingPlugin -Recurse
}

if (Test-Path -LiteralPath $zipPath) {
    Remove-Item -LiteralPath $zipPath -Force
}

Compress-Archive -Path (Join-Path $stagingRoot "uptime-monitor") -DestinationPath $zipPath -Force
Remove-Item -LiteralPath $stagingRoot -Recurse -Force

Write-Output "Created $zipPath"
