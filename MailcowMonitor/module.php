<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';
require_once __DIR__ . '/../libs/Trait_SmartHttp.php';
require_once __DIR__ . '/../libs/Trait_SmartLog.php';
class MailcowMonitor extends IPSModuleStrict
{
    use DeviceAvailability_Trait;
    use SmartHttp_Trait;
    use SmartLog_Trait;
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('URL', 'https://mail.ubyte.pink');
        $this->RegisterPropertyString('APIKey', '');
        $this->RegisterPropertyInteger('UpdateInterval', 3600);
        
        $this->RegisterPropertyBoolean('MonitorContainers', true);
        $this->RegisterPropertyBoolean('MonitorStorage', true);
        $this->RegisterPropertyBoolean('MonitorMailQueue', true);

        $this->RegisterPropertyInteger('AlarmStorageThreshold', 90);
        $this->RegisterPropertyInteger('AlarmMailQueueThreshold', 10);
        $this->RegisterPropertyBoolean('AlarmOnUpdate', false);
        $this->RegisterPropertyBoolean('AlarmOnQuarantine', false);

        $this->RegisterTimer('UpdateTimer', 0, 'MAILCOW_Update($_IPS[\'TARGET\']);');

        $this->DA_RegisterAvailability(900);

        $this->RegisterVariableString('Version', 'Version', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'microchip'
        ], 1);

        $updateOptions = json_encode([
            [
                'Value' => false, 'Caption' => 'Aktuell', 'IconValue' => 'check', 'IconActive' => true,
                'ColorActive' => true, 'ColorDisplay' => 0x00FF00, 'ContentColorActive' => false,
                'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00FF00
            ],
            [
                'Value' => true, 'Caption' => 'Update verfügbar', 'IconValue' => 'arrow-up-right-dots', 'IconActive' => true,
                'ColorActive' => true, 'ColorDisplay' => 0xFF0000, 'ContentColorActive' => false,
                'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF0000
            ]
        ]);

        $this->RegisterVariableBoolean('UpdateAvailable', 'Update verfügbar', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'microchip',
            'COLOR'        => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS'      => $updateOptions
        ], 2);

        $this->RegisterVariableInteger('QuarantineCount', 'Quarantäne Einträge', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'triangle-exclamation'
        ], 3);

        $this->RegisterVariableString('LastUpdate', 'Letztes Update', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'clock-rotate-left'
        ], 4);

        $alarmOptions = json_encode([
            [
                'Value' => false, 'Caption' => 'Alles OK', 'IconValue' => 'check', 'IconActive' => true,
                'ColorActive' => true, 'ColorDisplay' => 0x00FF00, 'ContentColorActive' => false,
                'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00FF00
            ],
            [
                'Value' => true, 'Caption' => 'Alarm', 'IconValue' => 'triangle-exclamation', 'IconActive' => true,
                'ColorActive' => true, 'ColorDisplay' => 0xFF0000, 'ContentColorActive' => false,
                'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF0000
            ]
        ]);

        $this->RegisterVariableBoolean('SystemAlarm', 'System Alarm', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'bell',
            'OPTIONS'      => $alarmOptions
        ], 8);

        $this->RegisterVariableString('AlarmMessage', 'Alarm Details', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'message'
        ], 9);
    }

    public function Destroy(): void
    {
        parent::Destroy();
        }


    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        if ($this->ReadPropertyBoolean('MonitorContainers')) {
            $containerOptions = json_encode([
                [
                    'Value' => false, 'Caption' => 'Fehler', 'IconValue' => 'triangle-exclamation', 'IconActive' => true,
                    'ColorActive' => true, 'ColorDisplay' => 0xFF0000, 'ContentColorActive' => false,
                    'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF0000
                ],
                [
                    'Value' => true, 'Caption' => 'Alle OK', 'IconValue' => 'check', 'IconActive' => true,
                    'ColorActive' => true, 'ColorDisplay' => 0x00FF00, 'ContentColorActive' => false,
                    'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00FF00
                ]
            ]);
            $this->RegisterVariableBoolean('ContainersRunning', 'Container Status', [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => 'network-wired',
                'COLOR'        => -1,
                'CONTENT_COLOR' => -1,
                'DISPLAY_TYPE' => 0,
                'PREVIEW_STYLE' => 1,
                'SHOW_PREVIEW' => true,
                'OPTIONS'      => $containerOptions
            ], 5);
        } else {
            $this->UnregisterVariable('ContainersRunning');
        }

        if ($this->ReadPropertyBoolean('MonitorStorage')) {
            $this->RegisterVariableInteger('StorageUsage', 'vMail Auslastung', [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => 'database',
                'SUFFIX'       => '%'
            ], 6);
        } else {
            $this->UnregisterVariable('StorageUsage');
        }

        if ($this->ReadPropertyBoolean('MonitorMailQueue')) {
            $this->RegisterVariableInteger('MailQueue', 'Mail Warteschlange', [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => 'envelope'
            ], 7);
        } else {
            $this->UnregisterVariable('MailQueue');
        }

        if ($this->ReadPropertyString('URL') != '' && $this->ReadPropertyString('APIKey') != '') {
            $interval = $this->ReadPropertyInteger('UpdateInterval');
            if ($interval > 0) {
                $this->SetTimerInterval('UpdateTimer', 2000); // Asynchroner Start nach 2 Sekunden
            } else {
                $this->SetTimerInterval('UpdateTimer', 0);
            }
        } else {
            $this->SetTimerInterval('UpdateTimer', 0);
        }

    }

    public function Update(): void
    {
        $interval = $this->ReadPropertyInteger('UpdateInterval');
        if ($interval > 0) {
            $this->SetTimerInterval('UpdateTimer', $interval * 1000);
        }

        $url = rtrim($this->ReadPropertyString('URL'), '/');
        $apiKey = $this->ReadPropertyString('APIKey');

        if ($url == '' || $apiKey == '') {
            return;
        }
        
        $errors = [];

        $headers = ['X-API-Key: ' . $apiKey];

        // Fetch Mailcow Version
        $data = $this->HttpRequest($url . '/api/v1/get/status/version', 'GET', $headers, null, 10);
        if ($data === null) {
            $this->DA_SetAvailable(false, 'API Fehler: Version konnte nicht geladen werden');
            return;
        }

        if (isset($data['version'])) {
            $localVersion = $data['version'];
            $this->SetValue('Version', $localVersion);
        } else {
            $this->DA_SetAvailable(false, 'Ungültige API Antwort (Version)');
            return;
        }

        // Fetch Quarantine Count
        $data = $this->HttpRequest($url . '/api/v1/get/quarantine/all', 'GET', $headers, null, 10);
        if ($data !== null) {
            if (is_array($data)) {
                $qCount = count($data);
                $this->SetValue('QuarantineCount', $qCount);
                if ($qCount > 0 && $this->ReadPropertyBoolean('AlarmOnQuarantine')) {
                    $errors[] = 'Mails in Quarantäne (' . $qCount . ')';
                }
            }
        }

        // Fetch Container Status
        if ($this->ReadPropertyBoolean('MonitorContainers')) {
            $data = $this->HttpRequest($url . '/api/v1/get/status/containers', 'GET', $headers, null, 10);
            if ($data !== null) {
                $allRunning = true;
                if (is_array($data)) {
                    foreach ($data as $container) {
                        if (isset($container['state']) && $container['state'] !== 'running') {
                            $allRunning = false;
                            $errors[] = 'Container ausgefallen: ' . $container['container'];
                        }
                    }
                    $this->SetValue('ContainersRunning', $allRunning);
                }
            }
        }

        // Fetch Storage Usage
        if ($this->ReadPropertyBoolean('MonitorStorage')) {
            $data = $this->HttpRequest($url . '/api/v1/get/status/vmail', 'GET', $headers, null, 10);
            if ($data !== null) {
                if (isset($data['used_percent'])) {
                    $percent = (int)str_replace('%', '', $data['used_percent']);
                    $this->SetValue('StorageUsage', $percent);
                    if ($percent >= $this->ReadPropertyInteger('AlarmStorageThreshold')) {
                        $errors[] = 'Festplatte fast voll (' . $percent . '%)';
                    }
                }
            }
        }

        // Fetch Mail Queue
        if ($this->ReadPropertyBoolean('MonitorMailQueue')) {
            $data = $this->HttpRequest($url . '/api/v1/get/mailq/all', 'GET', $headers, null, 10);
            if ($data !== null) {
                if (is_array($data)) {
                    $queueCount = count($data);
                    $this->SetValue('MailQueue', $queueCount);
                    if ($queueCount >= $this->ReadPropertyInteger('AlarmMailQueueThreshold')) {
                        $errors[] = 'Mail-Warteschlange hoch (' . $queueCount . ')';
                    }
                }
            }
        }

        // Check for Updates on Github
        $githubHeaders = ['User-Agent: IP-Symcon-MailcowMonitor'];
        $githubData = $this->HttpRequest('https://api.github.com/repos/mailcow/mailcow-dockerized/releases/latest', 'GET', $githubHeaders, null, 5);
        if ($githubData !== null) {
            if (isset($githubData['tag_name'])) {
                $latestVersion = $githubData['tag_name'];
                
                // Compare local version vs github latest version
                if (strcmp($latestVersion, $localVersion) > 0) {
                    $this->SetValue('UpdateAvailable', true);
                    if ($this->ReadPropertyBoolean('AlarmOnUpdate')) {
                        $errors[] = 'Update verfügbar (' . $latestVersion . ')';
                    }
                } else {
                    $this->SetValue('UpdateAvailable', false);
                }
            }
        }

        $this->SetValue('LastUpdate', date('d.m.Y H:i:s'));
        
        $hasErrors = count($errors) > 0;
        $this->SetValue('SystemAlarm', $hasErrors);
        $this->SetValue('AlarmMessage', $hasErrors ? implode(' | ', $errors) : 'Keine Alarme');

        // Since we reached the end successfully without API failure, the device is online
        $this->DA_SetAvailable(true);
    }
}
