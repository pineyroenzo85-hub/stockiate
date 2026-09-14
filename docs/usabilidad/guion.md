# Test de usabilidad de stockIAte — guion del facilitador

> Para imprimir y tener al lado durante la sesión. Una copia por participante.
>
> **Participante nº \_\_\_\_  ·  Fecha \_\_\_\_ / \_\_\_\_ / \_\_\_\_  ·  Hora de inicio \_\_\_\_ : \_\_\_\_**

---

## Antes de empezar (5 minutos)

### Preparación del equipo

- [ ] El sistema en **modo demostración** (`STOCKIATE_MODO=demo` en el `.env`),
      con los datos sembrados de nuevo (`php seed_demo.php`). Nunca sobre los
      datos reales del comercio: el test incluye cargar mercadería y cobrar
      ventas, y eso escribe en la base.
- [ ] Sesión iniciada con la cuenta del rol que corresponde a cada tarea
      (`repositor@aroma.demo`, `cajero@aroma.demo`, `duenio@aroma.demo`,
      contraseña `demo1234`).
- [ ] El servicio de IA corriendo. Chequeo de un comando:
      `curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/` tiene que
      dar **200**. Si no, la detección por foto no anda y el test mide otra
      cosa.
- [ ] Tres productos físicos sobre la mesa para la tarea 1 y dos para la
      tarea 2. Que sean envases reales, no cajas vacías.
- [ ] Cronómetro (el del celular alcanza).
- [ ] El consentimiento impreso y firmado **antes** de tocar nada.

### Lo que se le lee al participante, palabra por palabra

> Gracias por venir. Esto es una prueba de un sistema, no una prueba tuya. No
> hay respuestas correctas ni incorrectas: si algo no se entiende o no
> encontrás dónde tocar, es información valiosísima para nosotros, porque
> significa que lo diseñamos mal.
>
> Te voy a pedir que hagas tres cosas. Mientras las hacés, contame en voz alta
> lo que estás pensando: qué estás buscando, qué esperás que pase, qué te
> sorprende. Aunque parezca raro hablar solo, para nosotros es lo más útil.
>
> Yo no te voy a poder ayudar mientras hacés las tareas, aunque me lo pidas. No
> es maldad: si te ayudo, el problema queda tapado y nunca lo arreglamos. Si te
> trabás y querés dejar una tarea, decímelo y pasamos a la siguiente, sin
> ningún problema.
>
> No estamos grabando video ni audio. Yo voy a ir anotando en esta planilla.
>
> ¿Alguna pregunta antes de empezar?

---

## La regla más importante para vos, el facilitador

**No ayudes. Nunca. Aunque duela.**

Es el error más común de quien testea su propio sistema: uno conoce la
pantalla, ve a la persona a dos centímetros del botón y no se aguanta. Cada vez
que ayudás, borrás el hallazgo: ese problema va a seguir ahí el día que el
sistema lo use alguien sin vos al lado.

Si te preguntan algo, contestá siempre alguna de estas tres, y nada más:

- *"¿Y vos qué harías?"*
- *"Probá lo que te parezca."*
- *"No te puedo ayudar con eso, pero seguí."*

Si insisten dos veces, anotá **pidió ayuda = sí** y dejá que sigan. Lo mismo si
se quedan callados mucho rato: no llenes el silencio explicando, preguntá
*"¿en qué estás pensando?"*.

---

## Tarea 1 — Cargar mercadería que llegó (rol repositor)

**Se lee así, como una situación. No nombres módulos, botones ni pantallas:**

> Trabajás en la perfumería y acaba de llegar mercadería nueva del proveedor.
> Estos tres productos —te los dejo acá— hay que sumarlos al stock del local.
> Hacelo como te parezca.

**El cronómetro arranca** cuando el participante toca el dispositivo por
primera vez después de que terminaste de leer.

**El cronómetro para** cuando los tres productos quedaron confirmados y el
sistema muestra el aviso de que el stock se actualizó. Si el participante dice
"listo" antes de eso pero no está cargado, **no pares**: anotá el momento en
que lo dijo y dejá que siga.

**Cuenta como error:**

- Cargar un producto con el nombre equivocado (queda un producto distinto del
  que estaba sobre la mesa).
- Cargar una cantidad distinta de la que hay en la mesa.
- Confirmar la pantalla de validación sin corregir una detección que estaba
  mal.
- Cargar dos veces el mismo producto sin darse cuenta.
- Volver atrás porque se dio cuenta de que estaba en la pantalla equivocada
  (cuenta uno por cada vez).

**Cuenta como abandono:** el participante dice que no puede seguir, o pasan
**5 minutos** sin que haya cargado ninguno de los tres.

**Qué mirar especialmente** (anotalo en observaciones):

- ¿Saca una foto de los tres juntos o de a uno? ¿Qué esperaba que pasara?
- Cuando la IA se equivoca o no detecta nada, ¿se da cuenta de que puede
  corregir, o acepta lo que le propone la pantalla?
