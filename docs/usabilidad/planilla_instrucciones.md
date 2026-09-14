# Cómo se completa `planilla.csv`

Una fila por participante y tarea: 5 participantes × 3 tareas = **15 filas**,
ya creadas y vacías. Se puede abrir con Excel, LibreOffice o Google Sheets.

| Columna | Qué va | Ejemplo |
|---|---|---|
| `participante` | El número, nunca el nombre. Ver `consentimiento.md`. | `3` |
| `rol` | El rol con el que hizo esa tarea. Ya viene puesto. | `cajero` |
| `tarea` | Ya viene puesta. | `2 - Cobrar una venta` |
| `tiempo_segundos` | Del cronómetro, en **segundos enteros**. Si abandonó, dejalo vacío (no pongas 0: un 0 se promedia y un vacío no). | `147` |
| `errores` | La cuenta de errores según los criterios del guion. Si no hubo, `0`. | `2` |
| `pidio_ayuda` | `si` / `no`. Se marca `si` cuando pidió ayuda dos veces, aunque no se la hayas dado. | `no` |
| `completo` | `si` / `no`. `no` es abandono o se pasó del tiempo máximo. | `si` |
| `observaciones` | Lo que dijo e hizo, **en el momento**. Si tiene comas, va entre comillas dobles. | `"Buscó el vuelto en la pantalla 20 s antes de sacar la calculadora"` |

## Tres cosas que arruinan la planilla

**Completarla de memoria a la noche.** Las observaciones son el 80 % del valor
del test y se evaporan en veinte minutos. Escribilas mientras la persona sigue
sentada.

**Poner 0 en el tiempo cuando abandonó.** Un cero entra en el promedio y lo
hunde; un vacío se puede contar aparte, que es lo correcto. El abandono se
registra en `completo`, no en `tiempo_segundos`.

**Escribir conclusiones en vez de hechos.** `observaciones` es para lo que
pasó, no para lo que creés que significa. `"no entendió la Red de Seguridad"`
es una conclusión; `"confirmó sin mirar; cuando le pregunté después dijo 'pensé
que ya estaba bien'"` es un hecho, y de ahí sale la conclusión sola cuando
tengas las cinco sesiones juntas.
