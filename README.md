# Biblioteca UCH

Guía de trabajo para el equipo de desarrollo del sistema de biblioteca. El entorno local se ejecuta con Laravel Sail y
PostgreSQL.

## Estado actual

La base del proyecto incluye:

- Laravel con un panel administrativo de Filament en `/admin`.
- Spatie Laravel Permission con modelos propios `User`, `Role` y `Permission`.
- Cuatro roles iniciales y acceso al panel condicionado a su asignación.
- Usuarios de demostración limitados a los entornos `local` y `testing`.
- Laravel Boost y configuración de CodeRabbit como herramientas de desarrollo.

Las pruebas de integración en [tests/Feature/Auth/](tests/Feature/Auth/) cubren el inicio y el cierre de sesión,
el rechazo de credenciales inválidas y el bloqueo temporal tras varios intentos fallidos. También verifican que cada
uno de los cuatro roles permitidos puede entrar al panel y que el usuario pierde el acceso al retirarle su último rol
autorizado.

El desarrollo del login y de los módulos funcionales continuará en siguientes entregas.

## Tecnologías

| Componente                | Versión o configuración                 |
|---------------------------|-----------------------------------------|
| PHP                       | 8.3 en la imagen de Sail                |
| Laravel                   | 13.x                                    |
| Filament                  | 5.x                                     |
| Spatie Laravel Permission | 8.x                                     |
| PostgreSQL                | 18, imagen `postgres:18-alpine`         |
| Node.js                   | 24 en la imagen de Sail                 |
| Vite y Tailwind CSS       | 8.x y 4.x                               |
| Herramientas              | Composer, npm, Pint, Boost y CodeRabbit |

Las versiones exactas de las dependencias están registradas en `composer.lock` y `package-lock.json`.

## Requisitos

- Git y acceso al repositorio.
- Docker en ejecución y Docker Compose v2.
- Linux, macOS o Windows mediante WSL2. En Windows, ejecuta los comandos desde la terminal de WSL2.
- Puertos disponibles para la aplicación, Vite y PostgreSQL.

PHP, Composer y Node.js se ejecutan dentro de los contenedores. Usa `vendor/bin/sail` para los comandos del proyecto.

## Primera instalación

### 1. Clonar el repositorio

```bash
git clone https://github.com/aarnoolxd/BibliotecaUch-Ordenado.git
cd BibliotecaUch-Ordenado
```

Selecciona la rama de trabajo acordada con el equipo antes de instalar las dependencias. La disponibilidad de las
funcionalidades depende de la rama.

### 2. Configurar el entorno local

Solo en una instalación nueva, copia la plantilla:

```bash
cp .env.example .env
```

La plantilla configura PostgreSQL para Sail:

```dotenv
APP_ENV=local
APP_URL=http://localhost:8000
APP_PORT=8000

DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432
DB_DATABASE=biblioteca_uch
DB_USERNAME=sail
DB_PASSWORD=password
FORWARD_DB_PORT=5432
```

Estas credenciales son ejemplos exclusivos del entorno local. Mantén `.env` fuera del repositorio y utiliza credenciales
propias en los entornos compartidos.

| Conexión                                 | Host        | Puerto                                  |
|------------------------------------------|-------------|-----------------------------------------|
| Laravel hacia PostgreSQL dentro de Sail  | `pgsql`     | `5432`                                  |
| Cliente de base de datos desde tu equipo | `127.0.0.1` | `5432`, o el valor de `FORWARD_DB_PORT` |

`DB_PORT` es el puerto interno: mantenlo en `5432` aunque cambies `FORWARD_DB_PORT`. El nombre de base, usuario y
contraseña del cliente externo deben coincidir con tu `.env`.

Si `8000` ya está ocupado, cambia `APP_PORT` y `APP_URL` al mismo puerto libre. Para cambiar el puerto de Vite, agrega
`VITE_PORT`, por ejemplo `VITE_PORT=5174`.

### 3. Instalar las dependencias iniciales

En un clon nuevo todavía no existe `vendor/bin/sail`. Usa el contenedor de Composer para generar `vendor/`:

```bash
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php83-composer:latest \
    composer install --ignore-platform-reqs --no-scripts --no-interaction
```

Este paso inicial omite la comprobación de extensiones y los scripts dentro del contenedor auxiliar. La instalación
normal del siguiente paso los ejecuta dentro de la imagen completa del proyecto.

### 4. Construir e iniciar Sail

```bash
vendor/bin/sail up -d --build --wait
vendor/bin/sail composer install --no-interaction
vendor/bin/sail composer check-platform-reqs
vendor/bin/sail artisan key:generate --no-interaction
vendor/bin/sail artisan migrate --seed --no-interaction
vendor/bin/sail npm ci
vendor/bin/sail npm run build
```

La primera construcción descarga PHP, Node.js y las herramientas de la imagen, por lo que puede tardar varios minutos.
PostgreSQL debe superar su comprobación de salud antes de iniciar la aplicación.

`key:generate` corresponde a la primera instalación. Conserva la clave existente al actualizar un entorno que ya tiene
datos.

### 5. Entrar al panel

