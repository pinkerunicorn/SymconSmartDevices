<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';
require_once __DIR__ . '/../libs/Trait_SmartHttp.php';

class VestaboardLocal extends IPSModuleStrict {
    use SmartLog_Trait;
    use DeviceAvailability_Trait;
    use SmartHttp_Trait;

    public function Create(): void {
        parent::Create();
        $this->DA_RegisterAvailability(900); // Alarm priority: 0 (Low)
        
        // Standard-Eigenschaften für das Konfigurationsformular anlegen
        $this->RegisterPropertyString("ApiUrl", "http://<IP-ADRESSE>:7000/local-api/message");
        $this->RegisterPropertyString("ApiKey", "");
        $this->RegisterPropertyString("AlignHorizontal", "center");
        $this->RegisterPropertyString("AlignVertical", "center");
    }

    public function ApplyChanges(): void {
        parent::ApplyChanges();

        $localUrl = $this->ReadPropertyString("ApiUrl");
        $apiKey = $this->ReadPropertyString("ApiKey");

        // Wenn kein Key oder noch die Platzhalter-IP drin ist -> Status Inaktiv/Fehler
        if (empty($localUrl) || strpos($localUrl, '<IP-ADRESSE>') !== false || empty($apiKey)) {
            $this->SetStatus(104); // IS_INACTIVE
        } else {
            $this->SetStatus(102); // IS_ACTIVE
        }
        $this->DA_ApplyPresentation();
    }

    /**
     * Sendet einen Text erst an den Vestaboard Cloud-Compiler und dann an das lokale Board
     * Aufruf: VESTA_SendMessage(InstanzID, "Dein Text");
     */
    public function SendMessage(string $Text): bool {
        $localUrl = $this->ReadPropertyString("ApiUrl");
        $apiKey = $this->ReadPropertyString("ApiKey");
        $justify = $this->ReadPropertyString("AlignHorizontal");
        $align = $this->ReadPropertyString("AlignVertical");

        if (empty($localUrl) || empty($apiKey)) {
            $this->SLogInfo('VestaboardLocal: ' . "Fehler: Lokale URL oder API-Key nicht konfiguriert.");
            return false;
        }

        // ====================================================================
        // SCHRITT 1: Payload für die Cloud-Übersetzung (Compiler) bauen
        // ====================================================================
        $cloudUrl = "https://vbml.vestaboard.com/compose";
        $inputArray = [
            "components" => [
                [
                    "style" => [
                        "justify" => $justify,
                        "align" => $align
                    ],
                    "template" => $Text
                ]
            ]
        ];

        // cURL Request an die Vestaboard Cloud
        $compiledBoardArray = $this->HttpRequest($cloudUrl, 'POST', ["Content-Type: application/json"], $inputArray, 5);
        if ($compiledBoardArray === null || empty($compiledBoardArray)) {
            $this->SLogInfo('VestaboardLocal: Cloud-Kompilierung fehlgeschlagen!');
            return false;
        }
        $compiledBoardJson = json_encode($compiledBoardArray);

        // ====================================================================
        // SCHRITT 2: Das fertig berechnete Array ans lokale Board senden
        // ====================================================================
        $headersLocal = [
            'X-Vestaboard-Local-Api-Key: ' . $apiKey,
            'Content-Type: application/json'
        ];

        $responseLocal = $this->HttpRequest($localUrl, 'POST', $headersLocal, $compiledBoardArray, 10, false);
        if ($responseLocal === null) {
            $this->DA_SetAvailable(false, 'Lokale API nicht erreichbar');
            $this->SLogInfo('VestaboardLocal: Lokaler API Fehler!');
            return false;
        }

        $this->DA_SetAvailable(true);
        return true;
    }

    

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "Label",
            "label": "Verbindungseinstellungen"
        },
        {
            "type": "Label",
            "label": "Hier stellst du die Verbindung zu deinem lokalen Vestaboard ein. Trage die IP-Adresse (oder URL) und den API-Key ein."
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "ValidationTextBox",
                    "name": "ApiUrl",
                    "caption": "Vestaboard Local API URL"
                }
            ]
        },
        {
            "type": "PasswordTextBox",
            "name": "ApiKey",
            "caption": "Local API Key"
        },
        {
            "type": "Label",
            "label": "Standard Ausrichtung (Alignment)"
        },
        {
            "type": "Label",
            "label": "Lege hier fest, wie dein Text standardmäßig auf dem Board ausgerichtet werden soll, falls du nichts anderes angibst."
        },
        {
            "type": "Select",
            "name": "AlignHorizontal",
            "caption": "Horizontal (Justify)",
            "options": [
                {
                    "label": "Links (left)",
                    "value": "left"
                },
                {
                    "label": "Mittig (center)",
                    "value": "center"
                },
                {
                    "label": "Rechts (right)",
                    "value": "right"
                },
                {
                    "label": "Verteilt (justified)",
                    "value": "justified"
                }
            ]
        },
        {
            "type": "Select",
            "name": "AlignVertical",
            "caption": "Vertikal (Align)",
            "options": [
                {
                    "label": "Oben (top)",
                    "value": "top"
                },
                {
                    "label": "Mittig (center)",
                    "value": "center"
                },
                {
                    "label": "Unten (bottom)",
                    "value": "bottom"
                },
                {
                    "label": "Verteilt (justified)",
                    "value": "justified"
                }
            ]
        },
        {
            "type": "Label",
            "label": " "
        },
        {
            "type": "Label",
            "label": "=========================================================="
        },
        {
            "type": "Label",
            "label": "Vestaboard Farbcodes & Sonderzeichen (Info)"
        },
        {
            "type": "Label",
            "label": "=========================================================="
        },
        {
            "type": "Label",
            "label": "Farben:"
        },
        {
            "type": "Label",
            "label": "{63} Rot    | {64} Orange | {65} Gelb"
        },
        {
            "type": "Label",
            "label": "{66} Grün   | {67} Blau   | {68} Violett"
        },
        {
            "type": "Label",
            "label": "{69} Weiß   | {70} Schwarz| {0}  Leer"
        },
        {
            "type": "Label",
            "label": " "
        },
        {
            "type": "Label",
            "label": "Sonderzeichen:"
        },
        {
            "type": "Label",
            "label": "{37} !   | {38} @   | {39} #   | {40} $   | {41} (   | {42} )"
        },
        {
            "type": "Label",
            "label": "{44} -   | {46} +   | {47} &   | {48} =   | {49} ;   | {50} :"
        },
        {
            "type": "Label",
            "label": "{52} '   | {53} \"   | {54} %   | {55} ,   | {56} .   | {59} /"
        },
        {
            "type": "Label",
            "label": "{60} ?   | {62} °"
        }
    ],
    "actions": [
        {
            "type": "Label",
            "label": "Testbereich"
        },
        {
            "type": "ValidationTextBox",
            "name": "TestText",
            "caption": "Test-Nachricht"
        },
        {
            "type": "Button",
            "label": "Nachricht an Vestaboard senden",
            "onClick": "VESTA_SendMessage($id, $TestText);"
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
