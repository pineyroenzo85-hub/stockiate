"""
stockIAte - Chatbot IA (asistente de consultas sobre inventario y ventas)
=============================================
Encapsula la llamada a la API de Groq (inferencia gratuita sobre modelos
open-source, con límites de uso) con tool-use: el modelo elige entre un set
fijo de "herramientas" que consultan datos reales vía los endpoints PHP de
solo lectura (consultar_stock.php, consultar_ventas.php,
consultar_vencimientos.php, consultar_productos.php,
consultar_rentabilidad.php). Python nunca toca MySQL directamente -- la
persistencia sigue siendo responsabilidad de PHP, ver CLAUDE.md.

No se usa texto-a-SQL libre: las herramientas son fijas y cada una ejecuta
una query ya escrita y parametrizada del lado PHP, así se evita el riesgo de
que el modelo genere SQL arbitrario.

La API de Groq es compatible con el formato de tool-calling de OpenAI
(Chat Completions): mensajes con role user/assistant/tool, tools declaradas
como {"type": "function", "function": {...}}, y las respuestas con
`tool_calls` en el mensaje del asistente.
"""

from __future__ import annotations

import json
import logging
import os

import requests

# `groq` se importa DENTRO de _cliente_para(), no acá arriba. Es a propósito:
# este módulo lo importa main.py al arrancar, así que un `import groq` a nivel
# de módulo convierte al paquete en requisito para levantar TODO el servicio.
# Pasó de verdad: un venv creado antes de que `groq` entrara en
# requirements.txt tiraba `ModuleNotFoundError` al arrancar uvicorn, el proceso
# moría sin bindear el puerto, Apache devolvía 503 en /procesar-imagen, y el
# síntoma era "la IA no detecta nada" -- con la detección de imagen intacta y
# sin ninguna relación con el chatbot.
#
# Es el mismo criterio que ya se aplicaba a GROQ_API_KEY (validación lazy, ver
# el docstring de /chatbot en main.py): si falta algo del chatbot, sólo
# /chatbot falla; el resto del backend sigue de pie.
#
# Las anotaciones `-> Groq` de más abajo no rompen porque el módulo tiene
# `from __future__ import annotations`: no se evalúan en tiempo de import.

logger = logging.getLogger("stockiate.chatbot")

MODEL = os.getenv("GROQ_MODEL", "openai/gpt-oss-20b")
PHP_BASE_URL = os.getenv("PHP_BASE_URL", "http://localhost/stockiate/tesis_enzo")

_MAX_ITERACIONES_TOOL_USE = 5
# Hasta GROQ_API_KEY_9. Es un barrido de rango fijo y no un while que corta
# en el primer hueco: si alguien define la _2 y la _4 pero se olvida la _3,
# la _4 se usa igual en vez de quedar ignorada en silencio.
_MAX_CLAVES = 9
_TIMEOUT_PHP_SEGUNDOS = 5

ROLES_VALIDOS = ("repositor", "cajero", "dueño")


class ChatbotIAError(Exception):
    """Error base de configuración o comunicación con el chatbot IA."""


class ChatbotIAAuthError(ChatbotIAError):
    """Falta o es inválida la GROQ_API_KEY. No reintentable."""


class ChatbotIAConnectionError(ChatbotIAError):
    """No se pudo comunicar con la API de Groq."""


class ChatbotIALimitError(ChatbotIAError):
    """Se agotó el cupo (429) de todas las API keys configuradas."""


class ChatbotIADependenciaError(ChatbotIAError):
    """No está instalado el paquete `groq`. No reintentable: se arregla con
    `pip install -r requirements.txt`, no reintentando la llamada."""


# Clave que se viene usando, entre las de _claves(). Es sticky a proposito:
# una vez que la primera se queda sin cupo, arrancar cada pregunta por ella
# significaria pagar un 429 al pedo en cada mensaje. Cuando la de repuesto
# tambien se agote, el modulo da la vuelta y reintenta la primera, que para
# entonces lo mas probable es que ya se le haya reseteado la ventana.
_indice_clave = 0
_clientes: dict[str, Groq] = {}


