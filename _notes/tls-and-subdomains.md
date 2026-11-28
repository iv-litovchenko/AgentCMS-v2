# HTTPS и поддомены

## Локально (Docker, все ОС)

| Режим | URL |
|--------|-----|
| HTTP | http://localhost:8080/ |
| HTTPS (self-signed в образе) | https://localhost:8443/ |
| HTTPS (mkcert, без предупреждения браузера) | после `scripts/tls-dev-setup.sh` + `docker-compose.tls-dev.yml` |
| QwenPaw | http(s)://qwenpaw.localhost:8080/ или :8443/ |
| LAN | `https://<IP-хоста>:8443/` — IP добавляется mkcert-скриптом |

### Self-signed (по умолчанию)

При сборке `proxy` OpenSSL генерирует cert с SAN: `localhost`, `*.localhost`, `127.0.0.1`. Браузер покажет предупреждение — для быстрого старта достаточно.

### mkcert (Mac / Linux / Windows)

1. Установите [mkcert](https://github.com/FiloSottile/mkcert) и один раз: `mkcert -install`.
2. Из корня репозитория:

```bash
chmod +x scripts/tls-dev-setup.sh
./scripts/tls-dev-setup.sh
```

3. Поднимите proxy с монтированием `.dev-certs/`:

```bash
docker compose -f docker-compose.yml -f docker-compose.override.yml -f docker-compose.tls-dev.yml up -d proxy
```

Скрипт определяет платформу (`mac` / `linux` / `windows`) и по возможности LAN IP (Mac: en0/en1, Linux: `hostname -I`).

### Compose-профили

| Файл | Назначение |
|------|------------|
| `docker-compose.override.yml` | dev bind-mount кода |
| `docker-compose.tls-dev.yml` | mkcert → `/etc/nginx/certs` |
| `docker-compose.prod.yml` | порты 80/443, certs из `deploy/certs/` или `TLS_CERTS_DIR` |

## Продакшен (сайт в сети)

1. DNS: `A` / wildcard `*.domain` → IP сервера.
2. Сертификаты в `deploy/certs/` (`cert.pem`, `key.pem`) — Let's Encrypt, certbot, DNS-01 для wildcard и т.д.
3. `.env`: `PUBLIC_BASE_DOMAIN=domain`, `PUBLIC_HTTP_PORT=80`, `PUBLIC_HTTPS_PORT=443`.
4. Запуск:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

5. Nginx `server_name` для вашего домена — при необходимости расширить `docker/proxy/nginx.conf` (сейчас ориентир на localhost / `*.localhost` для dev).

Реестр сервисов CMS: `resources/cms/ui/menu.yaml` → `services`.
