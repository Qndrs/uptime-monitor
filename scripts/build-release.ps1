param(
    [string]$OutputDirectory = "dist"
)

$ErrorActionPreference = "Stop"

$root = Resolve-Path (Join-Path $PSScriptRoot "..")
$pluginFile = Join-Path $root "qndrs-availability-heartbeat-monitor.php"
$pluginHeader = Get-Content -LiteralPath $pluginFile -Raw

if ($pluginHeader -notmatch "\*\s+Version:\s+([0-9]+\.[0-9]+\.[0-9]+)") {
    throw "Could not find plugin version in qndrs-availability-heartbeat-monitor.php"
}

$version = $Matches[1]
$dist = Join-Path $root $OutputDirectory
$pluginSlug = "qndrs-availability-heartbeat-monitor"
$zipPath = Join-Path $dist "qndrs-availability-heartbeat-monitor-$version.zip"

$files = @(
    "LICENSE",
    "README.md",
    "readme.txt",
    "uninstall.php",
    "uptime-monitor.php",
    "uptime-monitor.css",
    "uptime-monitor.js",
    "qndrs-availability-heartbeat-monitor.php"
)

$publicDocs = @(
    "docs\heartbeat-monitors.md",
    "docs\heartbeat-monitors.nl.md"
)

$directoryFiles = @()
foreach ($directory in @("css", "js", "languages")) {
    $directoryFiles += Get-ChildItem -LiteralPath (Join-Path $root $directory) -File -Recurse | ForEach-Object {
        $_.FullName.Substring($root.Path.Length + 1)
    }
}

if (Test-Path -LiteralPath $zipPath) {
    Remove-Item -LiteralPath $zipPath -Force
}

New-Item -ItemType Directory -Force -Path $dist | Out-Null
Add-Type -AssemblyName System.IO.Compression.FileSystem
Add-Type -AssemblyName System.IO.Compression
$zip = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)

try {
    foreach ($relativePath in ($files + $directoryFiles + $publicDocs)) {
        $sourcePath = Join-Path $root $relativePath
        if (!(Test-Path -LiteralPath $sourcePath)) {
            throw "Missing release file: $relativePath"
        }

        $entryName = ($pluginSlug + "/" + $relativePath).Replace("\", "/")
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $sourcePath, $entryName) | Out-Null
    }
} finally {
    $zip.Dispose()
}

Write-Output "Created $zipPath"
