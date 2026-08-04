FROM python:3.11-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY synth_server.py .

RUN mkdir -p /app/output

EXPOSE 5000

CMD ["python", "-u", "synth_server.py"]
