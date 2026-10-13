#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Worker CLI de orquestación asíncrona para tickets de soporte técnico.
 *
 * Uso:
 *   php bin/ticket_worker.php            Modo daemon continuo (escucha tickets en loop).
 *   php bin/ticket_worker.php --once     Procesa el lote pendiente actual y finaliza.
 *   php bin/ticket_worker.php --limit=5  Ajusta el tamaño del lote por ciclo.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Application\Service\LlmTriageService;
use App\Application\Service\TicketOrchestratorService;
use App\Infrastructure\External\GitHub\GitHubClient;
use App\Infrastructure\External\Jira\JiraClient;
use App\Infrastructure\External\Llm\StructuredLlmClient;
use App\Infrastructure\Repository\PdoTicketRepository;

/** @var PDO $pdo */
$pdo = require PROJECT_ROOT . '/config/db.php';

$options = getopt('', ['once', 'limit:', 'sleep:']);
$runOnce = isset($options['once']);
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 10;
$sleepSeconds = isset($options['sleep']) ? max(1, (int) $options['sleep']) : 3;

$logger = static function (string $message): void {
    fwrite(STDOUT, sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message));
};

$repository = new PdoTicketRepository($pdo);
$llmClient = StructuredLlmClient::fromEnvironment(logger: $logger);
$triageService = new LlmTriageService($llmClient);
$gitHubClient = GitHubClient::fromEnvironment();
$jiraClient = JiraClient::fromEnvironment();

$orchestrator = new TicketOrchestratorService(
    ticketRepository: $repository,
    triageService: $triageService,
    gitHubClient: $gitHubClient,
    jiraClient: $jiraClient,
    logger: $logger,
);

$fallbackList = $llmClient->fallbackModels();
$fallbackChainStr = $fallbackList !== [] ? implode(' ➔ ', $fallbackList) : 'ninguno';

$logger('=====================================================');
$logger('🚀 Worker de Triaging de Soporte Técnico Inicializado');
$logger(sprintf('   Modo: %s | Batch Limit: %d | SQLite WAL Activo', $runOnce ? 'One-Shot (--once)' : 'Daemon Continuo', $limit));
$logger(sprintf('   LLM:    %s | Modelo: %s', (getenv('LLM_API_KEY') ?: '') !== '' ? '✔ Activo' : '⚠ Modo Mock', $llmClient->model()));
$logger(sprintf('   Cadena de Fallback: %s', $fallbackChainStr));
$logger(sprintf('   GitHub: %s (%s/%s)', $gitHubClient->isConfigured() ? '✔ Activo (GITHUB_TOKEN configurado)' : '⚠ Modo Mock (Falta GITHUB_TOKEN)', getenv('GITHUB_OWNER') ?: 'nextentity', getenv('GITHUB_REPO') ?: 'OrganigramaModule'));
$logger(sprintf('   Jira:   %s', $jiraClient->isConfigured() ? '✔ Activo (Jira API Token configurado)' : '⚠ Modo Mock (Falta JIRA_API_TOKEN)'));
$logger('=====================================================');

// Recuperación defensiva: tickets que quedaron en TRIAGED sin resultado tras reinicio o kill del contenedor
try {
    $recovered = $pdo->exec("UPDATE support_tickets SET status = 'PENDING' WHERE status = 'TRIAGED' AND triage_result IS NULL");
    if ($recovered !== false && $recovered > 0) {
        $logger(sprintf('🔄 [Recovery] %d ticket(s) en vuelo interrumpidos reencolados a PENDING para reintento.', $recovered));
    }
} catch (Throwable) {
    // Modo tolerante
}

$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $shutdown = static function (int $signal) use (&$running, $logger): void {
        $logger('⏹ Señal de parada recibida (SIGTERM/SIGINT). Finalizando de forma segura...');
        $running = false;
    };
    pcntl_signal(SIGTERM, $shutdown);
    pcntl_signal(SIGINT, $shutdown);
}

do {
    try {
        $processed = $orchestrator->processPendingBatch($limit);

        if ($processed > 0) {
            $logger(sprintf('✔ Ciclo completado: %d ticket(s) procesados.', $processed));
        }

        if ($runOnce) {
            $logger('✔ Ejecución --once finalizada con éxito.');
            break;
        }

        if ($processed === 0) {
            sleep($sleepSeconds);
        }
    } catch (Throwable $unhandled) {
        fwrite(STDERR, sprintf("[CRITICAL] Error en ciclo del worker: %s\n%s\n", $unhandled->getMessage(), $unhandled->getTraceAsString()));
        if ($runOnce) {
            exit(1);
        }
        sleep($sleepSeconds);
    }
} while ($running);

exit(0);

