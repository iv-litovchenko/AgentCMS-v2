# Типы

**Id = путь к файлу** (`viz/architecture.yaml` → `viz/architecture`). В YAML: без `id`, `kind`, `domain`; при необходимости только `status: active`. `kind` для UI — из пути в реестре. Dot-id — `aliases.yaml`.

Слои merge (снизу вверх, верх побеждает):

1. `packages/*/resources/cms/types/` — пакеты
2. `resources/cms/types/` — платформа (миграция из `notes/awn-system/types/`)
3. `workspaces/{slug}/types/` — хранилище

Повторная миграция: `php bin/migrate-types-from-notes.php` (обновляет дерево и `aliases.yaml`).
Опциональный bootstrap: `BOOTSTRAP_TYPES_DIR` (обычно не нужен).

`extends` разворачивается в реестре. Dot-id из manifest/MCP — через `aliases.yaml` и поле `id` в YAML.

Мета-схема значений настроек (не тип): `resources/cms/schemas/settings.platform.yaml`.

CLI: `php bin/console app:types:list` и `php bin/console app:types:list -w ws-core`.
