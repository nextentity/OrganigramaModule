<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class HierarchyException extends DomainException
{
    public static function selfReference(): self
    {
        return new self('Un usuario no puede ser su propio supervisor.', 422, [
            'supervisor_id' => 'Un usuario no puede asignarse a sí mismo como supervisor.'
        ]);
    }

    public static function cycleDetected(): self
    {
        return new self('Ciclo jerárquico detectado: el supervisor asignado es un subordinado (directo o indirecto) del usuario.', 422, [
            'supervisor_id' => 'No se puede asignar un subordinado como supervisor.'
        ]);
    }

    public static function crossTenantSupervisor(): self
    {
        return new self('El supervisor especificado no pertenece a la misma empresa.', 422, [
            'supervisor_id' => 'El supervisor debe pertenecer al mismo cliente.'
        ]);
    }

    public static function invalidReassignmentTarget(): self
    {
        return new self('El usuario destino para la reasignación de subordinados no es válido.', 422, [
            'reassign_to_user_id' => 'Debe especificar un usuario válido perteneciente a la misma empresa.'
        ]);
    }

    public static function cannotReassignToDeletedUser(): self
    {
        return new self('No se puede reasignar subordinados al mismo usuario que se está eliminando.', 422, [
            'reassign_to_user_id' => 'El usuario seleccionado para recibir subordinados no puede ser la persona a eliminar.'
        ]);
    }
}