def _claves() -> list[str]:
    """
    Las API keys de Groq disponibles, en orden de preferencia:
    GROQ_API_KEY primero y despues GROQ_API_KEY_2 .. GROQ_API_KEY_9.

    Se lee del entorno en cada llamada, no al importar el modulo: si se edita
    el .env, el --reload de uvicorn alcanza para tomarlo. Leyendolo arriba
    quedaba cacheada la key vieja y Groq devolvia 401 invalid_api_key con un
    .env ya corregido.
    """
    crudas = [os.getenv("GROQ_API_KEY")]
    crudas += [os.getenv(f"GROQ_API_KEY_{n}") for n in range(2, _MAX_CLAVES + 1)]

    claves: list[str] = []
    for cruda in crudas:
        clave = (cruda or "").strip()
        # El dedup evita que una key repetida en el .env se cuente como
        # repuesto y coma un reintento contra el mismo cupo ya agotado.
        if clave and clave not in claves:
            claves.append(clave)
    return claves


def _cliente_para(clave: str) -> Groq:
    # El import va acá y no arriba: ver el comentario junto a `import requests`.
    try:
        from groq import Groq
    except ImportError as e:
        raise ChatbotIADependenciaError(
            "Falta el paquete `groq`. Instalalo con: pip install -r requirements.txt"
        ) from e

    if clave not in _clientes:
        _clientes[clave] = Groq(api_key=clave)
    return _clientes[clave]


def _codigo_http(e: Exception) -> int | None:
    """Status HTTP de un error del SDK, sin acoplarse a su jerarquia de clases."""
    codigo = getattr(e, "status_code", None)
    if isinstance(codigo, int):
        return codigo
    codigo = getattr(getattr(e, "response", None), "status_code", None)
    return codigo if isinstance(codigo, int) else None


def _sin_cupo(e: Exception) -> bool:
    """La key es valida pero se le acabo la cuota / rate limit (429)."""
    if _codigo_http(e) == 429:
        return True
    texto = str(e).lower()
    return any(t in texto for t in ("429", "rate limit", "rate_limit", "quota"))


def _clave_rechazada(e: Exception) -> bool:
    """Groq no acepta la key: revocada, mal copiada o de otra cuenta."""
    if _codigo_http(e) in (401, 403):
        return True
    texto = str(e).lower()
    return any(
        t in texto
        for t in ("401", "invalid api key", "invalid_api_key", "authentication")
    )


def _completar(mensajes: list[dict], tools: list[dict]):
    """
    Una llamada a Groq, rotando de API key si hace falta.

    Prueba las claves de _claves() arrancando por la que quedo activa. Si una
    responde 429 (sin cupo) o la rechazan, sigue con la siguiente; la primera
    que contesta queda como activa para las proximas llamadas. Si ninguna
    anda, distingue "se acabo el cupo" de "las keys estan mal", porque lo que
    hay que hacer en cada caso no es lo mismo.
    """
    global _indice_clave

    claves = _claves()
    if not claves:
        raise ChatbotIAAuthError("Falta GROQ_API_KEY en el entorno (.env).")

    fallos: list[tuple[int, str, Exception]] = []

    for intento in range(len(claves)):
        indice = (_indice_clave + intento) % len(claves)
        try:
            respuesta = _cliente_para(claves[indice]).chat.completions.create(
                model=MODEL,
                messages=mensajes,
                tools=tools,
                tool_choice="auto",
                max_tokens=1024,
            )
        except Exception as e:  # noqa: BLE001 - ver nota en responder_pregunta
            if _sin_cupo(e):
                motivo = "cupo"
            elif _clave_rechazada(e):
                motivo = "auth"
            else:
                # Timeout, corte de red, 500 de Groq: cambiar de key no
                # arregla nada. Que lo clasifique responder_pregunta.
                raise
            fallos.append((indice, motivo, e))
            logger.warning(
                "Groq: la API key #%d fallo (%s). Probando la siguiente. Detalle: %s",
                indice + 1,
                motivo,
                e,
            )
            continue

        if indice != _indice_clave:
            logger.info("Groq: pasando a la API key #%d.", indice + 1)
            _indice_clave = indice
        return respuesta

    sin_cupo = [f for f in fallos if f[1] == "cupo"]
    if sin_cupo:
        raise ChatbotIALimitError(
            f"Se agoto el cupo de Groq ({len(sin_cupo)} de {len(claves)} "
            f"API keys sin cupo). Ultimo error: {sin_cupo[-1][2]}"
        )
    raise ChatbotIAAuthError(
        f"Groq rechazo las {len(claves)} API keys configuradas. "
        f"Ultimo error: {fallos[-1][2]}"
    )


