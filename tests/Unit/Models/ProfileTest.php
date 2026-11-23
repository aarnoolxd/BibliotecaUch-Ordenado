<?php

namespace Tests\Unit\Models;

use App\Models\Profile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProfileTest extends TestCase
{
    /**
     * @return array<string, array{firstName: ?string, lastName: ?string, maternalLastName: ?string, expected: string}>
     */
    public static function fullNameCasesProvider(): array
    {
        return [
            'nombres y ambos apellidos completos' => [
                'firstName' => 'Juan Alberto',
                'lastName' => 'Sedano',
                'maternalLastName' => 'Quispe',
                'expected' => 'Juan Alberto Sedano Quispe',
            ],
            'nombres y apellido paterno sin materno' => [
                'firstName' => 'Juan Alberto',
                'lastName' => 'Sedano',
                'maternalLastName' => null,
                'expected' => 'Juan Alberto Sedano',
            ],
            'nombres y apellido materno sin paterno' => [
                'firstName' => 'Juan Alberto',
                'lastName' => null,
                'maternalLastName' => 'Quispe',
                'expected' => 'Juan Alberto Quispe',
            ],
            'solo nombres' => [
                'firstName' => 'Juan Alberto',
                'lastName' => null,
                'maternalLastName' => null,
                'expected' => 'Juan Alberto',
            ],
            'solo apellidos' => [
                'firstName' => null,
                'lastName' => 'Sedano',
                'maternalLastName' => 'Quispe',
                'expected' => 'Sedano Quispe',
            ],
            'todos los valores nulos' => [
                'firstName' => null,
                'lastName' => null,
                'maternalLastName' => null,
                'expected' => '',
            ],
            'espacios en los extremos y segmentos vacios' => [
                'firstName' => '  Ana',
                'lastName' => '   ',
                'maternalLastName' => 'Torres  ',
                'expected' => 'Ana Torres',
            ],
        ];
    }

    /**
     * El accesor full_name consolida nombres, apellido paterno y apellido materno eliminando
     * espacios sobrantes o segmentos vacíos sin generar dobles espacios.
     */
    #[DataProvider('fullNameCasesProvider')]
    public function test_full_name_combines_and_trims_name_parts(
        ?string $firstName,
        ?string $lastName,
        ?string $maternalLastName,
        string $expected,
    ): void {
        $profile = new Profile([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'maternal_last_name' => $maternalLastName,
        ]);

        $this->assertSame($expected, $profile->full_name);
    }
}
