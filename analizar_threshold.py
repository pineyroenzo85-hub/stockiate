"""
stockIAte - analizar_threshold.py
================================================================================
Lee `resultados_threshold.csv` (el barrido que produce `test_threshold.py`) y
genera el material del informe:

    docs/umbral_confianza.md    tabla resumen, tabla por foto y el párrafo
                                que explica qué umbral se eligió y por qué
    docs/umbral_confianza.svg   el gráfico de la curva

Uso:
    python analizar_threshold.py [resultados_threshold.csv]

SIN DEPENDENCIAS NUEVAS
-----------------------
El SVG se arma a mano con la biblioteca estándar en vez de con matplotlib.
matplotlib está instalado en el `.venv` de esta máquina pero NO está en
`requirements.txt`, así que un entorno recreado desde ese archivo no lo
tendría y este script fallaría meses después, justo cuando haya que rehacer
la figura. Un SVG lo abre el navegador, lo importa Word 2016+ y no se pixela
al agrandarlo en el PDF de la tesis.

EL PÁRRAFO SE ESCRIBE SOLO A PARTIR DE LOS DATOS
------------------------------------------------
No está hardcodeado: el script mira si la cantidad de detecciones cambió al
variar `confidence` y escribe una conclusión u otra. Si algún día el workflow
se corrige y el umbral empieza a tener efecto, volver a correr esto da el
párrafo nuevo en vez de repetir el viejo.
"""

import csv
import sys
from collections import defaultdict
from datetime import date
from pathlib import Path

# Las columnas REALES del CSV que escribe test_threshold.py. Se verifican al
# leer: si alguien cambia CSV_COLUMNAS allá, acá se entera con un error claro
# en vez de con un KeyError a mitad de camino.
COLUMNAS_ESPERADAS = [
    "foto",
    "variante",
    "threshold",
    "cantidad_detecciones",
    "indice_deteccion",
    "confianza",
    "marca_ocr",
    "ocr_exitoso",
    "tiempo_respuesta_seg",
    "tamano_bytes",
]

SALIDA_MD = Path("docs/umbral_confianza.md")
SALIDA_SVG = Path("docs/umbral_confianza.svg")

# Paleta del panel de métricas (metricas_ia.js), validada para daltonismo.
# Acá el gráfico va sobre papel blanco, así que se usan tal cual y el texto
# va en gris oscuro: nunca el número pintado del color de la serie.
COLOR_ORIGINAL = "#8673d9"
COLOR_COMPRIMIDA = "#009faa"
COLOR_TEXTO = "#2b2b33"
COLOR_TENUE = "#7c7c8a"
COLOR_GUIA = "#d8d8e0"


def leer_csv(ruta: Path) -> list[dict]:
    with ruta.open(encoding="utf-8", newline="") as f:
        lector = csv.DictReader(f)
        faltantes = set(COLUMNAS_ESPERADAS) - set(lector.fieldnames or [])
        if faltantes:
            raise SystemExit(
                f"FALLÓ: a '{ruta}' le faltan columnas: {', '.join(sorted(faltantes))}\n"
                f"       Columnas encontradas: {', '.join(lector.fieldnames or [])}"
            )
        return list(lector)


def _f(valor: str) -> float | None:
    """Float, o None si la celda está vacía (las filas 'sin detecciones')."""
    return float(valor) if valor not in ("", None) else None


def resumir(filas: list[dict]) -> dict:
    """Agrega el CSV por (variante, umbral) y también por foto."""
    fotos = sorted({f["foto"] for f in filas})
    variantes = sorted({f["variante"] for f in filas}, reverse=True)  # original, comprimida
    umbrales = sorted({float(f["threshold"]) for f in filas}, reverse=True)

    por_grupo: dict[tuple[str, float], list[dict]] = defaultdict(list)
    for fila in filas:
        por_grupo[(fila["variante"], float(fila["threshold"]))].append(fila)

    resumen = {}
    for (variante, umbral), grupo in por_grupo.items():
        # Una fila con `indice_deteccion` vacío es "esta foto no detectó nada":
        # cuenta como foto procesada pero no como detección.
        detecciones = [g for g in grupo if g["indice_deteccion"] != ""]
        fotos_grupo = {g["foto"] for g in grupo}
        fotos_con_deteccion = {g["foto"] for g in detecciones}
        confianzas = [_f(d["confianza"]) for d in detecciones]
        ocr_ok = sum(1 for d in detecciones if d["ocr_exitoso"] == "True")

        resumen[(variante, umbral)] = {
            "fotos": len(fotos_grupo),
            "fotos_con_deteccion": len(fotos_con_deteccion),
            "detecciones": len(detecciones),
            "det_por_foto": len(detecciones) / len(fotos_grupo) if fotos_grupo else 0,
            "confianza_prom": sum(confianzas) / len(confianzas) if confianzas else None,
            "confianza_min": min(confianzas) if confianzas else None,
            "ocr_ok": ocr_ok,
            "ocr_rate": ocr_ok / len(detecciones) if detecciones else None,
            "tiempo_prom": sum(float(g["tiempo_respuesta_seg"]) for g in grupo) / len(grupo),
            "kb_prom": sum(int(g["tamano_bytes"]) for g in grupo) / len(grupo) / 1024,
        }

    return {
        "fotos": fotos,
        "variantes": variantes,
        "umbrales": umbrales,
        "grupos": resumen,
        "filas": filas,
    }


