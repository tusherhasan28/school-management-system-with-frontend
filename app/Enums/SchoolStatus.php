<?php

namespace App\Enums;

enum SchoolStatus: string
{
    case Pending = 'pending';
    case Trial = 'trial';
    case Active = 'active';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    public function label(): string
    {
        return __('school.status.'.$this->value);
    }
}
