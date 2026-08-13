"""
stockIAte - Smoke test del Workflow de Roboflow
=================================================
Corre el workflow "text-recognition" real contra una imagen de muestra y
valida que la respuesta tenga la forma esperada. Pensado para correrse a
mano, no con pytest (el repo no tiene un runner de tests todavía).

Uso:
    python smoke_test_roboflow.py [ruta/a/una/imagen.jpg]

Si no pasás una imagen, genera una imagen sólida de prueba en memoria
(no vas a tener detecciones reales, pero sirve para confirmar que la
conexión, la auth y el parseo de la respuesta funcionan de punta a punta).
Para probar el OCR de marca de verdad, pasale una foto real de un
producto con la etiqueta legible.

IMPORTANTE: necesita un ROBOFLOW_API_KEY válido y con acceso al workspace
"cooppers-workspace" en el .env.
"""

import io
import sys

from dotenv import load_dotenv

load_dotenv()

from roboflow_workflow import (  # noqa: E402  (después de load_dotenv a propósito)
    ejecutar_workflow_stock,
    RoboflowWorkflowAuthError,
    RoboflowWorkflowConnectionError,
    RoboflowWorkflowError,
)


def _imagen_de_prueba_bytes() -> bytes:
    from PIL import Image

    img = Image.new("RGB", (640, 640), color=(180, 180, 180))
    buffer = io.BytesIO()
    img.save(buffer, format="JPEG")
    return buffer.getvalue()


def main() -> int:
    if len(sys.argv) > 1:
        ruta = sys.argv[1]
        with open(ruta, "rb") as f:
            imagen_bytes = f.read()
        print(f"Usando imagen: {ruta}")
    else:
        imagen_bytes = _imagen_de_prueba_bytes()
        print("No se pasó imagen; usando una imagen sólida generada en memoria.")

    try:
        detecciones = ejecutar_workflow_stock(imagen_bytes)
    except RoboflowWorkflowAuthError as e:
        print(f"FALLÓ (auth): {e}")
        return 1
    except RoboflowWorkflowConnectionError as e:
        print(f"FALLÓ (conexión): {e}")
        return 1
    except RoboflowWorkflowError as e:
        print(f"FALLÓ (respuesta inesperada del workflow): {e}")
        return 1

    # Aserciones mínimas de forma: es una lista, y si hay detecciones,
    # cada una tiene clase_generica (str), confianza (float 0-1) y marca (str).
    assert isinstance(detecciones, list), "Se esperaba una lista de detecciones"
    for d in detecciones:
        assert isinstance(d.clase_generica, str) and d.clase_generica, (
            "clase_generica debe ser un string no vacío"
        )
        assert 0.0 <= d.confianza <= 1.0, f"confianza fuera de rango: {d.confianza}"
        assert isinstance(d.marca, str), "marca debe ser un string (puede venir vacío)"

    print(f"OK: {len(detecciones)} detección(es).")
    for d in detecciones:
        marca_mostrada = d.marca if d.marca else "(sin marca reconocida)"
        print(f"  - {d.clase_generica}: {d.confianza:.2f} | marca: {marca_mostrada}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