def umbral_tiene_efecto(datos: dict) -> tuple[bool, int, int]:
    """
    ¿Cambió algo al mover `confidence`?

    Se compara, para cada par (foto, variante), la cantidad de detecciones en
    los distintos umbrales. Si es siempre la misma, el parámetro no llegó al
    modelo. Devuelve (tiene_efecto, combinaciones_sin_cambio, total).
    """
    por_clave: dict[tuple[str, str], set[int]] = defaultdict(set)
    for fila in datos["filas"]:
        clave = (fila["foto"], fila["variante"])
        por_clave[clave].add(int(fila["cantidad_detecciones"] or 0))

    sin_cambio = sum(1 for valores in por_clave.values() if len(valores) <= 1)
    total = len(por_clave)
    return sin_cambio < total, sin_cambio, total


def detecciones_bajo_umbral(datos: dict) -> list[dict]:
    """
    Detecciones que volvieron con una confianza MENOR al umbral que se pidió.

    Es la prueba directa de que el filtro no se aplicó: si el modelo estuviera
    respetando `confidence=0.5`, ninguna predicción de 0.28 podría llegar.
    """
    fuera = []
    for fila in datos["filas"]:
        conf = _f(fila["confianza"])
        if conf is not None and conf < float(fila["threshold"]):
            fuera.append(fila)
    return fuera


# ----------------------------------------------------------------------
# Gráfico
# ----------------------------------------------------------------------

