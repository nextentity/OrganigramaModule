<?php

declare(strict_types=1);

namespace App\Infrastructure\External\GitHub;

use App\Domain\Model\Ticket;
use App\Domain\Model\TriageResult;

/**
 * Contrato de cliente para la API REST de GitHub.
 */
interface GitHubClientInterface
{
    /**
     * @param string $title
     * @param string $body
     * @param list<string> $labels
     * @return array{number: int, html_url: string, id: int}
     */
    public function createIssue(string $title, string $body, array $labels = []): array;

    /**
     * @param string $workflowFileName e.g. 'auto-fix.yml'
     * @param string $ref e.g. 'main'
     * @param array<string, string> $inputs
     */
    public function triggerWorkflowDispatch(string $workflowFileName, string $ref, array $inputs): bool;

    /**
     * Formatea el cuerpo del issue en Markdown profesional.
     */
    public function formatIssueBody(Ticket $ticket, TriageResult $triage): string;

    /**
     * Consulta el estado de un issue en GitHub.
     *
     * @return array{number: int, state: string, closed_at: ?string, html_url: string}|null
     */
    public function getIssue(int $issueNumber): ?array;

    /**
     * Consulta si existe un Pull Request para la rama de un ticket.
     *
     * @return array{number: int, html_url: string, state: string, merged: bool}|null
     */
    public function findPullRequestForBranch(string $branchName): ?array;

    /**
     * Indica si el cliente cuenta con credenciales válidas configuradas.
     */
    public function isConfigured(): bool;
}

