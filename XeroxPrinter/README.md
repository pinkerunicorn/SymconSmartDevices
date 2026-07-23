# XeroxPrinter

Integriert netzwerkfähige Xerox Drucker (z.B. VersaLink) über SNMP (Simple Network Management Protocol) in IP-Symcon. Das Modul kann flexibel konfiguriert werden, um beliebige OIDs auszulesen.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Auslesen beliebiger SNMP OIDs vom Drucker (z.B. Füllstände der Toner, gedruckte Seiten insgesamt, etc.).
* Dynamische Anlage von Variablen in IP-Symcon basierend auf einer flexiblen Liste.
* Intelligente Icon-Vergabe (erkennt z.B. anhand des Namens, ob es sich um Tonerfarben handelt und setzt Tropfen-Icons).
* Automatisches Polling der Daten im eingestellten Intervall.

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0
* Ein netzwerkfähiger Xerox Drucker (oder kompatibler SNMP-Drucker).
* SNMP v1 oder v2c muss im Drucker-Webinterface aktiviert sein.

### 3. Installation

* Über den Module Store das Modul `XeroxPrinter` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartDevices`
* Eine neue Instanz für den Drucker anlegen.

### 4. Konfiguration

* **Host**: Die IP-Adresse oder der Hostname des Druckers.
* **Community**: Die SNMP Community (meist standardmäßig `public`).
* **UpdateInterval**: Abfrageintervall in Sekunden.
* **OIDList**: Eine anpassbare Liste von OIDs, die ausgelesen werden sollen. Ein paar nützliche Xerox Standard-OIDs sind bereits vorausgefüllt (z.B. Seitenzähler und Toner).

### 5. Statusvariablen und Profile

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| LastUpdate | ⏱ Letztes erfolgreiches Update | Integer | Unix-Timestamp der letzten fehlerfreien SNMP-Abfrage. |
| OID_* | *(Frei wählbar)* | Float | Werden dynamisch anhand der OIDList erzeugt (z.B. Toner Level, Seitenzähler). |

### 6. PHP-Befehlsreferenz

```php
XEROX_UpdateStatus(int $InstanceID);
```
Fragt manuell alle konfigurierten OIDs sofort über SNMP ab. (Wird normalerweise vom Timer automatisch ausgeführt).
