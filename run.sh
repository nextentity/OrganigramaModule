#!/usr/bin/env bash
# =============================================================================
# Organigrama Multi-Tenant · Script Principal End-to-End
#
#   ./run.sh               Flujo completo: verificación + tests + seed + build + servidor web.
#   ./run.sh --serve       Inicia el servidor web (con verificación y build si falta).
#   ./run.sh --test        Ejecuta únicamente la suite de pruebas backend (41 tests).
#   ./run.sh --fresh       Recrea esquema desde cero, siembra datos y compila frontend.
#   ./run.sh --build-only  Ejecuta tests, seed y build sin iniciar el servidor.
# =============================================================================
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly PROJECT_ROOT
readonly MIN_PHP_VERSION="8.2.0"
readonly DEFAULT_PORT="8080"

info()    { printf '\033[36m==> %s\033[0m\n' "$*"; }
success() { printf '\033[32m✔ %s\033[0m\n' "$*"; }
warn()    { printf '\033[33m⚠ %s\033[0m\n' "$*"; }
fail()    { printf '\033[31m✘ %s\033[0m\n' "$*" >&2; exit 1; }

# PIDs en segundo plano a monitorear y limpiar
BACKGROUND_PIDS=()

# =============================================================================
# Trap para SIGINT y SIGTERM: Cierre limpio sin dejar puertos tomados
# =============================================================================
cleanup() {
  local exit_code=$?
  # Desactivar trap temporalmente para evitar recursión
  trap - SIGINT SIGTERM EXIT

  if [ ${#BACKGROUND_PIDS[@]} -gt 0 ]; then
    printf '\n\033[33m==> Capturada señal de interrupción. Deteniendo servicios...\033[0m\n'
    for pid in "${BACKGROUND_PIDS[@]}"; do
      if kill -0 "$pid" 2>/dev/null; then
        kill -TERM "$pid" 2>/dev/null || true
      fi
    done

    # Esperar brevemente a que los procesos finalicen
    sleep 0.5
    for pid in "${BACKGROUND_PIDS[@]}"; do
      if kill -0 "$pid" 2>/dev/null; then
        kill -KILL "$pid" 2>/dev/null || true
      fi
    done
    wait 2>/dev/null || true
    printf '\033[32m✔ Todos los procesos en segundo plano finalizados. Puertos liberados.\033[0m\n'
  fi

  exit "$exit_code"
}

trap cleanup SIGINT SIGTERM EXIT

# Asegura que Node/NPM esté en PATH si está instalado vía NVM
setup_node_path() {
  if [ -d "$HOME/.nvm/versions/node" ]; then
    local latest_node_dir
    latest_node_dir="$(ls -d "$HOME/.nvm/versions/node/"v* 2>/dev/null | tail -n 1 || true)"
    if [ -n "$latest_node_dir" ] && [ -d "$latest_node_dir/bin" ]; then
      export PATH="$latest_node_dir/bin:$PATH"
    fi
  fi
}

# =============================================================================
# Verificación de dependencias del entorno
# =============================================================================
check_requirements() {
  info "Verificando dependencias del entorno (PHP, extensiones PDO, Node.js y NPM)"

  # 1. PHP
  command -v php >/dev/null 2>&1 || fail "PHP no está instalado (se requiere >= ${MIN_PHP_VERSION})."

  php -r "exit(version_compare(PHP_VERSION, '${MIN_PHP_VERSION}', '>=') ? 0 : 1);" \
    || fail "Se requiere PHP >= ${MIN_PHP_VERSION} (versión detectada: $(php -r 'echo PHP_VERSION;'))."

  # 2. Extensiones PHP obligatorias
  local ext
  for ext in pdo pdo_sqlite; do
    php -r "exit(extension_loaded('${ext}') ? 0 : 1);" || fail "Falta la extensión requerida de PHP: ${ext}"
  done

  # 3. Node.js y NPM para Angular 17
  setup_node_path
  command -v node >/dev/null 2>&1 || fail "Node.js no está disponible en PATH. Se requiere Node >= 18."
  command -v npm >/dev/null 2>&1  || fail "NPM no está disponible en PATH."

  success "Entorno validado correctamente: PHP $(php -r 'echo PHP_VERSION;') + Node $(node -v) + NPM $(npm -v)"
}

# =============================================================================
# Ejecución de pruebas automatizadas
# =============================================================================
run_tests() {
  info "Ejecutando suite de pruebas automatizadas de negocio y arquitectura"
  php "${PROJECT_ROOT}/tests/run.php"
}

# =============================================================================
# Migración y Seeding de la base de datos
# =============================================================================
seed_database() {
  info "Migrando esquema y sembrando datos de prueba multi-tenant"
  php "${PROJECT_ROOT}/database/seed.php" "$@"
}

# =============================================================================
# Compilación del Frontend Angular 17 + PrimeNG
# =============================================================================
build_frontend() {
  setup_node_path
  info "Compilando aplicación SPA Angular 17 en producción"

  if [ ! -d "${PROJECT_ROOT}/frontend/node_modules" ]; then
    info "Instalando dependencias de frontend (npm install)..."
    (cd "${PROJECT_ROOT}/frontend" && npm install)
  fi

  (cd "${PROJECT_ROOT}/frontend" && npm run build)
  success "Frontend compilado exitosamente en: frontend/dist/organigrama-frontend"
}

# =============================================================================
# Servidor web integrado (PHP Router + Angular SPA)
# =============================================================================
serve_app() {
  local port="${PORT:-$DEFAULT_PORT}"

  # Verificar si el puerto ya está en uso
  if lsof -Pi :"$port" -sTCP:LISTEN -t >/dev/null 2>&1; then
    warn "El puerto ${port} está ocupado. Buscando procesos anteriores..."
    local existing_pid
    existing_pid="$(lsof -Pi :"$port" -sTCP:LISTEN -t | head -n 1)"
    warn "Finalizando proceso previo en PID ${existing_pid}..."
    kill -TERM "$existing_pid" 2>/dev/null || true
    sleep 1
  fi

  # Asegurar que el frontend esté compilado antes de servir
  if [ ! -f "${PROJECT_ROOT}/frontend/dist/organigrama-frontend/index.html" ]; then
    warn "El bundle de frontend no existe. Compilando ahora..."
    build_frontend
  fi

  info "Iniciando servidor web integrado (PHP + SPA Angular)..."
  printf "\n\033[1;32m%s\033[0m\n" "=========================================================="
  printf "\033[1;32m  SISTEMA ORGANIGRAMA MULTI-TENANT DISPONIBLE EN:\033[0m\n"
  printf "\033[1;34m  -> Aplicación Web:  http://127.0.0.1:%s\033[0m\n" "$port"
  printf "\033[1;34m  -> Organigrama:     http://127.0.0.1:%s/organigrama\033[0m\n" "$port"
  printf "\033[1;34m  -> ABM Personal:    http://127.0.0.1:%s/usuarios\033[0m\n" "$port"
  printf "\033[1;34m  -> API REST:        http://127.0.0.1:%s/api/clients\033[0m\n" "$port"
  printf "\033[1;32m==========================================================\033[0m\n"
  printf "\033[33m(Presione Ctrl+C para detener el servidor de forma segura)\033[0m\n\n"

  # Ejecutar php embebido en segundo plano registrando su PID para el trap
  php -S "127.0.0.1:${port}" -t "${PROJECT_ROOT}/public" "${PROJECT_ROOT}/public/router.php" &
  local server_pid=$!
  BACKGROUND_PIDS+=("$server_pid")

  # Monitorear proceso hasta que sea cancelado por señal SIGINT/SIGTERM
  wait "$server_pid"
}

# =============================================================================
# Entrada Principal
# =============================================================================
main() {
  case "${1:-}" in
    --test)
      check_requirements
      run_tests
      ;;
    --fresh)
      check_requirements
      run_tests
      seed_database --fresh
      build_frontend
      serve_app
      ;;
    --build-only)
      check_requirements
      run_tests
      seed_database
      build_frontend
      success "Proceso de build finalizado con éxito (sin levantar servidor)."
      ;;
    --serve)
      check_requirements
      serve_app
      ;;
    "")
      # Flujo end-to-end por defecto: verificación, tests, seed, build y servidor activo
      check_requirements
      run_tests
      seed_database
      build_frontend
      serve_app
      ;;
    *)
      fail "Opción desconocida: $1 (usar --serve, --test, --fresh o --build-only)"
      ;;
  esac
}

main "$@"
