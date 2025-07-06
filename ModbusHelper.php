<?php

/**
 * ABOUTME: Modbus protocol helper functions and register definitions
 * ABOUTME: Provides Modbus command generation and register mappings for device control
 */
class ModbusHelper {
    
    // Modbus registers
    const REGISTER_MODBUS_ADDRESS = 17;
    const REGISTER_MODBUS_COUNT = 80;
    const REGISTER_TOTAL_INPUT = 6;
    const REGISTER_TOTAL_OUTPUT = 39;
    const REGISTER_ACTIVE_OUTPUT_LIST = 41;
    const REGISTER_STATE_OF_CHARGE = 56;
    const REGISTER_MAXIMUM_CHARGING_CURRENT = 20;
    const REGISTER_USB_OUTPUT = 24;
    const REGISTER_DC_OUTPUT = 25;
    const REGISTER_AC_OUTPUT = 26;
    const REGISTER_LED = 27;
    const REGISTER_AC_SILENT_CHARGING = 57;
    const REGISTER_USB_STANDBY_TIME = 59;
    const REGISTER_AC_STANDBY_TIME = 60;
    const REGISTER_DC_STANDBY_TIME = 61;
    const REGISTER_SCREEN_REST_TIME = 62;
    const REGISTER_STOP_CHARGE_AFTER = 63;
    const REGISTER_DISCHARGE_LIMIT = 66;
    const REGISTER_CHARGING_LIMIT = 67;
    const REGISTER_SLEEP_TIME = 68;
    
    private static $registers = [
        self::REGISTER_MAXIMUM_CHARGING_CURRENT => [
            'name' => 'Maximum charging current setting',
            'unit' => 'int',
            'possibleValues' => [1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20]
        ],
        self::REGISTER_USB_OUTPUT => [
            'name' => 'USB Output',
            'unit' => 'boolean',
            'possibleValues' => [true, false]
        ],
        self::REGISTER_DC_OUTPUT => [
            'name' => 'DC Output',
            'unit' => 'boolean',
            'possibleValues' => [true, false]
        ],
        self::REGISTER_AC_OUTPUT => [
            'name' => 'AC Output',
            'unit' => 'boolean',
            'possibleValues' => [true, false]
        ],
        self::REGISTER_LED => [
            'name' => 'LED light',
            'unit' => 'int',
            'possibleValues' => [0,1,2,3],
            'description' => '0 = disabled, 1 = Always, 2 = SOS, 3 = Flash'
        ],
        self::REGISTER_AC_SILENT_CHARGING => [
            'name' => 'AC silent charging',
            'unit' => 'boolean',
            'possibleValues' => [true, false]
        ],
        self::REGISTER_USB_STANDBY_TIME => [
            'name' => 'USB standby time',
            'unit' => 'int',
            'possibleValues' => [0,3,5,10,30],
            'description' => 'minutes'
        ],
        self::REGISTER_AC_STANDBY_TIME => [
            'name' => 'AC standby time',
            'unit' => 'int',
            'possibleValues' => [0,480,960,1440],
            'description' => 'minutes'
        ],
        self::REGISTER_DC_STANDBY_TIME => [
            'name' => 'DC standby time',
            'unit' => 'int',
            'possibleValues' => [0,480,960,1440],
            'description' => 'minutes'
        ],
        self::REGISTER_SCREEN_REST_TIME => [
            'name' => 'Screen rest time',
            'unit' => 'int',
            'possibleValues' => [0,180,300,600,1800],
            'description' => 'seconds'
        ],
        self::REGISTER_STOP_CHARGE_AFTER => [
            'name' => 'Stop charge after',
            'unit' => 'int',
            'description' => 'minutes'
        ],
        self::REGISTER_DISCHARGE_LIMIT => [
            'name' => 'Discharge lower limit',
            'unit' => 'permille',
            'description' => 'Divide by 10 for 5-50% (app-safe range)',
            'min' => 50,   // 5%
            'max' => 500   // 50%
        ],
        self::REGISTER_CHARGING_LIMIT => [
            'name' => 'AC charging upper limit in EPS mode',
            'unit' => 'permille',
            'description' => 'Divide by 10 for 60-100% (app-safe range)',
            'min' => 600,  // 60%
            'max' => 1000  // 100%
        ],
        self::REGISTER_SLEEP_TIME => [
            'name' => 'Whole machine unused time',
            'unit' => 'int',
            'possibleValues' => [5,10,30,480],
            'description' => 'minutes - WARNING: Value 0 WILL BRICK THE DEVICE!'
        ]
    ];
    
    public static function intToHighLow($value) {
        return [
            'low' => $value & 255,
            'high' => ($value >> 8) & 255
        ];
    }
    
    public static function highLowToInt($high, $low) {
        return (($high & 255) << 8) | ($low & 255);
    }
    
    private static function crc16($data) {
        $crc = 0xFFFF;
        foreach ($data as $byte) {
            $crc ^= $byte;
            for ($i = 0; $i < 8; $i++) {
                if ($crc & 1) {
                    $crc = ($crc >> 1) ^ 0xA001;
                } else {
                    $crc >>= 1;
                }
            }
        }
        return $crc & 0xFFFF;
    }
    
    private static function zi($value) {
        return [
            'low' => $value & 255,
            'high' => ($value >> 8) & 255
        ];
    }
    
