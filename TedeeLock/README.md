# TedeeLock

Integriert ein Tedee Smart Lock über die lokale API der Tedee-Bridge in IP-Symcon. Die Steuerung und Statusabfrage erfolgen komplett lokal, ohne Abhängigkeit von der Tedee-Cloud.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Steuerung des Schlosses (Entriegeln, Verriegeln, Falle ziehen, Entriegeln & Falle ziehen).
* Anzeige des aktuellen Schloss-Status (z.B. Verriegelt, Entriegelt, Kalibriert).
* Anzeige des Batteriestatus und ob das Schloss gerade geladen wird.
* Live-Updates via Webhook: Das Modul registriert automatisch einen Webhook auf der Tedee-Bridge, wodurch Statusänderungen sofort an Symcon gepusht werden (kein Polling nötig).
* Unterstützung von verschlüsselten API-Tokens für maximale Sicherheit im lokalen Netzwerk.

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0
* Ein Tedee Smart Lock und eine Tedee Smart Bridge
* Aktivierte "Local API" in der Tedee App für die Bridge (inklusive generiertem Token)

### 3. Installation

* Über den Module Store das Modul `TedeeLock` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartDevices`
* Bei der Einrichtung sicherstellen, dass die IP-Adresse des Symcon Servers für die Tedee Bridge erreichbar ist (für Webhooks).

### 4. Konfiguration

* **BridgeIP**: Lokale IP-Adresse der Tedee Bridge.
* **ApiToken**: Der in der Tedee App generierte Local API Token.
* **UseEncryptedToken**: (Empfohlen) Nutzt Hash-Verschlüsselung bei der Kommunikation, wenn dies in der Tedee App so konfiguriert wurde.
* **LockID**: ID des Schlosses (nur bei mehreren Schlössern relevant; 0 wählt automatisch das erste gefundene Schloss).
* **SymconBaseURL**: Externe IP/URL des Symcon Servers, über die die Tedee-Bridge Webhooks senden kann (inkl. Port, z.B. http://192.168.1.10:3777).

### 5. Statusvariablen und Profile

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| LockState | Schloss Status | Integer | Aktueller Zustand (Verriegelt, Entriegelt, etc. - via Profil). |
| BatteryLevel | Batterie | Integer | Ladezustand des Akkus in %. |
| IsCharging | Wird geladen | Boolean | Gibt an, ob das Schloss momentan per Kabel geladen wird. |
| LockControl | Steuerung | Integer | Erlaubt die Steuerung (Entriegeln, Verriegeln, Falle ziehen). |

### 6. PHP-Befehlsreferenz

```php
TEDEE_UpdateStatus(int $InstanceID);
```
Fragt manuell den Status des Schlosses von der Bridge ab.

```php
TEDEE_RegisterWebhookAtBridge(int $InstanceID);
```
Registriert den Symcon-Webhook auf der Tedee Bridge. Alte, zur gleichen Instanz gehörende Webhooks auf der Bridge werden dabei automatisch aufgeräumt.
