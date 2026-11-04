# Sales Funnel Engine: limitaciones aceptadas para el MVP

## Transición pendiente tras un pago con envío fallido

Estado: aceptada para el MVP (decisión del 2026-11-04).

### Qué ocurre

Al iniciar una compra desde un embudo, el pago se crea antes de enviar su mensaje. Si WhatsApp no acepta el envío, la etapa de destino queda guardada en caché durante 48 horas, asociada a ese pago.

El siguiente mensaje que encuentre el pago abierto, sea un reintento de la cola o un mensaje nuevo del cliente, envía los datos del pago y aplica la etapa de destino una sola vez.

### La limitación

Si la caché vence o se limpia antes de ese mensaje, se pierde la transición: el embudo sigue en la etapa anterior.

Solo puede pasar si fallan todos los reintentos del envío y el cliente no vuelve a escribir en 48 horas, o si se ejecuta `cache:clear` en ese intervalo.

### Qué no cambia si el TTL vence

- El pago sigue abierto y no se crea otro.
- El cliente recibe los datos del pago abierto en su siguiente mensaje.
- La vigencia y el estado del pago no cambian.
- El acceso se decide como siempre, y la confirmación del pago lo concede.

### El TTL no es la vigencia del pago

Son dos conceptos distintos. Un pago abierto puede durar más de 48 horas: un pago `pending` vencido solo pasa a `expired` cuando llega un comprobante, y `under_review` y `not_reflected` no tienen plazo.

Hoy el TTL toma su valor de `CommerceResponder::PAYMENT_WINDOW_HOURS`, la misma constante que fija el plazo del pago. Si esa constante cambia, también cambia el TTL. La caché solo se usa para la transición del embudo y nunca para decidir sobre el pago o el acceso.

### Por qué se acepta

El flujo comercial espera que el pago se confirme de inmediato o en pocos minutos, sobre todo cuando se integre una pasarela de pago automático.

### Si hubiera que resolverla

Guardar el destino en `conversation_funnel_states.context`, que no requiere migración. No está implementado.
