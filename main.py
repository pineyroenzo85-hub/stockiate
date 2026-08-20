"""
stockIAte - Backend FastAPI (Puente Lógico)
=============================================
Corre local. Recibe la imagen del celular, la manda a Roboflow (YOLO),
y devuelve el conteo detectado para que el frontend muestre la
"Red de Seguridad" (validación humana antes de guardar en MySQL).

Si Roboflow no responde (sin internet / falla de la nube), el endpoint
devuelve modo_offline=True para que el frontend caiga a carga manual
sin romper el flujo (offline-first).
"""

from fastapi import FastAPI, UploadFile, File, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from dotenv import load_dotenv
from PIL import Image
import requests
import io
import logging
import os
import time

# Logging de diagnóstico (timestamp, tamaño/dimensiones de imagen, timing de
# Roboflow) para poder correlacionar una prueba real desde el celular con lo
# que pasa en el pipeline, sin depender de la config propia de uvicorn.
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
logger = logging.getLogger("stockiate.main")

# Carga las variables definidas en el archivo .env (debe estar en la misma
# carpeta que este main.py) hacia el entorno del proceso. Tiene que correr
# ANTES de importar roboflow_workflow/chatbot_ia: esos módulos leen sus
# API keys con os.getenv(...) a nivel de módulo (una sola vez, al importar),
# así que si load_dotenv() corriera después, verían el entorno todavía sin
# las variables del .env.
load_dotenv()

from roboflow_workflow import (
    ejecutar_workflow_stock,
    RoboflowWorkflowAuthError,
    RoboflowWorkflowConnectionError,
    RoboflowWorkflowError,
)
from chatbot_ia import (
    responder_pregunta,
    registrar_log,
    ChatbotIAAuthError,
    ChatbotIAConnectionError,
    ChatbotIAError,
    ROLES_VALIDOS,
)

app = FastAPI(title="stockIAte - Motor de IA")

# CORS abierto para que el frontend (celular / navegador) pueda pegarle
# a este backend local sin problemas durante desarrollo.
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)

# --- Configuración de Roboflow ---
# La API key vive en el archivo .env (nunca hardcodeada en el código). El
# resto de la config del Workflow (workspace, workflow id, api url) tiene
# defaults en roboflow_workflow.py y también se puede overridear por .env.
if not os.getenv("ROBOFLOW_API_KEY"):
    raise RuntimeError(
        "Falta ROBOFLOW_API_KEY. Definila en el archivo .env (mismo directorio que main.py)."
    )

# Bajado desde el default del Workflow (~0.4) porque en el local real
# (mano tapando el producto, ángulos raros, luz distinta a la de
# entrenamiento) el threshold alto estaba descartando detecciones válidas.
# Elegido a mano tras comparar con test_threshold.py -- si en los logs de
# /procesar-imagen la cantidad de detecciones no cambia respecto al
# default, es señal de que el Workflow no tiene este parámetro wireado y
# hay que bajarlo también en el editor visual de Roboflow.
CONFIDENCE_THRESHOLD = 0.2



class Deteccion(BaseModel):
    clase: str
    confianza: float
    marca: str = ""
    volumen: str = ""


class RespuestaProcesamiento(BaseModel):
    ok: bool
    modo_offline: bool
    detecciones: list[Deteccion] = []
    conteo_total: int = 0
    mensaje: str = ""


@app.get("/")
def healthcheck():
    return {"status": "stockIAte backend activo"}


