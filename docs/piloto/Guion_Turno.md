# Piloto: guion de un turno completo

Simulación de un turno en **La Mesa Centro** (staging) con personas reales en cada rol. Dura de 60 a 90 minutos. Cada paso dice **quién** lo hace, **dónde** y **qué se espera ver**. Anota en la hoja de observaciones (al final) todo lo que no salga como se espera.

## Participantes

| Rol | Persona | Dispositivo |
|---|---|---|
| Mesero 1 | | Teléfono con la app Restia (staging) |
| Mesero 2 (opcional) | | Teléfono con la app |
| Cocina | | Tableta o teléfono con la app, en la cocina |
| Gerente | | Teléfono con la app **y** computadora con el panel |
| Clientes (2 o 3) | | Su propio teléfono, para el QR y el sitio de La Mesa |

Sitio y panel: `https://restia-web-staging.onrender.com` (el panel está en `/acceso`).

## 0. Antes de empezar (15 min)

1. **Gerente, panel → Dispositivos:** genera un código de alta por cada teléfono que todavía no esté registrado. Cada persona abre la app, escribe el código y entra con su correo (o con *Continuar con Google*).
   - *Se espera:* el mesero ve las pestañas **Mesas, Comandas, Entregas y Más**; cocina ve **Cocina, Inventario y Más**.
2. **Gerente, app → Más → Caja:** si no hay turno abierto, **Abrir turno** con un fondo de $500.00. Si ya hay uno abierto, se usa ese.
3. **Gerente, panel → Salón:** imprime o muestra en pantalla el QR de las mesas 1 y 2.
4. Abre el sitio la primera vez antes de empezar: el servidor gratuito tarda hasta un minuto en despertar.

## 1. Una mesa de principio a fin (caso base)

| # | Quién | Dónde | Qué hace | Se espera |
|---|---|---|---|---|
| 1.1 | Cliente A | Su teléfono | Escanea el QR de la **mesa 1** | Ve "Mesa 1", la recomendación del día y la carta con fotos. Toca un platillo y ve su foto y descripción. |
| 1.2 | Mesero 1 | App → Mesas | Toca la mesa 1 (aparece *Posiblemente ocupada*) → **Abrir** con 2 personas | La mesa pasa a *Ocupada, sin comanda*. |
| 1.3 | Mesero 1 | Mesa 1 → **Agregar comanda** | Agrega 1 Pozole rojo, 1 Tacos al pastor y 1 Agua de jamaica (*Hielo: Poco hielo*). Nota en los tacos: "sin cebolla". **Revisar comanda → Enviar a cocina** | "Comanda de la mesa 1 enviada". La mesa pasa a *Esperando comida*. |
| 1.4 | Cocina | App → Cocina | Filtra **Cocina**: aparecen pozole y tacos con la nota. **Empezar** en los dos. Filtra **Barra**: el agua → **Empezar** y **Listo** | Al mesero le llega el aviso del agua lista; en la mesa, la ruta de la ronda avanza. |
| 1.5 | Mesero 1 | App → Entregas | **Entregar** el agua | El agua pasa a *Entregado*. |
| 1.6 | Cocina | App → Cocina | **Listo** en pozole y tacos | Aparecen en **Entregas** del mesero. |
| 1.7 | Mesero 1 | Entregas | **Entregar** los dos | La mesa pasa a *Consumiendo*. |
| 1.8 | Mesero 1 | Mesa 1 → Cuenta | **Pedir la cuenta** | La mesa pasa a *Cuenta solicitada*. |
| 1.9 | Mesero 1 | Cuenta → Cobrar | **Efectivo**, recibido $500 | "Pago registrado. Entrega $… de cambio." La cuenta queda *Pagada*. |
| 1.10 | Mesero 1 | Mesa 1 | **Cerrar mesa** → **Ya está limpia** | La mesa vuelve a *Libre*. |

## 2. Casos difíciles

### 2.1 Juntar mesas (grupo de 6)
1. **Mesero 2:** abre la **mesa 3** con 6 personas → **Juntar con la mesa** → elige la **mesa 4** → **Juntar**.
   - *Se espera:* las dos mesas comparten una sola cuenta; en Mesas ambas aparecen ocupadas.
2. Manda una comanda de 6 platillos variados (incluye 1 Arrachera con *Término: Medio*).
3. Antes de cerrar, **Separar una mesa**: separa la mesa 4.
   - *Se espera:* la mesa 4 queda *Por limpiar* y lo pedido se queda en la cuenta de la mesa 3.

