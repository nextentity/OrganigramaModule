<?php

declare(strict_types=1);

namespace App\Application\DTO;

use App\Domain\Exception\ValidationException;
use App\Domain\Model\SourceChannel;

/**
 * Data Transfer Object para la ingesta rápida y tipada de tickets.
 * Normaliza payloads de canales heterogéneos (WhatsApp Cloud API, Webforms, Helpdesk, API REST).
 */
final readonly class CreateTicketDTO
{
    /**
     * @param array<string, mixed> $rawPayload
     */
    public function __construct(
        public SourceChannel $sourceChannel,
        public string $customerId,
        public array $rawPayload,
        public string $extractedMessage,
        public ?string $ticketId = null,
    ) {
    }

    /**
     * Factory estricta que normaliza la entrada desde un array desordenado o de terceros.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if ($data === []) {
            throw ValidationException::single('payload', 'El payload de la solicitud no puede estar vacío.');
        }

        // 1. Detección de canal de origen
        $channel = self::detectSourceChannel($data);

        // 2. Extracción de customer_id
        $customerId = self::extractCustomerId($data, $channel);

        // 3. Extracción del mensaje relevante para triaging
        $extractedMessage = self::extractMessage($data, $channel);

        if (trim($extractedMessage) === '') {
            throw ValidationException::single('message', 'No se encontró un mensaje o descripción de soporte válida en el payload.');
        }

        $ticketId = isset($data['ticket_id']) && is_string($data['ticket_id']) && trim($data['ticket_id']) !== ''
            ? trim($data['ticket_id'])
            : null;

        return new self(
            sourceChannel: $channel,
            customerId: $customerId,
            rawPayload: $data,
            extractedMessage: $extractedMessage,
            ticketId: $ticketId,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function detectSourceChannel(array $data): SourceChannel
    {
        if (isset($data['source_channel']) && is_string($data['source_channel'])) {
            return SourceChannel::fromString($data['source_channel']);
        }

        // WhatsApp Cloud API Webhook payload signature
        if (isset($data['entry'][0]['changes'][0]['value']['messages'])) {
            return SourceChannel::WHATSAPP;
        }

        // Helpdesk standard webhook signature
        if (isset($data['helpdesk']) || isset($data['ticket']['requester_id'])) {
            return SourceChannel::HELPDESK;
        }

        // Webform standard payload
        if (isset($data['form_id']) || isset($data['webform'])) {
            return SourceChannel::WEBFORM;
        }

        return SourceChannel::API;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractCustomerId(array $data, SourceChannel $channel): string
    {
        if (isset($data['customer_id']) && is_string($data['customer_id']) && trim($data['customer_id']) !== '') {
            return trim($data['customer_id']);
        }

        if ($channel === SourceChannel::WHATSAPP) {
            $sender = $data['entry'][0]['changes'][0]['value']['messages'][0]['from']
                ?? $data['entry'][0]['changes'][0]['value']['contacts'][0]['wa_id']
                ?? null;

            if (is_string($sender) && trim($sender) !== '') {
                return 'wa_' . trim($sender);
            }
        }

        if ($channel === SourceChannel::HELPDESK) {
            $requester = $data['ticket']['requester_id']
                ?? $data['ticket']['requester']['email']
                ?? $data['requester_id']
                ?? null;

            if (is_string($requester) && trim($requester) !== '') {
                return 'helpdesk_' . trim($requester);
            }
        }

        if (isset($data['email']) && is_string($data['email']) && trim($data['email']) !== '') {
            return trim($data['email']);
        }

        throw ValidationException::single('customer_id', 'No se pudo identificar el customer_id en el payload provisto.');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractMessage(array $data, SourceChannel $channel): string
    {
        if (isset($data['message']) && is_string($data['message']) && trim($data['message']) !== '') {
            return trim($data['message']);
        }

        if (isset($data['description']) && is_string($data['description']) && trim($data['description']) !== '') {
            return trim($data['description']);
        }

        if ($channel === SourceChannel::WHATSAPP) {
            $textBody = $data['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] ?? null;
            if (is_string($textBody) && trim($textBody) !== '') {
                return trim($textBody);
            }
        }

        if ($channel === SourceChannel::HELPDESK) {
            $description = $data['ticket']['description']
                ?? $data['ticket']['comment']['body']
                ?? $data['ticket']['subject']
                ?? null;

            if (is_string($description) && trim($description) !== '') {
                return trim($description);
            }
        }

        // Fallback a JSON pretty de campos no-estructurados
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}

