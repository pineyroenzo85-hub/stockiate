"""
stockIAte - Parser/sanitizador de texto OCR
=============================================
El step `glm_ocr` del Workflow de Roboflow lee TODO el texto visible sobre
el recorte de cada envase: no solo la marca, también ingredientes, modo de
uso, número de lote, sitio web, etc. -- un bloque largo y ruidoso, poco útil
tal cual para mostrarlo en la Red de Seguridad o guardarlo en la base.

Este módulo limpia ese texto crudo antes de que roboflow_workflow.py arme
cada DeteccionProducto, separando:
  - texto_limpio: candidato de marca/nombre (3-4 palabras clave, sin ruido
    de etiqueta), para mostrar en la UI y usar como base del fuzzy matching
    contra el catálogo.
  - volumen: contenido/volumen del envase (ej: "150ml", "1l"), si el OCR
    llegó a leerlo.

No toca la base de datos ni conoce el catálogo de productos -- eso es
responsabilidad del frontend, que ya tiene el catálogo cargado en memoria
para el <select> (ver buscarProductoPorTexto en repositor.html/cajero.html).
Este módulo es puro procesamiento de texto.
"""

from __future__ import annotations

import re
from dataclasses import dataclass

# Frases/palabras de etiqueta que marcan una línea como ruido (no como
# marca/nombre de producto). Cada línea del OCR que matchee alguna de estas
# se descarta entera -- son secciones típicas de packaging, no el nombre.
_STOPWORD_PATTERNS = [
    r"ingredientes?",
    r"cont\.?\s*neto",
    r"contenido\s+neto",
    r"elaborado\s+por",
    r"fabricado\s+por",
    r"distribuido\s+por",
    r"industria\s+\w+",
    r"modo\s+de\s+uso",
    r"precauciones?",
    r"advertenc\w*",
    r"www\.\S*",
    r"https?://\S+",
    r"lote\s*n?[ºo°]?\s*[:\-]?\s*\S*",
    r"reg\.?\s*i?n?p?m?\s*\S*",
    r"hecho\s+en\s+\w+",
    r"vencimiento",
    r"lleve\s+m[aá]s",
    r"pague\s+menos",
    r"\d+\s*x\s*\d+",       # promos tipo "2x1", "3x2"
    r"oferta",
    r"promo\w*",
    r"\bdescuento\b",
]
_RE_STOPWORD = re.compile("|".join(_STOPWORD_PATTERNS), re.IGNORECASE)

# Patrón de línea "ruido puro": solo dígitos/símbolos (códigos, page numbers
# mal leídos por el OCR), demasiado corta para ser un nombre real.
_RE_LINEA_RUIDO = re.compile(r"^[\d\W]*$")

# Contenido/volumen: número + unidad típica de perfumería/cosmética.
_RE_VOLUMEN = re.compile(r"(\d+(?:[.,]\d+)?)\s*(ml|mL|g|gr|kg|lts?|l)\b", re.IGNORECASE)

# Códigos alfanuméricos sueltos tipo lote (mezcla de letras y números, 5+
# caracteres) que suelen colarse en el medio del texto útil.
_RE_CODIGO_LOTE = re.compile(r"\b(?=[A-Za-z0-9]*\d)(?=[A-Za-z0-9]*[A-Za-z])[A-Za-z0-9]{5,}\b")

# Cuántas palabras clave como máximo dejamos en el texto limpio final.
_MAX_PALABRAS_TEXTO_LIMPIO = 4


@dataclass
class ParsedOCR:
    texto_limpio: str   # candidato de marca/nombre, ej: "Rexona Hidra Proteccion"
    volumen: str         # ej: "150ml", o "" si no se detectó


def parsear_texto_ocr(texto_crudo: str) -> ParsedOCR:
    """
    Sanitiza el texto crudo que devuelve el OCR de Roboflow: saca líneas de
    etiqueta (ingredientes, modo de uso, lote, www., etc.), separa el
    volumen/contenido a un campo aparte, y deja como texto_limpio solo las
    primeras palabras clave -- pensado para ser el candidato de marca/nombre
    que se muestra en la Red de Seguridad y se usa para matchear contra el
    catálogo.
    """
    if not texto_crudo or not texto_crudo.strip():
        return ParsedOCR(texto_limpio="", volumen="")

    match_volumen = _RE_VOLUMEN.search(texto_crudo)
    volumen = f"{match_volumen.group(1).replace(',', '.')}{match_volumen.group(2).lower()}" if match_volumen else ""

    # El OCR devuelve el texto como si fueran "renglones" del envase
    # separados por \n -- filtramos línea por línea antes de unir, así una
    # sola línea de ingredientes no arrastra basura al resto.
    lineas_limpias = []
    for linea in texto_crudo.split("\n"):
        linea = linea.strip()
        if len(linea) < 3:
            continue
        if _RE_LINEA_RUIDO.match(linea):
            continue
        if _RE_STOPWORD.search(linea):
            continue
        lineas_limpias.append(linea)

    texto = " ".join(lineas_limpias)
    texto = _RE_VOLUMEN.sub(" ", texto)
    texto = _RE_CODIGO_LOTE.sub(" ", texto)
    texto = re.sub(r"[^\w\sÀ-ÿ-]", " ", texto)  # símbolos raros que deja el OCR
    texto = re.sub(r"\s+", " ", texto).strip()

    palabras = [p for p in texto.split(" ") if p]
    texto_limpio = " ".join(palabras[:_MAX_PALABRAS_TEXTO_LIMPIO])

    return ParsedOCR(texto_limpio=texto_limpio, volumen=volumen)
