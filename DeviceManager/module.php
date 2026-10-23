<?php
/**
 * Symcon Device Manager 0.4.1 - category selection dialogs.
 * Discovers hardware datapoints and proposes room/device/function mappings.
 * Creates device dummies and links only after explicit checkbox selection; never moves hardware.
 */
class DeviceManager extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('HardwareRoot', 10094);
        $this->RegisterPropertyInteger('BuildingRoot', 28329);
        $this->RegisterPropertyInteger('ManagedBuildingRoot', 58840);
        $this->RegisterPropertyInteger('DeviceSourceCategory', 57856);
        $this->RegisterPropertyInteger('DeviceTargetCategory', 27803);
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
        foreach (['Floor', 'Room', 'Type', 'Text'] as $field) {
            $this->RegisterAttributeString('LiveFilter' . $field, '');
        }
        $this->RegisterAttributeString('BuildingPlan', '{}');
        $this->RegisterAttributeString('DraftFloor', '');
        $this->RegisterAttributeString('DraftRoom', '');
        $this->RegisterAttributeString('ImportSelections', '{}');
        $this->RegisterAttributeBoolean('ShowImportedRooms', false);
        $this->RegisterAttributeString('DeviceSelections', '{}');
        $this->RegisterAttributeString('DeviceAssignments', '{}');
        $this->RegisterAttributeInteger('EditingDevice', 0);
        $this->RegisterAttributeString('DeviceSearch', '');
        $this->RegisterAttributeBoolean('ShowExistingDevices', false);
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
            $parts = explode('/', $r['room'] ?? 'Nicht zugeordnet');
            if ($r['room'] !== 'Nicht zugeordnet') {
                $floors[] = $parts[0];
                $rooms[] = $r['room'];
            }
            $types[] = $r['deviceType'] ?? $this->GuessType($r['device'] ?? '');
        }
        $floors = array_values(array_unique($floors));
        $rooms = array_values(array_unique($rooms));
        $types = array_values(array_unique($types));
        $filterFloor = $this->ReadAttributeString('LiveFilterFloor') ?: $this->ReadPropertyString('FilterFloor');
        $filterRoom = $this->ReadAttributeString('LiveFilterRoom') ?: $this->ReadPropertyString('FilterRoom');
        $filterType = $this->ReadAttributeString('LiveFilterType') ?: $this->ReadPropertyString('FilterType');
        $filterText = mb_strtolower(trim($this->ReadAttributeString('LiveFilterText')), 'UTF-8');
        $filtered = array_values(array_filter($rows, static function ($r) use ($filterFloor, $filterRoom, $filterType, $filterText) {
            if ($filterFloor !== 'Alle' && strpos($r['room'], $filterFloor . '/') !== 0) return false;
            if ($filterRoom !== 'Alle' && $r['room'] !== $filterRoom) return false;
            if ($filterType !== 'Alle' && ($r['deviceType'] ?? 'Sonstige') !== $filterType) return false;
            if ($filterText !== '' && mb_strpos(mb_strtolower(implode(' ', $r), 'UTF-8'), $filterText) === false) return false;
            return true;
        }));
        $grouped = $this->GroupDevices($filtered);
        $plan = json_decode($this->ReadAttributeString('BuildingPlan'), true);
        if (!is_array($plan)) $plan = [];
        if (count($plan) === 0) $plan = $this->ReadBuildingPlan($this->ReadPropertyInteger('ManagedBuildingRoot'));
        $planRows = [];
        foreach ($plan as $floor => $roomNames) {
            if (!is_array($roomNames)) continue;
            if (count($roomNames) === 0) $planRows[] = ['floor' => $floor, 'room' => '(noch keine Räume)'];
            foreach ($roomNames as $roomName) $planRows[] = ['floor' => $floor, 'room' => $roomName];
        }
        $importItems = $this->BuildImportControls();
        $deviceItems = $this->BuildDeviceImportControls();
        $deviceConfigurator = $this->BuildNativeDeviceConfigurator();
        $testEditor = $this->BuildTestDeviceEditor(52319);
        $selectOptions = static function ($items) {
            return array_map(static function ($item) { return ['caption' => $item, 'value' => $item]; }, $items);
        };
        return json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => 'Version 0.4: Gebäudeverwaltung und auswählbare Geräteübernahme mit Dummys und Links.'],
                ['type' => 'NumberSpinner', 'name' => 'HardwareRoot', 'caption' => 'Hardware-Kategorie (ID)'],
                ['type' => 'NumberSpinner', 'name' => 'BuildingRoot', 'caption' => 'Bestehende Gebäude-Kategorie (ID)'],
                ['type' => 'SelectCategory', 'name' => 'ManagedBuildingRoot', 'caption' => 'Zielstruktur für Geräteübernahme (Gebäude_neu)'],
                ['type' => 'SelectCategory', 'name' => 'DeviceSourceCategory', 'caption' => 'Gerätequelle auswählen (Hardware)'],
                ['type' => 'ValidationTextBox', 'name' => 'RoomAliases', 'caption' => 'Raum-Aliasse (JSON)'],
                ['type' => 'Select', 'name' => 'FilterFloor', 'value' => $filterFloor, 'caption' => 'Filter Etage', 'options' => $selectOptions($floors), 'onChange' => 'SDM_SetLiveFilter($id, "Floor", $FilterFloor);'],
                ['type' => 'Select', 'name' => 'FilterRoom', 'value' => $filterRoom, 'caption' => 'Filter Raum', 'options' => $selectOptions($rooms), 'onChange' => 'SDM_SetLiveFilter($id, "Room", $FilterRoom);'],
                ['type' => 'Select', 'name' => 'FilterType', 'value' => $filterType, 'caption' => 'Filter Gerätetyp', 'options' => $selectOptions($types), 'onChange' => 'SDM_SetLiveFilter($id, "Type", $FilterType);'],
                ['type' => 'ValidationTextBox', 'name' => 'FilterText', 'value' => $filterText, 'caption' => 'Freitext (Name / ID / Funktion)', 'onChange' => 'SDM_SetLiveFilter($id, "Text", $FilterText);'],
                ['type' => 'Label', 'caption' => count($filtered) . ' von ' . count($rows) . ' Datenpunkten sichtbar. Filter aktualisieren die Ansicht ohne Schließen (Freitext nach Eingabebestätigung).'],
                ['type' => 'Label', 'caption' => count($grouped) . ' erkannte Gerätegruppen (vorläufig; Zuordnungen prüfen)'],
                ['type' => 'ExpansionPanel', 'caption' => 'Gebäudeverwaltung – neue Struktur', 'items' => [
                    ['type' => 'Label', 'caption' => 'Die Aktionen „in Symcon anlegen“ erzeugen echte Kategorien ausschließlich unter der Ziel-ID. Bestehende Kategorien werden wiederverwendet. Löschen ist hier nicht möglich.'],
                    ['type' => 'List', 'name' => 'BuildingPreview', 'caption' => 'Geplante Etagen und Räume',
                        'rowCount' => 8, 'add' => false, 'delete' => false,
                        'columns' => [
                            ['caption' => 'Etage', 'name' => 'floor', 'width' => '180px'],
                            ['caption' => 'Raum', 'name' => 'room', 'width' => '220px']
                        ], 'values' => $planRows
                    ],
                    ['type' => 'ValidationTextBox', 'name' => 'DraftFloor', 'caption' => 'Etage (z.B. EG)', 'onChange' => 'SDM_SetDraft($id, "Floor", $DraftFloor);'],
                    ['type' => 'ValidationTextBox', 'name' => 'DraftRoom', 'caption' => 'Raum (z.B. Wohnzimmer)', 'onChange' => 'SDM_SetDraft($id, "Room", $DraftRoom);'],
                    ['type' => 'Button', 'caption' => 'Etage in Symcon anlegen', 'onClick' => 'SDM_CreateBuildingCategory($id, "Floor");'],
                    ['type' => 'Button', 'caption' => 'Raum in Symcon anlegen', 'onClick' => 'SDM_CreateBuildingCategory($id, "Room");'],
                    ['type' => 'Button', 'caption' => 'Gewerk in Symcon anlegen (Beleuchtung)', 'onClick' => 'SDM_CreateBuildingCategory($id, "Trade");'],
                    ['type' => 'Button', 'caption' => 'Zielstruktur neu einlesen', 'onClick' => 'IPS_RequestAction($id, "RefreshForm", 0);'],
                    ['type' => 'Button', 'caption' => 'Etage im Entwurf hinzufügen', 'onClick' => 'SDM_EditBuildingPlan($id, "AddFloor");'],
                    ['type' => 'Button', 'caption' => 'Raum hinzufügen', 'onClick' => 'SDM_EditBuildingPlan($id, "AddRoom");'],
                    ['type' => 'Button', 'caption' => 'Raum entfernen (Entwurf)', 'onClick' => 'SDM_EditBuildingPlan($id, "RemoveRoom");'],
                    ['type' => 'Button', 'caption' => 'Etage entfernen (nur wenn leer)', 'onClick' => 'SDM_EditBuildingPlan($id, "RemoveFloor");'],
                    ['type' => 'Button', 'caption' => 'Bestehende Etagen/Räume importieren (Entwurf)', 'onClick' => 'SDM_ImportBuildingPlan($id);'],
                    ['type' => 'ExpansionPanel', 'caption' => 'Vorhandene Struktur übernehmen (mit Auswahl)', 'items' => array_merge([
                        ['type' => 'Label', 'caption' => 'Quelle: bestehende Gebäude-Kategorie. Etagen, Räume und Gewerke einzeln auswählen. Keine vorhandenen Objekte werden verschoben.'],
                        ['type' => 'Button', 'caption' => 'Vorschläge aktualisieren', 'onClick' => 'IPS_RequestAction($id, "RefreshForm", 0);'],
                        ['type' => 'CheckBox', 'name' => 'ShowImportedRooms', 'caption' => 'Bereits vollständig übernommene Räume anzeigen', 'value' => $this->ReadAttributeBoolean('ShowImportedRooms'), 'onChange' => 'SDM_SetShowImportedRooms($id, $ShowImportedRooms);'],
                    ], $importItems, [
                        ['type' => 'Button', 'caption' => 'Ausgewählte Kategorien in Zielstruktur anlegen', 'onClick' => 'SDM_ApplyImportSelection($id);']
                    ])]
                ]],
                ['type' => 'Label', 'caption' => 'Geräteübernahme: Ziel ist die oben ausgewählte Gebäudestruktur. Der native Hinweis „Erstelle neue Instanzen in home“ ist ein festes Element des Symcon-Configurators und wird hier nicht verwendet. Vorerst nur Vorschau, keine Erstellung.'],
                $deviceConfigurator,
                $testEditor,
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
                ['type' => 'Button', 'caption' => 'Hardware scannen (nur lesen)', 'onClick' => 'SDM_Scan($id); IPS_RequestAction($id, "RefreshForm", 0);'],
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
        // Prioritize the building tree: otherwise large hardware scans can reach the limit first.
        $building = $this->ReadPropertyInteger('BuildingRoot');
        if ($building > 0 && IPS_ObjectExists($building) && $building !== $root) {
            $this->ScanBranch($building, $aliases, $rows, 0, null, $seen);
        }
        $this->ScanBranch($root, $aliases, $rows, 0, null, $seen);
        $this->WriteAttributeString('LastScan', json_encode($rows, JSON_UNESCAPED_UNICODE));
        $this->SendDebug('Scan', count($rows) . ' Kandidaten', 0);
        return count($rows);
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'RefreshForm') {
            $this->ReloadForm();
            return;
        }
        throw new Exception('Unknown action: ' . $Ident);
    }

    public function SetLiveFilter(string $field, string $value)
    {
        if (!in_array($field, ['Floor', 'Room', 'Type', 'Text'], true)) throw new Exception('Ungültiger Filter');
        $this->WriteAttributeString('LiveFilter' . $field, (string) $value);
        $this->ReloadForm();
    }

    public function SetDraft(string $field, string $value)
    {
        if (!in_array($field, ['Floor', 'Room'], true)) throw new Exception('Ungültiges Feld');
        $this->WriteAttributeString('Draft' . $field, trim((string) $value));
    }

    public function ImportBuildingPlan()
    {
        $root = $this->ReadPropertyInteger('BuildingRoot');
        if (!IPS_ObjectExists($root)) throw new Exception('Gebäude-Kategorie fehlt');
        $plan = $this->ReadBuildingPlan();
        $this->WriteAttributeString('BuildingPlan', json_encode($plan, JSON_UNESCAPED_UNICODE));
        $this->ReloadForm();
    }

    private function ReadBuildingPlan($root = null)
    {
        if ($root === null) $root = $this->ReadPropertyInteger('BuildingRoot');
        if (!IPS_ObjectExists($root)) return [];
        $plan = [];
        foreach (IPS_GetChildrenIDs($root) as $floorID) {
            $floor = IPS_GetObject($floorID);
            if ($floor['ObjectType'] !== 0) continue;
            $floorName = $floor['ObjectName'];
            $plan[$floorName] = [];
            foreach (IPS_GetChildrenIDs($floorID) as $roomID) {
                $room = IPS_GetObject($roomID);
                if ($room['ObjectType'] === 0) $plan[$floorName][] = $room['ObjectName'];
            }
        }
        return $plan;
    }

    public function EditBuildingPlan(string $action)
    {
        $plan = json_decode($this->ReadAttributeString('BuildingPlan'), true);
        if (!is_array($plan)) $plan = [];
        $floor = $this->ReadAttributeString('DraftFloor');
        $room = $this->ReadAttributeString('DraftRoom');
        if ($floor === '') throw new Exception('Bitte Etage eingeben');
        if ($action === 'AddFloor') {
            if (!isset($plan[$floor])) $plan[$floor] = [];
        } elseif ($action === 'RemoveFloor') {
            if (isset($plan[$floor]) && count($plan[$floor]) === 0) unset($plan[$floor]);
            else throw new Exception('Etage fehlt oder enthält noch Räume');
        } else {
            if ($room === '') throw new Exception('Bitte Raum eingeben');
            if (!isset($plan[$floor])) throw new Exception('Etage zuerst anlegen');
            if ($action === 'AddRoom') {
                if (!in_array($room, $plan[$floor], true)) $plan[$floor][] = $room;
            } elseif ($action === 'RemoveRoom') {
                $plan[$floor] = array_values(array_filter($plan[$floor], static function ($r) use ($room) { return $r !== $room; }));
            } else throw new Exception('Unbekannte Aktion');
        }
        $this->WriteAttributeString('BuildingPlan', json_encode($plan, JSON_UNESCAPED_UNICODE));
        $this->ReloadForm();
    }

    /**
     * Configurable KNX source category: direct instances and dummy groups.
     * Categories (including script folders) are ignored.
     */
    private function DiscoverPilotDevices()
    {
        $root = $this->ReadPropertyInteger('DeviceSourceCategory');
        if ($root <= 0 || !IPS_ObjectExists($root) || IPS_GetObject($root)['ObjectType'] !== 0) return [];
        $devices = [];
        $this->WalkDeviceCategories($root, $root, $devices, 0);
        return $devices;
    }

    private function WalkDeviceCategories($category, $root, &$devices, $depth)
    {
        if ($depth > 12 || count($devices) >= 500) return;
        foreach (IPS_GetChildrenIDs($category) as $id) {
            $obj = IPS_GetObject($id);
            if ($obj['ObjectType'] === 0) {
                if (preg_match('/^(Scripte?_Funktionen|Skripte?|Scripts?|Funktionen)$/iu', $obj['ObjectName'])) continue;
                $this->WalkDeviceCategories($id, $root, $devices, $depth + 1);
                continue;
            }
            if ($obj['ObjectType'] !== 1) continue;
            $isDummy = (IPS_GetInstance($id)['ModuleInfo']['ModuleID'] ?? '') === '{485D0419-BE97-4548-AA9C-C083EB82E61E}';
            $rawName = $obj['ObjectName'];
            $name = $isDummy ? $rawName : preg_replace('/_(s|d|w)$/iu', '', $rawName);
            if (!$isDummy) $name = preg_replace('/^Bel\\.[^.]+\\./iu', '', $name);
            $members = $isDummy ? IPS_GetChildrenIDs($id) : [$id];
            $functions = [];
            foreach ($members as $member) {
                if (IPS_GetObject($member)['ObjectType'] !== 1) continue;
                $instanceName = IPS_GetName($member);
                $function = 'Wert';
                if (preg_match('/_s$/iu', $instanceName)) $function = 'Schalten';
                elseif (preg_match('/_w$/iu', $instanceName)) $function = 'Dimmen';
                elseif (preg_match('/_d$/iu', $instanceName)) $function = 'Dimmen';
                foreach (IPS_GetChildrenIDs($member) as $variableID) {
                    if (IPS_GetObject($variableID)['ObjectType'] !== 2) continue;
                    $label = $function;
                    if (isset($functions[$label])) $label .= ' ' . $variableID;
                    $functions[$label] = $variableID;
                }
            }
            if (!$functions) continue;
            $devices[(string) $id] = [
                'name' => $name, 'functions' => $functions,
                'path' => IPS_GetLocation($id),
                'category' => $category,
                'proposal' => $this->SuggestDevicePlacement($name, $category, $root)
            ];
        }
    }

    private function SuggestDevicePlacement($name, $category, $root)
    {
        $rootName = IPS_GetName($root);
        $segments = [];
        $cursor = $category;
        while ($cursor > 0 && $cursor !== $root && count($segments) < 15) {
            array_unshift($segments, IPS_GetName($cursor));
            $cursor = IPS_GetParent($cursor);
        }
        $rooms = $this->ReadBuildingPlan($this->ReadPropertyInteger('ManagedBuildingRoot'));
        $aliases = json_decode($this->ReadPropertyString('RoomAliases'), true);
        if (!is_array($aliases)) $aliases = [];
        $room = '';
        $floor = '';
        foreach ($rooms as $floorName => $roomNames) {
            foreach ($roomNames as $roomName) {
                $haystack = mb_strtolower(implode(' ', $segments) . ' ' . $name, 'UTF-8');
                if (mb_strpos($haystack, mb_strtolower($roomName, 'UTF-8')) !== false) {
                    $room = $roomName;
                    $floor = $floorName;
                    break 2;
                }
            }
        }
        if ($room === '') {
            foreach ($aliases as $token => $location) {
                if ($token !== '' && mb_strpos(mb_strtolower($name . ' ' . implode(' ', $segments), 'UTF-8'), mb_strtolower($token, 'UTF-8')) !== false) {
                    $parts = explode('/', (string) $location);
                    if (count($parts) >= 2) { $floor = $parts[0]; $room = $parts[1]; break; }
                }
            }
        }
        if ($room === '' && preg_match('/^([^_]+)_([^_]+)/u', $name, $m)) {
            $room = $m[1];
        }
        if ($room === '') {
            if (preg_match('/\\blotta\\b/iu', $name . ' ' . implode(' ', $segments))) { $floor = 'OG'; $room = 'Lotta'; }
            elseif (preg_match('/\\bluise\\b/iu', $name . ' ' . implode(' ', $segments))) { $floor = 'OG'; $room = 'Luise'; }
        }
        $trade = 'Unbekannt';
        $haystack = mb_strtolower(implode(' ', $segments) . ' ' . $name, 'UTF-8');
        if (preg_match('/beleuchtung|licht|lampe|strahler|kugel|led|dimm/u', $haystack)) $trade = 'Beleuchtung';
        elseif (preg_match('/heizung|thermostat|temperatur|hzg/u', $haystack)) $trade = 'Heizung';
        elseif (preg_match('/rollladen|rolladen|jalous|shutter/u', $haystack)) $trade = 'Rollladen';
        elseif (preg_match('/musik|audio|tv|media|multimedia/u', $haystack)) $trade = 'Multimedia';
        return ['floor' => $floor, 'room' => $room, 'trade' => $trade, 'source' => $rootName];
    }

    private function GetPilotTarget()
    {
        $root = $this->ReadPropertyInteger('ManagedBuildingRoot');
        $target = $this->ReadPropertyInteger('DeviceTargetCategory');
        if ($root <= 0 || $target <= 0 || !IPS_ObjectExists($root) || !IPS_ObjectExists($target)) return 0;
        if (IPS_GetObject($root)['ObjectType'] !== 0 || IPS_GetObject($target)['ObjectType'] !== 0) return 0;
        $cursor = $target;
        for ($depth = 0; $depth < 20 && $cursor > 0; $depth++) {
            if ($cursor === $root) return $target;
            $cursor = IPS_GetParent($cursor);
        }
        return 0; // refuse writes outside managed building root
    }

    private function FindDeviceDummy($parent, $name)
    {
        if (!$parent) return 0;
        foreach (IPS_GetChildrenIDs($parent) as $id) {
            $obj = IPS_GetObject($id);
            if ($obj['ObjectType'] !== 1 || $obj['ObjectName'] !== $name) continue;
            if ((IPS_GetInstance($id)['ModuleInfo']['ModuleID'] ?? '') === '{485D0419-BE97-4548-AA9C-C083EB82E61E}') return $id;
            throw new Exception('Namenskonflikt mit anderer Instanz: ' . $name);
        }
        return 0;
    }

    private function DeviceAssignment($id, $device)
    {
        $all = json_decode($this->ReadAttributeString('DeviceAssignments'), true);
        if (!is_array($all)) $all = [];
        $proposal = $device['proposal'];
        $saved = $all[(string) $id] ?? [];
        return [
            'floor' => $saved['floor'] ?? $proposal['floor'],
            'room' => $saved['room'] ?? $proposal['room'],
            'trade' => $saved['trade'] ?? $proposal['trade'],
            'variables' => $saved['variables'] ?? []
        ];
    }

    private function BuildTestDeviceEditor($deviceID)
    {
        $devices = $this->DiscoverPilotDevices();
        if (!isset($devices[(string) $deviceID])) {
            return ['type' => 'Label', 'caption' => 'Testeditor: Shelly Klima Wohnzimmer (52319) ist in der gewählten Hardwarequelle nicht enthalten.'];
        }
        $device = $devices[(string) $deviceID];
        $a = $this->DeviceAssignment($deviceID, $device);
        $plan = $this->ReadBuildingPlan($this->ReadPropertyInteger('ManagedBuildingRoot'));
        $floorOptions = [];
        foreach (array_keys($plan) as $floor) $floorOptions[] = ['caption' => $floor, 'value' => $floor];
        if ($a['floor'] !== '' && !isset($plan[$a['floor']])) $floorOptions[] = ['caption' => $a['floor'], 'value' => $a['floor']];
        $rooms = $plan[$a['floor']] ?? [];
        $roomOptions = [];
        foreach ($rooms as $room) $roomOptions[] = ['caption' => $room, 'value' => $room];
        if ($a['room'] !== '' && !in_array($a['room'], $rooms, true)) $roomOptions[] = ['caption' => $a['room'], 'value' => $a['room']];
        $tradeOptions = [];
        foreach (['Unbekannt', 'Beleuchtung', 'Heizung', 'Rollladen', 'Multimedia', 'Steckdosen', 'Sensoren', 'Sonstige'] as $trade) {
            $tradeOptions[] = ['caption' => $trade, 'value' => $trade];
        }
        $items = [
            ['type' => 'Label', 'caption' => 'Testgerät: ' . $device['name'] . ' | ' . $device['path'] . '. Änderungen werden nur als Entwurf gespeichert. Keine Dummys oder Links werden angelegt.'],
            ['type' => 'Select', 'name' => 'TestFloor', 'caption' => 'Etage', 'value' => $a['floor'], 'options' => $floorOptions,
                'onChange' => 'SDM_SetDeviceAssignment($id, ' . $deviceID . ', "floor", $TestFloor);'],
            ['type' => 'Select', 'name' => 'TestRoom', 'caption' => 'Raum', 'value' => $a['room'], 'options' => $roomOptions,
                'onChange' => 'SDM_SetDeviceAssignment($id, ' . $deviceID . ', "room", $TestRoom);'],
            ['type' => 'Select', 'name' => 'TestTrade', 'caption' => 'Gewerk', 'value' => $a['trade'], 'options' => $tradeOptions,
                'onChange' => 'SDM_SetDeviceAssignment($id, ' . $deviceID . ', "trade", $TestTrade);'],
            ['type' => 'Label', 'caption' => 'Zu verlinkende Variablen (nur Entwurf):']
        ];
        foreach ($device['functions'] as $function => $variableID) {
            $checked = array_key_exists((string) $variableID, $a['variables']) ? (bool) $a['variables'][(string) $variableID] : true;
            $items[] = ['type' => 'CheckBox', 'name' => 'TestVar_' . $variableID,
                'caption' => IPS_GetName($variableID) . ' (' . $variableID . ') – ' . $function,
                'value' => $checked,
                'onChange' => 'SDM_SetDeviceVariable($id, ' . $deviceID . ', ' . $variableID . ', $TestVar_' . $variableID . ');'];
        }
        return ['type' => 'ExpansionPanel', 'caption' => 'Test: Klima Wohnzimmer – Raum, Gewerk und Variablen bearbeiten', 'items' => $items];
    }

    private function BuildNativeDeviceConfigurator()
    {
        $devices = $this->DiscoverPilotDevices();
        $linked = $this->ExistingDeviceVariableIDs();
        $values = [];
        $hidden = 0;
        foreach ($devices as $id => $device) {
            $functions = array_values($device['functions']);
            $allLinked = count($functions) > 0;
            foreach ($functions as $variableID) {
                if (!isset($linked[$variableID])) { $allLinked = false; break; }
            }
            if ($allLinked) { $hidden++; continue; }
            $a = $this->DeviceAssignment($id, $device);
            $values[] = [
                'id' => (int) $id,
                'name' => $device['name'],
                'device' => $device['name'],
                'source' => $device['path'],
                'floor' => $a['floor'] ?: '—',
                'room' => $a['room'] ?: '—',
                'trade' => $a['trade'] ?: 'Unbekannt',
                'variables' => count($functions),
                'sourceID' => (int) $id
            ];
        }
        return [
            'type' => 'Configurator',
            'name' => 'DeviceNativeConfigurator',
            'caption' => 'Gefundene Geräte (' . count($values) . ' offen, ' . $hidden . ' bereits verlinkt ausgeblendet)',
            'rowCount' => 18,
            'delete' => false,
            'sort' => ['column' => 'device', 'direction' => 'ascending'],
            'columns' => [
                ['caption' => 'Gerät', 'name' => 'device', 'width' => '250px'],
                ['caption' => 'Etage', 'name' => 'floor', 'width' => '70px'],
                ['caption' => 'Raum', 'name' => 'room', 'width' => '130px'],
                ['caption' => 'Gewerk', 'name' => 'trade', 'width' => '130px'],
                ['caption' => 'Variablen', 'name' => 'variables', 'width' => '85px'],
                ['caption' => 'Hardware-Pfad', 'name' => 'source', 'width' => 'auto'],
                ['caption' => 'Quell-ID', 'name' => 'sourceID', 'width' => '90px']
            ],
            'values' => $values
        ];
    }

    private function ExistingDeviceVariableIDs()
    {
        $root = $this->ReadPropertyInteger('ManagedBuildingRoot');
        $targets = [];
        if ($root <= 0 || !IPS_ObjectExists($root)) return $targets;
        $stack = [[$root, 0]];
        while ($stack) {
            [$parent, $depth] = array_pop($stack);
            if ($depth > 12) continue;
            foreach (IPS_GetChildrenIDs($parent) as $child) {
                $obj = IPS_GetObject($child);
                if ($obj['ObjectType'] === 6) {
                    $target = IPS_GetLink($child)['TargetID'];
                    if ($target > 0) $targets[$target] = true;
                } elseif ($obj['ObjectType'] === 0 || $obj['ObjectType'] === 1) {
                    $stack[] = [$child, $depth + 1];
                }
            }
        }
        return $targets;
    }

    public function SetShowExistingDevices(bool $enabled)
    {
        $this->WriteAttributeBoolean('ShowExistingDevices', $enabled);
        $this->UpdateFormField('ShowExistingDevices', 'value', $enabled);
    }

    private function BuildDeviceImportControls()
    {
        $devices = $this->DiscoverPilotDevices();
        $selected = json_decode($this->ReadAttributeString('DeviceSelections'), true);
        if (!is_array($selected)) $selected = [];
        $controls = [];
        $sourceID = $this->ReadPropertyInteger('DeviceSourceCategory');
        $sourceName = ($sourceID > 0 && IPS_ObjectExists($sourceID)) ? IPS_GetLocation($sourceID) : 'nicht gefunden';
        $controls[] = ['type' => 'Label', 'caption' => 'Quelle: ' . $sourceName . ' | Zielwurzel: ' . IPS_GetLocation($this->ReadPropertyInteger('ManagedBuildingRoot'))];
        $controls[] = ['type' => 'Label', 'caption' => count($devices) . ' Geräte erkannt. Markiere Geräte und bearbeite ihre Zuordnung einzeln. Skript-Unterkategorien werden ignoriert.'];
        $linkedTargets = $this->ExistingDeviceVariableIDs();
        $showExisting = $this->ReadAttributeBoolean('ShowExistingDevices');
        $controls[] = ['type' => 'CheckBox', 'name' => 'ShowExistingDevices', 'caption' => 'Bereits verlinkte Geräte anzeigen', 'value' => $showExisting,
            'onChange' => 'SDM_SetShowExistingDevices($id, $ShowExistingDevices);'];
        $editing = $this->ReadAttributeInteger('EditingDevice');
        $search = trim($this->ReadAttributeString('DeviceSearch'));
        $controls[] = ['type' => 'ValidationTextBox', 'name' => 'DeviceSearch', 'caption' => 'Volltextfilter (Gerät, Pfad, Raum, Gewerk)',
            'value' => $search, 'onChange' => 'SDM_SetDeviceSearch($id, $DeviceSearch);'];
        $controls[] = ['type' => 'Label', 'caption' => 'Suche filtert die Auswahlliste direkt, ohne den Bereich zu schließen.'];
        $deviceOptions = [['caption' => 'Gerät auswählen …', 'value' => 0]];
        $tableRows = [];
        $hiddenExisting = 0;
        foreach ($devices as $id => $device) {
            $assignment = $this->DeviceAssignment($id, $device);
            $allLinked = count($device['functions']) > 0;
            foreach ($device['functions'] as $variableID) {
                if (!isset($linkedTargets[$variableID])) { $allLinked = false; break; }
            }
            if ($allLinked && !$showExisting && (int) $id !== $editing) { $hiddenExisting++; continue; }
            $label = $device['name'] . ' | ' . ($assignment['floor'] ?: '?') . '/' . ($assignment['room'] ?: '?') . '/' . ($assignment['trade'] ?: '?');
            $haystack = $label . ' ' . $device['path'] . ' ' . $id;
            if ($search !== '' && mb_stripos($haystack, $search, 0, 'UTF-8') === false && (int) $id !== $editing) continue;
            $deviceOptions[] = ['caption' => $label . ' (' . $id . ')', 'value' => (int) $id];
            $tableRows[] = ['device' => $device['name'], 'floor' => $assignment['floor'] ?: '—', 'room' => $assignment['room'] ?: '—', 'trade' => $assignment['trade'], 'count' => count($device['functions']), 'state' => $allLinked ? 'Verlinkt' : 'Offen'];
        }

        $controls[] = ['type' => 'Label', 'caption' => count($tableRows) . ' Geräte sichtbar, ' . $hiddenExisting . ' vollständig verlinkte Geräte ausgeblendet.'];
        $controls[] = ['type' => 'List', 'name' => 'DeviceOverview', 'caption' => 'Gefundene Geräte', 'rowCount' => 12, 'add' => false, 'delete' => false, 'columns' => [
            ['caption' => 'Gerät', 'name' => 'device', 'width' => '260px'],
            ['caption' => 'Etage', 'name' => 'floor', 'width' => '75px'],
            ['caption' => 'Raum', 'name' => 'room', 'width' => '140px'],
            ['caption' => 'Gewerk', 'name' => 'trade', 'width' => '130px'],
            ['caption' => 'Variablen', 'name' => 'count', 'width' => '90px'],
            ['caption' => 'Status', 'name' => 'state', 'width' => '90px']
        ], 'values' => $tableRows];
        $controls[] = ['type' => 'Select', 'name' => 'EditingDevice', 'caption' => 'Gerät auswählen (Dropdown)',
            'value' => isset($devices[(string) $editing]) ? $editing : 0, 'options' => $deviceOptions,
            'onChange' => 'SDM_SetEditingDevice($id, $EditingDevice);'];
        if ($editing > 0 && isset($devices[(string) $editing])) {
            $device = $devices[(string) $editing];
            $assignment = $this->DeviceAssignment($editing, $device);
            $controls[] = ['type' => 'CheckBox', 'name' => 'DeviceSelect_' . $editing,
                'caption' => 'Dieses Gerät zur Übernahme vormerken (' . count($device['functions']) . ' Variablen vorhanden)',
                'value' => !empty($selected[(string) $editing]),
                'onChange' => 'SDM_SetDeviceSelection($id, ' . $editing . ', $DeviceSelect_' . $editing . ');'];
        }
        $controls[] = ['type' => 'Label', 'caption' => count($selected) . ' Gerät(e) zur Übernahme vorgemerkt.'];
        $controls[] = ['type' => 'Button', 'caption' => 'Ausgewähltes Gerät zur Bearbeitung öffnen', 'onClick' => 'IPS_RequestAction($id, "RefreshForm", 0);'];
        if ($editing > 0 && isset($devices[(string) $editing])) {
            $device = $devices[(string) $editing];
            $assignment = $this->DeviceAssignment($editing, $device);
            $plan = $this->ReadBuildingPlan($this->ReadPropertyInteger('ManagedBuildingRoot'));
            $floorOptions = [['caption' => 'Etage wählen', 'value' => '']];
            foreach (array_keys($plan) as $floor) $floorOptions[] = ['caption' => $floor, 'value' => $floor];
            if ($assignment['floor'] !== '' && !isset($plan[$assignment['floor']])) $floorOptions[] = ['caption' => $assignment['floor'] . ' (Vorschlag, neu)', 'value' => $assignment['floor']];
            $roomOptions = [['caption' => 'Raum wählen', 'value' => '']];
            foreach (($plan[$assignment['floor']] ?? []) as $room) $roomOptions[] = ['caption' => $room, 'value' => $room];
            if ($assignment['room'] !== '' && !in_array($assignment['room'], $plan[$assignment['floor']] ?? [], true)) $roomOptions[] = ['caption' => $assignment['room'] . ' (Vorschlag, neu)', 'value' => $assignment['room']];
            $trades = ['Beleuchtung', 'Heizung', 'Rollladen', 'Multimedia', 'Steckdosen', 'Sensoren', 'Sonstige'];
            $tradeOptions = [['caption' => 'Gewerk auswählen', 'value' => 'Unbekannt']];
            foreach ($trades as $trade) $tradeOptions[] = ['caption' => $trade, 'value' => $trade];
            $controls[] = ['type' => 'Label', 'caption' => 'Bearbeitung: ' . $device['path']];
            $controls[] = ['type' => 'Select', 'name' => 'AssignFloor', 'caption' => 'Etage', 'value' => $assignment['floor'], 'options' => $floorOptions,
                'onChange' => 'SDM_SetDeviceAssignment($id, ' . $editing . ', "floor", $AssignFloor);'];
            $controls[] = ['type' => 'Select', 'name' => 'AssignRoom', 'caption' => 'Raum', 'value' => $assignment['room'], 'options' => $roomOptions,
                'onChange' => 'SDM_SetDeviceAssignment($id, ' . $editing . ', "room", $AssignRoom);'];
            $controls[] = ['type' => 'Select', 'name' => 'AssignTrade', 'caption' => 'Gewerk', 'value' => $assignment['trade'], 'options' => $tradeOptions,
                'onChange' => 'SDM_SetDeviceAssignment($id, ' . $editing . ', "trade", $AssignTrade);'];
            $controls[] = ['type' => 'ValidationTextBox', 'name' => 'CustomRoom', 'caption' => 'Alternativer Raumname (optional; Eingabe bestätigen)',
                'value' => $assignment['room'], 'onChange' => 'SDM_SetDeviceAssignment($id, ' . $editing . ', "room", $CustomRoom);'];
            $controls[] = ['type' => 'Label', 'caption' => 'Variablen auswählen: Haken = Link wird angelegt; bestehende Links werden nicht gelöscht.'];
            foreach ($device['functions'] as $function => $variableID) {
                $enabled = array_key_exists((string) $variableID, $assignment['variables']) ? (bool) $assignment['variables'][(string) $variableID] : true;
                $controls[] = ['type' => 'CheckBox', 'name' => 'VariableSelect_' . $variableID,
                    'caption' => $function . ' → ' . IPS_GetName($variableID) . ' (' . $variableID . ')',
                    'value' => $enabled,
                    'onChange' => 'SDM_SetDeviceVariable($id, ' . $editing . ', ' . $variableID . ', $VariableSelect_' . $variableID . ');'];
            }
        }
        if (!$devices) $controls[] = ['type' => 'Label', 'caption' => 'Keine Geräte gefunden.'];
        return $controls;
    }

    public function SetEditingDevice(int $deviceID)
    {
        $this->WriteAttributeInteger('EditingDevice', $deviceID);
    }

    public function SetDeviceSearch(string $search)
    {
        $search = trim($search);
        $this->WriteAttributeString('DeviceSearch', $search);
        $devices = $this->DiscoverPilotDevices();
        $editing = $this->ReadAttributeInteger('EditingDevice');
        $options = [['caption' => 'Gerät auswählen …', 'value' => 0]];
        foreach ($devices as $id => $device) {
            $assignment = $this->DeviceAssignment($id, $device);
            $label = $device['name'] . ' | ' . ($assignment['floor'] ?: '?') . '/' . ($assignment['room'] ?: '?') . '/' . ($assignment['trade'] ?: '?');
            $haystack = $label . ' ' . $device['path'] . ' ' . $id;
            if ($search !== '' && mb_stripos($haystack, $search, 0, 'UTF-8') === false && (int) $id !== $editing) continue;
            $options[] = ['caption' => $label . ' (' . $id . ')', 'value' => (int) $id];
        }
        $this->UpdateFormField('EditingDevice', 'options', json_encode($options, JSON_UNESCAPED_UNICODE));
    }

    public function SetDeviceAssignment(int $deviceID, string $field, string $value)
    {
        if (!in_array($field, ['floor', 'room', 'trade'], true)) throw new Exception('Ungültiges Zuordnungsfeld');
        $devices = $this->DiscoverPilotDevices();
        if (!isset($devices[(string) $deviceID])) throw new Exception('Gerät nicht gefunden');
        $all = json_decode($this->ReadAttributeString('DeviceAssignments'), true);
        if (!is_array($all)) $all = [];
        if (!isset($all[(string) $deviceID])) $all[(string) $deviceID] = $this->DeviceAssignment($deviceID, $devices[(string) $deviceID]);
        $all[(string) $deviceID][$field] = trim($value);
        if ($field === 'floor') $all[(string) $deviceID]['room'] = '';
        $this->WriteAttributeString('DeviceAssignments', json_encode($all, JSON_UNESCAPED_UNICODE));
    }

    public function SetDeviceVariable(int $deviceID, int $variableID, bool $enabled)
    {
        $devices = $this->DiscoverPilotDevices();
        if (!isset($devices[(string) $deviceID]) || !in_array($variableID, $devices[(string) $deviceID]['functions'], true)) throw new Exception('Variable gehört nicht zum Gerät');
        $all = json_decode($this->ReadAttributeString('DeviceAssignments'), true);
        if (!is_array($all)) $all = [];
        if (!isset($all[(string) $deviceID])) $all[(string) $deviceID] = $this->DeviceAssignment($deviceID, $devices[(string) $deviceID]);
        $all[(string) $deviceID]['variables'][(string) $variableID] = $enabled;
        $this->WriteAttributeString('DeviceAssignments', json_encode($all, JSON_UNESCAPED_UNICODE));
    }

    public function SetDeviceSelection(int $deviceID, bool $enabled)
    {
        $selected = json_decode($this->ReadAttributeString('DeviceSelections'), true);
        if (!is_array($selected)) $selected = [];
        if ($enabled) $selected[(string) $deviceID] = true;
        else unset($selected[(string) $deviceID]);
        $this->WriteAttributeString('DeviceSelections', json_encode($selected));
    }

    public function ApplyDeviceSelection()
    {
        $root = $this->ReadPropertyInteger('ManagedBuildingRoot');
        if ($root <= 0 || !IPS_ObjectExists($root) || IPS_GetObject($root)['ObjectType'] !== 0) throw new Exception('Gebäudewurzel fehlt');
        $selected = json_decode($this->ReadAttributeString('DeviceSelections'), true);
        if (!is_array($selected) || !$selected) throw new Exception('Bitte mindestens ein Gerät auswählen.');
        $devices = $this->DiscoverPilotDevices();
        $operations = [];
        foreach (array_keys($selected) as $id) {
            if (!isset($devices[$id])) throw new Exception('Ausgewähltes Gerät nicht mehr vorhanden: ' . $id);
            $device = $devices[$id];
            $assignment = $this->DeviceAssignment($id, $device);
            foreach (['floor', 'room', 'trade'] as $field) {
                if ($assignment[$field] === '' || $assignment[$field] === 'Unbekannt' || strpos($assignment[$field], '/') !== false) {
                    throw new Exception('Etage, Raum und Gewerk für ' . $device['name'] . ' vollständig auswählen.');
                }
            }
            $chosen = [];
            foreach ($device['functions'] as $function => $variableID) {
                if (isset($assignment['variables'][(string) $variableID]) && !$assignment['variables'][(string) $variableID]) continue;
                if (IPS_ObjectExists($variableID) && IPS_GetObject($variableID)['ObjectType'] === 2) $chosen[$function] = $variableID;
            }
            if (!$chosen) throw new Exception('Keine Variablen ausgewählt: ' . $device['name']);
            $operations[] = ['device' => $device, 'assignment' => $assignment, 'functions' => $chosen];
        }
        foreach ($operations as $op) {
            $a = $op['assignment'];
            $floor = $this->FindOrCreateCategory($root, $a['floor']);
            $room = $this->FindOrCreateCategory($floor, $a['room']);
            $target = $this->FindOrCreateCategory($room, $a['trade']);
            $dummy = $this->FindDeviceDummy($target, $op['device']['name']);
            if (!$dummy) {
                $dummy = IPS_CreateInstance('{485D0419-BE97-4548-AA9C-C083EB82E61E}');
                IPS_SetParent($dummy, $target);
                IPS_SetName($dummy, $op['device']['name']);
            }
            foreach ($op['functions'] as $function => $variableID) {
                $link = 0;
                foreach (IPS_GetChildrenIDs($dummy) as $childID) {
                    if (IPS_GetObject($childID)['ObjectType'] === 6 && IPS_GetName($childID) === $function) { $link = $childID; break; }
                }
                if (!$link) {
                    $link = IPS_CreateLink();
                    IPS_SetParent($link, $dummy);
                    IPS_SetName($link, $function);
                }
                IPS_SetLinkTargetID($link, $variableID);
            }
        }
        $this->WriteAttributeString('DeviceSelections', '{}');
        $this->WriteAttributeInteger('EditingDevice', 0);
        $this->ReloadForm();
    }

    private function FindCategoryByName($parent, $name)
    {
        if (!IPS_ObjectExists($parent)) return 0;
        foreach (IPS_GetChildrenIDs($parent) as $id) {
            $obj = IPS_GetObject($id);
            if ($obj['ObjectType'] === 0 && $obj['ObjectName'] === $name) return $id;
        }
        return 0;
    }

    private function BuildImportControls()
    {
        $source = $this->ReadPropertyInteger('BuildingRoot');
        $target = $this->ReadPropertyInteger('ManagedBuildingRoot');
        if ($source <= 0 || $target <= 0 || !IPS_ObjectExists($source) || !IPS_ObjectExists($target) || $source === $target) {
            return [['type' => 'Label', 'caption' => 'Quell- oder Zielstruktur fehlt.']];
        }
        $selected = json_decode($this->ReadAttributeString('ImportSelections'), true);
        if (!is_array($selected)) $selected = [];
        $pending = [];
        $complete = [];
        $count = 0;
        foreach (IPS_GetChildrenIDs($source) as $floorID) {
            if (IPS_GetObject($floorID)['ObjectType'] !== 0) continue;
            $floor = IPS_GetName($floorID);
            $targetFloor = $this->FindCategoryByName($target, $floor);
            foreach (IPS_GetChildrenIDs($floorID) as $roomID) {
                if (IPS_GetObject($roomID)['ObjectType'] !== 0) continue;
                $room = IPS_GetName($roomID);
                $targetRoom = $targetFloor ? $this->FindCategoryByName($targetFloor, $room) : 0;
                $missing = [];
                if ($targetRoom) {
                    foreach (IPS_GetChildrenIDs($roomID) as $tradeID) {
                        $trade = IPS_GetObject($tradeID);
                        if ($trade['ObjectType'] === 0 && !$this->FindCategoryByName($targetRoom, $trade['ObjectName'])) {
                            $missing[] = $trade['ObjectName'];
                        }
                    }
                }
                $isComplete = $targetRoom && count($missing) === 0;
                $caption = $floor . ' / ' . $room;
                if ($targetRoom && count($missing) > 0) $caption .= ' (fehlende Gewerke: ' . implode(', ', $missing) . ')';
                if ($isComplete) {
                    $complete[] = ['type' => 'Label', 'caption' => '✓ ' . $caption . ' (bereits vorhanden)'];
                } else {
                    $pending[] = [
                        'type' => 'CheckBox', 'name' => 'ImportSelect_' . $roomID,
                        'caption' => $caption, 'value' => !empty($selected[(string) $roomID]),
                        'onChange' => 'SDM_SetImportSelection($id, ' . $roomID . ', $ImportSelect_' . $roomID . ');'
                    ];
                }
                $count++;
                if ($count >= 200) break 2;
            }
        }
        $controls = [['type' => 'Label', 'caption' => count($pending) . ' offen / ' . count($complete) . ' vollständig übernommen']];
        if (!$pending) $controls[] = ['type' => 'Label', 'caption' => 'Keine offenen Räume.'];
        $controls = array_merge($controls, $pending);
        if ($this->ReadAttributeBoolean('ShowImportedRooms') && $complete) {
            $controls[] = ['type' => 'Label', 'caption' => 'Bereits vorhandene Räume (nur Anzeige):'];
            $controls = array_merge($controls, $complete);
        }
        if ($count >= 200) $controls[] = ['type' => 'Label', 'caption' => 'Anzeige auf 200 Räume begrenzt.'];
        return $controls;
    }

    public function SetShowImportedRooms(bool $enabled)
    {
        $this->WriteAttributeBoolean('ShowImportedRooms', (bool) $enabled);
        $this->ReloadForm();
    }

    public function SetImportSelection(int $roomID, bool $enabled)
    {
        $selected = json_decode($this->ReadAttributeString('ImportSelections'), true);
        if (!is_array($selected)) $selected = [];
        if ($enabled) $selected[(string) $roomID] = true;
        else unset($selected[(string) $roomID]);
        $this->WriteAttributeString('ImportSelections', json_encode($selected));
    }

    public function ApplyImportSelection()
    {
        $source = $this->ReadPropertyInteger('BuildingRoot');
        $target = $this->ReadPropertyInteger('ManagedBuildingRoot');
        if ($source <= 0 || $target <= 0 || !IPS_ObjectExists($source) || !IPS_ObjectExists($target) || $source === $target) {
            throw new Exception('Quell- und Zielkategorie müssen gültig und verschieden sein.');
        }
        if (IPS_GetObject($source)['ObjectType'] !== 0 || IPS_GetObject($target)['ObjectType'] !== 0) {
            throw new Exception('Quelle und Ziel müssen Kategorien sein.');
        }
        if ($target === $this->ReadPropertyInteger('HardwareRoot')) throw new Exception('Hardware darf kein Ziel sein.');
        $selected = json_decode($this->ReadAttributeString('ImportSelections'), true);
        if (!is_array($selected) || count($selected) === 0) throw new Exception('Bitte mindestens einen Raum auswählen.');
        $valid = [];
        foreach (IPS_GetChildrenIDs($source) as $floorID) {
            if (IPS_GetObject($floorID)['ObjectType'] !== 0) continue;
            foreach (IPS_GetChildrenIDs($floorID) as $roomID) {
                if (IPS_GetObject($roomID)['ObjectType'] !== 0) continue;
                $valid[(string) $roomID] = [$floorID, $roomID];
            }
        }
        foreach (array_keys($selected) as $key) {
            if (!isset($valid[$key])) continue;
            [$floorID, $roomID] = $valid[$key];
            $floorName = IPS_GetName($floorID);
            $roomName = IPS_GetName($roomID);
            $newFloor = $this->FindOrCreateCategory($target, $floorName);
            $newRoom = $this->FindOrCreateCategory($newFloor, $roomName);
            foreach (IPS_GetChildrenIDs($roomID) as $tradeID) {
                $trade = IPS_GetObject($tradeID);
                if ($trade['ObjectType'] === 0) $this->FindOrCreateCategory($newRoom, $trade['ObjectName']);
            }
        }
        $this->WriteAttributeString('ImportSelections', '{}');
        $this->ReloadForm();
    }

    /**
     * Only create categories inside the explicitly configured new building tree.
     * Never move, rename, delete or change hardware objects.
     */
    public function CreateBuildingCategory(string $level)
    {
        $root = $this->ReadPropertyInteger('ManagedBuildingRoot');
        if ($root <= 0 || !IPS_ObjectExists($root) || IPS_GetObject($root)['ObjectType'] !== 0) {
            throw new Exception('Ziel-Gebäudekategorie ist nicht gültig: ' . $root);
        }
        if ($root === $this->ReadPropertyInteger('HardwareRoot') || $root === $this->ReadPropertyInteger('BuildingRoot')) {
            throw new Exception('Die Zielstruktur muss getrennt von Hardware und Bestandsgebäude sein.');
        }
        $floor = trim($this->ReadAttributeString('DraftFloor'));
        $room = trim($this->ReadAttributeString('DraftRoom'));
        if ($floor === '' || strpos($floor, '/') !== false || $floor === '.' || $floor === '..') {
            throw new Exception('Bitte einen gültigen Etagenamen eingeben.');
        }
        if (!in_array($level, ['Floor', 'Room', 'Trade'], true)) throw new Exception('Ungültige Ebene');
        if ($level !== 'Floor' && ($room === '' || strpos($room, '/') !== false || $room === '.' || $room === '..')) {
            throw new Exception('Bitte einen gültigen Raumnamen eingeben.');
        }
        $floorID = $this->FindOrCreateCategory($root, $floor);
        if ($level !== 'Floor') {
            $roomID = $this->FindOrCreateCategory($floorID, $room);
            if ($level === 'Trade') $this->FindOrCreateCategory($roomID, 'Beleuchtung');
        }
        $this->ReloadForm();
    }

    private function FindOrCreateCategory($parent, $name)
    {
        foreach (IPS_GetChildrenIDs($parent) as $childID) {
            $child = IPS_GetObject($childID);
            if ($child['ObjectName'] === $name) {
                if ($child['ObjectType'] !== 0) throw new Exception('Namenskonflikt mit Nicht-Kategorie: ' . $name);
                return $childID;
            }
        }
        $id = IPS_CreateCategory();
        IPS_SetName($id, $name);
        IPS_SetParent($id, $parent);
        return $id;
    }

    public function GetPreviewJSON()
    {
        return $this->ReadAttributeString('LastScan');
    }

    private function ScanBranch($parent, $aliases, &$rows, $depth, $roomPath, &$seen)
    {
        if ($depth > 20 || count($rows) >= 4000) {
            return;
        }
        foreach (IPS_GetChildrenIDs($parent) as $id) {
            if (count($rows) >= 4000) return;
            $object = IPS_GetObject($id);
            $pathRoom = $this->RoomFromPath($id);
            if ($object['ObjectType'] === 1 && !isset($seen[$id])) {
                $seen[$id] = true;
                $name = $object['ObjectName'];
                $module = IPS_GetInstance($id)['ModuleInfo']['ModuleName'] ?? '';
                // Skip container-like device instances that expose many unrelated subfunctions.
                $isContainer = count(IPS_GetChildrenIDs($id)) > 12;
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
                        'device' => $isContainer ? $name . ' / ' . $child['ObjectName'] : $parsed['device'],
                        'function' => $parsed['function'] !== 'Unbekannt' ? $parsed['function'] : $child['ObjectName'],
                        'instance' => (string) $id,
                        'variable' => (string) $childID,
                        'confidence' => $parsed['confidence']
                    ];
                    if (count($rows) >= 4000) {
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
            $name = preg_replace('/_(s|d|w)$/iu', '', $r['device'] ?? 'Unbekannt');
            $name = preg_replace('/^(Bel|HZG|Rollo|Rolladen)[.\\-_ ]+/iu', '', $name);
            $name = preg_replace('/^(Wz|Wohnzim|Kü|Kueche|Küche)[.\\-_ ]+/iu', '', $name);
            $type = $r['deviceType'] ?? $this->GuessType($r['device'] ?? '');
            $key = mb_strtolower(($r['room'] ?? 'Nicht zugeordnet') . '|' . $type . '|' . $name, 'UTF-8');
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'room' => $r['room'] ?? 'Nicht zugeordnet', 'deviceType' => $type,
                    'device' => $name, 'functions' => [], 'count' => 0, 'instances' => []
                ];
            }
            $groups[$key]['count']++;
            $groups[$key]['functions'][$r['function'] ?? 'Unbekannt'] = true;
            $groups[$key]['instances'][$r['instance'] ?? '?'] = true;
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
            $function = 'Dimmen';
        } elseif (mb_strpos($normalized, 'ist-temperatur') !== false) {
            $function = 'Isttemperatur';
        } elseif (mb_strpos($normalized, 'soll-temperatur') !== false) {
            $function = 'Solltemperatur';
        }
        $device = preg_replace('/_(s|d|w)$/iu', '', $name);
        return ['room' => $room, 'device' => $device, 'function' => $function, 'confidence' => $confidence];
    }
}
