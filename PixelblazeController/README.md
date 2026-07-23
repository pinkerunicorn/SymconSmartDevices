# PixelblazeController

Integriert den Pixelblaze LED-Controller via WebSocket-API in IP-Symcon.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Schalten (An/Aus) und Steuerung der Gesamthelligkeit (0-100%).
* Automatisches Auslesen der gespeicherten Muster/Programme vom Gerät.
* Auswahl des aktiven Programms direkt im WebFront über dynamisch generiertes Dropdown-Profil.
* Live-Anzeige des aktuell laufenden Programmnamens.
* Automatisches Polling (Status-Abfrage) über WebSocket.
* Auto-Reconnect-Funktion bei Verbindungsabbrüchen des WebSockets.
* Versteckt erweiterte Bedien-Variablen im WebFront, wenn der Controller ausgeschaltet ist.

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0
* Ein Pixelblaze V2 oder V3 Controller im lokalen Netzwerk.
* Ein WebSocket Client (Typ in IP-Symcon).

### 3. Installation

* Über den Module Store das Modul `PixelblazeController` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartDevices`
* Eine Instanz erstellen, als übergeordnete Instanz wird automatisch ein WebSocket Client benötigt (URL: `ws://[IP-des-Pixelblaze]:81`).

### 4. Konfiguration

* **AutoReconnectInterval**: Zeit in Sekunden, nach der bei getrennter Verbindung ein Reconnect-Versuch unternommen wird (Standard: 30).
* **FetchStateInterval**: Intervall in Sekunden für die zyklische Status-Abfrage (Standard: 10).

### 5. Statusvariablen und Profile

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| Power | 💡 Status | Boolean | Schaltet den Pixelblaze ein (mit der letzten Helligkeit) oder aus (0%). |
| Brightness | 🔆 Helligkeit | Integer | Regelt die Gesamthelligkeit (0-100%). |
| ActiveProgram | Programm | Integer | Erlaubt die Auswahl des aktiven Musters aus einem Dropdown (dynamisches Profil). |
| ActiveProgramName | Aktuelles Programm (Name) | String | Zeigt den Namen des momentan laufenden Musters als Text an. |

### 6. PHP-Befehlsreferenz

```php
PB_FetchPrograms(int $InstanceID);
```
Lädt die Liste aller auf dem Pixelblaze gespeicherten Programme (Pattern) herunter und generiert das Variablenprofil für die Dropdown-Auswahl.

```php
PB_FetchState(int $InstanceID);
```
Fragt manuell den aktuellen Status (Helligkeit, aktives Programm) ab. (Wird auch regelmäßig vom Timer ausgeführt).

```php
PB_Reconnect(int $InstanceID);
```
Erzwingt manuell einen Reconnect des WebSocket-Clients. (Wird auch bei Abbruch vom Timer ausgeführt).

```php
PB_SendJsonCommand(int $InstanceID, string $jsonString);
```
Ermöglicht das Senden eines rohen JSON-Befehls direkt an die WebSocket-API des Pixelblaze.
