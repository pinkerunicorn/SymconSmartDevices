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
            'DataID' => '{2C4A6B8D-F1E3-4A5C-9B7D-3E5F1A7C9B2D}',
            'Action' => 'GetDevices'
        ]));

        $devices = [];
        if ($response !== false) {
            $devices = json_decode($response, true) ?? [];
        }

        if (empty($devices) || !is_array($devices)) {
            return json_encode([
                'elements' => [
                    [
                        'type'  => 'Label',
                        'label' => 'Es konnten keine Ger\u00e4te vom Gateway abgerufen werden oder es sind keine Ger\u00e4te vorhanden.'
                    ]
                ]
            ]);
        }

        $values = [];
        foreach ($devices as $device) {
            $type = $device['type'] ?? 'Unknown';
            $deviceId = $device['id'] ?? '';
            $deviceName = $device['name'] ?? 'Unknown Device';
            $serial = $device['serial'] ?? '-';
            $status = $device['status'] ?? 'UNKNOWN';

            if (empty($deviceId)) {
                continue;
            }

            if ($type === 'GARDENA smart Irrigation Control') {
                $moduleID = '{7B3F1D5E-A9C2-4E8F-B6D4-2A7C3E5F1B9D}';
                for ($i = 1; $i <= 6; $i++) {
                    $valveId = (string)$i;
                    $instanceID = $this->GetExistingInstanceID($moduleID, $deviceId, $valveId);
                    $values[] = [
                        'name'       => $deviceName . " (Ventil $i)",
                        'serial'     => $serial . "-$i",
                        'status'     => $status,
                        'type'       => 'Irrigation Valve',
                        'instanceID' => $instanceID,
                        'create'     => [
                            'moduleID'      => $moduleID,
                            'configuration' => [
                                'DeviceID' => $deviceId,
                                'ValveID'  => $valveId
                            ],
                            'name'          => $deviceName . " (Ventil $i)"
                        ]
                    ];
                }
            } elseif ($type === 'GARDENA smart Water Control') {
                $moduleID = '{7B3F1D5E-A9C2-4E8F-B6D4-2A7C3E5F1B9D}';
                $instanceID = $this->GetExistingInstanceID($moduleID, $deviceId);
                $values[] = [
                    'name'       => $deviceName,
                    'serial'     => $serial,
                    'status'     => $status,
                    'type'       => $type,
                    'instanceID' => $instanceID,
                    'create'     => [
                        'moduleID'      => $moduleID,
                        'configuration' => [
                            'DeviceID' => $deviceId
                        ],
                        'name'          => $deviceName
                    ]
                ];
            } elseif ($type === 'GARDENA smart Sensor') {
                $moduleID = '{5E9C1A3B-D2F4-4B6E-8A7C-3F1D5E9B2C4A}';
                $instanceID = $this->GetExistingInstanceID($moduleID, $deviceId);
                $values[] = [
                    'name'       => $deviceName,
                    'serial'     => $serial,
                    'status'     => $status,
                    'type'       => $type,
                    'instanceID' => $instanceID,
                    'create'     => [
                        'moduleID'      => $moduleID,
                        'configuration' => [
                            'DeviceID' => $deviceId
                        ],
                        'name'          => $deviceName
                    ]
                ];
            }
        }

        return json_encode([
            'elements' => [
                [
                    'type'     => 'Configurator',
                    'name'     => 'Devices',
                    'caption'  => 'Gardena Ger\u00e4te',
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
