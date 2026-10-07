<?php

declare(strict_types=1);

/**
 * Datos de prueba: dos empresas (tenants) con jerarquías de 3 niveles.
 *
 *   Nivel 1: cabeza de la empresa (user_id = NULL)
 *   Nivel 2: gerencias / direcciones
 *   Nivel 3: equipos operativos
 *
 * Contraseña por defecto de todos los usuarios: ver SEED_DEFAULT_PASSWORD (.env.example).
 */

return [
    [
        'client' => [
            'name'        => 'Acme Tecnología S.A.',
            'description' => 'Empresa de desarrollo de software y servicios cloud.',
        ],
        'head' => [
            'name' => 'Laura', 'last_name' => 'Fernández', 'email' => 'laura.fernandez@acme.test',
            'identification_number' => 27111222, 'identification_code' => 1001,
            'subordinates' => [
                [
                    'name' => 'Martín', 'last_name' => 'Gómez', 'email' => 'martin.gomez@acme.test',
                    'identification_number' => 28222333, 'identification_code' => 1002,
                    'subordinates' => [
                        ['name' => 'Sofía', 'last_name' => 'Ruiz', 'email' => 'sofia.ruiz@acme.test',
                         'identification_number' => 33444555, 'identification_code' => 1005],
                        ['name' => 'Diego', 'last_name' => 'Pérez', 'email' => 'diego.perez@acme.test',
                         'identification_number' => 34555666, 'identification_code' => 1006],
                        ['name' => 'Julieta', 'last_name' => 'Acosta', 'email' => 'julieta.acosta@acme.test',
                         'identification_number' => 38999000, 'identification_code' => 1010],
                    ],
                ],
                [
                    'name' => 'Valentina', 'last_name' => 'López', 'email' => 'valentina.lopez@acme.test',
                    'identification_number' => 29333444, 'identification_code' => 1003,
                    'subordinates' => [
                        ['name' => 'Juan', 'last_name' => 'Martínez', 'email' => 'juan.martinez@acme.test',
                         'identification_number' => 35666777, 'identification_code' => 1007],
                    ],
                ],
                [
                    'name' => 'Carlos', 'last_name' => 'Díaz', 'email' => 'carlos.diaz@acme.test',
                    'identification_number' => 30444555, 'identification_code' => 1004,
                    'subordinates' => [
                        ['name' => 'Lucía', 'last_name' => 'Romero', 'email' => 'lucia.romero@acme.test',
                         'identification_number' => 36777888, 'identification_code' => 1008],
                        ['name' => 'Pablo', 'last_name' => 'Sánchez', 'email' => 'pablo.sanchez@acme.test',
                         'identification_number' => 37888999, 'identification_code' => 1009,
                         'block_login' => true],
                    ],
                ],
            ],
        ],
    ],
    [
        'client' => [
            'name'        => 'Globex Logística S.R.L.',
            'description' => 'Operador logístico de almacenamiento y distribución.',
        ],
        'head' => [
            'name' => 'Roberto', 'last_name' => 'Álvarez', 'email' => 'roberto.alvarez@globex.test',
            'identification_number' => 20123456, 'identification_code' => 2001,
            'subordinates' => [
                [
                    'name' => 'Ana', 'last_name' => 'Torres', 'email' => 'ana.torres@globex.test',
                    'identification_number' => 22234567, 'identification_code' => 2002,
                    'subordinates' => [
                        ['name' => 'Federico', 'last_name' => 'Castro', 'email' => 'federico.castro@globex.test',
                         'identification_number' => 31567890, 'identification_code' => 2005],
                        ['name' => 'Camila', 'last_name' => 'Herrera', 'email' => 'camila.herrera@globex.test',
                         'identification_number' => 32678901, 'identification_code' => 2006],
                    ],
                ],
                [
                    'name' => 'Jorge', 'last_name' => 'Molina', 'email' => 'jorge.molina@globex.test',
                    'identification_number' => 23345678, 'identification_code' => 2003,
                    'subordinates' => [
                        ['name' => 'Paula', 'last_name' => 'Ortiz', 'email' => 'paula.ortiz@globex.test',
                         'identification_number' => 33789012, 'identification_code' => 2007],
                    ],
                ],
                [
                    'name' => 'Mariana', 'last_name' => 'Silva', 'email' => 'mariana.silva@globex.test',
                    'identification_number' => 24456789, 'identification_code' => 2004,
                    'subordinates' => [
                        ['name' => 'Tomás', 'last_name' => 'Medina', 'email' => 'tomas.medina@globex.test',
                         'identification_number' => 34890123, 'identification_code' => 2008],
                        ['name' => 'Agustina', 'last_name' => 'Rojas', 'email' => 'agustina.rojas@globex.test',
                         'identification_number' => 35901234, 'identification_code' => 2009],
                    ],
                ],
            ],
        ],
    ],
];

