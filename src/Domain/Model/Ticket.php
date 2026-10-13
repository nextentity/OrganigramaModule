<?php

declare(strict_types=1);

namespace App\Domain\Model;

use App\Domain\Exception\InvalidTicketTransitionException;
use App\Domain\Exception\ValidationException;
use DateTimeImmutable;

/**
 * Entidad Aggregate Root que representa un Ticket de Soporte Técnico.
 * Encapsula invariantes de negocio y transiciones estrictas de estado.
 */
final class Ticket
{
    public function __construct(
        public readonly string $id,
        public readonly SourceChannel $sourceChannel,
        public readonly string $customerId,
        public readonly array $rawPayload,
        private TicketStatus $status = TicketStatus::PENDING,
        private ?TriageResult $triageResult = null,
        private ?int $githubIssueNumber = null,
        private ?string $githubPrUrl = null,
        private ?string $jiraKey = null,
        public readonly DateTimeImmutable $createdAt = new DateTimeImmutable(),
        private ?DateTimeImmutable $updatedAt = null,
    ) {
        $this->assertInvariants();
    }

    public static function createPending(
        string $id,
        SourceChannel $sourceChannel,
        string $customerId,
        array $rawPayload,
    ): self {
        return new self(
            id: $id,
            sourceChannel: $sourceChannel,
            customerId: $customerId,
            rawPayload: $rawPayload,
            status: TicketStatus::PENDING,
            createdAt: new DateTimeImmutable(),
        );
    }

    public function status(): TicketStatus
    {
        return $this->status;
    }

    public function triageResult(): ?TriageResult
    {
        return $this->triageResult;
    }

    public function githubIssueNumber(): ?int
    {
        return $this->githubIssueNumber;
    }

    public function githubPrUrl(): ?string
    {
        return $this->githubPrUrl;
    }

    public function jiraKey(): ?string
    {
        return $this->jiraKey;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Transición a TRIAGED tras evaluación exitosa del LLM.
     */
    public function markAsTriaged(TriageResult $result): void
    {
        $this->assertCanTransitionTo(TicketStatus::TRIAGED);

        $this->triageResult = $result;
        $this->status = TicketStatus::TRIAGED;
        $this->touch();
    }

    /**
     * Transición a AUTO_FIXING para bugs auto-reparables con workflow disparado.
     */
    public function markAsAutoFixing(int $githubIssueNumber, ?string $jiraKey = null): void
    {
        if ($this->triageResult === null) {
            throw new InvalidTicketTransitionException(
                $this->id,
                $this->status->value,
                TicketStatus::AUTO_FIXING->value,
                'El ticket no ha sido clasificado por IA previamente.',
            );
        }

        if ($this->triageResult->type !== TicketType::BUG || !$this->triageResult->isAutoFixable) {
            throw new InvalidTicketTransitionException(
                $this->id,
                $this->status->value,
                TicketStatus::AUTO_FIXING->value,
                'Solo los incidentes clasificados como bug con is_auto_fixable=true pueden pasar a AUTO_FIXING.',
            );
        }

        $this->assertCanTransitionTo(TicketStatus::AUTO_FIXING);

        $this->githubIssueNumber = $githubIssueNumber;
        if ($jiraKey !== null) {
            $this->jiraKey = $jiraKey;
        }
        $this->status = TicketStatus::AUTO_FIXING;
        $this->touch();
    }

    /**
     * Transición a OPEN cuando requiere atención de ingeniería (sin auto-fix).
     */
    public function markAsOpen(int $githubIssueNumber, ?string $jiraKey = null): void
    {
        $this->assertCanTransitionTo(TicketStatus::OPEN);

        $this->githubIssueNumber = $githubIssueNumber;
        if ($jiraKey !== null) {
            $this->jiraKey = $jiraKey;
        }
        $this->status = TicketStatus::OPEN;
        $this->touch();
    }

    /**
     * Transición a RESOLVED cuando es una duda operativa o se completó el flujo.
     */
    public function markAsResolved(?string $cannedResponse = null): void
    {
        $this->assertCanTransitionTo(TicketStatus::RESOLVED);

        if ($cannedResponse !== null && $this->triageResult !== null) {
            // Actualiza o conserva el resultado de triage con la respuesta aplicada
            $this->triageResult = new TriageResult(
                summary: $this->triageResult->summary,
                type: $this->triageResult->type,
                severity: $this->triageResult->severity,
                reproSteps: $this->triageResult->reproSteps,
                isAutoFixable: $this->triageResult->isAutoFixable,
                suggestedArea: $this->triageResult->suggestedArea,
                patchHints: $this->triageResult->patchHints,
                cannedResponse: $cannedResponse,
            );
        }

        $this->status = TicketStatus::RESOLVED;
        $this->touch();
    }

    /**
     * Transición defensiva a FAILED en caso de excepciones irrecuperables.
     */
    public function markAsFailed(string $reason): void
    {
        if ($this->status->isTerminal()) {
            throw new InvalidTicketTransitionException(
                $this->id,
                $this->status->value,
                TicketStatus::FAILED->value,
                "El ticket ya se encuentra en estado terminal {$this->status->value}.",
            );
        }

        $this->status = TicketStatus::FAILED;
        $this->touch();
    }

    public function setGithubPrUrl(string $githubPrUrl): void
    {
        if (trim($githubPrUrl) === '') {
            throw ValidationException::single('github_pr_url', 'La URL del Pull Request no puede estar vacía.');
        }

        $this->githubPrUrl = $githubPrUrl;
        $this->touch();
    }

    public function setJiraKey(string $jiraKey): void
    {
        if (trim($jiraKey) === '') {
            throw ValidationException::single('jira_key', 'La key de Jira no puede estar vacía.');
        }

        $this->jiraKey = $jiraKey;
        $this->touch();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'                  => $this->id,
            'source_channel'      => $this->sourceChannel->value,
            'customer_id'         => $this->customerId,
            'raw_payload'         => $this->rawPayload,
            'status'              => $this->status->value,
            'triage_result'       => $this->triageResult?->toArray(),
            'github_issue_number' => $this->githubIssueNumber,
            'github_pr_url'       => $this->githubPrUrl,
            'jira_key'            => $this->jiraKey,
            'created_at'          => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at'          => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }

    private function assertInvariants(): void
    {
        $errors = [];

        if (trim($this->id) === '') {
            $errors['id'] = 'El ID del ticket no puede estar vacío.';
        }

        if (trim($this->customerId) === '') {
            $errors['customer_id'] = 'El customer_id no puede estar vacío.';
        }

        if ($this->rawPayload === []) {
            $errors['raw_payload'] = 'El raw_payload no puede ser un array vacío.';
        }

        if ($errors !== []) {
            throw ValidationException::withErrors($errors);
        }
    }

    private function assertCanTransitionTo(TicketStatus $target): void
    {
        if (!$this->status->canTransitionTo($target)) {
            throw new InvalidTicketTransitionException(
                $this->id,
                $this->status->value,
                $target->value,
                "No está permitida la transición de {$this->status->value} a {$target->value}.",
            );
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}

