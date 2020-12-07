<?php

/** @noinspection PhpUnusedPrivateMethodInspection */
/** @noinspection PhpUndefinedFunctionInspection */
/** @noinspection DuplicatedCode */

/*
 * @module      Alarmanruf 1 (Variable)
 *
 * @prefix      AA1
 *
 * @file        AA1_alarmProtocol.php
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

trait AA1_alarmProtocol
{
    #################### Private

    /**
     * Updates the alarm protocol.
     *
     * @param string $Message
     */
    private function UpdateAlarmProtocol(string $Message): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt (' . microtime(true) . ')', 0);
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        $id = $this->ReadPropertyInteger('AlarmProtocol');
        if ($id == 0 || !@IPS_ObjectExists($id)) {
            return;
        }
        $timestamp = date('d.m.Y, H:i:s');
        $logText = $timestamp . ', ' . $Message;
        $logType = 0;
        @AP_UpdateMessages($id, $logText, $logType);
        /*
        $protocol = 'AP_UpdateMessages(' . $id . ', "' . $logText . '", ' . $logType . ');';
        @IPS_RunScriptText($protocol);
         */
    }
}