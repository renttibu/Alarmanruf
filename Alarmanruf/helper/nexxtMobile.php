<?php

// Declare
declare(strict_types=1);

trait AANR_nexxtMobile
{
    /**
     * Gets the current balance.
     *
     * @param bool $State
     */
    public function GetCurrentBalance(bool $State): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wurde aufgerufen.', 0);
        $this->SetValue('GetCurrentBalance', false);
        if ($State) {
            if (!$this->ReadPropertyBoolean('UseNexxtMobile')) {
                $this->SendDebug(__FUNCTION__, 'Abbruch, Nexxt Mobile wird nicht verwendet.', 0);
                return;
            }
            $token = $this->ReadPropertyString('NexxtMobileToken');
            if (empty($token)) {
                return;
            } else {
                $token = rawurlencode($token);
            }
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => 'https://api.nexxtmobile.de/?mode=user&token=' . $token . '&function=getBalance',
                CURLOPT_HEADER         => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FAILONERROR    => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 60]);
            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                $error_msg = curl_error($ch);
            }
            if ($response) {
                $this->SendDebug('Data', $response, 0);
                $data = json_decode($response, true);
                if (array_key_exists('result', $data)) {
                    if (array_key_exists('balanceFormated', $data['result'])) {
                        $this->SetValue('CurrentBalance', $data['result']['balanceFormated'] . ' €');
                    }
                }
            }
            curl_close($ch);
            if (isset($error_msg)) {
                $this->SendDebug('Data', 'An error has occurred: ' . json_encode($error_msg), 0);
            }
        }
    }

    //#################### Private

    /**
     * Executes an alarm call via Nexxt Mobile service.
     *
     * @param string $SensorName
     */
    private function ExecuteNexxtMobileAlarmCall(string $SensorName): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wurde aufgerufen.', 0);
        if (!$this->ReadPropertyBoolean('UseNexxtMobile')) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Nexxt Mobile soll nicht verwendet werden.', 0);
            return;
        }
        // Check config
        $token = $this->ReadPropertyString('NexxtMobileToken');
        if (empty($token)) {
            return;
        } else {
            $token = rawurlencode($token);
        }
        $originator = $this->ReadPropertyString('NexxtMobileOriginator');
        if (empty($originator) || strlen($originator) <= 3) {
            return;
        } else {
            $originator = rawurlencode($originator);
        }
        // Update balance
        $this->GetCurrentBalance(true);
        // Check recipients
        $count = $this->CheckExistingRecipient();
        if ($count == 0) {
            return;
        }
        $recipients = json_decode($this->ReadPropertyString('NexxtMobileRecipients'));
        if (empty($SensorName)) {
            $alarmText = 'Alarmierung, es wurde ein Alarm ausgelöst.     Bitte prüfen!     Ich wiederhole     es wurde ein Alarm ausgelöst.';
        } else {
            $alarmText = 'Alarmierung es wurde ein Alarm ausgelöst     ' . $SensorName . ' hat ausgelöst     Bitte prüfen!     Ich wiederhole     ' . $SensorName . ' hat ausgelöst!';
        }
        // Execute alarm call
        $success = true;
        foreach ($recipients as $recipient) {
            $phoneNumber = $recipient->PhoneNumber;
            if ($recipient->Use && strlen($phoneNumber) > 3) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL            => 'https://api.nexxtmobile.de/?mode=user&token=' . $token . '&function=callTTS&originator=' . $originator . '&number=' . rawurlencode($phoneNumber) . '&text=' . rawurlencode($alarmText) . '&phase=execute&language=',
                    CURLOPT_HEADER         => false,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FAILONERROR    => true,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT        => 60]);
                $response = curl_exec($ch);
                if (curl_errno($ch)) {
                    $error_msg = curl_error($ch);
                }
                if ($response) {
                    $this->SendDebug('Data', $response, 0);
                    $data = json_decode($response, true);
                    if (array_key_exists('isError', $data)) {
                        $error = $data['isError'];
                        if ($error) {
                            $success = false;
                        }
                    }
                    if (array_key_exists('result', $data)) {
                        if (array_key_exists('currentBalanceFormated', $data['result'])) {
                            $this->SetValue('CurrentBalance', $data['result']['currentBalanceFormated'] . ' €');
                        }
                    }
                }
                curl_close($ch);
                if (isset($error_msg)) {
                    $this->SendDebug('Data', 'An error has occurred: ' . json_encode($error_msg), 0);
                }
            }
        }
        // Log & Debug
        if (!$success) {
            $text = 'Der Alarmanruf konnte mittels Nexxt Mobile nicht ausgelöst werden. (ID ' . $this->InstanceID . ')';
            $this->LogMessage(__FUNCTION__ . ', ' . $text, KL_ERROR);
        } else {
            $text = 'Der Alarmanruf wurde mittels Nexxt Mobile ausgelöst. (ID ' . $this->InstanceID . ')';
        }
        $this->SendDebug(__FUNCTION__, $text, 0);
        // Protocol
        $this->UpdateProtocol($text);
    }

    /**
     * Checks for an existing recipient.
     *
     * @return int
     * Amount of used recipients.
     */
    private function CheckExistingRecipient(): int
    {
        $amount = 0;
        // Check recipients, abort if no recipients are defined
        $recipients = json_decode($this->ReadPropertyString('NexxtMobileRecipients'));
        if (empty($recipients)) {
            return $amount;
        } else {
            // Check if at least one recipient is used
            foreach ($recipients as $recipient) {
                if ($recipient->Use) {
                    $amount++;
                }
            }
        }
        return $amount;
    }
}