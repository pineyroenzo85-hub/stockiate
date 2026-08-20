"""
stockIAte - Testing de threshold de confianza y compresión sobre fotos reales
================================================================================
Corre el Workflow "text-recognition" contra una carpeta de fotos reales
(condiciones de local: mano tapando, ángulos raros, luz mala) probando:
  - varios valores de `confidence` (0.5, 0.4, 0.3, 0.2)
  - cada foto en su versión "original" (tal cual la subís) y "comprimida"
    (redimensionada + recomprimida como JPEG, simulando una foto de PC en
    vez de la del celular -- ver LADO_MAX_COMPRIMIDA / CALIDAD_COMPRIMIDA)

Vuelca todo a un CSV para poder comparar y elegir a ojo el threshold
óptimo y si comprimir antes de subir ayuda o no (minimizar falsos
negativos sin meter demasiada basura, sin romper el OCR de marca por
comprimir de más).

No toca el pipeline de producción -- ni el threshold que ya se aplicó en
main.py (CONFIDENCE_THRESHOLD) ni el envío de la imagen tal cual llega del
celular. Este script es solo para generar los datos y decidir si vale la
pena comprimir antes de subir.

Uso:
    python test_threshold.py <carpeta_con_fotos> [archivo_salida.csv]

Ejemplo:
    python test_threshold.py fotos_prueba_local resultados_threshold.csv

Requiere .env con ROBOFLOW_API_KEY (igual que smoke_test_roboflow.py).
"""

import csv
import sys
import time
from collections import defaultdict
from io import BytesIO
from pathlib import Path

from dotenv import load_dotenv

load_dotenv()

from PIL import Image  # noqa: E402  (después de load_dotenv a propósito)

from roboflow_workflow import (  # noqa: E402
    ejecutar_workflow_stock,
    RoboflowWorkflowError,
)

THRESHOLDS = [0.5, 0.4, 0.3, 0.2]
VARIANTES = ["original", "comprimida"]
EXTENSIONES_VALIDAS = {".jpg", ".jpeg", ".png"}

# Parámetros de la variante "comprimida": lado mayor tope y calidad JPEG.
# Pensado para simular una foto "de PC" en vez de la de cámara de celular
# (mucha más resolución/detalle de la que el modelo necesita). Si la
# comprimida rompe el OCR de marca (marca_ocr vacía más seguido que en la
# original), es señal de que se está yendo de mano con la compresión.
LADO_MAX_COMPRIMIDA = 1280
CALIDAD_COMPRIMIDA = 80