def generar_svg(datos: dict, ruta: Path) -> None:
    """
    Dos paneles, uno arriba del otro:

      (A) detecciones por foto contra el umbral pedido, una línea por variante
      (B) la confianza de CADA detección devuelta, con los umbrales pedidos
          dibujados como líneas verticales

    El panel B es el que prueba el punto: si hay puntos a la izquierda de una
    línea de umbral, esa corrida devolvió detecciones que el umbral tendría
    que haber filtrado.
    """
    W, H = 760, 560
    partes = [
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W}" height="{H}" '
        f'font-family="Segoe UI, Inter, system-ui, sans-serif">',
        f'<rect width="{W}" height="{H}" fill="#ffffff"/>',
    ]

    def texto(x, y, s, size=11, fill=COLOR_TEXTO, anchor="start", weight="normal"):
        seguro = (str(s).replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;"))
        partes.append(
            f'<text x="{x:.1f}" y="{y:.1f}" font-size="{size}" fill="{fill}" '
            f'text-anchor="{anchor}" font-weight="{weight}">{seguro}</text>'
        )

    umbrales = sorted(datos["umbrales"])
    colores = {"original": COLOR_ORIGINAL, "comprimida": COLOR_COMPRIMIDA}

    # ---------------- Panel A ----------------
    ax0, ay0, aw, ah = 60, 50, W - 200, 170
    texto(ax0, 28, "A · Detecciones por foto según el umbral pedido", 13, weight="bold")

    det_max = max(
        [datos["grupos"][(v, u)]["det_por_foto"] for v in datos["variantes"] for u in umbrales]
        + [1.0]
    )
    escala_y = det_max * 1.25

    def ax(u):
        lo, hi = min(umbrales), max(umbrales)
        return ax0 + (aw * (u - lo) / (hi - lo) if hi > lo else aw / 2)

    def ay(v):
        return ay0 + ah * (1 - v / escala_y)

    for i in range(5):
        v = escala_y * i / 4
        partes.append(f'<line x1="{ax0}" y1="{ay(v):.1f}" x2="{ax0 + aw}" y2="{ay(v):.1f}" '
                      f'stroke="{COLOR_GUIA}" stroke-width="1"/>')
        texto(ax0 - 8, ay(v) + 4, f"{v:.1f}", 10, COLOR_TENUE, "end")

    for u in umbrales:
        texto(ax(u), ay0 + ah + 18, f"{u:g}", 10, COLOR_TENUE, "middle")
    texto(ax0 + aw / 2, ay0 + ah + 36, "confidence pedido por código", 10, COLOR_TENUE, "middle")

    for j, variante in enumerate(datos["variantes"]):
        puntos = " ".join(
            f"{ax(u):.1f},{ay(datos['grupos'][(variante, u)]['det_por_foto']):.1f}"
            for u in umbrales
        )
        color = colores.get(variante, COLOR_TENUE)
        # Cuando el umbral no tiene efecto las dos series dan exactamente lo
        # mismo y se tapan una a la otra. Punteando una, la superposición se
        # ve como lo que es -- que es el resultado del experimento -- en vez
        # de parecer que falta una serie.
        guion = ' stroke-dasharray="7 5"' if j == 0 else ""
        partes.append(
            f'<polyline points="{puntos}" fill="none" stroke="{color}" stroke-width="2.5"{guion}/>'
        )
        for u in umbrales:
            partes.append(
                f'<circle cx="{ax(u):.1f}" cy="{ay(datos["grupos"][(variante, u)]["det_por_foto"]):.1f}" '
                f'r="4" fill="{color}"/>'
            )
        ly = ay0 + 14 + j * 20
        partes.append(f'<rect x="{ax0 + aw + 22}" y="{ly - 9}" width="11" height="11" rx="3" fill="{color}"/>')
        texto(ax0 + aw + 39, ly + 1, variante, 11)

    # ---------------- Panel B ----------------
    # El margen izquierdo es más ancho que el del panel A: acá el eje Y son
    # nombres de archivo, no números de dos dígitos.
    bx0, by0, bw, bh = 130, 320, W - 270, 170
    texto(bx0, 298, "B · Confianza de cada detección devuelta", 13, weight="bold")

    def bx(c):
        return bx0 + bw * c   # confianza de 0 a 1

    for i in range(6):
        c = i / 5
        partes.append(f'<line x1="{bx(c):.1f}" y1="{by0}" x2="{bx(c):.1f}" y2="{by0 + bh}" '
                      f'stroke="{COLOR_GUIA}" stroke-width="1"/>')
        texto(bx(c), by0 + bh + 18, f"{c:.1f}", 10, COLOR_TENUE, "middle")
    texto(bx0 + bw / 2, by0 + bh + 36, "confianza devuelta por el modelo", 10, COLOR_TENUE, "middle")

    # Las líneas de umbral pedido, punteadas.
    for u in umbrales:
        partes.append(
            f'<line x1="{bx(u):.1f}" y1="{by0 - 6}" x2="{bx(u):.1f}" y2="{by0 + bh}" '
            f'stroke="{COLOR_TEXTO}" stroke-width="1.2" stroke-dasharray="4 3"/>'
        )
        texto(bx(u), by0 - 11, f"{u:g}", 9.5, COLOR_TEXTO, "middle")

    # Un renglón por foto; dentro del renglón, un punto por detección.
    fotos = datos["fotos"]
    paso = bh / max(1, len(fotos))
    for i, foto in enumerate(fotos):
        y = by0 + paso * (i + 0.5)
        texto(bx0 - 8, y + 4, foto, 9.5, COLOR_TENUE, "end")
        for fila in datos["filas"]:
            if fila["foto"] != foto:
                continue
            conf = _f(fila["confianza"])
            if conf is None:
                continue
            color = colores.get(fila["variante"], COLOR_TENUE)
            partes.append(
                f'<circle cx="{bx(conf):.1f}" cy="{y:.1f}" r="4.5" fill="{color}" '
                f'fill-opacity="0.55" stroke="{color}" stroke-width="1"/>'
            )

    texto(bx0, H - 12,
          "Las líneas punteadas son los umbrales pedidos. Todo punto a su izquierda es una "
          "detección que ese umbral tendría que haber filtrado.",
          10, COLOR_TENUE)

    partes.append("</svg>")
    ruta.parent.mkdir(parents=True, exist_ok=True)
    ruta.write_text("\n".join(partes), encoding="utf-8")


# ----------------------------------------------------------------------
# Documento
# ----------------------------------------------------------------------

def tabla_resumen(datos: dict) -> str:
    filas = ["| Variante | confidence pedido | Fotos con detección | Detecciones | Det./foto | Confianza prom. | Confianza mín. | OCR leyó | Tiempo prom. | Peso prom. |",
             "|---|---|---|---|---|---|---|---|---|---|"]
    for variante in datos["variantes"]:
        for umbral in datos["umbrales"]:
            g = datos["grupos"][(variante, umbral)]
            prom = "—" if g["confianza_prom"] is None else f"{g['confianza_prom']:.3f}"
            minima = "—" if g["confianza_min"] is None else f"{g['confianza_min']:.3f}"
            filas.append(
                f"| {variante} | {umbral:g} | {g['fotos_con_deteccion']} de {g['fotos']} "
                f"| {g['detecciones']} | {g['det_por_foto']:.1f} | {prom} | {minima} "
                f"| {g['ocr_ok']} de {g['detecciones']} | {g['tiempo_prom']:.2f} s "
                f"| {g['kb_prom']:.0f} KB |"
            )
    return "\n".join(filas)


def tabla_por_foto(datos: dict) -> str:
    filas = ["| Foto | Variante | Detecciones | Confianza | OCR leyó |", "|---|---|---|---|---|"]
    for foto in datos["fotos"]:
        for variante in datos["variantes"]:
            # Todas las corridas de esta foto/variante dieron lo mismo si el
            # umbral no tuvo efecto; se toma la del umbral más alto.
            umbral = max(datos["umbrales"])
            propias = [f for f in datos["filas"]
                       if f["foto"] == foto and f["variante"] == variante
                       and float(f["threshold"]) == umbral]
            detecciones = [p for p in propias if p["indice_deteccion"] != ""]
            if not detecciones:
                filas.append(f"| {foto} | {variante} | 0 | — | — |")
                continue
            confs = ", ".join(f"{_f(d['confianza']):.3f}" for d in detecciones)
            ocr = "; ".join(d["marca_ocr"] or "(no leyó)" for d in detecciones)
            filas.append(f"| {foto} | {variante} | {len(detecciones)} | {confs} | {ocr} |")
    return "\n".join(filas)


def main() -> int:
    ruta = Path(sys.argv[1]) if len(sys.argv) > 1 else Path("resultados_threshold.csv")
    if not ruta.is_file():
        print(f"FALLÓ: no existe '{ruta}'.")
        print("       Generalo primero con:  python test_threshold.py <carpeta_con_fotos>")
        return 1

    datos = resumir(leer_csv(ruta))
    generar_svg(datos, SALIDA_SVG)

    tiene_efecto, sin_cambio, total_combinaciones = umbral_tiene_efecto(datos)
    bajo_umbral = detecciones_bajo_umbral(datos)

    if tiene_efecto:
        # El caso "normal": mover el umbral cambia lo que se detecta, así que
        # se puede elegir uno mirando la tabla.
        conclusion = (
            "El parámetro `confidence` **sí** cambia lo que devuelve el modelo: la cantidad de "
            f"detecciones varió en {total_combinaciones - sin_cambio} de las {total_combinaciones} "
            "combinaciones foto/variante. El umbral se puede elegir con la tabla de arriba, "
            "buscando el valor más alto que todavía no pierda detecciones válidas: subirlo de más "
            "genera falsos negativos (envases que la app no ve) y bajarlo de más mete detecciones "
            "basura que la persona tiene que descartar a mano en la Red de Seguridad."
        )
    else:
        conclusion = (
            "**No se eligió ningún umbral, porque el umbral no se puede elegir por código.** "
            f"En las {total_combinaciones} combinaciones foto/variante la cantidad de detecciones fue "
            "exactamente la misma con `confidence` en "
            + ", ".join(f"{u:g}" for u in datos["umbrales"]) + ": el parámetro no llega al modelo.\n\n"
            "La prueba directa está en el panel B del gráfico: "
            f"{len(bajo_umbral)} de las {sum(1 for f in datos['filas'] if f['indice_deteccion'] != '')} "
            "detecciones volvieron con una confianza MENOR al umbral que se había pedido. Si el "
            "filtro se estuviera aplicando, ninguna predicción por debajo del umbral podría llegar.\n\n"
            "La causa es que el step del modelo, en el editor visual de Roboflow, no tiene su campo "
            "`confidence` enlazado a un input del workflow, así que lo que manda "
            "`ejecutar_workflow_stock(..., confidence=X)` se ignora. Mientras siga así, el umbral hay "
            "que cambiarlo a mano en el editor de Roboflow y publicar el workflow de nuevo — y "
            "`CONFIDENCE_THRESHOLD` en `main.py` tampoco está teniendo el efecto que aparenta.\n\n"
            "Para el informe esto no es un resultado negativo: es el resultado. El experimento se "
            "diseñó para justificar un umbral con datos propios y lo que encontró fue que el umbral "
            "que se creía configurado no lo estaba. Justificar un número elegido a ojo habría sido "
            "escribir una conclusión falsa sobre datos reales."
        )

    fotos_txt = ", ".join(f"`{f}`" for f in datos["fotos"])
    total_det = sum(1 for f in datos["filas"] if f["indice_deteccion"] != "")

    documento = f"""# Barrido de umbral de confianza

> Generado por `analizar_threshold.py` a partir de `{ruta.name}`.
> Última corrida: {date.today().isoformat()}.
> Para rehacerlo: `python test_threshold.py <carpeta_con_fotos>` y después
> `python analizar_threshold.py`.

## Qué mide este experimento

Se corre el Workflow de Roboflow contra {len(datos['fotos'])} fotos reales ({fotos_txt}), cada una en dos versiones —tal cual sale de la cámara y recomprimida— y con {len(datos['umbrales'])} valores distintos de `confidence` ({", ".join(f"{u:g}" for u in datos['umbrales'])}): {len(datos['fotos']) * len(datos['variantes']) * len(datos['umbrales'])} corridas y {total_det} detecciones.

### Qué columnas tiene realmente el CSV

Conviene decirlo porque es distinto de lo que uno esperaría de un barrido de
umbral. `test_threshold.py` guarda:

`{"`, `".join(COLUMNAS_ESPERADAS)}`

**No hay ninguna columna de verdad de referencia**: el CSV no dice qué producto
era realmente cada foto ni cuántos envases había. Por eso acá no se puede
calcular *aciertos* ni *falsos positivos* — eso hace falta etiquetar las fotos
a mano, y no está hecho. Lo que sí se puede medir es cuántas detecciones
devuelve el modelo, con qué confianza, y si el OCR llegó a leer la etiqueta.
El acierto contra la corrección humana se mide por otro lado: en la tabla
`correcciones_ia`, que es de lo que vive el panel "Rendimiento del modelo de IA"
(`consultar_metricas_ia.php`).

### Antes de leer los números: qué modelo corrió

Qué modelo ejecuta el workflow **no se decide en este repositorio**: se elige en
el editor de Roboflow y puede cambiar sin que se toque una línea de código, lo
que cambia los resultados de arriba por completo. Antes de citar esta tabla en
el informe, verificar cuál estaba corriendo:

```
GET https://api.roboflow.com/{{workspace}}/workflows/{{workflow_id}}?api_key=...
```

y mirar `specification.steps[0].model_id`. Un `yolo*-640` a secas es un modelo
genérico entrenado en COCO (detecta "botella", "celular", "persona"); el del
proyecto es `products-vweue-1d62m-...`. La diferencia en tasa de detección
entre uno y otro es enorme — ver la tabla comparativa en `CLAUDE.md`.

## Resumen por variante y umbral

{tabla_resumen(datos)}

## Detalle por foto

{tabla_por_foto(datos)}

## El gráfico

![Barrido de umbral](umbral_confianza.svg)

*(`umbral_confianza.svg`, al lado de este archivo. Se abre en cualquier
navegador y Word 2016 en adelante lo importa como vectorial, así que no se
pixela al agrandarlo.)*

## Qué umbral se eligió y por qué

{conclusion}
"""

    SALIDA_MD.parent.mkdir(parents=True, exist_ok=True)
    SALIDA_MD.write_text(documento, encoding="utf-8")

    print(f"Escrito: {SALIDA_MD}")
    print(f"Escrito: {SALIDA_SVG}")
    print()
    print(f"El umbral {'SÍ' if tiene_efecto else 'NO'} tiene efecto "
          f"({sin_cambio} de {total_combinaciones} combinaciones sin ningún cambio).")
    if bajo_umbral:
        print(f"{len(bajo_umbral)} detecciones volvieron por debajo del umbral pedido.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
