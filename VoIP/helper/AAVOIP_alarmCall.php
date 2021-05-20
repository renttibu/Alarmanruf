<?php

/*
 * @author      Ulrich Bittner
 * @copyright   (c) 2020, 2021
 * @license    	CC BY-NC-SA 4.0
 * @see         https://github.com/ubittner/Alarmanruf/tree/master/VoIP
 */

/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */

declare(strict_types=1);

trait AAVOIP_alarmCall
{
    public function ToggleAlarmCall(bool $State, string $Announcement): bool
    {
        // Disable timers
        $this->SetTimerInterval('ActivateAlarmCall', 0);
        $this->SetTimerInterval('DeactivateAlarmCall', 0);
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        if ($this->CheckExistingRecipient() == 0) {
            return false;
        }
        $result = true;
        $actualAlarmCallState = $this->GetValue('AlarmCall');
        // Deactivate
        if (!$State) {
            $this->SendDebug(__FUNCTION__, 'Der Alarmanruf wird beendet', 0);
            $this->WriteAttributeString('Announcement', '');
            $this->SetValue('AlarmCall', false);
            if ($result) {
                $text = 'Der Alarmanruf wurde beendet';
                $this->SendDebug(__FUNCTION__, $text, 0);
            }
        }
        // Activate
        if ($State) {
            if ($this->CheckNightMode()) {
                return false;
            }
            $this->WriteAttributeString('Announcement', $Announcement);
            // Delay
            $delay = $this->ReadPropertyInteger('AlarmCallDelay');
            if ($delay > 0) {
                $this->SetTimerInterval('ActivateAlarmCall', $delay * 1000);
                $unit = 'Sekunden';
                if ($delay == 1) {
                    $unit = 'Sekunde';
                }
                $this->SetValue('AlarmCall', true);
                $text = 'Einschaltverzögerung, der Alarmanruf wird in ' . $delay . ' ' . $unit . ' ausgelöst';
                $this->SendDebug(__FUNCTION__, $text, 0);
                if (!$actualAlarmCallState) {
                    // Protocol
                    if ($State != $actualAlarmCallState) {
                        $this->UpdateAlarmProtocol($text . '. (ID ' . $this->InstanceID . ')');
                    }
                }
            } // No delay, activate alarm call immediately
            else {
                if ($State != $actualAlarmCallState) {
                    $this->SetValue('AlarmCall', true);
                    $result = $this->ActivateAlarmCall();
                    if (!$result) {
                        // Revert
                        $this->SetValue('AlarmCall', $actualAlarmCallState);
                    }
                }
            }
        }
        return $result;
    }

    public function ActivateAlarmCall(): bool
    {
        $this->SetTimerInterval('ActivateAlarmCall', 0);
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        if ($this->CheckNightMode()) {
            return false;
        }
        if ($this->CheckExistingRecipient() == 0) {
            return false;
        }
        $this->SendDebug(__FUNCTION__, 'Der Alarmanruf wird ausgelöst', 0);
        $this->SetValue('AlarmCall', true);
        $result = true;
        $announcement = $this->ReadAttributeString('Announcement');
        if (empty($announcement)) {
            $announcement = $this->ReadPropertyString('DefaultAnnouncement');
        }
        $recipients = json_decode($this->ReadPropertyString('Recipients'));
        foreach ($recipients as $recipient) {
            $phoneNumber = (string) $recipient->PhoneNumber;
            if ($recipient->Use && strlen($phoneNumber) > 3) {
                $this->SendDebug(__FUNCTION__, 'Name: ' . $recipient->Name, 0);
                $response = $this->ExecuteAlarmCall($phoneNumber, $announcement);
                if (!$response) {
                    $result = false;
                }
            }
        }
        $this->WriteAttributeString('Announcement', '');
        if ($result) {
            $text = 'Der Alarmanruf wurde erfolgreich ausgelöst';
            $this->SendDebug(__FUNCTION__, $text, 0);
            // Protocol
            $this->UpdateAlarmProtocol($text . '. (ID ' . $this->InstanceID . ')');
            $this->ToggleAlarmCall(false, '');
        } else {
            // Revert on failure
            $this->SetValue('AlarmCall', false);
            $text = 'Fehler, der Alarmanruf konnte nicht ausgelöst werden!';
            $this->SendDebug(__FUNCTION__, $text, 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_ERROR);
            // Protocol
            $this->UpdateAlarmProtocol($text . ' (ID ' . $this->InstanceID . ')');
        }
        return $result;
    }

