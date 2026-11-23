<?php

namespace Tests\Unit\Models;

use App\Models\Profile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProfileTest extends TestCase
{
    /**
     * @return array<string, array{firstName: ?string, lastName: ?string, expected: string}>
     */
    public static function fullNameCasesProvider(): array
    {
        return [
            'nombres y apellidos completos' => [
                'firstName' => 'Juan Alberto',
                'lastName' => 'Sedano Quispe',
                'expected' => 'Juan Alberto Sedano Quispe',
            ],
            'solo nombres' => [
                'firstName' => 'Juan Alberto',
                'lastName' => null,
                'expected' => 'Juan Alberto',
            ],
            'solo apellidos' => [
                'firstName' => null,
                'lastName' => 'Sedano Quispe',
                'expected' => 'Sedano Quispe',
            ],
            'ambos valores nulos' => [
                'firstName' => null,
                'lastName' => null,
                'expected' => '',
            ],
            'espacios en los extremos' => [
                'firstName' => '  Ana',
                'lastName' => 'Torres  ',
                'expected' => 'Ana Torres',
            ],
        ];
    }

    /**
     * El accesor full_name consolida nombres y apellidos eliminando espacios sobrantes
     * en los extremos, incluso cuando uno o ambos atributos están ausentes.
     */
    #[DataProvider('fullNameCasesProvider')]
    public function test_full_name_combines_and_trims_first_and_last_name(
        ?string $firstName,
        ?string $lastName,
        string $expected,
    ): void {
        $profile = new Profile([
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);

        $this->assertSame($expected, $profile->full_name);
    }
}
