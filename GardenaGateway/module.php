<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';
require_once __DIR__ . '/../libs/Trait_DeviceRegistration.php';

class GardenaGateway extends IPSModuleStrict
{
    use SmartLog_Trait;
    use DeviceAvailability_Trait;
    use DeviceRegistration_Trait;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('AppKey', '');
        $this->RegisterPropertyString('AppSecret', '');
        
        $this->RegisterAttributeString('Token', '');
        $this->RegisterAttributeInteger('TokenExpiry', 0);
        $this->RegisterAttributeString('LocationID', '');
        $this->RegisterAttributeInteger('ApiCallsToday', 0);
        $this->RegisterAttributeString('ApiCallsDate', '');
        $this->RegisterAttributeInteger('CooldownUntil', 0);
        $this->RegisterAttributeInteger('BackoffCount', 0);

        $this->DA_RegisterAvailability(900);
        $this->DA_RegisterWatchdog();

        // API-Call Counter mit farbcodierten Intervallen
        $intervals = json_encode([
            [
                'IntervalMinValue' => 0, 'IntervalMaxValue' => 50,
                'ConstantActive' => false, 'ConstantValue' => '',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => true, 'SuffixValue' => ' / Tag',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'database',
                'ColorActive' => true, 'ColorValue' => 0x00CC00,
                'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
            ],
            [
                'IntervalMinValue' => 50, 'IntervalMaxValue' => 80,
                'ConstantActive' => false, 'ConstantValue' => '',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => true, 'SuffixValue' => ' / Tag',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'triangle-exclamation',
                'ColorActive' => true, 'ColorValue' => 0xFFAA00,
                'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
            ],
            [
                'IntervalMinValue' => 80, 'IntervalMaxValue' => 200,
                'ConstantActive' => false, 'ConstantValue' => '',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => true, 'SuffixValue' => ' / Tag',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'bell',
                'ColorActive' => true, 'ColorValue' => 0xFF4444,
                'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
            ]
        ]);

