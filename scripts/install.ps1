# Agent CMS — Windows: ставит через WSL2 тот же install.sh
$ErrorActionPreference = "Stop"

Write-Host "Agent CMS: установка через WSL2 (рекомендуется Docker Desktop + WSL backend)" -ForegroundColor Cyan

$wsl = Get-Command wsl -ErrorAction SilentlyContinue
if (-not $wsl) {
    Write-Host "WSL не найден. Включите: wsl --install, затем повторите." -ForegroundColor Red
    exit 1
}

$repo = $env:AGENT_CMS_REPO
if (-not $repo) {
    $repo = "https://github.com/iv-litovchenko/AgentCMS-v2.git"
}

$branch = $env:AGENT_CMS_BRANCH
if (-not $branch) {
    $branch = "main"
}

$installSh = "curl -fsSL https://raw.githubusercontent.com/iv-litovchenko/AgentCMS-v2/main/scripts/install.sh | AGENT_CMS_REPO=$repo AGENT_CMS_BRANCH=$branch AGENT_CMS_PLATFORM=windows bash"

Write-Host "Запуск в WSL: install.sh → ~/agent-cms-v2, compose: docker-compose.windows.yml" -ForegroundColor Gray
wsl bash -lc $installSh

Write-Host ""
Write-Host "В WSL: agent-cms-cli-v2 start" -ForegroundColor Green
Write-Host "Браузер Windows: http://localhost:8080/" -ForegroundColor Green
