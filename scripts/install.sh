#!/usr/bin/env bash
# Agent CMS — установщик macOS / Linux (curl | bash или bash scripts/install.sh)
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUNDLED_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

AGENT_CMS_REPO="${AGENT_CMS_REPO:-https://github.com/iv-litovchenko/AgentCMS-v2.git}"
AGENT_CMS_BRANCH="${AGENT_CMS_BRANCH:-main}"

INSTALL_DIR=""
VPS=0
DO_START=0
FROM_REPO=0

usage() {
    cat <<'EOF'
install.sh — Agent CMS (macOS / Linux)

  --dir PATH     каталог установки (по умолчанию ~/agent-cms-v2)
  --vps          Linux VPS: /var/www/agent-cms-v2 (нужен root или sudo)
  --start        после установки: agent-cms-cli-v2 start (если Docker запущен)
  --help

Переменные: AGENT_CMS_REPO, AGENT_CMS_BRANCH
EOF
}

while [ $# -gt 0 ]; do
    case "$1" in
        --dir)
            INSTALL_DIR="$2"
            shift 2
            ;;
        --vps)
            VPS=1
            shift
            ;;
        --start)
            DO_START=1
            shift
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            echo "Неизвестный аргумент: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

need_cmd() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "Нужна команда: $1" >&2
        exit 1
    fi
}

detect_platform() {
    local uname_s
    uname_s="$(uname -s)"
    if [ "${uname_s}" = "Darwin" ]; then
        echo "mac"
    elif [ "${uname_s}" = "Linux" ]; then
        echo "linux"
    else
        echo "linux"
    fi
}

default_install_dir() {
    if [ "${VPS}" = "1" ]; then
        echo "/var/www/agent-cms-v2"
    else
        echo "${HOME}/agent-cms-v2"
    fi
}

ensure_git() {
    need_cmd git
}

clone_or_use_repo() {
    if [ -n "${INSTALL_DIR}" ] && [ -d "${INSTALL_DIR}/.git" ] && [ -f "${INSTALL_DIR}/docker-compose.yml" ]; then
        echo "Используем существующий репозиторий: ${INSTALL_DIR}"
        return 0
    fi

    if [ -f "${BUNDLED_ROOT}/docker-compose.yml" ] && [ -z "${INSTALL_DIR}" ]; then
        FROM_REPO=1
        INSTALL_DIR="${BUNDLED_ROOT}"
        echo "Локальный репозиторий: ${INSTALL_DIR}"
        return 0
    fi

    if [ -z "${INSTALL_DIR}" ]; then
        INSTALL_DIR="$(default_install_dir)"
    fi

    if [ -d "${INSTALL_DIR}/.git" ] && [ -f "${INSTALL_DIR}/docker-compose.yml" ]; then
        echo "Уже установлено: ${INSTALL_DIR}"
        return 0
    fi

    if [ -d "${INSTALL_DIR}" ] && [ "$(ls -A "${INSTALL_DIR}" 2>/dev/null | wc -l | tr -d ' ')" != "0" ]; then
        echo "Каталог не пуст и не git: ${INSTALL_DIR}" >&2
        exit 1
    fi

    mkdir -p "$(dirname "${INSTALL_DIR}")"
    echo "Клонирование ${AGENT_CMS_REPO} → ${INSTALL_DIR}"
    git clone --depth 1 --branch "${AGENT_CMS_BRANCH}" "${AGENT_CMS_REPO}" "${INSTALL_DIR}"
}

prepare_env() {
    cd "${INSTALL_DIR}"
    if [ ! -f .env ] && [ -f .env.example ]; then
        cp .env.example .env
        echo "Создан .env из .env.example"
    fi
    if [ ! -f docker-compose.override.yml ] && [ -f docker-compose.override.example.yml ]; then
        cp docker-compose.override.example.yml docker-compose.override.yml
        echo "Создан docker-compose.override.yml (dev, код с диска)"
    fi
}

install_cli() {
    local bin_dir="${HOME}/.local/bin"
    local config_dir="${HOME}/.config/agent-cms-v2"
    local platform
    platform="$(detect_platform)"
    if [ -n "${AGENT_CMS_PLATFORM:-}" ]; then
        platform="${AGENT_CMS_PLATFORM}"
    fi

    mkdir -p "${bin_dir}" "${config_dir}"

    cp "${SCRIPT_DIR}/agent-cms-cli-v2" "${bin_dir}/agent-cms-cli-v2"
    chmod +x "${bin_dir}/agent-cms-cli-v2"

    cat >"${config_dir}/env" <<EOF
# Создано install.sh — не редактировать AGENT_CMS_ROOT вручную без причины
AGENT_CMS_ROOT="${INSTALL_DIR}"
AGENT_CMS_PLATFORM="${platform}"
AGENT_CMS_VPS="${VPS}"
EOF

    if [ "${VPS}" = "1" ] && [ "$(id -u)" = "0" ]; then
        cat >/etc/profile.d/agent-cms-cli-v2.sh <<'PROFILE_EOF'
# Agent CMS CLI
if [ -d "${HOME}/.local/bin" ]; then
  case ":${PATH}:" in
    *:"${HOME}/.local/bin":*) ;;
    *) PATH="${HOME}/.local/bin:${PATH}" ;;
  esac
fi
PROFILE_EOF
        echo "PATH: /etc/profile.d/agent-cms-cli-v2.sh"
    fi

    path_line='export PATH="${HOME}/.local/bin:${PATH}"'
    for rc in "${HOME}/.profile" "${HOME}/.zshrc" "${HOME}/.bashrc"; do
        if [ -f "${rc}" ] || [ "${rc}" = "${HOME}/.profile" ]; then
            if [ ! -f "${rc}" ]; then
                touch "${rc}"
            fi
            if ! grep -q '.local/bin' "${rc}" 2>/dev/null; then
                echo "" >>"${rc}"
                echo "# Agent CMS CLI" >>"${rc}"
                echo "${path_line}" >>"${rc}"
            fi
        fi
    done

    echo "CLI: ${bin_dir}/agent-cms-cli-v2"
}

print_next_steps() {
    local platform
    platform="$(detect_platform)"
    cat <<EOF

Готово.
  Каталог:  ${INSTALL_DIR}
  Платформа: ${platform}
  Конфиг:   ~/.config/agent-cms-v2/env

Откройте новый терминал или: export PATH="\${HOME}/.local/bin:\${PATH}"

  agent-cms-cli-v2 status
  agent-cms-cli-v2 start   # нужен Docker Desktop / Engine

  CMS:  http://localhost:8080/
  HTTPS: https://localhost:8443/

Опционально CSS: ${INSTALL_DIR}/scripts/npm-build.sh
EOF
}

main() {
    if [ "${VPS}" = "1" ] && [ -z "${INSTALL_DIR}" ]; then
        INSTALL_DIR="/var/www/agent-cms-v2"
    fi

    ensure_git
    clone_or_use_repo
    prepare_env
    install_cli
    print_next_steps

    export PATH="${HOME}/.local/bin:${PATH}"
    if [ "${DO_START}" = "1" ]; then
        if docker info >/dev/null 2>&1; then
            agent-cms-cli-v2 start
        else
            echo "Docker не запущен — пропуск --start. Позже: agent-cms-cli-v2 start"
        fi
    fi
}

main
