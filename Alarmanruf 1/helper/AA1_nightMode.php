<?php

/** @noinspection PhpUnusedPrivateMethodInspection */
/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */

/*
 * @module      Alarmanruf 1 (Variable)
 *
 * @prefix      AA1
 *
 * @file        AA1_nightMode.php
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

trait AA1_nightMode
{
    /**
     * Toggles the night mode off or on.
     *
     * @param bool $State
     * false    = off
     * true     = on
     *
     * @return bool
     * false    = an error occurred
     * true     = successful
     */
    public function ToggleNightMode(bool $State): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wurde mit Parameter ' . json_encode($State) . ' aufgerufen (' . microtime(true) . ')', 0);
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        $result = true;
        $stateText = 'ausgeschaltet';
        if ($State) {
            $stateText = 'eingeschaltet';
        }
        $this->SendDebug(__FUNCTION__, 'Der Nachtmodus wird ' . $stateText, 0);
        $actualNightMode = $this->GetValue('NightMode');
        $this->SetValue('NightMode', $State);
        //Night mode on
        if ($State) {
            $toggle = $this->ToggleAlarmCall(false);
            if (!$toggle) {
                $result = false;
                //Revert value
                $this->SetValue('NightMode', $actualNightMode);
            }
        }
        if ($result) {
            $this->SendDebug(__FUNCTION__, 'Der Nachtmodus wurde ' . $stateText, 0);
        } else {
            $this->SendDebug(__FUNCTION__, 'Der Nachtmodus konnte nicht ' . $stateText . 'werden', 0);
        }
        return $result;
    }

    /**
     * Starts the night mode, used by timer.
     */
    public function StartNightMode(): void
    {
        $this->ToggleNightMode(true);
        $this->SetNightModeTimer();
    }

    /**
     * Stops the night mode, used by timer.
     */
    public function StopNightMode(): void
    {
        $this->ToggleNightMode(false);
        $this->SetNightModeTimer();
    }

    #################### Private

    /**
     * Sets the timer interval for the automatic night mode.
     */
    private function SetNightModeTimer(): void
    {
        $use = $this->ReadPropertyBoolean('UseAutomaticNightMode');
        //Start
        $milliseconds = 0;
        if ($use) {
            $milliseconds = $this->GetInterval('NightModeStartTime');
        }
        $this->SetTimerInterval('StartNightMode', $milliseconds);
        //End
        $milliseconds = 0;
        if ($use) {
            $milliseconds = $this->GetInterval('NightModeEndTime');
        }
        $this->SetTimerInterval('StopNightMode', $milliseconds);
    }

    /**
     * Gets the interval for a timer.
     *
     * @param string $TimerName
     *
     * @return int
     */
    private function GetInterval(string $TimerName): int
    {
        $timer = json_decode($this->ReadPropertyString($TimerName));
        $now = time();
        $hour = $timer->hour;
        $minute = $timer->minute;
        $second = $timer->second;
        $definedTime = $hour . ':' . $minute . ':' . $second;
        if (time() >= strtotime($definedTime)) {
            $timestamp = mktime($hour, $minute, $second, (int) date('n'), (int) date('j') + 1, (int) date('Y'));
        } else {
            $timestamp = mktime($hour, $minute, $second, (int) date('n'), (int) date('j'), (int) date('Y'));
        }
        return ($timestamp - $now) * 1000;
    }

    /**
     * Checks the state of the automatic night mode.
     */
    private function CheckAutomaticNightMode(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt (' . microtime(true) . ')', 0);
        if (!$this->ReadPropertyBoolean('UseAutomaticNightMode')) {
            return;
        }
        $start = $this->GetTimerInterval('StartNightMode');
        $stop = $this->GetTimerInterval('StopNightMode');
        if ($start > $stop) {
            $this->ToggleNightMode(true);
        } else {
            $this->ToggleNightMode(false);
        }
    }

    /**
     * Checks if the night mode is off or on.
     *
     * @return bool
     * false    = off
     * true     = on
     */
    private function CheckNightMode(): bool
    {
        $nightMode = $this->GetValue('NightMode');
        if ($nightMode) {
            $message = 'Abbruch, der Nachtmodus ist aktiv!';
            $this->SendDebug(__FUNCTION__, $message, 0);
        }
        return $nightMode;
    }
}