🟡 **Статус:** на обсуждении — создание установщика (Mac / Linux / Windows)

Канон: один раз ставите платформу через установщик (без ручного git clone), дальше в терминале — `agent-cms-cli-v2` (start, status, update и т.д.).

| ОС | Куда кладётся код | Compose |
|----|-------------------|---------|
| macOS | `~/agent-cms-v2` → `/Users/<you>/agent-cms-v2` | `docker-compose.mac.yml` |
| Linux | `~/agent-cms-v2` → `/home/<you>/agent-cms-v2` | `linux.yml` (desktop) |
| Linux VPS | `/var/www/agent-cms-v2` (`--vps` или `--dir`) | `docker-compose.linux.yml` |
| Windows | WSL2 `~/agent-cms-v2` | `docker-compose.windows.yml` |

На шаге `curl | bash`: нужны git и сеть; Docker не обязателен — установщик клонирует репо и ставит `agent-cms-cli-v2` в `~/.local/bin`. Конфиг: `~/.config/agent-cms-v2/env`. PATH — `~/.profile` / `/etc/profile.d` (root).

Перед первым запуском CMS: Docker Desktop (Mac / Windows) или Engine + Compose (Linux). `.env` из `.env.example` при установке.

```bash
# macOS / Linux — без npm: clone + CLI
curl -fsSL https://raw.githubusercontent.com/iv-litovchenko/AgentCMS-v2/main/scripts/install.sh | bash

# опции: --dir PATH · --vps (/var/www/agent-cms-v2) · --start (Docker Running)
# из уже клонированного репо: bash scripts/install.sh

# Windows PowerShell → WSL2 + тот же install.sh
irm https://raw.githubusercontent.com/iv-litovchenko/AgentCMS-v2/main/scripts/install.ps1 | iex
```
