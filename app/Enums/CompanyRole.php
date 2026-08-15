<?php

declare(strict_types=1);

namespace App\Enums;

enum CompanyRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case BranchManager = 'branch_manager';
    case SalesManager = 'sales_manager';
    case Salesperson = 'salesperson';
    case Marketer = 'marketer';
    case MarketingManager = 'marketing_manager';
    case Purchaser = 'purchaser';
    case Storekeeper = 'storekeeper';
    case Accountant = 'accountant';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Administrator',
            self::BranchManager => 'Branch Manager',
            self::SalesManager => 'Sales Manager',
            self::Salesperson => 'Salesperson',
            self::Marketer => 'Marketer',
            self::MarketingManager => 'Marketing Manager',
            self::Purchaser => 'Purchaser',
            self::Storekeeper => 'Storekeeper',
            self::Accountant => 'Accountant',
            self::Staff => 'Staff',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
