FROM nginx:1.27-alpine
RUN apk add --no-cache openssl \
    && mkdir -p /etc/nginx/certs \
    && openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
        -keyout /etc/nginx/certs/key.pem \
        -out /etc/nginx/certs/cert.pem \
        -subj "/CN=localhost" \
        -addext "subjectAltName=DNS:localhost,DNS:*.localhost,IP:127.0.0.1"
COPY docker/proxy/nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/proxy/static/404-service.html /usr/share/nginx/html/404-service.html
COPY docker/proxy/static/503-maintenance.html /usr/share/nginx/html/503-maintenance.html
COPY apps/cms/public /var/www/cms/public
