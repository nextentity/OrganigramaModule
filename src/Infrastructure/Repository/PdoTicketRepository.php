<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Model\SourceChannel;
use App\Domain\Model\Ticket;
use App\Domain\Model\TicketStatus;
use App\Domain\Model\TriageResult;
use App\Domain\Repository\TicketRepositoryInterface;
use DateTimeImmutable;
use PDO;

/**
 * Implementación PDO de TicketRepositoryInterface reutilizando la conexión SQLite del proyecto.
 * Emplea transacciones y actualizaciones atómicas para garantizar la concurrencia segura entre workers.
 */
final class PdoTicketRepository implements TicketRepositoryInterface
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function save(Ticket $ticket): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO support_tickets (
                id, source_channel, customer_id, raw_payload, status, triage_result,
                github_issue_number, github_pr_url, jira_key, created_at, updated_at
            ) VALUES (
                :id, :source_channel, :customer_id, :raw_payload, :status, :triage_result,
                :github_issue_number, :github_pr_url, :jira_key, :created_at, :updated_at
            )
        ');

        $stmt->execute([
            ':id'                  => $ticket->id,
            ':source_channel'      => $ticket->sourceChannel->value,
            ':customer_id'         => $ticket->customerId,
            ':raw_payload'         => (string) json_encode($ticket->rawPayload, JSON_UNESCAPED_UNICODE),
            ':status'              => $ticket->status()->value,
            ':triage_result'       => $ticket->triageResult()?->toJson(),
            ':github_issue_number' => $ticket->githubIssueNumber(),
            ':github_pr_url'       => $ticket->githubPrUrl(),
            ':jira_key'            => $ticket->jiraKey(),
            ':created_at'          => $ticket->createdAt->format('Y-m-d H:i:s'),
            ':updated_at'          => $ticket->updatedAt()?->format('Y-m-d H:i:s'),
        ]);
    }

    public function findById(string $id): ?Ticket
    {
        $stmt = $this->pdo->prepare('
            SELECT id, source_channel, customer_id, raw_payload, status, triage_result,
                   github_issue_number, github_pr_url, jira_key, created_at, updated_at
            FROM support_tickets
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->bindValue(':id', $id);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return $this->hydrateTicket($row);
    }

    /**
     * {@inheritdoc}
     */
    public function findPendingForProcessing(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare('
            SELECT id, source_channel, customer_id, raw_payload, status, triage_result,
                   github_issue_number, github_pr_url, jira_key, created_at, updated_at
            FROM support_tickets
            WHERE status = :status
            ORDER BY created_at ASC
            LIMIT :limit
        ');
        $stmt->bindValue(':status', TicketStatus::PENDING->value);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->hydrateTicket($row);
        }

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function findRecent(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare('
            SELECT id, source_channel, customer_id, raw_payload, status, triage_result,
                   github_issue_number, github_pr_url, jira_key, created_at, updated_at
            FROM support_tickets
            ORDER BY created_at DESC
            LIMIT :limit
        ');
        $stmt->bindValue(':limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $stmt->execute();

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->hydrateTicket($row);
        }

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function claimTicket(string $id): bool
    {
        // Actualización atómica condicional: sólo 1 worker gana la contención
        $stmt = $this->pdo->prepare('
            UPDATE support_tickets
            SET status = :in_flight, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND status = :pending
        ');

        $stmt->execute([
            ':in_flight' => TicketStatus::TRIAGED->value,
            ':id'        => $id,
            ':pending'   => TicketStatus::PENDING->value,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function update(Ticket $ticket): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE support_tickets SET
                status = :status,
                triage_result = :triage_result,
                github_issue_number = :github_issue_number,
                github_pr_url = :github_pr_url,
                jira_key = :jira_key,
                updated_at = :updated_at
            WHERE id = :id
        ');

        $triageJson = $ticket->triageResult()?->toJson();
        if ($triageJson === null && $ticket->failureReason() !== null) {
            $triageJson = (string) json_encode(['error' => $ticket->failureReason()], JSON_UNESCAPED_UNICODE);
        }

        $stmt->execute([
            ':status'              => $ticket->status()->value,
            ':triage_result'       => $triageJson,
            ':github_issue_number' => $ticket->githubIssueNumber(),
            ':github_pr_url'       => $ticket->githubPrUrl(),
            ':jira_key'            => $ticket->jiraKey(),
            ':updated_at'          => ($ticket->updatedAt() ?? new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ':id'                  => $ticket->id,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateTicket(array $row): Ticket
    {
        $rawPayload = [];
        if (isset($row['raw_payload']) && is_string($row['raw_payload'])) {
            $decoded = json_decode($row['raw_payload'], true);
            if (is_array($decoded)) {
                $rawPayload = $decoded;
            }
        }

        $triageResult = null;
        $failureReason = null;
        if (isset($row['triage_result']) && is_string($row['triage_result']) && trim($row['triage_result']) !== '') {
            $decoded = json_decode($row['triage_result'], true);
            if (is_array($decoded) && isset($decoded['error'])) {
                $failureReason = (string) $decoded['error'];
            } elseif (is_array($decoded)) {
                $triageResult = TriageResult::fromArray($decoded);
            }
        }

        $createdAt = isset($row['created_at']) && is_string($row['created_at'])
            ? new DateTimeImmutable($row['created_at'])
            : new DateTimeImmutable();

        $updatedAt = isset($row['updated_at']) && is_string($row['updated_at'])
            ? new DateTimeImmutable($row['updated_at'])
            : null;

        return new Ticket(
            id: (string) $row['id'],
            sourceChannel: SourceChannel::fromString((string) $row['source_channel']),
            customerId: (string) $row['customer_id'],
            rawPayload: $rawPayload,
            status: TicketStatus::from((string) $row['status']),
            triageResult: $triageResult,
            githubIssueNumber: isset($row['github_issue_number']) && $row['github_issue_number'] !== null ? (int) $row['github_issue_number'] : null,
            githubPrUrl: isset($row['github_pr_url']) && is_string($row['github_pr_url']) ? $row['github_pr_url'] : null,
            jiraKey: isset($row['jira_key']) && is_string($row['jira_key']) ? $row['jira_key'] : null,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
            failureReason: $failureReason,
        );
    }
}

