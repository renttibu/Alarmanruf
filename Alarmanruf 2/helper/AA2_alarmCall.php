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

    public function ToggleAlarmCall(bool $State, string $Announcement): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wurde mit Parameter ' . json_encode($State) . ' aufgerufen (' . microtime(true) . ')', 0);

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
                    // Protocol
                    if ($State != $actualAlarmCallState) {
                        $this->UpdateAlarmProtocol($text . '. (ID ' . $this->InstanceID . ')');
                    }
                }
            }

            // No delay, activate alarm call immediately
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
            // Balance
            $this->GetCurrentBalance();
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
        // Semaphore Enter
        if (!IPS_SemaphoreEnter($this->InstanceID . '.ExecuteAlarmCall', 5000)) {
            return false;
        }
        // Send data to NeXXt Mobile
        $result = false;
        $this->SendDebug(__FUNCTION__, 'Rufnummer: ' . $PhoneNumber, 0);
        $this->SendDebug(__FUNCTION__, 'Ansagetext: ' . $Announcement, 0);
        $this->SendDebug(__FUNCTION__, 'Der Teilnehmer wird angerufen', 0);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://api.nexxtmobile.de/?mode=user&token=' . $token . '&function=callTTS&originator=' . $originator . '&number=' . rawurlencode($PhoneNumber) . '&text=' . rawurlencode($Announcement) . '&phase=execute&language=',
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
        // Semaphore leave
        IPS_SemaphoreLeave($this->InstanceID . '.ExecuteAlarmCall');
        if ($result) {
            $this->SendDebug(__FUNCTION__, 'Der Teilnehmer wurde angerufen', 0);
        } else {
            $this->SendDebug(__FUNCTION__, 'Fehler, der Teilnehmer konnte nicht angerufen werden!', 0);
        }
        return $result;
    }

    public function DeactivateAlarmCall(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt (' . microtime(true) . ')', 0);
        $this->SetTimerInterval('DeactivateAlarmCall', 0);
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        return $this->ToggleAlarmCall(false, '');
    }

    public function CheckTriggerVariable(int $SenderID, bool $ValueChanged): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wurde vom Sender ' . $SenderID . ' aufgerufen (' . microtime(true) . ')', 0);
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
                                    $message = $variable->Announcement;
                                    $announcement = sprintf($message, GetValueString($variable->AlertingSensor));
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