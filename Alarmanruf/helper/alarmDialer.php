<?php

// Declare
declare(strict_types=1);

trait AANR_alarmDialer
{
    /**
     * Triggers the alarm dialer.
     *
     */
    private function TriggerAlarmDialer(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wurde aufgerufen.', 0);
        if (!$this->ReadPropertyBoolean('UseAlarmDialer')) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Das Wählgerät wird nicht verwendet.', 0);
            return;
        }
        $id = $this->ReadPropertyInteger('AlarmDialer');
        if ($id != 0 && @IPS_ObjectExists($id)) {
            $impulseDuration = $this->ReadPropertyInteger('ImpulseDuration');
            $type = $this->ReadPropertyInteger('AlarmDialerType');
            $execute = true;
            switch ($type) {
                // Variable
                case 1:
                    $execute = @RequestAction($id, true);
                    if ($impulseDuration > 0) {
                        @IPS_Sleep($impulseDuration * 1000);
                        @RequestAction($id, false);
                    }
                    break;

                // Script
                case 2:
                    $execute = @IPS_RunScriptEx($id, ['ImpulseDuration' => $impulseDuration]);
                    break;

            }
            // Log & Debug
            if (!$execute) {
                $text = 'Der Alarmanruf konnte mittels Wählgerät nicht ausgelöst werden. (ID ' . $id . ')';
                $this->LogMessage(__FUNCTION__ . ', ' . $text, KL_ERROR);
            } else {
                $text = 'Der Alarmanruf wurde mittels Wählgerät ausgelöst. (ID ' . $id . ')';
            }
            $this->SendDebug(__FUNCTION__, $text, 0);
            // Protocol
            $this->UpdateProtocol($text);
        }
    }
}