- ¿Encuentra el campo de vencimiento? ¿Lo completa o lo saltea?

---

## Tarea 2 — Cobrar una venta (rol cajero)

**Se lee así:**

> Ahora estás atendiendo la caja. Llega un cliente con estos dos productos.
> Cobrale. Te va a pagar con **\_\_\_\_\_\_\_\_ pesos** en efectivo, así que
> además vas a tener que darle el vuelto.

> *(Antes de la sesión, elegí un monto redondo que sea claramente mayor al
> total de esos dos productos. Anotalo en el espacio de arriba y decilo en voz
> alta.)*

**El cronómetro arranca** cuando toca el dispositivo por primera vez.

**El cronómetro para** cuando la venta quedó registrada **y** el participante
dijo en voz alta cuánto vuelto le da al cliente.

> **Ojo con esta tarea, y es a propósito:** el sistema **no calcula el vuelto**.
> Muestra el total a cobrar y nada más. Queremos ver qué hace la persona: si lo
> busca en la pantalla, si lo calcula de cabeza, si saca la calculadora del
> celular, o si se traba. **Que no lo encuentre no es un error del
> participante**, así que no lo cuentes como error: anotalo en observaciones y
> cronometrá aparte cuánto tarda esa parte. Si al final de la sesión los cinco
> participantes lo buscaron en la pantalla, eso es un hallazgo del test, no una
> falla de ellos.

**Cuenta como error:**

- Cobrar un producto distinto del que hay sobre la mesa.
- Cobrar una cantidad equivocada.
- Confirmar el ticket con un producto que la IA identificó mal.
- Decir un vuelto incorrecto.
- Volver atrás por haberse equivocado de pantalla (uno por cada vez).

**Cuenta como abandono:** dice que no puede seguir, o pasan **4 minutos** sin
que haya registrado la venta.

**Qué mirar especialmente:**

- ¿Revisa el ticket antes de confirmar, o confirma de una?
- ¿Nota el total? ¿Lo busca antes o después de confirmar?
- ¿Dónde busca el vuelto?

---

## Tarea 3 — Averiguar el stock de un producto (rol dueño)

**Se lee así:**

> Te llama un cliente por teléfono y te pregunta si te queda **\_\_\_\_\_\_\_\_
> \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_** y cuántas unidades. Averigualo.

> *(Elegí antes de la sesión un producto concreto del catálogo de demo y
> escribilo arriba. Que sea uno que exista y tenga stock, no uno de los que
> están en cero.)*

**El cronómetro arranca** cuando toca el dispositivo por primera vez.

**El cronómetro para** cuando el participante dice en voz alta un número de
unidades. Si dice el número equivocado, **igual parás el cronómetro** y lo
anotás como error: la tarea terminó, mal.

**Cuenta como error:**

- Decir una cantidad que no es la del sistema.
- Decir la cantidad de otro producto (por ejemplo, otra variante del mismo
  envase).
- Volver atrás por haberse equivocado de pantalla (uno por cada vez).

**Cuenta como abandono:** dice que no puede seguir, o pasan **3 minutos** sin
que haya dicho ningún número.

**Qué mirar especialmente:**

- ¿Usa la tabla de inventario, el buscador, o el chatbot? Hay tres caminos
  posibles y saber cuál eligen dice mucho.
- Si usa el chatbot, ¿le cree la respuesta o va a chequearla a la tabla?
- ¿Nota que el panel arranca con los bloques plegados? ¿Le molesta o lo
  agradece?

---

## Después de las tres tareas (5 minutos)

1. Entregá el **cuestionario** (`cuestionario.md`) y dejá que lo complete solo,
   sin vos mirando por encima del hombro. Decile que no hay respuestas
   correctas y que las críticas son lo más útil.

2. Preguntá estas tres, en este orden, y anotá lo que diga sin discutir ni
   corregir:

   - *¿Qué fue lo más difícil de todo lo que hiciste hoy?*
   - *¿Hubo algún momento en que no entendiste qué estaba pasando?*
   - *Si tuvieras que cambiarle una sola cosa, ¿cuál sería?*

3. Recién ahora, si el participante quiere, mostrale cómo se hacía lo que no le
   salió. Antes no.

4. Agradecé. Contale para qué van a servir los datos.

---

## Al cerrar la sesión

- [ ] Planilla completa (`planilla.csv`), con las observaciones escritas
      **ahora**, no de memoria a la noche.
- [ ] Cuestionario guardado, identificado sólo con el número de participante.
- [ ] `php seed_demo.php` de nuevo, para que el siguiente participante arranque
      con exactamente los mismos datos que este. **Esto no es opcional**: si el
      segundo participante encuentra el stock ya modificado por el primero, los
      tiempos no se pueden comparar entre sí.
