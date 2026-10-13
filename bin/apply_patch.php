#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Script de aplicación e interpretación de parches automáticos para Auto-Fix.
 *
 * Aplica modificaciones puntuales sugeridas por el LLM en el área indicada.
 * Diseñado con validaciones de seguridad para evitar tocar archivos fuera del repo
 * y resolución inteligente de rutas en caso de ligeras divergencias de nomenclatura.
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

// 1. Sanitización de path traversal
if (str_contains($suggestedArea, '..')) {
    fwrite(STDERR, "✘ Error de seguridad: intento de path traversal detectado ({$suggestedArea}).\n");
    exit(1);
}

$targetFile = PROJECT_ROOT . '/' . ltrim($suggestedArea, '/');
$realPath = file_exists($targetFile) ? realpath($targetFile) : null;

// 2. Si la ruta exacta no existe en disco, buscar archivo equivalente en el repositorio
if ($realPath === null) {
    fwrite(STDOUT, "ℹ Archivo sugerido no encontrado en la ruta exacta ({$suggestedArea}).\n");
    fwrite(STDOUT, "   Buscando archivo coincidente en el proyecto...\n");
    $candidate = findMatchingFile(PROJECT_ROOT, $suggestedArea, $patchHints);
    if ($candidate !== null) {
        $realPath = $candidate;
        $relFound = str_replace(PROJECT_ROOT . '/', '', $realPath);
        fwrite(STDOUT, "✔ Archivo equivalente localizado: {$relFound}\n");
    }
}

if ($realPath === null || !file_exists($realPath) || !str_starts_with($realPath, PROJECT_ROOT)) {
    fwrite(STDERR, "✘ Archivo objetivo no encontrado en el repositorio: {$suggestedArea}\n");
    exit(1);
}

$content = file_get_contents($realPath);
if ($content === false) {
    fwrite(STDERR, "✘ No se pudo leer el archivo: {$realPath}\n");
    exit(1);
}

$modified = false;

// A. Detección de bloque diff unificado en patch_hints
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

// B. Detección de formato "reemplazar 'X' por 'Y'" o "replace 'X' with 'Y'"
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

// C. Detección de formato con flecha: 'X' -> 'Y' o 'X' => 'Y'
if (!$modified && preg_match('/[\'"](.*?)[\'"]\s*(?:->|=>)\s*[\'"](.*?)[\'"]/', $patchHints, $arrowMatches)) {
    $search = $arrowMatches[1];
    $replace = $arrowMatches[2];
    if (str_contains($content, $search)) {
        $content = str_replace($search, $replace, $content);
        file_put_contents($realPath, $content);
        $modified = true;
        fwrite(STDOUT, "✔ Reemplazo semántico aplicado: '{$search}' -> '{$replace}'\n");
    }
}

// D. Detección de instrucción de remoción explícita: "remover 'X'" / "eliminar 'X'"
if (!$modified && preg_match('/(?:remover|eliminar|sacar|quitar|borrar)\s+[\'"](.*?)[\'"]/i', $patchHints, $delMatches)) {
    $toRemove = $delMatches[1];
    if (str_contains($content, $toRemove)) {
        $content = str_replace($toRemove, '', $content);
        file_put_contents($realPath, $content);
        $modified = true;
        fwrite(STDOUT, "✔ Fragmento removido: '{$toRemove}'\n");
    }
}

// E. Detección de remoción de placeholder en HTML: "remover ... placeholder"
if (!$modified && preg_match('/(?:remover|quitar|eliminar|sacar)\s+(?:el\s+atributo\s+)?(?:\[?placeholder\]?|placeholder)/i', $patchHints)) {
    // Buscar patrón placeholder="..." o [placeholder]="..."
    if (preg_match('/\s*\[?placeholder\]?\s*=\s*["\'][^"\']*["\']/', $content, $phMatch)) {
        $content = str_replace($phMatch[0], '', $content);
        file_put_contents($realPath, $content);
        $modified = true;
        fwrite(STDOUT, "✔ Atributo placeholder removido: '{$phMatch[0]}'\n");
    }
}

// F. Detección de pares de comillas simples o dobles en el hint (ej: 'Gaurdar' y 'Guardar')
if (!$modified && preg_match_all('/[\'"]([^\'"]+)[\'"]/', $patchHints, $allQuotes) && count($allQuotes[1]) >= 2) {
    $search = $allQuotes[1][0];
    $replace = $allQuotes[1][1];
    if (str_contains($content, $search)) {
        $content = str_replace($search, $replace, $content);
        file_put_contents($realPath, $content);
        $modified = true;
        fwrite(STDOUT, "✔ Reemplazo inferido de citas: '{$search}' -> '{$replace}'\n");
    }
}

if ($modified) {
    $relTarget = str_replace(PROJECT_ROOT . '/', '', $realPath);
    fwrite(STDOUT, "✔ Modificación completada exitosamente en {$relTarget}\n");
    exit(0);
}

fwrite(STDOUT, "ℹ No se pudo inferir una mutación determinista sin ambigüedades a partir de los hints.\n");
exit(0);

/**
 * Busca inteligentemente un archivo coincidente en el repositorio si la IA alucinó una subcarpeta.
 */
function findMatchingFile(string $projectRoot, string $suggestedArea, string $patchHints): ?string
{
    $combined = strtolower($suggestedArea . ' ' . $patchHints);
    $basename = basename($suggestedArea);

    // 1. Si el nombre de archivo exacto existe en alguna otra carpeta de frontend o src
    $recursiveFind = glob($projectRoot . '/frontend/src/**/' . $basename, GLOB_BRACE) ?: [];
    if (!empty($recursiveFind) && file_exists($recursiveFind[0])) {
        return realpath($recursiveFind[0]);
    }

    // 2. Si se refiere a ABM de personal / colaboradores / usuarios
    if (preg_match('/staff|personal|user|abm|colaborador/i', $combined)) {
        $candidateHtml = $projectRoot . '/frontend/src/app/components/user-abm/user-abm.component.html';
        $candidateTs = $projectRoot . '/frontend/src/app/components/user-abm/user-abm.component.ts';
        if (str_ends_with($suggestedArea, '.ts') && file_exists($candidateTs)) {
            return realpath($candidateTs);
        }
        if (file_exists($candidateHtml)) {
            return realpath($candidateHtml);
        }
    }

    // 3. Si se refiere a organigrama / org-chart / árbol jerárquico
    if (preg_match('/organigrama|org-chart|chart|tree|jerarquia/i', $combined)) {
        $candidateHtml = $projectRoot . '/frontend/src/app/components/org-chart/org-chart.component.html';
        $candidateTs = $projectRoot . '/frontend/src/app/components/org-chart/org-chart.component.ts';
        if (str_ends_with($suggestedArea, '.ts') && file_exists($candidateTs)) {
            return realpath($candidateTs);
        }
        if (file_exists($candidateHtml)) {
            return realpath($candidateHtml);
        }
    }

    // 4. Si los hints son sobre placeholder de búsqueda, buscar inputs en componentes html
    if (str_contains($combined, 'placeholder')) {
        $htmlFiles = glob($projectRoot . '/frontend/src/app/components/**/*.html') ?: [];
        foreach ($htmlFiles as $file) {
            $c = file_get_contents($file);
            if ($c !== false && str_contains($c, 'placeholder=')) {
                return realpath($file);
            }
        }
    }

    return null;
}
