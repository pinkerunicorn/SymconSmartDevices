<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';

class GardenaConfigurator extends IPSModuleStrict
{
    use SmartLog_Trait;

    public function Create(): void
    {
        parent::Create();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
    }

    public function GetConfigurationForm(): string
    {
        if (!$this->HasActiveParent()) {
            return json_encode([
                'elements' => [
                    [
                        'type'  => 'Label',
                        'label' => 'Bitte zuerst das Gateway verbinden und aktivieren.'
                    ]
                ]
            ]);
        }

        $response = $this->SendDataToParent(json_encode([
            'DataID' => '{A4B6C8D2-E1F3-4A5C-9B7D-3E5F7A9C1B2D}',
            'Command' => 'GetDevices'
        ]));


        $apiData = [];
        if ($response !== false) {
            $apiData = json_decode($response, true) ?? [];
        }

        if (empty($apiData) || isset($apiData['error'])) {
            return json_encode([
                'elements' => [
                    [
                        'type'  => 'Label',
                        'label' => 'Es konnten keine Geräte vom Gateway abgerufen werden oder es sind keine Geräte vorhanden.'
                    ]
                ]
            ]);
        }

        $included = $apiData['included'] ?? [];
        
        $devicesMap = [];
        $servicesMap = [];

        // First pass: Find all devices
        foreach ($included as $item) {
            if (($item['type'] ?? '') === 'DEVICE') {
                $id = $item['id'] ?? '';
                if (!empty($id)) {
                    $devicesMap[$id] = [
                        'id' => $id,
                        'services' => []
                    ];
                }
            }
        }
        
        // Second pass: Associate services
        foreach ($included as $item) {
            $type = $item['type'] ?? '';
            $id = $item['id'] ?? '';
            if ($type === 'DEVICE' || $type === 'LOCATION') {
                continue;
            }
            $deviceId = $item['relationships']['device']['data']['id'] ?? '';
            if ($deviceId && isset($devicesMap[$deviceId])) {
                // We keep a list of services for the device
                $devicesMap[$deviceId]['services'][] = $item;
                $servicesMap[$id] = $item;
            }
        }

        $values = [];
        // Add root location if needed, but flat is also fine. We will use tree structure.
        foreach ($devicesMap as $deviceId => $dev) {
            $services = $dev['services'];
            
            // Find COMMON service for device name and serial
            $common = null;
            $valveSet = null;
            $valves = [];
            $sensor = null;

            foreach ($services as $svc) {
                if ($svc['type'] === 'COMMON') $common = $svc;
                if ($svc['type'] === 'VALVE_SET') $valveSet = $svc;
                if ($svc['type'] === 'VALVE') $valves[] = $svc;
                if ($svc['type'] === 'SENSOR') $sensor = $svc;
            }

            if (!$common) {
                continue; // Cannot identify device without COMMON
            }

            $deviceName = $common['attributes']['name']['value'] ?? 'Unknown Device';
            $serial = $common['attributes']['serial']['value'] ?? '-';
            $modelType = $common['attributes']['modelType']['value'] ?? 'GARDENA Device';
            $rfLinkState = $common['attributes']['rfLinkState']['value'] ?? 'UNKNOWN';
            
            // Add Parent Node
            $values[] = [
                'id'         => $deviceId,
                'name'       => $deviceName,
                'serial'     => $serial,
                'status'     => $rfLinkState,
                'type'       => $modelType,
                'instanceID' => 0
            ];

            if ($valveSet && count($valves) > 1) {
                // Irrigation Control (multi-valve)
                $moduleID = '{7B3F1D5E-A9C2-4E8F-B6D4-2A7C3E5F1B9D}';
                foreach ($valves as $v) {
                    $vId = $v['id'];
                    $vName = $v['attributes']['name']['value'] ?? 'Valve';
                    // Extract ValveID from id (e.g. "deviceid:1")
                    $parts = explode(':', $vId);
                    $valveIdStr = isset($parts[1]) ? $parts[1] : '1';
                    
                    $instanceID = $this->GetExistingInstanceID($moduleID, $deviceId, $valveIdStr);
                    $values[] = [
                        'id'         => $vId,
                        'parent'     => $deviceId,
                        'name'       => $vName,
                        'serial'     => '',
                        'status'     => 'OK',
                        'type'       => 'Ventil',
                        'instanceID' => $instanceID,
                        'create'     => [
                            'moduleID'      => $moduleID,
                            'configuration' => [
                                'DeviceID' => $deviceId,
                                'ValveID'  => $valveIdStr
                            ],
                            'name'          => $vName
                        ]
                    ];
                }
            } elseif (count($valves) === 1) {
                // Water Control (single valve)
                $moduleID = '{7B3F1D5E-A9C2-4E8F-B6D4-2A7C3E5F1B9D}';
                $v = $valves[0];
                $vName = $v['attributes']['name']['value'] ?? $deviceName;
                $instanceID = $this->GetExistingInstanceID($moduleID, $deviceId, '1');
                $values[] = [
                    'id'         => $v['id'],
                    'parent'     => $deviceId,
                    'name'       => $vName,
                    'serial'     => '',
                    'status'     => 'OK',
                    'type'       => 'Ventil',
                    'instanceID' => $instanceID,
                    'create'     => [
                        'moduleID'      => $moduleID,
                        'configuration' => [
                            'DeviceID' => $deviceId,
                            'ValveID'  => '1'
                        ],
                        'name'          => $vName
                    ]
                ];
            } elseif ($sensor) {
                // Sensor
                $moduleID = '{5E9C1A3B-D2F4-4B6E-8A7C-3F1D5E9B2C4A}';
                $instanceID = $this->GetExistingInstanceID($moduleID, $deviceId);
                $values[] = [
                    'id'         => $sensor['id'],
                    'parent'     => $deviceId,
                    'name'       => $deviceName . ' (Sensor)',
                    'serial'     => '',
                    'status'     => 'OK',
                    'type'       => 'Sensor',
                    'instanceID' => $instanceID,
                    'create'     => [
                        'moduleID'      => $moduleID,
                        'configuration' => [
                            'DeviceID' => $deviceId
                        ],
                        'name'          => $deviceName . ' (Sensor)'
                    ]
                ];
            } else {
                // Unknown/Unsupported Device
                $values[] = [
                    'id'         => $deviceId . '_unk',
                    'parent'     => $deviceId,
                    'name'       => 'Unsupported Device',
                    'serial'     => '',
                    'status'     => 'OK',
                    'type'       => 'Unknown',
                    'instanceID' => 0
                ];
            }
        }

        return json_encode([
            'elements' => [
                [
                    'type'     => 'Configurator',
                    'name'     => 'Devices',
                    'caption'  => 'Gardena Geräte',
                    'rowCount' => 10,
                    'add'      => false,
                    'delete'   => false,
                    'sort'     => [
                        'column'    => 'name',
                        'direction' => 'ascending'
                    ],
                    'columns'  => [
                        ['caption' => 'Name',         'name' => 'name',   'width' => 'auto'],
                        ['caption' => 'Seriennummer', 'name' => 'serial', 'width' => '150px'],
                        ['caption' => 'Status',       'name' => 'status', 'width' => '100px'],
                        ['caption' => 'Typ',          'name' => 'type',   'width' => '250px']
                    ],
                    'values'   => $values
                ]
            ]
        ]);

    }

    private function GetExistingInstanceID(string $moduleID, string $deviceID, string $valveID = ''): int
    {
        $instances = IPS_GetInstanceListByModuleID($moduleID);
        foreach ($instances as $instanceID) {
            $confDeviceID = @IPS_GetProperty($instanceID, 'DeviceID');
            if ($confDeviceID === $deviceID) {
                if ($valveID !== '') {
                    $confValveID = @IPS_GetProperty($instanceID, 'ValveID');
                    if ($confValveID === $valveID) {
                        return $instanceID;
                    }
                } else {
                    return $instanceID;
                }
            }
        }
        return 0;
    }
}
