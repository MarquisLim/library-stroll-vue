FROM python:3.12-slim-bookworm
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 PIP_DISABLE_PIP_VERSION_CHECK=1
WORKDIR /service
COPY ai-service/pyproject.toml ./
COPY ai-service/requirements.lock ./
COPY ai-service/app ./app
COPY ai-service/tests ./tests
RUN pip install --no-cache-dir -c requirements.lock '.[dev]' \
    && useradd --create-home --uid 10001 service
USER service
EXPOSE 8001
CMD ["python", "-m", "uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001"]
