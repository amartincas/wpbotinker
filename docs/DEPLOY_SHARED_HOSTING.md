# BoTinker en hosting compartido: despliegue y E2E de pagos

Guía para desplegar BoTinker en un hosting compartido sin root, sin Docker y sin workers permanentes, y para ejecutar la prueba E2E del flujo comercial (comprobante → alerta → Sí/No del administrador → suscripción).

## 1. Requisitos del servidor

| Requisito | Detalle |
|---|---|
| PHP | **8.4** (lo exige `composer.lock`). En CloudLinux suele ser `/opt/alt/php84/usr/bin/php`, no el `php` del PATH. |
| Extensiones | `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo` (tipo de los comprobantes), `tokenizer`, `xml`, `ctype`. No se usan `bcmath`, `gd` ni binarios de OCR. |
| Base de datos | MySQL 8 o MariaDB. **Solo MariaDB 10.4 está validada**; MySQL 8 no se ha probado. |
| HTTPS | Certificado válido: Meta solo llama a webhooks HTTPS. |
| Cron | Una entrada cada minuto (sección 4). Imprescindible: sin él no se responde ningún mensaje. |
| Salida a Internet | HTTPS hacia `graph.facebook.com`, `api.openai.com` y/o `generativelanguage.googleapis.com`. |

La lectura de comprobantes no instala nada en el servidor: se hace con el proveedor de IA de cada tienda (OpenAI o Gemini) por HTTPS. Las tiendas con Grok no tienen lectura automática; el administrador revisa igual.

## 2. Directorios y permisos

- La raíz web debe apuntar a `public/`. Si el panel del hosting no lo permite, enlaza `public_html` a `public/`; nunca expongas la raíz del proyecto.
- `storage/` y `bootstrap/cache/` deben ser escribibles por el usuario de PHP.
- Los comprobantes van a `storage/app/private/receipts` (disco `receipts`, nunca público). Se borran al confirmar o rechazar el pago. Si un borrado falla, queda en el log `RECEIPT_PURGE_FAILED`; todavía no hay tarea que lo reintente.
- `php artisan storage:link` solo hace falta para las imágenes públicas de productos.

## 3. Variables de entorno (`.env` de producción)

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tu-dominio
APP_KEY=...            # generada una vez; si se pierde no se pueden descifrar las claves guardadas
DB_CONNECTION=mysql    # o mariadb
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
LOG_CHANNEL=daily
LOG_LEVEL=info
AI_MODELS_OPENAI=gpt-4o,gpt-4o-mini   # solo los modelos habilitados; un modelo de tienda fuera de esta lista da error
AI_MODELS_GEMINI=gemini-2.5-flash,gemini-2.5-pro
AI_MODELS_GROK=grok-beta
```

Las credenciales de WhatsApp y de IA de cada tienda **no** van en `.env`: se guardan cifradas en la base de datos, desde el formulario de cada tienda.

## 4. Cola sin worker permanente

El hosting no mantiene procesos vivos. El scheduler (`routes/console.php`) ejecuta cada minuto `queue:work --stop-when-empty --max-time=50`, que procesa los mensajes pendientes y termina. Solo hace falta este cron:

```
* * * * * /opt/alt/php84/usr/bin/php /home/USUARIO/botinker/artisan schedule:run >> /dev/null 2>&1
```

- Latencia esperada: hasta ~1 minuto entre el mensaje del cliente y la respuesta.
- Si el hosting solo permite cron cada 5 minutos, la latencia sube a ~5 minutos. Funciona, pero se nota.
- Fallback si no hay cron (no recomendado): `QUEUE_CONNECTION=sync`. El mensaje se procesa dentro del webhook; si tarda más de unos 20 s, Meta reintenta. Los reintentos se descartan por id de mensaje durante 10 minutos.
- Diagnóstico: `php artisan queue:failed` y la tabla `jobs`.

## 5. Despliegue seguro

1. **Copia de seguridad completa de la base de datos.**
2. Subir el código e instalar dependencias sin las de desarrollo (mismo procedimiento que `WpBotStore/deploy/optimize-vendor.sh`):
   `/opt/alt/php84/usr/bin/php /opt/alt/php84/usr/bin/composer install --no-dev --optimize-autoloader`
3. Comprobaciones previas, solo de lectura:
   - **Modelos de IA de las tiendas** (no muestra claves; si no imprime nada, todo está bien):
     ```bash
     php artisan tinker --execute='foreach (App\Models\Store::query()->get(["id","ai_provider","ai_model"]) as $s) { if (! in_array($s->ai_model, (array) config("ai.models.{$s->ai_provider}"), true)) echo "{$s->id}\t{$s->ai_provider}\t{$s->ai_model}\n"; }'
     ```
   - **Números de WhatsApp duplicados o vacíos** (la migración `2026_10_19_000000` se detiene si los hay y no cambia datos):
     ```sql
     SELECT wa_phone_number_id, COUNT(*) n, GROUP_CONCAT(id ORDER BY id) store_ids
     FROM stores WHERE wa_phone_number_id IS NOT NULL GROUP BY wa_phone_number_id HAVING COUNT(*) > 1;
     SELECT id FROM stores WHERE wa_phone_number_id IS NOT NULL AND TRIM(wa_phone_number_id) = '';
     ```
     No uses `migrate --pretend` para esto: en ese modo la consulta de la migración no se ejecuta.
4. Resolver a mano lo que salga del paso 3. Nada se reasigna automáticamente.
5. `php artisan migrate --force`
6. `php artisan config:cache && php artisan route:cache && php artisan view:cache`
7. Verificar: `php artisan schedule:list` debe mostrar la tarea de la cola.

## 6. Configuración de Meta y de la tienda

1. **Webhook** (Meta for Developers → WhatsApp → Configuración):
   - URL: `https://tu-dominio/api/whatsapp/webhook/{wa_verify_token de la tienda}`
   - Token de verificación: el mismo `wa_verify_token`.
   - Suscribirse al campo `messages`.
