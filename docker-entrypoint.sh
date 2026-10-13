#!/usr/bin/env bash
set -e

PORT="${PORT:-8080}"

echo "=========================================================="
echo "🚀 Iniciando Organigrama & Support AI Triaging en Railway"
echo "   Puerto: ${PORT} | SQLite WAL Activo"
echo "=========================================================="

mkdir -p storage/database storage/logs
chmod -R 777 storage 2>/dev/null || true

# 1. Migración de base de datos
echo "==> [DB] Aplicando esquema de base de datos..."
php database/migrate.php || true

# 2. Seeding de demostración si la base está nueva
echo "==> [DB] Verificando datos iniciales..."
php database/seed.php || true

# 3. Iniciar Worker en segundo plano
echo "==> [Worker] Iniciando ticket_worker en segundo plano..."
php bin/ticket_worker.php &
WORKER_PID=$!

cleanup() {
  echo "==> [Shutdown] Recibida señal de detención. Cerrando worker y servidor..."
  if [ -n "$WORKER_PID" ] && kill -0 "$WORKER_PID" 2>/dev/null; then
    kill -TERM "$WORKER_PID" 2>/dev/null || true
  fi
  exit 0
}

trap cleanup SIGTERM SIGINT

# 4. Iniciar Servidor Web PHP en primer plano en 0.0.0.0:$PORT
echo "==> [Web] Servidor escuchando en http://0.0.0.0:${PORT}..."
php -S "0.0.0.0:${PORT}" -t public public/router.php &
SERVER_PID=$!

# Esperar que el servidor web siga activo
wait "$SERVER_PID"

