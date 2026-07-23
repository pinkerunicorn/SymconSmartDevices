# Vestaboard Generator

Ein intelligenter Generator zur formatierbaren Textaufbereitung für ein Vestaboard. Er überwacht konfigurierbare IP-Symcon Variablen, formatiert diese basierend auf Regeln und sendet das Ergebnis zeilenweise an eine Vestaboard-Ausgabe-Instanz (z.B. Vestaboard Local).

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Überwachung beliebig vieler IP-Symcon Variablen auf Änderungen.
* Zuweisung von Formatierungs-Regeln je nach Variablentyp (z.B. Temperatur-Farbcodes, Prozentbalken, Müll-Kalender, Alarm/Ereignis).
* Definition von Prioritäten ("Sofort", "Hoch", "Niedrig"), um wichtige Infos bei Platzmangel (max. 6 Zeilen) zu bevorzugen.
* Mehrere "Ansichten" (Views), die durch eine Kontroll-Variable durchgeschaltet werden können.
* Beachtung von Ruhezeiten (SleepTimer), in denen das Board nicht rattert oder einen fixen Sleep-Text anzeigt.
* Berücksichtigung eines Haus-Modus (z.B. Abwesenheit, Heimkino), um Aktualisierungen temporär zu pausieren.

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0
* Eine existierende Instanz zur Ansteuerung des Vestaboards (z.B. "Vestaboard Local").

### 3. Installation

* Über den Module Store das Modul `Vestaboard Generator` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartDevices`
* Bei der Einrichtung muss die Instanz-ID des eigentlichen Vestaboard-Treibers (Vestaboard Local) angegeben werden.

### 4. Konfiguration

* **VariablesList**: Liste der zu überwachenden Variablen mit Einstellungen zu Ansicht, Priorität, Typ und Formatstring (inkl. Farbcodes).
* **InstIdVestaboardLocal**: Die Instanz-ID des Vestaboard Local Moduls, an das der generierte Text gesendet wird.
* **ManualUpdateTriggerID**: (Optional) Variablen-ID, die bei Änderung ein manuelles Board-Update erzwingt.
* **ActiveViewVariableID**: (Optional) Variablen-ID, die bestimmt, welche Ansicht (1-6) generiert werden soll.
* **HouseModeVariableID**: (Optional) Globale Variable für den Hausmodus (z.B. zur Erkennung von Heimkino- oder Abwesenheitsmodus).
* **ActiveTimeStart** / **ActiveTimeEnd**: Start- und Endzeit (Stunde) der aktiven Phase (Tageszeit), in der das Board Aktualisierungen anzeigt.
* **UpdateDelayMinutes**: Verzögerung in Minuten nach einer Variablen-Änderung, bis das Board tatsächlich aktualisiert (schont das Board und bündelt Änderungen).
* **SleepText**: Ein statischer Text, der auf dem Board angezeigt wird, sobald die Ruhezeit oder der Heimkino-Modus beginnt.

### 5. Statusvariablen und Profile

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| Line1 - Line6 | Zeile 1-6 | String | Der generierte, bereinigte Text (ohne Farbcodes) für die jeweilige Zeile (hauptsächlich zur Vorschau in Symcon). |

### 6. PHP-Befehlsreferenz

```php
VESTA_UpdateBoard(int $InstanceID, bool $force = false);
```
Generiert den Text anhand der Variablen neu und sendet ihn ans Board. `$force = true` erzwingt die Aktualisierung auch außerhalb der aktiven Zeiten (oder im Verzögerungs-Fenster).

```php
VESTA_PushAlert(int $InstanceID, string $text, bool $resume = false);
```
Sendet einen Alarm-Text sofort auf das Board unter Umgehung aller Regeln. Wenn `$resume = true`, wird nach kurzer Zeit wieder der generierte Normal-Zustand hergestellt.
