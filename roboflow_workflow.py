"""
stockIAte - Cliente del Workflow de Roboflow
=============================================
Encapsula la llamada al Workflow publicado en Roboflow usando el SDK
oficial `inference-sdk` (InferenceHTTPClient.run_workflow).

Pipeline real del workflow "text-recognition" (workspace cooppers-workspace),
confirmado con el JSON de definición exportado desde Roboflow:

  input:  image (WorkflowImage)
  steps:
    1. model                -> roboflow_object_detection_model@v3
                                (model_id: cooppers-workspace/products-vweue-1d62m-1-yolov8n-t1)
                                detecta envases de forma genérica
    2. dynamic_crop          -> recorta cada detección de la imagen original
    3. glm_ocr                -> lee texto (marca) sobre cada recorte
    4. detection_visualization / annotated_image -> imagen anotada (no la usamos por ahora)

  outputs declarados:
    - predictions       -> $steps.model.predictions        (detecciones YOLO, una lista)
    - dynamic_crop        -> $steps.dynamic_crop.crops       (imágenes recortadas, no las usamos)
    - recognized_text     -> $steps.glm_ocr.parsed_output     (texto OCR por recorte)
    - output_image        -> $steps.annotated_image.image     (imagen con boxes, no la usamos por ahora)

Como dynamic_crop y glm_ocr corren en batch sobre las N detecciones de una
imagen, asumimos que "predictions" (lista de N detecciones) y
"recognized_text" (lista de N resultados OCR) vienen alineados por índice:
recognized_text[i] es el texto leído sobre el recorte de predictions[i].

⚠️ Mandamos la imagen como string base64 (no como objeto PIL.Image) porque
con inference-sdk==1.2.0 se detectó que pasar un PIL.Image a run_workflow
no serializaba bien la imagen: la respuesta volvía con predictions vacío y
width/height en null, incluso con fotos que sí detectaban productos al
correr el mismo workflow desde el simulador de Roboflow. Si algún día
actualizás inference-sdk y querés volver a probar con PIL.Image/numpy,
hacelo con cautela y comparando contra el simulador.

No se pudo capturar una respuesta real de ejemplo (falla de conexión con
la herramienta MCP al momento de integrar esto), así que `_extraer_texto`
acepta varias formas plausibles de cada entrada de recognized_text (string
plano, o dict con alguna clave típica como "text"/"content"/"parsed_output"
/"output"). Si el smoke test te devuelve una marca vacía o rara, avisá para
ajustar `_extraer_texto` a la forma exacta que devuelve glm_ocr.
"""

from __future__ import annotations

import base64
import os
import time
from dataclasses import dataclass

from inference_sdk import InferenceHTTPClient

from ocr_parser import parsear_texto_ocr

WORKSPACE_NAME = os.getenv("ROBOFLOW_WORKSPACE_NAME", "cooppers-workspace")
WORKFLOW_ID = os.getenv("ROBOFLOW_WORKFLOW_ID", "text-recognition")
API_URL = os.getenv("ROBOFLOW_API_URL", "https://serverless.roboflow.com")
API_KEY = os.getenv("ROBOFLOW_API_KEY")

_MAX_REINTENTOS = 2
_ESPERA_BASE_SEGUNDOS = 1.5


class RoboflowWorkflowError(Exception):
    """Error base de configuración o comunicación con el Workflow de Roboflow."""


class RoboflowWorkflowAuthError(RoboflowWorkflowError):
    """La API key no es válida o no tiene acceso a este workspace/workflow. No reintentable."""


class RoboflowWorkflowConnectionError(RoboflowWorkflowError):
    """No se pudo llegar al servicio (timeout / sin conexión) tras reintentar."""


@dataclass
class DeteccionProducto:
    clase_generica: str   # clase que devuelve el modelo YOLO (genérica, ej: "product")
    confianza: float      # confidence de la detección del envase
    marca: str            # texto de marca/nombre YA sanitizado (ver ocr_parser.py),
                           # candidato de 3-4 palabras clave -- puede venir vacío
    volumen: str = ""     # contenido/volumen detectado en la etiqueta, ej: "150ml"


def _cliente() -> InferenceHTTPClient:
    if not API_KEY:
        raise RoboflowWorkflowError("Falta ROBOFLOW_API_KEY en el entorno (.env).")
    return InferenceHTTPClient(api_url=API_URL, api_key=API_KEY)


