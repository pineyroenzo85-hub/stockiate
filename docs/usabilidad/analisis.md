# Capítulo X — Evaluación de usabilidad

> **Plantilla.** Los espacios `______` se completan con los resultados. Los
> bloques en cita (`>`) son instrucciones para vos y **no van al informe
> final**: borralos antes de entregar.

---

## X.1 Objetivo y alcance

Se realizó una prueba de usabilidad con **cinco participantes** sobre las tres
tareas centrales del sistema: cargar mercadería, cobrar una venta y consultar
el stock de un producto.

> **Esto tiene que quedar escrito así, o parecido, y no se negocia:**

El objetivo de esta prueba fue **encontrar problemas de usabilidad**, no medir
el desempeño del sistema. Con cinco participantes es posible detectar la
mayoría de los problemas graves de una interfaz —un problema que afecta a la
mayoría de los usuarios aparece con muy pocas sesiones—, pero **no es posible
sacar conclusiones estadísticas**: cinco casos no permiten estimar tiempos
promedio de la población, ni comparar significativamente entre roles, ni
afirmar que una diferencia observada no sea producto del azar.

Por eso, en las páginas que siguen los números se presentan **caso por caso** y
no como promedios con intervalos de confianza. Cuando se menciona un promedio
es como resumen descriptivo de estas cinco sesiones, y nada más.

## X.2 Participantes

| # | Edad | Ocupación | Experiencia previa con sistemas de gestión | Usa smartphone a diario |
|---|---|---|---|---|
| 1 | \_\_\_\_ | \_\_\_\_\_\_\_\_\_\_ | \_\_\_\_\_\_\_\_\_\_ | \_\_\_\_ |
| 2 | \_\_\_\_ | \_\_\_\_\_\_\_\_\_\_ | \_\_\_\_\_\_\_\_\_\_ | \_\_\_\_ |
| 3 | \_\_\_\_ | \_\_\_\_\_\_\_\_\_\_ | \_\_\_\_\_\_\_\_\_\_ | \_\_\_\_ |
| 4 | \_\_\_\_ | \_\_\_\_\_\_\_\_\_\_ | \_\_\_\_\_\_\_\_\_\_ | \_\_\_\_ |
| 5 | \_\_\_\_ | \_\_\_\_\_\_\_\_\_\_ | \_\_\_\_\_\_\_\_\_\_ | \_\_\_\_ |

> Sin nombres: sólo el número, como dice el consentimiento. Si todos los
> participantes se parecen entre sí (por ejemplo, todos estudiantes de
> sistemas), **decilo acá**: es una limitación real del estudio y omitirla es
> peor que tenerla.

## X.3 Método

Cada sesión duró aproximadamente \_\_\_\_ minutos y siguió el guion del
Anexo \_\_\_\_. Las tareas se presentaron como situaciones de trabajo —"acaba de
llegar mercadería nueva"— y no como instrucciones del sistema, para no develar
en el enunciado cuál era el camino esperado.

El facilitador no brindó ayuda durante las tareas. Se registró tiempo de
ejecución, cantidad de errores, si el participante solicitó ayuda y si logró
completar la tarea, según los criterios definidos en el guion.

Las pruebas se realizaron sobre la **base de demostración** del sistema
(`stockiate_demo`), regenerada entre participante y participante para que todos
partieran del mismo estado inicial.

## X.4 Resultados por tarea

### Tabla X.1 — Tiempos, errores y finalización

| Participante | T1 Cargar (s) | Err. | T2 Cobrar (s) | Err. | T3 Consultar (s) | Err. |
|---|---|---|---|---|---|---|
| 1 | \_\_\_\_ | \_\_ | \_\_\_\_ | \_\_ | \_\_\_\_ | \_\_ |
| 2 | \_\_\_\_ | \_\_ | \_\_\_\_ | \_\_ | \_\_\_\_ | \_\_ |
| 3 | \_\_\_\_ | \_\_ | \_\_\_\_ | \_\_ | \_\_\_\_ | \_\_ |
| 4 | \_\_\_\_ | \_\_ | \_\_\_\_ | \_\_ | \_\_\_\_ | \_\_ |
| 5 | \_\_\_\_ | \_\_ | \_\_\_\_ | \_\_ | \_\_\_\_ | \_\_ |
| **Mediana** | \_\_\_\_ | \_\_ | \_\_\_\_ | \_\_ | \_\_\_\_ | \_\_ |

