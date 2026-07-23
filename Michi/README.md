# Michi

Integriert Rotel Michi Verstärker direkt via TCP/IP-Schnittstelle in IP-Symcon.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Steuerung des Power-Status (Ein/Standby).
* Steuerung der Display-Helligkeit (Dimmer).
* Auslesen statischer Geräteinformationen (Modell, Software Version, IP-Adresse, MAC-Adresse).
* Automatische Aktualisierung des Status durch ein einstellbares Polling-Intervall.
* Versteckt Bedienelemente und Statusinfos im WebFront, wenn das Gerät im Standby ist.
* Direkte TCP/IP-Verbindung ohne zusätzliche Sockets in Symcon.

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0
* Ein netzwerkfähiger Rotel Michi Verstärker

### 3. Installation

* Über den Module Store das Modul `Michi` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartDevices`

### 4. Konfiguration

* **Host**: IP-Adresse oder Hostname des Michi-Geräts.
* **Port**: TCP-Port (Standard: `9596`).
* **UpdateInterval**: Abfrage-Intervall in Sekunden (0 deaktiviert das Polling).

### 5. Statusvariablen und Profile

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| Power | Power | Boolean | Schaltet das Gerät ein oder aus (Standby). |
| Dimmer | Display Helligkeit | Integer | Regelt die Display-Helligkeit in Prozent (0-100%). |
| Model | Modell | String | Ausgelesenes Gerätemodell. |
| Version | Software Version | String | Firmware-Version des Geräts. |
| IP | IP-Adresse | String | IP-Adresse des Geräts laut Netzwerkkonfiguration. |
| MAC | MAC-Adresse | String | MAC-Adresse des Geräts. |

### 6. PHP-Befehlsreferenz

```php
MICHI_RequestStatus(int $InstanceID);
```
Fragt manuell den aktuellen Status (Dimmer, Source, etc.) vom Michi ab. Wird vom Update-Timer automatisch aufgerufen.
