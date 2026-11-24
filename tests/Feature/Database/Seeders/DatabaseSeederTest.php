<?php

namespace Tests\Feature\Database\Seeders;

use App\Models\Borrowing;
use App\Models\Equipment;
use App\Models\EquipmentIncident;
use App\Models\Profile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * DatabaseSeeder siembra perfiles actualizados, inventario, préstamos en todos los
     * flujos vigentes (incluyendo N:N, solo periféricos, vencidos e incidencias) y es idempotente.
     */
    public function test_database_seeder_populates_current_domain_scenarios_idempotently(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(count(Role::PANEL_ROLES), Role::query()->count());
        $this->assertSame(4, User::query()->count());
        $this->assertSame(8, Profile::query()->count());
        $this->assertSame(14, Equipment::query()->count());
        $this->assertSame(6, Borrowing::query()->count());
        $this->assertSame(4, DB::table('borrowing_equipment')->count());
        $this->assertSame(1, EquipmentIncident::query()->count());

        // Verifica perfiles con apellido materno, tipo de usuario y datos académicos solo para estudiantes
        $studentProfile = Profile::query()->where('dni', '70000005')->firstOrFail();
        $this->assertSame('Salazar', $studentProfile->last_name);
        $this->assertSame('Huaman', $studentProfile->maternal_last_name);
        $this->assertSame(Profile::USER_TYPE_STUDENT, $studentProfile->user_type);
        $this->assertNotNull($studentProfile->program);
        $this->assertNotNull($studentProfile->cycle);

        $teacherProfile = Profile::query()->where('dni', '70000008')->firstOrFail();
        $this->assertSame(Profile::USER_TYPE_TEACHER, $teacherProfile->user_type);
        $this->assertNull($teacherProfile->program);
        $this->assertNull($teacherProfile->cycle);

        // Verifica préstamo de solo periféricos (sin equipo principal ni due_date)
        $peripheralsOnly = Borrowing::query()
            ->with('additionalEquipment')
            ->whereNull('equipment_id')
            ->firstOrFail();
        $this->assertNull($peripheralsOnly->due_date);
        $this->assertCount(2, $peripheralsOnly->additionalEquipment);

        // Verifica que solo las computadoras sembradas tienen due_date (y +2h)
        $laptopBorrowing = Borrowing::query()
            ->whereHas('equipment', fn ($query) => $query->where('type', 'Laptop'))
            ->where('status', Borrowing::STATUS_IN_PROGRESS)
            ->firstOrFail();
        $this->assertNull($laptopBorrowing->due_date);

        $computerBorrowing = Borrowing::query()
            ->with('additionalEquipment')
            ->whereHas('equipment', fn ($query) => $query->where('patrimonial_code', 'UCH-EQ-00002'))
            ->firstOrFail();
        $this->assertSame(
            $computerBorrowing->borrowing_date->copy()->addHours(2)->format('Y-m-d H:i:s'),
            $computerBorrowing->due_date->format('Y-m-d H:i:s'),
        );
        $this->assertCount(1, $computerBorrowing->additionalEquipment);

        // Verifica idempotencia al ejecutar nuevamente el seeder
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(count(Role::PANEL_ROLES), Role::query()->count());
        $this->assertSame(4, User::query()->count());
        $this->assertSame(8, Profile::query()->count());
        $this->assertSame(14, Equipment::query()->count());
        $this->assertSame(6, Borrowing::query()->count());
        $this->assertSame(4, DB::table('borrowing_equipment')->count());
        $this->assertSame(1, EquipmentIncident::query()->count());

        // Verifica idempotencia incluso después de que un préstamo En curso pase a Vencido
        $computerBorrowing->update(['status' => Borrowing::STATUS_OVERDUE]);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(6, Borrowing::query()->count());
        $this->assertSame(4, DB::table('borrowing_equipment')->count());
    }
}