Abre la dirección de `APP_URL` seguida de `/admin`. La ruta raíz `/` aún no tiene una página implementada.

Con `APP_ENV=local`, el seeder crea las siguientes cuentas si no existen:

| Correo                    | Rol           | Contraseña inicial |
|---------------------------|---------------|--------------------|
| `admin@example.com`       | `admin`       | `password`         |
| `superadmin@example.com`  | `super-admin` | `password`         |
| `coordinador@example.com` | `coordinador` | `password`         |
| `ayudante@example.com`    | `ayudante`    | `password`         |

Repetir el seeder no restablece las contraseñas de las cuentas existentes. Las cuentas de demostración no deben
utilizarse en staging ni en producción.

## Roles y acceso al panel

`DatabaseSeeder` ejecuta primero `RoleSeeder`, que crea de forma repetible los roles `admin`, `super-admin`,
`coordinador` y `ayudante` con el guard `web`.

Después ejecuta `DevUsersSeeder` únicamente en `local` o `testing`. La ejecución directa de este último se rechaza en
los demás entornos, incluso si se utiliza `--force`.

`User::canAccessPanel()` exige al menos uno de los cuatro roles. Un usuario autenticado sin alguno de ellos recibe 403
al acceder a una ruta protegida del panel. No se debe habilitar el acceso general a usuarios sin roles para resolver
este bloqueo.

Los nombres de los roles no definen una jerarquía automática. En esta base, los cuatro permiten entrar al panel; los
permisos de las acciones y recursos deben definirse según se implementen los módulos.

### Auditoría de roles y permisos (pendiente)

Los eventos de roles y permisos están habilitados en `config/permission.php`. Queda pendiente escucharlos para
guardar un historial que indique quién asignó o retiró cada rol o permiso, a qué usuario o rol afectó el cambio
y en qué fecha y hora ocurrió.

### Crear un usuario propio y asignarle un rol

Si las migraciones ya se ejecutaron, asegura primero la existencia de los roles:

```bash
vendor/bin/sail artisan db:seed --class=RoleSeeder --no-interaction
vendor/bin/sail artisan make:filament-user --panel=admin
```

El segundo comando solicita nombre, correo y una contraseña propia. Filament crea el usuario, pero no le asigna un rol.

Consulta su ID, reemplazando el correo de ejemplo por el registrado:

```bash
vendor/bin/sail artisan tinker --execute='dump(App\Models\User::where("email", "tu-correo@institucion.edu")->firstOrFail()->id);'
```

Después sustituye `ID_DEL_USUARIO` por el ID obtenido:

```bash
vendor/bin/sail artisan permission:assign-role admin ID_DEL_USUARIO web --no-interaction
```

Para otro perfil, utiliza uno de los otros tres roles permitidos. La asignación de roles privilegiados debe hacerla una
persona autorizada del equipo.

En staging y producción, utiliza credenciales propias y ejecuta únicamente el seeder de roles; no ejecutes
`DevUsersSeeder`. Si esos entornos solicitan confirmación para ejecutar el seeder, utiliza `--force` tras comprobar la
conexión de destino. Esta guía describe el entorno de desarrollo con Sail, no un procedimiento de despliegue a
producción.

## Trabajo diario

Inicia los servicios:

```bash
vendor/bin/sail up -d --wait
```

Para trabajar en los recursos frontend, deja Vite ejecutándose en otra terminal:

```bash
vendor/bin/sail npm run dev
```

El servidor PHP ya se ejecuta dentro de Sail. No hace falta iniciar otro `artisan serve` en tu equipo.

| Tarea                               | Comando                                            |
|-------------------------------------|----------------------------------------------------|
| Consultar servicios                 | `vendor/bin/sail ps`                               |
| Ver logs de la aplicación           | `vendor/bin/sail logs -f laravel.test`             |
| Ver logs de PostgreSQL              | `vendor/bin/sail logs -f pgsql`                    |
| Abrir consola PostgreSQL            | `vendor/bin/sail psql`                             |
| Ejecutar migraciones pendientes     | `vendor/bin/sail artisan migrate --no-interaction` |
| Ver estado de las migraciones       | `vendor/bin/sail artisan migrate:status`           |
| Limpiar configuración en caché      | `vendor/bin/sail artisan config:clear`             |
| Limpiar caché de permisos           | `vendor/bin/sail artisan permission:cache-reset`   |
| Formatear PHP modificado            | `vendor/bin/sail bin pint --dirty --format agent`  |
| Compilar recursos frontend          | `vendor/bin/sail npm run build`                    |
| Detener servicios conservando datos | `vendor/bin/sail stop`                             |

### Al cambiar de rama o actualizar dependencias

Después de seleccionar una rama y con Sail iniciado, instala las versiones registradas en sus archivos de bloqueo:

```bash
vendor/bin/sail composer install --no-interaction
vendor/bin/sail npm ci
vendor/bin/sail artisan config:clear
vendor/bin/sail artisan migrate --no-interaction
```