# --- Definición de herramientas (tool-use) ---
# Cada tool mapea 1:1 a un endpoint PHP de solo lectura. El schema ya está en
# el formato JSON Schema que espera Groq/OpenAI (parameters), no hace falta
# traducirlo.
_TOOLS = [
    {
        "name": "consultar_stock",
        "description": (
            "Consulta el stock actual de productos del catálogo. Usala para "
            "preguntas sobre cuánto stock hay, qué productos tienen stock "
            "bajo/crítico, o buscar el stock de un producto/marca puntual."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "termino": {
                    "type": "string",
                    "description": "Nombre, marca o SKU a buscar (opcional).",
                },
                "categoria": {
                    "type": "string",
                    "description": "Categoría exacta para filtrar (opcional).",
                },
                "solo_bajo": {
                    "type": "boolean",
                    "description": "Si es true, devuelve solo productos con stock_actual <= stock_minimo.",
                },
            },
        },
    },
    {
        "name": "consultar_ventas",
        "description": (
            "Consulta ventas registradas: totales, agrupadas por producto, "
            "marca o día, en un rango de fechas. Usala para preguntas sobre "
            "cuánto se vendió, qué se vendió más, o ventas de un producto puntual."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "desde": {
                    "type": "string",
                    "description": "Fecha inicio (YYYY-MM-DD), opcional, default hace 30 días.",
                },
                "hasta": {
                    "type": "string",
                    "description": "Fecha fin (YYYY-MM-DD), opcional, default hoy.",
                },
                "producto_id": {
                    "type": "integer",
                    "description": "ID de producto puntual (opcional).",
                },
                "agrupar_por": {
                    "type": "string",
                    "enum": ["producto", "marca", "dia"],
                    "description": "Cómo agrupar los resultados (default producto).",
                },
            },
        },
    },
    {
        "name": "consultar_vencimientos",
        "description": (
            "Consulta lotes de stock próximos a vencer o ya vencidos. Usala "
            "para preguntas sobre vencimientos."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "dias": {
                    "type": "integer",
                    "description": "Ventana de días a futuro (opcional, default el umbral configurado del negocio).",
                },
            },
        },
    },
    {
        "name": "consultar_productos",
        "description": (
            "Busca productos en el catálogo por nombre, marca, SKU o categoría. "
            "Usala para preguntas tipo '¿tenés tal producto?' o para resolver a "
            "qué producto_id corresponde un nombre antes de llamar otra herramienta."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "termino": {"type": "string", "description": "Texto a buscar (opcional)."},
            },
        },
    },
    {
        "name": "consultar_rentabilidad",
        "description": (
            "Costos, márgenes, ganancia y proveedores. Usala para cualquier "
            "pregunta de plata que vaya más allá de la facturación: cuánto se "
            "gana con cada producto, qué conviene vender o reponer, en qué está "
            "inmovilizado el capital, a qué proveedor se le compra más, qué "
            "productos no tienen el costo cargado. También para preguntas tipo "
            "'¿en qué me conviene invertir?', '¿qué me deja más margen?' o "
            "'¿me conviene vender esto a tal precio?'. Devuelve, por producto: "
            "precio de costo y de venta, margen unitario y en %, unidades "
            "vendidas y ganancia estimada en el rango, stock y capital "
            "inmovilizado; más un resumen por proveedor y los totales del "
            "negocio."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "desde": {
                    "type": "string",
                    "description": "Fecha inicio (YYYY-MM-DD), opcional, default hace 30 días.",
                },
                "hasta": {
                    "type": "string",
                    "description": "Fecha fin (YYYY-MM-DD), opcional, default hoy.",
                },
                "termino": {
                    "type": "string",
                    "description": "Nombre, marca o SKU a buscar (opcional).",
                },
                "proveedor": {
                    "type": "string",
                    "description": "Nombre (o parte) del proveedor para filtrar (opcional).",
                },
                "solo_sin_costo": {
                    "type": "boolean",
                    "description": "Si es true, devuelve solo los productos que no tienen precio de costo cargado.",
                },
                "orden": {
                    "type": "string",
                    "enum": ["margen", "ganancia", "unidades", "capital"],
                    "description": (
                        "Cómo ordenar los productos: margen (% sobre la venta), "
                        "ganancia (plata ganada en el rango), unidades (rotación) "
                        "o capital (plata inmovilizada en stock). Default margen."
                    ),
                },
            },
        },
    },
    {
        "name": "consultar_prediccion_quiebre",
        "description": (
            "Predice qué productos se van a quedar sin stock PRONTO según el "
            "ritmo de ventas reciente -- no según el umbral de stock mínimo "
            "(esos ya los cubre consultar_stock). Usala para preguntas tipo "
            "'¿qué tengo que reponer ya?', '¿qué se me va a acabar?' o '¿hay "
            "algo en riesgo que todavía no esté marcado como crítico?'. Es una "
            "estimación (asume que el ritmo de venta de los últimos días se "
            "mantiene), no un pronóstico exacto."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "dias_umbral": {
                    "type": "integer",
                    "description": "Sólo productos que se agotarían dentro de este plazo, en días (opcional, default 7).",
                },
            },
        },
    },
]

