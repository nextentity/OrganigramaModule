<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Model\Ticket;

/**
 * Contrato de persistencia para el agregado Ticket.
 */
interface TicketRepositoryInterface
{
    /**
     * Persiste un nuevo ticket en la base de datos.
     */
    public function save(Ticket $ticket): void;

    /**
     * Busca un ticket por su identificador UUID.
     */
    public function findById(string $id): ?Ticket;

    /**
     * Obtiene tickets pendientes para triaging, respetando orden cronológico.
     *
     * @return list<Ticket>
     */
    public function findPendingForProcessing(int $limit = 10): array;

    /**
     * Reclama atómicamente un ticket PENDING para evitar condiciones de carrera entre workers.
     * Devuelve true si el worker logró reservarlo exclusivamente.
     */
    public function claimTicket(string $id): bool;

    /**
     * Actualiza el estado, resultados de triage y metadatos de integraciones.
     */
    public function update(Ticket $ticket): void;

    /**
     * Obtiene tickets ordenados por fecha de creación descendente para dashboards y monitoreo.
     *
     * @return list<Ticket>
     */
    public function findRecent(int $limit = 50): array;

    /**
     * Obtiene tickets activos (AUTO_FIXING u OPEN) que tienen un issue de GitHub asociado.
     *
     * @return list<Ticket>
     */
    public function findActiveWithExternalIssues(int $limit = 20): array;

    /**
     * Elimina permanentemente todos los tickets que no se encuentren en estado RESOLVED.
     *
     * @return int Cantidad de registros eliminados.
     */
    public function deleteNonResolved(): int;
}

