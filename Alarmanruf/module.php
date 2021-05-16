<?php

/*
 * @author      Ulrich Bittner
 * @copyright   (c) 2020, 2021
 * @license    	CC BY-NC-SA 4.0
 * @see         https://github.com/ubittner/Alarmanruf/tree/master/Alarmanruf
 */

/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */

declare(strict_types=1);

include_once __DIR__ . '/helper/autoload.php';

class Alarmanruf extends IPSModule
{
    // Helper
    use AA_alarmCall;
    use AA_alarmProtocol;
    use AA_backupRestore;
    use AA_nightMode;

    // Constants
    private const DELAY_MILLISECONDS = 250;

    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Properties
        // Functions
        $this->RegisterPropertyBoolean('MaintenanceMode', false);
        $this->RegisterPropertyBoolean('EnableAlarmCall', true);
        $this->RegisterPropertyBoolean('EnableNightMode', true);
        // Alarm call
        $this->RegisterPropertyInteger('Variable', 0);
        $this->RegisterPropertyInteger('AlarmCallSwitchingDelay', 0);
        $this->RegisterPropertyInteger('SwitchOnDelay', 0);
        $this->RegisterPropertyInteger('SwitchOnDuration', 0);
        // Trigger variables
        $this->RegisterPropertyString('TriggerVariables', '[]');
        // Alarm protocol
        $this->RegisterPropertyInteger('AlarmProtocol', 0);
        // Night mode
        $this->RegisterPropertyBoolean('UseAutomaticNightMode', false);
        $this->RegisterPropertyString('NightModeStartTime', '{"hour":22,"minute":0,"second":0}');
        $this->RegisterPropertyString('NightModeEndTime', '{"hour":6,"minute":0,"second":0}');

        // Variables
        // Alarm call
        $id = @$this->GetIDForIdent('AlarmCall');
        $this->RegisterVariableBoolean('AlarmCall', 'Alarmanruf', '~Switch', 10);
        $this->EnableAction('AlarmCall');
        if ($id == false) {
            IPS_SetIcon($this->GetIDForIdent('AlarmCall'), 'Mobile');
        }
        // Night mode
        $id = @$this->GetIDForIdent('NightMode');
        $this->RegisterVAriableBoolean('NightMode', 'Nachtmodus', '~Switch', 20);
        $this->EnableAction('NightMode');
        if ($id == false) {
            IPS_SetIcon($this->GetIDForIdent('NightMode'), 'Moon');
        }

