<?php

namespace Fedale\SettingBundle\Scoped;

/**
 * Native type of a scoped setting. It drives the conversion between the value
 * the code works with and the string the store persists (see SettingTypeCaster).
 *
 * Duration, DateTime and List are planned for when booking-policy needs them.
 */
enum SettingType: string
{
    case Bool = 'bool';
    case Int = 'int';
    case Float = 'float';
    case String = 'string';
    /** One of the definition's choices(); stored as the choice value. */
    case Enum = 'enum';
}
