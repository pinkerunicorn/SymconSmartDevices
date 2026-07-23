# RoonZone

Integriert eine Roon Audio-Zone über MQTT in IP-Symcon.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Anzeige der aktuellen Titelinformationen (Titel, Künstler, Album).
* Steuerung und Anzeige des Wiedergabestatus (Play, Pause, Stop, Previous, Next).
* Steuerung und Anzeige der Zonen-Lautstärke in Prozent.
* Bidirektionale Kommunikation über MQTT (kompatibel z.B. mit roon-extension-mqtt).

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0
* Ein aktiver MQTT Server/Client in IP-Symcon
* Roon Core mit installierter MQTT Extension (z.B. roon-extension-mqtt), welche die Daten veröffentlicht.

### 3. Installation

* Über den Module Store das Modul `RoonZone` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartDevices`
* Eine neue Instanz für die Roon Zone erstellen (die MQTT Splitter Instanz wird dabei automatisch eingebunden).

### 4. Konfiguration

* **ZoneName**: Der exakte Name der Roon Zone, wie er auch in der Roon App und im MQTT-Topic verwendet wird (inklusive Leerzeichen, falls vorhanden).

### 5. Statusvariablen und Profile

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| State | ℹ Status | Integer | Zeigt den aktuellen Wiedergabestatus an und ermöglicht die Transport-Steuerung (Previous, Stop, Play, Pause, Next). |
| Title | 🎵 Titel | String | Aktuell spielender Titel. |
| Artist | 🎤 Künstler | String | Interpret des aktuellen Titels. |
| Album | 💿 Album | String | Album des aktuellen Titels. |
| Volume | 🔊 Lautstärke | Integer | Lautstärke der Zone in Prozent (0-100%). |

### 6. PHP-Befehlsreferenz

```php
ROON_SendCommand(int $InstanceID, string $command);
```
Sendet einen benutzerdefinierten Transport-Befehl an die Zone (z.B. 'play', 'pause', 'stop', 'next', 'previous').

```php
ROON_SetVolume(int $InstanceID, int $volume);
```
Setzt die Lautstärke direkt (wird intern beim Ändern des Volume-Sliders aufgerufen).

```php
ROON_TogglePlayPause(int $InstanceID);
```
Schaltet zwischen Wiedergabe und Pause um.

```php
ROON_NextTrack(int $InstanceID);
```
Springt zum nächsten Titel.

```php
ROON_PreviousTrack(int $InstanceID);
```
Springt zum vorherigen Titel.
