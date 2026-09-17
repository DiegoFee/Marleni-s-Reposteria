# Sistema administrativo de pasteleria

## Proposito

Aplicacion web interna para la administradora de Marleni's Reposteria. Permite gestionar clientes, pedidos estandar o personalizados, anticipos, abonos, saldos, auditoria y recordatorios de preparacion. Todas las rutas administrativas requieren autenticacion; no existe catalogo ni flujo de compra publico.

## Stack tecnologico

- PHP 8.2.12
- Laravel 12.69.2
- MariaDB 10.4 compatible con Eloquent
- Livewire 4.4.5 y Volt 1.11.2
- Flux UI 2.20.0
- Tailwind CSS 4.0.8 y Vite 6.4.3
- Pest 3.8.7
- Composer 2.9.5, Node 24.14.0 y npm 11.9.0

## Requisitos

- PHP 8.2 o superior con `pdo_mysql`, `mbstring`, `openssl`, `fileinfo` y `tokenizer`.
- Composer 2.
- Node.js y npm para compilar los recursos locales.
- MariaDB 10.4 o una version compatible.
- `mariadb-dump` o `mysqldump` para respaldos.
- Cron en el servidor dedicado para ejecutar el scheduler cada minuto.

## Montaje local

1. Instalar dependencias PHP y JavaScript:

   ```bash
   composer install
   npm ci
   ```

2. Crear el archivo de entorno y generar la clave:

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. Crear una base de datos MariaDB vacia y completar en `.env` `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD`. Definir tambien `ADMIN_EMAIL` y `ADMIN_PASSWORD` fuera del repositorio.

4. Ejecutar migraciones y catalogos:

   ```bash
   php artisan migrate --seed
   ```

5. Compilar los recursos locales y levantar la aplicacion:

   ```bash
   npm run build
   php artisan serve
   ```

   La aplicacion queda disponible normalmente en `http://localhost:8000`.

## Montaje en servidor dedicado

El document root del servidor web debe apuntar a `public/`; nunca debe exponerse la raiz del repositorio. El proceso PHP-FPM debe tener permisos de lectura sobre el proyecto y escritura sobre `storage/` y `bootstrap/cache/`.

```bash
cd /var/www/sistema-pasteleria
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
cp .env.example .env
php artisan key:generate --force
php artisan migrate --seed --force
npm run build
php artisan storage:link
php artisan config:cache
php artisan view:cache
chmod -R ug+rwX storage bootstrap/cache
```

En el `.env` de produccion se deben definir `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, las credenciales de MariaDB, `ADMIN_EMAIL`, `ADMIN_PASSWORD` y las variables del canal de recordatorios. Las credenciales no se escriben en el repositorio ni en la base de datos.

El canal de recordatorios permanece desactivado hasta confirmar proveedor y destinatario:

```dotenv
NOTIFICATION_ENABLED=false
NOTIFICATION_CHANNEL=telegram
NOTIFICATION_RECIPIENT=
```

Al activar Telegram o WhatsApp, completar sus variables secretas mediante el gestor de secretos del servidor y luego ejecutar `php artisan config:cache`.

## Scheduler y cron

Laravel ejecuta los recordatorios cada minuto. El respaldo de MariaDB se ejecuta diariamente a las 02:00, hora de `APP_TIMEZONE`, solamente en `production`; ambos comandos tienen proteccion contra ejecuciones simultaneas.

Agregar al crontab del usuario que administra la aplicacion:

```cron
* * * * * cd /var/www/sistema-pasteleria && /usr/bin/php artisan schedule:run >> /var/log/sistema-pasteleria-scheduler.log 2>&1
```

Comprobar las tareas registradas con:

```bash
php artisan schedule:list
```

## Respaldos y restauracion

El comando `app:backup-database` genera un archivo SQL con permisos `0600` en `storage/app/private/backups` por defecto. Ese directorio esta fuera de `public/`. En produccion se recomienda un volumen separado:

```dotenv
BACKUP_PATH=/var/backups/sistema-pasteleria
BACKUP_RETENTION_DAYS=14
BACKUP_DUMP_BINARY=mariadb-dump
```

Crear el directorio con acceso exclusivo para la cuenta de la aplicacion y probar un respaldo manual:

```bash
sudo install -d -o www-data -g www-data -m 700 /var/backups/sistema-pasteleria
php artisan app:backup-database
```

Para validar una restauracion sin tocar la base productiva, crear una base temporal y cargar una copia:

```bash
mariadb -u USUARIO -p -e "CREATE DATABASE sistema_pasteleria_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mariadb -u USUARIO -p sistema_pasteleria_restore < /var/backups/sistema-pasteleria/sistema-pasteleria-AAAAMMDD_HHMMSS.sql
mariadb -u USUARIO -p sistema_pasteleria_restore -e "SHOW TABLES; SELECT COUNT(*) AS pedidos FROM orders; SELECT COUNT(*) AS pagos FROM payments;"
mariadb -u USUARIO -p -e "DROP DATABASE sistema_pasteleria_restore;"
```

No guardar las contrasenas en esos comandos ni en este archivo. La copia contiene datos operativos y debe conservarse fuera del document root con permisos restringidos.

## Demostracion con datos no reales

El seeder de demostracion no forma parte de `DatabaseSeeder` y no debe ejecutarse en una base productiva con datos reales. Requiere que la administradora y los catalogos ya existan:

```bash
php artisan db:seed --class=Database\\Seeders\\DemoDataSeeder
```

Crea un cliente ficticio, un pedido estandar con entrega dentro de 12 horas, un anticipo de `Q 75.00` y un abono de `Q 50.00`. El saldo esperado es `Q 100.00`. Para simular un recordatorio sin red real se usa la prueba `tests/Feature/Operations/PhaseNineDemoTest.php`, que falsifica el cliente HTTP y verifica la auditoria.

## Operacion diaria

- Crear cliente desde `Clientes` o seleccionar uno existente.
- Crear pedido desde `Pedidos > Nuevo pedido`, elegir modalidad estandar o personalizada, confirmar entrega y registrar el anticipo si existe.
- Abrir el detalle del pedido para registrar abonos. El sistema clasifica anticipo, abono o liquidacion y calcula el saldo con pagos registrados.
- Revisar `Panel de control` para entregas proximas y saldos pendientes.
- Marcar el pedido como entregado desde su detalle cuando finalice.
- Para un recordatorio fallido, revisar el historial del pedido y `storage/logs/laravel.log`, corregir las variables del proveedor y ejecutar `php artisan orders:send-reminders` para reintentar. Los fallos quedan auditados y no confirman una notificacion hasta recibir una respuesta exitosa.

## Verificacion de entrega

```bash
php artisan test --compact
npm run build
php artisan migrate:status
php artisan schedule:list
php artisan app:backup-database
vendor/bin/pint --dirty --format agent
composer audit --locked
npm audit --audit-level=high --omit=dev
```

La fase de preparacion termina cuando una instalacion limpia puede migrar y sembrar la base, los recursos locales compilan, el cron ejecuta `schedule:run` cada minuto, el respaldo se crea fuera de `public/` y una copia puede restaurarse en una base segura. La confirmacion del canal productivo, la zona horaria definitiva y la politica de reembolso siguen siendo decisiones de publicacion de la dueña.
