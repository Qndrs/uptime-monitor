$ErrorActionPreference = "Stop"

$root = Resolve-Path (Join-Path $PSScriptRoot "..")
$pluginFile = Join-Path $root "qndrs-availability-heartbeat-monitor.php"
$readmeFile = Join-Path $root "README.md"
$wpReadmeFile = Join-Path $root "readme.txt"
$jsFile = Join-Path $root "js\uptime-monitor.js"

function Assert-True {
    param(
        [bool]$Condition,
        [string]$Message
    )

    if (-not $Condition) {
        throw $Message
    }
}

Write-Output "Checking PHP syntax..."
& php -l $pluginFile | Out-Host
if ($LASTEXITCODE -ne 0) {
    throw "PHP syntax check failed"
}

& php -l (Join-Path $root "uninstall.php") | Out-Host
if ($LASTEXITCODE -ne 0) {
    throw "Uninstall PHP syntax check failed"
}

$node = Get-Command node -ErrorAction SilentlyContinue
if ($node) {
    Write-Output "Checking JavaScript syntax..."
    & node --check $jsFile | Out-Host
    if ($LASTEXITCODE -ne 0) {
        throw "JavaScript syntax check failed"
    }
} else {
    Write-Output "Skipping JavaScript syntax check because Node.js is not available."
}

Write-Output "Checking i18n source strings..."
$i18nScript = Join-Path $root "scripts\check-i18n.py"
$python = Get-Command python -ErrorAction SilentlyContinue
$pyLauncher = Get-Command py -ErrorAction SilentlyContinue
if ($python) {
    & python $i18nScript | Out-Host
    if ($LASTEXITCODE -ne 0) {
        throw "i18n check failed"
    }
} elseif ($pyLauncher) {
    & py $i18nScript | Out-Host
    if ($LASTEXITCODE -ne 0) {
        throw "i18n check failed"
    }
} else {
    Write-Output "Python is not available; using minimal PowerShell i18n fallback."
    $pluginForI18n = Get-Content -LiteralPath $pluginFile -Raw
    Assert-True ($pluginForI18n -notmatch "Net bijgewerkt|Uptime Monitor-instellingen|Instellingen opslaan|Configuratiepaneel|Heartbeat monitor toevoegen") "Dutch source strings found in PHP gettext calls"
    $poFile = Join-Path $root "languages\qndrs-availability-heartbeat-monitor-nl_NL.po"
    $po = Get-Content -LiteralPath $poFile -Raw
    Assert-True ($po -match 'msgid "Updated just now\."\s+msgstr "Net bijgewerkt\."') "Dutch translation for Updated just now is missing"
    Assert-True ($po -match 'msgid "Delete"\s+msgstr "Verwijderen"') "Dutch translation for Delete is missing"
}

$plugin = Get-Content -LiteralPath $pluginFile -Raw
$readme = Get-Content -LiteralPath $readmeFile -Raw
$wpReadme = Get-Content -LiteralPath $wpReadmeFile -Raw

Assert-True ($plugin -match "\*\s+Version:\s+3\.5\.2") "Plugin header version must be 3.5.2"
Assert-True ($readme -match "Stable tag:\s+3\.5\.2") "README stable tag must be 3.5.2"
Assert-True ($readme -match "### 3\.5\.2") "README changelog must include 3.5.2"
Assert-True ($readme -notmatch "ð|â|Ÿ|Œ|§") "README still contains mojibake characters"
Assert-True ($wpReadme -match "Stable tag:\s+3\.5\.2") "readme.txt stable tag must be 3.5.2"
Assert-True ($wpReadme -match "Tested up to:\s+7\.0") "readme.txt tested-up-to header is missing"
Assert-True ($wpReadme -match "License:\s+GPLv2 or later") "readme.txt license header is missing"

Assert-True ($plugin -match "private const CRON_SCHEDULE = 'qndrs_ahm_interval'") "Cron schedule constant is missing"
Assert-True ($plugin -match "current_user_can\('manage_options'\)") "Capability checks are missing"
Assert-True ($plugin -match "MAX_LOG_ENTRIES") "Log entry guard is missing"
Assert-True ($plugin -match "normalize_stored_urls") "URL normalization is missing"

$buildScript = Join-Path $root "scripts\build-release.ps1"
Assert-True (Test-Path -LiteralPath $buildScript) "Release build script is missing"
Assert-True ($readme -match "docs/heartbeat-monitors\.nl\.md") "README must link to Dutch heartbeat documentation"
Assert-True ($wpReadme -match "docs/heartbeat-monitors\.nl\.md") "readme.txt must link to Dutch heartbeat documentation"

Write-Output "Release checks passed."
