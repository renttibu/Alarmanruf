<?php

/*
 * @author      Ulrich Bittner
 * @copyright   (c) 2020, 2021
 * @license    	CC BY-NC-SA 4.0
 * @see         https://github.com/ubittner/Alarmanruf/tree/master/VoIP
 */

/** @noinspection PhpUnusedPrivateMethodInspection */
/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */

declare(strict_types=1);

trait AAVOIP_nightMode
{
    public function ToggleNightMode(bool $State): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wurde mit Parameter ' . json_encode($State) . ' aufgerufen (' . microtime(true) . ')', 0);
        $result = false;
        if ($this->CheckMaintenanceMode()) {
            return $result;
        }
        $result = true;
        $stateText = 'ausgeschaltet';
        if ($State) {
            $stateText = 'eingeschaltet';
        }
        $this->SendDebug(__FUNCTION__, 'Der Nachtmodus wird ' . $stateText, 0);
        $actualNightMode = $this->GetValue('NightMode');
        $this->SetValue('NightMode', $State);
        // Night mode on
        if ($State) {
            $toggle = $this->ToggleAlarmCall(false, '');
            if (!$toggle) {
                $result = false;
                // Revert value
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

    public function StartNightMode(): void
    {
        $this->ToggleNightMode(true);
        $this->SetNightModeTimer();
    }

    public function StopNightMode(): void
    {
        $this->ToggleNightMode(false);
        $this->SetNightModeTimer();
    }

    #################### Private

    private function SetNightModeTimer(): void
    {
        $use = $this->ReadPropertyBoolean('UseAutomaticNightMode');
        // Start
        $milliseconds = 0;
        if ($use) {
            $milliseconds = $this->GetInterval('NightModeStartTime');
        }
        $this->SetTimerInterval('StartNightMode', $milliseconds);
        // End
        $milliseconds = 0;
        if ($use) {
            $milliseconds = $this->GetInterval('NightModeEndTime');
        }
        $this->SetTimerInterval('StopNightMode', $milliseconds);
    }

    private function GetInterval(string $TimerName): int
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
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

    private function CheckAutomaticNightMode(): bool
    {
        if (!$this->ReadPropertyBoolean('UseAutomaticNightMode')) {
            return false;
        }
        $start = $this->GetTimerInterval('StartNightMode');
        $stop = $this->GetTimerInterval('StopNightMode');
        if ($start > $stop) {
            $this->ToggleNightMode(true);
            return true;
        } else {
            $this->ToggleNightMode(false);
            return false;
        }
    }

    private function CheckNightMode(): bool
    {
        $nightMode = boolval($this->GetValue('NightMode'));
        if ($nightMode) {
            $message = 'Abbruch, der Nachtmodus ist aktiv!';
            $this->SendDebug(__FUNCTION__, $message, 0);
        }
        return $nightMode;
    }
}