Si cambia `docker/8.3/Dockerfile`, reconstruye la imagen con `vendor/bin/sail up -d --build --wait`. Revisa las
migraciones de cada rama antes de aplicarlas sobre una base compartida. Utiliza `composer update` o cambios de
dependencias únicamente cuando formen parte del trabajo acordado.

## PostgreSQL y persistencia

El servicio `pgsql` conserva los datos en el volumen Docker `sail-pgsql`, cuyo nombre real lleva el prefijo del proyecto
Compose. Al inicializar un volumen vacío se crean la base indicada en `DB_DATABASE` y una base auxiliar llamada
`testing`.

Modificar `DB_DATABASE`, `DB_USERNAME` o `DB_PASSWORD` en `.env` no modifica automáticamente las bases ni los usuarios
de un volumen ya inicializado. Esos cambios requieren actualizar PostgreSQL o preparar una nueva base de forma
coordinada.

La transición de MySQL a PostgreSQL cambia el servicio y crea un volumen independiente. Los datos previos de MySQL u
otras instancias de PostgreSQL no se importan automáticamente. No borres sus volúmenes si necesitas conservarlos.

Evita `down -v` y `migrate:fresh` sobre bases que deban conservar información: eliminan volúmenes o tablas,
respectivamente. Para cerrar el entorno habitual, usa `vendor/bin/sail stop`.

## Herramientas de desarrollo

- **Laravel Boost** está incluido en las dependencias de desarrollo. Su configuración se encuentra en `boost.json`,
  `.agents/` y las carpetas de skills. El servidor MCP `laravel-boost` se configura en
  [.ai/mcp/mcp.json](.ai/mcp/mcp.json) y se ejecuta con `vendor/bin/sail artisan boost:mcp`, por lo que Sail debe
  estar iniciado.
- **Agentes de código:** consulta [AGENTS.md](AGENTS.md) antes de modificar el proyecto con un asistente.
- **IDE Helper:** proporciona metadatos para el editor; los archivos `_ide_helper*` no forman parte de la lógica de
  negocio.
- **CodeRabbit:** [.coderabbit.yaml](.coderabbit.yaml) configura revisiones en español para PR dirigidos a `developer` y
  `main`. Los filtros excluyen de revisión los archivos auxiliares de agentes, Boost y metadatos del IDE.

## Referencia de carpetas

| Ruta                      | Propósito                                                  |
|---------------------------|------------------------------------------------------------|
| `app/Models/`             | Modelos de usuarios, roles y permisos                      |
| `app/Providers/Filament/` | Configuración del panel administrativo                     |
| `config/permission.php`   | Configuración de Spatie y modelos utilizados               |
| `database/migrations/`    | Cambios del esquema de base de datos                       |
| `database/seeders/`       | Roles iniciales y usuarios de desarrollo                   |
| `docker/8.3/`             | Imagen PHP y configuración del contenedor de la aplicación |
| `docker/pgsql/`           | Inicialización auxiliar de PostgreSQL                      |
| `resources/`              | Recursos frontend                                          |
| `routes/`                 | Rutas web y comandos de consola                            |
| `tests/Feature/Auth/`     | Pruebas de autenticación y acceso al panel según el rol    |

## Problemas frecuentes

| Síntoma                                           | Qué revisar                                                                                                         |
|---------------------------------------------------|---------------------------------------------------------------------------------------------------------------------|
| `vendor/bin/sail` no existe                       | Ejecutar la instalación inicial de Composer con Docker                                                              |
| `Sail is not running`                             | Iniciar Docker y ejecutar `vendor/bin/sail up -d --wait`                                                            |
| Puerto ocupado                                    | Ajustar `APP_PORT` y `APP_URL`, `FORWARD_DB_PORT` o `VITE_PORT`, según el servicio                                  |
| `could not find driver` para PostgreSQL           | Reconstruir la imagen; debe incluir `php8.3-pgsql`                                                                  |
| Error de conexión a PostgreSQL                    | Dentro de Sail usar `DB_HOST=pgsql`, `DB_PORT=5432` y revisar los logs del servicio                                 |
| Cambios de `.env` sin efecto                      | Ejecutar `vendor/bin/sail artisan config:clear`; recrear los contenedores si cambian puertos o variables de Compose |
| 403 al entrar al panel                            | Comprobar que el usuario tenga uno de los cuatro roles con guard `web`                                              |
| Error de caché o sesiones por tablas inexistentes | Ejecutar las migraciones antes de iniciar sesión                                                                    |
| Error de manifiesto Vite o recursos ausentes      | Ejecutar `vendor/bin/sail npm ci` y `vendor/bin/sail npm run build`, o iniciar Vite durante el desarrollo           |

## Documentación de referencia

- [Laravel 13 y Sail](https://laravel.com/docs/13.x/sail).
- [Filament 5: usuarios y acceso al panel](https://filamentphp.com/docs/5.x/users/overview).
- [Spatie Laravel Permission 8: roles y permisos](https://spatie.be/docs/laravel-permission/v8/basic-usage/role-permissions).
- [PostgreSQL: imagen oficial de Docker](https://hub.docker.com/_/postgres).
- [CodeRabbit: filtros de archivos](https://docs.coderabbit.ai/pr-reviews/which-files).