> **Mediana y no promedio.** Con cinco valores, un participante que se trabó
> tres minutos arrastra el promedio y deja de describir a nadie. Reportá los
> cinco valores individuales igual: son pocos y entran.
>
> Las tareas abandonadas van con celda vacía y se cuentan en la tabla X.2, no
> como un tiempo alto.

### Tabla X.2 — Tasa de finalización

| Tarea | Completaron | Abandonaron | Pidieron ayuda |
|---|---|---|---|
| T1 Cargar mercadería | \_\_ de 5 | \_\_ | \_\_ |
| T2 Cobrar una venta | \_\_ de 5 | \_\_ | \_\_ |
| T3 Consultar stock | \_\_ de 5 | \_\_ | \_\_ |

> Escribí siempre "\_\_ de 5", nunca el porcentaje solo. "El 60 % completó la
> tarea" suena a un estudio con cientos de casos; "3 de 5" dice la verdad del
> tamaño de la muestra en las mismas cuatro palabras.

### Figura X.1 — Tiempo por tarea y participante

> **Un solo gráfico, y este.** Un *dot plot*: el eje horizontal es el tiempo en
> segundos y hay tres filas, una por tarea. Cada participante es un punto en la
> fila de su tarea, con su número al lado.
>
> Por qué éste y no un gráfico de barras con promedios: las barras muestran
> cinco promedios y esconden que uno de los cinco tardó el triple. El dot plot
> muestra los quince valores reales, y el lector ve la dispersión —que con n=5
> es la información— en vez de una barra que insinúa precisión que no hay.
>
> Sin barras de error, sin desvíos estándar, sin intervalos de confianza.
>
> Colores: si necesitás distinguir series, usá la paleta del panel de métricas
> (`MIA_COLORES` en `metricas_ia.js`): `#8673d9`, `#009faa`, `#cf6139`,
> `#b761b1`. Están validadas para daltonismo y son las mismas que las del resto
> del informe.

```
[ Figura X.1 ]
```

## X.5 Problemas de usabilidad encontrados

> El corazón del capítulo. Una entrada por problema, ordenadas por gravedad.
> La gravedad es tu juicio, pero justificalo con las dos cosas de la derecha:
> a cuántos les pasó y si pudieron seguir igual.

### Tabla X.3 — Problemas detectados

| # | Problema | Dónde | Participantes afectados | ¿Impidió terminar? | Gravedad |
|---|---|---|---|---|---|
| P1 | \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ | \_\_\_\_\_\_\_\_ | \_\_ de 5 | \_\_\_\_ | Alta / Media / Baja |
| P2 | \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ | \_\_\_\_\_\_\_\_ | \_\_ de 5 | \_\_\_\_ | \_\_\_\_ |
| P3 | \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ | \_\_\_\_\_\_\_\_ | \_\_ de 5 | \_\_\_\_ | \_\_\_\_ |
| P4 | \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ | \_\_\_\_\_\_\_\_ | \_\_ de 5 | \_\_\_\_ | \_\_\_\_ |
| P5 | \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ | \_\_\_\_\_\_\_\_ | \_\_ de 5 | \_\_\_\_ | \_\_\_\_ |

### P1 — \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_

**Qué pasó.** \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_

**A cuántos.** \_\_ de 5 participantes.

**Cita.** *"\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_"* (participante \_\_).

**Qué se hizo o se propone hacer.** \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_

> Repetí este bloque para cada problema. Las citas textuales valen mucho más
> que la paráfrasis: una frase dicha por alguien que está usando el sistema es
> el dato más difícil de discutir que vas a tener en todo el informe.

### Un candidato conocido antes de empezar

