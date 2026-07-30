<?php

declare(strict_types=1);

class EMSESPDevice extends IPSModuleStrict
{
    public function Create(): void
    {
        //Never delete this line!
        parent::Create();
        
        $this->RegisterPropertyString('MQTTTopic', 'ems-esp');
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        $topic = $this->ReadPropertyString('MQTTTopic');
        $this->SetReceiveDataFilter('.*' . preg_quote($topic, '.') . '.*');
    }

    public function ReceiveData($JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!isset($data['Buffer'])) {
            return "";
        }
        
        $payload = json_decode(utf8_decode($data['Buffer']), true);
        if (!$payload || !isset($payload['Topic']) || !isset($payload['Payload'])) {
            return "";
        }
        
        $topic = $payload['Topic'];
        $message = $payload['Payload'];
        
        $this->SendDebug("MQTT RX Topic", $topic, 0);
        $this->SendDebug("MQTT RX Payload", $message, 0);
        
        $this->ProcessMQTTMessage($topic, $message);
        
        return "OK";
    }
    
    private function ProcessMQTTMessage(string $topic, string $message): void
    {
        $baseTopic = $this->ReadPropertyString('MQTTTopic');
        
        if (strpos($topic, $baseTopic) !== 0) {
            return;
        }
        
        $data = json_decode($message, true);
        if ($data === null) {
            return;
        }
        
        $subTopic = substr($topic, strlen($baseTopic) + 1);
        $prefix = str_replace('/', '_', $subTopic);
        
        $this->ParseJSONPayload($prefix, $data);
    }
    
    private function ParseJSONPayload(string $prefix, array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $this->ParseJSONPayload($prefix . '_' . $key, $value);
            } else {
                $this->UpdateOrCreateVariable($prefix . '_' . $key, $key, $value);
            }
        }
    }
    
    private function UpdateOrCreateVariable(string $ident, string $name, $value): void
    {
        // Determine type and format
        $type = 3; // String
        $presentation = 0; // Default
        $profile = '';
        
        $writableKeys = ['seltemp', 'mode', 'daytemp', 'nighttemp', 'manualtemp', 'heatingoff', 'wwcharge', 'setpoint'];
        $isWritable = in_array(strtolower($name), $writableKeys);
        
        if (is_bool($value)) {
            $type = 0; // Boolean
        } elseif (is_int($value)) {
            // Check for temperature scaling
            if (strpos($name, 'temp') !== false) {
                $type = 2; // Float
                $value = $value / 10.0;
                if ($isWritable) {
                    $profile = '~Temperature';
                } else {
                    $presentation = VARIABLE_PRESENTATION_VALUE_PRESENTATION;
                }
            } else {
                $type = 1; // Integer
            }
        } elseif (is_float($value)) {
            $type = 2; // Float
        }
        
        $this->MaintainVariable($ident, $name, $type, $profile, 0, true);
        
        $varID = $this->GetIDForIdent($ident);
        if ($varID) {
            if ($isWritable) {
                $this->EnableAction($ident);
            }
            
            SetValue($varID, $value);
            
            // Set custom presentation for IPS 8 ONLY for read-only variables
            if ($presentation !== 0 && !$isWritable) {
                IPS_SetVariableCustomPresentation($varID, [
                    'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}' // Value presentation
                ]);
            }
        }
    }
    
    public function RequestAction(string $Ident, mixed $Value): void
    {
        $baseTopic = $this->ReadPropertyString('MQTTTopic');
        
        // Extract command name from ident (last part)
        $parts = explode('_', $Ident);
        $cmd = array_pop($parts);
        $deviceType = $parts[0]; // e.g. thermostat from thermostat_data
        
        if ($deviceType === 'thermostat' || $deviceType === 'boiler' || $deviceType === 'mixer') {
            $cmdTopic = $baseTopic . '/' . $deviceType . '_cmd';
        } else {
            $cmdTopic = $baseTopic . '/system_cmd';
        }
        
        $payload = json_encode([
            'cmd' => $cmd,
            'value' => $Value
        ]);
        
        $data = [
            'DataID' => '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}',
            'Topic' => $cmdTopic,
            'Payload' => $payload
        ];
        
        $this->SendDataToParent(json_encode($data));
        
        // Optimistically set value in Symcon
        SetValue($this->GetIDForIdent($Ident), $Value);
    }
}
