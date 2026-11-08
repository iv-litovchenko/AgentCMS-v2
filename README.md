# Agent CMS

Контейнер **внешней цифровой памяти** пользователя и агентов: хранилища на диске (YAML), веб-админка, API и MCP.

- **Источник правды** — файлы workspace, БД для метаданных и ускорения.
- **Модульность** — расширения (Composer-пакеты), сервисы в Docker, настройки без правок ядра.
Репозиторий: полный rewrite преемника Agent CMS; разработка и документация — в приложении «Документация» в CMS.

## Рекомендуемые требования

| | Dev (Mac / desktop Linux) | Prod (VPS) |
|---|---------------------------|------------|
| **ОС** | macOS 12+ или любой Linux с Docker | Ubuntu 22.04+ / Debian 12+ |
| **Docker** | Desktop (Mac/Win) или Engine + Compose v2 | Engine + Compose v2 |
| **RAM** | 8 GB | 4 GB |
| **Диск** | 20 GB | 30 GB |
| **Сеть** | localhost | IPv4, порты 22, 80, 443 |

## Стек

| Слой | Технологии |
|------|------------|
| **Приложение** | PHP 8.2+, Symfony 7, Twig |
| **UI** | Tailwind CSS 3, Twig |
| **Хранение** | Workspace на диске (YAML, Markdown); PostgreSQL 16 — метаданные; Redis 7 — кеш |
| **Интеграции** | HTTP API, MCP для агентов; модульные Composer-пакеты |
| **Инфраструктура** | Docker Compose: Nginx (proxy), `cms`, `mcp`, `api`, `db`, `redis` |

## Установка

```bash
# macOS / Linux
curl -fsSL https://raw.githubusercontent.com/iv-litovchenko/AgentCMS-v2/main/scripts/install.sh | bash
# | sudo bash -s -- --vps
# | bash -s -- --dir ~/Projects/agent-cms-v2
# | bash -s -- --start

# Windows (PowerShell)
# irm https://raw.githubusercontent.com/iv-litovchenko/AgentCMS-v2/main/scripts/install.ps1 | iex

# из клона
# bash scripts/install.sh

export PATH="$HOME/.local/bin:$PATH"
agent-cms-cli-v2 start
```

| ОС | Пути |
|----|------|
| **macOS** | Репозиторий — `/Users/<you>/agent-cms-v2`<br>CLI — `/Users/<you>/.local/bin/agent-cms-cli-v2`<br>Конфиг CLI — `/Users/<you>/.config/agent-cms-v2/env` |
| **Linux** | Репозиторий — `/home/<you>/agent-cms-v2`<br>CLI — `/home/<you>/.local/bin/agent-cms-cli-v2`<br>Конфиг CLI — `/home/<you>/.config/agent-cms-v2/env` |
| **Linux VPS** | Репозиторий — `/var/www/agent-cms-v2`<br>CLI и конфиг CLI — у пользователя, под которым запускали `install.sh` (`~/.local/bin/…`, `~/.config/agent-cms-v2/env`) |
| **Windows (WSL2)** | Репозиторий — `/home/<you>/agent-cms-v2`<br>CLI — `/home/<you>/.local/bin/agent-cms-cli-v2`<br>Конфиг CLI — `/home/<you>/.config/agent-cms-v2/env` |

**Адреса по умолчанию:** http://localhost:8080/ · https://localhost:8443/
