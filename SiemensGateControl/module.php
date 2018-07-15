<?php

/*
 * @module      Siemens Gate Control
 *
 * @file		module.php
 *
 * @author		Ulrich Bittner
 * @copyright	(c) 2018
 * @license     CC BY-NC-SA 4.0
 *
 * @version		1.00
 * @date		2018-07-13, 10:00
 * @lastchange  2018-07-13, 10:00
 *
 * @see			https://github.com/ubittner/SymconSiemensGateControl.git
 *
 * @guids		Library
 * 				{4FC5B109-BFF2-4C62-8168-76C913362BEF}
 *
 *              Module
 *              {B527EBF7-DAC4-47A7-8DF3-4250C7CF36A6}
 *
 * @changelog	2018-07-13, 10:00, initial module script version 1.00
 *
 */

// Definitions
if (!defined('IPS_BASE')) {
    define('IPS_BASE', 10000);
}
if (!defined('IPS_KERNELMESSAGE')) {
    define('IPS_KERNELMESSAGE', IPS_BASE + 100);
}
if (!defined('KR_READY')) {
    define('KR_READY', IPS_BASE + 103);
}
if (!defined('WEBFRONT_GUID')) {
    define('WEBFRONT_GUID', '{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}');
}

class SiemensGateControl extends IPSModule
{
    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Register properties
        $this->RegisterPropertyString('Description', '');
        $this->RegisterPropertyInteger('ClientSocket', 0);
        $this->RegisterPropertyInteger('Timeout', 1000);
        $this->RegisterPropertyString('SwitchingType', 'Open');
        $this->RegisterPropertyInteger('MerkerClose', 0);
        $this->RegisterPropertyInteger('MerkerOpen', 0);
        $this->RegisterPropertyBoolean('UseSwitchingImpulse', false);
        $this->RegisterPropertyInteger('ImpulseDuration', 1000);
        $this->RegisterPropertyBoolean('UseNotification', false);
        $this->RegisterPropertyInteger('WebFront', 0);

        // Register timer
        $this->RegisterTimer('SwitchingImpulse', 0, 'SGC_TerminateSwitchingImpulse($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        // Never delete this line!
        parent::ApplyChanges();

        // Delete profiles first
        $this->DeleteProfiles();

        // Check switching profile
        $switchingType = $this->ReadPropertyString('SwitchingType');
        switch ($switchingType) {
            case 'Close':
                // Create variable profile
                IPS_CreateVariableProfile('SGC.MerkerVariableInteger.' . $this->InstanceID, 1);
                IPS_SetVariableProfileValues('SGC.MerkerVariableInteger.' . $this->InstanceID, 0, 0, 0);
                IPS_SetVariableProfileIcon('SGC.MerkerVariableInteger.' . $this->InstanceID, '');
                IPS_SetVariableProfileAssociation('SGC.MerkerVariableInteger.' . $this->InstanceID, 0, $this->Translate('Close'), 'LockClosed', 0xFF0000);

                // Register variable
                $this->RegisterVariableInteger('MerkerVariable', 'Merker', 'SGC.MerkerVariableInteger.' . $this->InstanceID, 1);
                $this->EnableAction('MerkerVariable');
                SetValue($this->GetIDForIdent('MerkerVariable'), 0);
                break;

            case 'Open':
                // Create variable profile
                IPS_CreateVariableProfile('SGC.MerkerVariableInteger.' . $this->InstanceID, 1);
                IPS_SetVariableProfileValues('SGC.MerkerVariableInteger.' . $this->InstanceID, 1, 1, 0);
                IPS_SetVariableProfileIcon('SGC.MerkerVariableInteger.' . $this->InstanceID, '');
                IPS_SetVariableProfileAssociation('SGC.MerkerVariableInteger.' . $this->InstanceID, 1, $this->Translate('Open'), 'LockOpen', 0x00FF00);

                // Register variable
                $this->RegisterVariableInteger('MerkerVariable', 'Merker', 'SGC.MerkerVariableInteger.' . $this->InstanceID, 1);
                $this->EnableAction('MerkerVariable');
                SetValue($this->GetIDForIdent('MerkerVariable'), 1);
                break;

            case 'Close / Open':
                // Create variable profile
                IPS_CreateVariableProfile('SGC.MerkerVariableBoolean.' . $this->InstanceID, 0);
                IPS_SetVariableProfileIcon('SGC.MerkerVariableBoolean.' . $this->InstanceID, '');
                IPS_SetVariableProfileAssociation('SGC.MerkerVariableBoolean.' . $this->InstanceID, 0, $this->Translate('Close'), 'LockClosed', 0xFF0000);
                IPS_SetVariableProfileAssociation('SGC.MerkerVariableBoolean.' . $this->InstanceID, 1, $this->Translate('Open'), 'LockOpen', 0x00FF00);

                // Register variable
                $this->RegisterVariableBoolean('MerkerVariable', 'Merker', 'SGC.MerkerVariableBoolean.' . $this->InstanceID, 1);
                $this->EnableAction('MerkerVariable');
                SetValue($this->GetIDForIdent('MerkerVariable'), false);
                break;
        }

        // Register messages
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        if (IPS_GetKernelRunlevel() == KR_READY) {

            // Check configuration
            $this->ValidateConfiguration();

            // Disable timer
            $this->SetTimerInterval('SwitchingImpulse', 0);
        }
    }

