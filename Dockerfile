# =============================================================================
# Stage 1: Build Angular 17 Frontend
# =============================================================================
FROM node:20-alpine AS frontend-builder

WORKDIR /app/frontend

COPY frontend/package*.json ./
RUN npm ci --prefer-offline || npm install

COPY frontend/ ./
RUN npm run build

# =============================================================================
# Stage 2: Production PHP 8.3 Runtime
# =============================================================================
FROM php:8.3-cli-alpine

RUN apk add --no-cache \
    bash \
    curl \
    sqlite \
    git \
    linux-headers \
    && docker-php-ext-install \
    pdo \
    pdo_mysql \
    opcache \
    pcntl

WORKDIR /app

# Copiar código fuente
COPY . .

# Copiar SPA de Angular compilada
COPY --from=frontend-builder /app/frontend/dist/organigrama-frontend /app/frontend/dist/organigrama-frontend

# Permisos para SQLite y scripts de ejecución
RUN mkdir -p storage/database storage/logs \
    && chmod -R 777 storage \
    && chmod +x docker-entrypoint.sh bin/ticket_worker.php bin/apply_patch.php database/migrate.php database/seed.php

ENV PORT=8080
ENV DB_CONNECTION=sqlite
ENV DB_SQLITE_PATH=/app/storage/database/organigrama.sqlite

EXPOSE 8080

ENTRYPOINT ["./docker-entrypoint.sh"]