2. **En la tienda** (Filament → Tiendas):
   - número (`wa_phone_number_id`), token y App Secret de la app de Meta;
   - proveedor, modelo y clave de IA;
   - modo de conversación **Conocimiento**;
   - instrucciones de pago (Nequi, Daviplata, Llave…);
   - **Admin Alert Phone**: el WhatsApp del administrador, el **único** número que puede responder Sí/No a los pagos de esa tienda.
3. **Plantillas aprobadas por Meta** (Filament → Plantillas, campo «Uso en pagos»). Se usan cuando la ventana de 24 h está cerrada:
   - `payment_alert` → al administrador. Parámetros: `store_name`, `payment_ref`, `product_name`, `amount`, `customer_phone`.
   - `payment_result` → al cliente. Parámetros: `product_name`, `result`, `expires_at`.

   Sin estas plantillas, los mensajes fuera de la ventana se envían como texto, y Meta probablemente no los entregará.
4. **Producto:** dominio o tema, acceso **Con suscripción** y al menos un plan activo (Filament → Planes).

## 7. E2E del flujo comercial (con Meta real)

Preparación:
- Un número de prueba del cliente distinto del número del administrador.
- El administrador escribe cualquier cosa al número de la tienda antes de empezar, para abrir la ventana de 24 h. Si no, hace falta la plantilla `payment_alert`.

| Paso | Acción | Resultado esperado |
|---|---|---|
| 1 | El cliente escribe «pagar» | Recibe plan, valor, duración e instrucciones de pago. En la base: pago `pending` |
| 2 | El cliente envía una foto del comprobante | Recibe «Recibí tu comprobante…». Pago `under_review`; comprobante en `storage/app/private/receipts` |
| 3 | — | El administrador recibe «🧾 Comprobante por revisar — P-n» con los datos leídos y la imagen |
| 4 | El administrador verifica en su banco y responde «Sí P-n» | Recibe «✅ P-n confirmado…». El cliente recibe «✅ Tu pago… fue confirmado… hasta el dd/mm/aaaa». Pago `confirmed`, suscripción de 30 días, archivo borrado |
| 5 | El cliente usa el producto | Recibe el servicio completo |
| 6 | El administrador repite «Sí P-n» | «ya estaba confirmado»; no hay segundo aviso al cliente ni extensión |
| 7 | Repetir 1–3 con otro cliente y responder «No P-n» | El cliente recibe «no pudo confirmarse»; sin suscripción |
| 8 | Otro número escribe «Sí P-n» | Se trata como mensaje normal de cliente; el pago no cambia |

Durante el E2E, revisar `storage/logs` (sin contenido de mensajes): `PAYMENT_RECEIPT_RECEIVED`, `STORE_MESSAGE_SENT`, `PAYMENT_CONFIRMED`/`PAYMENT_REJECTED`.
