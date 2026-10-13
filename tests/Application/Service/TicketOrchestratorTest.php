<?php

declare(strict_types=1);

namespace Tests\Application\Service;

use App\Application\Service\LlmTriageServiceInterface;
use App\Application\Service\TicketOrchestratorService;
use App\Domain\Model\SourceChannel;
use App\Domain\Model\Ticket;
use App\Domain\Model\TicketSeverity;
use App\Domain\Model\TicketStatus;
use App\Domain\Model\TicketType;
use App\Domain\Model\TriageResult;
use App\Infrastructure\External\GitHub\GitHubClientInterface;
use App\Infrastructure\External\Jira\JiraClientInterface;
use App\Infrastructure\Repository\PdoTicketRepository;
use App\Infrastructure\Support\Uuid;
use PDO;
use RuntimeException;
use Tests\Support\TestCase;

final class TicketOrchestratorTest extends TestCase
{
    private PDO $pdo;
    private PdoTicketRepository $repository;

    public function setUp(): void
    {
        $this->pdo = $this->createMigratedDatabase();
        $this->repository = new PdoTicketRepository($this->pdo);
    }

    public function testOperationalQuestionResolvesDirectlyWithoutExternalCalls(): void
    {
        $ticketId = Uuid::v4();
        $ticket = Ticket::createPending($ticketId, SourceChannel::WEBFORM, 'client_help', [
            'message' => '¿Cómo reasigno un subordinado?',
        ]);
        $this->repository->save($ticket);

        $fakeTriage = new class implements LlmTriageServiceInterface {
            public function triage(Ticket $ticket): TriageResult
            {
                return new TriageResult(
                    summary: 'Consulta sobre reasignación',
                    type: TicketType::OPERATIONAL_QUESTION,
                    severity: TicketSeverity::LOW,
                    reproSteps: [],
                    isAutoFixable: false,
                    suggestedArea: 'docs',
                    patchHints: '',
                    cannedResponse: 'Para reasignar, usa el selector de supervisor en la edición del usuario.',
                );
            }
        };

        $fakeGitHub = new class implements GitHubClientInterface {
            public bool $called = false;
            public function createIssue(string $title, string $body, array $labels = []): array
            {
                $this->called = true;
                return ['number' => 1, 'html_url' => 'http', 'id' => 1];
            }
            public function triggerWorkflowDispatch(string $workflowFileName, string $ref, array $inputs): bool
            {
                $this->called = true;
                return true;
            }
            public function formatIssueBody(Ticket $ticket, TriageResult $triage): string
            {
                return 'body';
            }
        };

        $fakeJira = new class implements JiraClientInterface {
            public bool $called = false;
            public function createIssue(string $summary, string $description, string $issueType = 'Bug', ?string $githubIssueUrl = null): array
            {
                $this->called = true;
                return ['id' => '1', 'key' => 'SUP-1', 'self' => 'http'];
            }
        };

        $orchestrator = new TicketOrchestratorService(
            ticketRepository: $this->repository,
            triageService: $fakeTriage,
            gitHubClient: $fakeGitHub,
            jiraClient: $fakeJira,
            logger: static fn () => null,
        );

        $processed = $orchestrator->processPendingBatch();
        $this->assertSame(1, $processed);

        $updated = $this->repository->findById($ticketId);
        $this->assertSame(TicketStatus::RESOLVED, $updated?->status());
        $this->assertSame('Para reasignar, usa el selector de supervisor en la edición del usuario.', $updated?->triageResult()?->cannedResponse);
        $this->assertFalse($fakeGitHub->called, 'No se debe llamar a GitHub para dudas operativas');
        $this->assertFalse($fakeJira->called, 'No se debe llamar a Jira para dudas operativas');
    }

    public function testStandardBugCreatesGithubIssueAndJiraCard(): void
    {
        $ticketId = Uuid::v4();
        $ticket = Ticket::createPending($ticketId, SourceChannel::API, 'client_bug', [
            'message' => 'Fallo 500 al eliminar usuario raíz',
        ]);
        $this->repository->save($ticket);

        $fakeTriage = new class implements LlmTriageServiceInterface {
            public function triage(Ticket $ticket): TriageResult
            {
                return new TriageResult(
                    summary: '500 al eliminar usuario raíz sin subordinados',
                    type: TicketType::BUG,
                    severity: TicketSeverity::HIGH,
                    reproSteps: ['Click delete usuario'],
                    isAutoFixable: false,
                    suggestedArea: 'src/Application/Service/OrganigramaService.php',
                    patchHints: 'Manejar caso borde cuando no hay sucesor',
                    cannedResponse: null,
                );
            }
        };

        $fakeGitHub = new class implements GitHubClientInterface {
            public ?string $createdTitle = null;
            public function createIssue(string $title, string $body, array $labels = []): array
            {
                $this->createdTitle = $title;
                return ['number' => 456, 'html_url' => 'https://github.com/issues/456', 'id' => 456];
            }
            public function triggerWorkflowDispatch(string $workflowFileName, string $ref, array $inputs): bool
            {
                return false;
            }
            public function formatIssueBody(Ticket $ticket, TriageResult $triage): string
            {
                return 'Issue body markdown';
            }
        };

        $fakeJira = new class implements JiraClientInterface {
            public ?string $linkedUrl = null;
            public function createIssue(string $summary, string $description, string $issueType = 'Bug', ?string $githubIssueUrl = null): array
            {
                $this->linkedUrl = $githubIssueUrl;
                return ['id' => '102', 'key' => 'SUP-456', 'self' => 'https://jira'];
            }
        };

        $orchestrator = new TicketOrchestratorService(
            ticketRepository: $this->repository,
            triageService: $fakeTriage,
            gitHubClient: $fakeGitHub,
            jiraClient: $fakeJira,
            logger: static fn () => null,
        );

        $orchestrator->processPendingBatch();

        $updated = $this->repository->findById($ticketId);
        $this->assertSame(TicketStatus::OPEN, $updated?->status());
        $this->assertSame(456, $updated?->githubIssueNumber());
        $this->assertSame('SUP-456', $updated?->jiraKey());
        $this->assertSame('https://github.com/issues/456', $fakeJira->linkedUrl);
    }