# Qué herramientas puede usar cada rol. None = sin restricción (todas).
ROLE_TOOLS: dict[str, set[str] | None] = {
    "repositor": {"consultar_stock", "consultar_vencimientos", "consultar_productos"},
    "cajero": {"consultar_ventas", "consultar_stock", "consultar_productos"},
    "dueño": None,
}

# Qué herramientas corresponden a cada SECCIÓN del sistema (la pantalla desde
# la que se abrió el chat, ver CONTEXTO_POR_PAGINA en auth.js). None = todas.
#
# Esto es independiente del rol y se combina con él por intersección: un dueño
# parado en la pantalla de Depósito puede preguntar por stock y vencimientos,
# pero no por facturación -- para eso se va a Caja o al panel.
#
# El contexto lo manda el cliente, así que SÓLO PUEDE RECORTAR: falsearlo no
# habilita nada que el rol no tuviera. Ver _nombres_permitidos().
CONTEXT_TOOLS: dict[str, set[str] | None] = {
    "repositor": {"consultar_stock", "consultar_vencimientos", "consultar_productos"},
    "cajero": {"consultar_ventas", "consultar_stock", "consultar_productos"},
    "admin": None,
}

# Cada tool_name mapea al endpoint PHP que ejecuta la query real.
_ENDPOINT_POR_TOOL = {
    "consultar_stock": "consultar_stock.php",
    "consultar_ventas": "consultar_ventas.php",
    "consultar_vencimientos": "consultar_vencimientos.php",
    "consultar_productos": "consultar_productos.php",
    "consultar_rentabilidad": "consultar_rentabilidad.php",
    "consultar_prediccion_quiebre": "consultar_prediccion_quiebre.php",
}

_SYSTEM_PROMPT_BASE = """\
Sos el asistente de stockIAte, un sistema de inventario para una perfumería. \
Respondés en español rioplatense (vos), corto y directo, como si le \
hablaras a alguien del local mientras trabaja.

Solo respondés preguntas sobre el negocio (stock, ventas, vencimientos, \
catálogo, costos), usando las herramientas disponibles para traer datos \
reales -- nunca inventes números ni nombres de productos que no te haya \
devuelto una herramienta. Si te preguntan algo que no podés resolver con \
las herramientas que tenés habilitadas, decilo con onda y sugerí desde qué \
pantalla del sistema se consulta eso. Si te preguntan algo totalmente ajeno \
al negocio, decí que no podés ayudar con eso.

Cuando des números, redondeá a algo legible y aclará la unidad ($ o \
unidades). Si una herramienta no devuelve resultados, decilo tal cual -- no \
lo disfraces ni inventes datos para completar.\
"""

# Una línea por sección, para que el modelo sepa dónde está parado y a dónde
# mandar a la persona cuando pregunta algo de otra pantalla. El recorte real
# de herramientas lo hace CONTEXT_TOOLS; esto es para que la negativa sea
# útil ("eso lo ves desde Caja") en vez de un "no tengo esa herramienta".
_NOTA_POR_CONTEXTO = {
    "repositor": (
        "Estás abierto en la pantalla de Depósito (carga de stock). Desde acá "
        "se consulta stock, vencimientos y catálogo. Las preguntas de ventas o "
        "facturación se ven desde Caja, y las de costos, márgenes y "
        "proveedores desde el panel de Administración: si te preguntan eso, "
        "decilo así en vez de decir que no tenés la herramienta."
    ),
    "cajero": (
        "Estás abierto en la pantalla de Caja (registro de ventas). Desde acá "
        "se consulta ventas, stock disponible y catálogo. Las preguntas de "
        "vencimientos se ven desde Depósito, y las de costos, márgenes y "
        "proveedores desde el panel de Administración: si te preguntan eso, "
        "decilo así en vez de decir que no tenés la herramienta."
    ),
    "admin": (
        "Estás abierto en el panel de Administración. Desde acá se ve todo: "
        "stock, ventas, vencimientos, catálogo, costos, márgenes y proveedores."
    ),
}


