<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';
require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceRegistration.php';

class XeroxPrinter extends IPSModuleStrict
{
    use SmartLog_Trait;

    use DeviceAvailability_Trait;
    use DeviceRegistration_Trait;

    public function Create(): void
    {
        parent::Create();
        $this->DA_RegisterAvailability(900); // Alarm priority: 0 (Low - it's just a printer)

        // Eigenschaften registrieren
        $this->RegisterPropertyString('Host', '10.1.20.30');
        $this->RegisterPropertyString('Community', 'public');
        $this->RegisterPropertyInteger('UpdateInterval', 60);

        // Standard-OIDs als JSON Liste registrieren
        $defaultOIDs = json_encode([
            ['Name'=> 'Seiten insgesamt', 'OID'=> '1.3.6.1.4.1.253.8.53.13.2.1.6.1.20.200'],
            ['Name'=> 'Schwarzweißseiten', 'OID'=> '1.3.6.1.4.1.253.8.53.13.2.1.6.1.20.201'],
            ['Name'=> 'Farbseiten', 'OID'=> '1.3.6.1.4.1.253.8.53.13.2.1.6.1.20.202'],
            ['Name'=> 'Restseiten Cyan', 'OID'=> '1.3.6.1.2.1.43.11.1.1.9.1.4'],
            ['Name'=> 'Restseiten Magenta', 'OID'=> '1.3.6.1.2.1.43.11.1.1.9.1.3'],
            ['Name'=> 'Restseiten Gelb', 'OID'=> '1.3.6.1.2.1.43.11.1.1.9.1.2'],
            ['Name'=> 'Restseiten Schwarz', 'OID'=> '1.3.6.1.2.1.43.11.1.1.9.1.1']
        ]);
        $this->RegisterPropertyString('OIDList', $defaultOIDs);

        // Timer registrieren
        $this->RegisterTimer('UpdateTimer', 0, 'XEROX_UpdateStatus($_IPS[\'TARGET\']);');

        // Feste Variablen
        $this->RegisterVariableInteger('LastUpdate', 'â± Letztes erfolgreiches Update', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'clock-rotate-left'], 999);
    }

    public function Destroy(): void
    {
        parent::Destroy();
        $this->DR_Unregister();
    }


    public function ApplyChanges(): void{
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        if (empty($this->ReadPropertyString('Host'))) {
            $this->SetStatus(104);
            return;
        $this->DR_Register('DevicesGenericSensor');
        }


        // OID Liste auslesen und Variablen anlegen
        $oidList = json_decode($this->ReadPropertyString('OIDList'), true);
        $keepVariables = ['LastUpdate'];

        if (is_array($oidList)) {
            foreach ($oidList as $index => $item) {
                $oid = trim($item['OID']);
                $name = trim($item['Name']);
                
                if (empty($oid) || empty($name)) {
                    continue;
                }
                
                // Generiere einen sicheren, eindeutigen Ident aus der OID
                $ident = 'OID_'. str_replace('.', '_', ltrim($oid, '.'));
                
                $icon = 'Document';
                if (stripos($name, 'Cyan') !== false || stripos($name, 'Magenta') !== false || stripos($name, 'Gelb') !== false || stripos($name, 'Yellow') !== false || stripos($name, 'Schwarz') !== false || stripos($name, 'Black') !== false) {
                    $icon = 'Drop';
                }
                
                $this->RegisterVariableFloat($ident, $name, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => $icon], $index * 10);
                $keepVariables[] = $ident;
            }
        }

        // Cleanup alter Variablen (nicht mehr in der Liste oder alte statische Idents)
        $children = IPS_GetChildrenIDs($this->InstanceID);
        foreach ($children as $childID) {
            $obj = IPS_GetObject($childID);
            if ($obj['ObjectType'] == 2) { // Ist eine Variable
                $ident = $obj['ObjectIdent'];
                if (!in_array($ident, $keepVariables)) {
                    $this->UnregisterVariable($ident);
                }
            }
        }

        $interval = $this->ReadPropertyInteger('UpdateInterval');
        if ($interval > 0) {
            $this->SetTimerInterval('UpdateTimer', $interval * 1000);
        } else {
            $this->SetTimerInterval('UpdateTimer', 0);
        }

    }

    public function UpdateStatus(): void
    {
        $host = $this->ReadPropertyString('Host');
        $community = $this->ReadPropertyString('Community');

        if (empty($host)) {
            $this->SendDebug("Update", "Kein Host konfiguriert.", 0);
            return;
        }

        $oidList = json_decode($this->ReadPropertyString('OIDList'), true);
        if (!is_array($oidList) || empty($oidList)) {
            $this->SendDebug("Update", "Keine OIDs konfiguriert.", 0);
            return;
        }

        require_once(__DIR__ . '/../libs/phpSNMP/snmp.php');
        $snmp = new snmp();
        $snmp->version = SNMP_VERSION_2;
        $success = false;

        foreach ($oidList as $item) {
            $oid = trim($item['OID']);
            $name = trim($item['Name']);
            if (empty($oid) || empty($name)) continue;

            $ident = 'OID_'. str_replace('.', '_', ltrim($oid, '.'));
            
            $result = @$snmp->get($host, $oid, ['community'=> $community]);
            
            if ($result !== false && $result !== null && is_array($result)) {
                // Das phpSNMP-Skript gibt ein Array zurück: [oid => wert]
                $raw_value = (string)current($result);
                // Begrenze Länge und entferne Null-Bytes, um PCRE/Speicher-Bugs in PHP zu vermeiden
                $raw_value = substr(str_replace("\0", "", $raw_value), 0, 255);
                
                // Bereinigen, falls Text wie "Gauge32:"oder ähnliches drin steht (ohne preg_replace)
                $value = '';
                $len = strlen($raw_value);
                for ($i = 0; $i < $len; $i++) {
                    $c = $raw_value[$i];
                    if (($c >= '0' && $c <= '9') || $c === '.') {
                        $value .= $c;
                    }
                }
                
                if (is_numeric($value) && $value !== '') {
                    $this->SendDebug("SNMP", "$name ($oid) = $value", 0);
                    $this->SetValue($ident, (float)$value);
                    $success = true;
                } else {
                    $this->SendDebug("SNMP", "$name ($oid) = ungültiger Wert ($raw_value)", 0);
                }
            } else {
                $this->SendDebug("SNMP-Error", "Fehler beim Abrufen von $name ($oid)", 0);
            }
            
            // Kleine Pause
            IPS_Sleep(50);
        }

        if ($success) {
            $this->SetValue('LastUpdate', time());
            $this->DA_SetAvailable(true);
        } else {
            $this->DA_SetAvailable(false, 'SNMP-Abfrage fehlgeschlagen');
        }
    }

    

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "Label",
            "caption": "Hier stellst du ein, wie ich deinen Xerox Drucker erreichen kann. Gib die IP und deine SNMP Community an."
        },
        {
            "type": "ExpansionPanel",
            "caption": "âš™ Allgemeine Einstellungen",
            "items": [
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "ValidationTextBox",
                            "name": "Host",
                            "caption": "IP-Adresse / Hostname"
                        },
                        {
                            "type": "ValidationTextBox",
                            "name": "Community",
                            "caption": "SNMP Community"
                        }
                    ]
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "NumberSpinner",
                            "name": "UpdateInterval",
                            "caption": "Abfrage-Intervall (Sekunden)",
                            "suffix": "s",
                            "minimum": 0
                        }
                    ]
                }
            ]
        },
        {
            "type": "Label",
            "caption": "Was soll ich auslesen? Trage hier die SNMP OIDs ein, die du überwachen möchtest. Den Namen kannst du frei wählen."
        },
        {
            "type": "List",
            "name": "OIDList",
            "caption": "Auszulesende OIDs",
            "add": true,
            "delete": true,
            "changeOrder": true,
            "columns": [
                {
                    "caption": "Name",
                    "name": "Name",
                    "width": "auto",
                    "add": "Neue Variable",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "OID",
                    "name": "OID",
                    "width": "150px",
                    "add": "1.3.6.1.2.1...",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                }
            ]
        }
    ],
    "actions": [
        {
            "type": "Button",
            "label": "Status jetzt aktualisieren",
            "onClick": "XEROX_UpdateStatus($id);"
        }
    ],
    "status": [
        {"code": 102, "icon": "active",   "caption": "Aktiv"},
        {"code": 104, "icon": "inactive", "caption": "Nicht konfiguriert"},
        {"code": 200, "icon": "error",    "caption": "Verbindungsfehler"}
    ]
}
EOT;
    }
}


