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

3. Crear una base de datos MariaDB vacia y completar en `.env` `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD`. Definir tambien `ADMIN_USERNAME` y `ADMIN_PASSWORD` fuera del repositorio.

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

## Instalacion inicial en servidor dedicado

Esta seccion aplica solo a una base de datos nueva y vacia. Para una instalacion que ya tiene datos, sigue «Actualizar una instalacion existente»; no vuelvas a generar su clave ni ejecutes seeders.

El document root del servidor web debe apuntar a `public/`; nunca debe exponerse la raiz del repositorio. El proceso PHP-FPM debe tener permisos de lectura sobre el proyecto y escritura sobre `storage/` y `bootstrap/cache/`.

```bash
cd /var/www/sistema-pasteleria
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
cp .env.example .env
# Solo en esta instalacion nueva; conserva esta APP_KEY en despliegues posteriores.
php artisan key:generate --force
# Configura primero .env con APP_ENV=production, APP_DEBUG=false y credenciales validas.
php artisan migrate --seed --force
npm run build
php artisan storage:link
php artisan config:cache
php artisan view:cache
chmod -R ug+rwX storage bootstrap/cache
```

En el `.env` de produccion se deben definir `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` con HTTPS, las credenciales de MariaDB, `ADMIN_USERNAME`, `ADMIN_PASSWORD` y las variables del canal de recordatorios. `ADMIN_USERNAME` debe ser unico y la clave de administracion solo se necesita para crear la cuenta inicial; no se vuelve a sembrar al actualizar. Usa `SESSION_SECURE_COOKIE=true` cuando el servicio se publique por HTTPS; si TLS termina en un proxy, configura tambien correctamente los proxies de confianza. Las credenciales no se escriben en el repositorio ni en la base de datos.

## Actualizar una instalacion existente

Realiza la actualizacion en una ventana controlada y conserva `.env`, `storage/`, `APP_KEY`, credenciales, catalogos y la configuracion/destinatario de Telegram. No uses `migrate:fresh`, `migrate:refresh`, `migrate:rollback`, `key:generate`, `migrate --seed` ni seeders masivos en una base operativa.

1. Antes de cambiar codigo, confirma que `.env` apunta a la base correcta, `APP_ENV=production`, `APP_DEBUG=false` y `ADMIN_USERNAME` coincide exactamente con la cuenta que debe ser Dueña. Verifica que el respaldo tenga espacio suficiente y este fuera de `public/`.
2. Crea el respaldo y verifica su restauracion en una base temporal aislada; no avances si la copia no restaura o no conserva tablas y relaciones.

   ```bash
   php artisan app:backup-database
   ```

3. Activa mantenimiento, publica una nueva version sin reemplazar `.env` ni `storage/`, instala exactamente las dependencias del lock y compila los recursos locales.

   ```bash
   php artisan down --retry=60
   composer install --no-dev --prefer-dist --optimize-autoloader
   npm ci
   npm run build
   ```

4. Aplica exclusivamente las migraciones pendientes. La migracion de rol reconoce a la Dueña por `ADMIN_USERNAME`; no ejecuta el seeder administrativo ni crea o restablece contrasenas.

   ```bash
   php artisan migrate --force
   php artisan migrate:status
   ```

5. Simula la reparacion historica y revisa los pedidos candidatos. El comando no cambia nada sin `--apply`; aplica solo despues de confirmar el listado. Los pedidos con pagos activos se omiten y requieren resolucion financiera de la Dueña.

   ```bash
   php artisan orders:repair-archived-pending
   # Solo si la simulacion lista candidatos revisados:
   php artisan orders:repair-archived-pending --apply
   ```

6. Reconstruye las caches de despliegue, comprueba las tareas programadas y abre el servicio. No limpies la cache de datos compartida como sustituto de estas caches.

   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan schedule:list
   php artisan up
   ```

7. Comprueba el acceso como Dueña y Asistente, el flujo de pedido/cliente/pago/historial, que los mensajes sigan usando la misma configuracion y destinatario, y que el cron existente ejecute `schedule:run` cada minuto. No agregues una segunda entrada de cron.

Si falla la publicacion, vuelve al release anterior de codigo compatible con el esquema aditivo (la columna `users.role` puede permanecer). No reviertas la migracion ni los estados ya corregidos: los pedidos archivados regularizados deben conservar su estado y auditoria. Restaura una base desde el respaldo solo bajo una ventana controlada, despues de evaluar y proteger las operaciones registradas desde que se creo.

Antes de abrir la aplicacion a usuarios, revisa los resultados actuales de `composer audit --locked` y `npm audit --audit-level=high --omit=dev`. Esta guia no autoriza cambios de dependencias; resuelve cualquier aviso en una actualizacion aprobada y probada por separado.

El canal de recordatorios usa Telegram inicialmente; completar las credenciales del servidor antes de ejecutar el scheduler:

```dotenv
NOTIFICATIONS_ENABLED=true
NOTIFICATION_CHANNEL=telegram
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=
TELEGRAM_CONNECT_TIMEOUT=5
TELEGRAM_TIMEOUT=10
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
- Para una notificacion fallida, revisar el historial del pedido y `storage/logs/laravel.log`, y corregir las variables del proveedor. Las respuestas `429` se reintentan automaticamente con backoff; las fallas de conexion o del servidor no se reenvian automaticamente porque los proveedores no ofrecen una garantia de idempotencia para estos mensajes. Despues de verificar que el proveedor no acepto el mensaje, ejecutar `php artisan orders:send-reminders --retry-failed`. Los fallos quedan auditados y no confirman una notificacion hasta recibir una respuesta exitosa.

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
