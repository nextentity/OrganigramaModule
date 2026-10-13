<?php

declare(strict_types=1);

/**
 * Router para el servidor embebido de PHP (php -S localhost:8000 public/router.php).
 * Redirige peticiones /api/... a index.php, sirve archivos estáticos del frontend (dist),
 * y delega cualquier ruta desconocida de la SPA a index.html.
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

// Si es una petición a la API REST, ejecutar Front Controller index.php
if (str_starts_with($uri, '/api')) {
    require __DIR__ . '/index.php';
    exit(0);
}

// Servir Dashboard Interactivo del Agente IA en / o /dashboard
if (($uri === '/' || $uri === '/dashboard') && is_file(__DIR__ . '/dashboard.html')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/dashboard.html');
    exit(0);
}

// Buscar archivos estáticos en public/ o en el build de Angular (frontend/dist)
$distDir = dirname(__DIR__) . '/frontend/dist/organigrama-frontend/browser';
if (!is_dir($distDir)) {
    $distDir = dirname(__DIR__) . '/frontend/dist/organigrama-frontend';
}

$targetFile = $distDir . $uri;

if ($uri !== '/' && is_file($targetFile)) {
    $mimeType = match (pathinfo($targetFile, PATHINFO_EXTENSION)) {
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'json' => 'application/json',
        'png'  => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'woff2' => 'font/woff2',
        'ttf'  => 'font/ttf',
        default => mime_content_type($targetFile) ?: 'application/octet-stream',
    };

    header("Content-Type: {$mimeType}");
    readfile($targetFile);
    exit(0);
}

// Si la SPA de Angular está compilada, servir index.html para soporte de HTML5 History API
$spaIndex = $distDir . '/index.html';
if (is_file($spaIndex)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($spaIndex);
    exit(0);
}

// Fallback al Front Controller PHP si la SPA aún no fue compilada
require __DIR__ . '/index.php';

