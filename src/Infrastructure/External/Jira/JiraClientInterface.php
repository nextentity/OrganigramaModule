<?php

declare(strict_types=1);

namespace App\Infrastructure\External\Jira;

/**
 * Contrato de cliente para Jira Cloud REST API v3.
 */
interface JiraClientInterface
{
    /**
     * @param string $summary
     * @param string $description
     * @param string $issueType e.g. 'Bug', 'Task', 'Story'
     * @param string|null $githubIssueUrl URL del issue de GitHub a vincular
     * @return array{id: string, key: string, self: string}
     */
    public function createIssue(
        string $summary,
        string $description,
        string $issueType = 'Bug',
        ?string $githubIssueUrl = null,
    ): array;
}