# Pautas para que las respuestas de plata sean útiles y no delirantes. Se
# agrega al prompt SÓLO cuando consultar_rentabilidad está disponible: para un
# repositor o un cajero sería ruido que no puede accionar.
_GUIA_RENTABILIDAD = """\
Cuando respondas sobre plata (costos, margen, rentabilidad, qué conviene \
comprar o vender):
- Nunca mires el margen solo. Un producto con 60% de margen que no se vende \
deja menos plata que uno con 20% que rota todos los días: cruzá siempre \
margen_pct con unidades_vendidas antes de recomendar algo.
- Mucho capital_inmovilizado con pocas unidades_vendidas es plata dormida. \
Vale la pena señalarlo aunque no te lo hayan preguntado.
- Si precio_costo viene en null, NO estimes ni inventes un costo: decí que \
ese producto no tiene el costo cargado y sugerí cargarlo. Si \
productos_sin_costo o unidades_sin_costo son mayores a cero, aclará que el \
número que estás dando es parcial.
- Son estimaciones sobre los datos cargados en el sistema, no una proyección \
financiera. Hablá de este inventario y estos números concretos, no de \
inversiones en general.\
"""

# Igual que _GUIA_RENTABILIDAD: sólo se agrega cuando la herramienta está
# disponible, para no meter ruido en el prompt de repositor/cajero.
_GUIA_PREDICCION_QUIEBRE = """\
Cuando uses consultar_prediccion_quiebre:
- dias_restantes es una ESTIMACIÓN (asume que el ritmo de venta de los \
últimos días se mantiene igual, sin estacionalidad ni tendencia) -- aclaralo \
la primera vez que la menciones en la conversación, no la des como un dato \
exacto.
- Esta herramienta ya excluye los productos que están en stock crítico (esos \
salen por consultar_stock): si algo aparece acá es porque el riesgo es \
NUEVO -- vende rápido, no que ya esté bajo. No mezcles los dos avisos como \
si fueran lo mismo.
- Si no devuelve productos, decí que no hay nada en riesgo dentro del plazo \
consultado -- no lo confundas con "no hay ventas" ni inventes un motivo.\
"""


def _nombres_permitidos(rol: str, contexto: str | None = None) -> set[str]:
    """
    Intersección entre lo que puede el ROL y lo que corresponde a la SECCIÓN.

    El `contexto` viene del cliente (lo setea auth.js según la página), así
    que sólo puede recortar: nunca suma una herramienta que el rol no tenga.
    Y ni siquiera el recorte por rol es el control de acceso real -- ése vive
    en cada endpoint PHP, que valida contra $_SESSION. Esto es una
    optimización del prompt: no ofrecerle al modelo herramientas que no
    corresponden.
    """
    todas = {t["name"] for t in _TOOLS}

    por_rol = ROLE_TOOLS.get(rol)
    permitidas = todas if por_rol is None else set(por_rol)

    por_contexto = CONTEXT_TOOLS.get((contexto or "").strip().lower(), None)
    if por_contexto is not None:
        recortadas = permitidas & por_contexto
        # Si la intersección quedara vacía el asistente no podría consultar
        # nada: preferimos quedarnos con el set del rol (PHP igual bloquea lo
        # que no corresponda) antes que dejarlo mudo.
        if recortadas:
            permitidas = recortadas

    return permitidas


def _tools_openai(permitidas: set[str]) -> list[dict]:
    return [
        {
            "type": "function",
            "function": {
                "name": t["name"],
                "description": t["description"],
                "parameters": t["input_schema"],
            },
        }
        for t in _TOOLS
        if t["name"] in permitidas
    ]


