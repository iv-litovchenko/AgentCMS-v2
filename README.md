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

agent-cms-cli-v2 start    # PATH дописывает install.sh в ~/.zshrc / ~/.profile
```

| ОС | Пути |
|----|------|
| **macOS** | Репозиторий — `/Users/<you>/agent-cms-v2`<br>CLI — `/Users/<you>/.local/bin/agent-cms-cli-v2`<br>Конфиг CLI — `/Users/<you>/.config/agent-cms-v2/env` |
| **Linux** | Репозиторий — `/home/<you>/agent-cms-v2`<br>CLI — `/home/<you>/.local/bin/agent-cms-cli-v2`<br>Конфиг CLI — `/home/<you>/.config/agent-cms-v2/env` |
| **Linux VPS** | Репозиторий — `/var/www/agent-cms-v2`<br>CLI и конфиг CLI — у пользователя, под которым запускали `install.sh` (`~/.local/bin/…`, `~/.config/agent-cms-v2/env`) |
| **Windows (WSL2)** | Репозиторий — `/home/<you>/agent-cms-v2`<br>CLI — `/home/<you>/.local/bin/agent-cms-cli-v2`<br>Конфиг CLI — `/home/<you>/.config/agent-cms-v2/env` |

**Адреса по умолчанию:** http://localhost:8080/ · https://localhost:8443/

## CLI `agent-cms-cli-v2`

```bash
agent-cms-cli-v2 version                          # версия CLI
agent-cms-cli-v2 doctor                           # Docker, compose, каталог, HTTP-порт
agent-cms-cli-v2 paths                            # пути: HOME, CLI, URL, workspaces mount
agent-cms-cli-v2 urls                             # HTTP и HTTPS ссылки из .env
agent-cms-cli-v2 status                           # HTTP ping + docker compose ps

agent-cms-cli-v2 maintenance status               # on | off
agent-cms-cli-v2 maintenance on                   # 503, заглушка maintenance
agent-cms-cli-v2 maintenance off                  # обычная работа

agent-cms-cli-v2 start                            # up -d --build, URL в терминал
agent-cms-cli-v2 stop                             # compose stop
agent-cms-cli-v2 restart                          # compose restart
agent-cms-cli-v2 down                             # compose down
agent-cms-cli-v2 ps                               # compose ps
agent-cms-cli-v2 rebuild                          # up -d --build (образы)

agent-cms-cli-v2 logs                             # логи потоком (-f)
agent-cms-cli-v2 logs snap                        # снимок ~200 строк (proxy, cms, mcp, api)
agent-cms-cli-v2 logs snap --tail 400             # снимок, другой хвост

agent-cms-cli-v2 backup_zzz                       # WIP: архив workspaces
agent-cms-cli-v2 restore_zzz                      # WIP: восстановление из архива

agent-cms-cli-v2 update                           # git pull --ff-only
agent-cms-cli-v2 env show                         # ключи .env (секреты ***)
agent-cms-cli-v2 env show --reveal KEY            # один ключ без маски
agent-cms-cli-v2 env set KEY=VALUE                # запись в .env (whitelist .env.example)
```

## Переменные окружения

Корневой `.env` (из `.env.example`), в контейнер — через compose. Подробнее: `resources/cms/starter/env.registry.yaml`, правка — `agent-cms-cli-v2 env set`.

| Группа | Переменная | По умолчанию | Назначение |
|--------|------------|--------------|------------|
| UI / URL | `CMS_SITE_TITLE` | `Agent CMS` | Заголовок сайта |
| UI / URL | `PUBLIC_BASE_DOMAIN` | `localhost` | Домен ссылок |
| UI / URL | `PUBLIC_HTTP_PORT` | `8080` (`80` prod) | HTTP proxy |
| UI / URL | `PUBLIC_HTTPS_PORT` | `8443` (`443` prod) | HTTPS proxy |
| UI / URL | `PUBLIC_PREFERRED_SCHEME` | `https` | Протокол в UI |
| UI / URL | `REDIRECT_HTTP_TO_HTTPS` | `1` | Редирект на HTTPS |
| Symfony | `APP_ENV` | `dev` / `prod` | Режим (dev-профиль compose) |
| Symfony | `APP_DEBUG` | `1` / `0` | Отладка |
| Symfony | `APP_SECRET` | — | Сессии, CSRF |
| Symfony | `DEFAULT_URI` | `https://localhost:8443` | Базовый URL |
| Symfony | `APP_HOST_PLATFORM` | `mac`/`linux`/`windows` | Стартер |
| Symfony | `PROJECT_README_PATH` | корневой README | Документация в CMS |
| Данные | `DATABASE_URL` | `postgresql://…@db:5432/agentcms` | PostgreSQL |
| Данные | `REDIS_URL` | `redis://redis:6379` | Кеш |
| Данные | `WORKSPACES_DIR` | `/var/www/workspaces` | Volume `workspaces/` |
| Prod | `TLS_CERTS_DIR` | `./deploy/certs` | TLS prod-compose |
| Служебная | `MAINTENANCE_MODE_FILE` | `var/cli/maintenance-mode` | Режим 503 |

## Лицензия

Copyright © 2026 Agent CMS

Исходный код распространяется под [GNU Affero General Public License v3.0](LICENSE) (AGPL-3.0-or-later).
