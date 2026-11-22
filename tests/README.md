# Pruebas de Biblioteca UCH

Guía rápida de ejecución y convenciones para la suite de pruebas con PHPUnit y Laravel Sail.

## Cómo ejecutar las pruebas

Con Sail iniciado (`vendor/bin/sail up -d --wait`):

| Objetivo                           | Comando                                                                                    |
|------------------------------------|--------------------------------------------------------------------------------------------|
| Ejecutar toda la suite             | `vendor/bin/sail artisan test --compact`                                                   |
| Ejecutar un archivo específico     | `vendor/bin/sail artisan test tests/Feature/Filament/Resources/Roles/RoleResourceTest.php` |
| Filtrar por nombre de prueba       | `vendor/bin/sail artisan test --filter=test_creating_a_role`                               |
| Formatear código antes de terminar | `vendor/bin/sail bin pint --dirty --format agent`                                          |

## Entorno de ejecución

La configuración de [phpunit.xml](../phpunit.xml) ejecuta las pruebas de forma aislada respecto a tu PostgreSQL local:

- Base de datos SQLite en memoria (`:memory:`), reiniciada de forma perezosa con el trait `LazilyRefreshDatabase`.
- `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync` y `MAIL_MAILER=array`.
- Los cambios en las pruebas nunca afectan los datos persistidos en el volumen `sail-pgsql`.

## Estructura de carpetas

| Ruta                                        | Qué cubre                                                                             |
|---------------------------------------------|---------------------------------------------------------------------------------------|
| `tests/Feature/Auth/`                       | Inicio/cierre de sesión, throttling de intentos fallidos y acceso al panel según rol  |
| `tests/Feature/Filament/Resources/Permissions/` | CRUD y autorización de `PermissionResource`                                       |
| `tests/Feature/Filament/Resources/Roles/`   | CRUD, autorización, matriz de permisos de Shield y validaciones de `RoleResource`     |
| `tests/Unit/`                               | Pruebas unitarias de lógica pura que no requiere arrancar el framework                |

## Convenciones del equipo

1. **Ubicación y nombres**
   - Espeja la ruta de `app/` y nombra cada archivo `{ClassName}Test.php`.
   - Usa el prefijo `test_` con nombres que describan condición y resultado esperado, acompañados de un docblock breve en español.
   - Crea pruebas nuevas con `vendor/bin/sail artisan make:test --phpunit <Ruta/NombreTest> --no-interaction`.

2. **Pruebas de recursos Filament**
   - Configura el panel activo en `setUp()` con `Filament::setCurrentPanel(Filament::getPanel('admin'))`. No crees registros de base de datos dentro de `setUp()`.
   - Aplica **mínimo privilegio**: autentica un usuario con un rol válido del panel (por ejemplo `ayudante`) y asigna únicamente los permisos necesarios para cada escenario.
   - Usa `Livewire::test(...)` para formularios, acciones de tabla y páginas de Filament, y `$this->get(...)` para validar respuestas HTTP (`200`, `403`, redirecciones).

3. **Datos y validaciones**
   - Crea los datos dentro de cada test usando factories (`User::factory()`, `Role::factory()`) o helpers privados explícitos.
   - Para validaciones repetitivas con la misma estructura, usa `#[DataProvider]` verificando el mensaje visible al usuario, `assertNotNotified()` y que la base de datos no cambie.

4. **Qué probar y qué evitar**
   - Prueba comportamiento observable: flujos CRUD, autorización (caso permitido y denegado), lógica personalizada (como sincronización de permisos) y reglas de validación propias.
   - Evita probar comportamiento interno del framework o la ausencia de botones/acciones que no forman parte del recurso.
