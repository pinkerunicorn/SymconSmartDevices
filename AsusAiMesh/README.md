# ASUS AiMesh – IP-Symcon Modul

Dieses Modul ermöglicht die Überwachung und Steuerung eines ASUS AiMesh WiFi-Netzwerks direkt aus IP-Symcon heraus.

## Features

### Monitoring
- **Mesh-Status:** Alle Nodes online/offline, IP, Firmware, Anzahl verbundener Clients
- **Client-Übersicht:** HTML-Tabelle aller verbundenen Geräte mit Node-Zuordnung und Verbindungsart
- **System-Monitoring:** CPU- und RAM-Auslastung pro Node
- **Temperatur:** CPU- und WiFi-Chip-Temperaturen (2.4 GHz, 5 GHz, 6 GHz)
- **Firmware-Update:** Automatische Prüfung ob ein Firmware-Update verfügbar ist

### Steuerung
- **LED:** Router-LEDs ein-/ausschalten
- **WiFi-Bänder:** 2.4 GHz, 5 GHz (Band 1), 5 GHz (Band 2/Backhaul), 6 GHz einzeln ein-/ausschalten
- **Gästenetzwerk:** Gast-WLAN (Partynetzwerk) ein-/ausschalten
- **Reboot:** Router über die Weboberfläche neustarten

## Voraussetzungen

- ASUS Router mit AiMesh-Unterstützung (getestet mit ZenWiFi BQ16)
- IP-Symcon 9.0 oder höher
- Netzwerkzugriff zum AiMesh-Controller (HTTP oder HTTPS)
- Admin-Zugangsdaten für die Router-Weboberfläche

## Konfiguration

| Parameter | Beschreibung | Standard |
|---|---|---|
| Host | Hostname oder IP-Adresse des AiMesh-Controllers | – |
| Benutzername | Router Admin-Benutzername | `admin` |
| Passwort | Router Admin-Passwort | – |
| HTTPS verwenden | HTTPS statt HTTP nutzen (empfohlen) | An |
| Aktualisierungsintervall | Polling-Intervall in Sekunden | 60 |
| Anzahl Mesh-Nodes | Wie viele Nodes im Mesh erwartet werden | 4 |

## Technische Details

Das Modul nutzt die undokumentierte ASUS HTTP-API, die auch von der ASUS Router App und der Home Assistant AsusRouter-Integration verwendet wird.

### Verwendete Endpunkte
- `/login.cgi` – Authentifizierung
- `/appGet.cgi` – Datenabfrage (mehrere Hooks kombiniert in einem Request)
- `/ajax_coretmp.asp` – Temperatur-Daten
- `/apply.cgi` – Steuerungsbefehle

### Performance
Alle Daten werden in nur **2 HTTP-Requests** pro Polling-Zyklus abgefragt (kombinierte Hooks + Temperatur), um die Last auf dem Router minimal zu halten.

### Bekannte Einschränkungen
- Die API ist undokumentiert und kann sich bei Firmware-Updates ändern
- Firmware-Version 388.10 (Merlin) kann Probleme mit dem HTTP-Daemon verursachen
- Im Access-Point-Modus sind WAN-Status-Informationen nicht verfügbar
- Temperatur-Daten sind nur vom Controller-Node (Node 1) verfügbar

## Autor

Florian Graßinger  
https://github.com/pinkerunicorn/SymconSmartDevices
