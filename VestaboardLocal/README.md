# Vestaboard Local

Ermöglicht das Senden von Text-Nachrichten an ein Vestaboard über dessen lokale API (Local API). Da das Vestaboard lokal ein spezielles Array-Format erwartet, nutzt dieses Modul den offiziellen Vestaboard Cloud-Compiler (`vbml.vestaboard.com`), um normalen Text (inklusive Vestaboard-Farbcodes) in das lokale Array-Format umzuwandeln und direkt an das Board im eigenen Netzwerk zu senden.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [PHP-Befehlsreferenz](#5-php-befehlsreferenz)

### 1. Funktionsumfang

* Übersetzung von reinem Text (mit `{63}` Farbcodes etc.) in das Array-Format der Vestaboard Local API.
* Lokaler Versand der kompilierten Nachrichten an das Board (schneller als über die Cloud und weniger restriktiv bzgl. Rate-Limits).
* Einstellbare Standard-Ausrichtung (horizontal und vertikal) für gesendeten Text.
* Dient als Basis/Ausgabe-Instanz für den "Vestaboard Generator".

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0
* Ein Vestaboard mit aktivierter "Local API" (dies erfordert ein aktives "Vestaboard+" Abo oder den einmaligen Freischalt-Kauf für die Local API beim Hersteller).
* Ein generierter "Local API Key".
* Internetverbindung für IP-Symcon (für die Nutzung des Cloud-Compilers).

### 3. Installation

* Über den Module Store das Modul `Vestaboard Local` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartDevices`
* Eine neue Instanz für das Vestaboard anlegen.

### 4. Konfiguration

* **ApiUrl**: Die lokale URL der Vestaboard API (z.B. `http://192.168.1.100:7000/local-api/message`).
* **ApiKey**: Der in der Vestaboard Entwickler-Plattform generierte Local API Key.
* **AlignHorizontal**: Standard-Ausrichtung horizontal (`left`, `center`, `right`, `justified`).
* **AlignVertical**: Standard-Ausrichtung vertikal (`top`, `center`, `bottom`, `justified`).

### 5. PHP-Befehlsreferenz

```php
VESTA_SendMessage(int $InstanceID, string $Text);
```
Kompiliert den übergebenen Text und sendet ihn direkt an das Vestaboard. Unterstützt Farbcodes wie z.B. `{63}` für Rot, `{67}` für Blau, etc.
Gibt `true` bei Erfolg, andernfalls `false` zurück.
