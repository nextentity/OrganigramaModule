#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Script de aplicación e interpretación de parches automáticos para Auto-Fix.
 *
 * Aplica modificaciones puntuales sugeridas por el LLM en el área indicada.
 * Diseñado con validaciones de seguridad para evitar tocar archivos fuera del repo.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

$options = getopt('', ['ticket-id:', 'issue-number:', 'suggested-area:', 'patch-hints:']);

$ticketId = $options['ticket-id'] ?? 'unknown';
$issueNumber = $options['issue-number'] ?? '0';
$suggestedArea = $options['suggested-area'] ?? '';
$patchHints = $options['patch-hints'] ?? '';

fwrite(STDOUT, "🔧 [Auto-Fix] Analizando indicaciones de parche para Ticket {$ticketId} (Issue #{$issueNumber})...\n");
fwrite(STDOUT, "   Área: {$suggestedArea}\n");
fwrite(STDOUT, "   Hints: {$patchHints}\n");

if ($suggestedArea === '') {
    fwrite(STDERR, "✘ Error: suggested_area no especificada.\n");
    exit(1);
}

$targetFile = PROJECT_ROOT . '/' . ltrim($suggestedArea, '/');

// Sanitización de path traversal
$realPath = realpath($targetFile) ?: (is_dir(dirname($targetFile)) ? realpath(dirname($targetFile)) . '/' . basename($targetFile) : null);

if ($realPath === null || !str_starts_with($realPath, PROJECT_ROOT)) {
    fwrite(STDERR, "✘ Error de seguridad: intento de acceso fuera del directorio del proyecto ({$suggestedArea}).\n");
    exit(1);
}

if (!file_exists($realPath)) {
    fwrite(STDERR, "✘ Archivo objetivo no existe: {$realPath}\n");
    exit(1);
}

$content = file_get_contents($realPath);
if ($content === false) {
    fwrite(STDERR, "✘ No se pudo leer el archivo: {$realPath}\n");
    exit(1);
}

$modified = false;

// 1. Detección de bloque diff unificado en patch_hints
if (str_contains($patchHints, '--- a/') || str_contains($patchHints, '+++ b/')) {
    $tempPatchFile = tempnam(sys_get_temp_dir(), 'patch_');
    file_put_contents($tempPatchFile, $patchHints);
    $output = [];
    $returnCode = 0;
    exec(sprintf('patch -p1 --batch < %s 2>&1', escapeshellarg($tempPatchFile)), $output, $returnCode);
    @unlink($tempPatchFile);

    if ($returnCode === 0) {
        $modified = true;
        fwrite(STDOUT, "✔ Parche unificado aplicado exitosamente vía diff.\n");
    }
}

// 2. Detección de formato "replace 'X' with 'Y'" o "reemplazar 'X' por 'Y'"
if (!$modified && preg_match('/(?:reemplazar|replace|cambiar)\s+[\'"](.*?)[\'"]\s+(?:por|with)\s+[\'"](.*?)[\'"]/i', $patchHints, $matches)) {
    $search = $matches[1];
    $replace = $matches[2];

    if (str_contains($content, $search)) {
        $content = str_replace($search, $replace, $content);
        file_put_contents($realPath, $content);
        $modified = true;
        fwrite(STDOUT, "✔ Reemplazo textual aplicado: '{$search}' -> '{$replace}'\n");
    }
}

// 3. Si no es un diff formal ni un replace estructurado, pero es un archivo de texto/html/json
if (!$modified) {
    // Si contiene indicaciones específicas de typo o texto
    if (preg_match('/[\'"](.*?)[\'"]\s*(?:->|=>)\s*[\'"](.*?)[\'"]/', $patchHints, $arrowMatches)) {
        $search = $arrowMatches[1];
        $replace = $arrowMatches[2];
        if (str_contains($content, $search)) {
            $content = str_replace($search, $replace, $content);
            file_put_contents($realPath, $content);
            $modified = true;
            fwrite(STDOUT, "✔ Reemplazo semántico aplicado: '{$search}' -> '{$replace}'\n");
        }
    }
}

if ($modified) {
    fwrite(STDOUT, "✔ Modificación aplicada en {$suggestedArea}\n");
    exit(0);
}

fwrite(STDOUT, "ℹ No se pudo inferir una mutación automática determinista sin ambigüedades.\n");
exit(0);

