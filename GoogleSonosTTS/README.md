# GoogleSonosTTS

Das Modul ermöglicht die Text-to-Speech (TTS) Sprachausgabe über Sonos Lautsprecher unter Verwendung der Google Cloud TTS API.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Erzeugt Sprachausgaben aus Text über die Google Cloud TTS API (unterstützt SSML).
* Spielt die erzeugte Audiodatei auf beliebig vielen Sonos-Instanzen ab, mit individueller Lautstärkeneinstellung.
* Pausiert auf Wunsch automatisch konfigurierte Roon-Systeme während der Durchsage und setzt sie danach fort.
* Lokaler Cache der MP3-Dateien zur Schonung des API-Kontingents (automatischer Cleanup von alten Dateien).
* Bereitstellung der Audiodateien über einen Webhook für die Sonos-Geräte.

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0
* Gültiger Google Cloud API Key mit aktivierter Text-to-Speech API
* Installiertes Symcon-Sonos-Modul für die Audio-Ausgabe (benötigt `SNS_PlayFiles` Funktion)

### 3. Installation

* Über den Module Store das Modul `GoogleSonosTTS` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartDevices`

### 4. Konfiguration

* **ApiKey**: Google Cloud API Key.
* **VoiceName**: Sprache und Stimme für die Sprachausgabe (z.B. de-DE-Wavenet-C).
* **SonosInstances**: Liste der Sonos-Instanzen für die Ausgabe, inkl. individueller Lautstärke.
* **RoonInstances**: Liste der Roon-Instanzen, die während der Ansage pausiert werden sollen.
* **SymconBaseURL**: Base URL deines IP-Symcon Servers, über die Sonos die MP3-Datei abruft.
* **SpeakingRate**: Sprechgeschwindigkeit (0.25 bis 4.0).
* **Pitch**: Tonhöhe (-20.0 bis 20.0).

### 5. Statusvariablen und Profile

Dieses Modul legt keine eigenen Statusvariablen an, sondern dient als Hilfsmodul für Skripte.

### 6. PHP-Befehlsreferenz

```php
GSTTS_PlayMessage(int $InstanceID, string $Text);
```
Erzeugt die Sprachausgabe für den übergebenen Text und spielt sie auf den konfigurierten Sonos-Boxen ab. Unterstützt SSML (Text muss mit `<speak>` beginnen).

```php
GSTTS_ClearCache(int $InstanceID);
```
Löscht manuell den kompletten Ordner mit zwischengespeicherten MP3-Dateien.

```php
GSTTS_CleanupCache(int $InstanceID);
```
Wird einmal am Tag automatisch aufgerufen und löscht MP3-Dateien, die älter als 30 Tage sind.

```php
GSTTS_ResumeRoon(int $InstanceID);
```
Setzt manuell (bzw. vom Timer nach der Ansage aufgerufen) die pausierten Roon-Instanzen fort.