    private static function sa($address, $function, $data, $reverse = false) {
        $frame = array_merge([$address, $function], $data);
        $crc = self::zi(self::crc16($frame));
        
        if ($reverse) {
            $frame[] = $crc['low'];
            $frame[] = $crc['high'];
        } else {
            $frame[] = $crc['high'];
            $frame[] = $crc['low'];
        }
        
        return $frame;
    }
    
    public static function getWriteModbus($address, $register, $value) {
        $highLow = self::intToHighLow($value);
        $registerHighLow = self::zi($register);
        
        return self::sa($address, 6, [
            $registerHighLow['high'],
            $registerHighLow['low'],
            $highLow['high'],
            $highLow['low']
        ], false);
    }
    
    public static function getReadModbus($address, $count) {
        $registerHighLow = self::zi(0);
        $countLow = $count & 255;
        $countHigh = ($count >> 8) & 255;
        
        return self::sa($address, 3, [
            $registerHighLow['high'],
            $registerHighLow['low'],
            $countHigh,
            $countLow
        ], false);
    }
    
    public static function validateRegisterValue($register, $value) {
        if (!isset(self::$registers[$register])) {
            throw new Exception("CRITICAL: Unknown register $register - blocking to prevent device damage!");
        }
        
        $regInfo = self::$registers[$register];
        $originalValue = $value;
        
        switch ($regInfo['unit']) {
            case 'permille':
                $value = intval($value);
                
                // Check for app-safe ranges if defined
                if (isset($regInfo['min']) && isset($regInfo['max'])) {
                    if ($value < $regInfo['min'] || $value > $regInfo['max']) {
                        throw new Exception("CRITICAL: Invalid permille value $originalValue for register '{$regInfo['name']}'. Must be {$regInfo['min']}-{$regInfo['max']} (app-safe range). BLOCKING to prevent device damage!");
                    }
                } else {
                    if ($value < 0 || $value > 1000) {
                        throw new Exception("CRITICAL: Invalid permille value $originalValue for register '{$regInfo['name']}'. Must be 0-1000. BLOCKING to prevent device damage!");
                    }
                }
                
                if (!($value % 10 == 0 || $value % 5 == 0)) {
                    throw new Exception("CRITICAL: Invalid permille value $originalValue for register '{$regInfo['name']}'. Must be divisible by 5. BLOCKING to prevent device damage!");
                }
                break;
                
            case 'int':
                $value = intval($value);
                if (isset($regInfo['possibleValues'])) {
                    if (!in_array($value, $regInfo['possibleValues'], true)) {
                        throw new Exception("CRITICAL: Invalid value $originalValue for register '{$regInfo['name']}'. Allowed values: " . implode(', ', $regInfo['possibleValues']) . ". BLOCKING to prevent device damage!");
                    }
                } else {
                    if ($value < 0) {
                        throw new Exception("CRITICAL: Invalid value $originalValue for register '{$regInfo['name']}'. Must be >= 0. BLOCKING to prevent device damage!");
                    }
                }
                break;
                
            case 'boolean':
                if (!in_array($value, [true, false, 0, 1], true)) {
                    throw new Exception("CRITICAL: Invalid boolean value $originalValue for register '{$regInfo['name']}'. Must be true/false or 0/1. BLOCKING to prevent device damage!");
                }
                $value = $value ? 1 : 0;
                break;
                
            default:
                throw new Exception("CRITICAL: Unknown unit type '{$regInfo['unit']}' for register '{$regInfo['name']}'. BLOCKING to prevent device damage!");
        }
        
        return ['valid' => true, 'value' => $value];
    }
    
    // Convenience methods for common commands
    public static function getRequestSettingsCommand() {
        return self::getReadModbus(self::REGISTER_MODBUS_ADDRESS, self::REGISTER_MODBUS_COUNT);
    }
    
    public static function getMaxChargeCurrentCommand($value) {
        $validation = self::validateRegisterValue(self::REGISTER_MAXIMUM_CHARGING_CURRENT, $value);
        return self::getWriteModbus(self::REGISTER_MODBUS_ADDRESS, self::REGISTER_MAXIMUM_CHARGING_CURRENT, $validation['value']);
    }
    
    public static function getChargeUpperLimitCommand($value) {
        $validation = self::validateRegisterValue(self::REGISTER_CHARGING_LIMIT, $value);
        return self::getWriteModbus(self::REGISTER_MODBUS_ADDRESS, self::REGISTER_CHARGING_LIMIT, $validation['value']);
    }
    
    public static function getStopChargeAfterCommand($value) {
        $validation = self::validateRegisterValue(self::REGISTER_STOP_CHARGE_AFTER, $value);
        return self::getWriteModbus(self::REGISTER_MODBUS_ADDRESS, self::REGISTER_STOP_CHARGE_AFTER, $validation['value']);
    }
    
    public static function getUSBOutputCommand($enable) {
        $value = $enable ? 1 : 0;
        return self::getWriteModbus(self::REGISTER_MODBUS_ADDRESS, self::REGISTER_USB_OUTPUT, $value);
    }
    
    public static function getDCOutputCommand($enable) {
        $value = $enable ? 1 : 0;
        return self::getWriteModbus(self::REGISTER_MODBUS_ADDRESS, self::REGISTER_DC_OUTPUT, $value);
    }
    
    public static function getACOutputCommand($enable) {
        $value = $enable ? 1 : 0;
        return self::getWriteModbus(self::REGISTER_MODBUS_ADDRESS, self::REGISTER_AC_OUTPUT, $value);
    }
    
    public static function getRegisterInfo($register) {
        return isset(self::$registers[$register]) ? self::$registers[$register] : null;
    }
}