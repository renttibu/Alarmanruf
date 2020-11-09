<?php

/** @noinspection PhpUnused */

/*
 * @module      Alarmanruf 1 (Variable)
 *
 * @prefix      AA1
 *
 * @file        AA1_alarmCall.php
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

trait AA1_alarmCall
{
    /**
     * Toggles the alarm call off or on.
     *
     * @param bool $State
     * false    = off
     * true     = on
     *
     * @return bool
     * false    = an error occurred
     * true     = successful
     *
     * @throws Exception
     */
    public function ToggleAlarmCall(bool $State): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wurde mit Parameter ' . json_encode($State) . ' aufgerufen (' . microtime(true) . ')', 0);
        $this->DisableTimers();
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        if (!$this->CheckVariable()) {
            return false;
        }
        $result = true;
        $id = $this->ReadPropertyInteger('Variable');
        $actualAlarmCallState = $this->GetValue('AlarmCall');
        //Deactivate
        if (!$State) {
            $this->SendDebug(__FUNCTION__, 'Der Alarmanruf wird beendet', 0);
            IPS_Sleep($this->ReadPropertyInteger('AlarmCallSwitchingDelay'));
            //Semaphore Enter
            if (!IPS_SemaphoreEnter($this->InstanceID . '.ToggleAlarmCall', 5000)) {
                return false;
            }
            $this->SetValue('AlarmCall', false);
            $response = @RequestAction($id, false);
            if (!$response) {
                IPS_Sleep(self::DELAY_MILLISECONDS);
                $response = @RequestAction($id, false);
                if (!$response) {
                    $result = false;
                }
            }
            //Semaphore leave
            IPS_SemaphoreLeave($this->InstanceID . '.ToggleAlarmCall');
            if ($result) {
                $text = 'Der Alarmanruf wurde beendet';
                $this->SendDebug(__FUNCTION__, $text, 0);
                //Protocol
                if ($State != $actualAlarmCallState) {
                    $this->UpdateAlarmProtocol($text . '. (ID ' . $id . ')');
                }
            } else {
                //Revert on failure
                $this->SetValue('AlarmCall', $actualAlarmCallState);
                //Log
                $text = 'Fehler, der Alarmanruf konnte nicht beendet werden!';
                $this->SendDebug(__FUNCTION__, $text, 0);
                $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_ERROR);
                //Protocol
                if ($State != $actualAlarmCallState) {
                    $this->UpdateAlarmProtocol($text . ' (ID ' . $id . ')');
                }
            }
        }
        //Activate
        if ($State) {
            if ($this->CheckNightMode()) {
                return false;
            }
            //Delay
            $delay = $this->ReadPropertyInteger('SwitchOnDelay');
            if ($delay > 0) {
                $this->SetTimerInterval('ActivateAlarmCall', $delay * 1000);
                $unit = 'Sekunden';
                if ($delay == 1) {
                    $unit = 'Sekunde';
                }
                $this->SetValue('AlarmCall', true);
                $text = 'Der Alarmanruf wird in ' . $delay . ' ' . $unit . ' ausgelöst';
                $this->SendDebug(__FUNCTION__, $text, 0);
                if (!$actualAlarmCallState) {
                    //Protocol
                    if ($State != $actualAlarmCallState) {
                        $this->UpdateAlarmProtocol($text . '. (ID ' . $id . ')');
                    }
                }
            }
            //No delay, activate alarm call immediately
            else {
                if ($State != $actualAlarmCallState) {
                    $result = $this->ActivateAlarmCall();
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
        if (!$this->CheckVariable()) {
            return false;
        }
        IPS_Sleep($this->ReadPropertyInteger('AlarmCallSwitchingDelay'));
        //Semaphore Enter
        if (!IPS_SemaphoreEnter($this->InstanceID . '.ActivateAlarmCall', 5000)) {
            return false;
        }
        $this->SendDebug(__FUNCTION__, 'Der Alarmanruf wird ausgelöst', 0);
        $actualAlarmCallState = $this->GetValue('AlarmCall');
        $this->SetValue('AlarmCall', true);
        $result = true;
        $id = $this->ReadPropertyInteger('Variable');
        $response = @RequestAction($id, true);
        if (!$response) {
            IPS_Sleep(self::DELAY_MILLISECONDS);
            $response = @RequestAction($id, true);
            if (!$response) {
                $result = false;
            }
        }
        //Semaphore leave
        IPS_SemaphoreLeave($this->InstanceID . '.ActivateAlarmCall');
        if ($result) {
            $text = 'Der Alarmanruf wurde ausgelöst';
            $this->SendDebug(__FUNCTION__, $text, 0);
            //Protocol
            $this->UpdateAlarmProtocol($text . '. (ID ' . $id . ')');
            //Switch on duration
            $duration = $this->ReadPropertyInteger('SwitchOnDuration');
            $this->SetTimerInterval('DeactivateAlarmCall', $duration * 1000);
            if ($duration > 0) {
                $unit = 'Sekunden';
                if ($duration == 1) {
                    $unit = 'Sekunde';
                }
                $this->SendDebug(__FUNCTION__, 'Einschaltdauer, der Alarmanruf wird in ' . $duration . ' ' . $unit . ' automatisch beendet', 0);
            }
        } else {
            //Revert on failure
            $this->SetValue('AlarmCall', $actualAlarmCallState);
            //Log
            $text = 'Fehler, der Alarmanruf konnte nicht ausgelöst werden!';
            $this->SendDebug(__FUNCTION__, $text, 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_ERROR);
            //Protocol
            $this->UpdateAlarmProtocol($text . ' (ID ' . $id . ')');
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
        return $this->ToggleAlarmCall(false);
    }

    /**
     * Checks the trigger variable.
     *
     * @param int $SenderID
     *
     * @return bool
     * false    = an error occurred
     * true     = successful
     *
     * @throws Exception
     */
    public function CheckTrigger(int $SenderID): bool
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
                $id = $variable->ID;
                if ($SenderID == $id) {
                    if ($variable->Use) {
                        $actualValue = intval(GetValue($id));
                        $this->SendDebug(__FUNCTION__, 'Aktueller Wert: ' . $actualValue, 0);
                        $triggerValue = $variable->TriggerValue;
                        $this->SendDebug(__FUNCTION__, 'Auslösender Wert: ' . $triggerValue, 0);
                        if ($actualValue == $triggerValue) {
                            $triggerAction = $variable->TriggerAction;
                            switch ($triggerAction) {
                                case 0:
                                    $this->SendDebug(__FUNCTION__, 'Aktion: Alarmanruf beenden', 0);
                                    $result = $this->ToggleAlarmCall(false);
                                    break;

                                case 1:
                                    $this->SendDebug(__FUNCTION__, 'Aktion: Alarmanruf auslösen', 0);
                                    $result = $this->ToggleAlarmCall(true);
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
     * Checks for an existing variable.
     *
     * @return bool
     * false    = no variable
     * true     = ok
     */
    private function CheckVariable(): bool
    {
        $id = $this->ReadPropertyInteger('Variable');
        if ($id == 0 || @!IPS_ObjectExists($id)) {
            $text = 'Abbruch, es ist kein Variable ausgewählt!';
            $this->SendDebug(__FUNCTION__, $text, 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_WARNING);
            return false;
        }
        return true;
    }
}