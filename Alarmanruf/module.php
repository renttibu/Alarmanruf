<?php

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
 * @version     4.00-1
 * @date        2020-01-17, 18:00, 1579280400
 * @review      2020-01-17, 18:00, 1579280400
 *
 * @see         https://github.com/ubittner/Alarmanruf/
 *
 * @guids       Library
 *              {D071A59E-A674-A8CF-8604-BDB76F26F88D}
 *
 *              Alarmanruf
 *              {8BB803E5-876D-B342-5CAE-A6A9A0928B61}
 */

// Declare
declare(strict_types=1);

// Include
include_once __DIR__ . '/helper/autoload.php';

class Alarmanruf extends IPSModule
{
    // Helper
    use AANR_alarmCall;
    use AANR_alarmDialer;
    use AANR_nexxtMobile;

    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Register properties
        $this->RegisterProperties();

        // Create profiles
        $this->CreateProfiles();

        // Register variables
        $this->RegisterVariables();

        // Register attributes
        $this->RegisterAttributes();

        // Register Timers
        $this->RegisterTimers();
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

        // Set Options
        $this->SetOptions();

        // Reset attributes
        $this->ResetAttributes();

        // Set buffer
        $this->SetBuffer('SensorName', '');

        // Deactivate timers
        $this->DeactivateTimers();

        // Get current balance
        $this->GetCurrentBalance(true);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        // Send debug
        $this->SendDebug('MessageSink', 'Message from SenderID ' . $SenderID . ' with Message ' . $Message . "\r\n Data: " . print_r($Data, true), 0);
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->KernelReady();
                break;

        }
    }

    protected function KernelReady()
    {
        $this->ApplyChanges();
    }

    public function Destroy()
    {
        // Never delete this line!
        parent::Destroy();

        // Delete profile
        $this->DeleteProfiles();
    }

    //#################### Request Action

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'AlarmCall':
                $this->ToggleAlarmCall($Value, '');
                break;

            case 'GetCurrentBalance':
                $this->SetValue('GetCurrentBalance', $Value);
                $this->GetCurrentBalance($Value);
                break;

        }
    }

    //###################### Private

    private function RegisterProperties(): void
    {
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
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Aus', 'Euro', -1);
        IPS_SetVariableProfileAssociation($profile, 1, 'Guthaben abfragen', 'Euro', 0x00FF00);
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
        $this->RegisterVariableBoolean('AlarmCall', 'Alarmanruf', $profile, 1);
        $this->EnableAction('AlarmCall');

        // Get current balance
        $profile = 'AANR.' . $this->InstanceID . '.GetCurrentBalance';
        $this->RegisterVariableBoolean('GetCurrentBalance', 'Guthaben abfragen', $profile, 2);
        $this->EnableAction('GetCurrentBalance');

        // Show current balance
        $this->RegisterVariableString('CurrentBalance', 'Guthaben', '', 3);
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
}
