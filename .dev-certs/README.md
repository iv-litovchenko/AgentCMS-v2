# Dev TLS (mkcert)

Сгенерируйте сертификаты:

```bash
agent-cms-cli-v2 https setup --restart
# или: ./scripts/tls-dev-setup.sh && agent-cms-cli-v2 https reload
```

В dev `docker-compose.override.yml` уже монтирует эту папку в proxy. Отдельный файл `docker-compose.tls-dev.yml` — опционально.

Файлы `cert.pem`, `key.pem`, `meta.yaml` создаются скриптом и не коммитятся.