def ejecutar_workflow_stock(
    imagen_bytes: bytes,
    *,
    confidence: float | None = None,
) -> list[DeteccionProducto]:
    """
    Corre el Workflow "text-recognition" sobre una imagen: detecta envases y
    lee la marca de cada uno por OCR. Devuelve una lista de DeteccionProducto
    (una por envase detectado), lista para mapear en main.py.

    Reintenta con backoff exponencial solo ante fallas de conexión/timeout.
    Los errores de autenticación fallan rápido, sin reintentar.
    """
    cliente = _cliente()
    imagen_b64 = base64.b64encode(imagen_bytes).decode("utf-8")

    parametros = {"confidence": confidence} if confidence is not None else None

    ultimo_error: Exception | None = None

    for intento in range(_MAX_REINTENTOS + 1):
        try:
            resultado = cliente.run_workflow(
                workspace_name=WORKSPACE_NAME,
                workflow_id=WORKFLOW_ID,
                images={"image": imagen_b64},
                parameters=parametros,
            )
            return _parsear_resultado(resultado)

        except Exception as e:  # noqa: BLE001 - inference-sdk no expone jerarquía propia de excepciones
            mensaje = str(e).lower()
            if "unauthorized" in mensaje or "401" in mensaje or "api key" in mensaje:
                raise RoboflowWorkflowAuthError(
                    f"Roboflow rechazó la API key para {WORKSPACE_NAME}/{WORKFLOW_ID}: {e}"
                ) from e

            ultimo_error = e
            if intento < _MAX_REINTENTOS:
                time.sleep(_ESPERA_BASE_SEGUNDOS * (2**intento))
                continue

    raise RoboflowWorkflowConnectionError(
        f"No se pudo ejecutar el workflow tras {_MAX_REINTENTOS + 1} intento(s): {ultimo_error}"
    ) from ultimo_error


def _extraer_texto(item) -> str:
    """
    Normaliza una entrada de 'recognized_text' a un string limpio.
    Acepta string plano o dict con alguna clave típica de texto.
    """
    if item is None:
        return ""
    if isinstance(item, str):
        return item.strip()
    if isinstance(item, dict):
        for clave in ("text", "content", "parsed_output", "output", "value", "result"):
            valor = item.get(clave)
            if isinstance(valor, str):
                return valor.strip()
        for valor in item.values():
            if isinstance(valor, str):
                return valor.strip()
    return str(item).strip()


def _parsear_resultado(resultado) -> list[DeteccionProducto]:
    """
    `resultado` es una lista con una entrada por imagen de entrada (acá
    mandamos una sola). Esa entrada es un dict keyed por los nombres de
    salida que declara el propio workflow: predictions, dynamic_crop,
    recognized_text, output_image. Solo leemos predictions y
    recognized_text -- no cargamos dynamic_crop (recortes) ni output_image
    (imagen anotada) porque no los usamos, y son los pesados (base64).
    """
    if not resultado:
        return []

    primera_imagen = resultado[0]
    bloque_predicciones = primera_imagen.get("predictions")

    if bloque_predicciones is None:
        raise RoboflowWorkflowError(
            "La respuesta del workflow no trae la clave 'predictions'. "
            f"Claves recibidas: {list(primera_imagen.keys())}"
        )

    if isinstance(bloque_predicciones, dict):
        lista_predicciones = bloque_predicciones.get("predictions", [])
    else:
        lista_predicciones = bloque_predicciones

    # El workflow puede tener el step de lectura de marca bajo distintos
    # nombres de output según cómo lo hayas armado en el editor (glm_ocr ->
    # "recognized_text", un step tipo OpenAI -> "open_ai", etc). Probamos
    # las claves conocidas en orden y nos quedamos con la primera que
    # exista, para no romper si renombrás el step de vuelta.
    bloque_textos = (
        primera_imagen.get("recognized_text")
        or primera_imagen.get("open_ai")
        or []
    )
    if not isinstance(bloque_textos, list):
        bloque_textos = [bloque_textos]

    detecciones: list[DeteccionProducto] = []
    for i, p in enumerate(lista_predicciones):
        texto_crudo = _extraer_texto(bloque_textos[i]) if i < len(bloque_textos) else ""
        # El OCR lee TODO el texto visible del envase (ingredientes, modo de
        # uso, lote, etc.), no solo la marca -- lo sanitizamos antes de que
        # llegue a main.py/la Red de Seguridad. Ver ocr_parser.py.
        parsed = parsear_texto_ocr(texto_crudo)
        detecciones.append(
            DeteccionProducto(
                clase_generica=p.get("class", "producto"),
                confianza=float(p.get("confidence", 0.0)),
                marca=parsed.texto_limpio,
                volumen=parsed.volumen,
            )
        )
    return detecciones
