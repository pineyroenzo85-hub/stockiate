"""
stockIAte - Debug: respuesta cruda del Workflow de Roboflow
==============================================================
Corre el workflow y muestra la respuesta CRUDA (sin pasar por el parseo
de roboflow_workflow.py), para diagnosticar si el modelo no detecta nada
o si el problema es cómo estamos leyendo la respuesta.

Los campos que vienen en base64 (imágenes: output_image, dynamic_crop) se
truncan/omiten para no inundar la consola -- nunca los logueamos completos.

Uso:
    python debug_roboflow_raw.py "ruta\\a\\una\\foto.jpg" [confidence]

Ejemplo bajando el umbral de confianza para descartar que el modelo esté
detectando algo pero por debajo del default (0.4):
    python debug_roboflow_raw.py "ruta\\a\\una\\foto.jpg" 0.1
"""

import base64
import json
import sys

from dotenv import load_dotenv

load_dotenv()

from inference_sdk import InferenceHTTPClient

import roboflow_workflow as rw


def _achicar(obj):
    """Reemplaza cualquier string larga (probable base64) por un resumen,
    y no toca el resto de la estructura."""
    if isinstance(obj, dict):
        return {k: _achicar(v) for k, v in obj.items()}
    if isinstance(obj, list):
        return [_achicar(v) for v in obj]
    if isinstance(obj, str) and len(obj) > 200:
        return f"<string de {len(obj)} caracteres, omitida>"
    return obj


def main():
    if len(sys.argv) < 2:
        print("Uso: python debug_roboflow_raw.py \"ruta\\a\\una\\foto.jpg\" [confidence]")
        return 1

    ruta = sys.argv[1]
    confidence = float(sys.argv[2]) if len(sys.argv) > 2 else None

    with open(ruta, "rb") as f:
        imagen_bytes = f.read()

    print(f"Imagen: {ruta}")
    print(f"Workspace: {rw.WORKSPACE_NAME}  Workflow: {rw.WORKFLOW_ID}  API URL: {rw.API_URL}")
    if confidence is not None:
        print(f"Confidence forzado a: {confidence}")

    cliente = InferenceHTTPClient(api_url=rw.API_URL, api_key=rw.API_KEY)
    imagen_b64 = base64.b64encode(imagen_bytes).decode("utf-8")
    print(f"Tamaño del archivo: {len(imagen_bytes)} bytes")

    parametros = {"confidence": confidence} if confidence is not None else None

    resultado = cliente.run_workflow(
        workspace_name=rw.WORKSPACE_NAME,
        workflow_id=rw.WORKFLOW_ID,
        images={"image": imagen_b64},
        parameters=parametros,
    )

    print(f"\nTipo de 'resultado': {type(resultado)}")
    print(f"Cantidad de entradas (una por imagen de entrada): {len(resultado)}")

    primera = resultado[0]
    print(f"\nClaves del output para la imagen: {list(primera.keys())}")

    print("\n--- Respuesta completa (achicada) ---")
    print(json.dumps(_achicar(primera), indent=2, ensure_ascii=False))

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
