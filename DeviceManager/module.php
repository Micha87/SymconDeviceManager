<?php
/**
 * Symcon Device Manager 0.2 - read-only grouping prototype.
 * Discovers hardware datapoints and proposes room/device/function mappings.
 * No object writes, moves, links, or hardware commands are performed.
 */
class DeviceManager extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('HardwareRoot', 10094);
        $this->RegisterPropertyInteger('BuildingRoot', 28329);
        $this->RegisterPropertyString('RoomAliases', json_encode([
            'wohnzim' => 'EG/Wohnzimmer', 'wohnzimmer' => 'EG/Wohnzimmer',
            'kü' => 'EG/Küche', 'kueche' => 'EG/Küche', 'küche' => 'EG/Küche',
            'luise' => 'OG/Luise', 'lotta' => 'OG/Lotta'
        ], JSON_UNESCAPED_UNICODE));
        $this->RegisterAttributeString('LastScan', '[]');
        $this->RegisterPropertyString('FilterFloor', 'Alle');
        $this->RegisterPropertyString('FilterRoom', 'Alle');
        $this->RegisterPropertyString('FilterType', 'Alle');
        $this->RegisterPropertyString('FilterText', '');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(102);
    }

    public function GetConfigurationForm()
    {
        $rows = json_decode($this->ReadAttributeString('LastScan'), true);
        if (!is_array($rows)) {
            $rows = [];
        }
        $floors = ['Alle'];
        $rooms = ['Alle'];
        $types = ['Alle'];
        foreach ($rows as $r) {
            $parts = explode('/', $r['room']);
            if ($r['room'] !== 'Nicht zugeordnet') {
                $floors[] = $parts[0];
                $rooms[] = $r['room'];
            }
            $types[] = $r['deviceType'] ?? 'Sonstige';
        }
        $floors = array_values(array_unique($floors));
        $rooms = array_values(array_unique($rooms));
        $types = array_values(array_unique($types));
        $filterFloor = $this->ReadPropertyString('FilterFloor');
        $filterRoom = $this->ReadPropertyString('FilterRoom');
        $filterType = $this->ReadPropertyString('FilterType');
        $filterText = mb_strtolower(trim($this->ReadPropertyString('FilterText')), 'UTF-8');
        $filtered = array_values(array_filter($rows, static function ($r) use ($filterFloor, $filterRoom, $filterType, $filterText) {
            if ($filterFloor !== 'Alle' && strpos($r['room'], $filterFloor . '/') !== 0) return false;
            if ($filterRoom !== 'Alle' && $r['room'] !== $filterRoom) return false;
            if ($filterType !== 'Alle' && ($r['deviceType'] ?? 'Sonstige') !== $filterType) return false;
            if ($filterText !== '' && mb_strpos(mb_strtolower(implode(' ', $r), 'UTF-8'), $filterText) === false) return false;
            return true;
        }));
        $grouped = $this->GroupDevices($filtered);
        $selectOptions = static function ($items) {
            return array_map(static function ($item) { return ['caption' => $item, 'value' => $item]; }, $items);
        };
        return json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => 'Prototyp 0.2: Hardware und Bestandsstruktur lesen, Geräte gruppieren. Keine Objektänderungen.'],
                ['type' => 'NumberSpinner', 'name' => 'HardwareRoot', 'caption' => 'Hardware-Kategorie (ID)'],
                ['type' => 'NumberSpinner', 'name' => 'BuildingRoot', 'caption' => 'Bestehende Gebäude-Kategorie (ID)'],
                ['type' => 'ValidationTextBox', 'name' => 'RoomAliases', 'caption' => 'Raum-Aliasse (JSON)'],
                ['type' => 'Select', 'name' => 'FilterFloor', 'caption' => 'Filter Etage', 'options' => $selectOptions($floors)],
                ['type' => 'Select', 'name' => 'FilterRoom', 'caption' => 'Filter Raum', 'options' => $selectOptions($rooms)],
                ['type' => 'Select', 'name' => 'FilterType', 'caption' => 'Filter Gerätetyp', 'options' => $selectOptions($types)],
                ['type' => 'ValidationTextBox', 'name' => 'FilterText', 'caption' => 'Freitext (Name / ID / Funktion)'],
                ['type' => 'Label', 'caption' => count($filtered) . ' von ' . count($rows) . ' Datenpunkten sichtbar. Filter ändern, Übernehmen und Konfigurator erneut öffnen.'],
                ['type' => 'Label', 'caption' => count($grouped) . ' erkannte Gerätegruppen (vorläufig; Zuordnungen prüfen)'],
                ['type' => 'List', 'name' => 'Devices', 'caption' => 'Geräteübersicht',
                    'rowCount' => 12, 'add' => false, 'delete' => false,
                    'columns' => [
                        ['caption' => 'Raum', 'name' => 'room', 'width' => '150px'],
                        ['caption' => 'Typ', 'name' => 'deviceType', 'width' => '110px'],
                        ['caption' => 'Gerät', 'name' => 'device', 'width' => '240px'],
                        ['caption' => 'Funktionen', 'name' => 'functions', 'width' => '230px'],
                        ['caption' => 'Variablen', 'name' => 'count', 'width' => '90px'],
                        ['caption' => 'Instanz-IDs', 'name' => 'instances', 'width' => '160px']
                    ],
                    'values' => $grouped
                ],
                ['type' => 'List', 'name' => 'Preview', 'caption' => 'Gefilterte Datenpunkte',
                    'rowCount' => 15, 'add' => false, 'delete' => false, 'sort' => ['column' => 'room', 'direction' => 'ascending'],
                    'columns' => [
                        ['caption' => 'Typ', 'name' => 'deviceType', 'width' => '110px'],
                        ['caption' => 'Raumvorschlag', 'name' => 'room', 'width' => '160px'],
                        ['caption' => 'Gerätevorschlag', 'name' => 'device', 'width' => '230px'],
                        ['caption' => 'Funktion', 'name' => 'function', 'width' => '120px'],
                        ['caption' => 'Instanz', 'name' => 'instance', 'width' => '80px'],
                        ['caption' => 'Variable', 'name' => 'variable', 'width' => '80px'],
                        ['caption' => 'Zuordnungsqualität', 'name' => 'confidence', 'width' => '90px']
                    ],
                    'values' => $filtered
                ]
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Hardware scannen (nur lesen)', 'onClick' => 'SDM_Scan($id);'],
                ['type' => 'Label', 'caption' => 'Nach dem Scan die Konfiguration erneut öffnen, um die Vorschläge zu sehen.']
            ]
        ], JSON_UNESCAPED_UNICODE);
    }

    public function Scan()
    {
        $root = $this->ReadPropertyInteger('HardwareRoot');
        if ($root <= 0 || !IPS_ObjectExists($root)) {
            throw new Exception('Hardware-Kategorie nicht gefunden: ' . $root);
        }
        $aliases = json_decode($this->ReadPropertyString('RoomAliases'), true);
        if (!is_array($aliases)) {
            throw new Exception('RoomAliases muss ein JSON-Objekt sein.');
        }
        $rows = [];
        $seen = [];
        $this->ScanBranch($root, $aliases, $rows, 0, null, $seen);
        $building = $this->ReadPropertyInteger('BuildingRoot');
        if ($building > 0 && IPS_ObjectExists($building) && $building !== $root) {
            $this->ScanBranch($building, $aliases, $rows, 0, null, $seen);
        }
        $this->WriteAttributeString('LastScan', json_encode($rows, JSON_UNESCAPED_UNICODE));
        $this->SendDebug('Scan', count($rows) . ' Kandidaten', 0);
        return count($rows);
    }

    public function GetPreviewJSON()
    {
        return $this->ReadAttributeString('LastScan');
    }

    private function ScanBranch($parent, $aliases, &$rows, $depth, $roomPath, &$seen)
    {
        if ($depth > 20 || count($rows) >= 2000) {
            return;
        }
        foreach (IPS_GetChildrenIDs($parent) as $id) {
            if (count($rows) >= 4000) return;
            $object = IPS_GetObject($id);
            $pathRoom = $this->RoomFromPath($id);
            if ($object['ObjectType'] === 1 && !isset($seen[$id])) {
                $seen[$id] = true;
                $name = $object['ObjectName'];
                $parsed = $this->ParseName($name, $aliases);
                if ($pathRoom !== null) {
                    $parsed['room'] = $pathRoom;
                    $parsed['confidence'] = 'Hoch (Pfad)';
                }
                foreach (IPS_GetChildrenIDs($id) as $childID) {
                    $child = IPS_GetObject($childID);
                    if ($child['ObjectType'] !== 2) {
                        continue;
                    }
                    $rows[] = [
                        'room' => $parsed['room'],
                        'deviceType' => $this->GuessType($name),
                        'device' => $parsed['device'],
                        'function' => $parsed['function'] !== 'Unbekannt' ? $parsed['function'] : $child['ObjectName'],
                        'instance' => (string) $id,
                        'variable' => (string) $childID,
                        'confidence' => $parsed['confidence']
                    ];
                    if (count($rows) >= 2000) {
                        return;
                    }
                }
            }
            if ($object['ObjectType'] === 0 || $object['ObjectType'] === 1) {
                $this->ScanBranch($id, $aliases, $rows, $depth + 1, $pathRoom ?? $roomPath, $seen);
            }
        }
    }

    private function RoomFromPath($id)
    {
        $root = $this->ReadPropertyInteger('BuildingRoot');
        $parts = [];
        $current = $id;
        for ($i = 0; $i < 24 && $current > 0 && IPS_ObjectExists($current); $i++) {
            if ($current === $root) break;
            $o = IPS_GetObject($current);
            array_unshift($parts, $o['ObjectName']);
            $current = $o['ParentID'];
        }
        if ($current !== $root || count($parts) < 3) return null;
        $floor = $parts[0];
        $room = $parts[1];
        if (in_array($room, ['Allgemein', 'Erweiterte Lichtsteuerung'], true)) return null;
        return $floor . '/' . $room;
    }

    private function GroupDevices($rows)
    {
        $groups = [];
        foreach ($rows as $r) {
            $name = preg_replace('/_(s|d|w)$/iu', '', $r['device']);
            $name = preg_replace('/^(Bel|HZG|Rollo|Rolladen)[.\\-_ ]+/iu', '', $name);
            $name = preg_replace('/^(Wz|Wohnzim|Kü|Kueche|Küche)[.\\-_ ]+/iu', '', $name);
            $key = mb_strtolower($r['room'] . '|' . $r['deviceType'] . '|' . $name, 'UTF-8');
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'room' => $r['room'], 'deviceType' => $r['deviceType'],
                    'device' => $name, 'functions' => [], 'count' => 0, 'instances' => []
                ];
            }
            $groups[$key]['count']++;
            $groups[$key]['functions'][$r['function']] = true;
            $groups[$key]['instances'][$r['instance']] = true;
        }
        $result = [];
        foreach ($groups as $g) {
            $g['functions'] = implode(', ', array_keys($g['functions']));
            $g['instances'] = implode(', ', array_keys($g['instances']));
            $result[] = $g;
        }
        usort($result, static function ($a, $b) {
            return strcmp($a['room'] . $a['device'], $b['room'] . $b['device']);
        });
        return $result;
    }

    private function GuessType($name)
    {
        $name = mb_strtolower($name, 'UTF-8');
        if (preg_match('/(bel\\.|licht|lampe|strahler|wled|dimmer)/u', $name)) return 'Beleuchtung';
        if (preg_match('/(roll|jalous|shutter|blind)/u', $name)) return 'Rollladen';
        if (preg_match('/(hzg|heizung|thermostat|temperatur)/u', $name)) return 'Heizung';
        if (preg_match('/(steckdose|socket|plug)/u', $name)) return 'Steckdose';
        if (preg_match('/(sensor|bewegung|präsenz|kontakt)/u', $name)) return 'Sensor';
        return 'Sonstige';
    }

    private function ParseName($name, $aliases)
    {
        $normalized = mb_strtolower($name, 'UTF-8');
        $room = 'Nicht zugeordnet';
        $confidence = 'Niedrig';
        foreach ($aliases as $token => $path) {
            if ($token !== '' && mb_strpos($normalized, mb_strtolower($token, 'UTF-8')) !== false) {
                $room = (string) $path;
                $confidence = 'Mittel';
                break;
            }
        }
        $function = 'Unbekannt';
        if (preg_match('/_s$/iu', $name)) {
            $function = 'Schalten';
        } elseif (preg_match('/_d$/iu', $name)) {
            $function = 'Dimmen';
        } elseif (preg_match('/_w$/iu', $name)) {
            $function = 'Wert/Status (prüfen)';
        } elseif (mb_strpos($normalized, 'ist-temperatur') !== false) {
            $function = 'Isttemperatur';
        } elseif (mb_strpos($normalized, 'soll-temperatur') !== false) {
            $function = 'Solltemperatur';
        }
        $device = preg_replace('/_(s|d|w)$/iu', '', $name);
        return ['room' => $room, 'device' => $device, 'function' => $function, 'confidence' => $confidence];
    }
}