    public function ExecuteAlarmCall(string $PhoneNumber, string $Announcement): bool
    {
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        if ($this->CheckNightMode()) {
            return false;
        }
        // Semaphore enter
        if (!IPS_SemaphoreEnter($this->InstanceID . '.ExecuteAlarmCall', 5000)) {
            return false;
        }
        // Call recipient
        $voipID = $this->ReadPropertyInteger('VoIP');
        if ($voipID == 0 || @!IPS_ObjectExists($voipID)) {
            return false;
        }
        $pollyID = $this->ReadPropertyInteger('TTSAWSPolly');
        $duration = $this->ReadPropertyInteger('VoIPDuration');
        $scriptText = '
        $id = VoIP_Connect(' . $voipID . ', "' . $PhoneNumber . '");
        for($i = 0; $i < ' . $duration . '; $i++) {
            IPS_Sleep(1000);
            $c = VoIP_GetConnection(' . $voipID . ', $id);
            if($c["Connected"]) {
                break;
            }
        }
        VoIP_Disconnect(' . $voipID . ', $id);';
        if ($pollyID != 0 && @IPS_ObjectExists($pollyID)) {
            $scriptText = '
            $id = VoIP_Connect(' . $voipID . ', "' . $PhoneNumber . '");
            for($i = 0; $i < ' . $duration . '; $i++) {
                IPS_Sleep(1000);
                $c = VoIP_GetConnection(' . $voipID . ', $id);
                if($c["Connected"]) {
                    if (' . $pollyID . ' != 0 && @IPS_ObjectExists(' . $pollyID . ')) {
                        VoIP_PlayWave(' . $voipID . ', $id, TTSAWSPOLLY_GenerateFile(' . $pollyID . ', "' . $Announcement . '"));
                        return;
                    }
                }
            }
            VoIP_Disconnect(' . $voipID . ', $id);';
        }
        IPS_RunScriptText($scriptText);
        // Semaphore leave
        IPS_SemaphoreLeave($this->InstanceID . '.ExecuteAlarmCall');
        return true;
    }

    public function DeactivateAlarmCall(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
        $this->SetTimerInterval('DeactivateAlarmCall', 0);
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        return $this->ToggleAlarmCall(false, '');
    }