def _prompt_sistema(rol: str, contexto: str | None = None) -> str:
    partes = [_SYSTEM_PROMPT_BASE]

    nota = _NOTA_POR_CONTEXTO.get((contexto or "").strip().lower())
    if nota:
        partes.append(nota)

    if "consultar_rentabilidad" in _nombres_permitidos(rol, contexto):
        partes.append(_GUIA_RENTABILIDAD)

    if "consultar_prediccion_quiebre" in _nombres_permitidos(rol, contexto):
        partes.append(_GUIA_PREDICCION_QUIEBRE)

    return "\n\n".join(partes)


def rol_de_sesion(cookie: str | None) -> str | None:
    """
    Le pregunta a PHP quién es el usuario detrás de esta cookie.

    Antes el `rol` venía como un campo del body de /chatbot y se le creía:
    cualquiera que le pegara directo al endpoint podía mandar rol="dueño" y
    desbloquear todas las herramientas (ROLE_TOOLS["dueño"] es None = todas).
    Ahora el rol sale de la sesión de servidor, igual que el negocio.

    Devuelve el rol, o None si no hay sesión válida.
    """
    if not cookie:
        return None

    try:
        resp = requests.get(
            f"{PHP_BASE_URL}/sesion_actual.php",
            headers={"Cookie": cookie},
            timeout=_TIMEOUT_PHP_SEGUNDOS,
        )
        if resp.status_code != 200:
            return None
        datos = resp.json()
        if not datos.get("ok"):
            return None
        return datos.get("usuario", {}).get("rol")
    except (requests.RequestException, ValueError):
        return None


def _ejecutar_tool(
    nombre: str,
    entrada: dict,
    cookie: str | None = None,
    permitidas: set[str] | None = None,
) -> dict:
    # El modelo puede pedir una herramienta que NO le ofrecimos: gpt-oss-20b
    # arma tool_calls a partir del texto de la pregunta y a veces inventa una
    # que no está en la lista. Se vio en la práctica -- con el contexto
    # "repositor" (tres herramientas, sin ventas) igual llamaba a
    # consultar_ventas y el endpoint contestaba, porque quien preguntaba era
    # dueño y PHP se lo permitía. Sin este chequeo el recorte por sección era
    # una sugerencia, no una regla.
    if permitidas is not None and nombre not in permitidas:
        return {
            "ok": False,
            "mensaje": (
                f"La herramienta {nombre} no está disponible en esta sección "
                "del sistema. Decile a la persona desde qué pantalla se "
                "consulta eso en vez de intentar responderlo igual."
            ),
        }

    endpoint = _ENDPOINT_POR_TOOL.get(nombre)
    if endpoint is None:
        return {"ok": False, "mensaje": f"Herramienta desconocida: {nombre}"}

    try:
        # La cookie del navegador se reenvía tal cual: PHP resuelve la sesión
        # y de ahí saca el negocio_id con el que filtra la consulta. Este
        # módulo nunca ve ni manipula el negocio -- sigue sin tocar MySQL, y
        # tampoco tiene forma de pedir datos de otro comercio.
        headers = {"Cookie": cookie} if cookie else {}
        resp = requests.post(
            f"{PHP_BASE_URL}/{endpoint}",
            json=entrada,
            headers=headers,
            timeout=_TIMEOUT_PHP_SEGUNDOS,
        )
        resp.raise_for_status()
        return resp.json()
    except requests.RequestException as e:
        return {"ok": False, "mensaje": f"No se pudo consultar {nombre}: {e}"}


