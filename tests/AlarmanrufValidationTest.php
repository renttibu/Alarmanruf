<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/Validator.php';

class AlarmanrufValidationTest extends TestCaseSymconValidation
{
    public function testValidateLibrary_Alarmanruf(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }

    public function testValidateModule_Alarmanruf(): void
    {
        $this->validateModule(__DIR__ . '/../Alarmanruf');
    }

    public function testValidateModule_NeXXtMobile(): void
    {
        $this->validateModule(__DIR__ . '/../NeXXt Mobile');
    }

    public function testValidateModule_VoiP(): void
    {
        $this->validateModule(__DIR__ . '/../VoIP');
    }
}