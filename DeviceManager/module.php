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
        $this->RegisterPropertyString('LinkNameRules', json_encode([['from'=>'s','to'=>'Schalten'],['from'=>'d','to'=>'Dimmen'],['from'=>'w','to'=>'Wert'],['from'=>'s.Status','to'=>'Schaltstatus'],['from'=>'w.Status','to'=>'Wertstatus']],JSON_UNESCAPED_UNICODE));
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
        $this->RegisterPropertyString('DeviceDraftRows', '[]');
        $this->RegisterAttributeString('DeviceListSearch', '');
        $this->RegisterAttributeString('ExistingDeviceSearch', '');
        $this->RegisterAttributeString('SeenSourceDevices', '[]');
        $this->RegisterAttributeBoolean('ShowSeenSourceDevices', false);
        $this->RegisterAttributeInteger('ManageDeviceID', 0);
        $this->RegisterAttributeString('ManagedEditDraft', '{}');
        $this->RegisterAttributeString('HardwareSwapDraft', '{}');

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
        $nativeConfigurator = $this->BuildNativeDeviceConfigurator();
        $deviceSearch = ['type' => 'ValidationTextBox', 'name' => 'DeviceListSearch', 'caption' => 'Geräte filtern (Volltext)', 'value' => $this->ReadAttributeString('DeviceListSearch'), 'onChange' => 'SDM_SetDeviceListSearch($id, $DeviceListSearch);'];
        $selectOptions = static function ($items) {
            return array_map(static function ($item) { return ['caption' => $item, 'value' => $item]; }, $items);
        };
        return json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => 'Version 0.4: Gebäudeverwaltung und auswählbare Geräteübernahme mit Dummys und Links.'],
                ['type' => 'NumberSpinner', 'name' => 'HardwareRoot', 'caption' => 'Hardware-Kategorie (ID)'],
                ['type' => 'NumberSpinner', 'name' => 'BuildingRoot', 'caption' => 'Bestehende Gebäude-Kategorie (ID)'],
                ['type' => 'SelectCategory', 'name' => 'DeviceSourceCategory', 'caption' => 'Gerätequelle auswählen (Hardware)'],
                ['type' => 'ValidationTextBox', 'name' => 'RoomAliases', 'caption' => 'Raum-Aliasse (JSON)'],
                ['type'=>'ExpansionPanel','caption'=>'Linknamen-Regeln (KNX und weitere)','items'=>[
                    ['type'=>'Label','caption'=>'Quellnamen werden exakt verglichen. Regeln können ergänzt und in jeder Geräteinstanz angepasst werden. Bereits manuell vergebene Linknamen bleiben erhalten.'],
                    ['type'=>'List','name'=>'LinkNameRules','caption'=>'Kürzel übersetzen','rowCount'=>8,'add'=>true,'delete'=>true,'columns'=>[
                        ['caption'=>'Quellname','name'=>'from','width'=>'50%','edit'=>['type'=>'ValidationTextBox']],
                        ['caption'=>'Linkname','name'=>'to','width'=>'50%','edit'=>['type'=>'ValidationTextBox']]
                    ]]
                ]],
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
                ['type' => 'Label', 'caption' => 'Geräte über Hinzufügen auswählen. Im Dialog werden Etage, Raum, Gewerk und Variablen festgelegt. Mit Änderungen übernehmen wird die Auswahl gespeichert; es werden noch keine Objekte erstellt.'],
                $deviceSearch,
                $nativeConfigurator,

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
            // Some integrations expose variables through nested instances or direct dummy variables.
            if ($isDummy) {
                foreach (IPS_GetChildrenIDs($id) as $variableID) {
                    if (IPS_GetObject($variableID)['ObjectType'] !== 2) continue;
                    $label = IPS_GetName($variableID);
                    if (isset($functions[$label])) $label .= ' '.$variableID;
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

    private function FindDeviceDummy($parent, $name, bool $throwOnConflict = true)
    {
        if (!$parent) return 0;
        foreach (IPS_GetChildrenIDs($parent) as $id) {
            $obj = IPS_GetObject($id);
            if ($obj['ObjectType'] !== 1 || $obj['ObjectName'] !== $name) continue;
            if ((IPS_GetInstance($id)['ModuleInfo']['ModuleID'] ?? '') === '{485D0419-BE97-4548-AA9C-C083EB82E61E}') return $id;
            if ($throwOnConflict) throw new Exception('Namenskonflikt mit anderer Instanz: ' . $name);
            return 0;
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
            'variables' => $saved['variables'] ?? [],
            'linkNames' => $saved['linkNames'] ?? [],
            'deviceName' => $saved['deviceName'] ?? $device['name'],
            'dummyID' => (int)($saved['dummyID'] ?? 0)
        ];
    }

    private function BuildDeviceEditorItems($deviceID)
    {
        $devices = $this->DiscoverPilotDevices();
        if (!isset($devices[(string) $deviceID])) {
            return [['type' => 'Label', 'caption' => 'Gerät nicht in der aktuellen Hardwarequelle gefunden.']];
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
            ['type' => 'Label', 'caption' => 'Gerät: ' . $device['name'] . ' | ' . $device['path'] . '. Änderungen werden nur als Entwurf gespeichert. Keine Dummys oder Links werden angelegt.'],
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
        return $items;
    }

    private function BuildUniversalDeviceEditor()
    {
        $devices = $this->DiscoverPilotDevices();
        $editing = $this->ReadAttributeInteger('EditingDevice');
        $options = [['caption' => 'Gerät auswählen …', 'value' => 0]];
        foreach ($devices as $id => $device) {
            $a = $this->DeviceAssignment($id, $device);
            $options[] = ['caption' => $device['name'] . ' | ' . ($a['room'] ?: '?') . ' | ' . $device['path'], 'value' => (int) $id];
        }
        $items = [
            ['type' => 'Label', 'caption' => 'Bearbeitung ohne vollständigen Formular-Neuaufbau. Noch keine Übernahmefunktion.'],
            ['type' => 'Select', 'name' => 'UniversalDeviceSelect', 'caption' => 'Gerät bearbeiten', 'value' => isset($devices[(string) $editing]) ? $editing : 0,
                'options' => $options, 'onChange' => 'SDM_OpenDeviceEditor($id, $UniversalDeviceSelect);'],
            ['type' => 'ExpansionPanel', 'name' => 'UniversalEditor', 'caption' => 'Etage, Raum, Gewerk und Variablen',
                'items' => ($editing > 0 && isset($devices[(string) $editing])) ? $this->BuildDeviceEditorItems($editing) : [
                    ['type' => 'Label', 'caption' => 'Bitte oben ein Gerät auswählen.']
                ]]
        ];
        return ['type' => 'ExpansionPanel', 'caption' => 'Geräte bearbeiten (Entwurf)', 'items' => $items];
    }

    public function OpenDeviceEditor(int $deviceID)
    {
        $devices = $this->DiscoverPilotDevices();
        if ($deviceID !== 0 && !isset($devices[(string) $deviceID])) throw new Exception('Gerät nicht gefunden');
        $this->WriteAttributeInteger('EditingDevice', $deviceID);
        $items = $deviceID > 0 ? $this->BuildDeviceEditorItems($deviceID) : [
            ['type' => 'Label', 'caption' => 'Bitte oben ein Gerät auswählen.']
        ];
        $this->UpdateFormField('UniversalEditor', 'items', json_encode($items, JSON_UNESCAPED_UNICODE));
    }

    public function SetDeviceListSearch(string $search)
    {
        $this->WriteAttributeString('DeviceListSearch', trim($search));
        $list = $this->BuildDeviceDraftList();
        $this->UpdateFormField('DeviceDiscoveryRows', 'values', json_encode($list['values'], JSON_UNESCAPED_UNICODE));
        $native = $this->BuildNativeDeviceConfigurator();
        $this->UpdateFormField('DeviceNativeConfigurator','values',json_encode($native['values'],JSON_UNESCAPED_UNICODE));
        $this->UpdateFormField('DeviceDiscoveryRows', 'caption', $list['caption']);
    }

    public function OpenDraftDeviceEditor(int $deviceID)
    {
        $this->OpenDeviceEditor($deviceID);
        $this->UpdateFormField('UniversalDeviceSelect', 'value', $deviceID);
    }

    public function GetDeviceDialogForm(int $deviceID): array
    {
        $devices = $this->DiscoverPilotDevices();
        $plan = $this->ReadBuildingPlan($this->ReadPropertyInteger('ManagedBuildingRoot'));
        $options = [['caption' => 'Gerät auswählen', 'value' => 0]];
        foreach ($devices as $id => $device) $options[] = ['caption' => $device['name'] . ' (' . $id . ')', 'value' => (int)$id];
        $floors = [['caption' => 'Etage auswählen', 'value' => '']];
        $rooms = [['caption' => 'Raum auswählen', 'value' => '']];
        foreach ($plan as $floor => $roomNames) {
            $floors[] = ['caption' => $floor, 'value' => $floor];
            foreach ($roomNames as $room) $rooms[$room] = ['caption' => $room, 'value' => $room];
        }
        $rooms = array_values($rooms);
        $trades = [];
        foreach (['Unbekannt','Beleuchtung','Heizung','Rollladen','Multimedia','Steckdosen','Sensoren','Sonstige'] as $trade) $trades[] = ['caption' => $trade, 'value' => $trade];
        $a = isset($devices[(string)$deviceID]) ? $this->DeviceAssignment($deviceID, $devices[(string)$deviceID]) : ['floor'=>'','room'=>'','trade'=>'Unbekannt'];
        return [
            ['type'=>'Select','name'=>'deviceID','caption'=>'Gerät','options'=>$options,'value'=>$deviceID,
                'onChange'=>'SDM_UpdateDeviceDialog($id, $deviceID);'],
            ['type'=>'ValidationTextBox','name'=>'deviceName','caption'=>'Gerätename (Ziel-Dummy)','value'=>$a['deviceName'] ?? '',
                'onChange'=>'SDM_SetManagedDeviceName($id, '.$deviceID.', $deviceName);'],
            ['type'=>'Select','name'=>'floor','caption'=>'Etage','options'=>$floors,'value'=>$a['floor']],
            ['type'=>'Select','name'=>'room','caption'=>'Raum','options'=>$rooms,'value'=>$a['room']],
            ['type'=>'Select','name'=>'trade','caption'=>'Gewerk','options'=>$trades,'value'=>$a['trade']],
            ['type'=>'List','name'=>'selectedVariables','caption'=>'Variablen verlinken','rowCount'=>10,'add'=>false,'delete'=>false,
                'onEdit'=>'SDM_SaveDialogVariableSelection($id, '.$deviceID.', json_encode($selectedVariables));',
                'columns'=>[
                    ['caption'=>'Verlinken','name'=>'enabled','width'=>'95px','edit'=>['type'=>'CheckBox']],
                    ['caption'=>'Linkname (änderbar)','name'=>'name','width'=>'45%','save'=>true,'edit'=>['type'=>'ValidationTextBox']],
                    ['caption'=>'Quellname','name'=>'sourceName','width'=>'35%'],
                    ['caption'=>'ID','name'=>'variableID','width'=>'90px','save'=>true]
                ],
                'values'=>$this->DeviceDialogVariables($deviceID)],
            ['type'=>'Label','caption'=>'Variablen ausschließlich oben in der Tabelle auswählen. Die Auswahl wird beim Bearbeiten übernommen.'],
            ['type'=>'Button','caption'=>'Gerät mit ausgewählten Variablen in Gebäude_neu anlegen','onClick'=>'SDM_CreateDeviceFromDialog($id, '.$deviceID.', json_encode($selectedVariables));']
        ];
    }

    public function SaveDialogVariableSelection(int $deviceID, string $rowsJSON)
    {
        $rows = json_decode($rowsJSON,true);
        if (!is_array($rows)) throw new Exception('Variablenauswahl konnte nicht gelesen werden.');
        if (isset($rows['variableID'])) $rows = [$rows];
        $devices = $this->DiscoverPilotDevices();
        if (!isset($devices[(string)$deviceID])) throw new Exception('Gerät nicht gefunden.');
        $valid = array_values($devices[(string)$deviceID]['functions']);
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $varID = (int)($row['variableID'] ?? 0);
            if (!in_array($varID,$valid,true)) continue;
            $this->SetDeviceVariable($deviceID,$varID,!empty($row['enabled']));
            if (isset($row['name']) && is_string($row['name'])) $this->SetDeviceLinkName($deviceID,$varID,$row['name']);
        }
    }

    public function SetDeviceLinkName(int $deviceID, int $variableID, string $name)
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name,'UTF-8') > 100) throw new Exception('Linkname muss 1 bis 100 Zeichen enthalten.');
        $devices = $this->DiscoverPilotDevices();
        if (!isset($devices[(string)$deviceID]) || !in_array($variableID,array_values($devices[(string)$deviceID]['functions']),true)) throw new Exception('Ungültige Quellvariable.');
        $all = json_decode($this->ReadAttributeString('DeviceAssignments'),true);
        if (!is_array($all)) $all = [];
        if (!isset($all[(string)$deviceID])) $all[(string)$deviceID] = $this->DeviceAssignment($deviceID,$devices[(string)$deviceID]);
        $all[(string)$deviceID]['linkNames'][(string)$variableID] = $name;
        $this->WriteAttributeString('DeviceAssignments',json_encode($all,JSON_UNESCAPED_UNICODE));
    }

    public function SetManagedDeviceName(int $deviceID, string $name)
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name,'UTF-8') > 100 || strpos($name,'/') !== false) throw new Exception('Ungültiger Gerätename.');
        $devices = $this->DiscoverPilotDevices();
        if (!isset($devices[(string)$deviceID])) throw new Exception('Quellgerät fehlt.');
        $all = json_decode($this->ReadAttributeString('DeviceAssignments'),true);
        if (!is_array($all)) $all = [];
        if (!isset($all[(string)$deviceID])) $all[(string)$deviceID] = $this->DeviceAssignment($deviceID,$devices[(string)$deviceID]);
        $all[(string)$deviceID]['deviceName'] = $name;
        $this->WriteAttributeString('DeviceAssignments',json_encode($all,JSON_UNESCAPED_UNICODE));
    }

    public function CreateDeviceFromDialog(int $deviceID, string $rowsJSON)
    {
        $rows = json_decode($rowsJSON,true);
        // Nested configuration List values are sometimes sent as [] by the button callback.
        // In that case use the displayed default selection plus any persisted edits.
        if (is_array($rows) && count($rows) > 0) $this->SaveDialogVariableSelection($deviceID,$rowsJSON);
        $this->CreateConfiguredDevice($deviceID);
    }

    public function ApplyManagedDeviceName(int $deviceID)
    {
        $devices = $this->DiscoverPilotDevices();
        if (!isset($devices[(string)$deviceID])) throw new Exception('Quellgerät fehlt.');
        $a = $this->DeviceAssignment($deviceID,$devices[(string)$deviceID]);
        $root = $this->ReadPropertyInteger('ManagedBuildingRoot');
        $floor = $this->FindCategoryByName($root,$a['floor']);
        $room = $floor ? $this->FindCategoryByName($floor,$a['room']) : 0;
        $trade = $room ? $this->FindCategoryByName($room,$a['trade']) : 0;
        $dummy = $this->ManagedDummyID($deviceID,$devices[(string)$deviceID],$a);
        if (!$dummy) throw new Exception('Dummy nicht gefunden.');
        $collision = $this->FindDeviceDummy($trade,$a['deviceName']);
        if ($collision && $collision !== $dummy) throw new Exception('Ein anderes Gerät trägt bereits diesen Namen.');
        IPS_SetName($dummy,$a['deviceName']);
        $this->StoreManagedDummyID($deviceID,$dummy);
        $this->ReloadForm();
    }

    public function CreateConfiguredDevice(int $deviceID)
    {
        $devices = $this->DiscoverPilotDevices();
        if (!isset($devices[(string)$deviceID])) throw new Exception('Quellgerät nicht gefunden.');
        $a = $this->DeviceAssignment($deviceID,$devices[(string)$deviceID]);
        $root = $this->ReadPropertyInteger('ManagedBuildingRoot');
        if (!$root || !IPS_ObjectExists($root) || IPS_GetObject($root)['ObjectType'] !== 0) throw new Exception('Zielwurzel ungültig.');
        foreach (['floor','room','trade'] as $field) {
            if (!is_string($a[$field]) || trim($a[$field]) === '' || $a[$field] === 'Unbekannt' || strpos($a[$field],'/') !== false) throw new Exception('Bitte Etage, Raum und Gewerk zuerst speichern.');
        }
        $chosen = [];
        foreach ($devices[(string)$deviceID]['functions'] as $function=>$variableID) {
            if (array_key_exists((string)$variableID,$a['variables']) && !$a['variables'][(string)$variableID]) continue;
            if (!IPS_ObjectExists($variableID) || IPS_GetObject($variableID)['ObjectType'] !== 2) throw new Exception('Quellvariable ungültig: '.$variableID);
            $chosen[$function] = $variableID;
        }
        if (!$chosen) throw new Exception('Bitte mindestens eine Variable ausdrücklich auswählen.');
        $floor = $this->FindOrCreateCategory($root,$a['floor']);
        $room = $this->FindOrCreateCategory($floor,$a['room']);
        $trade = $this->FindOrCreateCategory($room,$a['trade']);
        $dummy = $this->FindDeviceDummy($trade,$a['deviceName']) ?: $this->FindDeviceDummy($trade,$devices[(string)$deviceID]['name']);
        if (!$dummy) {
            $dummy = IPS_CreateInstance('{485D0419-BE97-4548-AA9C-C083EB82E61E}');
            IPS_SetParent($dummy,$trade);
            IPS_SetName($dummy,$a['deviceName']);
        }
        $this->StoreManagedDummyID($deviceID,$dummy);
        foreach ($chosen as $function=>$variableID) {
            $link = 0;
            foreach (IPS_GetChildrenIDs($dummy) as $child) {
                if (IPS_GetObject($child)['ObjectType'] !== 6) continue;
                if (IPS_GetLink($child)['TargetID'] === $variableID || IPS_GetName($child) === $function) { $link = $child; break; }
            }
            if (!$link) {
                $link = IPS_CreateLink();
                IPS_SetParent($link,$dummy);
                IPS_SetName($link,$this->EffectiveLinkName($a,(int)$variableID,(string)$function));
            }
            IPS_SetLinkTargetID($link,$variableID);
        }
        $this->ReloadForm();
    }

    private function EffectiveLinkName(array $assignment, int $variableID, string $function): string
    {
        $source = trim(IPS_GetName($variableID));
        $stored = trim((string)($assignment['linkNames'][(string)$variableID] ?? ''));
        if ($stored === '') return $this->SuggestedLinkName($function,$variableID);
        // Legacy values were persisted as "Wert [ID] – <source name>".
        // Only normalize when the suffix is exactly the current source name.
        if ($source !== '' && preg_match('/^Wert(?:\\s+\\d+)?\\s*[–—-]\\s*(.+)$/iu',$stored,$m)
            && trim($m[1]) === $source) return $source;
        return $stored;
    }

    private function SuggestedLinkName(string $function, int $variableID): string
    {
        $sourceName = trim(IPS_GetName($variableID));
        $ident = trim((string)(IPS_GetObject($variableID)['ObjectIdent'] ?? ''));
        if ($sourceName !== '' && !preg_match('/^(Wert|Value)(\\s+\\d+)?$/iu', $sourceName)) return $sourceName;
        if ($ident !== '' && !preg_match('/^(Value|Wert)$/iu', $ident)) return $ident;
        if ($function !== 'Wert' && !preg_match('/^Wert\\s+\\d+$/iu',$function)) return $function;
        $parent = IPS_GetParent($variableID);
        if ($parent && IPS_ObjectExists($parent)) {
            $parentName = trim(IPS_GetName($parent));
            if ($parentName !== '' && !preg_match('/^(Wert|Value)$/iu',$parentName)) return $parentName;
        }
        return $sourceName !== '' ? $sourceName : 'Variable '.$variableID;
    }

    private function DeviceDialogVariables(int $deviceID): array
    {
        $devices = $this->DiscoverPilotDevices();
        if (!isset($devices[(string)$deviceID])) return [];
        $a = $this->DeviceAssignment($deviceID, $devices[(string)$deviceID]);
        $rows = [];
        foreach ($devices[(string)$deviceID]['functions'] as $function=>$variableID) {
            if (!IPS_ObjectExists($variableID)) continue;
            $rows[] = ['enabled'=>array_key_exists((string)$variableID,$a['variables']) ? (bool)$a['variables'][(string)$variableID] : true,
                'name'=>$this->EffectiveLinkName($a,(int)$variableID,(string)$function),
                'sourceName'=>IPS_GetName($variableID),
                'variableID'=>(int)$variableID];
        }
        return $rows;
    }

    public function UpdateDeviceDialog(int $deviceID)
    {
        $devices = $this->DiscoverPilotDevices();
        if (!isset($devices[(string)$deviceID])) return;
        $a = $this->DeviceAssignment($deviceID, $devices[(string)$deviceID]);
        foreach (['floor','room','trade'] as $field) $this->UpdateFormField($field, 'value', $a[$field]);
        $this->UpdateFormField('selectedVariables','values',json_encode($this->DeviceDialogVariables($deviceID), JSON_UNESCAPED_UNICODE));
    }

    public function SetExistingDeviceSearch(string $search)
    {
        $this->WriteAttributeString('ExistingDeviceSearch',trim($search));
        $list = $this->BuildExistingDeviceList();
        $this->UpdateFormField('ExistingDevices','values',json_encode($list['values'],JSON_UNESCAPED_UNICODE));
    }

    private function ManagedDummyID(int $deviceID, array $device, array $assignment): int
    {
        $root = $this->ReadPropertyInteger('ManagedBuildingRoot');
        $floor = $this->FindCategoryByName($root,$assignment['floor']);
        $room = $floor ? $this->FindCategoryByName($floor,$assignment['room']) : 0;
        $trade = $room ? $this->FindCategoryByName($room,$assignment['trade']) : 0;
        if (!$trade) return 0;
        $savedID = (int)($assignment['dummyID'] ?? 0);
        if ($savedID && IPS_ObjectExists($savedID) && IPS_GetParent($savedID) === $trade && IPS_GetObject($savedID)['ObjectType'] === 1) return $savedID;
        foreach (array_unique([$assignment['deviceName'],$device['name']]) as $name) {
            $found = $this->FindDeviceDummy($trade,$name,false);
            if ($found) return $found;
        }
        // Recover renamed dummies using their actual linked source variables.
        $matches = [];
        foreach (IPS_GetChildrenIDs($trade) as $child) {
            if (IPS_GetObject($child)['ObjectType'] !== 1) continue;
            $score = 0;
            foreach (IPS_GetChildrenIDs($child) as $link) {
                if (IPS_GetObject($link)['ObjectType'] === 6 && in_array(IPS_GetLink($link)['TargetID'],array_values($device['functions']),true)) $score++;
            }
            if ($score > 0) $matches[$child] = $score;
        }
        if (!$matches) return 0;
        arsort($matches);
        $best = reset($matches);
        if (count(array_filter($matches,static function($v) use ($best) { return $v === $best; })) !== 1) return 0;
        return (int)array_key_first($matches);
    }

    private function StoreManagedDummyID(int $deviceID, int $dummyID): void
    {
        $all = json_decode($this->ReadAttributeString('DeviceAssignments'),true);
        if (!is_array($all)) $all = [];
        if (!isset($all[(string)$deviceID])) $all[(string)$deviceID] = [];
        $all[(string)$deviceID]['dummyID'] = $dummyID;
        $this->WriteAttributeString('DeviceAssignments',json_encode($all,JSON_UNESCAPED_UNICODE));
    }

    private function BuildExistingDeviceList(): array
    {
        $devices = $this->DiscoverPilotDevices();
        $rows = [];
        $root = $this->ReadPropertyInteger('ManagedBuildingRoot');
        $search = trim($this->ReadAttributeString('ExistingDeviceSearch'));
        $seenDummies = [];
        foreach ($devices as $id => $device) {
            $a = $this->DeviceAssignment($id, $device);
            $floor = $this->FindCategoryByName($root, $a['floor']);
            $room = $floor ? $this->FindCategoryByName($floor, $a['room']) : 0;
            $trade = $room ? $this->FindCategoryByName($room, $a['trade']) : 0;
            $dummy = $this->ManagedDummyID((int)$id,$device,$a);
            $nativeInstance = 0;
            foreach (IPS_GetInstanceListByModuleID('{7C91D1A2-29C9-4DF7-86BD-BB23E4795C71}') as $managedID) {
                $settings = json_decode(IPS_GetConfiguration($managedID),true);
                if ((int)($settings['SourceInstanceID'] ?? 0) === (int)$id) { $nativeInstance = $managedID; break; }
            }
            if (!$dummy) continue;
            if (isset($seenDummies[$dummy])) continue;
            // A dummy is one managed device, even if multiple source instances share its name.
            // Prefer the source whose variables are actually targeted by the dummy links.
            $linkedTargets = [];
            foreach (IPS_GetChildrenIDs($dummy) as $child) {
                if (IPS_GetObject($child)['ObjectType'] === 6) $linkedTargets[] = IPS_GetLink($child)['TargetID'];
            }
            $matches = count(array_intersect($linkedTargets, array_values($device['functions'])));
            $bestMatches = $matches;
            foreach ($devices as $otherID => $otherDevice) {
                if ((string)$otherID === (string)$id || $otherDevice['name'] !== $device['name']) continue;
                $otherAssignment = $this->DeviceAssignment($otherID,$otherDevice);
                if ($otherAssignment['floor'] !== $a['floor'] || $otherAssignment['room'] !== $a['room'] || $otherAssignment['trade'] !== $a['trade']) continue;
                $otherMatches = count(array_intersect($linkedTargets,array_values($otherDevice['functions'])));
                if ($otherMatches > $bestMatches) $bestMatches = $otherMatches;
            }
            if ($matches < $bestMatches) continue;
            $seenDummies[$dummy] = true;
            $haystack = $device['name'].' '.$device['path'].' '.$id.' '.$a['floor'].' '.$a['room'].' '.$a['trade'].' '.$dummy;
            if ($search !== '' && mb_stripos($haystack,$search,0,'UTF-8') === false) continue;
            $links = 0;
            foreach (IPS_GetChildrenIDs($dummy) as $child) {
                if (IPS_GetObject($child)['ObjectType'] === 6) $links++;
            }
            $aliasTrade = ['Beleuchtung'=>'Bel.', 'Heizung'=>'Hzg.', 'Rollladen'=>'Rol.', 'Multimedia'=>'Med.', 'Steckdosen'=>'Steck.', 'Sensoren'=>'Sens.', 'Sonstige'=>'Sonst.'];
            $aliasRoom = ['Wohnzimmer'=>'Wohn.', 'Schlafzimmer'=>'Schlaf.', 'Wintergarten'=>'Winterg.', 'Küche'=>'Kü.', 'Badezimmer'=>'Bad.', 'Kinderzimmer'=>'Kind.'];
            $alias = ($aliasTrade[$a['trade']] ?? $a['trade']).'/'.$a['floor'].'/'.($aliasRoom[$a['room']] ?? $a['room']).'/';
            $rows[] = ['deviceID'=>(int)$id, 'alias'=>$alias, 'name'=>IPS_GetName($dummy), 'floor'=>$a['floor'], 'room'=>$a['room'], 'trade'=>$a['trade'], 'dummyID'=>$dummy, 'links'=>$links];
        }
        return ['type'=>'List','name'=>'ExistingDevices','caption'=>'Bereits angelegte Geräte – Variablenbestand','rowCount'=>10,'add'=>false,'delete'=>false,'width'=>'100%',
            'form'=>'return SDM_GetExistingDeviceDialogForm($id, (int)($ExistingDevices["deviceID"] ?? 0));',
            'columns'=>[
                ['caption'=>'Alias','name'=>'alias','width'=>'16%'],
                ['caption'=>'Gerät','name'=>'name','width'=>'28%'],
                ['caption'=>'Etage','name'=>'floor','width'=>'9%'],
                ['caption'=>'Raum','name'=>'room','width'=>'17%'],
                ['caption'=>'Gewerk','name'=>'trade','width'=>'14%'],
                ['caption'=>'Dummy-ID','name'=>'dummyID','width'=>'95px'],
                ['caption'=>'Links','name'=>'links','width'=>'65px']
            ],'values'=>$rows];
    }

    public function GetUnifiedDeviceDialogForm(int $deviceID): array
    {
        $devices = $this->DiscoverPilotDevices();
        if (!isset($devices[(string)$deviceID])) return [['type'=>'Label','caption'=>'Quellgerät nicht gefunden']];
        $assignment = $this->DeviceAssignment($deviceID,$devices[(string)$deviceID]);
        if ($this->ManagedDummyID($deviceID,$devices[(string)$deviceID],$assignment)) {
            return $this->GetExistingDeviceDialogForm($deviceID);
        }
        return $this->GetDeviceDialogForm($deviceID);
    }

    public function GetExistingDeviceDialogForm(int $deviceID): array
    {
        $devices = $this->DiscoverPilotDevices();
        if (!isset($devices[(string)$deviceID])) return [['type'=>'Label','caption'=>'Gerät nicht gefunden']];
        $device = $devices[(string)$deviceID];
        $a = $this->DeviceAssignment($deviceID,$device);
        $root = $this->ReadPropertyInteger('ManagedBuildingRoot');
        $floor = $this->FindCategoryByName($root,$a['floor']);
        $room = $floor ? $this->FindCategoryByName($floor,$a['room']) : 0;
        $trade = $room ? $this->FindCategoryByName($room,$a['trade']) : 0;
        $dummy = $this->ManagedDummyID($deviceID,$device,$a);
        if (!$dummy) return [['type'=>'Label','caption'=>'Dummy nicht mehr vorhanden']];
        $linked = [];
        foreach (IPS_GetChildrenIDs($dummy) as $child) {
            if (IPS_GetObject($child)['ObjectType'] === 6) $linked[IPS_GetLink($child)['TargetID']] = $child;
        }
        $rows = [];
        foreach ($device['functions'] as $function=>$variableID) {
            $rows[] = ['enabled'=>isset($linked[$variableID]),'name'=>$function.' – '.IPS_GetName($variableID),'variableID'=>(int)$variableID,
                'source'=>$variableID.' · '.IPS_GetName($variableID),
                'target'=>isset($linked[$variableID]) ? (string)$linked[$variableID].' · '.IPS_GetName($linked[$variableID]) : 'Nicht verlinkt'];
        }
        $drafts = json_decode($this->ReadAttributeString('ManagedEditDraft'),true);
        $draft = is_array($drafts) ? ($drafts[(string)$deviceID] ?? []) : [];
        $form = array_slice($this->GetDeviceDialogForm($deviceID),0,6);
        $form[0] = ['type'=>'Label','caption'=>'Gerät: '.$device['name'].' ('.$deviceID.') | Dummy: '.$dummy];
        $form[1]['value'] = $a['deviceName'];
        $form[1]['onChange'] = 'SDM_SetManagedDeviceName($id, '.$deviceID.', $deviceName);';
        foreach (['floor'=>2,'room'=>3,'trade'=>4] as $field=>$index) {
            $form[$index]['value'] = $draft[$field] ?? $a[$field];
            $form[$index]['onChange'] = 'SDM_SetManagedEditField($id, '.$deviceID.', "'.$field.'", $'.$field.');';
        }
        foreach ($rows as &$row) {
            $varID = (string)$row['variableID'];
            $row['name'] = isset($linked[(int)$varID]) ? IPS_GetName($linked[(int)$varID]) : ($a['linkNames'][$varID] ?? IPS_GetName((int)$varID));
        }
        unset($row);
        foreach ($rows as &$row) {
            $row['sourceVariableID'] = (int)$row['variableID'];
            $row['targetLinkID'] = isset($linked[(int)$row['variableID']]) ? (int)$linked[(int)$row['variableID']] : 0;
        }
        unset($row);
        $form[5]['values'] = $rows;
        $form[5]['onEdit'] = 'SDM_SaveExistingLinkNames($id, '.$deviceID.', json_encode($selectedVariables));';
        $form[5]['columns'] = [
            ['caption'=>'Verlinken','name'=>'enabled','width'=>'75px','edit'=>['type'=>'CheckBox']],
            ['caption'=>'Linkname','name'=>'name','width'=>'30%','edit'=>['type'=>'ValidationTextBox'],'save'=>true],
            ['caption'=>'Quellvariable','name'=>'sourceVariableID','width'=>'22%','edit'=>['type'=>'SelectVariable'],'save'=>true],
            ['caption'=>'Ziel-Link (ID)','name'=>'targetLinkID','width'=>'18%','edit'=>['type'=>'SelectObject'],'save'=>true]
        ];
        array_splice($form,1,0,[
            ['type'=>'Label','caption'=>'Quelle: '.$device['path'].' / '.$device['name']],
            ['type'=>'Label','caption'=>'Ziel: '.implode(' / ',array_filter([$a['floor'],$a['room'],$a['trade'],$device['name']]))],
            ['type'=>'Label','caption'=>'Gerätenamen und Linknamen nach der Bearbeitung über die Buttons übernehmen. Dummy- und Link-IDs bleiben erhalten.'],
            ['type'=>'Button','caption'=>'Gerätenamen am bestehenden Dummy übernehmen','onClick'=>'SDM_ApplyManagedDeviceName($id, '.$deviceID.');'],
            ['type'=>'Button','caption'=>'Variablen und Linknamen übernehmen','onClick'=>'SDM_SaveExistingLinkNames($id, '.$deviceID.', json_encode($selectedVariables));'],
            ['type'=>'Button','caption'=>'Gerät in gewählten Raum / Gewerk verschieben','onClick'=>'SDM_MoveManagedDevice($id, '.$deviceID.');']
        ]);
        $swapDrafts = json_decode($this->ReadAttributeString('HardwareSwapDraft'),true);
        $newSourceID = (int)(is_array($swapDrafts) ? ($swapDrafts[(string)$deviceID] ?? 0) : 0);
        $form[] = ['type'=>'SelectInstance','name'=>'ReplacementSource','caption'=>'Neue Quellinstanz (Hardwaretausch)','value'=>$newSourceID,
            'onChange'=>'SDM_SetHardwareSwapSource($id, '.$deviceID.', $ReplacementSource);'];
        $form[] = ['type'=>'Label','caption'=>'Hardwaretausch nur bei eindeutiger Übereinstimmung aller verlinkten Funktionsnamen. Sonst wird nichts geändert.'];
        $form[] = ['type'=>'Button','caption'=>'Hardwaretausch ausführen (bestehende Links umstellen)','onClick'=>'SDM_ApplyHardwareSwap($id, '.$deviceID.');'];
        return $form;
    }

    public function SaveExistingLinkNames(int $deviceID, string $rowsJSON)
    {
        $rows = json_decode($rowsJSON,true);
        if (!is_array($rows)) throw new Exception('Ungültige Variablenliste.');
        // IP-Symcon sends one edited row, not necessarily the complete List.
        if (isset($rows['variableID'])) $rows = [$rows];
        $devices = $this->DiscoverPilotDevices();
        if (!isset($devices[(string)$deviceID])) throw new Exception('Gerät fehlt.');
        $a = $this->DeviceAssignment($deviceID,$devices[(string)$deviceID]);
        $root = $this->ReadPropertyInteger('ManagedBuildingRoot');
        $floor = $this->FindCategoryByName($root,$a['floor']);
        $room = $floor ? $this->FindCategoryByName($floor,$a['room']) : 0;
        $trade = $room ? $this->FindCategoryByName($room,$a['trade']) : 0;
        $dummy = $this->ManagedDummyID($deviceID,$devices[(string)$deviceID],$a);
        if (!$dummy) throw new Exception('Dummy fehlt.');
        $targets = [];
        foreach (IPS_GetChildrenIDs($dummy) as $child) {
            if (IPS_GetObject($child)['ObjectType'] === 6) $targets[IPS_GetLink($child)['TargetID']] = $child;
        }
        $changes = [];
        foreach ($rows as $row) {
            $originalID = (int)($row['variableID'] ?? 0);
            if (!in_array($originalID,array_values($devices[(string)$deviceID]['functions']),true)) continue;
            $linkID = (int)($row['targetLinkID'] ?? 0);
            $newSourceID = (int)($row['sourceVariableID'] ?? $originalID);
            if ($linkID === 0) {
                if ($newSourceID !== $originalID) throw new Exception('Für eine neue Verknüpfung muss zuerst ein Ziel-Link angelegt werden.');
                continue;
            }
            if (!IPS_ObjectExists($linkID) || IPS_GetObject($linkID)['ObjectType'] !== 6 || IPS_GetParent($linkID) !== $dummy) {
                throw new Exception('Ziel-Link '.$linkID.' gehört nicht zu diesem Dummy.');
            }
            if (!IPS_ObjectExists($newSourceID) || IPS_GetObject($newSourceID)['ObjectType'] !== 2) {
                throw new Exception('Ungültige Quellvariable '.$newSourceID);
            }
            if (isset($changes[$linkID])) throw new Exception('Ziel-Link mehrfach zugeordnet: '.$linkID);
            $changes[$linkID] = $newSourceID;
        }
        foreach ($changes as $linkID=>$newSourceID) {
            if (IPS_GetLink($linkID)['TargetID'] !== $newSourceID) IPS_SetLinkTargetID($linkID,$newSourceID);
        }
        foreach ($rows as $row) {
            $varID = (int)($row['variableID'] ?? 0);
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '' || !in_array($varID,array_values($devices[(string)$deviceID]['functions']),true)) continue;
            $this->SetDeviceLinkName($deviceID,$varID,$name);
            $linkID = (int)($row['targetLinkID'] ?? ($targets[$varID] ?? 0));
            if ($linkID && IPS_ObjectExists($linkID) && IPS_GetObject($linkID)['ObjectType'] === 6 && IPS_GetParent($linkID) === $dummy && IPS_GetName($linkID) !== $name) IPS_SetName($linkID,$name);
        }
    }

    public function SetHardwareSwapSource(int $deviceID, int $newSourceID)
    {
        $devices = $this->DiscoverPilotDevices();
        if (!isset($devices[(string)$deviceID])) throw new Exception('Bisheriges Gerät fehlt');
        if ($newSourceID && !isset($devices[(string)$newSourceID])) throw new Exception('Neue Quelle fehlt');
        $drafts = json_decode($this->ReadAttributeString('HardwareSwapDraft'),true);
        if (!is_array($drafts)) $drafts = [];
        $drafts[(string)$deviceID] = $newSourceID;
        $this->WriteAttributeString('HardwareSwapDraft',json_encode($drafts));
    }

    public function ApplyHardwareSwap(int $deviceID)
    {
        $devices = $this->DiscoverPilotDevices();
        $drafts = json_decode($this->ReadAttributeString('HardwareSwapDraft'),true);
        $newID = (int)(is_array($drafts) ? ($drafts[(string)$deviceID] ?? 0) : 0);
        if (!$newID || $newID === $deviceID || !isset($devices[(string)$newID]) || !isset($devices[(string)$deviceID])) {
            throw new Exception('Bitte eine andere gültige Quellinstanz auswählen.');
        }
        $old = $devices[(string)$deviceID];
        $a = $this->DeviceAssignment($deviceID,$old);
        $root = $this->ReadPropertyInteger('ManagedBuildingRoot');
        $floor = $this->FindCategoryByName($root,$a['floor']);
        $room = $floor ? $this->FindCategoryByName($floor,$a['room']) : 0;
        $trade = $room ? $this->FindCategoryByName($room,$a['trade']) : 0;
        $dummy = $trade ? $this->FindDeviceDummy($trade,$old['name']) : 0;
        if (!$dummy) throw new Exception('Bestehender Dummy fehlt.');
        $oldFunctions = array_flip($old['functions']);
        $newFunctions = $devices[(string)$newID]['functions'];
        $operations = [];
        foreach (IPS_GetChildrenIDs($dummy) as $child) {
            if (IPS_GetObject($child)['ObjectType'] !== 6) continue;
            $target = IPS_GetLink($child)['TargetID'];
            if (!isset($oldFunctions[$target])) throw new Exception('Unbekannte bestehende Verknüpfung '.$child.' – Abbruch.');
            $function = $oldFunctions[$target];
            if (!isset($newFunctions[$function])) throw new Exception('Neue Quelle hat keine eindeutige Funktion: '.$function);
            $newTarget = (int)$newFunctions[$function];
            if (!IPS_ObjectExists($newTarget) || IPS_GetObject($newTarget)['ObjectType'] !== 2) throw new Exception('Zielvariable ungültig: '.$newTarget);
            $operations[$child] = $newTarget;
        }
        if (!$operations) throw new Exception('Keine Links zum Umstellen vorhanden.');
        foreach ($operations as $link=>$target) IPS_SetLinkTargetID($link,$target);
        unset($drafts[(string)$deviceID]);
        $this->WriteAttributeString('HardwareSwapDraft',json_encode($drafts));
        $this->ReloadForm();
    }

    public function SetManagedEditField(int $deviceID, string $field, string $value)
    {
        if (!in_array($field,['floor','room','trade'],true)) throw new Exception('Ungültiges Feld');
        $drafts = json_decode($this->ReadAttributeString('ManagedEditDraft'),true);
        if (!is_array($drafts)) $drafts = [];
        $drafts[(string)$deviceID][$field] = trim($value);
        $this->WriteAttributeString('ManagedEditDraft',json_encode($drafts,JSON_UNESCAPED_UNICODE));
    }

    public function MoveManagedDevice(int $deviceID)
    {
        $devices = $this->DiscoverPilotDevices();
        if (!isset($devices[(string)$deviceID])) throw new Exception('Gerät nicht gefunden');
        $device = $devices[(string)$deviceID];
        $a = $this->DeviceAssignment($deviceID,$device);
        $root = $this->ReadPropertyInteger('ManagedBuildingRoot');
        if (!$root || !IPS_ObjectExists($root)) throw new Exception('Zielwurzel fehlt');
        $oldFloor = $this->FindCategoryByName($root,$a['floor']);
        $oldRoom = $oldFloor ? $this->FindCategoryByName($oldFloor,$a['room']) : 0;
        $oldTrade = $oldRoom ? $this->FindCategoryByName($oldRoom,$a['trade']) : 0;
        $dummy = $oldTrade ? $this->FindDeviceDummy($oldTrade,$device['name']) : 0;
        if (!$dummy) throw new Exception('Bestehender Dummy nicht gefunden');
        $drafts = json_decode($this->ReadAttributeString('ManagedEditDraft'),true);
        $changes = is_array($drafts) ? ($drafts[(string)$deviceID] ?? []) : [];
        foreach (['floor','room','trade'] as $field) {
            if (isset($changes[$field])) $a[$field] = trim($changes[$field]);
            if ($a[$field] === '' || $a[$field] === 'Unbekannt' || strpos($a[$field],'/') !== false) throw new Exception('Ungültige Zuordnung: '.$field);
        }
        $floor = $this->FindCategoryByName($root,$a['floor']);
        $room = $floor ? $this->FindCategoryByName($floor,$a['room']) : 0;
        $trade = $room ? $this->FindCategoryByName($room,$a['trade']) : 0;
        if (!$trade) throw new Exception('Zielraum oder Gewerk existiert noch nicht. Bitte Zielstruktur zuerst anlegen.');
        $collision = $this->FindDeviceDummy($trade,$device['name']);
        if ($collision && $collision !== $dummy) throw new Exception('Im Ziel existiert bereits ein gleichnamiger Dummy.');
        IPS_SetParent($dummy,$trade);
        $this->StoreManagedDummyID($deviceID,$dummy);
        $all = json_decode($this->ReadAttributeString('DeviceAssignments'),true);
        if (!is_array($all)) $all = [];
        $all[(string)$deviceID] = $a;
        $this->WriteAttributeString('DeviceAssignments',json_encode($all,JSON_UNESCAPED_UNICODE));
        unset($drafts[(string)$deviceID]);
        $this->WriteAttributeString('ManagedEditDraft',json_encode($drafts,JSON_UNESCAPED_UNICODE));
        $this->ReloadForm();
    }

    private function BuildLinkManagement(): array
    {
        $devices = $this->DiscoverPilotDevices();
        $options = [['caption'=>'Gerät wählen','value'=>0]];
        foreach ($devices as $id=>$device) {
            $a = $this->DeviceAssignment($id,$device);
            $root = $this->ReadPropertyInteger('ManagedBuildingRoot');
            $floor = $this->FindCategoryByName($root,$a['floor']);
            $room = $floor ? $this->FindCategoryByName($floor,$a['room']) : 0;
            $trade = $room ? $this->FindCategoryByName($room,$a['trade']) : 0;
            if ($trade && $this->FindDeviceDummy($trade,$device['name'])) $options[] = ['caption'=>$device['name'].' ('.$id.')','value'=>(int)$id];
        }
        $id = $this->ReadAttributeInteger('ManageDeviceID');
        $items = [['type'=>'Select','name'=>'ManageDeviceSelect','caption'=>'Angelegtes Gerät','value'=>$id,'options'=>$options,
            'onChange'=>'SDM_SelectLinkDevice($id, $ManageDeviceSelect);']];
        if ($id && isset($devices[(string)$id])) {
            $a = $this->DeviceAssignment($id,$devices[(string)$id]);
            $floor = $this->FindCategoryByName($this->ReadPropertyInteger('ManagedBuildingRoot'),$a['floor']);
            $room = $floor ? $this->FindCategoryByName($floor,$a['room']) : 0;
            $trade = $room ? $this->FindCategoryByName($room,$a['trade']) : 0;
            $dummy = $trade ? $this->FindDeviceDummy($trade,$devices[(string)$id]['name']) : 0;
            if ($dummy) {
                $linked = [];
                foreach (IPS_GetChildrenIDs($dummy) as $child) {
                    if (IPS_GetObject($child)['ObjectType'] === 6) $linked[IPS_GetLink($child)['TargetID']] = true;
                }
                foreach ($devices[(string)$id]['functions'] as $function=>$variableID) {
                    $enabled = array_key_exists((string)$variableID,$a['variables']) ? (bool)$a['variables'][(string)$variableID] : isset($linked[$variableID]);
                    $items[] = ['type'=>'CheckBox','name'=>'ManageVar_'.$variableID,
                        'caption'=>(isset($linked[$variableID]) ? 'Verlinkt: ' : 'Nicht verlinkt: ').$function.' ('.$variableID.')',
                        'value'=>$enabled,'onChange'=>'SDM_SetDeviceVariable($id, '.$id.', '.$variableID.', $ManageVar_'.$variableID.');'];
                }
                $items[] = ['type'=>'Button','caption'=>'Variablenlinks abgleichen','onClick'=>'SDM_SyncManagedDeviceLinks($id, '.$id.');'];
            }
        }
        return ['type'=>'ExpansionPanel','caption'=>'Variablen bestehender Geräte hinzufügen / entfernen','items'=>$items];
    }

    public function SyncManagedDeviceLinks(int $deviceID)
    {
        $devices = $this->DiscoverPilotDevices();
        if (!isset($devices[(string)$deviceID])) throw new Exception('Quellgerät fehlt.');
        $device = $devices[(string)$deviceID];
        $a = $this->DeviceAssignment($deviceID,$device);
        $root = $this->ReadPropertyInteger('ManagedBuildingRoot');
        $floor = $this->FindCategoryByName($root,$a['floor']);
        $room = $floor ? $this->FindCategoryByName($floor,$a['room']) : 0;
        $trade = $room ? $this->FindCategoryByName($room,$a['trade']) : 0;
        $dummy = $trade ? $this->FindDeviceDummy($trade,$device['name']) : 0;
        if (!$dummy) throw new Exception('Dummy in der Zielstruktur nicht gefunden. Es wird kein Gerät automatisch angelegt.');
        $allowed = array_values($device['functions']);
        $links = [];
        foreach (IPS_GetChildrenIDs($dummy) as $child) {
            if (IPS_GetObject($child)['ObjectType'] !== 6) continue;
            $target = IPS_GetLink($child)['TargetID'];
            if (in_array($target,$allowed,true)) $links[$target] = $child;
        }
        foreach ($device['functions'] as $function=>$variableID) {
            $enabled = array_key_exists((string)$variableID,$a['variables']) ? (bool)$a['variables'][(string)$variableID] : isset($links[$variableID]);
            if ($enabled && !isset($links[$variableID])) {
                if (!IPS_ObjectExists($variableID) || IPS_GetObject($variableID)['ObjectType'] !== 2) continue;
                $link = IPS_CreateLink();
                IPS_SetParent($link,$dummy);
                IPS_SetName($link,$function);
                IPS_SetLinkTargetID($link,$variableID);
            } elseif (!$enabled && isset($links[$variableID])) {
                IPS_DeleteLink($links[$variableID]);
            }
        }
        $this->ReloadForm();
    }

    public function SelectLinkDevice(int $deviceID)
    {
        $this->WriteAttributeInteger('ManageDeviceID',$deviceID);
        $this->ReloadForm();
    }

    // Experimental single-table editor. Does not replace the production Configurator.
    private function BuildNativeDeviceConfigurator(): array
    {
        $devices = $this->DiscoverPilotDevices();
        $rows = [];
        $search = trim($this->ReadAttributeString('DeviceListSearch'));
        $managedBySource = [];
        foreach (IPS_GetInstanceListByModuleID('{7C91D1A2-29C9-4DF7-86BD-BB23E4795C71}') as $managedID) {
            $config = json_decode(IPS_GetConfiguration($managedID), true);
            $sourceID = (int)($config['SourceInstanceID'] ?? 0);
            if ($sourceID > 0 && !isset($managedBySource[$sourceID])) $managedBySource[$sourceID] = ['id'=>(int)$managedID, 'config'=>$config];
        }
        foreach ($devices as $id => $device) {
            $a = $this->DeviceAssignment((int)$id,$device);
            $managed = $managedBySource[(int)$id] ?? null;
            $nativeInstance = (int)($managed['id'] ?? 0);
            $settings = $managed['config'] ?? [];
            $floor = $nativeInstance ? (string)($settings['Floor'] ?? $a['floor']) : (string)$a['floor'];
            $room = $nativeInstance ? (string)($settings['Room'] ?? $a['room']) : (string)$a['room'];
            $trade = $nativeInstance ? (string)($settings['Trade'] ?? $a['trade']) : (string)$a['trade'];
            $deviceName = $nativeInstance ? IPS_GetName($nativeInstance) : (string)($a['deviceName'] ?? $device['name']);
            $haystack = $deviceName.' '.$device['name'].' '.$device['path'].' '.$id.' '.$floor.' '.$room.' '.$trade;
            if ($search !== '' && mb_stripos($haystack,$search,0,'UTF-8') === false) continue;
            $rows[] = [
                'id'=>(int)$id,
                'deviceID'=>(int)$id,
                'type'=>'Gerät',
                'floor'=>$floor,
                'floorName'=>$floor,
                'room'=>$room,
                'name'=>$deviceName,
                'source'=>$device['path'],
                'trade'=>$trade,
                'tradeName'=>$trade,
                'variables'=>count($device['functions']),
                'instanceID'=>$nativeInstance,
                'create'=>[
                    'moduleID'=>'{7C91D1A2-29C9-4DF7-86BD-BB23E4795C71}',
                    'configuration'=>[
                        'SourceInstanceID'=>(int)$id,
                        'DeviceName'=>(string)($a['deviceName'] ?? $device['name']),
                        'Floor'=>(string)$a['floor'],
                        'Room'=>(string)$a['room'],
                        'Trade'=>(string)$a['trade'],
                        'ManagedBuildingRoot'=>$this->ReadPropertyInteger('ManagedBuildingRoot'),
                        'LinkNameRules'=>$this->ReadPropertyString('LinkNameRules'),
                        'Links'=>json_encode(array_map(function($variableID) {
                            $sourceName = IPS_GetName((int)$variableID);
                            $name = ($sourceName === 'Wert' && IPS_GetParent((int)$variableID) > 0) ? IPS_GetName(IPS_GetParent((int)$variableID)) : $sourceName;
                            $rules = json_decode($this->ReadPropertyString('LinkNameRules'),true);
                            foreach (is_array($rules) ? $rules : [] as $rule) {
                                if (trim((string)($rule['from'] ?? '')) !== '' && strcasecmp($name,(string)$rule['from']) === 0 && trim((string)($rule['to'] ?? '')) !== '') {
                                    $name = (string)$rule['to'];
                                    break;
                                }
                            }
                            return ['sourceID'=>(int)$variableID,'enabled'=>true,'linkName'=>$name];
                        },array_values($device['functions'])),JSON_UNESCAPED_UNICODE)
                    ],
                    'location'=>array_values(array_filter([$a['floor'],$a['room'],$a['trade']]))
                ]
            ];
        }
        return [
            'type'=>'Configurator','name'=>'DeviceNativeConfigurator','caption'=>'Geräteübersicht',
            'rowCount'=>18,
            'add'=>false,
            'delete'=>false,
            'columns'=>[
                ['caption'=>'Typ','name'=>'type','width'=>'70px'],
                ['caption'=>'Etage','name'=>'floorName','width'=>'100px'],
                ['caption'=>'Raum','name'=>'room','width'=>'140px'],
                ['caption'=>'Gerät','name'=>'name','width'=>'20%'],
                ['caption'=>'Quelle','name'=>'source','width'=>'35%'],
                ['caption'=>'Gewerk','name'=>'tradeName','width'=>'120px'],
                ['caption'=>'Variablen','name'=>'variables','width'=>'90px'],

            ],
            'values'=>$rows
        ];
    }

    private function BuildDeviceDraftList()
    {
        $devices = $this->DiscoverPilotDevices();
        $plan = $this->ReadBuildingPlan($this->ReadPropertyInteger('ManagedBuildingRoot'));
        $linked = $this->ExistingDeviceVariableIDs();
        $saved = [];
        $hidden = 0;
        $seen = json_decode($this->ReadAttributeString('SeenSourceDevices'),true);
        if (!is_array($seen)) $seen = [];
        $showSeen = $this->ReadAttributeBoolean('ShowSeenSourceDevices');
        $search = trim($this->ReadAttributeString('DeviceListSearch'));
        $deviceOptions = [['caption' => 'Gerät auswählen', 'value' => 0]];
        $floorOptions = [['caption' => 'Etage wählen', 'value' => '']];
        $roomOptions = [['caption' => 'Raum wählen', 'value' => '']];
        foreach ($plan as $floor => $rooms) {
            $floorOptions[] = ['caption' => $floor, 'value' => $floor];
            foreach ($rooms as $room) $roomOptions[$room] = ['caption' => $room, 'value' => $room];
        }
        $roomOptions = array_values($roomOptions);
        foreach ($devices as $id => $device) {
            $a = $this->DeviceAssignment($id, $device);
            $managedDummy = $this->ManagedDummyID((int)$id,$device,$a);
            if (in_array((int)$id,$seen,true) && !$showSeen) { $hidden++; continue; }
            $allLinked = count($device['functions']) > 0;
            foreach ($device['functions'] as $variableID) {
                if (!isset($linked[$variableID])) { $allLinked = false; break; }
            }
            $haystack = $device['name'] . ' ' . $device['path'] . ' ' . $id . ' ' . $a['floor'] . ' ' . $a['room'] . ' ' . $a['trade'];
            if ($search !== '' && mb_stripos($haystack, $search, 0, 'UTF-8') === false) continue;
            // Recount from the live source instance to avoid stale zero counts in the editable list.
            $variableCount = count($device['functions']);
            if (IPS_ObjectExists((int)$id) && IPS_GetObject((int)$id)['ObjectType'] === 1) {
                $directCount = 0;
                foreach (IPS_GetChildrenIDs((int)$id) as $childID) {
                    if (IPS_GetObject($childID)['ObjectType'] === 2) $directCount++;
                }
                $variableCount = max($variableCount,$directCount);
            }
            $saved[] = ['deviceID' => (int) $id, 'floor' => $a['floor'], 'room' => $a['room'],
                'trade' => $a['trade'], 'variableCount' => $variableCount,
                'instanceID' => $managedDummy ?: 0,
                'rowColor' => ($managedDummy || $allLinked) ? '#FFFFFF' : '#D9F4DD'];
            $deviceOptions[] = ['caption' => $device['name'] . ' | ' . $device['path'], 'value' => (int) $id];
        }
        $tradeOptions = [];
        foreach (['Unbekannt', 'Beleuchtung', 'Heizung', 'Rollladen', 'Multimedia', 'Steckdosen', 'Sensoren', 'Sonstige'] as $trade) {
            $tradeOptions[] = ['caption' => $trade, 'value' => $trade];
        }
        return [
            'type' => 'List', 'name' => 'DeviceDiscoveryRows', 'caption' => 'Gefundene Geräte – ' . count($saved) . ' sichtbar, ' . $hidden . ' als gesehen ausgeblendet',
            'rowCount' => 18, 'add' => true, 'delete' => false, 'width' => '100%',
            'onEdit' => 'SDM_SaveDeviceDraft($id, json_encode($DeviceDiscoveryRows));',
            'form' => 'return SDM_GetUnifiedDeviceDialogForm($id, (int)($DeviceDiscoveryRows["deviceID"] ?? 0));',
            'columns' => [
                ['caption' => 'Quellgerät', 'name' => 'deviceID', 'width' => '40%', 'add' => 0, 'edit' => ['type' => 'Select', 'options' => $deviceOptions]],
                ['caption' => 'Etage', 'name' => 'floor', 'width' => '12%', 'add' => '', 'edit' => ['type' => 'Select', 'options' => $floorOptions]],
                ['caption' => 'Raum', 'name' => 'room', 'width' => '18%', 'add' => '', 'edit' => ['type' => 'Select', 'options' => $roomOptions]],
                ['caption' => 'Gewerk', 'name' => 'trade', 'width' => '130px', 'add' => 'Unbekannt', 'edit' => ['type' => 'Select', 'options' => $tradeOptions]],
                ['caption' => 'Variablen', 'name' => 'variableCount', 'width' => '90px', 'add' => 0, 'edit' => ['type' => 'Label']],
                ['caption' => 'InstanzID', 'name' => 'instanceID', 'width' => '100px', 'add' => 0, 'edit' => ['type' => 'Label']]
            ],
            'values' => $saved
        ];
    }

    public function MarkAllSourceDevicesSeen()
    {
        $devices = $this->DiscoverPilotDevices();
        $seen = json_decode($this->ReadAttributeString('SeenSourceDevices'),true);
        if (!is_array($seen)) $seen = [];
        $search = trim($this->ReadAttributeString('DeviceListSearch'));
        foreach ($devices as $id=>$device) {
            $a = $this->DeviceAssignment($id,$device);
            $haystack = $device['name'].' '.$device['path'].' '.$id.' '.$a['floor'].' '.$a['room'].' '.$a['trade'];
            if ($search !== '' && mb_stripos($haystack,$search,0,'UTF-8') === false) continue;
            $seen[] = (int)$id;
        }
        $this->WriteAttributeString('SeenSourceDevices',json_encode(array_values(array_unique($seen))));
        $this->WriteAttributeBoolean('ShowSeenSourceDevices',false);
        $this->ReloadForm();
    }

    public function ShowSeenSourceDevices()
    {
        $this->WriteAttributeBoolean('ShowSeenSourceDevices',!$this->ReadAttributeBoolean('ShowSeenSourceDevices'));
        $this->ReloadForm();
    }

    public function AddDeviceDraft()
    {
        $rows = json_decode($this->ReadAttributeString('DeviceDraftRows'), true);
        if (!is_array($rows)) $rows = [];
        $rows[] = ['deviceID' => 0, 'floor' => '', 'room' => '', 'trade' => 'Unbekannt', 'variableCount' => 0];
        $this->WriteAttributeString('DeviceDraftRows', json_encode($rows, JSON_UNESCAPED_UNICODE));
        $this->UpdateFormField('DeviceDraftRows', 'values', json_encode($rows, JSON_UNESCAPED_UNICODE));
    }

    private function ApplySavedDeviceDraftRows()
    {
        $raw = $this->ReadPropertyString('DeviceDraftRows');
        $rows = json_decode($raw, true);
        if (!is_array($rows) || !$rows) return;
        $devices = $this->DiscoverPilotDevices();
        $assignments = json_decode($this->ReadAttributeString('DeviceAssignments'), true);
        if (!is_array($assignments)) $assignments = [];
        $changes = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $id = (int) ($row['deviceID'] ?? 0);
            if (!$id || !isset($devices[(string) $id])) continue;
            $old = $this->DeviceAssignment($id, $devices[(string) $id]);
            $new = $old;
            foreach (['floor', 'room', 'trade'] as $field) {
                if (!isset($row[$field]) || !is_string($row[$field])) continue;
                $value = trim($row[$field]);
                if ($value !== '' && $value !== $old[$field]) $new[$field] = $value;
            }
            if ($new !== $old) {
                $assignments[(string) $id] = $new;
                $changes++;
            }
        }
        if ($changes) {
            $this->WriteAttributeString('DeviceAssignments', json_encode($assignments, JSON_UNESCAPED_UNICODE));
            $this->SendDebug('DeviceDraft', $changes . ' Gerätezuordnungen gespeichert', 0);
        }
    }

    public function SaveDeviceDraft(string $rowsInput)
    {
        if (is_string($rowsInput)) {
            $rows = json_decode($rowsInput, true);
        } elseif (is_array($rowsInput)) {
            $rows = $rowsInput;
        } elseif (is_object($rowsInput)) {
            $rows = json_decode(json_encode($rowsInput, JSON_UNESCAPED_UNICODE), true);
        } else {
            return;
        }
        if (!is_array($rows)) {
            $this->SendDebug('DeviceDraft', 'Listeneingabe konnte nicht gelesen werden', 0);
            return;
        }
        $this->SendDebug('DeviceDraft', json_encode($rows, JSON_UNESCAPED_UNICODE), 0);
        if (isset($rows['values']) && is_array($rows['values'])) $rows = $rows['values'];
        $devices = $this->DiscoverPilotDevices();
        $savedAssignments = json_decode($this->ReadAttributeString('DeviceAssignments'), true);
        if (!is_array($savedAssignments)) $savedAssignments = [];
        $updated = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $deviceID = (int) ($row['deviceID'] ?? 0);
            if ($deviceID <= 0 || !isset($devices[(string) $deviceID])) continue;
            $device = $devices[(string) $deviceID];
            $current = $this->DeviceAssignment($deviceID, $device);
            $next = $current;
            foreach (['floor', 'room', 'trade'] as $field) {
                if (isset($row[$field]) && is_string($row[$field])) {
                    $candidate = trim($row[$field]);
                    if ($candidate !== '' && $candidate !== $current[$field]) $next[$field] = $candidate;
                }
            }
            if ($next !== $current) {
                $savedAssignments[(string) $deviceID] = $next;
                $updated++;
            }
        }
        if ($updated > 0) {
            $this->WriteAttributeString('DeviceAssignments', json_encode($savedAssignments, JSON_UNESCAPED_UNICODE));
        }
    }

    public function DeleteDeviceDraft(string $rowsJSON)
    {
        $this->SaveDeviceDraft($rowsJSON);
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

    public function SetPilotCreationConfirmation(bool $confirmed)
    {
        $this->WriteAttributeBoolean('ConfirmPilotCreation', $confirmed);
    }

    public function CreatePilotDevice()
    {
        if (!$this->ReadAttributeBoolean('ConfirmPilotCreation')) {
            throw new Exception('Bitte die Erstellung des Testgeräts zuerst ausdrücklich bestätigen.');
        }
        // Confirmation is single-use, including when validation fails.
        $this->WriteAttributeBoolean('ConfirmPilotCreation', false);
        $root = $this->ReadPropertyInteger('ManagedBuildingRoot');
        if ($root !== 58840) throw new Exception('Testlauf nur für Gebäude_neu (58840) freigegeben.');
        $devices = $this->DiscoverPilotDevices();
        if (!isset($devices['52319'])) throw new Exception('Shelly Klima Wohnzimmer (52319) nicht gefunden.');
        $assignment = $this->DeviceAssignment(52319, $devices['52319']);
        if ($assignment['floor'] !== 'EG' || $assignment['room'] !== 'Wohnzimmer' || $assignment['trade'] !== 'Heizung') {
            throw new Exception('Testgerät muss EG / Wohnzimmer / Heizung zugeordnet sein. Bitte zuerst die Zuordnung speichern.');
        }
        $rows = json_decode($this->ReadPropertyString('DeviceDraftRows'), true);
        $marked = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!empty($row['create'])) $marked[] = (int) ($row['deviceID'] ?? 0);
        }
        if ($marked !== [52319]) throw new Exception('Für diesen Test bitte ausschließlich Klima Wohnzimmer (52319) in der oberen Liste markieren und Änderungen übernehmen.');
        $this->WriteAttributeString('DeviceSelections', json_encode(['52319' => true]));
        try {
            $this->ApplyDeviceSelection();
        } finally {
            $this->WriteAttributeString('DeviceSelections', '{}');
        }
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
