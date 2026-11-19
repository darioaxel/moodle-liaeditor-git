# Engine LiaEditor–Moodle — imagen de producción.

FROM python:3.12-slim

# git: LocalGitProvider usa el CLI (worktrees, plumbing); GitPython también
# necesita el binario.
# docker-cli (solo el cliente; Debian 13 lo separó de docker.io):
# LiaexDockerExecutor lanza el exporter de LiaScript como contenedor
# (LIA-040). Necesita además montar /var/run/docker.sock y que el usuario
# del contenedor pertenezca al grupo docker del host (--group-add).
RUN apt-get update \
    && apt-get install -y --no-install-recommends git docker-cli \
    && rm -rf /var/lib/apt/lists/*

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    # Defaults de desarrollo; sobrescribir en despliegue real.
    HOST=0.0.0.0 \
    PORT=8000 \
    MATERIALES_LIASCRIPT_LOG_FORMAT=json

WORKDIR /srv/materiales-liascript

# Dependencias primero para aprovechar la caché de capas.
COPY pyproject.toml README.md ./
COPY src ./src
RUN pip install --no-cache-dir .

# Usuario no-root.
RUN useradd --create-home liascript \
    && chown -R liascript:liascript /srv/materiales-liascript
USER liascript

# El repo de contenidos se monta en /content con otro propietario (host):
# sin esta excepción git rechaza operar sobre él ("dubious ownership").
RUN git config --global --add safe.directory /content

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD python -c "import os,urllib.request;urllib.request.urlopen(f'http://127.0.0.1:{os.environ.get(\"PORT\",\"8000\")}/health')" || exit 1

# El repo de contenidos se monta en /content (ver docker-compose.yml).
CMD ["python", "-m", "materiales_liascript.serve"]
