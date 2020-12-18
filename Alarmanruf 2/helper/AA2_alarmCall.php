<?php

/** @noinspection PhpUnused */

/*
 * @module      Alarmanruf 2 (NeXXt Mobile)
 *
 * @prefix      AA2
 *
 * @file        AA2_alarmCall.php
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

trait AA2_alarmCall
{
    #################### NeXXt Mobile

    /**
     * Gets the current balance.
     */
    public function GetCurrentBalance(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wurde aufgerufen (' . microtime(true) . ')', 0);
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        $token = $this->ReadPropertyString('Token');
        if (empty($token)) {
            return;
        } else {
            $token = rawurlencode($token);
        }
        $timeout = $this->ReadPropertyInteger('Timeout');
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://api.nexxtmobile.de/?mode=user&token=' . $token . '&function=getBalance',
            CURLOPT_HEADER         => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FAILONERROR    => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => 60]);
        $result = curl_exec($ch);
        if (!curl_errno($ch)) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $this->SendDebug(__FUNCTION__, 'HTTP Code: ' . $httpCode, 0);
            switch ($httpCode) {
                case $httpCode >= 200 && $httpCode < 300:
                    $this->SendDebug(__FUNCTION__, 'Response: ' . $result, 0);
                    $data = json_decode($result, true);
                    if (!empty($data)) {
                        if (array_key_exists('isError', $data)) {
                            $isError = $data['isError'];
                            if ($isError) {
                                $this->SendDebug(__FUNCTION__, 'Es ist ein Fehler aufgetreten!', 0);
                            }
                        } else {
                            $this->SendDebug(__FUNCTION__, 'Es ist ein Fehler aufgetreten!', 0);
                        }
                        if (array_key_exists('result', $data)) {
                            if (array_key_exists('balanceFormated', $data['result'])) {
                                $balance = $data['result']['balanceFormated'] . ' €';
                                $this->SendDebug(__FUNCTION__, 'Aktuelles Guthaben: ' . $balance, 0);
                                $this->SetValue('CurrentBalance', $balance);
                            }
                        }
                    } else {
                        $this->SendDebug(__FUNCTION__, 'Keine Rückantwort erhalten!', 0);
                    }
                    break;

                default:
                    $this->SendDebug(__FUNCTION__, 'HTTP Code: ' . $httpCode, 0);
            }
        } else {
            $error_msg = curl_error($ch);
            $this->SendDebug(__FUNCTION__, 'Es ist ein Fehler aufgetreten: ' . json_encode($error_msg), 0);
        }
    }

    /**
     * Toggles the alarm call off or on.
     *
     * @param bool $State
     * false    = off
     * true     = on
     *
     * @param int $MessageNumber
     * 1    = message 1
     * 2    = message 2
     * 3    = message 3
     * 4    = message 4
     *
     * @return bool
     * false    = an error occurred
     * true     = successful
     *
     * @throws Exception
     */
    public function ToggleAlarmCall(bool $State, int $MessageNumber): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wurde mit Parameter ' . json_encode($State) . ' aufgerufen (' . microtime(true) . ')', 0);
        $this->DisableTimers();
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        if ($this->CheckExistingRecipient() == 0) {
            return false;
        }
        $result = true;
        $actualAlarmCallState = $this->GetValue('AlarmCall');
        //Deactivate
        if (!$State) {
            $this->SendDebug(__FUNCTION__, 'Der Alarmanruf wird beendet', 0);
            $this->ResetAttributeMessageNumber();
            $this->SetValue('AlarmCall', false);
            if ($result) {
                $text = 'Der Alarmanruf wurde beendet';
                $this->SendDebug(__FUNCTION__, $text, 0);
            }
        }
        //Activate
        if ($State) {
            if ($this->CheckNightMode()) {
                return false;
            }
            $this->WriteAttributeInteger('MessageNumber', $MessageNumber);
            //Delay
            $delay = $this->ReadPropertyInteger('SwitchOnDelay');
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
                    //Protocol
                    if ($State != $actualAlarmCallState) {
                        $this->UpdateAlarmProtocol($text . '. (ID ' . $this->InstanceID . ')');
                    }
                }
            }
            //No delay, activate alarm call immediately
            else {
                if ($State != $actualAlarmCallState) {
                    $this->SetValue('AlarmCall', true);
                    $result = $this->ActivateAlarmCall();
                    if (!$result) {
                        //Revert
                        $this->SetValue('AlarmCall', $actualAlarmCallState);
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Activates the alarm call, also used by timer.
     *
     * @return bool
     * false    = an error occurred
     * true     = successful
     *
     * @throws Exception
     */
    public function ActivateAlarmCall(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt (' . microtime(true) . ')', 0);
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
        $messageNumber = $this->ReadAttributeInteger('MessageNumber');
        $message = $this->ReadPropertyString('Message' . $messageNumber);
        $recipients = json_decode($this->ReadPropertyString('Recipients'));
        foreach ($recipients as $recipient) {
            $phoneNumber = (string) $recipient->PhoneNumber;
            if ($recipient->Use && strlen($phoneNumber) > 3) {
                $this->SendDebug(__FUNCTION__, 'Name: ' . $recipient->Name, 0);
                $response = $this->TriggerAlarmCall($phoneNumber, $message);
                if (!$response) {
                    $result = false;
                }
            }
        }
        $this->ResetAttributeMessageNumber();
        if ($result) {
            $text = 'Der Alarmanruf wurde erfolgreich ausgelöst';
            $this->SendDebug(__FUNCTION__, $text, 0);
            //Protocol
            $this->UpdateAlarmProtocol($text . '. (ID ' . $this->InstanceID . ')');
            $this->ToggleAlarmCall(false, 0);
            //Balance
            $this->GetCurrentBalance();
        } else {
            //Revert on failure
            $this->SetValue('AlarmCall', false);
            //Log
            $text = 'Fehler, der Alarmanruf konnte nicht ausgelöst werden!';
            $this->SendDebug(__FUNCTION__, $text, 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_ERROR);
            //Protocol
            $this->UpdateAlarmProtocol($text . ' (ID ' . $this->InstanceID . ')');
        }
        return $result;
    }

    /**
     * Triggers an alarm call.
     *
     * @param string $PhoneNumber
     *
     * @param string $Message
     *
     * @return bool
     * false    = an error occurred
     * true     = ok
     *
     * @throws Exception
     */
    public function TriggerAlarmCall(string $PhoneNumber, string $Message): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt (' . microtime(true) . ')', 0);
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        if ($this->CheckNightMode()) {
            return false;
        }
        $token = $this->ReadPropertyString('Token');
        if (empty($token)) {
            return false;
        } else {
            $token = rawurlencode($token);
        }
        $originator = $this->ReadPropertyString('SenderPhoneNumber');
        if (empty($originator) || strlen($originator) <= 3) {
            return false;
        } else {
            $originator = rawurlencode($originator);
        }
        //Semaphore Enter
        if (!IPS_SemaphoreEnter($this->InstanceID . '.TriggerAlarmCall', 5000)) {
            return false;
        }
        //Send data to NeXXt Mobile
        $result = false;
        $this->SendDebug(__FUNCTION__, 'Rufnummer: ' . $PhoneNumber, 0);
        $this->SendDebug(__FUNCTION__, 'Ansagetext: ' . $Message, 0);
        $this->SendDebug(__FUNCTION__, 'Der Teilnehmer wird angerufen', 0);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://api.nexxtmobile.de/?mode=user&token=' . $token . '&function=callTTS&originator=' . $originator . '&number=' . rawurlencode($PhoneNumber) . '&text=' . rawurlencode($Message) . '&phase=execute&language=',
            CURLOPT_HEADER         => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FAILONERROR    => true,
            CURLOPT_CONNECTTIMEOUT => $this->ReadPropertyInteger('Timeout'),
            CURLOPT_TIMEOUT        => 60]);
        $response = curl_exec($ch);
        if (!curl_errno($ch)) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $this->SendDebug(__FUNCTION__, 'HTTP Code: ' . $httpCode, 0);
            switch ($httpCode) {
                case $httpCode >= 200 && $httpCode < 300:
                    $this->SendDebug(__FUNCTION__, 'Response: ' . $response, 0);
                    $data = json_decode($response, true);
                    if (!empty($data)) {
                        if (array_key_exists('isError', $data)) {
                            $isError = $data['isError'];
                            if (!$isError) {
                                $result = true;
                            }
                        }
                        if (array_key_exists('result', $data)) {
                            if (array_key_exists('balanceFormated', $data['result'])) {
                                $balance = $data['result']['balanceFormated'] . ' €';
                                $this->SendDebug(__FUNCTION__, 'Aktuelles Guthaben: ' . $balance, 0);
                                $this->SetValue('CurrentBalance', $balance);
                            }
                        }
                    }
                    break;

            }
        } else {
            $this->SendDebug(__FUNCTION__, json_encode(curl_error($ch)), 0);
        }
        //Semaphore leave
        IPS_SemaphoreLeave($this->InstanceID . '.TriggerAlarmCall');
        if ($result) {
            $this->SendDebug(__FUNCTION__, 'Der Teilnehmer wurde angerufen', 0);
        } else {
            $this->SendDebug(__FUNCTION__, 'Fehler, der Teilnehmer konnte nicht angerufen werden!', 0);
        }
        return $result;
    }

    /**
     * Deactivates the alarm call, used by timer.
     *
     * @return bool
     * false    = an error occurred
     * true     = successful
     *
     * @throws Exception
     */
    public function DeactivateAlarmCall(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt (' . microtime(true) . ')', 0);
        $this->SetTimerInterval('DeactivateAlarmCall', 0);
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        return $this->ToggleAlarmCall(false, 0);
    }

    /**
     * Checks the trigger variable.
     *
     * @param int $SenderID
     * @param bool $ValueChanged
     *
     * @return bool
     * false    = an error occurred
     * true     = successful
     *
     * @throws Exception
     */
    public function CheckTrigger(int $SenderID, bool $ValueChanged): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wurde vom Sender ' . $SenderID . ' aufgerufen (' . microtime(true) . ')', 0);
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        if ($this->CheckNightMode()) {
            return false;
        }
        $result = true;
        //Trigger variables
        $triggerVariables = json_decode($this->ReadPropertyString('TriggerVariables'));
        if (!empty($triggerVariables)) {
            foreach ($triggerVariables as $variable) {
                $id = $variable->TriggeringVariable;
                if ($SenderID == $id) {
                    if ($variable->Use) {
                        $this->SendDebug(__FUNCTION__, 'Variable ' . $id . ' ist aktiv', 0);
                        $execute = false;
                        $type = IPS_GetVariable($id)['VariableType'];
                        $trigger = $variable->Trigger;
                        $value = $variable->Value;
                        switch ($trigger) {
                            case 0: #on change (bool, integer, float, string)
                                if ($ValueChanged) {
                                    $execute = true;
                                }
                                break;

                            case 1: #on update (bool, integer, float, string)
                                $execute = true;
                                break;

                            case 2: #on limit drop (integer, float)
                                switch ($type) {
                                    case 1: #integer
                                        $actualValue = GetValueInteger($id);
                                        $triggerValue = intval($value);
                                        if ($actualValue < $triggerValue) {
                                            $execute = true;
                                        }
                                        break;

                                    case 2: #float
                                        $actualValue = GetValueFloat($id);
                                        $triggerValue = floatval(str_replace(',', '.', $value));
                                        if ($actualValue < $triggerValue) {
                                            $execute = true;
                                        }
                                        break;

                                }
                                break;

                            case 3: #on limit exceed (integer, float)
                                switch ($type) {
                                    case 1: #integer
                                        $actualValue = GetValueInteger($id);
                                        $triggerValue = intval($value);
                                        if ($actualValue > $triggerValue) {
                                            $execute = true;
                                        }
                                        break;

                                    case 2: #float
                                        $actualValue = GetValueFloat($id);
                                        $triggerValue = floatval(str_replace(',', '.', $value));
                                        if ($actualValue > $triggerValue) {
                                            $execute = true;
                                        }
                                        break;

                                }
                                break;

                            case 4: #on specific value (bool, integer, float, string)
                                switch ($type) {
                                    case 0: #bool
                                        $actualValue = GetValueBoolean($id);
                                        if ($value == 'false') {
                                            $value = '0';
                                        }
                                        $triggerValue = boolval($value);
                                        if ($actualValue == $triggerValue) {
                                            $condition = $variable->Condition;
                                            switch ($condition) {
                                                case 1: #trigger once
                                                    if ($ValueChanged) {
                                                        $execute = true;
                                                    }
                                                    break;

                                                case 2: #trigger every time
                                                    $execute = true;
                                            }
                                        }
                                        break;

                                    case 1: #integer
                                        $actualValue = GetValueInteger($id);
                                        $triggerValue = intval($value);
                                        if ($actualValue == $triggerValue) {
                                            $condition = $variable->Condition;
                                            switch ($condition) {
                                                case 1: #trigger once
                                                    if ($ValueChanged) {
                                                        $execute = true;
                                                    }
                                                    break;

                                                case 2: #trigger every time
                                                    $execute = true;
                                            }
                                        }
                                        break;

                                    case 2: #float
                                        $actualValue = GetValueFloat($id);
                                        $triggerValue = floatval(str_replace(',', '.', $value));
                                        if ($actualValue == $triggerValue) {
                                            $condition = $variable->Condition;
                                            switch ($condition) {
                                                case 1: #trigger once
                                                    if ($ValueChanged) {
                                                        $execute = true;
                                                    }
                                                    break;

                                                case 2: #trigger every time
                                                    $execute = true;
                                            }
                                        }
                                        break;

                                    case 3: #string
                                        $actualValue = GetValueString($id);
                                        $triggerValue = (string) $value;
                                        if ($actualValue == $triggerValue) {
                                            $condition = $variable->Condition;
                                            switch ($condition) {
                                                case 1: #trigger once
                                                    if ($ValueChanged) {
                                                        $execute = true;
                                                    }
                                                    break;

                                                case 2: #trigger every time
                                                    $execute = true;
                                            }
                                        }
                                        break;

                                }
                                break;
                        }
                        if ($execute) {
                            $action = $variable->Action;
                            switch ($action) {
                                case 0:
                                    $this->SendDebug(__FUNCTION__, 'Aktion: Alarmanruf beenden', 0);
                                    $result = $this->ToggleAlarmCall(false, 0);
                                    break;

                                case 1:
                                    $this->SendDebug(__FUNCTION__, 'Aktion: Alarmanruf auslösen', 0);
                                    $result = $this->ToggleAlarmCall(true, $variable->Message);
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

    /**
     * Checks for an existing recipient.
     *
     * @return int
     * Amount of used recipients.
     */
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