<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/Validator.php';

class AlarmanrufValidationTest extends TestCaseSymconValidation
{
    public function testValidateAlarmanruf(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }

    public function testValidateAlarmanruf1Module(): void
    {
        $this->validateModule(__DIR__ . '/../Alarmanruf 1');
    }

    public function testValidateAlarmanruf2Module(): void
    {
        $this->validateModule(__DIR__ . '/../Alarmanruf 2');
    }
}