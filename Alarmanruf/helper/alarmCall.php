<?php

/** @noinspection PhpUndefinedFunctionInspection */
/** @noinspection PhpUnusedPrivateMethodInspection */

declare(strict_types=1);

trait AANR_alarmCall
{
    /**
     * Toggles an alarm call.
     *
     * @param bool $State
     * false    = don't execute alarm call
     * true     = execute alarm call
     *
     * @param string $SensorName
     */
    public function ToggleAlarmCall(bool $State, string $SensorName): void
    {
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        $this->SendDebug(__FUNCTION__, 'Die Methode wurde mit Parameter ' . json_encode($State) . ' aufgerufen.', 0);
        // Deactivate
        if (!$State) {
            $this->SendDebug(__FUNCTION__, 'Der Alarmanruf wird ausgeschaltet.', 0);
            $this->SetTimerInterval('TriggerAlarmCall', 0);
            $this->SendDebug(__FUNCTION__, 'Timer TriggerAlarmCall: 0', 0);
            $this->WriteAttributeBoolean('AlarmCallActive', false);
            $this->SendDebug(__FUNCTION__, 'AlarmCallActive: false', 0);
            $this->SetBuffer('SensorName', '');
            $this->SendDebug(__FUNCTION__, 'ResetBuffer SensorName: ', 0);
            $this->SetValue('AlarmCall', false);
            $this->SendDebug(__FUNCTION__, 'Der Alarmanruf wurde ausgeschaltet.', 0);
        }
        // Activate
        if ($State) {
            $active = $this->ReadAttributeBoolean('AlarmCallActive');
            if ($active) {
                $text = 'Der Alarmanruf ist noch aktiv!';
                $this->LogMessage(__FUNCTION__ . ', ' . $text, KL_ERROR);
                $this->SendDebug(__FUNCTION__, $text, 0);
                return;
            }
            $this->SendDebug(__FUNCTION__, 'Der Alarmanruf wird ausgelöst.', 0);
            $this->SetValue('AlarmCall', true);
            // Check delay
            $delay = $this->ReadPropertyInteger('ExecutionDelay');
            if ($delay > 0) {
                $this->SetBuffer('SensorName', $SensorName);
                $this->SendDebug(__FUNCTION__, 'SetBuffer SensorName: ' . $SensorName, 0);
                $this->SetTimerInterval('TriggerAlarmCall', $delay * 1000);
                $this->SendDebug(__FUNCTION__, 'Timer TriggerAlarmCall: ' . $delay, 0);
            }
            // No delay, activate alarm call immediately
            else {
                $this->SetBuffer('SensorName', $SensorName);
                $this->TriggerAlarmCall();
            }
        }
    }

    /**
     * Triggers an alarm call.
     */
    public function TriggerAlarmCall(): void
    {
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        // Set
        $this->SetTimerInterval('TriggerAlarmCall', 0);
        $this->WriteAttributeBoolean('AlarmCallActive', true);
        $this->SendDebug(__FUNCTION__, 'AlarmCallActive: true', 0);
        $this->SetValue('AlarmCall', true);
        // Alarm dialer
        $this->TriggerAlarmDialer();
        // Nexxt Mobile
        $sensorName = $this->GetBuffer('SensorName');
        $this->SendDebug(__FUNCTION__, 'SensorName: ' . $sensorName, 0);
        $this->ExecuteNexxtMobileAlarmCall($sensorName);
        // Reset
        $this->WriteAttributeBoolean('AlarmCallActive', false);
        $this->SendDebug(__FUNCTION__, 'AlarmCallActive: false', 0);
        $this->SetBuffer('SensorName', '');
        $this->SendDebug(__FUNCTION__, 'ResetBuffer SensorName: ', 0);
        $this->SetValue('AlarmCall', false);
    }

    #################### Private

    /**
     * Updates the protocol.
     *
     * @param string $Message
     */
    private function UpdateProtocol(string $Message): void
    {
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        $protocolID = $this->ReadPropertyInteger('AlarmProtocol');
        if ($protocolID != 0 && @IPS_ObjectExists($protocolID)) {
            $timestamp = date('d.m.Y, H:i:s');
            $logText = $timestamp . ', ' . $Message;
            @APRO_UpdateMessages($protocolID, $logText, 0);
        }
    }
}