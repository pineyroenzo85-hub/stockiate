# 1. Import the library
from inference_sdk import InferenceHTTPClient

# 2. Connect to your workflow
client = InferenceHTTPClient(
    api_url="https://serverless.roboflow.com",
    api_key="7TmyyIDZ2Vi1var1XDGt"
)

# 3. Run your workflow on an image
result = client.run_workflow(
    workspace_name="cooppers-workspace",
    workflow_id="text-recognition",
    images={
        "image": "YOUR_IMAGE.jpg" # Path to your image file
    },
    use_cache=True # Speeds up repeated requests
)

# 4. Get your results
print(result)
