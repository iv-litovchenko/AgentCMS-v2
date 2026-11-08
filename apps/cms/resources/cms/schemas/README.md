# Схемы платформы (не типы сущностей)

- `settings.platform.yaml` — мета-схема глобальных настроек (виджеты через ref на `resources/cms/types/fields/`).

Типы, поля, md-blocks, presets: `resources/cms/types/**`.

Workspace override типов: `workspaces/{slug}/types/**`. Локальные значения настроек: `workspaces/{slug}/.agent-cms/settings.yaml`.
