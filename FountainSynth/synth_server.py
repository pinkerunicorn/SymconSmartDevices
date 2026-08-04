"""
FountainSynth – Generative Audio-Synthesizer für SmartFountain.

Empfängt Wasserhöhen h(t) als Array per HTTP POST und rendert
daraus eine WAV-Datei mit dem gewählten Sound-Theme.

Endpunkte:
  POST /render   – Rendert Audio aus {"heights": [...], "theme": "...", "duration": 20}
  GET  /health   – Statuscheck
  GET  /output/<file> – Download der gerenderten WAV-Datei
"""

import os
import struct
import time
import math
from io import BytesIO

import numpy as np
from scipy.io import wavfile
from flask import Flask, request, jsonify, send_file, send_from_directory

app = Flask(__name__)

SAMPLE_RATE = 44100
OUTPUT_DIR = "/app/output"
os.makedirs(OUTPUT_DIR, exist_ok=True)


# =============================================================================
# Basis-Utilities
# =============================================================================

def quantize_to_scale(h_val: float, scale: list[float]) -> float:
    """Mapped einen Wert [0.0-1.0] auf die nächste Note in der Skala."""
    idx = int(np.clip(h_val, 0.0, 1.0) * (len(scale) - 1))
    return scale[idx]


def make_envelope(num_samples: int, fade_ms: int = 500) -> np.ndarray:
    """Erzeugt eine Hüllkurve mit Fade-In und Fade-Out."""
    env = np.ones(num_samples)
    fade_samples = int(SAMPLE_RATE * fade_ms / 1000)
    fade_samples = min(fade_samples, num_samples // 4)
    if fade_samples > 0:
        env[:fade_samples] = np.linspace(0, 1, fade_samples)
        env[-fade_samples:] = np.linspace(1, 0, fade_samples)
    return env


def normalize(audio: np.ndarray) -> np.ndarray:
    """Normalisiert Audio auf [-1.0, 1.0] und verhindert Clipping."""
    peak = np.max(np.abs(audio))
    if peak > 0:
        audio = audio / peak * 0.95
    return audio


def compute_derivatives(h_interp: np.ndarray, dt: float) -> tuple[np.ndarray, np.ndarray]:
    """Berechnet erste und zweite Ableitung der Wasserhöhe."""
    velocity = np.gradient(h_interp) / dt
    acceleration = np.gradient(velocity) / dt
    return velocity, acceleration


# =============================================================================
# Sound-Themes
# =============================================================================

def theme_mystisch(t: np.ndarray, h: np.ndarray, vel: np.ndarray, acc: np.ndarray) -> np.ndarray:
    """
    Mystisch: Dunkle Pads, Sub-Bass, Hall-artige Obertöne.
    Akkord: C-Moll 9 über 3 Oktaven.
    """
    # C-Moll 9: C, Eb, G, Bb, D
    base_freqs = [32.70, 38.89, 49.00, 58.27, 73.42,
                  65.41, 77.78, 98.00, 116.54, 146.83,
                  130.81, 155.56, 196.00, 233.08, 293.66]

    audio = np.zeros_like(t)

    for freq in base_freqs:
        # Chorus: 3 leicht verstimmte Oszillatoren
        for detune in [-0.3, 0.0, 0.3]:
            f = freq + detune
            phase = 2 * np.pi * f * t

            # Grundton: immer leise hörbar (Sub-Ebene)
            audio += np.sin(phase) * 0.12

            # 1. Oberton: steigt mit Wasserhöhe
            audio += np.sin(2 * phase) * (h * 0.18)

            # 2. Oberton (strahlend): nur bei hoher Fontäne
            audio += np.sin(3 * phase) * (h ** 2 * 0.12)

            # 3. Oberton (Glanz): nur bei Maximum
            audio += np.sin(4 * phase) * (h ** 3 * 0.08)

    # Wasser-Zischen bei schnellen Sprüngen
    noise = np.random.normal(0, 1, len(t))
    vel_norm = np.clip(np.abs(vel) * 3, 0, 1)
    audio += noise * vel_norm * 0.12

    return audio


def theme_karibik(t: np.ndarray, h: np.ndarray, vel: np.ndarray, acc: np.ndarray) -> np.ndarray:
    """
    Karibik: Helle, perkussive Klänge. Steel-Drum / Marimba-artig.
    Akkord: C-Dur Pentatonik.
    """
    # C-Dur Pentatonik über 3 Oktaven
    scale = [261.63, 293.66, 329.63, 392.00, 440.00,
             523.25, 587.33, 659.26, 783.99, 880.00,
             1046.50, 1174.66, 1318.51, 1567.98, 1760.00]

    audio = np.zeros_like(t)
    dt = t[1] - t[0] if len(t) > 1 else 1.0 / SAMPLE_RATE

    # Helle Grundtöne (weniger, dafür kristallin)
    for freq in scale[:8]:
        phase = 2 * np.pi * freq * t
        # Schneller Decay -> perkussiv
        decay = np.exp(-t * 0.3)
        audio += np.sin(phase) * decay * (h * 0.15)
        # Hohe Obertöne für "Kling"
        audio += np.sin(3 * phase) * decay * (h ** 2 * 0.08)

    # Rhythmische Impulse bei Richtungsänderungen
    acc_norm = np.clip(np.abs(acc) * 0.5, 0, 1)
    for freq in [523.25, 659.26, 783.99]:
        phase = 2 * np.pi * freq * t
        audio += np.sin(phase) * acc_norm * 0.1

    # Leichtes Rauschen (wie Wellen am Strand)
    noise = np.random.normal(0, 1, len(t))
    audio += noise * 0.03

    return audio


def theme_zen(t: np.ndarray, h: np.ndarray, vel: np.ndarray, acc: np.ndarray) -> np.ndarray:
    """
    Zen: Klangschalen, reine Obertöne, meditative Stille.
    Akkord: F-Dur 7 (F, A, C, E).
    """
    # F-Dur 7 mit reinen Obertönen
    fundamentals = [87.31, 110.00, 130.81, 164.81,
                    174.61, 220.00, 261.63, 329.63]

    audio = np.zeros_like(t)

    for freq in fundamentals:
        phase = 2 * np.pi * freq * t

        # Klangschalen-Charakter: Grundton + reine Quinte + Oktave
        audio += np.sin(phase) * 0.15 * (0.3 + h * 0.7)
        audio += np.sin(1.5 * phase) * 0.08 * h  # Quinte
        audio += np.sin(2 * phase) * 0.06 * (h ** 2)  # Oktave

        # Sehr hohe, leise Obertöne (Glockenton)
        audio += np.sin(5 * phase) * 0.02 * (h ** 3)

    # Langsamer LFO auf der Amplitude (meditatives Schweben)
    lfo = (np.sin(2 * np.pi * 0.1 * t) + 1) / 2  # 0.1 Hz = 10s Periode
    audio *= (0.7 + lfo * 0.3)

    return audio


def theme_dramatic(t: np.ndarray, h: np.ndarray, vel: np.ndarray, acc: np.ndarray) -> np.ndarray:
    """
    Dramatic: Cinematic Strings, wuchtiges Crescendo.
    Akkord: D-Moll (D, F, A) mit tiefen Drones.
    """
    # D-Moll über breiten Frequenzbereich
    base_freqs = [36.71, 43.65, 55.00,  # Sub-Drone
                  73.42, 87.31, 110.00,  # Tiefe Streicher
                  146.83, 174.61, 220.00,  # Mittlere Streicher
                  293.66, 349.23, 440.00]  # Hohe Streicher

    audio = np.zeros_like(t)

    for i, freq in enumerate(base_freqs):
        # Sägezahn-artige Wellenform (String-Charakter)
        for harmonic in range(1, 6):
            amp = 1.0 / harmonic
            phase = 2 * np.pi * freq * harmonic * t

            if i < 3:
                # Sub-Drone: immer präsent
                audio += np.sin(phase) * amp * 0.08
            elif i < 6:
                # Tiefe Streicher: steigen mit Wasserhöhe
                audio += np.sin(phase) * amp * (h * 0.12)
            elif i < 9:
                # Mittlere Streicher: quadratisch
                audio += np.sin(phase) * amp * (h ** 2 * 0.10)
            else:
                # Hohe Streicher: nur bei Höhepunkt
                audio += np.sin(phase) * amp * (h ** 3 * 0.08)

        # Leichtes Vibrato (Streicher-Charakter)
        vibrato = np.sin(2 * np.pi * 5.5 * t) * 0.003
        phase_vib = 2 * np.pi * freq * (1 + vibrato) * t
        audio += np.sin(phase_vib) * 0.03 * h

    # Paukenschlag bei starker Beschleunigung
    acc_hits = np.clip(np.abs(acc) * 0.3, 0, 1) ** 2
    timpani_freq = 55.0
    audio += np.sin(2 * np.pi * timpani_freq * t) * acc_hits * 0.15

    return audio


def theme_techno(t: np.ndarray, h: np.ndarray, vel: np.ndarray, acc: np.ndarray) -> np.ndarray:
    """
    Techno: Kick-Drum, Acid-Bass (303-artig), Hi-Hats.
    Tonart: A-Moll.
    """
    audio = np.zeros_like(t)
    dt = t[1] - t[0] if len(t) > 1 else 1.0 / SAMPLE_RATE

    # --- Acid Bass (TB-303 Style) ---
    # Frequenz folgt Wasserhöhe auf A-Moll Skala
    a_minor = [55.00, 61.74, 65.41, 73.42, 82.41, 87.31, 98.00, 110.00,
               123.47, 130.81, 146.83, 164.81, 174.61, 196.00, 220.00]

    bass_freqs = np.array([quantize_to_scale(hv, a_minor) for hv in h])
    bass_phase = np.cumsum(bass_freqs) * 2 * np.pi / SAMPLE_RATE

    # Sägezahn-Bass
    for harmonic in range(1, 8):
        amp = 1.0 / harmonic
        audio += np.sin(bass_phase * harmonic) * amp * 0.12

    # Filter-Sweep: Velocity steuert Cutoff (simuliert durch Oberton-Mischung)
    vel_norm = np.clip(np.abs(vel) * 2, 0, 1)
    audio *= (0.4 + vel_norm * 0.6)

    # --- Kick Drum (bei starker Beschleunigung) ---
    acc_norm = np.clip(np.abs(acc) * 0.2, 0, 1) ** 2
    kick_freq = 60.0 * np.exp(-t * 8)  # Frequency sweep down
    kick_phase = np.cumsum(kick_freq) * 2 * np.pi / SAMPLE_RATE
    kick = np.sin(kick_phase) * np.exp(-t * 4)
    audio += kick * acc_norm * 0.2

    # --- Hi-Hat (gefiltertes Rauschen, bei hohem Wasser) ---
    noise = np.random.normal(0, 1, len(t))
    hihat_env = h ** 2
    audio += noise * hihat_env * 0.06

    return audio


# =============================================================================
# Theme-Registry
# =============================================================================

THEMES = {
    "mystisch": theme_mystisch,
    "karibik": theme_karibik,
    "zen": theme_zen,
    "dramatic": theme_dramatic,
    "techno": theme_techno,
}


# =============================================================================
# Render-Engine
# =============================================================================

def render_audio(heights: list[float], theme: str, duration: float) -> str:
    """
    Rendert eine WAV-Datei aus den Wasserhöhen und dem gewählten Theme.

    Args:
        heights: Array von Wasserhöhen [0.0 - 1.0]
        theme: Name des Sound-Themes
        duration: Dauer der Show in Sekunden

    Returns:
        Dateipfad der gerenderten WAV-Datei
    """
    total_samples = int(SAMPLE_RATE * duration)
    t = np.linspace(0, duration, total_samples, endpoint=False)
    dt = 1.0 / SAMPLE_RATE

    # Wasserhöhen auf die Zeitachse strecken (Interpolation)
    time_original = np.linspace(0, duration, len(heights))
    h_interp = np.interp(t, time_original, heights)
    h_interp = np.clip(h_interp, 0.0, 1.0)

    # Erste und zweite Ableitung berechnen
    velocity, acceleration = compute_derivatives(h_interp, dt)

    # Theme-Funktion aufrufen
    theme_fn = THEMES.get(theme, theme_mystisch)
    audio = theme_fn(t, h_interp, velocity, acceleration)

    # Normalisieren
    audio = normalize(audio)

    # Hüllkurve anwenden (Fade-In/Out)
    envelope = make_envelope(total_samples, fade_ms=500)
    audio *= envelope

    # In 16-Bit PCM konvertieren
    audio_int16 = np.int16(audio * 32767)

    # Dateiname mit Timestamp (Cache-Busting)
    filename = f"show_{int(time.time())}.wav"
    filepath = os.path.join(OUTPUT_DIR, filename)

    # Alte Dateien aufräumen (max. 5 behalten)
    existing = sorted(
        [f for f in os.listdir(OUTPUT_DIR) if f.endswith(".wav")],
        key=lambda f: os.path.getmtime(os.path.join(OUTPUT_DIR, f))
    )
    while len(existing) > 4:
        os.remove(os.path.join(OUTPUT_DIR, existing.pop(0)))

    wavfile.write(filepath, SAMPLE_RATE, audio_int16)
    return filename


# =============================================================================
# Flask Endpoints
# =============================================================================

@app.route("/health", methods=["GET"])
def health():
    """Statuscheck."""
    return jsonify({
        "status": "ok",
        "themes": list(THEMES.keys()),
        "sample_rate": SAMPLE_RATE,
    })


@app.route("/render", methods=["POST"])
def render():
    """
    Rendert eine WAV-Datei aus Wasserhöhen.

    Erwartet JSON:
    {
        "heights": [0.1, 0.5, 1.0, 0.5, 0.1, ...],
        "theme": "mystisch",
        "duration": 20
    }

    Gibt zurück:
    {
        "status": "success",
        "filename": "show_1722790000.wav",
        "url": "/output/show_1722790000.wav",
        "render_time_ms": 42,
        "duration": 20,
        "theme": "mystisch"
    }
    """
    data = request.get_json()

    if not data or "heights" not in data:
        return jsonify({"error": "JSON mit 'heights' Array erforderlich"}), 400

    heights = data["heights"]
    if not isinstance(heights, list) or len(heights) < 2:
        return jsonify({"error": "'heights' muss ein Array mit mindestens 2 Werten sein"}), 400

    theme = data.get("theme", "mystisch")
    if theme not in THEMES:
        return jsonify({
            "error": f"Unbekanntes Theme '{theme}'",
            "available": list(THEMES.keys())
        }), 400

    duration = float(data.get("duration", 20))
    if duration < 1 or duration > 300:
        return jsonify({"error": "'duration' muss zwischen 1 und 300 Sekunden liegen"}), 400

    # Rendern und Zeit messen
    start = time.time()
    filename = render_audio(heights, theme, duration)
    elapsed_ms = round((time.time() - start) * 1000, 1)

    return jsonify({
        "status": "success",
        "filename": filename,
        "url": f"/output/{filename}",
        "render_time_ms": elapsed_ms,
        "duration": duration,
        "theme": theme,
    })


@app.route("/output/<path:filename>", methods=["GET"])
def serve_output(filename):
    """Liefert die gerenderte WAV-Datei zum Download."""
    return send_from_directory(OUTPUT_DIR, filename, mimetype="audio/wav")


# =============================================================================
# Main
# =============================================================================

if __name__ == "__main__":
    print("=" * 60)
    print("FountainSynth – Generative Audio für SmartFountain")
    print(f"  Themes: {', '.join(THEMES.keys())}")
    print(f"  Sample Rate: {SAMPLE_RATE} Hz")
    print(f"  Output: {OUTPUT_DIR}")
    print("=" * 60)
    app.run(host="0.0.0.0", port=5000, debug=False)