    public function CheckTriggerVariable(int $SenderID, bool $ValueChanged): bool
    {
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        if ($this->CheckNightMode()) {
            return false;
        }
        $result = true;
        // Trigger variables
        $triggerVariables = json_decode($this->ReadPropertyString('TriggerVariables'));
        if (!empty($triggerVariables)) {
            foreach ($triggerVariables as $variable) {
                $id = $variable->ID;
                if ($SenderID == $id) {
                    if ($variable->Use) {
                        $this->SendDebug(__FUNCTION__, 'Variable ' . $id . ' ist aktiv', 0);
                        $execute = false;
                        $type = IPS_GetVariable($id)['VariableType'];
                        $trigger = $variable->TriggerType;
                        $value = $variable->TriggerValue;
                        switch ($trigger) {
                            case 0: #on change (bool, integer, float, string)
                                $this->SendDebug(__FUNCTION__, 'Bei Änderung (bool, integer, float, string)', 0);
                                if ($ValueChanged) {
                                    $execute = true;
                                }
                                break;

                            case 1: #on update (bool, integer, float, string)
                                $this->SendDebug(__FUNCTION__, 'Bei Aktualisierung (bool, integer, float, string)', 0);
                                $execute = true;
                                break;

                            case 2: #on limit drop, once (integer, float)
                                switch ($type) {
                                    case 1: #integer
                                        $this->SendDebug(__FUNCTION__, 'Bei Grenzunterschreitung, einmalig (integer)', 0);
                                        if ($ValueChanged) {
                                            if ($value == 'false') {
                                                $value = '0';
                                            }
                                            if ($value == 'true') {
                                                $value = '1';
                                            }
                                            if (GetValueInteger($SenderID) < intval($value)) {
                                                $execute = true;
                                            }
                                        }
                                        break;

                                    case 2: #float
                                        $this->SendDebug(__FUNCTION__, 'Bei Grenzunterschreitung, einmalig (float)', 0);
                                        if ($ValueChanged) {
                                            if ($value == 'false') {
                                                $value = '0';
                                            }
                                            if ($value == 'true') {
                                                $value = '1';
                                            }
                                            if (GetValueFloat($SenderID) < floatval(str_replace(',', '.', $value))) {
                                                $execute = true;
                                            }
                                        }
                                        break;

                                }
                                break;

                            case 3: #on limit drop, every time (integer, float)
                                switch ($type) {
                                    case 1: #integer
                                        $this->SendDebug(__FUNCTION__, 'Bei Grenzunterschreitung, mehrmalig (integer)', 0);
                                        if ($value == 'false') {
                                            $value = '0';
                                        }
                                        if ($value == 'true') {
                                            $value = '1';
                                        }
                                        if (GetValueInteger($SenderID) < intval($value)) {
                                            $execute = true;
                                        }
                                        break;

                                    case 2: #float
                                        $this->SendDebug(__FUNCTION__, 'Bei Grenzunterschreitung, mehrmalig (float)', 0);
                                        if ($value == 'false') {
                                            $value = '0';
                                        }
                                        if ($value == 'true') {
                                            $value = '1';
                                        }
                                        if (GetValueFloat($SenderID) < floatval(str_replace(',', '.', $value))) {
                                            $execute = true;
                                        }
                                        break;

                                }
                                break;

                            case 4: #on limit exceed, once (integer, float)
                                switch ($type) {
                                    case 1: #integer
                                        $this->SendDebug(__FUNCTION__, 'Bei Grenzunterschreitung, einmalig (integer)', 0);
                                        if ($ValueChanged) {
                                            if ($value == 'false') {
                                                $value = '0';
                                            }
                                            if ($value == 'true') {
                                                $value = '1';
                                            }
                                            if (GetValueInteger($SenderID) > intval($value)) {
                                                $execute = true;
                                            }
                                        }
                                        break;

                                    case 2: #float
                                        $this->SendDebug(__FUNCTION__, 'Bei Grenzunterschreitung, einmalig (float)', 0);
                                        if ($ValueChanged) {
                                            if ($value == 'false') {
                                                $value = '0';
                                            }
                                            if ($value == 'true') {
                                                $value = '1';
                                            }
                                            if (GetValueFloat($SenderID) > floatval(str_replace(',', '.', $value))) {
                                                $execute = true;
                                            }
                                        }
                                        break;

                                }
                                break;

                            case 5: #on limit exceed, every time (integer, float)
                                switch ($type) {
                                    case 1: #integer
                                        $this->SendDebug(__FUNCTION__, 'Bei Grenzunterschreitung, mehrmalig (integer)', 0);
                                        if ($value == 'false') {
                                            $value = '0';
                                        }
                                        if ($value == 'true') {
                                            $value = '1';
                                        }
                                        if (GetValueInteger($SenderID) > intval($value)) {
                                            $execute = true;
                                        }
                                        break;

                                    case 2: #float
                                        $this->SendDebug(__FUNCTION__, 'Bei Grenzunterschreitung, mehrmalig (float)', 0);
                                        if ($value == 'false') {
                                            $value = '0';
                                        }
                                        if ($value == 'true') {
                                            $value = '1';
                                        }
                                        if (GetValueFloat($SenderID) > floatval(str_replace(',', '.', $value))) {
                                            $execute = true;
                                        }
                                        break;

                                }
                                break;

                            case 6: #on specific value, once (bool, integer, float, string)
                                switch ($type) {
                                    case 0: #bool
                                        $this->SendDebug(__FUNCTION__, 'Bei bestimmten Wert, einmalig (bool)', 0);
                                        if ($ValueChanged) {
                                            if ($value == 'false') {
                                                $value = '0';
                                            }
                                            if (GetValueBoolean($SenderID) == boolval($value)) {
                                                $execute = true;
                                            }
                                        }
                                        break;

                                    case 1: #integer
                                        $this->SendDebug(__FUNCTION__, 'Bei bestimmten Wert, einmalig (integer)', 0);
                                        if ($ValueChanged) {
                                            if ($value == 'false') {
                                                $value = '0';
                                            }
                                            if ($value == 'true') {
                                                $value = '1';
                                            }
                                            if (GetValueInteger($SenderID) == intval($value)) {
                                                $execute = true;
                                            }
                                        }
                                        break;

                                    case 2: #float
                                        $this->SendDebug(__FUNCTION__, 'Bei bestimmten Wert, einmalig (float)', 0);
                                        if ($ValueChanged) {
                                            if ($value == 'false') {
                                                $value = '0';
                                            }
                                            if ($value == 'true') {
                                                $value = '1';
                                            }
                                            if (GetValueFloat($SenderID) == floatval(str_replace(',', '.', $value))) {
                                                $execute = true;
                                            }
                                        }
                                        break;

                                    case 3: #string
                                        $this->SendDebug(__FUNCTION__, 'Bei bestimmten Wert, einmalig (string)', 0);
                                        if ($ValueChanged) {
                                            if (GetValueString($SenderID) == (string) $value) {
                                                $execute = true;
                                            }
                                        }
                                        break;

                                }
                                break;

                            case 7: #on specific value, every time (bool, integer, float, string)
                                switch ($type) {
                                    case 0: #bool
                                        $this->SendDebug(__FUNCTION__, 'Bei bestimmten Wert, mehrmalig (bool)', 0);
                                        if ($value == 'false') {
                                            $value = '0';
                                        }
                                        if (GetValueBoolean($SenderID) == boolval($value)) {
                                            $execute = true;
                                        }
                                        break;

                                    case 1: #integer
                                        $this->SendDebug(__FUNCTION__, 'Bei bestimmten Wert, mehrmalig (integer)', 0);
                                        if ($value == 'false') {
                                            $value = '0';
                                        }
                                        if ($value == 'true') {
                                            $value = '1';
                                        }
                                        if (GetValueInteger($SenderID) == intval($value)) {
                                            $execute = true;
                                        }
                                        break;

                                    case 2: #float
                                        $this->SendDebug(__FUNCTION__, 'Bei bestimmten Wert, mehrmalig (float)', 0);
                                        if ($value == 'false') {
                                            $value = '0';
                                        }
                                        if ($value == 'true') {
                                            $value = '1';
                                        }
                                        if (GetValueFloat($SenderID) == floatval(str_replace(',', '.', $value))) {
                                            $execute = true;
                                        }
                                        break;

                                    case 3: #string
                                        $this->SendDebug(__FUNCTION__, 'Bei bestimmten Wert, mehrmalig (string)', 0);
                                        if (GetValueString($SenderID) == (string) $value) {
                                            $execute = true;
                                        }
                                        break;

                                }
                                break;
                        }
                        $this->SendDebug(__FUNCTION__, 'Bedingung erfüllt: ' . json_encode($execute), 0);
                        if ($execute) {
                            $action = $variable->Action;
                            switch ($action) {
                                case 0:
                                    $this->SendDebug(__FUNCTION__, 'Aktion: Alarmanruf beenden', 0);
                                    $result = $this->ToggleAlarmCall(false, '');
                                    break;

                                case 1:
                                    $this->SendDebug(__FUNCTION__, 'Aktion: Alarmanruf auslösen', 0);
                                    $announcement = $variable->Announcement;
                                    $alertingSensor = $variable->AlertingSensor;
                                    if (!empty($alertingSensor)) {
                                        $announcement = sprintf($announcement, GetValueString($variable->AlertingSensor));
                                    }
                                    $this->SendDebug(__FUNCTION__, $announcement, 0);
                                    $result = $this->ToggleAlarmCall(true, $announcement);
                                    break;

                                default:
                                    $this->SendDebug(__FUNCTION__, 'Es soll keine Aktion erfolgen!', 0);
                            }
                        } else {
                            $this->SendDebug(__FUNCTION__, 'Keine Übereinstimmung!', 0);
                        }
                    }
                }
            }
        }
        return $result;
    }

    #################### Private

    private function CheckExistingRecipient(): int
    {
        $amount = 0;
        $recipients = json_decode($this->ReadPropertyString('Recipients'));
        if (empty($recipients)) {
            return $amount;
        } else {
            foreach ($recipients as $recipient) {
                if ($recipient->Use) {
                    $amount++;
                }
            }
        }
        return $amount;
    }
}