        $this->RegisterVariableInteger('ApiCalls', 'API Calls heute', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'server',
            'INTERVALS_ACTIVE' => true,
            'INTERVALS' => $intervals
        ], 100);

        $this->RegisterTimer('TokenRefresh', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "TokenRefresh", "");');
        $this->RegisterTimer('MidnightReset', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "MidnightReset", "");');
        $this->RegisterTimer('Reconnect', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "Reconnect", "");');
        $this->RegisterTimer('StartConnection', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "StartConnection", "");');
        $this->RegisterTimer('WSPing', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "WSPing", "");');
    }




    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        if ($this->ReadPropertyString('AppKey') !== '' && $this->ReadPropertyString('AppSecret') !== '') {
            $this->SetStatus(102);
            $this->SetMidnightResetTimer();
            // Start connection in the background to avoid blocking Symcon startup
            $this->SetTimerInterval('StartConnection', 2000);
        } else {
            $this->SetStatus(104);
        }

        $parentId = $this->GetParent();
        if ($parentId > 0) {
            $this->RegisterMessage($parentId, IM_CHANGESTATUS);
        }

        // Keep-Alive Ping (Gardena Idle-Timeout: 150s, Ping alle 120s wie HA/openHAB)
        $this->SetTimerInterval('WSPing', 120000);

        $this->DR_Register('DevicesHealth', [
            'Reachable_VarID' => $this->GetIDForIdent('DeviceAvailable'),
        ]);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ($Message == IM_CHANGESTATUS && $SenderID == $this->GetParent()) {
            // Geplanten Reconnect nicht stören
            if ($this->GetBuffer('IsReconnecting') === 'true') {
                return;
            }
            $this->SLogInfo("MessageSink: Parent Status changed to $Data[0]");
            // Status 200 = Faulty (e.g. disconnected), Status 201 = Not quite right
            if ($Data[0] >= 200) {
                $this->SLogWarning("WebSocket Client error (Status $Data[0]). Requesting new URL...");
                // Disconnect it to avoid auto-reconnect loops to the dead URL
                if (IPS_GetProperty($SenderID, 'Active')) {
                    IPS_SetProperty($SenderID, 'Active', false);
                    IPS_ApplyChanges($SenderID);
                }
                // Trigger background reconnect
                $this->SetTimerInterval('StartConnection', 3000);
            }
        }
    }

    private function SetMidnightResetTimer(): void
    {
        $now = time();
        $midnight = strtotime('tomorrow midnight');
        $this->SetTimerInterval('MidnightReset', ($midnight - $now) * 1000);
    }

    public function Authenticate(): bool
    {
        $appKey = $this->ReadPropertyString('AppKey');
        $appSecret = $this->ReadPropertyString('AppSecret');

        if (empty($appKey) || empty($appSecret)) {
            $this->SetStatus(104);
            return false;
        }

        $ch = curl_init('https://api.authentication.husqvarnagroup.dev/v1/oauth2/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $appKey,
            'client_secret' => $appSecret
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['access_token'])) {
                $this->WriteAttributeString('Token', $data['access_token']);
                $this->WriteAttributeInteger('TokenExpiry', time() + $data['expires_in']);
                $this->SLogInfo('Authentication successful');
                $this->SetStatus(102);
                $this->WriteAttributeInteger('BackoffCount', 0);
                return true;
            }
        }

        $this->SLogError("Authentication failed. HTTP: $httpCode, Response: $response");
        // Keep status < 200 so timers continue to work
        $this->SetStatus(104);
        return false;
    }

    public function GetLocationID(): string
    {
        $locationId = $this->ReadAttributeString('LocationID');
        if (!empty($locationId)) {
            return $locationId;
        }

        $response = $this->ApiRequest('GET', 'https://api.smart.gardena.dev/v1/locations');
        if ($response && isset($response['data']) && is_array($response['data']) && count($response['data']) > 0) {
            $locationId = $response['data'][0]['id'];
            $this->WriteAttributeString('LocationID', $locationId);
            return $locationId;
        }

        return '';
    }

    public function ConnectWebSocket(): void
    {
        $locationId = $this->GetLocationID();
        if (empty($locationId)) {
            $this->SLogError('No LocationID found, cannot connect WebSocket');
            return;
        }

        $body = json_encode([
            'data' => [
                'type' => 'WEBSOCKET',
                'attributes' => [
                    'locationId' => $locationId
                ]
            ]
        ]);

        $response = $this->ApiRequest('POST', 'https://api.smart.gardena.dev/v1/websocket', $body);

        if ($response && isset($response['data']['attributes']['url'])) {
            $wssUrl = $response['data']['attributes']['url'];
            
            $parentId = $this->GetParent();
            $this->SLogInfo('ConnectWebSocket: Got URL. Parent ID is ' . $parentId);
            
            if ($parentId > 0) {
                // Flag setzen um MessageSink-Race-Condition zu vermeiden
                $this->SetBuffer('IsReconnecting', 'true');
                
                // Ensure clean reconnect by deactivating first
                if (IPS_GetProperty($parentId, 'Active')) {
                    IPS_SetProperty($parentId, 'Active', false);
                    IPS_ApplyChanges($parentId);
                    IPS_Sleep(100);
                }
                
                IPS_SetProperty($parentId, 'URL', $wssUrl);
                IPS_SetProperty($parentId, 'Active', true);
                IPS_ApplyChanges($parentId);
                
                $this->SetBuffer('IsReconnecting', 'false');
                $this->SLogInfo('WebSocket URL updated and parent activated.');
                $this->SetStatus(102);
                
                // Fetch and push initial snapshot to all child instances
                $this->PushSnapshot();
            } else {
                $this->SLogError('ConnectWebSocket: Parent ID is 0, cannot set URL.');
            }
        } else {
            $this->SLogError('Failed to get WebSocket URL. Response: ' . json_encode($response));
        }
    }

    private function GetParent(): int
    {
        $instance = IPS_GetInstance($this->InstanceID);
        return $instance['ConnectionID'];
    }

    private function PushSnapshot(): void
    {
        $locationId = $this->GetLocationID();
        if (empty($locationId)) {
            $this->SLogError('PushSnapshot: LocationID empty');
            return;
        }

        $response = $this->ApiRequest('GET', "https://api.smart.gardena.dev/v1/locations/$locationId");
        if (!$response) {
            $this->SLogError('PushSnapshot: ApiRequest returned false');
            return;
        }

        if (isset($response['included']) && is_array($response['included'])) {
            $this->SLogInfo('Pushing initial snapshot to children. Found ' . count($response['included']) . ' items.');
            foreach ($response['included'] as $event) {
                if (!is_array($event)) {
                    continue;
                }

                $rawId = $event['id'] ?? '';
                $parts = explode(':', $rawId);
                $deviceId = $parts[0];
                $serviceId = isset($parts[1]) ? $parts[1] : '';

                $forward = [
                    'DataID'      => '{FE3A29C6-B712-4D85-9C3E-71A5F82DB430}',
                    'DeviceID'    => $deviceId,
                    'ServiceID'   => $serviceId,
                    'ServiceType' => $event['type'] ?? '',
                    'Attributes'  => $event['attributes'] ?? []
                ];
                $this->SendDataToChildren(json_encode($forward));
            }
        } else {
            $this->SLogError('PushSnapshot: No included array found in response');
        }
    }

    public function ApiRequest(string $method, string $url, string $body = '', bool $isRetry = false): array|false
    {
        $cooldown = $this->ReadAttributeInteger('CooldownUntil');
        if (time() < $cooldown) {
            $this->SLogError("Rate limit active. Cooldown until: " . date("Y-m-d H:i:s", $cooldown));
            return false;
        }

        $expiry = $this->ReadAttributeInteger('TokenExpiry');
        if (time() >= $expiry - 300) {
            if (!$this->Authenticate()) {
                return false;
            }
        }

        $date = date('Y-m-d');
        if ($this->ReadAttributeString('ApiCallsDate') !== $date) {
            $this->WriteAttributeString('ApiCallsDate', $date);
            $this->WriteAttributeInteger('ApiCallsToday', 0);
        }

        $token = $this->ReadAttributeString('Token');
        $appKey = $this->ReadPropertyString('AppKey');

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        $headers = [
            'Authorization: Bearer ' . $token,
            'X-Api-Key: ' . $appKey,
            'Authorization-Provider: husqvarna',
            'Accept: application/vnd.api+json'
        ];

        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            $headers[] = 'Content-Type: application/vnd.api+json';
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 401 && !$isRetry) {
            $this->SLogWarning('HTTP 401 - Erneuere Token und versuche erneut...');
            if ($this->Authenticate()) {
                return $this->ApiRequest($method, $url, $body, true);
            }
            return false;
        }

        if ($httpCode === 429) {
            $this->SLogError('HTTP 429 - Rate Limit erreicht! Sperre API für 24 Stunden.');
            $this->WriteAttributeInteger('CooldownUntil', time() + 86400);
            // Keep status < 200 so timers continue to work
            $this->SetStatus(104);
            return false;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            // API-Call erst nach Erfolg zählen (kein doppeltes Zählen bei 401-Retry)
            $calls = $this->ReadAttributeInteger('ApiCallsToday') + 1;
            $this->WriteAttributeInteger('ApiCallsToday', $calls);
            $this->SetValue('ApiCalls', $calls);
            if (empty($response)) {
                return [];
            }
            return json_decode($response, true);
        }

        $this->SLogError("API Request failed (HTTP $httpCode): $response");
        return false;
    }

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        $payload = $data['Buffer'] ?? '';
        if (empty($payload)) {
            return '';
        }

        // Bei WebSocket Client (Symcon) ist Buffer oft Hex-kodiert
        if (preg_match('/^[a-fA-F0-9]+$/', $payload)) {
            $payload = hex2bin($payload);
        }

        // DEBUG: Was kommt rein?
        $this->SendDebug('WS_RECV', substr($payload, 0, 500), 0);

        $this->DA_ResetWatchdog(300); 
        $this->DA_SetAvailable(true);
        $this->WriteAttributeInteger('BackoffCount', 0);

        $payloadArray = json_decode($payload, true);
        if (!is_array($payloadArray)) {
            return '';
        }


        // Gardena liefert entweder direkt ein Event-Objekt (z.B. {"type":"...", "id":"..."})
        // oder bei Initialisierung ein JSONAPI-Objekt mit "data" und "included"
        $events = [];

        if (isset($payloadArray['type'])) {
            $events[] = $payloadArray;
        } else {
            if (isset($payloadArray['data'])) {
                if (isset($payloadArray['data']['id'])) {
                    $events[] = $payloadArray['data'];
                } else {
                    foreach ($payloadArray['data'] as $item) {
                        $events[] = $item;
                    }
                }
            }
            if (isset($payloadArray['included']) && is_array($payloadArray['included'])) {
                foreach ($payloadArray['included'] as $item) {
                    $events[] = $item;
                }
            }
        }

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            
            $rawId = $event['id'] ?? '';
            $parts = explode(':', $rawId);
            $deviceId = $parts[0];
            $serviceId = isset($parts[1]) ? $parts[1] : '';

            $forward = [
                'DataID'      => '{FE3A29C6-B712-4D85-9C3E-71A5F82DB430}',
                'DeviceID'    => $deviceId,
                'ServiceID'   => $serviceId,
                'ServiceType' => $event['type'] ?? '',
                'Attributes'  => $event['attributes'] ?? []
            ];
            $this->SendDataToChildren(json_encode($forward));
        }
        return '';
    }

    public function ForwardData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || ($data['DataID'] ?? '') !== '{2C4A6B8D-F1E3-4A5C-9B7D-3E5F1A7C9B2D}') {
            return '';
        }

        $command = $data['Command'] ?? '';

        if ($command === 'GetDevices') {
            $locationId = $this->GetLocationID();
            if ($locationId) {
                $response = $this->ApiRequest('GET', "https://api.smart.gardena.dev/v1/locations/$locationId");
                return json_encode($response ?: ['error' => true]);
            }
            return json_encode(['error' => true, 'message' => 'No LocationID']);
        }

        if ($command === 'SendCommand') {
            $serviceId = $data['ServiceID'] ?? '';
            $actionBody = $data['Body'] ?? '';

            if (empty($serviceId) || empty($actionBody)) {
                return json_encode(['error' => true, 'message' => 'ServiceID oder Body fehlt']);
            }

            // Cooldown-Check
            $cooldown = $this->ReadAttributeInteger('CooldownUntil');
            if (time() < $cooldown) {
                return json_encode(['error' => true, 'message' => 'API gesperrt (Rate Limit) bis ' . date('H:i', $cooldown)]);
            }

            $url = "https://api.smart.gardena.dev/v1/command/$serviceId";
            $response = $this->ApiRequest('PUT', $url, $actionBody);
            if ($response === false) {
                return json_encode(['error' => true, 'message' => 'API-Anfrage fehlgeschlagen (Timeout/Auth/Netzwerk)']);
            }
            return json_encode($response);
        }

        return '';
    }

    public function GetConfigurationForParent(): string
    {
        return '{}';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'DA_Watchdog':
                $this->DA_HandleWatchdog();
                // Reconnect via Timer mit Exponential Backoff (NIEMALS IPS_Sleep!)
                $cooldown = $this->ReadAttributeInteger('CooldownUntil');
                if (time() >= $cooldown) {
                    $backoffCount = $this->ReadAttributeInteger('BackoffCount');
                    // 5s, 10s, 20s, 40s, 80s, 160s, 300s, ... max 900s (15min)
                    $delay = min((int)(5 * pow(2, $backoffCount) + random_int(0, 5)), 900);
                    $this->WriteAttributeInteger('BackoffCount', $backoffCount + 1);
                    $this->SLogInfo("WebSocket Watchdog ausgelöst. Reconnect in {$delay}s (Backoff #{$backoffCount})");
                    $this->SetTimerInterval('Reconnect', $delay * 1000);
                }
                break;

            case 'Reconnect':
                $this->SetTimerInterval('Reconnect', 0); // Timer sofort stoppen
                if ($this->Authenticate()) {
                    $this->ConnectWebSocket();
                    $this->WriteAttributeInteger('BackoffCount', 0);
                }
                break;
                
            case 'StartConnection':
                $this->SetTimerInterval('StartConnection', 0); // Execute once
                if ($this->Authenticate()) {
                    $this->ConnectWebSocket();
                }
                break;

            case 'TokenRefresh':
                $this->SLogInfo('TokenRefresh action triggered.');
                if ($this->Authenticate()) {
                    $this->SLogInfo('TokenRefresh: Auth success, calling ConnectWebSocket.');
                    $this->ConnectWebSocket();
                } else {
                    $this->SLogError('TokenRefresh: Auth failed.');
                }
                break;

            case 'MidnightReset':
                $this->WriteAttributeInteger('ApiCallsToday', 0);
                $this->WriteAttributeString('ApiCallsDate', date('Y-m-d'));
                $this->SetValue('ApiCalls', 0);
                $this->SetMidnightResetTimer();
                $this->SLogInfo('API-Call Counter zurückgesetzt.');
                break;

            case 'WSPing':
                $parentId = $this->GetParent();
                if ($parentId > 0 && @IPS_GetProperty($parentId, 'Active')) {
                    $this->SendDataToParent(json_encode([
                        'DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}',
                        'Buffer' => '{}'
                    ]));
                }
                break;

            default:
                throw new Exception('Ungültige Aktion: ' . $Ident);
        }
    }

    public function TestConnection(): string
    {
        if ($this->Authenticate()) {
            if ($this->GetLocationID()) {
                return 'Verbindung erfolgreich (Token + Location ID erhalten).';
            }
            return 'Auth OK, aber keine Location ID gefunden.';
        }
        return 'Authentifizierung fehlgeschlagen.';
    }

    public function RefreshToken(): string
    {
        if ($this->Authenticate()) {
            $this->ConnectWebSocket();
            return 'Token erfolgreich erneuert und WebSocket verbunden.';
        }
        return 'Fehler bei der Token-Erneuerung.';
    }
}