        // Timers
        $this->RegisterTimer('ActivateAlarmCall', 0, 'AA_ActivateAlarmCall(' . $this->InstanceID . ');');
        $this->RegisterTimer('DeactivateAlarmCall', 0, 'AA_DeactivateAlarmCall(' . $this->InstanceID . ');');
        $this->RegisterTimer('StartNightMode', 0, 'AA_StartNightMode(' . $this->InstanceID . ');');
        $this->RegisterTimer('StopNightMode', 0, 'AA_StopNightMode(' . $this->InstanceID . ',);');
    }

    public function ApplyChanges()
    {
        // Wait until IP-Symcon is started
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        // Never delete this line!
        parent::ApplyChanges();

        // Check runlevel
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }

        // Options
        IPS_SetHidden($this->GetIDForIdent('AlarmCall'), !$this->ReadPropertyBoolean('EnableAlarmCall'));
        IPS_SetHidden($this->GetIDForIdent('NightMode'), !$this->ReadPropertyBoolean('EnableNightMode'));

        // Reset
        $this->SetTimerInterval('ActivateAlarmCall', 0);
        $this->SetTimerInterval('DeactivateAlarmCall', 0);

        // Delete all references
        foreach ($this->GetReferenceList() as $referenceID) {
            $this->UnregisterReference($referenceID);
        }

        // Delete all registrations
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                if ($message == VM_UPDATE) {
                    $this->UnregisterMessage($senderID, VM_UPDATE);
                }
            }
        }

        // Validation
        if (!$this->ValidateConfiguration()) {
            return;
        }

        // Register references and update messages
        $properties = ['Variable', 'AlarmProtocol'];
        foreach ($properties as $property) {
            $id = $this->ReadPropertyInteger($property);
            if ($id != 0 && @IPS_ObjectExists($id)) {
                $this->RegisterReference($id);
            }
        }
        $variables = json_decode($this->ReadPropertyString('TriggerVariables'));
        foreach ($variables as $variable) {
            if ($variable->Use) {
                if ($variable->ID != 0 && @IPS_ObjectExists($variable->ID)) {
                    $this->RegisterReference($variable->ID);
                    $this->RegisterMessage($variable->ID, VM_UPDATE);
                }
            }
        }

        $this->SetNightModeTimer();
        $this->CheckAutomaticNightMode();
    }

    public function Destroy()
    {
        // Never delete this line!
        parent::Destroy();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $this->SendDebug(__FUNCTION__, $TimeStamp . ', SenderID: ' . $SenderID . ', Message: ' . $Message . ', Data: ' . print_r($Data, true), 0);
        if (!empty($Data)) {
            foreach ($Data as $key => $value) {
                $this->SendDebug(__FUNCTION__, 'Data[' . $key . '] = ' . json_encode($value), 0);
            }
        }
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->KernelReady();
                break;

            case VM_UPDATE:

                // $Data[0] = actual value
                // $Data[1] = value changed
                // $Data[2] = last value
                // $Data[3] = timestamp actual value
                // $Data[4] = timestamp value changed
                // $Data[5] = timestamp last value

                if ($this->CheckMaintenanceMode()) {
                    return;
                }

                // Check trigger
                $valueChanged = 'false';
                if ($Data[1]) {
                    $valueChanged = 'true';
                }
                $scriptText = 'AA_CheckTriggerVariable(' . $this->InstanceID . ', ' . $SenderID . ', ' . $valueChanged . ');';
                IPS_RunScriptText($scriptText);
                break;

        }
    }

    public function GetConfigurationForm()
    {
        $formData = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        // Alarm call
        $id = $this->ReadPropertyInteger('Variable');
        $enabled = false;
        if ($id != 0 && @IPS_ObjectExists($id)) {
            $enabled = true;
        }
        $formData['elements'][1]['items'][0] = [
            'type'  => 'RowLayout',
            'items' => [$formData['elements'][1]['items'][0]['items'][0] = [
                'type'    => 'SelectVariable',
                'name'    => 'Variable',
                'caption' => 'Variable',
                'width'   => '600px',
            ],
                $formData['elements'][1]['items'][0]['items'][1] = [
                    'type'    => 'Label',
                    'caption' => ' ',
                    'visible' => $enabled
                ],
                $formData['elements'][1]['items'][0]['items'][2] = [
                    'type'     => 'OpenObjectButton',
                    'caption'  => 'ID ' . $id . ' bearbeiten',
                    'visible'  => $enabled,
                    'objectID' => $id
                ]
            ]
        ];
        $formData['elements'][1]['items'][1] = [
            'type'    => 'NumberSpinner',
            'name'    => 'AlarmCallSwitchingDelay',
            'caption' => 'Schaltverzögerung',
            'minimum' => 0,
            'suffix'  => 'Millisekunden'
        ];
        $formData['elements'][1]['items'][2] = [
            'type'    => 'NumberSpinner',
            'name'    => 'SwitchOnDelay',
            'caption' => 'Einschaltverzögerung',
            'minimum' => 0,
            'suffix'  => 'Sekunden'
        ];
        $formData['elements'][1]['items'][3] = [
            'type'    => 'NumberSpinner',
            'name'    => 'SwitchOnDuration',
            'caption' => 'Impulsdauer',
            'minimum' => 0,
            'maximum' => 30,
            'suffix'  => 'Sekunden'
        ];
        // Trigger variables
        $variables = json_decode($this->ReadPropertyString('TriggerVariables'));
        if (!empty($variables)) {
            foreach ($variables as $variable) {
                $rowColor = '#C0FFC0'; # light green
                $use = $variable->Use;
                if (!$use) {
                    $rowColor = '';
                }
                $id = $variable->ID;
                if ($id == 0 || @!IPS_ObjectExists($id)) {
                    $rowColor = '#FFC0C0'; # red
                }
                $formData['elements'][2]['items'][0]['values'][] = [
                    'Use'              => $use,
                    'ID'               => $id,
                    'TriggerType'      => $variable->TriggerType,
                    'TriggerValue'     => $variable->TriggerValue,
                    'Action'           => $variable->Action,
                    'rowColor'         => $rowColor];
            }
        }
        // Alarm protocol
        $id = $this->ReadPropertyInteger('AlarmProtocol');
        $enabled = false;
        if ($id != 0 && @IPS_ObjectExists($id)) {
            $enabled = true;
        }
        $formData['elements'][3]['items'][0] = [
            'type'  => 'RowLayout',
            'items' => [$formData['elements'][3]['items'][0]['items'][0] = [
                'type'     => 'SelectModule',
                'name'     => 'AlarmProtocol',
                'caption'  => 'Alarmprotokoll',
                'moduleID' => '{33EF9DF1-C8D7-01E7-F168-0A1927F1C61F}',
                'width'    => '600px',
            ],
                $formData['elements'][3]['items'][0]['items'][1] = [
                    'type'    => 'Label',
                    'caption' => ' ',
                    'visible' => $enabled
                ],
                $formData['elements'][3]['items'][0]['items'][2] = [
                    'type'     => 'OpenObjectButton',
                    'caption'  => 'ID ' . $id . ' konfigurieren',
                    'visible'  => $enabled,
                    'objectID' => $id
                ]
            ]
        ];
        // Registered messages
        $messages = $this->GetMessageList();
        foreach ($messages as $senderID => $messageID) {
            $senderName = 'Objekt #' . $senderID . ' existiert nicht';
            $rowColor = '#FFC0C0'; # red
            if (@IPS_ObjectExists($senderID)) {
                $senderName = IPS_GetName($senderID);
                $rowColor = '#C0FFC0'; # light green
            }
            switch ($messageID) {
                case [10001]:
                    $messageDescription = 'IPS_KERNELSTARTED';
                    break;

                case [10603]:
                    $messageDescription = 'VM_UPDATE';
                    break;

                default:
                    $messageDescription = 'keine Bezeichnung';
            }
            $formData['actions'][1]['items'][0]['values'][] = [
                'SenderID'              => $senderID,
                'SenderName'            => $senderName,
                'MessageID'             => $messageID,
                'MessageDescription'    => $messageDescription,
                'rowColor'              => $rowColor];
        }
        // Status
        $formData['status'][0] = [
            'code'    => 101,
            'icon'    => 'active',
            'caption' => 'Alarmanruf wird erstellt',
        ];
        $formData['status'][1] = [
            'code'    => 102,
            'icon'    => 'active',
            'caption' => 'Alarmanruf ist aktiv (ID ' . $this->InstanceID . ')',
        ];
        $formData['status'][2] = [
            'code'    => 103,
            'icon'    => 'active',
            'caption' => 'Alarmanruf wird gelöscht (ID ' . $this->InstanceID . ')',
        ];
        $formData['status'][3] = [
            'code'    => 104,
            'icon'    => 'inactive',
            'caption' => 'Alarmanruf ist inaktiv (ID ' . $this->InstanceID . ')',
        ];
        $formData['status'][4] = [
            'code'    => 200,
            'icon'    => 'inactive',
            'caption' => 'Es ist Fehler aufgetreten, weitere Informationen unter Meldungen, im Log oder Debug! (ID ' . $this->InstanceID . ')',
        ];
        return json_encode($formData);
    }

    public function ReloadConfiguration()
    {
        $this->ReloadForm();
    }

    public function EnableTriggerVariableConfigurationButton(int $ObjectID): void
    {
        $this->UpdateFormField('TriggerVariableConfigurationButton', 'caption', 'Variable ' . $ObjectID . ' Bearbeiten');
        $this->UpdateFormField('TriggerVariableConfigurationButton', 'visible', true);
        $this->UpdateFormField('TriggerVariableConfigurationButton', 'enabled', true);
        $this->UpdateFormField('TriggerVariableConfigurationButton', 'objectID', $ObjectID);
    }

    public function ShowVariableDetails(int $VariableID): void
    {
        if ($VariableID == 0 || !@IPS_ObjectExists($VariableID)) {
            return;
        }
        if ($VariableID != 0) {
            // Variable
            echo 'ID: ' . $VariableID . "\n";
            echo 'Name: ' . IPS_GetName($VariableID) . "\n";
            $variable = IPS_GetVariable($VariableID);
            if (!empty($variable)) {
                $variableType = $variable['VariableType'];
                switch ($variableType) {
                    case 0:
                        $variableTypeName = 'Boolean';
                        break;

                    case 1:
                        $variableTypeName = 'Integer';
                        break;

                    case 2:
                        $variableTypeName = 'Float';
                        break;

                    case 3:
                        $variableTypeName = 'String';
                        break;

                    default:
                        $variableTypeName = 'Unbekannt';
                }
                echo 'Variablentyp: ' . $variableTypeName . "\n";
            }
            // Profile
            $profile = @IPS_GetVariableProfile($variable['VariableProfile']);
            if (empty($profile)) {
                $profile = @IPS_GetVariableProfile($variable['VariableCustomProfile']);
            }
            if (!empty($profile)) {
                $profileType = $variable['VariableType'];
                switch ($profileType) {
                    case 0:
                        $profileTypeName = 'Boolean';
                        break;

                    case 1:
                        $profileTypeName = 'Integer';
                        break;

                    case 2:
                        $profileTypeName = 'Float';
                        break;

                    case 3:
                        $profileTypeName = 'String';
                        break;

                    default:
                        $profileTypeName = 'Unbekannt';
                }
                echo 'Profilname: ' . $profile['ProfileName'] . "\n";
                echo 'Profiltyp: ' . $profileTypeName . "\n\n";
            }
            if (!empty($variable)) {
                echo "\nVariable:\n";
                print_r($variable);
            }
            if (!empty($profile)) {
                echo "\nVariablenprofil:\n";
                print_r($profile);
            }
        }
    }

    #################### Request Action

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'AlarmCall':
                $this->ToggleAlarmCall($Value);
                break;

            case 'NightMode':
                $this->ToggleNightMode($Value);
                break;

        }
    }

    #################### Private

    private function KernelReady(): void
    {
        $this->ApplyChanges();
    }

    private function ValidateConfiguration(): bool
    {
        $result = true;
        $status = 102;
        // Maintenance mode
        $maintenance = $this->CheckMaintenanceMode();
        if ($maintenance) {
            $result = false;
            $status = 104;
        }
        IPS_SetDisabled($this->InstanceID, $maintenance);
        $this->SetStatus($status);
        return $result;
    }

    private function CheckMaintenanceMode(): bool
    {
        $result = $this->ReadPropertyBoolean('MaintenanceMode');
        if ($result) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, der Wartungsmodus ist aktiv!', 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', Abbruch, der Wartungsmodus ist aktiv!', KL_WARNING);
        }
        return $result;
    }
}