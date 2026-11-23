<?php

namespace Database\Seeders;

use App\Models\Equipment;
use Illuminate\Database\Seeder;
use RuntimeException;

class EquipmentSeeder extends Seeder
{
    /**
     * Crea equipos tecnológicos de ejemplo en el inventario para entornos
     * de desarrollo y pruebas, cubriendo equipos principales (Computadora, Laptop)
     * y dispositivos adicionales (Periférico, Otro) en todos los estados operativos.
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException(
                'EquipmentSeeder solo puede ejecutarse en local o testing.'
            );
        }

        /** @var list<array{patrimonial_code: string, type: string, brand: string, model: string, serial_number: string, status: string, observations: ?string}> $items */
        $items = [
            // Equipos principales: Computadoras
            [
                'patrimonial_code' => 'UCH-EQ-00001',
                'type' => 'Computadora',
                'brand' => 'Dell',
                'model' => 'OptiPlex 7090',
                'serial_number' => 'SN-UCH-PC-0001',
                'status' => 'Disponible',
                'observations' => 'Estación principal de consulta en sala de lectura.',
            ],
            [
                'patrimonial_code' => 'UCH-EQ-00002',
                'type' => 'Computadora',
                'brand' => 'HP',
                'model' => 'EliteDesk 800 G6',
                'serial_number' => 'SN-UCH-PC-0002',
                'status' => 'Prestado',
                'observations' => 'Asignada en préstamo activo en sala.',
            ],
            [
                'patrimonial_code' => 'UCH-EQ-00011',
                'type' => 'Computadora',
                'brand' => 'Lenovo',
                'model' => 'ThinkCentre M70q',
                'serial_number' => 'SN-UCH-PC-0011',
                'status' => 'Prestado',
                'observations' => 'Computadora con préstamo vencido pendiente de devolución.',
            ],

            // Equipos principales: Laptops
            [
                'patrimonial_code' => 'UCH-EQ-00003',
                'type' => 'Laptop',
                'brand' => 'Lenovo',
                'model' => 'ThinkPad T14 Gen 3',
                'serial_number' => 'SN-UCH-LP-0003',
                'status' => 'Prestado',
                'observations' => 'Incluye cargador original de 65W y maletín.',
            ],
            [
                'patrimonial_code' => 'UCH-EQ-00004',
                'type' => 'Laptop',
                'brand' => 'HP',
                'model' => 'ProBook 450 G9',
                'serial_number' => 'SN-UCH-LP-0004',
                'status' => 'Mantenimiento',
                'observations' => 'Derivada a mantenimiento por bisagra dañada reportada en devolución.',
            ],
            [
                'patrimonial_code' => 'UCH-EQ-00012',
                'type' => 'Laptop',
                'brand' => 'Dell',
                'model' => 'Latitude 5430',
                'serial_number' => 'SN-UCH-LP-0012',
                'status' => 'Disponible',
                'observations' => 'Disponible para préstamo en biblioteca.',
            ],

            // Dispositivos adicionales: Periféricos
            [
                'patrimonial_code' => 'UCH-EQ-00005',
                'type' => 'Periférico',
                'brand' => 'Logitech',
                'model' => 'M185 Wireless',
                'serial_number' => 'SN-UCH-PF-0005',
                'status' => 'Prestado',
                'observations' => 'Mouse inalámbrico con receptor USB.',
            ],
            [
                'patrimonial_code' => 'UCH-EQ-00006',
                'type' => 'Periférico',
                'brand' => 'Epson',
                'model' => 'Perfection V39',
                'serial_number' => 'SN-UCH-PF-0006',
                'status' => 'Baja',
                'observations' => 'Dado de baja por falla irreparable en el sensor óptico.',
            ],
            [
                'patrimonial_code' => 'UCH-EQ-00009',
                'type' => 'Periférico',
                'brand' => 'Logitech',
                'model' => 'K120 Teclado USB',
                'serial_number' => 'SN-UCH-PF-0009',
                'status' => 'Prestado',
                'observations' => 'Teclado alámbrico en préstamo de solo periféricos.',
            ],
            [
                'patrimonial_code' => 'UCH-EQ-00013',
                'type' => 'Periférico',
                'brand' => 'HyperX',
                'model' => 'Cloud Stinger Core',
                'serial_number' => 'SN-UCH-PF-0013',
                'status' => 'Disponible',
                'observations' => 'Audífonos con micrófono para sesiones multimedia.',
            ],

            // Dispositivos adicionales: Otros
            [
                'patrimonial_code' => 'UCH-EQ-00007',
                'type' => 'Otro',
                'brand' => 'Epson',
                'model' => 'PowerLite E20',
                'serial_number' => 'SN-UCH-OT-0007',
                'status' => 'Disponible',
                'observations' => 'Proyector multimedia para sala de capacitaciones.',
            ],
            [
                'patrimonial_code' => 'UCH-EQ-00008',
                'type' => 'Otro',
                'brand' => 'APC',
                'model' => 'Back-UPS 700VA',
                'serial_number' => 'SN-UCH-OT-0008',
                'status' => 'Disponible',
                'observations' => null,
            ],
            [
                'patrimonial_code' => 'UCH-EQ-00010',
                'type' => 'Otro',
                'brand' => 'UGREEN',
                'model' => 'Adaptador USB-C a HDMI',
                'serial_number' => 'SN-UCH-OT-0010',
                'status' => 'Prestado',
                'observations' => 'Adaptador en préstamo de solo periféricos/otros.',
            ],
            [
                'patrimonial_code' => 'UCH-EQ-00014',
                'type' => 'Otro',
                'brand' => 'Anker',
                'model' => 'Cargador USB-C 65W',
                'serial_number' => 'SN-UCH-OT-0014',
                'status' => 'Disponible',
                'observations' => 'Cargador rápido compatible con laptops institucionales.',
            ],
        ];

        foreach ($items as $data) {
            $alreadyExists = Equipment::query()
                ->where('patrimonial_code', $data['patrimonial_code'])
                ->orWhere('serial_number', $data['serial_number'])
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            Equipment::query()->create($data);
        }
    }
}
