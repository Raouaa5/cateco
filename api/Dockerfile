FROM python:3.11-slim

# Install PHP CLI (needed to run generate_dataset_hybrid.php)
RUN apt-get update && apt-get install -y \
    php-cli \
    php-pdo \
    php-mysql \
    && rm -rf /var/lib/apt/lists/*

# Install Python dependencies for the FastAPI service
WORKDIR /srv/sylius/api
COPY api/requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# The application code is mounted as a volume at runtime (see compose.yml)
# We do NOT copy ml/ or api/ here — they come from the shared volume
WORKDIR /srv/sylius/api

CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000"]
