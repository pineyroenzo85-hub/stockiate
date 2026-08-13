"""
stockIAte - Chatbot IA (asistente de consultas sobre inventario y ventas)
=============================================
Encapsula la llamada a la API de Groq (inferencia gratuita sobre modelos
open-source, con límites de uso) con tool-use: el modelo elige entre un set
fijo de "herramientas" que consultan datos reales vía los endpoints PHP de
solo lectura (consultar_stock.php, consultar_ventas.php,
consultar_vencimientos.php, consultar_productos.php). Python nunca toca
MySQL directamente -- la persistencia sigue siendo responsabilidad de PHP,
ver CLAUDE.md.

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
import os

import requests
from groq import Groq

MODEL = os.getenv("GROQ_MODEL", "openai/gpt-oss-20b")
API_KEY = os.getenv("GROQ_API_KEY")
PHP_BASE_URL = os.getenv("PHP_BASE_URL", "http://localhost/stockiate/tesis_enzo")

_MAX_ITERACIONES_TOOL_USE = 5
_TIMEOUT_PHP_SEGUNDOS = 5

ROLES_VALIDOS = ("repositor", "cajero", "dueño")


class ChatbotIAError(Exception):
    """Error base de configuración o comunicación con el chatbot IA."""


class ChatbotIAAuthError(ChatbotIAError):
    """Falta o es inválida la GROQ_API_KEY. No reintentable."""


class ChatbotIAConnectionError(ChatbotIAError):
    """No se pudo comunicar con la API de Groq."""


def _cliente() -> Groq:
    if not API_KEY:
        raise ChatbotIAAuthError("Falta GROQ_API_KEY en el entorno (.env).")
    return Groq(api_key=API_KEY)


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
]

# Qué herramientas puede usar cada rol. None = sin restricción (todas).
ROLE_TOOLS: dict[str, set[str] | None] = {
    "repositor": {"consultar_stock", "consultar_vencimientos", "consultar_productos"},
    "cajero": {"consultar_ventas", "consultar_stock", "consultar_productos"},
    "dueño": None,
}

# Cada tool_name mapea al endpoint PHP que ejecuta la query real.
_ENDPOINT_POR_TOOL = {
    "consultar_stock": "consultar_stock.php",
    "consultar_ventas": "consultar_ventas.php",
    "consultar_vencimientos": "consultar_vencimientos.php",
    "consultar_productos": "consultar_productos.php",
}

_SYSTEM_PROMPT = """\
Sos el asistente de stockIAte, un sistema de inventario para una perfumería. \
Respondés en español rioplatense (vos), corto y directo, como si le \
hablaras a alguien del local mientras trabaja.

Solo respondés preguntas sobre stock, ventas, vencimientos y catálogo de \
esta perfumería, usando las herramientas disponibles para traer datos \
reales -- nunca inventes números ni nombres de productos que no te haya \
devuelto una herramienta. Si te preguntan algo que no podés resolver con \
las herramientas que tenés habilitadas para este rol (por ejemplo, te \
preguntan por ventas pero no tenés esa herramienta), decilo con onda y \
sugerí a quién le correspondería esa consulta (repositor, cajero o dueño). \
Si te preguntan algo totalmente ajeno al negocio, decí que no podés ayudar \
con eso.

Cuando des números, redondeá a algo legible y aclará la unidad ($ o \
unidades). Si una herramienta no devuelve resultados, decilo tal cual -- no \
lo disfraces ni inventes datos para completar.\
"""


def _tools_para_rol(rol: str) -> list[dict]:
    permitidas = ROLE_TOOLS.get(rol)
    if permitidas is None:
        return _TOOLS
    return [t for t in _TOOLS if t["name"] in permitidas]


def _tools_openai_para_rol(rol: str) -> list[dict]:
    return [
        {
            "type": "function",
            "function": {
                "name": t["name"],
                "description": t["description"],
                "parameters": t["input_schema"],
            },
        }
        for t in _tools_para_rol(rol)
    ]


def _ejecutar_tool(nombre: str, entrada: dict) -> dict:
    endpoint = _ENDPOINT_POR_TOOL.get(nombre)
    if endpoint is None:
        return {"ok": False, "mensaje": f"Herramienta desconocida: {nombre}"}

    try:
        resp = requests.post(
            f"{PHP_BASE_URL}/{endpoint}",
            json=entrada,
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
) -> tuple[str, list[str]]:
    """
    Responde una pregunta en lenguaje natural usando Groq con tool-use
    restringido a las herramientas permitidas para `rol`.

    `historial` es una lista de turnos previos {"role": "user"|"assistant",
    "content": "..."} que manda el frontend para dar contexto conversacional
    (no incluye tool_calls, solo texto de intercambios anteriores) -- calza
    directo en el formato de mensajes de Groq/OpenAI, sin traducir roles.

    Devuelve (respuesta_texto, herramientas_usadas).
    """
    cliente = _cliente()
    tools = _tools_openai_para_rol(rol)

    mensajes: list[dict] = [{"role": "system", "content": _SYSTEM_PROMPT}]
    mensajes.extend(historial or [])
    mensajes.append({"role": "user", "content": pregunta})

    herramientas_usadas: list[str] = []

    try:
        for _ in range(_MAX_ITERACIONES_TOOL_USE):
            respuesta = cliente.chat.completions.create(
                model=MODEL,
                messages=mensajes,
                tools=tools,
                tool_choice="auto",
                max_tokens=1024,
            )

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
                resultado = _ejecutar_tool(tc.function.name, entrada)
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
    rol: str,
    usuario_id: int | None,
    pregunta: str,
    respuesta: str,
    herramientas_usadas: list[str],
) -> None:
    """
    Guarda el intercambio en chatbot_conversaciones vía PHP. Best-effort: si
    falla, no propaga la excepción -- no debe romper la respuesta que ya se
    le mostró al usuario (mismo espíritu offline-first del resto del proyecto).
    """
    try:
        requests.post(
            f"{PHP_BASE_URL}/registrar_chatbot_log.php",
            json={
                "rol": rol,
                "usuario_id": usuario_id,
                "pregunta": pregunta,
                "respuesta": respuesta,
                "herramientas_usadas": ",".join(herramientas_usadas),
            },
            timeout=_TIMEOUT_PHP_SEGUNDOS,
        )
    except requests.RequestException:
        pass
