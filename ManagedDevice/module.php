<?php
/**
 * Managed Device: native IP-Symcon instance with configurable variable links.
 * Existing dummy devices are never automatically converted or deleted.
 */
class ManagedDevice extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('SourceInstanceID', 0);
        $this->RegisterPropertyString('DeviceName', '');
        $this->RegisterPropertyString('Floor', '');
        $this->RegisterPropertyString('Room', '');
        $this->RegisterPropertyString('Trade', '');
        $this->RegisterPropertyInteger('ManagedBuildingRoot', 58840);
        $this->RegisterPropertyString('Links', '[]');
        $this->RegisterPropertyString('LinkNameRules', json_encode([['from'=>'s','to'=>'Schalten'],['from'=>'d','to'=>'Dimmen'],['from'=>'w','to'=>'Wert'],['from'=>'s.Status','to'=>'Schaltstatus'],['from'=>'w.Status','to'=>'Wertstatus']],JSON_UNESCAPED_UNICODE));
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $name = trim($this->ReadPropertyString('DeviceName'));
        if ($name !== '' && IPS_GetName($this->InstanceID) !== $name) IPS_SetName($this->InstanceID,$name);
        $this->SynchronizePlacement();
        $this->SynchronizeLinks();
    }

    private function SynchronizePlacement(): void
    {
        $floor = trim($this->ReadPropertyString('Floor'));
        $room = trim($this->ReadPropertyString('Room'));
        $trade = trim($this->ReadPropertyString('Trade'));
        $root = $this->ReadPropertyInteger('ManagedBuildingRoot');
        // Never move existing instances on upgrade while metadata is incomplete.
        if ($root <= 0 || $floor === '' || $room === '' || $trade === '') return;
        if (!IPS_ObjectExists($root) || IPS_GetObject($root)['ObjectType'] !== 0) return;
        foreach ([$floor,$room,$trade] as $part) {
            if ($part === '.' || $part === '..' || strpos($part,'/') !== false || strpos($part,'\\\\') !== false) return;
        }
        $parent = $root;
        foreach ([$floor,$room,$trade] as $part) {
            $category = 0;
            foreach (IPS_GetChildrenIDs($parent) as $child) {
                $obj = IPS_GetObject($child);
                if ($obj['ObjectType'] === 0 && IPS_GetName($child) === $part) {
                    $category = $child;
                    break;
                }
            }
            if ($category === 0) {
                // Avoid duplicate category names where a non-category object exists.
                foreach (IPS_GetChildrenIDs($parent) as $child) {
                    if (IPS_GetName($child) === $part) {
                        $this->SendDebug('Placement','Name conflict at '. $part,0);
                        return;
                    }
                }
                $category = IPS_CreateCategory();
                IPS_SetName($category,$part);
                IPS_SetParent($category,$parent);
            }
            $parent = $category;
        }
        if (IPS_GetParent($this->InstanceID) !== $parent) {
            IPS_SetParent($this->InstanceID,$parent);
        }
    }

    private function SourceVariables(): array
    {
        $source = $this->ReadPropertyInteger('SourceInstanceID');
        if ($source <= 0 || !IPS_ObjectExists($source)) return [];
        $found = [];
        $pending = [[$source,0]];
        while ($pending) {
            [$parent,$depth] = array_pop($pending);
            if ($depth > 3) continue;
            foreach (IPS_GetChildrenIDs($parent) as $id) {
                $type = IPS_GetObject($id)['ObjectType'];
                if ($type === 2) $found[$id] = IPS_GetName($id);
                elseif (($type === 0 || $type === 1) && $depth < 3) $pending[] = [$id,$depth+1];
            }
        }
        return $found;
    }

    private function SuggestedLinkName(int $variableID, string $sourceName): string
    {
        $name = trim($sourceName);
        if ($name === 'Wert') {
            $parent = IPS_GetParent($variableID);
            if ($parent > 0 && IPS_ObjectExists($parent)) $name = trim(IPS_GetName($parent));
        }
        $rules = json_decode($this->ReadPropertyString('LinkNameRules'),true);
        if (!is_array($rules)) $rules = [];
        // Exact names first, then KNX suffixes like Bel.Wohnzimmer.TV_s.
        foreach ($rules as $rule) {
            $from = trim((string)($rule['from'] ?? ''));
            $to = trim((string)($rule['to'] ?? ''));
            if ($from === '' || $to === '') continue;
            if (strcasecmp($name,$from) === 0) return $to;
        }
        foreach ($rules as $rule) {
            $from = trim((string)($rule['from'] ?? ''));
            $to = trim((string)($rule['to'] ?? ''));
            if ($from === '' || $to === '') continue;
            if (strlen($name) > strlen($from) + 1 && strcasecmp(substr($name,-strlen($from)-1),'_'.$from) === 0) return $to;
        }
        return $name !== '' ? $name : 'Variable '.$variableID;
    }

    private function IsLegacyAutomaticName(string $name, int $sourceID, string $sourceName): bool
    {
        if ($name === '' || $name === $sourceName) return true;
        if ($sourceName === 'Wert') {
            $rules = json_decode($this->ReadPropertyString('LinkNameRules'), true);
            foreach (is_array($rules) ? $rules : [] as $rule) {
                $candidate = trim((string)($rule['to'] ?? ''));
                if ($candidate !== '' && strcasecmp($name, $candidate) === 0) return true;
            }
        }
        if ($sourceName === 'Wert') {
            $parent = IPS_GetParent($sourceID);
            if ($parent > 0 && IPS_ObjectExists($parent) && $name === IPS_GetName($parent)) return true;
        }
        return false;
    }

    public function GetConfigurationForm()
    {
        $vars = $this->SourceVariables();
        $saved = json_decode($this->ReadPropertyString('Links'),true);
        if (!is_array($saved)) $saved = [];
        $selected = [];
        $sourceIDs = array_keys($vars);
        foreach ($saved as $index => $entry) {
            // Symcon List row editor can drop Label fields when a row is edited.
            // Recover immutable source identity from its original row position.
            $id = (int)($entry['sourceID'] ?? ($sourceIDs[$index] ?? 0));
            if (!isset($vars[$id])) $id = (int)($sourceIDs[$index] ?? 0);
            if ($id > 0) $selected[$id] = $entry;
        }
        $rows = [];
        $existingLinks = [];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $child) {
            if (IPS_GetObject($child)['ObjectType'] === 6) {
                $target = (int)IPS_GetLink($child)['TargetID'];
                if (!isset($existingLinks[$target])) $existingLinks[$target] = $child;
            }
        }
        foreach ($vars as $id=>$name) {
            $storedName = (string)($selected[$id]['name'] ?? $selected[$id]['linkName'] ?? '');
            if ($this->IsLegacyAutomaticName($storedName,(int)$id,$name)) $storedName = $this->SuggestedLinkName((int)$id,$name);
            $rows[] = [
                'instanceID'=>(int)($existingLinks[$id] ?? 0),
                'sourceID'=>$id,
                'sourceIDLabel'=>(string)$id,
                'enabled'=>isset($selected[$id]) ? (bool)($selected[$id]['enabled'] ?? true) : true,
                'name'=>$storedName,
                'sourceName'=>$name
            ];
        }
        return json_encode([
            'elements'=>[
                ['type'=>'SelectInstance','name'=>'SourceInstanceID','caption'=>'Hardware-Quellinstanz'],
                ['type'=>'ValidationTextBox','name'=>'DeviceName','caption'=>'Gerätename'],
                ['type'=>'ValidationTextBox','name'=>'Floor','caption'=>'Etage'],
                ['type'=>'ValidationTextBox','name'=>'Room','caption'=>'Raum'],
                ['type'=>'ValidationTextBox','name'=>'Trade','caption'=>'Gewerk'],
                ['type'=>'SelectCategory','name'=>'ManagedBuildingRoot','caption'=>'Gebäude-Zielkategorie'],
                ['type'=>'Label','caption'=>'Links werden unter dieser Geräteinstanz angelegt. Vorhandene Link-IDs bleiben beim Umbenennen erhalten.'],
                ['type'=>'Configurator','name'=>'LinkConfigurator','caption'=>'Linkübersicht (Stift am Linknamen)','rowCount'=>15,
                    'columns'=>[
                        ['caption'=>'Linkname','name'=>'name','width'=>'35%'],
                        ['caption'=>'Quellname','name'=>'sourceName','width'=>'40%'],
                        ['caption'=>'Quell-ID','name'=>'sourceIDLabel','width'=>'100px']
                    ],
                    'values'=>array_map(static function($row) {
                        return [
                            'name'=>$row['name'],
                            'sourceName'=>$row['sourceName'],
                            'sourceIDLabel'=>$row['sourceIDLabel'],
                            'instanceID'=>$row['instanceID']
                        ];
                    },$rows)
                ],
                ['type'=>'List','name'=>'Links','caption'=>'Linkauswahl und Namensvorgaben','rowCount'=>15,'add'=>false,'delete'=>false,
                    'columns'=>[
                        ['caption'=>'Verlinken','name'=>'enabled','width'=>'85px','edit'=>['type'=>'CheckBox']],
                        ['caption'=>'Linkname','name'=>'name','width'=>'35%','edit'=>['type'=>'ValidationTextBox']],
                        ['caption'=>'Quellname','name'=>'sourceName','width'=>'45%'],
                        ['caption'=>'Quell-ID','name'=>'sourceIDLabel','width'=>'90px']
                    ],
                    'values'=>$rows
                ]
            ],
            'actions'=>[
                ['type'=>'Label','caption'=>'Änderungen übernehmen speichert die Auswahl und synchronisiert die Links.']
            ]
        ],JSON_UNESCAPED_UNICODE);
    }

    private function SynchronizeLinks(): void
    {
        $sourceVars = $this->SourceVariables();
        $saved = json_decode($this->ReadPropertyString('Links'),true);
        if (!is_array($saved)) return;
        $existing = [];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $child) {
            if (IPS_GetObject($child)['ObjectType'] !== 6) continue;
            $target = (int)IPS_GetLink($child)['TargetID'];
            $existing[$target][] = $child;
        }
        $sourceIDs = array_keys($sourceVars);
        foreach ($saved as $index => $row) {
            $sourceID = (int)($row['sourceID'] ?? ($sourceIDs[$index] ?? 0));
            if (!isset($sourceVars[$sourceID])) $sourceID = (int)($sourceIDs[$index] ?? 0);
            // Symcon omits read-only Label fields from edited List rows.
            // Recover by stable source row position, never by link name.
            if (!$sourceID || !isset($sourceVars[$sourceID])) continue;
            $enabled = (bool)($row['enabled'] ?? true);
            if (!$enabled) continue; // Disabling never destroys existing links automatically.
            $name = trim((string)($row['name'] ?? $row['linkName'] ?? ''));
            if ($this->IsLegacyAutomaticName($name,$sourceID,$sourceVars[$sourceID])) $name = $this->SuggestedLinkName($sourceID,$sourceVars[$sourceID]);
            $link = (int)($existing[$sourceID][0] ?? 0);
            if (!$link) {
                $link = IPS_CreateLink();
                IPS_SetParent($link,$this->InstanceID);
                IPS_SetLinkTargetID($link,$sourceID);
            }
            if (IPS_GetName($link) !== $name) IPS_SetName($link,$name);
        }
    }
}
