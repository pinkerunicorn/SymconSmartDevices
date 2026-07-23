# WithingsDevice

Integriert Gesundheits- und Messdaten (Waagen, Blutdruckmessgeräte, Uhren, etc.) aus der Withings Cloud via OAuth2 API in IP-Symcon. Das Modul enthält außerdem optional einen KI-gestützten Gesundheits-Coach basierend auf Google Gemini.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Automatischer Abruf der neuesten Messwerte aus der Withings Cloud (z.B. Gewicht, Körperfett, Herzfrequenz, Blutdruck, Temperatur, uvm.).
* Dynamische Generierung von Statusvariablen, je nachdem welche Messwerte von den Geräten übertragen werden.
* Vollwertiger OAuth2-Prozess über einen lokalen Webhook (Symcon Connect wird unterstützt).
* **KI-Gesundheits-Coach (Optional)**: Nutzt Google Gemini (über die `SmartGeminiIO`-Zentralinstanz), um Archiv-Daten der letzten X Tage auszuwerten und motivierende Text-Berichte (Trends, Warnungen) zu erstellen.
* Optionaler automatischer E-Mail-Versand des KI-Berichts über eine verknüpfte SMTP-Instanz.

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0
* Ein kostenloser Withings Entwickler-Account (Developer App registriert).
* Die `SmartGeminiIO` Instanz, falls die KI-Auswertung genutzt werden soll.
* Aktiviertes Archiv für die dynamisch erzeugten Variablen (wird teilweise automatisch eingerichtet, benötigt aber das Archive Control).

### 3. Installation

* Über den Module Store das Modul `WithingsDevice` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartDevices`
* Bei Withings eine App anlegen und die Callback-URL exakt nach der Anleitung im Konfigurationsformular eintragen (meist `https://<DEINE-CONNECT-ID>.ipmagic.de/hook/smartwithings`).
* Nach der Einrichtung in der Instanz auf "Mit Withings verbinden" klicken, den generierten Link im Browser öffnen und den Zugriff erlauben.

### 4. Konfiguration

* **ClientID**: Die Client ID aus dem Withings Entwickler-Portal.
* **ClientSecret**: Das Client Secret aus dem Withings Entwickler-Portal.
* **FetchInterval**: Das Intervall für den automatischen Abruf neuer Daten in Minuten (0 = deaktiviert).
* **EnableAI**: Aktiviert die automatische KI-Auswertung nach jedem Abruf.
* **ArchiveDays**: Bestimmt, wie viele Tage in die Vergangenheit die KI bei der Trendanalyse berücksichtigen soll (z.B. 28 für 4 Wochen).
* **SMTPInstanceID**: (Optional) Eine SMTP-Instanz-ID, an die der generierte Bericht als E-Mail verschickt wird.

### 5. Statusvariablen und Profile

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| LastMeasurement | ⏱ Letzte Messung | String | Zeitstempel der letzten erfassten Messung von Withings. |
| DailyReport | 🧠 Gemini Analyse | String | Der von Google Gemini generierte Textbericht über die Messwerte. |
| Measure_* | *(Dynamisch)* | Float | Werden je nach gemessenem Typ (z.B. Measure_1 = Gewicht) dynamisch erzeugt. |

### 6. PHP-Befehlsreferenz

```php
WITHINGS_GetAuthURL(int $InstanceID);
```
Generiert und gibt die URL für den OAuth2-Login-Prozess aus.

```php
WITHINGS_FetchMeasurements(int $InstanceID);
```
Holt manuell die neuesten Daten seit dem letzten erfolgreichen Abruf von der Withings API. (Wird auch vom Timer aufgerufen).

```php
WITHINGS_EvaluateWithGemini(int $InstanceID);
```
Löst manuell den KI-Gesundheits-Coach aus. Sammelt Daten, schickt sie an Gemini, speichert das Ergebnis in `DailyReport` und versendet (falls konfiguriert) eine E-Mail.
