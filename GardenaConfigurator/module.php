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
        foreach ($included as $item) {
            if (($item['type'] ?? '') === 'DEVICE') {
                $id = $item['id'] ?? '';
                // Gardena API JSONAPI returns attributes nested in a 'value' object
                $name = $item['attributes']['name']['value'] ?? 'Unknown Device';
                $serial = $item['attributes']['serial']['value'] ?? '-';
                if (!empty($id)) {
                    $devicesMap[$id] = [
                        'id' => $id,
                        'name' => $name,
                        'serial' => $serial,
                        'services' => []
                    ];
                }
            }
        }
        
        foreach ($included as $item) {
            $type = $item['type'] ?? '';
            if ($type === 'DEVICE' || $type === 'LOCATION') {
                continue;
            }
            $deviceId = $item['relationships']['device']['data']['id'] ?? '';
            if ($deviceId && isset($devicesMap[$deviceId])) {
                $devicesMap[$deviceId]['services'][$type] = $item;
            }
        }

        $values = [];
        foreach ($devicesMap as $deviceId => $dev) {
            $name = $dev['name'];
            $serial = $dev['serial'];
            $services = $dev['services'];

            if (isset($services['VALVE_SET'])) {
                $moduleID = '{7B3F1D5E-A9C2-4E8F-B6D4-2A7C3E5F1B9D}';
                for ($i = 1; $i <= 6; $i++) {
                    $valveId = (string)$i;
                    $instanceID = $this->GetExistingInstanceID($moduleID, $deviceId, $valveId);
                    $values[] = [
                        'name'       => $name . " (Ventil $i)",
                        'serial'     => $serial . "-$i",
                        'status'     => 'OK',
                        'type'       => 'Irrigation Control',
                        'instanceID' => $instanceID,
                        'create'     => [
                            'moduleID'      => $moduleID,
                            'configuration' => [
                                'DeviceID' => $deviceId,
                                'ValveID'  => $valveId
                            ],
                            'name'          => $name . " (Ventil $i)"
                        ]
                    ];
                }
            } elseif (isset($services['VALVE'])) {
                $moduleID = '{7B3F1D5E-A9C2-4E8F-B6D4-2A7C3E5F1B9D}';
                $instanceID = $this->GetExistingInstanceID($moduleID, $deviceId, '1');
                $values[] = [
                    'name'       => $name,
                    'serial'     => $serial,
                    'status'     => 'OK',
                    'type'       => 'Water Control',
                    'instanceID' => $instanceID,
                    'create'     => [
                        'moduleID'      => $moduleID,
                        'configuration' => [
                            'DeviceID' => $deviceId,
                            'ValveID'  => '1'
                        ],
                        'name'          => $name
                    ]
                ];
            } elseif (isset($services['SENSOR'])) {
                $moduleID = '{5E9C1A3B-D2F4-4B6E-8A7C-3F1D5E9B2C4A}';
                $instanceID = $this->GetExistingInstanceID($moduleID, $deviceId);
                $values[] = [
                    'name'       => $name,
                    'serial'     => $serial,
                    'status'     => 'OK',
                    'type'       => 'Sensor',
                    'instanceID' => $instanceID,
                    'create'     => [
                        'moduleID'      => $moduleID,
                        'configuration' => [
                            'DeviceID' => $deviceId
                        ],
                        'name'          => $name
                    ]
                ];
            } else {
                $values[] = [
                    'name'       => $name,
                    'serial'     => $serial,
                    'status'     => 'OK',
                    'type'       => 'Unknown (' . implode(', ', array_keys($services)) . ')',
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
