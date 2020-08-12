<?php

/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */

/*
 * @module      Alarmanruf
 *
 * @prefix      AANR
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
 * @guids       Library
 *              {D071A59E-A674-A8CF-8604-BDB76F26F88D}
 *
 *              Alarmanruf
 *              {8BB803E5-876D-B342-5CAE-A6A9A0928B61}
 */

declare(strict_types=1);

// Include
include_once __DIR__ . '/helper/autoload.php';

class Alarmanruf extends IPSModule
{
    // Helper
    use AANR_alarmCall;
    use AANR_alarmDialer;
    use AANR_backupRestore;
    use AANR_nexxtMobile;

    // Constants
    private const ALARMANRUF_LIBRARY_GUID = '{D071A59E-A674-A8CF-8604-BDB76F26F88D}';
    private const ALARMANRUF_MODULE_GUID = '{8BB803E5-876D-B342-5CAE-A6A9A0928B61}';

    public function Create()
    {
        // Never delete this line!
        parent::Create();
        $this->RegisterProperties();
        $this->CreateProfiles();
        $this->RegisterVariables();
        $this->RegisterAttributes();
        $this->RegisterTimers();
    }

    public function Destroy()
    {
        // Never delete this line!
        parent::Destroy();
        $this->DeleteProfiles();
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
        $this->SetOptions();
        $this->ResetAttributes();
        $this->SetBuffer('SensorName', '');
        $this->DeactivateTimers();
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        $this->GetCurrentBalance();
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

        }
    }

    public function GetConfigurationForm()
    {
        $formData = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $moduleInfo = [];
        $library = IPS_GetLibrary(self::ALARMANRUF_LIBRARY_GUID);
        $module = IPS_GetModule(self::ALARMANRUF_MODULE_GUID);
        $moduleInfo['name'] = $module['ModuleName'];
        $moduleInfo['version'] = $library['Version'] . '-' . $library['Build'];
        $moduleInfo['date'] = date('d.m.Y', $library['Date']);
        $moduleInfo['time'] = date('H:i', $library['Date']);
        $moduleInfo['developer'] = $library['Author'];
        $formData['elements'][0]['items'][2]['caption'] = "Instanz ID:\t\t" . $this->InstanceID;
        $formData['elements'][0]['items'][3]['caption'] = "Modul:\t\t\t" . $moduleInfo['name'];
        $formData['elements'][0]['items'][4]['caption'] = "Version:\t\t\t" . $moduleInfo['version'];
        $formData['elements'][0]['items'][5]['caption'] = "Datum:\t\t\t" . $moduleInfo['date'];
        $formData['elements'][0]['items'][6]['caption'] = "Uhrzeit:\t\t\t" . $moduleInfo['time'];
        $formData['elements'][0]['items'][7]['caption'] = "Entwickler:\t\t" . $moduleInfo['developer'];
        $formData['elements'][0]['items'][8]['caption'] = "Präfix:\t\t\tAANR";
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
                $this->ToggleAlarmCall($Value, '');
                break;

            case 'GetCurrentBalance':
                $this->GetCurrentBalance();
                break;

        }
    }

    ###################### Private

    private function KernelReady()
    {
        $this->ApplyChanges();
    }

    private function RegisterProperties(): void
    {
        $this->RegisterPropertyString('Note', '');
        $this->RegisterPropertyBoolean('MaintenanceMode', false);
        // Visibility
        $this->RegisterPropertyBoolean('EnableAlarmCall', true);
        $this->RegisterPropertyBoolean('EnableGetCurrentBalance', false);
        $this->RegisterPropertyBoolean('EnableCurrentBalance', false);
        // Delay
        $this->RegisterPropertyInteger('ExecutionDelay', 0);
        // Alarm dialer
        $this->RegisterPropertyBoolean('UseAlarmDialer', false);
        $this->RegisterPropertyInteger('AlarmDialerType', 0);
        $this->RegisterPropertyInteger('AlarmDialer', -1);
        $this->RegisterPropertyInteger('ImpulseDuration', 3);
        // Nexxt Mobile
        $this->RegisterPropertyBoolean('UseNexxtMobile', false);
        $this->RegisterPropertyString('NexxtMobileToken', '');
        $this->RegisterPropertyString('NexxtMobileOriginator', '+49');
        $this->RegisterPropertyString('NexxtMobileRecipients', '[]');
        // Alarm protocol
        $this->RegisterPropertyInteger('AlarmProtocol', 0);
    }

    private function CreateProfiles(): void
    {
        // Alarm call
        $profile = 'AANR.' . $this->InstanceID . '.AlarmCall';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Aus', 'Mobile', -1);
        IPS_SetVariableProfileAssociation($profile, 1, 'Anruf auslösen', 'Mobile', 0xFF0000);
        // Get current balance
        $profile = 'AANR.' . $this->InstanceID . '.GetCurrentBalance';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
        }
        //IPS_SetVariableProfileAssociation($profile, 0, 'Aus', 'Euro', -1);
        IPS_SetVariableProfileAssociation($profile, 0, 'Guthaben abfragen', 'Euro', 0x00FF00);
    }

    private function DeleteProfiles(): void
    {
        $profiles = ['AlarmCall', 'GetCurrentBalance'];
        if (!empty($profiles)) {
            foreach ($profiles as $profile) {
                $profileName = 'AANR.' . $this->InstanceID . '.' . $profile;
                if (IPS_VariableProfileExists($profileName)) {
                    IPS_DeleteVariableProfile($profileName);
                }
            }
        }
    }

    private function RegisterVariables(): void
    {
        // Alarm call
        $profile = 'AANR.' . $this->InstanceID . '.AlarmCall';
        $this->RegisterVariableBoolean('AlarmCall', 'Alarmanruf', $profile, 10);
        $this->EnableAction('AlarmCall');
        // Get current balance
        $profile = 'AANR.' . $this->InstanceID . '.GetCurrentBalance';
        $this->RegisterVariableInteger('GetCurrentBalance', 'Guthaben abfragen', $profile, 20);
        $this->EnableAction('GetCurrentBalance');
        // Show current balance
        $this->RegisterVariableString('CurrentBalance', 'Guthaben', '', 30);
        $id = $this->GetIDForIdent('CurrentBalance');
        IPS_SetIcon($id, 'Information');
    }

    private function SetOptions(): void
    {
        // Alarm call
        $id = $this->GetIDForIdent('AlarmCall');
        $use = $this->ReadPropertyBoolean('EnableAlarmCall');
        IPS_SetHidden($id, !$use);
        // Get current balance
        $id = $this->GetIDForIdent('GetCurrentBalance');
        $use = $this->ReadPropertyBoolean('EnableGetCurrentBalance');
        IPS_SetHidden($id, !$use);
        // Show current balance
        $id = $this->GetIDForIdent('CurrentBalance');
        $use = $this->ReadPropertyBoolean('EnableCurrentBalance');
        IPS_SetHidden($id, !$use);
    }

    private function RegisterAttributes(): void
    {
        $this->RegisterAttributeBoolean('AlarmCallActive', false);
    }

    private function ResetAttributes(): void
    {
        $this->WriteAttributeBoolean('AlarmCallActive', false);
    }

    private function RegisterTimers(): void
    {
        $this->RegisterTimer('TriggerAlarmCall', 0, 'AANR_TriggerAlarmCall(' . $this->InstanceID . ');');
    }

    private function DeactivateTimers(): void
    {
        $this->SetTimerInterval('TriggerAlarmCall', 0);
    }

    private function CheckMaintenanceMode(): bool
    {
        $result = false;
        $status = 102;
        if ($this->ReadPropertyBoolean('MaintenanceMode')) {
            $result = true;
            $status = 104;
            $this->SendDebug(__FUNCTION__, 'Abbruch, der Wartungsmodus ist aktiv!', 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', Abbruch, der Wartungsmodus ist aktiv!', KL_WARNING);
        }
        $this->SetStatus($status);
        IPS_SetDisabled($this->InstanceID, $result);
        return $result;
    }
}