    public function Destroy()
    {
        // Never delete this line!
        parent::Destroy();

        $this->DeleteProfiles();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message) {
            case IPS_KERNELMESSAGE:
                if ($Data[0] == KR_READY) {
                    $this->ApplyChanges();
                }
                break;
        }
    }

    //#################### Request action

    public function RequestAction($Ident, $Value)
    {
        try {
            switch ($Ident) {
                case 'MerkerVariable':
                    $this->ToggleMerkerVariable($Value);
                    break;
                default:
                    throw new Exception('Invalid Ident');
            }
        } catch (Exception $e) {
            IPS_LogMessage('SGC', $e->getMessage());
        }
    }

    //#################### Public

    /**
     * Toggles the merker variable.
     *
     * @param bool $State
     */
    public function ToggleMerkerVariable(bool $State)
    {
        // Set state first
        SetValue($this->GetIDForIdent('MerkerVariable'), $State);

        // Toggle merker
        $clientSocket = $this->ReadPropertyInteger('ClientSocket');
        if (!empty($clientSocket)) {
            $ipAddress = IPS_GetProperty($clientSocket, 'Host');
            $timeout = $this->ReadPropertyInteger('Timeout');
            $state = false;
            if (!empty($ipAddress)) {
                if ($timeout && Sys_Ping($ipAddress, $timeout) == true) {
                    switch ($State) {
                        case false:
                            $merker = $this->ReadPropertyInteger('MerkerClose');
                            $merkerType = 'Close';
                            $this->SetBuffer('MerkerType', $merkerType);
                            break;
                        case true:
                            $merker = $this->ReadPropertyInteger('MerkerOpen');
                            $merkerType = 'Open';
                            $this->SetBuffer('MerkerType', $merkerType);
                    }
                    if (!empty($merker)) {
                        $toggleMerker = S7_WriteBit($merker, true);
                        if ($toggleMerker == true) {
                            $interval = $this->ReadPropertyInteger('ImpulseDuration');
                            if ($interval > 0) {
                                $this->SetTimerInterval('SwitchingImpulse', $interval);
                            }
                            $state = true;
                        }
                    }
                }
            }
            $useNotification = $this->ReadPropertyBoolean('UseNotification');
            if ($useNotification == true) {
                $webFront = $this->ReadPropertyInteger('WebFront');
                if ($webFront != 0) {
                    $title = $this->ReadPropertyString('Description');
                    if ($state == true) {
                        if ($State == false) {
                            $text = 'will be closed!';
                        } else {
                            $text = 'will be opened';
                        }
                        WFC_SendNotification($webFront, $title, $text, 'Information', 5);
                    } else {
                        WFC_SendNotification($webFront, $title, 'There was an error during the switching process!', 'Warning', 5);
                    }
                }
            }
        }
    }

    /**
     * Terminates the switching impulse.
     */
    public function TerminateSwitchingImpulse()
    {
        // Disable timer
        $this->SetTimerInterval('SwitchingImpulse', 0);

        // Get merker type
        $merkerType = $this->GetBuffer('MerkerType');
        if (!empty($merkerType)) {
            switch ($merkerType) {
                case 'Close':
                    // Reset merker type buffer
                    $this->SetBuffer('MerkerType', '');
                    $merker = $this->ReadPropertyInteger('MerkerClose');
                    break;
                case 'Open':
                    // Reset merker type buffer
                    $this->SetBuffer('MerkerType', '');
                    $merker = $this->ReadPropertyInteger('MerkerOpen');
                    break;
            }
            if (!empty($merker)) {
                $toggleMerker = S7_WriteBit($merker, false);
                if ($toggleMerker == false) {
                    $useNotification = $this->ReadPropertyBoolean('UseNotification');
                    if ($useNotification == true) {
                        $webFront = $this->ReadPropertyInteger('WebFront');
                        if (!empty($webFront)) {
                            $title = $this->ReadPropertyString('Description');
                            WFC_SendNotification($webFront, $title, 'There was an error during the switching process!', 'Warning', 5);
                        }
                    }
                }
            }
        }
    }

    //#################### Private

    /**
     * Validates the configuration.
     */
    private function ValidateConfiguration()
    {
        $this->SetStatus(102);

        // Check notification
        $useNotification = $this->ReadPropertyBoolean('UseNotification');
        if ($useNotification == true) {
            $webFrontID = $this->ReadPropertyInteger('WebFront');
            if ($webFrontID == 0 || IPS_GetInstance($webFrontID)['ModuleInfo']['ModuleID'] != WEBFRONT_GUID) {
                $this->SetStatus(2521);
            }
        }

        // Check switching impulse
        $useImpulse = $this->ReadPropertyBoolean('UseSwitchingImpulse');
        $impulseDuration = $this->ReadPropertyInteger('ImpulseDuration');
        if ($useImpulse == true && $impulseDuration <= 0) {
            $this->SetStatus(2421);
        }

        // Check merker
        $switchingType = $this->ReadPropertyString('SwitchingType');
        $merkerClose = $this->ReadPropertyInteger('MerkerClose');
        $merkerOpen = $this->ReadPropertyInteger('MerkerOpen');
        if ($switchingType == 'Close') {
            if ($merkerClose == 0) {
                $this->SetStatus(2321);
            }
        }
        if ($switchingType == 'Open') {
            if ($merkerOpen == 0) {
                $this->SetStatus(2331);
            }
        }
        if ($switchingType == 'Close / Open') {
            if ($merkerClose == 0 || $merkerOpen == 0) {
                $this->SetStatus(2341);
            }
        }

        // Check socket
        if ($this->ReadPropertyInteger('ClientSocket') == 0) {
            $this->SetStatus(2211);
        }

        // Set description
        $description = $this->ReadPropertyString('Description');
        if ($description == '') {
            $this->SetStatus(2111);
        } else {
            // Rename instance
            IPS_SetName($this->InstanceID, $description);
            // Rename merker variable
            IPS_SetName($this->GetIDForIdent('MerkerVariable'), $this->ReadPropertyString('Description'));
        }
    }

    /**
     * Deletes the profiles.
     */
    private function DeleteProfiles()
    {
        if (IPS_VariableProfileExists('SGC.MerkerVariableBoolean.' . $this->InstanceID)) {
            IPS_DeleteVariableProfile('SGC.MerkerVariableBoolean.' . $this->InstanceID);
        }
        if (IPS_VariableProfileExists('SGC.MerkerVariableInteger.' . $this->InstanceID)) {
            IPS_DeleteVariableProfile('SGC.MerkerVariableInteger.' . $this->InstanceID);
        }
    }
}