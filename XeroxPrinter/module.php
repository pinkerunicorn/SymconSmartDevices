<?php

declare(strict_types=1);

class XeroxPrinter extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
        if (function_exists('IPS_SetVariableCustomPresentation')) {
            foreach(['LastUpdate'] as $ident) {
                $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
                if ($id !== false) @IPS_SetVariableCustomPresentation($id, []);
            }
        }

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
        $this->RegisterVariableInteger('LastUpdate', '⏱ Letztes erfolgreiches Update', '', 999);
        IPS_SetIcon($this->GetIDForIdent('LastUpdate'), 'Clock');
    }

    public function ApplyChanges(): void{
        parent::ApplyChanges();

        
        // Set LastUpdate Icon
        IPS_SetIcon($this->GetIDForIdent('LastUpdate'), 'Clock');

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
                
                $this->RegisterVariableFloat($ident, $name, '', $index * 10);
                IPS_SetIcon($this->GetIDForIdent($ident), $icon);
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
            $this->UpdateStatus();
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
                
                // Bereinigen, falls Text wie "Gauge32:"oder ähnliches drin steht
                $value = preg_replace('/[^0-9.]/', '', $raw_value);
                
                if (is_numeric($value)) {
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
        }
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        IPS_LogMessage('SmartVillaKunterbunt', 'XeroxPrinter: '. $Message);
        return true;
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
            "caption": "⚙ Allgemeine Einstellungen",
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
    ]
}
EOT;
    }
}


