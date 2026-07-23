# SonyBeamer

Integriert netzwerkfähige Sony Beamer (z.B. VPL-VW590ES) über eine direkte TCP/IP-Verbindung in IP-Symcon.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Steuerung des Betriebsstatus (Ein/Aus/Standby).
* Umschalten der Eingänge (HDMI 1, HDMI 2, Video 1, Component).
* Umschalten des Bildmodus (Dynamic, Standard, Cinema Film, Reference, Game, etc.).
* Auslesen der Betriebsstunden (Gerät gesamt).
* Auslesen der Lampenstunden (Lichtquelle).
* Auslesen und Anzeigen von internen Geräte-Warnungen.
* Automatisches Polling der Statuswerte über ein einstellbares Intervall.
* Versteckt erweiterte Bedien-Variablen im WebFront, wenn der Beamer ausgeschaltet ist.

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0
* Ein unterstützter Sony Beamer (getestet mit VPL-VW590ES), der in demselben Netzwerk erreichbar ist.

### 3. Installation

* Über den Module Store das Modul `SonyBeamer` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartDevices`
* Eine neue Instanz für den Beamer anlegen.

### 4. Konfiguration

* **Host**: Die IP-Adresse des Sony Beamers im lokalen Netzwerk.
* **Port**: Der TCP-Port zur Steuerung (Standard: `53595`).
* **UpdateInterval**: Das Intervall in Sekunden, in dem der aktuelle Status vom Gerät abgefragt wird (Standard: 20 Sekunden).

### 5. Statusvariablen und Profile

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| Power | 📺 Status | Boolean | Schaltet den Beamer ein oder aus. |
| Input | 🔌 Eingang | Integer | Wählt den Video-Eingang aus. |
| PictureMode | 🖼 Bildmodus | Integer | Wählt das Farbprofil/den Bildmodus des Beamers aus. |
| OperationTime | ⏱ Betriebsstunden | Integer | Gesamte Betriebsdauer des Geräts in Stunden (nur lesen). |
| LightSourceTime | 💡 Lampenstunden | Integer | Betriebsdauer der aktuellen Lampe/Lichtquelle in Stunden (nur lesen). |
| Warning | Warnungen | String | Zeigt eventuelle Fehler- oder Warnmeldungen des Geräts an (nur lesen). |

### 6. PHP-Befehlsreferenz

```php
SONY_UpdateStatus(int $InstanceID);
```
Fragt manuell alle Werte (Power, Eingang, Bildmodus, Zeiten, Fehler) vom Beamer ab. (Wird auch regelmäßig vom Timer ausgeführt).
