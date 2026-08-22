"""
stockIAte - Script suelto de debug del Workflow de Roboflow
============================================================
Corre el workflow "text-recognition" contra una imagen y vuelca el
resultado crudo. Es un script de debug para correr a mano: `main.py` NO lo
importa, y la ruta de producción es `roboflow_workflow.py`.

Uso:
    python detector.py [ruta/a/una/imagen.jpg]

Necesita un ROBOFLOW_API_KEY válido en el .env (ver .env.example).
"""

import os
import sys

from dotenv import load_dotenv

load_dotenv()

from inference_sdk import InferenceHTTPClient  # noqa: E402  (después de load_dotenv a propósito)

# Mismos nombres y defaults que roboflow_workflow.py, para no divergir.
WORKSPACE_NAME = os.getenv("ROBOFLOW_WORKSPACE_NAME", "cooppers-workspace")
WORKFLOW_ID = os.getenv("ROBOFLOW_WORKFLOW_ID", "text-recognition")
API_URL = os.getenv("ROBOFLOW_API_URL", "https://serverless.roboflow.com")
API_KEY = os.getenv("ROBOFLOW_API_KEY")

if not API_KEY:
    sys.exit("Falta ROBOFLOW_API_KEY en el entorno (.env).")

imagen = sys.argv[1] if len(sys.argv) > 1 else "YOUR_IMAGE.jpg"

client = InferenceHTTPClient(api_url=API_URL, api_key=API_KEY)

result = client.run_workflow(
    workspace_name=WORKSPACE_NAME,
    workflow_id=WORKFLOW_ID,
    images={"image": imagen},
    use_cache=True,  # Acelera pedidos repetidos
)

print(result)
