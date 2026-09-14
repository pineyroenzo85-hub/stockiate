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

from fastapi import FastAPI, UploadFile, File, HTTPException, Request
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
    rol_de_sesion,
    PHP_BASE_URL,
    ChatbotIAAuthError,
    ChatbotIAConnectionError,
    ChatbotIADependenciaError,
    ChatbotIALimitError,
    ChatbotIAError,
    ROLES_VALIDOS,
)

app = FastAPI(title="stockIAte - Motor de IA")

# CORS con credenciales: el frontend manda la cookie de sesión de PHP a este
# servicio para que pueda reenviarla a los endpoints PHP (ver /chatbot).
#
# `allow_origins=["*"]` junto con `allow_credentials=True` NO funciona: el
# navegador rechaza la combinación comodín + credenciales. Por eso hay una
# lista explícita. Si cambia el dominio de ngrok, actualizala acá y en la
# constante ORIGENES_PERMITIDOS de sesion.php.
ORIGENES_PERMITIDOS = [
    "http://localhost",
    "http://127.0.0.1",
    "https://atrium-overtime-deluxe.ngrok-free.dev",
]

app.add_middleware(
    CORSMiddleware,
    allow_origins=ORIGENES_PERMITIDOS,
    allow_credentials=True,
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

# NO FILTRAR POR LA CLASE QUE DEVUELVE YOLO. Suena razonable descartar las
# detecciones cuya clase es "person" o "marker", pero este modelo es genérico
# (COCO) y sus etiquetas no tienen NADA que ver con lo que hay en el envase.
# Medido contra las fotos reales de images/:
#
#   images/local.jpeg -> clase 'remote'      , OCR "WET EFFECT EFFECTO HUMIDO"
#   images/hawas.png  -> clase 'cell phone'  , OCR "HAWAS For Him BLACK"
#
# Los dos son perfumes. Una denylist con "cell phone" adentro tiraba el
# segundo, con la marca leída perfecta y todo, y el repositor veía "no detectó
# nada". Como un frasco alto puede caer en 'person' igual que en 'remote',
# tampoco hay una sublista segura: la clase es ruido, no señal.
#
# Lo que sí filtra la basura es el candado de más abajo: la clase cruda nunca
# se usa como nombre, y sin OCR la detección llega como "sin identificar" y no
# se puede confirmar hasta que una persona elija el producto. Una mano en
# cuadro genera una tarjeta roja que se borra de un click, no un producto
# llamado "person".


class Deteccion(BaseModel):
    clase: str
    confianza: float
    marca: str = ""
    volumen: str = ""
    # Clase cruda que devolvió el modelo ('remote', 'cell phone', 'person'...).
    # Viaja al frontend para que se vea en la Red de Seguridad: es el único
    # dato que dice qué vio realmente el detector, y sin él diagnosticar por
    # qué una foto detecta o no es a ciegas. Es informativa: NO se usa como
    # nombre de producto (ver el bloque de abajo) ni para descartar nada.
    clase_yolo: str = ""
    # False = YOLO contó un envase pero el OCR no pudo leer la etiqueta. El
    # frontend tiene que pedirle al humano que elija el producto a mano y NO
    # dejar confirmar hasta que lo haga. Antes, en este caso se mandaba la
    # clase genérica de YOLO como si fuera el nombre leído.
    identificado: bool = True


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

    # La clase cruda de YOLO NUNCA sale de acá como nombre de producto: o el
    # OCR leyó algo (y eso es el candidato de nombre), o la detección viaja
    # como "sin identificar" para que la resuelva una persona. Antes se
    # mandaba `d.clase_generica` como fallback y el frontend lo prellenaba
    # como nombre, así que una foto con una mano en cuadro podía terminar
    # dando de alta un producto llamado "person" -- o, peor, un perfume real
    # dándose de alta como "cell phone" (ver el bloque de arriba).
    detecciones = [
        Deteccion(
            clase=d.marca,
            confianza=d.confianza,
            marca=d.marca,
            volumen=d.volumen,
            clase_yolo=d.clase_generica,
            identificado=bool(d.marca),
        )
        for d in detecciones_workflow
    ]

    sin_identificar = sum(1 for d in detecciones if not d.identificado)
    logger.info(
        "procesar-imagen: OK, %d detecciones (%d sin identificar), %.2fs total, clases_yolo=%s",
        len(detecciones), sin_identificar, time.monotonic() - t0,
        [d.clase_generica for d in detecciones_workflow],
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
    # `usuario_id` ya no se acepta: lo resuelve PHP desde la sesión.


@app.post("/registrar-correccion")
async def registrar_correccion(correccion: Correccion, request: Request):
    """
    El frontend llama esto cuando el repositor ajusta manualmente lo que
    detectó la IA en la pantalla de validación (Red de Seguridad).
    Este endpoint reenvía el dato al backend PHP para que quede en MySQL
    junto con el resto de la persistencia.

    Reenvía también la cookie de sesión: PHP necesita saber de qué negocio y
    de qué usuario es la corrección, y eso ya no viaja en el body.
    """
    cookie = request.headers.get("cookie")

    try:
        resp = requests.post(
            f"{PHP_BASE_URL}/registrar_correccion.php",
            json=correccion.model_dump(),
            headers={"Cookie": cookie} if cookie else {},
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
    historial: list[dict] | None = None
    # Sección desde la que se abrió el chat ("repositor" | "cajero" | "admin").
    # A diferencia del rol, éste SÍ puede venir del cliente: sólo recorta las
    # herramientas (intersección con las del rol), nunca las amplía. Ver
    # CONTEXT_TOOLS en chatbot_ia.py.
    contexto: str | None = None
    # `rol` y `usuario_id` YA NO se aceptan del cliente: salen de la sesión.
    # Ver el docstring de /chatbot.


class RespuestaChatbot(BaseModel):
    ok: bool
    respuesta: str
    herramientas_usadas: list[str] = []


@app.post("/chatbot", response_model=RespuestaChatbot)
async def chatbot(payload: PreguntaChatbot, request: Request):
    """
    Responde preguntas en lenguaje natural sobre stock, ventas y
    vencimientos usando Groq (tool-use) contra los endpoints PHP de solo
    lectura.

    QUIÉN ES EL USUARIO
    -------------------
    Antes el `rol` venía como campo del body y se le creía: cualquiera que le
    pegara directo a este endpoint podía mandar rol="dueño" y desbloquear
    todas las herramientas. Ahora el rol (y el negocio con el que se filtran
    los datos) salen de la sesión de servidor: el navegador manda su cookie
    acá, este endpoint la reenvía a PHP, y PHP resuelve quién es.

    Python sigue sin tocar MySQL: sólo copia un header opaco.

    No valida GROQ_API_KEY al arrancar el proceso (a diferencia de
    ROBOFLOW_API_KEY): si falta, este endpoint responde 503 pero el resto
    del backend (detección de imagen) sigue funcionando.
    """
    cookie = request.headers.get("cookie")
    rol = rol_de_sesion(cookie)

    if rol is None:
        raise HTTPException(status_code=401, detail="Necesitás iniciar sesión")

    if rol not in ROLES_VALIDOS:
        raise HTTPException(
            status_code=400,
            detail=f"Rol inválido. Debe ser uno de: {', '.join(ROLES_VALIDOS)}",
        )

    try:
        texto, herramientas_usadas = responder_pregunta(
            payload.pregunta,
            rol,
            historial=payload.historial,
            cookie=cookie,
            contexto=payload.contexto,
        )
    except ChatbotIALimitError as e:
        # 429 y no 502: la key esta bien y el servicio anda, lo que se acabo
        # es la cuota gratuita. Con otro codigo el mensaje del widget manda a
        # revisar el servidor, que es justo lo que no hay que tocar.
        logger.warning("Chatbot sin cupo en Groq: %s", e)
        raise HTTPException(
            status_code=429,
            detail=(
                "El asistente se quedó sin cupo en Groq por ahora. "
                "Probá de nuevo más tarde."
            ),
        )
    except ChatbotIADependenciaError as e:
        # 503 y no 500: el servicio anda perfecto, lo que falta es una
        # dependencia del chatbot. La detección de imagen no se ve afectada.
        raise HTTPException(status_code=503, detail=f"Chatbot no instalado: {e}")
    except ChatbotIAAuthError as e:
        raise HTTPException(status_code=503, detail=f"Chatbot no configurado: {e}")
    except ChatbotIAConnectionError as e:
        raise HTTPException(status_code=502, detail=f"No se pudo comunicar con el chatbot IA: {e}")
    except ChatbotIAError as e:
        raise HTTPException(status_code=502, detail=f"Error del chatbot IA: {e}")

    registrar_log(payload.pregunta, texto, herramientas_usadas, cookie=cookie)

    return RespuestaChatbot(ok=True, respuesta=texto, herramientas_usadas=herramientas_usadas)


if __name__ == "__main__":
    import uvicorn
    uvicorn.run("main:app", host="0.0.0.0", port=8000, reload=True)
