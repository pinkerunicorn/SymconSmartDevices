# Airthings Wave Plus

Integriert den Airthings Wave Plus Raumluftsensor über MQTT (z.B. via ESPHome) in IP-Symcon.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Empfängt Sensordaten des Airthings Wave Plus via MQTT.
* Bereitet Werte für Temperatur, Luftfeuchtigkeit, Luftdruck, CO2, VOC, sowie Radon (Kurzzeit und Langzeit) auf.
* Berechnet den Batteriestand in Prozent aus der übertragenen Spannung.
* Watchdog-Funktion: Überwacht den Empfang der Daten und löst Alarm aus, wenn für eine bestimmte Zeit keine Daten empfangen werden.
* Setzt den Online-Status basierend auf dem MQTT-Status (`status`-Topic) des Gateways.

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0
* MQTT-Broker/Server in IP-Symcon konfiguriert
* ESPHome (oder ein anderes Gateway), das die Airthings Wave Plus Daten per MQTT sendet.

### 3. Installation

* Über den Module Store das Modul `Airthings Wave Plus` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartDevices`

### 4. Konfiguration

* **MQTTBaseTopic**: Das MQTT-Basis-Topic, unter dem die ESPHome-Daten gesendet werden (Standard: `airthings01`).
* **Timeout**: Zeit in Minuten, nach der der Watchdog einen Alarm auslöst, wenn keine Daten empfangen wurden (Standard: `30`).

### 5. Statusvariablen und Profile

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| Online | Online | Boolean | Gibt an, ob das Gerät bzw. Gateway online ist. |
| Alarm | Alarm | Boolean | Zeigt an, ob der Watchdog mangels neuer Daten ausgelöst wurde oder das Gateway offline ist. |
| AirTemp | Temperatur | Float | Gemessene Temperatur in °C. |
| AirHum | Luftfeuchtigkeit | Float | Gemessene relative Luftfeuchtigkeit in %. |
| AirPress | Luftdruck | Float | Gemessener Luftdruck in hPa. |
| AirBatt | Batterie | Float | Berechneter Batteriestand in %. |
| AirCO2 | CO2 | Integer | CO2-Wert in ppm. |
| AirVOC | VOC | Integer | VOC-Wert (Flüchtige organische Verbindungen) in ppb. |
| AirRadonST | Radon (Short Term) | Integer | Kurzzeit-Radonmesswert in Bq/m³. |
| AirRadonLT | Radon (Long Term) | Integer | Langzeit-Radonmesswert in Bq/m³. |

### 6. PHP-Befehlsreferenz

```php
AIRTHINGS_RequestUpdate(int $InstanceID);
```
Sendet einen Update-Befehl per MQTT, um das Gateway aufzufordern, neue Daten zu senden.

```php
AIRTHINGS_WatchdogTriggered(int $InstanceID);
```
Wird intern vom Timer aufgerufen, wenn das Timeout überschritten wurde. Setzt den Alarm und den Offline-Status.