    public function testAutoFixableBugTransitionsToAutoFixingAndDispatchesWorkflow(): void
    {
        $ticketId = Uuid::v4();
        $ticket = Ticket::createPending($ticketId, SourceChannel::WEBFORM, 'client_typo', [
            'message' => 'Typo en el botón de guardar: dice Gurdar',
        ]);
        $this->repository->save($ticket);

        $fakeTriage = new class implements LlmTriageServiceInterface {
            public function triage(Ticket $ticket): TriageResult
            {
                return new TriageResult(
                    summary: 'Typo en etiqueta de botón de guardado',
                    type: TicketType::BUG,
                    severity: TicketSeverity::LOW,
                    reproSteps: ['Abrir pantalla de ABM', 'Ver botón'],
                    isAutoFixable: true,
                    suggestedArea: 'frontend/src/app/components/user-abm/user-abm.component.html',
                    patchHints: "reemplazar 'Gurdar' por 'Guardar'",
                    cannedResponse: null,
                );
            }
        };

        $fakeGitHub = new class implements GitHubClientInterface {
            public bool $dispatched = false;
            /** @var array<string, string> */
            public array $dispatchInputs = [];

            public function createIssue(string $title, string $body, array $labels = []): array
            {
                return ['number' => 789, 'html_url' => 'https://github.com/issues/789', 'id' => 789];
            }
            public function triggerWorkflowDispatch(string $workflowFileName, string $ref, array $inputs): bool
            {
                $this->dispatched = true;
                $this->dispatchInputs = $inputs;
                return true;
            }
            public function formatIssueBody(Ticket $ticket, TriageResult $triage): string
            {
                return 'Auto fix body';
            }
        };

        $fakeJira = new class implements JiraClientInterface {
            public function createIssue(string $summary, string $description, string $issueType = 'Bug', ?string $githubIssueUrl = null): array
            {
                return ['id' => '103', 'key' => 'SUP-789', 'self' => 'https://jira'];
            }
        };

        $orchestrator = new TicketOrchestratorService(
            ticketRepository: $this->repository,
            triageService: $fakeTriage,
            gitHubClient: $fakeGitHub,
            jiraClient: $fakeJira,
            logger: static fn () => null,
        );

        $orchestrator->processPendingBatch();

        $updated = $this->repository->findById($ticketId);
        $this->assertSame(TicketStatus::AUTO_FIXING, $updated?->status());
        $this->assertTrue($fakeGitHub->dispatched, 'workflow_dispatch debe ser invocado');
        $this->assertSame((string) 789, $fakeGitHub->dispatchInputs['issue_number']);
        $this->assertSame($ticketId, $fakeGitHub->dispatchInputs['ticket_id']);
    }

    public function testDefensiveErrorHandlingTransitionsToFailed(): void
    {
        $ticketId = Uuid::v4();
        $ticket = Ticket::createPending($ticketId, SourceChannel::API, 'client_fail', [
            'message' => 'Crash test',
        ]);
        $this->repository->save($ticket);

        $failingTriage = new class implements LlmTriageServiceInterface {
            public function triage(Ticket $ticket): TriageResult
            {
                throw new RuntimeException('API de LLM agotó cuota (429 Rate Limited)');
            }
        };

        $fakeGitHub = new class implements GitHubClientInterface {
            public function createIssue(string $title, string $body, array $labels = []): array { return []; }
            public function triggerWorkflowDispatch(string $workflowFileName, string $ref, array $inputs): bool { return false; }
            public function formatIssueBody(Ticket $ticket, TriageResult $triage): string { return ''; }
        };

        $fakeJira = new class implements JiraClientInterface {
            public function createIssue(string $summary, string $description, string $issueType = 'Bug', ?string $githubIssueUrl = null): array { return []; }
        };

        $orchestrator = new TicketOrchestratorService(
            ticketRepository: $this->repository,
            triageService: $failingTriage,
            gitHubClient: $fakeGitHub,
            jiraClient: $fakeJira,
            logger: static fn () => null,
        );

        $orchestrator->processPendingBatch();

        $updated = $this->repository->findById($ticketId);
        $this->assertSame(TicketStatus::FAILED, $updated?->status());
    }
}

