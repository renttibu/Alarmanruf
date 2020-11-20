<?php

/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */

/*
 * @module      Alarmanruf 2 (NeXXt Mobile)
 *
 * @prefix      AA2
 *
 * @file        module.php
 *
 * @author      Ulrich Bittner
 * @copyright   (c) 2020
 * @license    	CC BY-NC-SA 4.0
 *              https://creativecommons.org/licenses/by-nc-sa/4.0/
 *
 * @see         https://github.com/ubittner/Alarmanruf
 *
 */

declare(strict_types=1);

include_once __DIR__ . '/helper/autoload.php';

class Alarmanruf2 extends IPSModule
{
    //Helper
    use AA2_alarmCall;
    use AA2_alarmProtocol;
    use AA2_backupRestore;
    use AA2_nightMode;

    public function Create()
    {
        //Never delete this line!
        parent::Create();
        $this->RegisterProperties();
        $this->CreateProfiles();
        $this->RegisterVariables();
        $this->RegisterAttributeMessageNumber();
        $this->RegisterTimers();
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
        $this->DeleteProfiles();
    }

    public function ApplyChanges()
    {
        //Wait until IP-Symcon is started
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        //Never delete this line!
        parent::ApplyChanges();
        //Check runlevel
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }
        $this->SetOptions();
        $this->ResetAttributeMessageNumber();
        $validate = $this->ValidateConfiguration();
        if (!$validate) {
            return;
        }
        $this->GetCurrentBalance();
        $this->RegisterMessages();
        $this->SetNightModeTimer();
        $this->CheckAutomaticNightMode();
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
                //$Data[0] = actual value
                //$Data[1] = value changed
                //$Data[2] = last value
                //$Data[3] = timestamp actual value
                //$Data[4] = timestamp value changed
                //$Data[5] = timestamp last value
                if ($this->CheckMaintenanceMode()) {
                    return;
                }
                //Trigger action
                if ($Data[1]) {
                    $scriptText = 'AA2_CheckTrigger(' . $this->InstanceID . ', ' . $SenderID . ');';
                    IPS_RunScriptText($scriptText);
                }
                break;

        }
    }

    public function GetConfigurationForm()
    {
        $formData = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        //Trigger variables
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
                $formData['elements'][1]['items'][0]['values'][] = [
                    'Use'           => $use,
                    'ID'            => $id,
                    'TriggerValue'  => $variable->TriggerValue,
                    'TriggerAction' => $variable->TriggerAction,
                    'rowColor'      => $rowColor];
            }
        }
        //Registered messages
        $messages = $this->GetMessageList();
        foreach ($messages as $senderID => $messageID) {
            $senderName = 'Objekt #' . $senderID . ' existiert nicht';
            $rowColor = '#FFC0C0'; # red
            if (@IPS_ObjectExists($senderID)) {
                $senderName = IPS_GetName($senderID);
                $rowColor = ''; # '#C0FFC0' # light green
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
        return json_encode($formData);
    }

    public function ReloadConfiguration()
    {
        $this->ReloadForm();
    }

    #################### Request Action

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'AlarmCall':
                $this->ToggleAlarmCall($Value, 1);
                break;

            case 'NightMode':
                $this->ToggleNightMode($Value);
                break;

            case 'GetCurrentBalance':
                $this->GetCurrentBalance();
                break;

        }
    }

    #################### Private

    private function KernelReady(): void
    {
        $this->ApplyChanges();
    }

    private function RegisterProperties(): void
    {
        //Functions
        $this->RegisterPropertyBoolean('MaintenanceMode', false);
        $this->RegisterPropertyBoolean('EnableAlarmCall', true);
        $this->RegisterPropertyBoolean('EnableNightMode', true);
        $this->RegisterPropertyBoolean('EnableGetCurrentBalance', true);
        $this->RegisterPropertyBoolean('EnableCurrentBalance', true);
        //Trigger variables
        $this->RegisterPropertyString('TriggerVariables', '[]');
        $this->RegisterPropertyString('Message1', 'Hinweis, es wurde ein Alarm ausgelöst!');
        $this->RegisterPropertyString('Message2', '');
        $this->RegisterPropertyString('Message3', '');
        $this->RegisterPropertyString('Message4', '');
        //Alarm call
        $this->RegisterPropertyString('Token', '');
        $this->RegisterPropertyString('SenderPhoneNumber', '+49');
        $this->RegisterPropertyInteger('Timeout', 5000);
        $this->RegisterPropertyInteger('SwitchOnDelay', 0);
        $this->RegisterPropertyString('Recipients', '[]');
        //Alarm protocol
        $this->RegisterPropertyInteger('AlarmProtocol', 0);
        //Night mode
        $this->RegisterPropertyBoolean('UseAutomaticNightMode', false);
        $this->RegisterPropertyString('NightModeStartTime', '{"hour":22,"minute":0,"second":0}');
        $this->RegisterPropertyString('NightModeEndTime', '{"hour":6,"minute":0,"second":0}');
    }

    private function CreateProfiles(): void
    {
        //Get current balance
        $profile = 'AA2.' . $this->InstanceID . '.GetCurrentBalance';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Guthaben abfragen', 'Euro', 0x00FF00);
    }

    private function DeleteProfiles(): void
    {
        $profiles = ['GetCurrentBalance'];
        if (!empty($profiles)) {
            foreach ($profiles as $profile) {
                $profileName = 'AA2.' . $this->InstanceID . '.' . $profile;
                if (IPS_VariableProfileExists($profileName)) {
                    IPS_DeleteVariableProfile($profileName);
                }
            }
        }
    }

    private function RegisterVariables(): void
    {
        //Alarm call
        $this->RegisterVariableBoolean('AlarmCall', 'Alarmanruf', '~Switch', 10);
        $this->EnableAction('AlarmCall');
        IPS_SetIcon($this->GetIDForIdent('AlarmCall'), 'Mobile');
        //Night mode
        $this->RegisterVAriableBoolean('NightMode', 'Nachtmodus', '~Switch', 20);
        $this->EnableAction('NightMode');
        IPS_SetIcon($this->GetIDForIdent('NightMode'), 'Moon');
        //Get current balance
        $profile = 'AA2.' . $this->InstanceID . '.GetCurrentBalance';
        $this->RegisterVariableInteger('GetCurrentBalance', 'Guthaben abfragen', $profile, 30);
        $this->EnableAction('GetCurrentBalance');
        //Show current balance
        $this->RegisterVariableString('CurrentBalance', 'Guthaben', '', 40);
        $id = $this->GetIDForIdent('CurrentBalance');
        IPS_SetIcon($id, 'Information');
    }

    private function SetOptions(): void
    {
        IPS_SetHidden($this->GetIDForIdent('AlarmCall'), !$this->ReadPropertyBoolean('EnableAlarmCall'));
        IPS_SetHidden($this->GetIDForIdent('NightMode'), !$this->ReadPropertyBoolean('EnableNightMode'));
        IPS_SetHidden($this->GetIDForIdent('GetCurrentBalance'), !$this->ReadPropertyBoolean('EnableGetCurrentBalance'));
        IPS_SetHidden($this->GetIDForIdent('CurrentBalance'), !$this->ReadPropertyBoolean('EnableCurrentBalance'));
    }

    private function RegisterAttributeMessageNumber(): void
    {
        $this->RegisterAttributeInteger('MessageNumber', 1);
    }

    private function ResetAttributeMessageNumber(): void
    {
        $this->WriteAttributeInteger('MessageNumber', 1);
    }

    private function RegisterTimers(): void
    {
        $this->RegisterTimer('ActivateAlarmCall', 0, 'AA2_ActivateAlarmCall(' . $this->InstanceID . ');');
        $this->RegisterTimer('DeactivateAlarmCall', 0, 'AA2_DeactivateAlarmCall(' . $this->InstanceID . ');');
        $this->RegisterTimer('StartNightMode', 0, 'AA2_StartNightMode(' . $this->InstanceID . ');');
        $this->RegisterTimer('StopNightMode', 0, 'AA2_StopNightMode(' . $this->InstanceID . ',);');
    }

    private function DisableTimers(): void
    {
        $this->SetTimerInterval('ActivateAlarmCall', 0);
        $this->SetTimerInterval('DeactivateAlarmCall', 0);
    }

    private function ValidateConfiguration(): bool
    {
        $result = true;
        $status = 102;
        //Maintenance mode
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

    private function RegisterMessages(): void
    {
        //Unregister
        $messages = $this->GetMessageList();
        if (!empty($messages)) {
            foreach ($messages as $id => $message) {
                foreach ($message as $messageType) {
                    if ($messageType == VM_UPDATE) {
                        $this->UnregisterMessage($id, VM_UPDATE);
                    }
                }
            }
        }
        //Register
        $variables = json_decode($this->ReadPropertyString('TriggerVariables'));
        if (!empty($variables)) {
            foreach ($variables as $variable) {
                if ($variable->Use) {
                    if ($variable->ID != 0 && @IPS_ObjectExists($variable->ID)) {
                        $this->RegisterMessage($variable->ID, VM_UPDATE);
                    }
                }
            }
        }
    }
}