@app.post("/procesar-imagen", response_model=RespuestaProcesamiento)
async def procesar_imagen(imagen: UploadFile = File(...)):
    """
    Recibe la foto plana del lote de productos, la manda a Roboflow
    y devuelve el conteo por clase detectada (ej: 5x "Fragancia A 100ml").
    """
    t0 = time.monotonic()
    try:
        contenido = await imagen.read()
    except Exception:
        raise HTTPException(status_code=400, detail="No se pudo leer la imagen")

    try:
        ancho, alto = Image.open(io.BytesIO(contenido)).size
    except Exception:
        ancho, alto = None, None
    logger.info(
        "procesar-imagen: recibida (%d bytes, %sx%s)", len(contenido), ancho, alto
    )

    try:
        detecciones_workflow = ejecutar_workflow_stock(contenido, confidence=CONFIDENCE_THRESHOLD)
    except RoboflowWorkflowConnectionError:
        # --- MODO OFFLINE-FIRST ---
        # No hay conexión con el Workflow de Roboflow: el frontend debe
        # caer a carga manual sin romper el flujo.
        logger.warning(
            "procesar-imagen: modo offline tras %.2fs", time.monotonic() - t0
        )
        return RespuestaProcesamiento(
            ok=False,
            modo_offline=True,
            mensaje="Sin conexión con el motor de IA. Cambiando a carga manual.",
        )
    except RoboflowWorkflowAuthError as e:
        # Esto no es "sin conexión": la key está mal o no tiene acceso al
        # workflow. Mejor cortar acá con un 502 claro que caer a offline
        # silenciosamente y esconder un problema de configuración.
        logger.error("procesar-imagen: error de autenticación: %s", e)
        raise HTTPException(status_code=502, detail=f"Error de autenticación con Roboflow: {e}")
    except RoboflowWorkflowError as e:
        logger.error("procesar-imagen: error del workflow: %s", e)
        raise HTTPException(status_code=502, detail=f"Error del Workflow de Roboflow: {e}")

    # Diagnóstico de threshold: lo que llega acá ya viene filtrado
    # server-side con CONFIDENCE_THRESHOLD (si el Workflow tiene ese
    # parámetro wireado -- ver el comentario junto a CONFIDENCE_THRESHOLD).
    # "Cuántas entraron" y "cuántas pasaron el threshold" coinciden porque
    # ya mandamos el threshold nosotros. Para comparar contra otros
    # thresholds sobre las mismas fotos, usar test_threshold.py.
    logger.info(
        "procesar-imagen: confidence=%.2f, %d detecciones del workflow, confidences=%s",
        CONFIDENCE_THRESHOLD,
        len(detecciones_workflow),
        [round(d.confianza, 3) for d in detecciones_workflow],
    )

    detecciones = [
        Deteccion(
            clase=d.marca if d.marca else d.clase_generica,
            confianza=d.confianza,
            marca=d.marca,
            volumen=d.volumen,
        )
        for d in detecciones_workflow
    ]

    logger.info(
        "procesar-imagen: OK, %d detecciones, %.2fs total",
        len(detecciones), time.monotonic() - t0,
    )
    return RespuestaProcesamiento(
        ok=True,
        modo_offline=False,
        detecciones=detecciones,
        conteo_total=len(detecciones),
        mensaje="Procesado correctamente",
    )


# --- Feedback loop: guarda cada corrección que hace el repositor ---
class Correccion(BaseModel):
    producto_detectado_id: int | None = None
    producto_corregido_id: int | None = None
    cantidad_detectada: int
    cantidad_corregida: int
    confianza_ia: float | None = None
    usuario_id: int | None = None


@app.post("/registrar-correccion")
async def registrar_correccion(correccion: Correccion):
    """
    El frontend llama esto cuando el repositor ajusta manualmente lo que
    detectó la IA en la pantalla de validación (Red de Seguridad).
    Este endpoint reenvía el dato al backend PHP para que quede en MySQL
    junto con el resto de la persistencia.
    """
    try:
        resp = requests.post(
            "http://localhost/stockiate/tesis_enzo/registrar_correccion.php",
            json=correccion.model_dump(),
            timeout=5,
        )
        resp.raise_for_status()
    except requests.RequestException:
        raise HTTPException(
            status_code=502,
            detail="No se pudo guardar la corrección en la base local",
        )

    return {"ok": True}


# --- Chatbot IA (Groq, tool-use sobre datos reales vía PHP) ---
class PreguntaChatbot(BaseModel):
    pregunta: str
    rol: str
    usuario_id: int | None = None
    historial: list[dict] | None = None


class RespuestaChatbot(BaseModel):
    ok: bool
    respuesta: str
    herramientas_usadas: list[str] = []


@app.post("/chatbot", response_model=RespuestaChatbot)
async def chatbot(payload: PreguntaChatbot):
    """
    Responde preguntas en lenguaje natural sobre stock, ventas y
    vencimientos usando Groq (tool-use) contra los endpoints PHP de solo
    lectura. Las herramientas disponibles se restringen según `rol`
    (repositor / cajero / dueño) -- el rol lo determina el frontend según
    la página en la que está el usuario (no hay login en el proyecto, ver
    CLAUDE.md).

    No valida GROQ_API_KEY al arrancar el proceso (a diferencia de
    ROBOFLOW_API_KEY): si falta, este endpoint responde 503 pero el resto
    del backend (detección de imagen) sigue funcionando.
    """
    if payload.rol not in ROLES_VALIDOS:
        raise HTTPException(
            status_code=400,
            detail=f"Rol inválido. Debe ser uno de: {', '.join(ROLES_VALIDOS)}",
        )

    try:
        texto, herramientas_usadas = responder_pregunta(
            payload.pregunta,
            payload.rol,
            usuario_id=payload.usuario_id,
            historial=payload.historial,
        )
    except ChatbotIAAuthError as e:
        raise HTTPException(status_code=503, detail=f"Chatbot no configurado: {e}")
    except ChatbotIAConnectionError as e:
        raise HTTPException(status_code=502, detail=f"No se pudo comunicar con el chatbot IA: {e}")
    except ChatbotIAError as e:
        raise HTTPException(status_code=502, detail=f"Error del chatbot IA: {e}")

    registrar_log(payload.rol, payload.usuario_id, payload.pregunta, texto, herramientas_usadas)

    return RespuestaChatbot(ok=True, respuesta=texto, herramientas_usadas=herramientas_usadas)


if __name__ == "__main__":
    import uvicorn
    uvicorn.run("main:app", host="0.0.0.0", port=8000, reload=True)