> El sistema **no calcula el vuelto**: la pantalla de caja muestra el total y
> nada más. Está anotado en el guion como punto de observación de la tarea 2.
> Si varios participantes lo buscaron en la pantalla, entra acá como problema
> con evidencia propia. Si ninguno lo buscó —porque en el rubro se calcula de
> cabeza—, **también decilo**: un problema que suponías y no apareció es un
> resultado, y contarlo muestra que la prueba no estaba armada para confirmar
> lo que ya pensabas.

## X.6 Resultados del cuestionario

### Tabla X.4 — Puntuación por participante

| Participante | Puntuación (0-100) |
|---|---|
| 1 | \_\_\_\_ |
| 2 | \_\_\_\_ |
| 3 | \_\_\_\_ |
| 4 | \_\_\_\_ |
| 5 | \_\_\_\_ |
| **Mediana** | \_\_\_\_ |

> **Advertencia que va sí o sí en el informe, no en una nota al pie:** el
> cuestionario está inspirado en la estructura de la escala SUS pero sus ítems
> son propios, reescritos para este sistema. La puntuación resultante **no es
> un puntaje SUS** y **no se puede comparar contra los valores de referencia de
> esa escala**. Sirve para comparar entre estos cinco participantes o contra
> una medición futura del mismo sistema con el mismo cuestionario.

### Tabla X.5 — Respuestas por ítem

| Ítem | P1 | P2 | P3 | P4 | P5 |
|---|---|---|---|---|---|
| 1. Me resultó fácil | \_ | \_ | \_ | \_ | \_ |
| 2. Pantallas complicadas *(neg.)* | \_ | \_ | \_ | \_ | \_ |
| 3. Supe dónde estaba | \_ | \_ | \_ | \_ | \_ |
| 4. Necesitaba que me expliquen *(neg.)* | \_ | \_ | \_ | \_ | \_ |
| 5. Confío en que quedó guardado | \_ | \_ | \_ | \_ | \_ |
| 6. Dudé de la detección *(neg.)* | \_ | \_ | \_ | \_ | \_ |
| 7. La pantalla de revisión es útil | \_ | \_ | \_ | \_ | \_ |
| 8. Miedo en un día de movimiento *(neg.)* | \_ | \_ | \_ | \_ | \_ |
| 9. Lo usaría todos los días | \_ | \_ | \_ | \_ | \_ |
| 10. Más lento que a mano *(neg.)* | \_ | \_ | \_ | \_ | \_ |

> Los ítems 6 y 7 son los que hablan de la Red de Seguridad, que es el
> argumento central del trabajo. Si el 7 da alto y el 6 da bajo, es evidencia a
> favor: la gente encuentra útil la validación humana y confía en el resultado.
> Si el 7 da alto y el 6 **también**, quiere decir que la pantalla tranquiliza
> pero la detección sigue generando desconfianza: distinto matiz, igual de
> interesante, y hay que escribirlo así.

## X.7 Conclusiones

\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_

\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_

## X.8 Limitaciones

> Este apartado no es un trámite: es lo que separa un capítulo honesto de uno
> que promete más de lo que midió. Como mínimo, tienen que estar estos cuatro
> puntos.

- **Tamaño de la muestra.** Cinco participantes permiten detectar problemas de
  usabilidad, no estimar magnitudes. Ningún número de este capítulo puede
  leerse como una medición de la población de usuarios del sistema.
- **Perfil de los participantes.** \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_. Si
  no son empleados de comercio reales, las tareas se ejecutaron sin la presión
  de un cliente esperando, que es la condición donde el sistema tiene que
  funcionar.
- **Entorno.** La prueba se hizo en \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_, no en el local, con
  buena luz y sin movimiento. La detección por imagen es sensible a la luz y al
  fondo, así que su desempeño en condiciones reales puede ser distinto.
- **Datos de demostración.** Se usó la base de demostración, no el inventario
  real del comercio. Los productos y los precios son verosímiles pero
  inventados.

---

## Anexos

- Anexo \_\_\_\_ — Guion del facilitador (`guion.md`)
- Anexo \_\_\_\_ — Cuestionario (`cuestionario.md`)
- Anexo \_\_\_\_ — Formulario de consentimiento (`consentimiento.md`)
- Anexo \_\_\_\_ — Planilla de registro completa (`planilla.csv`)
