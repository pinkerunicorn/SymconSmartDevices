# LyngdorfMP60

Bindet einen Lyngdorf MP-60 Audio-Video-Prozessor (oder kompatible Modelle) in IP-Symcon ein.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Steuerung von Power, Lautstärke, Mute, Quelle (Source), Audio Mode und Voicing.
* Anzeige des aktuellen Eingangs- und Ausgangs-Audiotyps (Audio Type In/Out).
* Automatische Profilerstellung: Die verfügbaren Quellen, Audio-Modi und Voicings werden dynamisch vom Gerät ausgelesen und als Profilauswahl bereitgestellt.
* Optionales Verstecken der Bedien-Variablen im WebFront, wenn das Gerät ausgeschaltet ist.
* Bidirektionale Kommunikation: Änderungen, die direkt am Gerät vorgenommen werden, werden in IP-Symcon aktualisiert (Live-Auswertung der empfangenen Datenpakete).
* Automatisches Polling als Fallback.

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0
* Ein Lyngdorf AV-Prozessor (z.B. MP-60), der über das Netzwerk erreichbar ist.
* Ein Client Socket (TCP/IP), der mit der IP des Geräts und dem entsprechenden Port verbunden ist.

### 3. Installation

* Über den Module Store das Modul `LyngdorfMP60` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartDevices`
* Eine neue Instanz des Moduls erstellen. Der notwendige Client Socket und eine Splitter-Instanz werden dabei in der Regel automatisch angelegt oder können zugewiesen werden.

### 4. Konfiguration

* **HideVariablesWhenOff**: Wenn aktiv, werden alle steuernden Variablen (Volume, Source, etc.) im WebFront versteckt, solange der Receiver ausgeschaltet ist. Das sorgt für mehr Übersichtlichkeit.

### 5. Statusvariablen und Profile

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| Power | ⚡ Power | Boolean | Schaltet das Gerät ein (Main) oder in den Standby. |
| Volume | 🔊 Lautstärke | Float | Regelt die Lautstärke in dB (-99.9 bis 24.0). |
| Mute | 🔇 Mute | Boolean | Schaltet das Gerät stumm. |
| Source | 🎵 Quelle | Integer | Wählt den Eingang. Dynamisches Profil basierend auf dem Gerät. |
| AudioMode | 🎛 Audio Mode | Integer | Wählt den Audio-Modus (Stereo, Dolby, etc.). Dynamisches Profil. |
| Voicing | 🗣 Voicing | Integer | Wählt das RoomPerfect Voicing-Profil. Dynamisches Profil. |
| AudioTypeIn | 📥 Audio Type In | String | Zeigt das eingehende Audioformat an (nur lesen). |
| AudioTypeOut | 📤 Audio Type Out | String | Zeigt das ausgehende Audioformat an (nur lesen). |

### 6. PHP-Befehlsreferenz

```php
LYNG_UpdateData(int $InstanceID);
```
Fragt alle Statuswerte (Power, Volume, Mute, Source, Modes, etc.) manuell vom Gerät ab. Wird auch vom Timer regelmäßig aufgerufen.
