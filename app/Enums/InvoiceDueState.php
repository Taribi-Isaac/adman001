<?php

namespace App\Enums;

/**
 * Derived due-date state — not persisted.
 */
enum InvoiceDueState: string
{
    case NotApplicable = 'not_applicable';
    case NotDue = 'not_due';
    case DueToday = 'due_today';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::NotApplicable => 'N/A',
            self::NotDue => 'Not due',
            self::DueToday => 'Due today',
            self::Overdue => 'Overdue',
        };
    }
}
