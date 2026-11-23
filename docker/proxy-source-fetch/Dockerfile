FROM python:3.12-slim

RUN pip install --no-cache-dir 'curl_cffi==0.17.0' \
    && groupadd --system -g 10001 fetch \
    && useradd --system -u 10001 -g fetch -M fetch
WORKDIR /app
COPY --chown=fetch:fetch server.py /app/server.py
COPY --chown=fetch:fetch tests /app/tests
USER fetch
EXPOSE 8765
HEALTHCHECK --interval=15s --timeout=3s --retries=3 \
    CMD python -c 'import os,urllib.request; urllib.request.urlopen("http://"+os.getenv("FETCH_BIND","127.0.0.1")+":8765/health",timeout=2).read()'
CMD ["python", "-u", "/app/server.py"]