CSV_COLUMNAS = [
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


def _listar_fotos(carpeta: Path) -> list[Path]:
    return sorted(
        p for p in carpeta.iterdir()
        if p.is_file() and p.suffix.lower() in EXTENSIONES_VALIDAS
    )


def _comprimir_imagen(imagen_bytes: bytes) -> bytes:
    """Redimensiona (lado mayor <= LADO_MAX_COMPRIMIDA) y recomprime como
    JPEG a CALIDAD_COMPRIMIDA, simulando una foto de menor calidad/resolución."""
    img = Image.open(BytesIO(imagen_bytes)).convert("RGB")
    ancho, alto = img.size
    lado_actual = max(ancho, alto)
    if lado_actual > LADO_MAX_COMPRIMIDA:
        factor = LADO_MAX_COMPRIMIDA / lado_actual
        img = img.resize(
            (max(1, round(ancho * factor)), max(1, round(alto * factor))),
            Image.LANCZOS,
        )
    buffer = BytesIO()
    img.save(buffer, format="JPEG", quality=CALIDAD_COMPRIMIDA)
    return buffer.getvalue()


def _bytes_por_variante(imagen_original: bytes, variante: str) -> bytes:
    if variante == "original":
        return imagen_original
    return _comprimir_imagen(imagen_original)


def _correr_una_combinacion(
    foto_nombre: str, imagen_bytes: bytes, threshold: float
) -> tuple[list, float, str | None]:
    """Devuelve (filas_csv, tiempo_seg, error_o_None) para una foto+variante+threshold."""
    t0 = time.monotonic()
    try:
        detecciones = ejecutar_workflow_stock(imagen_bytes, confidence=threshold)
    except RoboflowWorkflowError as e:
        return [], time.monotonic() - t0, str(e)

    tiempo = time.monotonic() - t0
    base = {
        "foto": foto_nombre,
        "threshold": threshold,
        "tiempo_respuesta_seg": round(tiempo, 2),
        "tamano_bytes": len(imagen_bytes),
    }

    if not detecciones:
        fila = dict(base, cantidad_detecciones=0, indice_deteccion="",
                    confianza="", marca_ocr="", ocr_exitoso="")
        return [fila], tiempo, None

    filas = [
        dict(
            base,
            cantidad_detecciones=len(detecciones),
            indice_deteccion=i,
            confianza=round(d.confianza, 4),
            marca_ocr=d.marca,
            ocr_exitoso=bool(d.marca),
        )
        for i, d in enumerate(detecciones)
    ]
    return filas, tiempo, None


def _stats(filas: list[dict]) -> dict:
    total_detecciones = sum(1 for f in filas if f["indice_deteccion"] != "")
    ocr_exitosos = sum(1 for f in filas if f["ocr_exitoso"] is True)
    confidences = [f["confianza"] for f in filas if f["confianza"] != ""]
    tiempos = [f["tiempo_respuesta_seg"] for f in filas]
    tamanos = [f["tamano_bytes"] for f in filas]
    fotos = {f["foto"] for f in filas}
    return {
        "detecciones_totales": total_detecciones,
        "detecciones_por_foto": total_detecciones / len(fotos) if fotos else 0,
        "confianza_prom": sum(confidences) / len(confidences) if confidences else 0,
        "ocr_rate": ocr_exitosos / total_detecciones if total_detecciones else 0,
        "tiempo_prom": sum(tiempos) / len(tiempos) if tiempos else 0,
        "tamano_prom_kb": (sum(tamanos) / len(tamanos) / 1024) if tamanos else 0,
    }


def _imprimir_resumen(filas: list[dict]) -> None:
    print("\n" + "=" * 70)
    print("RESUMEN POR VARIANTE x THRESHOLD")
    print("=" * 70)

    grupos: dict[tuple[str, float], list[dict]] = defaultdict(list)
    for fila in filas:
        grupos[(fila["variante"], fila["threshold"])].append(fila)

    for variante in VARIANTES:
        print(f"\n--- variante: {variante} ---")
        for threshold in sorted(THRESHOLDS, reverse=True):
            filas_grupo = grupos.get((variante, threshold), [])
            if not filas_grupo:
                continue
            s = _stats(filas_grupo)
            print(
                f"  threshold={threshold}: {s['detecciones_totales']} detecciones "
                f"({s['detecciones_por_foto']:.1f}/foto), confidence prom={s['confianza_prom']:.3f}, "
                f"OCR ok={s['ocr_rate']:.0%}, tiempo prom={s['tiempo_prom']:.2f}s, "
                f"tamaño prom={s['tamano_prom_kb']:.0f}KB"
            )

    # Comparación directa original vs. comprimida (agregando todos los thresholds)
    print("\n" + "-" * 70)
    print("ORIGINAL vs. COMPRIMIDA (agregado en todos los thresholds)")
    print("-" * 70)
    for variante in VARIANTES:
        filas_variante = [f for f in filas if f["variante"] == variante]
        if not filas_variante:
            continue
        s = _stats(filas_variante)
        print(
            f"  {variante:12s}: {s['detecciones_por_foto']:.1f} detecciones/foto, "
            f"confidence prom={s['confianza_prom']:.3f}, OCR ok={s['ocr_rate']:.0%}, "
            f"tiempo prom={s['tiempo_prom']:.2f}s, tamaño prom={s['tamano_prom_kb']:.0f}KB"
        )
    print(
        "\n  Si 'comprimida' tiene similar o más detecciones/foto y OCR ok "
        "parecido o mejor que 'original', comprimir antes de subir ayuda "
        "(y de paso sube más rápido). Si el OCR ok de 'comprimida' cae "
        "fuerte contra 'original', se está comprimiendo de más y se pierde "
        "texto legible de la etiqueta."
    )

    # Chequeo: ¿el parámetro `confidence` tiene efecto real sobre el Workflow?
    print("\n" + "-" * 70)
    cantidad_por_clave: dict[str, dict[float, int]] = defaultdict(dict)
    for fila in filas:
        clave = f"{fila['foto']}|{fila['variante']}"
        cantidad_por_clave[clave][fila["threshold"]] = fila["cantidad_detecciones"] or 0

    total_claves = len(cantidad_por_clave)
    sin_variacion = sum(1 for por_th in cantidad_por_clave.values() if len(set(por_th.values())) <= 1)

    if total_claves and sin_variacion / total_claves >= 0.5:
        print(
            "[ADVERTENCIA] en la mayoría de las combinaciones foto/variante la "
            "cantidad de detecciones NO cambió al variar `confidence` entre "
            f"{max(THRESHOLDS)} y {min(THRESHOLDS)}."
        )
        print(
            "   Esto sugiere que el parámetro `confidence` pasado por código NO "
            "está teniendo efecto sobre el Workflow -- probablemente el step del "
            "modelo en el editor de Roboflow no tiene su campo de confidence "
            "enlazado a un workflow input llamado 'confidence'."
        )
        print(
            "   En ese caso, el threshold hay que cambiarlo a mano en el editor "
            "visual de Roboflow (y hacer Deploy/Publish), no por código -- "
            "revisar también si CONFIDENCE_THRESHOLD en main.py está teniendo "
            "efecto real mirando los logs de /procesar-imagen."
        )
    else:
        print(
            "[OK] El parámetro `confidence` sí parece afectar la cantidad de "
            "detecciones -- se puede ajustar por código "
            "(ejecutar_workflow_stock(..., confidence=X), o CONFIDENCE_THRESHOLD en main.py)."
        )
    print("-" * 70)


def main() -> int:
    if len(sys.argv) < 2:
        print("Uso: python test_threshold.py <carpeta_con_fotos> [archivo_salida.csv]")
        return 1

    carpeta = Path(sys.argv[1])
    if not carpeta.is_dir():
        print(f"FALLÓ: '{carpeta}' no es una carpeta válida")
        return 1

    salida = Path(sys.argv[2]) if len(sys.argv) > 2 else Path("resultados_threshold.csv")

    fotos = _listar_fotos(carpeta)
    if not fotos:
        print(f"FALLÓ: no se encontraron fotos ({', '.join(EXTENSIONES_VALIDAS)}) en '{carpeta}'")
        return 1

    total_combinaciones = len(fotos) * len(VARIANTES) * len(THRESHOLDS)
    print(f"Fotos encontradas: {len(fotos)}")
    print(f"Variantes: {VARIANTES} (comprimida: lado_max={LADO_MAX_COMPRIMIDA}px, calidad={CALIDAD_COMPRIMIDA})")
    print(f"Thresholds a probar: {THRESHOLDS}")
    print(f"Total de combinaciones: {total_combinaciones}\n")

    todas_las_filas: list[dict] = []
    errores: list[str] = []

    for foto in fotos:
        imagen_original = foto.read_bytes()
        for variante in VARIANTES:
            try:
                imagen_bytes = _bytes_por_variante(imagen_original, variante)
            except Exception as e:
                print(f"  -> {foto.name} [{variante}]: ERROR al preparar imagen: {e}")
                errores.append(f"{foto.name} [{variante}]: preparar imagen: {e}")
                continue

            for threshold in THRESHOLDS:
                print(f"  -> {foto.name} [{variante}] @ confidence={threshold} ...", end=" ", flush=True)
                filas, tiempo, error = _correr_una_combinacion(foto.name, imagen_bytes, threshold)
                if error:
                    print(f"ERROR ({tiempo:.2f}s): {error}")
                    errores.append(f"{foto.name} [{variante}] @ {threshold}: {error}")
                    continue
                for fila in filas:
                    fila["variante"] = variante
                cantidad = filas[0]["cantidad_detecciones"] if filas else 0
                print(f"OK ({tiempo:.2f}s, {cantidad} detección(es))")
                todas_las_filas.extend(filas)

    if not todas_las_filas:
        print("\nFALLÓ: ninguna combinación foto/variante/threshold produjo resultados.")
        return 1

    with salida.open("w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=CSV_COLUMNAS)
        writer.writeheader()
        writer.writerows(todas_las_filas)

    print(f"\nCSV guardado en: {salida.resolve()}")

    if errores:
        print(f"\n{len(errores)} combinación(es) fallaron:")
        for e in errores:
            print(f"  - {e}")

    _imprimir_resumen(todas_las_filas)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