### 2.2 Cancelar un platillo (antes de que cocina empiece)
1. **Mesero 1:** en una mesa abierta, manda una comanda con 2 Churros.
2. Antes de que cocina toque **Empezar**: en la mesa, sobre los churros → **Cancelar** 1, con motivo "El cliente cambió de opinión".
   - *Se espera:* desaparece de la cola de cocina y no se cobra.
3. **Prueba de control:** cocina toca **Empezar** en el otro churro y el mesero intenta cancelarlo.
   - *Se espera:* "Cocina ya empezó…": no se puede cancelar.

### 2.3 Anular un platillo ya preparado (con autorización)
1. Con el churro que ya está en preparación: **Anular** con motivo "Se cayó al servir".
2. Aparece **Autorización de un gerente**: el **gerente** escribe su correo y contraseña en el teléfono del mesero → **Anular**.
   - *Se espera:* el platillo queda anulado y fuera de la cuenta. Sin los datos del gerente no se puede.

### 2.4 Platillo agotado
1. **Cocina:** **Agotados** → marca **Pay de queso** como agotado.
2. **Mesero:** al agregar una comanda, el pay aparece *Agotado* y no se puede pedir. **Cliente:** en el QR aparece marcado como agotado.
3. **Cocina:** lo vuelve a marcar **Disponible**.

### 2.5 Dividir la cuenta y cobrar con tarjeta
1. Mesa con 4 platillos ya entregados. **Cuenta → Mover platillos**: pasa 2 platillos a **Cuenta nueva** (nombre "Ana").
   - *Se espera:* dos cuentas, cada una con su total. (Dividir se hace **antes** del primer pago.)
2. **Cuenta 1:** cobra en **Efectivo** con cambio.
3. **Cuenta "Ana":** cobra con **Crédito**, con propina de $20. Aparece *Esperando el resultado de la terminal* → **Registrar resultado → Aprobado**.
   - *Se espera:* las dos cuentas *Pagadas*; la propina aparece aparte.
4. **Prueba de control:** intenta un segundo cobro con tarjeta y regístralo como **Rechazado**.
   - *Se espera:* la cuenta sigue con saldo pendiente.

### 2.6 Cortesía
1. En una cuenta abierta, **Cortesía** sobre un postre (la aplica el gerente, o el mesero con su autorización).
   - *Se espera:* el postre queda en $0 y aparece en "Cortesías" de la cuenta.

### 2.7 Reserva desde el sitio de La Mesa
1. **Cliente B:** en el sitio, **Encontrar la más cercana** (acepta la ubicación) → en **Reservar**, elige La Mesa Centro, hoy o mañana, 4 personas y una hora disponible → **Enviar solicitud**.
   - *Se espera:* folio de 8 caracteres y "Pendiente: la sucursal la confirmará contigo".
2. **Gerente:** panel → **Reservaciones**: aparece la solicitud *Sin confirmar* → **Confirmar**.
3. **Cliente B:** en el sitio, **Consulta su estado** con folio y teléfono.
   - *Se espera:* "Confirmada".
4. **Mesero:** app → Más → **Reservas**: cuando "llega" el cliente → **Llegó** → **Sentar** en la mesa propuesta.

### 2.8 Inventario de cocina
1. **Cocina:** app → **Inventario** → un insumo → **Registrar merma** (cantidad pequeña, motivo "Se echó a perder").
2. **Cocina:** **Recibir mercancía** de otro insumo, con proveedor y costo.
3. **Cocina:** **Bloquear por inocuidad** un insumo, con motivo, y avisa al gerente en persona (el bloqueo no manda aviso a la campana).
4. **Gerente:** panel → **Inventario**: el insumo aparece como bloqueado → revísalo → **Desbloquear**. Revisa **Stock bajo**: el insumo bajo mínimo también llega como aviso a la campana.

## 3. Cierre
Sigue la **Lista de revisión al cierre** (`Lista_Cierre.md`).

## Hoja de observaciones

| Hora | Paso | Quién | Qué pasó | Qué se esperaba | Gravedad (alta / media / baja) |
|---|---|---|---|---|---|
| | | | | | |
| | | | | | |
| | | | | | |

Al final, cada participante responde:
1. ¿Qué fue lo más confuso?
2. ¿Qué tardó más de lo que debería?
3. ¿Qué te faltó poder hacer?
