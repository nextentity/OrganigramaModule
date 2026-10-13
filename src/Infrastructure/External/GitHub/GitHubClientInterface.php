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
}

