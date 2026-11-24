# Схемы платформы (не типы сущностей)

- `settings.platform.yaml` — мета-схема глобальных настроек (виджеты через ref на `resources/cms/types/fields/`).

Типы, поля, md-blocks, presets: `resources/cms/types/**`.

Vault override типов: `vaults/{slug}/types/**`. Локальные значения настроек: `vaults/{slug}/.agent-cms/settings.yaml`.
