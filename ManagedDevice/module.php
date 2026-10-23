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
        $this->RegisterPropertyString('Links', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $name = trim($this->ReadPropertyString('DeviceName'));
        if ($name !== '' && IPS_GetName($this->InstanceID) !== $name) IPS_SetName($this->InstanceID,$name);
        $this->SynchronizeLinks();
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
        if ($sourceName !== 'Wert') return $sourceName;
        $parent = IPS_GetParent($variableID);
        if ($parent > 0 && IPS_ObjectExists($parent)) {
            $parentName = trim(IPS_GetName($parent));
            if ($parentName !== '') return $parentName;
        }
        return $sourceName . ' ' . $variableID;
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
        foreach ($vars as $id=>$name) {
            $rows[] = [
                'sourceID'=>$id,
                'sourceIDLabel'=>(string)$id,
                'enabled'=>isset($selected[$id]) ? (bool)($selected[$id]['enabled'] ?? true) : true,
                'linkName'=>(string)($selected[$id]['linkName'] ?? $this->SuggestedLinkName((int)$id,$name)),
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
                ['type'=>'Label','caption'=>'Links werden unter dieser Geräteinstanz angelegt. Vorhandene Link-IDs bleiben beim Umbenennen erhalten.'],
                ['type'=>'List','name'=>'Links','caption'=>'Verknüpfte Variablen','rowCount'=>15,'add'=>false,'delete'=>false,
                    'columns'=>[
                        ['caption'=>'Verlinken','name'=>'enabled','width'=>'85px','edit'=>['type'=>'CheckBox']],
                        ['caption'=>'Linkname','name'=>'linkName','width'=>'35%','edit'=>['type'=>'ValidationTextBox']],
                        ['caption'=>'Quellname','name'=>'sourceName','width'=>'45%','edit'=>['type'=>'ValidationTextBox']],
                        ['caption'=>'Quell-ID','name'=>'sourceIDLabel','width'=>'90px','edit'=>['type'=>'ValidationTextBox']]
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
            $name = trim((string)($row['linkName'] ?? ''));
            if ($name === '') $name = $this->SuggestedLinkName($sourceID,$sourceVars[$sourceID]);
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
