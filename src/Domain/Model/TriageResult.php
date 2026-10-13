<?php

declare(strict_types=1);

namespace App\Domain\Model;

use App\Domain\Exception\ValidationException;
use JsonSerializable;

/**
 * Value Object inmutable que representa el resultado estructurado del triage con LLM.
 */
final readonly class TriageResult implements JsonSerializable
{
    /**
     * @param list<string> $reproSteps
     */
    public function __construct(
        public string $summary,
        public TicketType $type,
        public TicketSeverity $severity,
        public array $reproSteps,
        public bool $isAutoFixable,
        public string $suggestedArea,
        public string $patchHints,
        public ?string $cannedResponse = null,
    ) {
        $errors = [];

        if (trim($this->summary) === '') {
            $errors['summary'] = 'El resumen del triage no puede estar vacío.';
        }

        if (trim($this->suggestedArea) === '') {
            $errors['suggested_area'] = 'El área sugerida no puede estar vacía.';
        }

        if ($this->type === TicketType::OPERATIONAL_QUESTION && ($this->cannedResponse === null || trim($this->cannedResponse) === '')) {
            $errors['canned_response'] = 'Una duda operativa debe incluir una respuesta sugerida (canned_response).';
        }

        if ($errors !== []) {
            throw ValidationException::withErrors($errors);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $summary = (string) ($data['summary'] ?? '');
        $typeStr = (string) ($data['type'] ?? '');
        $severityStr = (string) ($data['severity'] ?? '');
        $reproStepsRaw = $data['repro_steps'] ?? [];
        $isAutoFixable = (bool) ($data['is_auto_fixable'] ?? false);
        $suggestedArea = (string) ($data['suggested_area'] ?? '');
        $patchHints = (string) ($data['patch_hints'] ?? '');
        $cannedResponse = isset($data['canned_response']) && $data['canned_response'] !== null && trim((string) $data['canned_response']) !== ''
            ? (string) $data['canned_response']
            : null;

        $type = TicketType::fromString($typeStr);
        $severity = TicketSeverity::fromString($severityStr);

        /** @var list<string> $reproSteps */
        $reproSteps = is_array($reproStepsRaw)
            ? array_values(array_filter(array_map('strval', $reproStepsRaw), static fn (string $s): bool => trim($s) !== ''))
            : [];

        return new self(
            summary: $summary,
            type: $type,
            severity: $severity,
            reproSteps: $reproSteps,
            isAutoFixable: $isAutoFixable,
            suggestedArea: $suggestedArea,
            patchHints: $patchHints,
            cannedResponse: $cannedResponse,
        );
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw ValidationException::single('triage_result', 'El JSON de triage_result no es válido.');
        }

        return self::fromArray($decoded);
    }

    /**
     * @return array{
     *     summary: string,
     *     type: string,
     *     severity: string,
     *     repro_steps: list<string>,
     *     is_auto_fixable: bool,
     *     suggested_area: string,
     *     patch_hints: string,
     *     canned_response: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'summary'         => $this->summary,
            'type'            => $this->type->value,
            'severity'        => $this->severity->value,
            'repro_steps'     => $this->reproSteps,
            'is_auto_fixable' => $this->isAutoFixable,
            'suggested_area'  => $this->suggestedArea,
            'patch_hints'     => $this->patchHints,
            'canned_response' => $this->cannedResponse,
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

