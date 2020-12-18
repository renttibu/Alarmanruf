<?php

/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */

/*
 * @module      Alarmanruf 1 (Variable)
 *
 * @prefix      AA1
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

class Alarmanruf1 extends IPSModule
{
    //Helper
    use AA1_alarmCall;
    use AA1_alarmProtocol;
    use AA1_backupRestore;
    use AA1_nightMode;

    //Constants
    private const DELAY_MILLISECONDS = 250;

    public function Create()
    {
        //Never delete this line!
        parent::Create();
        $this->RegisterProperties();
        $this->RegisterVariables();
        $this->RegisterTimers();
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
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
        $validate = $this->ValidateConfiguration();
        if (!$validate) {
            return;
        }
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
                $valueChanged = 'false';
                if ($Data[1]) {
                    $valueChanged = 'true';
                }
                $scriptText = 'AA1_CheckTrigger(' . $this->InstanceID . ', ' . $SenderID . ', ' . $valueChanged . ');';
                IPS_RunScriptText($scriptText);
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
                $id = $variable->TriggeringVariable;
                if ($id == 0 || @!IPS_ObjectExists($id)) {
                    $rowColor = '#FFC0C0'; # red
                }
                $formData['elements'][1]['items'][0]['values'][] = [
                    'Use'                   => $use,
                    'TriggeringVariable'    => $id,
                    'Trigger'               => $variable->Trigger,
                    'Value'                 => $variable->Value,
                    'Condition'             => $variable->Condition,
                    'Action'                => $variable->Action,
                    'rowColor'              => $rowColor];
            }
        }
        //Registered messages
        $messages = $this->GetMessageList();
        foreach ($messages as $senderID => $messageID) {
            $senderName = 'Objekt #' . $senderID . ' existiert nicht';
            $rowColor = '#FFC0C0'; # red
            if (@IPS_ObjectExists($senderID)) {
                $senderName = IPS_GetName($senderID);
                $rowColor = ''; # '#C0FFC0' # green
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

    private function RegisterProperties(): void
    {
        //Functions
        $this->RegisterPropertyBoolean('MaintenanceMode', false);
        $this->RegisterPropertyBoolean('EnableAlarmCall', true);
        $this->RegisterPropertyBoolean('EnableNightMode', true);
        //Trigger variables
        $this->RegisterPropertyString('TriggerVariables', '[]');
        //Alarm call
        $this->RegisterPropertyInteger('Variable', 0);
        $this->RegisterPropertyInteger('AlarmCallSwitchingDelay', 0);
        $this->RegisterPropertyInteger('SwitchOnDelay', 0);
        $this->RegisterPropertyInteger('SwitchOnDuration', 0);
        //Alarm protocol
        $this->RegisterPropertyInteger('AlarmProtocol', 0);
        //Night mode
        $this->RegisterPropertyBoolean('UseAutomaticNightMode', false);
        $this->RegisterPropertyString('NightModeStartTime', '{"hour":22,"minute":0,"second":0}');
        $this->RegisterPropertyString('NightModeEndTime', '{"hour":6,"minute":0,"second":0}');
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
    }

    private function SetOptions(): void
    {
        IPS_SetHidden($this->GetIDForIdent('AlarmCall'), !$this->ReadPropertyBoolean('EnableAlarmCall'));
        IPS_SetHidden($this->GetIDForIdent('NightMode'), !$this->ReadPropertyBoolean('EnableNightMode'));
    }

    private function RegisterTimers(): void
    {
        $this->RegisterTimer('ActivateAlarmCall', 0, 'AA1_ActivateAlarmCall(' . $this->InstanceID . ');');
        $this->RegisterTimer('DeactivateAlarmCall', 0, 'AA1_DeactivateAlarmCall(' . $this->InstanceID . ');');
        $this->RegisterTimer('StartNightMode', 0, 'AA1_StartNightMode(' . $this->InstanceID . ');');
        $this->RegisterTimer('StopNightMode', 0, 'AA1_StopNightMode(' . $this->InstanceID . ',);');
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
                    if ($variable->TriggeringVariable != 0 && @IPS_ObjectExists($variable->TriggeringVariable)) {
                        $this->RegisterMessage($variable->TriggeringVariable, VM_UPDATE);
                    }
                }
            }
        }
    }
}