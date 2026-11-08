🟡 **Статус:** на обсуждении

**Markdown «как БД»**

Обычно это не «MD целиком», а **frontmatter (YAML/TOML) + тело**:

| Задача | PHP / экосистема |
|--------|------------------|
| Разбор MD + frontmatter | **league/commonmark** + **symfony/yaml** (у вас уже есть YAML) или **spatie/yaml-front-matter** |
| Типы полей / схема | Ваш реестр типов в `resources/cms/types/` + валидация **symfony/validator** по DTO |
| Запросы «как БД» | Индекс в БД/Redis (у вас так и задумано) + файлы на диске; полнотекст — **SQLite FTS**, **Meilisearch**, позже |
| Wikilinks, чеклисты | CommonMark + расширения или свой пост-процессор |

Готового «ORM для .md» почти нет — модель: **схема типа → парс frontmatter → объект → атомарная запись файла**.

**CSV «как БД»**

| Задача | Библиотека |
|--------|------------|
| Чтение/запись, заголовки | **league/csv** (де‑факто стандарт в PHP) |
| Схема колонок | Массив `columns` в YAML типа + Validator |
| Фильтры/агрегации | **openspout/openspout** (большие файлы), или импорт в SQLite для ad‑hoc SQL |

**Для Agent CMS логично:** тип сущности в YAML (`fields:`), источник — `.md` с frontmatter или `.csv`; один слой **WorkspaceEntityReader/Writer** (файл + flock), без отдельной «MD‑БД» библиотеки.

Если нужно — могу набросать минимальный тип `note_md` + `table_csv` в реестре и один сервис чтения.
