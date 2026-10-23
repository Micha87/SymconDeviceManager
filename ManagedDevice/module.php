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
        $this->RegisterPropertyString('Links', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
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

    public function GetConfigurationForm()
    {
        $vars = $this->SourceVariables();
        $saved = json_decode($this->ReadPropertyString('Links'),true);
        if (!is_array($saved)) $saved = [];
        $selected = [];
        foreach ($saved as $entry) {
            $id = (int)($entry['sourceID'] ?? 0);
            if ($id > 0) $selected[$id] = $entry;
        }
        $rows = [];
        foreach ($vars as $id=>$name) {
            $rows[] = [
                'sourceID'=>$id,
                'enabled'=>isset($selected[$id]) ? (bool)($selected[$id]['enabled'] ?? true) : true,
                'linkName'=>(string)($selected[$id]['linkName'] ?? $name),
                'sourceName'=>$name
            ];
        }
        return json_encode([
            'elements'=>[
                ['type'=>'SelectInstance','name'=>'SourceInstanceID','caption'=>'Hardware-Quellinstanz'],
                ['type'=>'Label','caption'=>'Links werden unter dieser Geräteinstanz angelegt. Vorhandene Link-IDs bleiben beim Umbenennen erhalten.'],
                ['type'=>'List','name'=>'Links','caption'=>'Verknüpfte Variablen','rowCount'=>15,'add'=>false,'delete'=>false,
                    'columns'=>[
                        ['caption'=>'Verlinken','name'=>'enabled','width'=>'85px','edit'=>['type'=>'CheckBox']],
                        ['caption'=>'Linkname','name'=>'linkName','width'=>'35%','edit'=>['type'=>'ValidationTextBox']],
                        ['caption'=>'Quellname','name'=>'sourceName','width'=>'45%','edit'=>['type'=>'Label']],
                        ['caption'=>'Quell-ID','name'=>'sourceID','width'=>'90px','edit'=>['type'=>'Label']]
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
        foreach ($saved as $row) {
            $sourceID = (int)($row['sourceID'] ?? 0);
            if (!$sourceID || !isset($sourceVars[$sourceID])) continue;
            $enabled = (bool)($row['enabled'] ?? true);
            if (!$enabled) continue; // Disabling never destroys existing links automatically.
            $name = trim((string)($row['linkName'] ?? ''));
            if ($name === '') $name = $sourceVars[$sourceID];
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