def responder_pregunta(
    pregunta: str,
    rol: str,
    usuario_id: int | None = None,
    historial: list[dict] | None = None,
    cookie: str | None = None,
    contexto: str | None = None,
) -> tuple[str, list[str]]:
    """
    Responde una pregunta en lenguaje natural usando Groq con tool-use
    restringido a las herramientas permitidas para `rol` y `contexto`.

    `contexto` es la sección desde la que se abrió el chat ("repositor",
    "cajero", "admin"), que manda el frontend. Recorta las herramientas por
    intersección con las del rol: parado en el depósito, ni el dueño pregunta
    de facturación. Como sólo recorta, falsearlo no habilita nada.

    `cookie` es el header Cookie del navegador, que main.py reenvía tal cual.
    Se propaga a cada llamada PHP para que el negocio y el usuario salgan de
    la sesión de servidor. Sin cookie, los endpoints PHP responden 401 y las
    herramientas devuelven un error que el modelo reporta como "no pude
    consultar" -- que es el comportamiento correcto para alguien sin sesión.

    Nota sobre defensa en profundidad: el filtro de ROLE_TOOLS de acá es una
    optimización del prompt (no ofrecerle al modelo herramientas que no
    corresponden), NO el control de acceso. El control real está en cada
    endpoint PHP, que valida el rol contra $_SESSION por su cuenta.

    `historial` es una lista de turnos previos {"role": "user"|"assistant",
    "content": "..."} que manda el frontend para dar contexto conversacional
    (no incluye tool_calls, solo texto de intercambios anteriores) -- calza
    directo en el formato de mensajes de Groq/OpenAI, sin traducir roles.

    Devuelve (respuesta_texto, herramientas_usadas).
    """
    # Un solo set de herramientas permitidas para las dos cosas: lo que se le
    # ofrece al modelo y lo que se le deja ejecutar. Si sólo se filtrara la
    # oferta, un tool_call inventado igual saldría a pegarle al endpoint.
    permitidas = _nombres_permitidos(rol, contexto)
    tools = _tools_openai(permitidas)

    mensajes: list[dict] = [{"role": "system", "content": _prompt_sistema(rol, contexto)}]
    mensajes.extend(historial or [])
    mensajes.append({"role": "user", "content": pregunta})

    herramientas_usadas: list[str] = []

    try:
        for _ in range(_MAX_ITERACIONES_TOOL_USE):
            # _completar() rota de API key si la activa se quedo sin cupo.
            # Va adentro del for, no afuera: si el cupo se corta a mitad del
            # ida y vuelta de tool-use, la iteracion que sigue ya sale por la
            # key nueva y la pregunta se termina de responder igual.
            respuesta = _completar(mensajes, tools)

            mensaje = respuesta.choices[0].message

            if not mensaje.tool_calls:
                return (mensaje.content or "").strip(), herramientas_usadas

            mensajes.append(
                {
                    "role": "assistant",
                    "content": mensaje.content,
                    "tool_calls": [
                        {
                            "id": tc.id,
                            "type": "function",
                            "function": {"name": tc.function.name, "arguments": tc.function.arguments},
                        }
                        for tc in mensaje.tool_calls
                    ],
                }
            )

            for tc in mensaje.tool_calls:
                herramientas_usadas.append(tc.function.name)
                try:
                    entrada = json.loads(tc.function.arguments or "{}")
                except json.JSONDecodeError:
                    entrada = {}
                resultado = _ejecutar_tool(tc.function.name, entrada, cookie, permitidas)
                mensajes.append(
                    {
                        "role": "tool",
                        "tool_call_id": tc.id,
                        "content": json.dumps(resultado, ensure_ascii=False),
                    }
                )

        return (
            "Se me complicó resolver eso con las herramientas que tengo, ¿podés reformular la pregunta?",
            herramientas_usadas,
        )
    except ChatbotIAError:
        raise
    except Exception as e:  # noqa: BLE001 - no queremos acoplarnos a la jerarquía interna del SDK de groq
        mensaje_error = str(e).lower()
        if "401" in mensaje_error or "invalid api key" in mensaje_error or "authentication" in mensaje_error:
            raise ChatbotIAAuthError(f"Groq rechazó la API key: {e}") from e
        raise ChatbotIAConnectionError(f"No se pudo comunicar con Groq: {e}") from e


def registrar_log(
    pregunta: str,
    respuesta: str,
    herramientas_usadas: list[str],
    cookie: str | None = None,
) -> None:
    """
    Guarda el intercambio en chatbot_conversaciones vía PHP. Best-effort: si
    falla, no propaga la excepción -- no debe romper la respuesta que ya se
    le mostró al usuario (mismo espíritu offline-first del resto del proyecto).

    Ya no recibe `rol` ni `usuario_id`: los saca PHP de la sesión a partir de
    la cookie. Antes se mandaban desde acá con lo que hubiera dicho el
    cliente, así que el log de auditoría era falsificable.
    """
    try:
        requests.post(
            f"{PHP_BASE_URL}/registrar_chatbot_log.php",
            json={
                "pregunta": pregunta,
                "respuesta": respuesta,
                "herramientas_usadas": ",".join(herramientas_usadas),
            },
            headers={"Cookie": cookie} if cookie else {},
            timeout=_TIMEOUT_PHP_SEGUNDOS,
        )
    except requests.RequestException:
        pass
