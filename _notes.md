# Обсудить

**Как собирается тип (схема)**

```
resolveType(typeId, vaultSlug)
│
├─① Слои каталогов (скан → merge по id, ниже слабее, выше сильнее)
│   ├─ BOOTSTRAP_TYPES_DIR          (опц., env)
│   ├─ packages/*/resources/cms/types/
│   ├─ apps/cms/resources/cms/types/  платформа
│   └─ vaults/{slug}/types/           хранилище  ← твой override
│       · тот же относительный путь = тот же id
│       · пример: vaults/vault-core/types/sections/topic/micro.yaml
│
├─② id типа
│   ├─ путь файла без .yaml  →  sections/topic/micro
│   └─ aliases.yaml          →  dot-id (acms.*) → slash-id (имя, не merge)
│
├─③ extends (после merge слоёв, цепочка вверх)
│   sections/topic/micro
│   └─ sections/topic/_base
│       └─ sections/_base
│           └─ base/base
│               └─ …
│   · fields — дополняются
│   · properties[] — по key
│   · mixins[] — объединение списков
│   · остальные ключи — перезапись
│
├─④ mixins (ссылки на types/mixins/*)
│   └─ подмешиваются поля/поведение без отдельного extends-файла
│
└─⑤ Экземпляр на диске (не расширение схемы)
    ├─ manifest.section.md / frontmatter записи — значения полей типа
    └─ vaults/{slug}/.agent-cms/settings.yaml — настройки vault
```

**Дерево kinds (что лежит в `types/`, не порядок merge)**

```
types/
├─ base/
├─ hubs/          зоны (topic, channel, …)
│   └─ sections/  секции в зоне (manifest.section.md)
│       └─ slots/     папки/слоты (path: notes/, main/, …)
│           └─ content/   записи в слоте
├─ fields/        поля форм
├─ mixins/        переиспользуемые куски полей
├─ presets/       шаблоны/заготовки
├─ md-blocks/     блоки markdown
├─ settings/      типы настроек
├─ modules/       модули vault
├─ annotations/   sidecar и т.п.
└─ viz/           визуализации
```

**Хранилище:** положи YAML с **тем же id**, что у платформы — `TypeSchemaRegistry` подмешает при `getTypeForVault(slug, …)`. Новый id = новый файл в `vaults/{slug}/types/...`.

Проверка: `php bin/console app:types:list -w vault-core`.